<?php

namespace Drupal\document\Plugin\Field\FieldWidget;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\WidgetBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\document\Entity\Document;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Choose a document already given to us, or give us a new one.
 *
 * The point is the choice. Asking somebody to find and upload the same CV for
 * every application is the most tedious part of applying, and the friction
 * lands exactly where it is least wanted - at the moment of submitting. If we
 * already hold a document of the right kind, offering it costs one click.
 *
 * Reuse is also what makes a declaration worth recording on the document: a
 * file that has been declared to contain faith or health detail carries that
 * answer into every later use of it, so nobody is asked the same question about
 * the same file twice, and an inconsistent answer becomes visible instead of
 * being the last one typed.
 *
 * @FieldWidget(
 *   id = "document_selector",
 *   label = @Translation("Document: choose or upload"),
 *   field_types = {
 *     "entity_reference",
 *     "entity_reference_revisions"
 *   }
 * )
 */
class DocumentSelector extends WidgetBase {

  /**
   * The value meaning "none of the above, I am giving you a new one".
   */
  const UPLOAD = '_upload';

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected $currentUser;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $instance = new static($plugin_id, $plugin_definition, $configuration['field_definition'], $configuration['settings'], $configuration['third_party_settings']);
    $instance->entityTypeManager = $container->get('entity_type.manager');
    $instance->currentUser = $container->get('current_user');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public static function defaultSettings() {
    return [
      'document_type' => '',
      'upload_extensions' => 'pdf doc docx odt txt',
      'upload_max_size' => '',
    ] + parent::defaultSettings();
  }

  /**
   * {@inheritdoc}
   */
  public function settingsForm(array $form, FormStateInterface $form_state) {
    $types = $this->entityTypeManager->getStorage('document_type')->loadMultiple();

    $element['document_type'] = [
      '#type' => 'select',
      '#title' => $this->t('Document type'),
      '#description' => $this->t('New uploads are created as this type, and only documents of this type are offered as alternatives.'),
      '#options' => array_map(fn($type) => $type->label(), $types),
      '#default_value' => $this->getSetting('document_type'),
      '#empty_option' => $this->t('- Any -'),
    ];

    $element['upload_extensions'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Allowed file extensions'),
      '#default_value' => $this->getSetting('upload_extensions'),
      '#required' => TRUE,
    ];

    $element['upload_max_size'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Maximum file size'),
      '#description' => $this->t('Leave empty to use the PHP limit.'),
      '#default_value' => $this->getSetting('upload_max_size'),
    ];

    return $element;
  }

  /**
   * {@inheritdoc}
   */
  public function settingsSummary() {
    $type = $this->getSetting('document_type');
    $summary[] = $type
      ? $this->t('Type: @type', ['@type' => $type])
      : $this->t('Any document type');
    $summary[] = $this->t('Accepts: @ext', ['@ext' => $this->getSetting('upload_extensions')]);
    return $summary;
  }

  /**
   * {@inheritdoc}
   */
  public function formElement(FieldItemListInterface $items, $delta, array $element, array &$form, FormStateInterface $form_state) {
    $existing = $this->existingDocuments();
    $current = $items[$delta]->target_id ?? NULL;

    $element['#type'] = 'fieldset';
    $element['#tree'] = TRUE;

    // The default is whatever is already on the entity; failing that, the most
    // recent thing they gave us, because that is what they would have picked.
    // Only when there is nothing at all does the form open on "upload".
    $default = $current ?? (array_key_first($existing) ?: static::UPLOAD);

    $options = $existing;
    $options[static::UPLOAD] = $this->t('Upload a new one');

    $element['target_id'] = [
      '#type' => 'radios',
      '#title' => $element['#title'] ?? $this->fieldDefinition->getLabel(),
      '#options' => $options,
      '#default_value' => $default,
      '#required' => $this->fieldDefinition->isRequired(),
    ];

    // Only ever one radio when there is nothing to choose between, so do not
    // pretend there is a choice.
    if (!$existing) {
      $element['target_id']['#access'] = FALSE;
    }

    $selector = ':input[name="' . $this->uploadStateSelector($element, $delta) . '"]';

    $element['upload'] = [
      '#type' => 'managed_file',
      '#title' => $this->t('File'),
      '#upload_location' => 'private://documents/' . date('Y-m'),
      '#upload_validators' => array_filter([
        'file_validate_extensions' => [$this->getSetting('upload_extensions')],
        'file_validate_size' => $this->getSetting('upload_max_size')
          ? [$this->getSetting('upload_max_size')]
          : NULL,
      ]),
      '#states' => $existing ? [
        'visible' => [$selector => ['value' => static::UPLOAD]],
      ] : [],
    ];

    // Asked because a list of files called "document.pdf" three times over is
    // not a choice anybody can make. Optional: a name we generate from the
    // filename is better than blocking the upload on a question.
    $element['label'] = [
      '#type' => 'textfield',
      '#title' => $this->t('What should we call this?'),
      '#description' => $this->t('So you can recognise it next time. We will use the file name if you leave this empty.'),
      '#maxlength' => 255,
      '#states' => $existing ? [
        'visible' => [$selector => ['value' => static::UPLOAD]],
      ] : [],
    ];

    return $element;
  }

  /**
   * The name of the radio input that drives the #states above.
   *
   * @param array $element
   *   The element being built.
   * @param int $delta
   *   The delta.
   *
   * @return string
   *   The input name.
   */
  protected function uploadStateSelector(array $element, $delta) {
    $parents = array_merge($element['#field_parents'] ?? [], [$this->fieldDefinition->getName()]);
    $name = array_shift($parents);
    foreach (array_merge($parents, [$delta, 'target_id']) as $part) {
      $name .= '[' . $part . ']';
    }
    return $name;
  }

  /**
   * Documents this user has already given us, newest first.
   *
   * @return array
   *   Labels keyed by document id.
   */
  protected function existingDocuments(): array {
    if (!$this->currentUser->isAuthenticated()) {
      return [];
    }

    $storage = $this->entityTypeManager->getStorage('document');
    // By whose document it is, not who uploaded it, and this is the query form
    // of DocumentInterface::getPersonId(). A recruiter who uploaded a
    // candidate's CV owns that document while the candidate is the person it is
    // about: the candidate must be offered it back and the recruiter must not
    // be, so an OR across both columns would be wrong in exactly the case the
    // field exists for.
    $query = $storage->getQuery()->accessCheck(TRUE);
    $query->condition($query->orConditionGroup()
      ->condition('person', $this->currentUser->id())
      ->condition($query->andConditionGroup()
        ->notExists('person')
        ->condition('owner', $this->currentUser->id())))
      ->condition('is_archived', TRUE, '<>')
      ->sort('changed', 'DESC')
      ->range(0, 20);

    if ($type = $this->getSetting('document_type')) {
      $query->condition('type', $type);
    }

    $options = [];
    foreach ($storage->loadMultiple($query->execute()) as $document) {
      $options[$document->id()] = $document->label();
    }
    return $options;
  }

  /**
   * {@inheritdoc}
   */
  public function massageFormValues(array $values, array $form, FormStateInterface $form_state) {
    foreach ($values as $delta => $value) {
      if (($value['target_id'] ?? NULL) !== static::UPLOAD) {
        $document = $value['target_id']
          ? $this->entityTypeManager->getStorage('document')->load($value['target_id'])
          : NULL;
        $values[$delta] = $this->reference($document);
        continue;
      }

      $fid = reset($value['upload']) ?: NULL;
      $values[$delta] = $fid
        ? $this->reference($this->createDocument($fid, $value['label'] ?? ''))
        : $this->reference(NULL);
    }

    return $values;
  }

  /**
   * Builds the reference value, with a revision where the field wants one.
   *
   * The same widget serves entity_reference and entity_reference_revisions,
   * because the difference is not one a person filling in a form should have to
   * care about - but it matters entirely to what gets stored. A revision
   * reference records *which version* of the document was chosen, so replacing
   * a CV next year does not rewrite what an employer was sent; leaving
   * target_revision_id off such a field stores a reference that resolves to
   * nothing.
   *
   * @param \Drupal\document\Entity\DocumentInterface|null $document
   *   The chosen document, or NULL.
   *
   * @return array
   *   The field value.
   */
  protected function reference($document): array {
    if (!$document) {
      return ['target_id' => NULL];
    }

    $value = ['target_id' => $document->id()];
    if ($this->fieldDefinition->getType() === 'entity_reference_revisions') {
      $value['target_revision_id'] = $document->getRevisionId();
    }

    return $value;
  }

  /**
   * Turns an uploaded file into a document.
   *
   * @param int $fid
   *   The uploaded file id.
   * @param string $label
   *   What they called it, if they said.
   *
   * @return \Drupal\document\Entity\DocumentInterface
   *   The saved document.
   */
  protected function createDocument($fid, string $label) {
    $file = $this->entityTypeManager->getStorage('file')->load($fid);

    // Permanent, because the entity that will reference it is not saved yet and
    // Drupal's temporary-file collector does not wait. A document nobody ends
    // up referencing is a loose end for a cron job, which is a far better
    // problem than a candidate's CV being deleted between upload and submit.
    if ($file) {
      $file->setPermanent();
      $file->save();
    }

    $document = Document::create([
      'type' => $this->getSetting('document_type') ?: 'general',
      'label' => $label !== '' ? $label : ($file ? $file->getFilename() : (string) new TranslatableMarkup('Untitled')),
      'status' => 'received',
      'owner' => $this->currentUser->id(),
      // Uploading your own CV makes you both; a recruiter uploading somebody
      // else's sets this afterwards, which is the case the field exists for.
      'person' => $this->currentUser->id(),
      'file' => ['target_id' => $fid],
      'files' => [['target_id' => $fid]],
    ]);
    $document->save();

    return $document;
  }

}
