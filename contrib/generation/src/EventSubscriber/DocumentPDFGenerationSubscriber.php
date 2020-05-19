<?php

namespace Drupal\document_generation\EventSubscriber;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\pdf_tools\PDFGeneratorInterface;
use Drupal\state_machine\Event\WorkflowTransitionEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\File\MimeType\MimeTypeGuesserInterface;

class DocumentPDFGenerationSubscriber implements EventSubscriberInterface {

  /**
   * @var \Drupal\pdf_tools\PDFGeneratorInterface
   */
  protected $generator;

  /**
   * @var \Symfony\Component\HttpFoundation\File\MimeType\MimeTypeGuesserInterface
   */
  protected $mimeTypeGuesser;

  /**
   * @var \Drupal\Core\File\FileSystemInterface
   */
  protected $fileSystem;

  /**
   * @var \Drupal\Core\Entity\EntityStorageInterface
   */
  protected $fileStorage;

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    return [
      'document.generate.pre_transition' => 'generateDocumentPDF',
    ];
  }

  /**
   * DocumentPDFGenerationSubscriber constructor.
   *
   * @param \Drupal\pdf_tools\PDFGeneratorInterface $generator
   */
  public function __construct(
    PDFGeneratorInterface $generator,
    EntityTypeManagerInterface $entity_type_manager,
    MimeTypeGuesserInterface $mime_type_guesser,
    FileSystemInterface $file_system
  ) {
    $this->generator = $generator;
    $this->mimeTypeGuesser = $mime_type_guesser;
    $this->fileSystem = $file_system;
    $this->fileStorage = $entity_type_manager->getStorage('file');
  }

  /**
   * Generate the pdf.
   *
   * @param \Drupal\state_machine\Event\WorkflowTransitionEvent $event
   */
  public function generateDocumentPDF(WorkflowTransitionEvent $event) {
    /** @var \Drupal\document\Entity\Document $document */
    $document = $event->getEntity();

    /** @var \Drupal\Core\Field\FieldDefinitionInterface $field_def */
    $field_def = $document->file->getFieldDefinition();

    $pdf_options = [
      'pdf_style' => $document->pdf_style->entity,
      '__destination' => $field_def->getSetting('uri_scheme', 'private'). '://'
        . $field_def->getSetting('file_directory', 'documents')
        . '/' . preg_replace('/[^a-z0-9_-]+/i', '-', $document->label->value) . '.pdf',
    ];

    $uri = $this->generator->renderArrayToPDF(
      $document->pdf_content->view([
        'type' => 'text_default',
        'label' => 'hidden',
      ]),
      $pdf_options
    );

    /** @var \Drupal\file\Entity\File $file */
    $file = $this->fileStorage->create([
      'uri' => $uri,
      'size' => filesize($uri),
      'uid' => \Drupal::currentUser()->id(),
      'status' => 1,
      'filename' => basename($uri),
      'filemime' => $this->mimeTypeGuesser->guess($uri),
    ]);

    $this->fileSystem->chmod($file->getFileUri());
    $file->save();
    $document->file = $file;
  }

}
