<?php

declare(strict_types=1);

namespace Drupal\brightedge_ai\Entity;

use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;

/**
 * Defines the Demo Request content entity.
 *
 * Why a custom entity (and not a webform submission):
 *  - Typed, queryable fields suitable for downstream CRM mapping.
 *  - Explicit sync status field so the Salesforce queue worker has a clean
 *    state machine (pending -> synced / failed) to operate against.
 *  - Standard Drupal entity API gives us a free admin UI, access checks,
 *    hook ecosystem, and clean upgrade path.
 *
 * @ContentEntityType(
 *   id = "brightedge_demo_request",
 *   label = @Translation("Demo Request"),
 *   base_table = "brightedge_demo_request",
 *   entity_keys = {
 *     "id" = "id",
 *     "uuid" = "uuid",
 *     "label" = "email",
 *   },
 *   handlers = {
 *     "view_builder" = "Drupal\Core\Entity\EntityViewBuilder",
 *     "list_builder" = "Drupal\brightedge_ai\DemoRequestListBuilder",
 *     "access" = "Drupal\Core\Entity\EntityAccessControlHandler",
 *   },
 *   admin_permission = "administer site configuration",
 *   links = {
 *     "collection" = "/admin/content/demo-requests",
 *   },
 * )
 */
final class DemoRequest extends ContentEntityBase {

  public const STATUS_PENDING = 'pending';
  public const STATUS_SYNCED = 'synced';
  public const STATUS_FAILED = 'failed';

  /**
   * Defines the schema (base fields) for the Demo Request entity.
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);

    $fields['name'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Name'))
      ->setRequired(TRUE)
      ->setSetting('max_length', 128);

    $fields['email'] = BaseFieldDefinition::create('email')
      ->setLabel(t('Work Email'))
      ->setRequired(TRUE);

    $fields['company'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Company'))
      ->setRequired(TRUE)
      ->setSetting('max_length', 128);

    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(t('Created'));

    // Salesforce sync state — owned by the queue worker, not the form.
    $fields['sf_sync_status'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Salesforce sync status'))
      ->setSetting('max_length', 16)
      ->setDefaultValue(self::STATUS_PENDING);

    // Salesforce Lead ID — populated by the queue worker on success.
    $fields['sf_lead_id'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Salesforce Lead ID'))
      ->setSetting('max_length', 32);

    return $fields;
  }

}
