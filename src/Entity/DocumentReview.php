<?php

namespace Drupal\document\Entity;

use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * One person's decision about a particular version of a document.
 *
 * @ContentEntityType(
 *   id = "document_review",
 *   label = @Translation("Document review"),
 *   handlers = {
 *     "storage" = "Drupal\document\DocumentReviewStorage",
 *     "storage_schema" = "Drupal\document\DocumentReviewStorageSchema",
 *     "access" = "Drupal\document\Entity\DocumentReviewAccessControlHandler",
 *     "views_data" = "Drupal\views\EntityViewsData"
 *   },
 *   base_table = "document_review",
 *   entity_keys = {"id" = "id", "uuid" = "uuid"}
 * )
 */
class DocumentReview extends ContentEntityBase {

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type) {
    $fields = parent::baseFieldDefinitions($entity_type);
    $labels = ['document' => new TranslatableMarkup('Document'), 'reviewer' => new TranslatableMarkup('Reviewer')];
    foreach (['document' => 'document', 'reviewer' => 'user'] as $name => $target) {
      $fields[$name] = BaseFieldDefinition::create('entity_reference')
        ->setLabel($labels[$name])
        ->setSetting('target_type', $target)->setRequired(TRUE);
    }
    $fields['revision'] = BaseFieldDefinition::create('integer')->setLabel(new TranslatableMarkup('Reviewed revision'))->setRequired(TRUE);
    foreach ([
      'role' => new TranslatableMarkup('Named review'),
      'role_label' => new TranslatableMarkup('Review label'),
      'decision' => new TranslatableMarkup('Decision'),
      'decision_label' => new TranslatableMarkup('Decision label'),
      'fingerprint' => new TranslatableMarkup('Document fingerprint'),
      'source' => new TranslatableMarkup('Source'),
      'attempt' => new TranslatableMarkup('Attempt'),
      'receipt' => new TranslatableMarkup('Submission receipt'),
    ] as $name => $label) {
      $fields[$name] = BaseFieldDefinition::create('string')->setLabel($label);
    }
    $fields['analysis'] = BaseFieldDefinition::create('string_long')->setLabel(new TranslatableMarkup('Analysis JSON'));
    $fields['analysis_schema'] = BaseFieldDefinition::create('string_long')->setLabel(new TranslatableMarkup('Analysis schema at review time'));
    $fields['reason'] = BaseFieldDefinition::create('string_long')->setLabel(new TranslatableMarkup('Reason'));
    $fields['created'] = BaseFieldDefinition::create('created')->setLabel(new TranslatableMarkup('Reviewed at'));
    return $fields;
  }

  /**
   * {@inheritdoc}
   */
  public function preSave(EntityStorageInterface $storage) {
    if (!$this->isNew()) {
      throw new \LogicException('Reviews are immutable; record a new review instead.');
    }
    parent::preSave($storage);
  }

}
