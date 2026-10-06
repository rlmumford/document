<?php

namespace Drupal\document;

use Drupal\Core\Entity\ContentEntityTypeInterface;
use Drupal\Core\Entity\Sql\SqlContentEntityStorageSchema;

/**
 * Prevents duplicate evidence from a replayed source attempt.
 */
class DocumentReviewStorageSchema extends SqlContentEntityStorageSchema {

  /**
   * {@inheritdoc}
   */
  protected function getEntitySchema(ContentEntityTypeInterface $entity_type, $reset = FALSE) {
    $schema = parent::getEntitySchema($entity_type, $reset);
    $schema['document_review']['unique keys']['submission_receipt'] = ['receipt'];
    $schema['document_review']['indexes']['document_reviews'] = ['document', 'created'];
    return $schema;
  }

}
