<?php

declare(strict_types=1);

namespace Drupal\brightedge_ai;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityListBuilder;

/**
 * Admin list of demo requests at /admin/content/demo-requests.
 *
 * Kept intentionally simple — this is for ops/admin visibility during the
 * PoC. A production build would add filters, search, export, and the SF
 * resync action column.
 */
final class DemoRequestListBuilder extends EntityListBuilder {

  public function buildHeader(): array {
    return [
      'id' => $this->t('ID'),
      'name' => $this->t('Name'),
      'email' => $this->t('Email'),
      'company' => $this->t('Company'),
      'created' => $this->t('Submitted'),
      'sf_sync_status' => $this->t('SF Sync'),
      'sf_lead_id' => $this->t('SF Lead ID'),
    ] + parent::buildHeader();
  }

  public function buildRow(EntityInterface $entity): array {
    /** @var \Drupal\brightedge_ai\Entity\DemoRequest $entity */
    return [
      'id' => $entity->id(),
      'name' => $entity->get('name')->value,
      'email' => $entity->get('email')->value,
      'company' => $entity->get('company')->value,
      'created' => \Drupal::service('date.formatter')
        ->format((int) $entity->get('created')->value, 'short'),
      'sf_sync_status' => $entity->get('sf_sync_status')->value,
      'sf_lead_id' => $entity->get('sf_lead_id')->value ?: '—',
    ] + parent::buildRow($entity);
  }

}
