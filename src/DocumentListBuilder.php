<?php

namespace Drupal\document;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityListBuilder;

/**
 * Lists documents.
 */
class DocumentListBuilder extends EntityListBuilder {

  /**
   * {@inheritdoc}
   */
  public function buildHeader() {
    return [
      'label' => $this->t('Name'),
      'type' => $this->t('Type'),
      'status' => $this->t('Status'),
      'owner' => $this->t('Owner'),
      'changed' => $this->t('Updated'),
    ] + parent::buildHeader();
  }

  /**
   * {@inheritdoc}
   */
  public function buildRow(EntityInterface $entity) {
    /** @var \Drupal\document\Entity\DocumentInterface $entity */
    $status = $entity->get('status')->first();
    $owner = $entity->getOwner();

    return [
      'label' => $entity->toLink()->toString(),
      'type' => $entity->get('type')->entity ? $entity->get('type')->entity->label() : $entity->bundle(),
      'status' => $status ? $status->getFieldDefinition()->getSetting('allowed_values')[$status->value] ?? $status->value : '',
      'owner' => $owner ? $owner->getDisplayName() : $this->t('Nobody'),
      'changed' => \Drupal::service('date.formatter')->format($entity->getChangedTime(), 'short'),
    ] + parent::buildRow($entity);
  }

}
