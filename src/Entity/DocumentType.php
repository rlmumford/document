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
   * Gets the description.
   *
   * @return string
   *   What this kind of document is for.
   */
  public function getDescription(): string {
    return (string) $this->description;
  }

}
