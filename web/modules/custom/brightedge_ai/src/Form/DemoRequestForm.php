<?php

declare(strict_types=1);

namespace Drupal\brightedge_ai\Form;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Flood\FloodInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Public "Request a Demo" form at /request-demo.
 *
 * On submit:
 *  1. Validates inputs (work email check, required fields, flood control).
 *  2. Persists a DemoRequest entity in 'pending' state.
 *  3. (Designed for, not built:) enqueues a job to sync the entity to
 *     Salesforce — see README "Salesforce hand-off" section.
 *
 * Note: we deliberately rely on FormBase's inherited services
 * ($this->messenger, $this->loggerFactory, $this->requestStack) instead of
 * redeclaring them. Drupal core sets these via setters from the container.
 */
final class DemoRequestForm extends FormBase {

  private const FLOOD_EVENT = 'brightedge_ai.demo_request';
  private const FLOOD_THRESHOLD = 5;
  private const FLOOD_WINDOW = 3600;

  private EntityTypeManagerInterface $entityTypeManager;
  private FloodInterface $flood;

  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    FloodInterface $flood,
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->flood = $flood;
  }

  public static function create(ContainerInterface $container): self {
    $instance = new self(
      $container->get('entity_type.manager'),
      $container->get('flood'),
    );
    // Use FormBase's setters so $this->messenger, ->loggerFactory,
    // ->requestStack are populated from the container. This is the
    // idiomatic Drupal pattern for forms that need these utilities.
    $instance->setMessenger($container->get('messenger'));
    $instance->setLoggerFactory($container->get('logger.factory'));
    $instance->setRequestStack($container->get('request_stack'));
    return $instance;
  }

  public function getFormId(): string {
    return 'brightedge_ai_demo_request_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form['#attributes']['class'][] = 'be-demo-form';

    $form['intro'] = [
      '#markup' => '<p>Tell us a bit about yourself and someone from our team will reach out to schedule your demo.</p>',
    ];

    $form['name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Full Name'),
      '#required' => TRUE,
      '#maxlength' => 128,
    ];

    $form['email'] = [
      '#type' => 'email',
      '#title' => $this->t('Work Email'),
      '#required' => TRUE,
      '#description' => $this->t('Please use your work email — personal emails (gmail, yahoo, etc.) will be rejected.'),
    ];

    $form['company'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Company'),
      '#required' => TRUE,
      '#maxlength' => 128,
    ];

    $form['actions'] = [
      '#type' => 'actions',
      'submit' => [
        '#type' => 'submit',
        '#value' => $this->t('Request Demo'),
        '#button_type' => 'primary',
      ],
    ];

    return $form;
  }

  public function validateForm(array &$form, FormStateInterface $form_state): void {
    // Flood control — per-IP, prevents form spam.
    $ip = $this->getRequest()->getClientIp() ?? 'unknown';
    if (!$this->flood->isAllowed(self::FLOOD_EVENT, self::FLOOD_THRESHOLD, self::FLOOD_WINDOW, $ip)) {
      $form_state->setErrorByName('', $this->t('Too many submissions from this network. Please try again later.'));
      return;
    }

    // Reject obvious personal-email providers — this is a B2B funnel.
    $email = strtolower((string) $form_state->getValue('email'));
    $personal_domains = ['gmail.com', 'yahoo.com', 'hotmail.com', 'outlook.com', 'icloud.com', 'aol.com', 'proton.me', 'protonmail.com'];
    foreach ($personal_domains as $domain) {
      if (str_ends_with($email, '@' . $domain)) {
        $form_state->setErrorByName('email', $this->t('Please use your work email address.'));
        return;
      }
    }
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $ip = $this->getRequest()->getClientIp() ?? 'unknown';
    $this->flood->register(self::FLOOD_EVENT, self::FLOOD_WINDOW, $ip);

    try {
      $storage = $this->entityTypeManager->getStorage('brightedge_demo_request');
      $entity = $storage->create([
        'name' => trim((string) $form_state->getValue('name')),
        'email' => trim((string) $form_state->getValue('email')),
        'company' => trim((string) $form_state->getValue('company')),
        // sf_sync_status defaults to 'pending' (set in baseFieldDefinitions).
      ]);
      $entity->save();

      $this->logger('brightedge_ai')->info(
        'Demo request submitted: id=@id, email=@email',
        ['@id' => $entity->id(), '@email' => $entity->get('email')->value]
      );

      // PRODUCTION HOOK POINT — this is where we'd enqueue the SF sync job:
      //   \Drupal::queue('brightedge_sf_lead_sync')->createItem([
      //     'submission_id' => $entity->id(),
      //   ]);
      // See README "Salesforce hand-off design" for the full architecture.

      $this->messenger()->addStatus($this->t("Thanks! We've received your request and someone from BrightEdge will be in touch shortly."));
    }
    catch (\Throwable $e) {
      $this->logger('brightedge_ai')->error(
        'Demo request save failed: @msg',
        ['@msg' => $e->getMessage()]
      );
      $this->messenger()->addError($this->t('Sorry, something went wrong saving your request. Please try again.'));
    }
  }

}
