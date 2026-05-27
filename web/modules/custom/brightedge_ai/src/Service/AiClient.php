<?php

declare(strict_types=1);

namespace Drupal\brightedge_ai\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Talks to an LLM provider (Anthropic Claude by default) server-side.
 *
 * Design notes:
 * - API key is read from the ANTHROPIC_API_KEY environment variable so it
 *   never lives in code, DB, or Git. In production this same pattern works
 *   with server env vars or Drupal's Key module.
 * - Provider/model are also env-driven so swapping Claude <-> Gemini <-> OpenAI
 *   is a config change, not a code rewrite.
 * - The system prompt defines the assistant's persona, scope, and refusal
 *   patterns — prompt engineering lives in code, reviewable like any other
 *   code, not in a CMS field that a non-engineer might edit.
 */
final class AiClient {

  /**
   * System prompt giving the assistant its BrightEdge persona and guardrails.
   */
  private const SYSTEM_PROMPT = <<<PROMPT
You are the BrightEdge AI Assistant — a helpful, concise expert living on the
brightedge.com marketing site.

BrightEdge is an enterprise SEO and content performance platform that helps
marketers discover content opportunities, optimize for search and AI-driven
discovery, and measure organic performance at scale.

How to behave:
- Answer questions about SEO, content marketing, AI search, and the BrightEdge
  platform clearly and briefly (2-4 short paragraphs max).
- If a user shows buying intent ("pricing", "demo", "trial", "talk to sales"),
  warmly suggest they request a demo using the "Request Demo" form on the site.
- Stay on-topic. If asked something unrelated (politics, personal advice,
  unrelated coding help), politely redirect: "I'm focused on helping with
  SEO, content, and BrightEdge — happy to help with those!"
- Never invent specific pricing, customer names, or features you are not
  certain about. If unsure, say so and suggest the demo.
PROMPT;

  private ClientInterface $httpClient;
  private LoggerChannelInterface $logger;
  private ConfigFactoryInterface $configFactory;

  public function __construct(
    ClientInterface $http_client,
    LoggerChannelFactoryInterface $logger_factory,
    ConfigFactoryInterface $config_factory,
  ) {
    $this->httpClient = $http_client;
    $this->logger = $logger_factory->get('brightedge_ai');
    $this->configFactory = $config_factory;
  }

  /**
   * Send a user message and get the assistant's reply.
   *
   * @param string $userMessage
   *   The visitor's message. Trimmed and length-checked before sending.
   *
   * @return array
   *   [
   *     'ok' => bool,
   *     'reply' => string (on success),
   *     'error' => string (on failure — safe to show end user),
   *   ]
   */
  public function chat(string $userMessage): array {
    $userMessage = trim($userMessage);

    // Defensive input validation. These run *before* any API call so we
    // never waste tokens on junk and never expose internal errors to the user.
    if ($userMessage === '') {
      return ['ok' => FALSE, 'error' => 'Please type a message.'];
    }
    if (mb_strlen($userMessage) > 2000) {
      return ['ok' => FALSE, 'error' => 'Message is too long. Please keep it under 2000 characters.'];
    }

    $apiKey = getenv('ANTHROPIC_API_KEY') ?: '';
    if ($apiKey === '') {
      // Log internal cause, return generic message to user. Never leak
      // configuration details to the browser.
      $this->logger->error('ANTHROPIC_API_KEY is not configured on the server.');
      return ['ok' => FALSE, 'error' => 'The AI assistant is not configured. Please contact the site administrator.'];
    }

    $model = getenv('AI_MODEL') ?: 'claude-sonnet-4-20250514';

    try {
      $response = $this->httpClient->request('POST', 'https://api.anthropic.com/v1/messages', [
        'headers' => [
          'x-api-key' => $apiKey,
          'anthropic-version' => '2023-06-01',
          'content-type' => 'application/json',
        ],
        'json' => [
          'model' => $model,
          // Bounded output — caps cost and prevents runaway responses.
          'max_tokens' => 512,
          'system' => self::SYSTEM_PROMPT,
          'messages' => [
            ['role' => 'user', 'content' => $userMessage],
          ],
        ],
        // Bounded waits — never hang the user's browser if the API stalls.
        'timeout' => 30,
        'connect_timeout' => 10,
      ]);

      $body = json_decode((string) $response->getBody(), TRUE, 512, JSON_THROW_ON_ERROR);
      $reply = $body['content'][0]['text'] ?? '';

      if ($reply === '') {
        $this->logger->warning('Empty response from AI provider. Raw body: @body', [
          '@body' => substr((string) $response->getBody(), 0, 500),
        ]);
        return ['ok' => FALSE, 'error' => 'The assistant did not return a response. Please try again.'];
      }

      return ['ok' => TRUE, 'reply' => $reply];
    }
    catch (GuzzleException $e) {
      // Network / HTTP errors (4xx, 5xx, DNS, timeout).
      $this->logger->error('AI provider HTTP error: @msg', ['@msg' => $e->getMessage()]);
      return ['ok' => FALSE, 'error' => 'The assistant is temporarily unavailable. Please try again in a moment.'];
    }
    catch (\JsonException $e) {
      // Malformed JSON from the provider — rare but possible.
      $this->logger->error('AI provider returned invalid JSON: @msg', ['@msg' => $e->getMessage()]);
      return ['ok' => FALSE, 'error' => 'The assistant returned an unexpected response.'];
    }
  }

}
