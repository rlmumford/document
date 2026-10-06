<?php

namespace Drupal\document;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Database\Connection;
use Drupal\document\Event\ReviewRequirementsCompleted;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Drupal\Core\Plugin\Context\ContextHandlerInterface;
use Drupal\Core\Plugin\Context\EntityContext;
use Drupal\document\Review\ReviewerContext;
use Drupal\document\Review\AnalysisSchema;
use Drupal\typed_data_plus\PlaceholderResolver;
use Drupal\typed_data\Exception\TypedDataException;
use Drupal\Component\Plugin\Exception\ContextException;
use Drupal\Component\Render\HtmlEscapedText;
use Drupal\Component\Utility\Html;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\document\Entity\DocumentInterface;
use Drupal\document\Entity\DocumentReview;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Records individual reviews without deciding aggregate document status.
 */
class DocumentReviewer {

  public function __construct(protected EntityTypeManagerInterface $entityTypes, protected AccountProxyInterface $account, protected ContextHandlerInterface $contexts, protected PlaceholderResolver $placeholders, protected Connection $database, protected EventDispatcherInterface $events) {}

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
      (string) $document->getRevisionId(),
      array_values(array_filter($document->get('file')->getValue(), static fn(array $item) => !empty($item['target_id']))),
      array_values(array_filter($document->get('files')->getValue(), static fn(array $item) => !empty($item['target_id']))),
    ]));
  }

  /**
   * Reads type-owned instructions and decisions.
   */
  public function policy(DocumentInterface $document, string $name): array {
    return $this->entityTypes->getStorage('document_type')->load($document->bundle())->getReview($name);
  }

  /**
   * Resolves a relationship-bound reviewer against fresh document data.
   */
  public function requiredReviewer(DocumentInterface $document, array $definition): ?int {
    if ($definition['eligibility'] === 'permission') {
      return NULL;
    }
    if ($definition['eligibility'] !== 'context' || empty($definition['context_mapping']['reviewer'])) {
      throw new \DomainException('The required reviewer is not configured.');
    }
    $mapping = ReviewerContext::fromDefinition($definition);
    $this->contexts->applyContextMapping($mapping, ['document' => EntityContext::fromEntity($document)]);
    $user = $mapping->getContextValue('reviewer');
    if (!$user || !$user->id()) {
      throw new \DomainException('The required reviewer is not available.');
    }
    return (int) $user->id();
  }

  /**
   * Checks the named requirement as well as ordinary document review access.
   */
  public function authorize(DocumentInterface $document, string $name): array {
    $document = $this->load($document);
    $definition = $this->policy($document, $name);
    try {
      $reviewer = $this->requiredReviewer($document, $definition);
    }
    catch (ContextException | TypedDataException | \DomainException $exception) {
      throw new AccessDeniedHttpException('The required reviewer could not be resolved.', $exception);
    }
    if ($definition['eligibility'] === 'permission') {
      if (empty($definition['permission']) || !$this->account->hasPermission($definition['permission'])) {
        throw new AccessDeniedHttpException('You cannot perform this named review.');
      }
    }
    elseif ($reviewer !== (int) $this->account->id()) {
      throw new AccessDeniedHttpException('This review must be performed by its required reviewer.');
    }
    return $definition;
  }

  /**
   * Prepares a type-owned prompt and output schema without invoking a model.
   *
   * An AI adapter must separately authorize its execution and persist AI run
   * provenance; this method never represents a model as a human reviewer.
   */
  public function prepareAnalysis(DocumentInterface $document, string $name): array {
    $document = $this->load($document);
    $definition = $this->authorize($document, $name);
    $prompt = $definition['prompt'];
    $replacements = $this->placeholders->resolvePlaceholders($prompt, ['document' => $document->getTypedData()]);
    foreach ($this->placeholders->scan($prompt) as $placeholders) {
      foreach ($placeholders as $placeholder) {
        if (!array_key_exists($placeholder, $replacements)) {
          throw new \DomainException('The review prompt contains an unresolved placeholder.');
        }
      }
    }
    foreach ($replacements as $key => $replacement) {
      $replacements[$key] = $replacement instanceof HtmlEscapedText ? Html::decodeEntities((string) $replacement) : (string) $replacement;
    }
    return [
      'review' => $name,
      'fingerprint' => $this->fingerprint($document),
      'prompt' => strtr($prompt, $replacements),
      'analysis_schema' => AnalysisSchema::parse($definition['analysis_schema']),
      'decisions' => $definition['options'],
    ];
  }

  /**
   * Reports current-version evidence for each required named review.
   *
   * Does not change document status. A partial decision cannot approve the
   * original full scope. A workflow must first narrow that scope explicitly.
   */
  public function requirements(DocumentInterface $document): array {
    return $this->evaluateRequirements($document);
  }

  /**
   * Evaluates evidence, optionally using current reads inside review recording.
   */
  protected function evaluateRequirements(DocumentInterface $document, bool $lock = FALSE): array {
    $document = $this->entityTypes->getStorage('document')->loadUnchanged($document->id());
    if (!$document || !$document->access('view', $this->account)) {
      throw new AccessDeniedHttpException('The document cannot be viewed.');
    }
    $result = [];
    $definitions = $this->entityTypes->getStorage('document_type')->load($document->bundle())->getReviews();
    $storage = $this->entityTypes->getStorage('document_review');
    foreach ($definitions as $name => $definition) {
      if (empty($definition['required'])) {
        continue;
      }
      $result[$name] = ['label' => $definition['label'], 'met' => FALSE, 'review' => NULL];
      try {
        $reviewer = $this->requiredReviewer($document, $definition);
      }
      catch (ContextException | TypedDataException | \DomainException) {
        continue;
      }
      $review = $storage->latestEvidence((string) $document->id(), $this->fingerprint($document), $name, $reviewer, $lock);
      if ($review) {
        $result[$name]['review'] = $review['uuid'];
        $result[$name]['met'] = isset($definition['options']['approved']) && $review['decision'] === 'approved';
      }
    }
    return $result;
  }

  /**
   * Exposes current evidence without changing the document workflow status.
   */
  public function summary(DocumentInterface $document): array {
    $requirements = $this->requirements($document);
    $met = count(array_filter($requirements, static fn(array $requirement) => $requirement['met']));
    return [
      'status' => !$requirements ? 'not_required' : ($met === count($requirements) ? 'complete' : 'pending'),
      'met' => $met,
      'total' => count($requirements),
      'requirements' => $requirements,
    ];
  }

  /**
   * Records the executing account; role and provenance are trusted caller data.
   *
   * Callers authorize the workflow role, never accepting it from action input.
   * Source and attempt are opaque IDs; document has no checklist dependency.
   */
  public function record(DocumentInterface $document, string $fingerprint, string $decision, string $role, string $reason = '', string $source = '', string $attempt = '', array|\stdClass|null $analysis = NULL): DocumentReview {
    $transaction = $this->database->startTransaction();
    try {
      // Serialize reviewers of the same document before reading their evidence.
      $this->database->select('document', 'd')->fields('d', ['id'])
        ->condition('id', $document->id())->forUpdate()->execute()->fetchField();
      return $this->recordReview($document, $fingerprint, $decision, $role, $reason, $source, $attempt, $analysis);
    }
    catch (\Throwable $exception) {
      $transaction->rollBack();
      throw $exception;
    }
  }

  /**
   * Writes evidence and notifies consumers in the same database transaction.
   */
  protected function recordReview(DocumentInterface $document, string $fingerprint, string $decision, string $role, string $reason, string $source, string $attempt, array|\stdClass|null $analysis): DocumentReview {
    $document = $this->load($document);
    if (!hash_equals($this->fingerprint($document), $fingerprint)) {
      throw new \DomainException('The document changed. Reload it before reviewing.');
    }
    $policy = $this->authorize($document, $role);
    if (!isset($policy['options'][$decision]) || trim($role) === '' || mb_strlen($role) > 255 || mb_strlen($decision) > 128 || mb_strlen($policy['options'][$decision]) > 255) {
      throw new \InvalidArgumentException('Choose an available decision and provide a review role.');
    }
    $before = $this->evaluateRequirements($document, TRUE);
    $review = $this->entityTypes->getStorage('document_review')->create([
      'document' => $document->id(),
      'revision' => (string) $document->getRevisionId(),
      'reviewer' => $this->account->id(),
      'role' => $role,
      'role_label' => $policy['label'],
      'analysis' => $analysis !== NULL ? AnalysisSchema::validate($policy['analysis_schema'], $analysis) : NULL,
      'analysis_schema' => $analysis !== NULL ? $policy['analysis_schema'] : NULL,
      'decision' => $decision,
      'decision_label' => $policy['options'][$decision],
      'reason' => trim($reason),
      'fingerprint' => $fingerprint,
      'source' => $source,
      'attempt' => $attempt,
      'receipt' => $source !== '' ? hash('sha256', serialize([$source, $attempt])) : NULL,
    ]);
    $review->save();
    $after = $this->evaluateRequirements($document, TRUE);
    $complete = static fn(array $requirements): bool => $requirements && !in_array(FALSE, array_column($requirements, 'met'), TRUE);
    if (!$complete($before) && $complete($after)) {
      $this->events->dispatch(new ReviewRequirementsCompleted($document, $after));
    }
    return $review;
  }

}
