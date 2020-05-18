<?php

namespace Drupal\document\Entity;

use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityChangedInterface;
use Drupal\Core\Entity\EntityChangedTrait;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\user\EntityOwnerInterface;
use Drupal\user\EntityOwnerTrait;

/**
 * Class Document
 *
 * @ContentEntityType(
 *   id = "document",
 *   label = @Translation("Document"),
 *   label_singular = @Translation("document"),
 *   label_plural = @Translation("documents"),
 *   label_count = @PluralTranslation(
 *     singular = "@count document",
 *     plural = "@count documents"
 *   ),
 *   handlers = {
 *     "storage" = "Drupal\Core\Entity\Sql\SqlContentEntityStorage",
 *     "access" = "Drupal\document\Entity\DocumentAccessControlHandler",
 *     "views_data" = "Drupal\views\EntityViewsData",
 *     "form" = {
 *       "default" = "Drupal\Core\Entity\ContentEntityForm"
 *     },
 *   },
 *   base_table = "document",
 *   revision_table = "document_revision",
 *   admin_permission = "administer documents",
 *   entity_keys = {
 *     "id" = "id",
 *     "revision" = "vid",
 *     "uuid" = "uuid",
 *     "label" = "label",
 *     "owner" = "owner",
 *   },
 * );
 *
 * @package Drupal\document\Entity
 */
class Document extends ContentEntityBase implements EntityOwnerInterface, EntityChangedInterface {
  use EntityOwnerTrait;
  use EntityChangedTrait;

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type) {
    $fields = parent::baseFieldDefinitions($entity_type);
    $fields += static::ownerBaseFieldDefinitions($entity_type);

    $fields['label'] = BaseFieldDefinition::create('string')
      ->setRevisionable(TRUE)
      ->setLabel(new TranslatableMarkup('Label'))
      ->setRequired(TRUE)
      ->setDisplayConfigurable('view', TRUE)
      ->setDisplayConfigurable('form', TRUE);

    $fields['description'] = BaseFieldDefinition::create('text_long')
      ->setRevisionable(TRUE)
      ->setLabel(new TranslatableMarkup('Description'))
      ->setDisplayConfigurable('view', TRUE)
      ->setDisplayConfigurable('form', TRUE);

    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(new TranslatableMarkup('Created'));

    $fields['changed'] = BaseFieldDefinition::create('changed')
      ->setLabel(new TranslatableMarkup('Changed'));

    $fields['file'] = BaseFieldDefinition::create('file')
      ->setRevisionable(TRUE)
      ->setLabel(new TranslatableMarkup('File'))
      ->setDescription(new TranslatableMarkup('Please upload the file.'))
      ->setSetting('uri_scheme', in_array('private', stream_get_wrappers()) ? 'private' : 'public')
      ->setSetting('file_extensions', 'pdf txt doc jpg png bmp')
      ->setSetting('file_directory', 'documents')
      ->setDisplayConfigurable('view', TRUE)
      ->setDisplayConfigurable('form', TRUE);

    $fields['is_archived'] = BaseFieldDefinition::create('boolean')
      ->setLabel(new TranslatableMarkup('Archived?'))
      ->setRevisionable(TRUE)
      ->setDefaultValue(FALSE)
      ->setDisplayOptions('form', [
        'type' => 'boolean_checkbox',
        'settings' => [
          'display_label' => TRUE,
        ],
      ])
      ->setDisplayConfigurable('view', TRUE)
      ->setDisplayConfigurable('form', TRUE);

    $fields['archived_reason'] = BaseFieldDefinition::create('text_long')
      ->setLabel(new TranslatableMarkup('Archive Reason'))
      ->setDescription(new TranslatableMarkup('Why this document was archived.'))
      ->setDisplayConfigurable('view', TRUE)
      ->setDisplayConfigurable('form', TRUE);

    return $fields;
  }

}
