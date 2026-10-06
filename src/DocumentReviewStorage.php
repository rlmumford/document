<?php

namespace Drupal\document;

use Drupal\Core\Entity\Sql\SqlContentEntityStorage;

/**
 * Queries review evidence, including current reads during serialized recording.
 */
class DocumentReviewStorage extends SqlContentEntityStorage {

  /**
   * Gets the latest evidence for one requirement and document fingerprint.
   *
   * Locking reads see committed evidence even when a caller's enclosing MySQL
   * transaction already established a repeatable-read snapshot. The document
   * row must be locked first to serialize all reviewers of that document.
   */
  public function latestEvidence(string $document, string $fingerprint, string $role, ?int $reviewer, bool $lock = FALSE): ?array {
    $query = $this->database->select('document_review', 'r')->fields('r', ['uuid', 'decision'])
      ->condition('document', $document)->condition('fingerprint', $fingerprint)
      ->condition('role', $role)->orderBy('id', 'DESC')->range(0, 1);
    if ($reviewer !== NULL) {
      $query->condition('reviewer', $reviewer);
    }
    if ($lock) {
      $query->forUpdate();
    }
    return $query->execute()->fetchAssoc() ?: NULL;
  }

}
