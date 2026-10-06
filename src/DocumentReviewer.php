<?php

namespace Drupal\document;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\document\Entity\DocumentInterface;
use Drupal\document\Entity\DocumentReview;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Records individual reviews without deciding aggregate document status.
 */
class DocumentReviewer {

  public function __construct(protected EntityTypeManagerInterface $entityTypes, protected AccountProxyInterface $account) {}

  /**
   * Reloads the current document and enforces review access.
   */
  public function load(DocumentInterface $document): DocumentInterface {
    $document = $document->id() ? $this->entityTypes->getStorage('document')->loadUnchanged($document->id()) : NULL;
    if (!$document) {
      throw new \DomainException('The document no longer exists.');
    }
    if (!$this->account->isAuthenticated() || !$this->account->hasPermission('review documents') || !$document->access('view', $this->account)) {
      throw new AccessDeniedHttpException('You cannot review this document.');
    }
    return $document;
  }

  /**
   * Identifies the revision and file references presented for review.
   */
  public function fingerprint(DocumentInterface $document): string {
    return hash('sha256', serialize([
      $document->uuid(),
      $document->getRevisionId(),
      $document->get('file')->getValue(),
      $document->get('files')->getValue(),
    ]));
  }

  /**
   * Reads type-owned instructions and decisions.
   */
  public function policy(DocumentInterface $document): array {
    return $this->entityTypes->getStorage('document_type')->load($document->bundle())->getReview();
  }

  /**
   * Records the executing account; role and provenance are trusted caller data.
   *
   * Callers authorize the workflow role, never accepting it from action input.
   * Source and attempt are opaque IDs; document has no checklist dependency.
   */
  public function record(DocumentInterface $document, string $fingerprint, string $decision, string $role, string $reason = '', string $source = '', string $attempt = ''): DocumentReview {
    $document = $this->load($document);
    if (!hash_equals($this->fingerprint($document), $fingerprint)) {
      throw new \DomainException('The document changed. Reload it before reviewing.');
    }
    $policy = $this->policy($document);
    if (!isset($policy['options'][$decision]) || trim($role) === '' || mb_strlen($role) > 255 || mb_strlen($decision) > 128 || mb_strlen($policy['options'][$decision]) > 255) {
      throw new \InvalidArgumentException('Choose an available decision and provide a review role.');
    }
    $review = $this->entityTypes->getStorage('document_review')->create([
      'document' => $document->id(),
      'revision' => $document->getRevisionId(),
      'reviewer' => $this->account->id(),
      'role' => trim($role),
      'decision' => $decision,
      'decision_label' => $policy['options'][$decision],
      'reason' => trim($reason),
      'fingerprint' => $fingerprint,
      'source' => $source,
      'attempt' => $attempt,
      'receipt' => $source !== '' ? hash('sha256', serialize([$source, $attempt])) : NULL,
    ]);
    $review->save();
    return $review;
  }

}
