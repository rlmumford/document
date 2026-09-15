<?php

namespace Drupal\document;

use Drupal\Core\Config\Entity\ConfigEntityListBuilder;
use Drupal\Core\Entity\EntityInterface;

/**
 * Lists document types.
 */
class DocumentTypeListBuilder extends ConfigEntityListBuilder {

  /**
   * {@inheritdoc}
   */
  public function buildHeader() {
    return [
      'label' => $this->t('Name'),
      'description' => $this->t('Description'),
    ] + parent::buildHeader();
  }

  /**
   * {@inheritdoc}
   */
  public function buildRow(EntityInterface $entity) {
    /** @var \Drupal\document\Entity\DocumentType $entity */
    return [
      'label' => $entity->label(),
      'description' => $entity->getDescription(),
    ] + parent::buildRow($entity);
  }

}
