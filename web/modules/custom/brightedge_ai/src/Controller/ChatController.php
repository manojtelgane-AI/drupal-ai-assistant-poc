<?php

declare(strict_types=1);

namespace Drupal\brightedge_ai\Controller;

use Drupal\brightedge_ai\Service\AiClient;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Flood\FloodInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * HTTP entry point for the AI chat assistant.
 *
 * Responsibilities (kept narrow on purpose):
 *  - Parse and validate the incoming request.
 *  - Enforce per-IP rate limiting (Drupal Flood API).
 *  - Delegate the actual AI call to AiClient.
 *  - Return a clean, predictable JSON shape to the browser.
 *
 * All AI / vendor logic lives in AiClient. This controller is intentionally
 * thin so it stays easy to test and reason about.
 */
final class ChatController extends ControllerBase {

  /**
   * Flood limits: at most N events per window, per identifier.
   */
  private const FLOOD_EVENT = 'brightedge_ai.chat';
  private const FLOOD_THRESHOLD = 20;
  // 1 hour in seconds.
  private const FLOOD_WINDOW = 3600;

  public function __construct(
    private readonly AiClient $aiClient,
    private readonly FloodInterface $flood,
  ) {}

  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('brightedge_ai.client'),
      $container->get('flood'),
    );
  }

  /**
   * Handle a chat POST.
   *
   * Expects JSON body: { "message": "..." }
   * Returns JSON:      { "ok": bool, "reply": "...", "error": "..." }
   */
  public function handle(Request $request): JsonResponse {
    // 1. Per-IP rate limit. Prevents abuse and runaway API costs.
    $identifier = $request->getClientIp() ?? 'unknown';
    if (!$this->flood->isAllowed(self::FLOOD_EVENT, self::FLOOD_THRESHOLD, self::FLOOD_WINDOW, $identifier)) {
      return new JsonResponse([
        'ok' => FALSE,
        'error' => 'You are sending messages too quickly. Please wait a moment and try again.',
      ], 429);
    }

    // 2. Parse JSON body defensively.
    $payload = json_decode((string) $request->getContent(), TRUE);
    if (!is_array($payload)) {
      return new JsonResponse([
        'ok' => FALSE,
        'error' => 'Invalid request format.',
      ], 400);
    }

    $message = isset($payload['message']) && is_string($payload['message'])
      ? $payload['message']
      : '';

    // 3. Register the attempt *before* the upstream call so even errors count
    //    against the rate limit (denies abusive retry loops).
    $this->flood->register(self::FLOOD_EVENT, self::FLOOD_WINDOW, $identifier);

    // 4. Delegate to the service. The service handles its own errors and
    //    returns a well-typed result.
    $result = $this->aiClient->chat($message);

    $status = $result['ok'] ? 200 : 400;
    return new JsonResponse($result, $status);
  }

}
