<?php

namespace Drupal\document\Entity;

use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityChangedTrait;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\user\EntityOwnerTrait;

/**
 * A file people treat as one thing.
 *
 * The additions here are forward-ported from CounselKit's D7 ck_document, which
 * reached this shape over a decade of a business whose work is almost entirely
 * documents. Each exists because a file could not answer a question somebody
 * kept asking:
 *
 * - **type**, because a CV and a bank statement share nothing but a file field;
 * - **status**, because "we asked for this and it has not arrived" is a state a
 *   document is in, and a file cannot represent the absence of itself;
 * - **files**, because a document assembled from six photographs of one bank
 *   statement is one document, and discarding the six destroys the only copy of
 *   each;
 * - **analysis_data**, because what has been worked out about a document -
 *   extracted text, a parse, a model's answer - belongs with it and not in a
 *   cache that can be cleared.
 *
 * @ContentEntityType(
 *   id = "document",
 *   label = @Translation("Document"),
 *   label_collection = @Translation("Documents"),
 *   label_singular = @Translation("document"),
 *   label_plural = @Translation("documents"),
 *   label_count = @PluralTranslation(
 *     singular = "@count document",
 *     plural = "@count documents"
 *   ),
 *   bundle_label = @Translation("Document type"),
 *   handlers = {
 *     "storage" = "Drupal\Core\Entity\Sql\SqlContentEntityStorage",
 *     "view_builder" = "Drupal\Core\Entity\EntityViewBuilder",
 *     "list_builder" = "Drupal\document\DocumentListBuilder",
 *     "access" = "Drupal\document\Entity\DocumentAccessControlHandler",
 *     "views_data" = "Drupal\views\EntityViewsData",
 *     "form" = {
 *       "default" = "Drupal\Core\Entity\ContentEntityForm",
 *       "add" = "Drupal\Core\Entity\ContentEntityForm",
 *       "edit" = "Drupal\Core\Entity\ContentEntityForm",
 *       "delete" = "Drupal\Core\Entity\ContentEntityDeleteForm",
 *     },
 *     "route_provider" = {
 *       "html" = "Drupal\Core\Entity\Routing\AdminHtmlRouteProvider",
 *     },
 *   },
 *   base_table = "document",
 *   revision_table = "document_revision",
 *   admin_permission = "administer documents",
 *   entity_keys = {
 *     "id" = "id",
 *     "revision" = "vid",
 *     "bundle" = "type",
 *     "uuid" = "uuid",
 *     "label" = "label",
 *     "owner" = "owner",
 *   },
 *   bundle_entity_type = "document_type",
 *   field_ui_base_route = "entity.document_type.edit_form",
 *   links = {
 *     "canonical" = "/document/{document}",
 *     "add-page" = "/document/add",
 *     "add-form" = "/document/add/{document_type}",
 *     "edit-form" = "/document/{document}/edit",
 *     "delete-form" = "/document/{document}/delete",
 *     "collection" = "/admin/content/documents",
 *   },
 * )
 */
class Document extends ContentEntityBase implements DocumentInterface {

  use EntityChangedTrait;
  use EntityOwnerTrait;

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

    // Who this document is about, as distinct from who uploaded it. A case
    // worker scanning a client's bank statement, a recruiter typing up somebody
    // else's CV: the owner is whoever put it there and is answerable for it,
    // and this is whose life it describes. Usually the same person, and
    // occasionally the whole point.
    //
    // Left empty rather than defaulted to the owner, so "nobody said" stays
    // distinguishable from "it is theirs" - see subjectId().
    $fields['about'] = BaseFieldDefinition::create('entity_reference')
      ->setRevisionable(TRUE)
      ->setLabel(new TranslatableMarkup('About'))
      ->setDescription(new TranslatableMarkup('Whose document this is, if that is not the person who provided it.'))
      ->setSetting('target_type', 'user')
      ->setDisplayConfigurable('view', TRUE)
      ->setDisplayConfigurable('form', TRUE);

    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(new TranslatableMarkup('Created'));

    $fields['changed'] = BaseFieldDefinition::create('changed')
      ->setLabel(new TranslatableMarkup('Changed'));

    // The document as one usable thing. Files are given to us in `files` and
    // then composed into this: several photographs of one bank statement
    // become one readable PDF, and the photographs stay where they are.
    $fields['file'] = BaseFieldDefinition::create('file')
      ->setRevisionable(TRUE)
      ->setLabel(new TranslatableMarkup('File'))
      ->setDescription(new TranslatableMarkup('Please upload the file.'))
      ->setSetting('uri_scheme', in_array('private', stream_get_wrappers()) ? 'private' : 'public')
      ->setSetting('file_extensions', 'pdf txt doc docx odt jpg png bmp')
      ->setSetting('file_directory', 'documents')
      ->setDisplayConfigurable('view', TRUE)
      ->setDisplayConfigurable('form', TRUE);

    // What it is made of, as given to us. Composing them into `file` must not
    // destroy the originals: a composition can be wrong, a page can be
    // missing, and the only way to find out afterwards is to still have what
    // came in.
    $fields['files'] = BaseFieldDefinition::create('file')
      ->setRevisionable(TRUE)
      ->setLabel(new TranslatableMarkup('Files'))
      ->setDescription(new TranslatableMarkup('The files this document is made of, as they were given to us.'))
      ->setCardinality(FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED)
      ->setSetting('uri_scheme', in_array('private', stream_get_wrappers()) ? 'private' : 'public')
      ->setSetting('file_extensions', 'pdf txt doc docx odt jpg png bmp')
      ->setSetting('file_directory', 'documents')
      ->setDisplayConfigurable('view', TRUE)
      ->setDisplayConfigurable('form', TRUE);

    // A list rather than a workflow: the transitions differ by document type
    // and by whose document it is, and baking one set into a shared module
    // decides that for everybody.
    $fields['status'] = BaseFieldDefinition::create('list_string')
      ->setRevisionable(TRUE)
      ->setLabel(new TranslatableMarkup('Status'))
      ->setRequired(TRUE)
      ->setSetting('allowed_values', [
        'needed' => (string) new TranslatableMarkup('Needed'),
        'not_needed' => (string) new TranslatableMarkup('Not required'),
        'received' => (string) new TranslatableMarkup('Received'),
        'refused' => (string) new TranslatableMarkup('Refused'),
        'approved' => (string) new TranslatableMarkup('Approved'),
        'rejected' => (string) new TranslatableMarkup('Rejected'),
        'expired' => (string) new TranslatableMarkup('Expired'),
        'superseded' => (string) new TranslatableMarkup('Superseded'),
      ])
      ->setDefaultValue('needed')
      ->setDisplayConfigurable('view', TRUE)
      ->setDisplayConfigurable('form', TRUE);

    // Whatever has been worked out about the document, with what produced it.
    // A map, so it is an array in PHP and serialized in storage - which means
    // it is not queryable. CounselKit used a real JSON column and can query it;
    // nothing here needs to yet, and the day something does, the column type is
    // the change rather than the shape of the data.
    $fields['analysis_data'] = BaseFieldDefinition::create('map')
      ->setRevisionable(TRUE)
      ->setLabel(new TranslatableMarkup('Analysis'))
      ->setDescription(new TranslatableMarkup('What has been worked out about this document, and by what.'));

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

  /**
   * {@inheritdoc}
   */
  public function getStatus(): string {
    return (string) $this->get('status')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function setStatus(string $status) {
    $this->set('status', $status);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getFile() {
    return $this->get('file')->entity;
  }

  /**
   * {@inheritdoc}
   */
  public function getFiles(): array {
    return $this->get('files')->referencedEntities();
  }

  /**
   * {@inheritdoc}
   */
  public function subjectId(): ?int {
    // Falls back to the owner, because most documents are about the person
    // who gave them to us, and making every caller write that fallback is how
    // half of them come to forget it.
    $about = $this->get('about')->target_id;
    return $about !== NULL ? (int) $about : ($this->getOwnerId() ?: NULL);
  }

  /**
   * {@inheritdoc}
   */
  public function getAnalysis(?string $key = NULL) {
    // MapItem::setValue() stores the array as given and getValue() hands it
    // straight back - there is no wrapping property to strip, whatever the
    // rest of the Field API leads you to expect.
    $item = $this->get('analysis_data')->first();
    $data = $item ? $item->getValue() : [];
    $data = is_array($data) ? $data : [];

    if ($key === NULL) {
      return $data;
    }
    return $data[$key] ?? NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function setAnalysis(string $key, $value) {
    $data = $this->getAnalysis() ?: [];
    $data[$key] = $value;
    $this->set('analysis_data', $data);
    return $this;
  }

}
