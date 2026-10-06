<?php

namespace Drupal\document\Entity;

use Drupal\Core\Config\Entity\ConfigEntityBundleBase;

/**
 * A kind of document.
 *
 * A CV, a bank statement and a signed contract are all "a file somebody gave
 * us", and beyond that they have nothing in common: different fields, different
 * retention, different people allowed to read them. The bundle is where that
 * difference lives.
 *
 * @ConfigEntityType(
 *   id = "document_type",
 *   label = @Translation("Document type"),
 *   label_collection = @Translation("Document types"),
 *   label_singular = @Translation("document type"),
 *   label_plural = @Translation("document types"),
 *   handlers = {
 *     "list_builder" = "Drupal\document\DocumentTypeListBuilder",
 *     "form" = {
 *       "add" = "Drupal\document\Form\DocumentTypeForm",
 *       "edit" = "Drupal\document\Form\DocumentTypeForm",
 *       "delete" = "Drupal\Core\Entity\EntityDeleteForm",
 *     },
 *     "route_provider" = {
 *       "html" = "Drupal\Core\Entity\Routing\AdminHtmlRouteProvider",
 *     },
 *   },
 *   config_prefix = "type",
 *   admin_permission = "administer documents",
 *   bundle_of = "document",
 *   entity_keys = {
 *     "id" = "id",
 *     "label" = "label",
 *     "uuid" = "uuid",
 *   },
 *   config_export = {
 *     "id",
 *     "label",
 *     "description",
 *     "reviews",
 *   },
 *   links = {
 *     "add-form" = "/admin/structure/document-types/add",
 *     "edit-form" = "/admin/structure/document-types/{document_type}",
 *     "delete-form" = "/admin/structure/document-types/{document_type}/delete",
 *     "collection" = "/admin/structure/document-types",
 *   },
 * )
 */
class DocumentType extends ConfigEntityBundleBase {

  /**
   * The machine name.
   *
   * @var string
   */
  protected $id;

  /**
   * The human-readable name.
   *
   * @var string
   */
  protected $label;

  /**
   * What this kind of document is for.
   *
   * @var string
   */
  protected $description;

  /**
   * Named review requirements for this document type.
   *
   * @var array
   */
  protected $reviews = [
    'staff' => [
      'label' => 'Staff review',
      'instructions' => 'Review the document and record your decision.',
      'options' => ['approved' => 'Approve', 'rejected' => 'Reject'],
      'required' => TRUE,
      'eligibility' => 'permission',
      'permission' => 'review documents as staff',
      'context_mapping' => [],
      'prompt' => '',
      'analysis_schema' => '',
    ],
  ];

  /**
   * Gets all named review requirements.
   */
  public function getReviews(): array {
    return $this->reviews;
  }

  /**
   * Gets a named definition, rejecting unknown review capacities.
   */
  public function getReview(string $name): array {
    if (!isset($this->reviews[$name])) {
      throw new \InvalidArgumentException('This document type does not define the requested review.');
    }
    return $this->reviews[$name];
  }

  /**
   * Gets the description.
   *
   * @return string
   *   What this kind of document is for.
   */
  public function getDescription(): string {
    return (string) $this->description;
  }

}
