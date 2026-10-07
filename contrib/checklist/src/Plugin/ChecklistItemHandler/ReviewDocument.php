<?php

namespace Drupal\document_checklist\Plugin\ChecklistItemHandler;

use Drupal\checklist\ChecklistActionResource;
use Drupal\checklist\ChecklistActionState;
use Drupal\checklist\Plugin\ChecklistItemHandler\ActionStateChecklistItemHandlerInterface;
use Drupal\checklist\Entity\ChecklistItemInterface;
use Drupal\checklist\Plugin\ChecklistItemHandler\ContextAwareChecklistItemHandlerBase;
use Drupal\checklist\Plugin\ChecklistItemHandler\ChecklistItemHandlerInterface;
use Drupal\checklist\Plugin\ChecklistItemHandler\InteractiveChecklistItemHandlerInterface;
use Drupal\checklist\Plugin\ChecklistItemHandler\ExpectedOutcomeChecklistItemHandlerInterface;
use Drupal\checklist\Plugin\ChecklistItemHandler\ActionOperationsChecklistItemHandlerInterface;
use Drupal\checklist\Plugin\ChecklistItemHandler\ActionResourceChecklistItemHandlerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\TypedData\EntityDataDefinition;
use Drupal\Core\Database\Connection;
use Drupal\Core\TypedData\DataDefinition;
use Drupal\Core\Url;
use Drupal\document\DocumentReviewer;
use Drupal\document\Review\AnalysisSchema;
use Drupal\checklist\Attempt\ChecklistAttemptJournal;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Records a human review using the document type's review policy.
 *
 * @ChecklistItemHandler(
 *   id = "document_review",
 *   label = @Translation("Review document"),
 *   category = @Translation("Document"),
 *   context_definitions = {
 *     "document" = @ContextDefinition("entity:document", required = TRUE, label = @Translation("Document"))
 *   },
 *   forms = {
 *     "configure" = "\Drupal\document_checklist\PluginForm\ReviewConfigureForm",
 *     "row" = "\Drupal\checklist\PluginForm\StartableItemRowForm",
 *     "action" = "\Drupal\document_checklist\PluginForm\ReviewActionForm"
 *   }
 * )
 */
class ReviewDocument extends ContextAwareChecklistItemHandlerBase implements InteractiveChecklistItemHandlerInterface, ExpectedOutcomeChecklistItemHandlerInterface, ActionOperationsChecklistItemHandlerInterface, ActionResourceChecklistItemHandlerInterface, ActionStateChecklistItemHandlerInterface {

  /**
   * Records reviews.
   */
  protected DocumentReviewer $reviewer;
  /**
   * Entity storage and display builders.
   */
  protected EntityTypeManagerInterface $entityTypes;
  /**
   * Atomic review and outcome persistence.
   */
  protected Connection $database;
  /**
   * Execution provenance.
   */
  protected ChecklistAttemptJournal $journal;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->reviewer = $container->get('document.reviewer');
    $instance->entityTypes = $container->get('entity_type.manager');
    $instance->database = $container->get('database');
    $instance->journal = $container->get('checklist.attempt_journal');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return ['role' => 'staff'] + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function getMethod(): string {
    return ChecklistItemInterface::METHOD_INTERACTIVE;
  }

  /**
   * {@inheritdoc}
   */
  public function action(): ChecklistItemHandlerInterface {
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function expectedOutcomeDefinitions(): array {
    // The document type is selected through runtime contexts. The review keeps
    // the historical choice label even if that type's choices later change.
    return [
      'decision' => DataDefinition::create('string')->setLabel($this->t('Decision')),
      'review' => EntityDataDefinition::create('document_review')->setLabel($this->t('Review')),
      'document' => EntityDataDefinition::create('document')->setLabel($this->t('Reviewed document')),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function isActionable(): bool {
    if (!parent::isActionable()) {
      return FALSE;
    }
    try {
      $this->reviewer->authorize($this->getContextValue('document'), $this->getConfiguration()['role']);
      return TRUE;
    }
    catch (AccessDeniedHttpException | \DomainException | \InvalidArgumentException) {
      return FALSE;
    }
  }

  /**
   * Gets current policy and a token identifying the document shown to the user.
   */
  public function reviewInput(): array {
    $item = $this->getItem();
    if (!$item->get('checklist')->checklist->getEntity()->access('update')) {
      throw new AccessDeniedHttpException('The checklist cannot be updated.');
    }
    $document = $this->reviewer->load($this->getContextValue('document'));
    $definition = $this->reviewer->authorize($document, $this->getConfiguration()['role']);
    if (!$item->isIncomplete() || $item->isApplicable() !== TRUE || !$item->isActionable()) {
      throw new \DomainException('The document review is not actionable.');
    }
    return $definition + [
      'fingerprint' => $this->reviewer->fingerprint($document),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function actionOperations(): array {
    try {
      $input = $this->reviewInput();
    }
    catch (AccessDeniedHttpException | \DomainException) {
      return [];
    }
    return [
      'review' => [
        'label' => (string) $this->t('Record review'),
        'description' => $input['instructions'],
        'parameters_schema' => [
          'type' => 'object',
          'properties' => [
            'decision' => [
              'type' => 'string',
              'enum' => array_keys($input['options']),
              'x-enum-labels' => array_values($input['options']),
            ],
            'fingerprint' => ['type' => 'string', 'const' => $input['fingerprint']],
            'reason' => ['type' => 'string'],
            'analysis' => json_decode(json_encode(AnalysisSchema::parse($input['analysis_schema'])), TRUE),
          ],
          'required' => ['decision', 'fingerprint'],
          'additionalProperties' => FALSE,
        ],
        'result_schema' => [
          'type' => 'object',
          'properties' => ['review_uuid' => ['type' => 'string'], 'decision' => ['type' => 'string']],
          'required' => ['review_uuid', 'decision'],
          'additionalProperties' => FALSE,
        ],
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function executeActionOperation(string $operation, array $parameters): array {
    if ($operation !== 'review' || array_diff(array_keys($parameters), ['decision', 'fingerprint', 'reason', 'analysis']) || !is_string($parameters['decision'] ?? NULL) || !is_string($parameters['fingerprint'] ?? NULL) || !is_string($parameters['reason'] ?? '')) {
      throw new \InvalidArgumentException('Provide a decision, document fingerprint and optional reason.');
    }
    if (isset($parameters['analysis']) && !is_array($parameters['analysis']) && !$parameters['analysis'] instanceof \stdClass) {
      throw new \InvalidArgumentException('Analysis must be an object.');
    }
    $this->reviewInput();
    $transaction = $this->database->startTransaction();
    try {
      $item = $this->getItem();
      $document = $this->getContextValue('document');
      $review = $this->reviewer->record($document, $parameters['fingerprint'], $parameters['decision'], $this->getConfiguration()['role'], $parameters['reason'] ?? '', $item->uuid(), $this->journal->latest($item)?->id ?? '', $parameters['analysis'] ?? NULL);
      $item->setOutcome('review', $review);
      $item->setOutcome('document', $document);
      $item->setOutcome('decision', $parameters['decision']);
      $item->setComplete(ChecklistItemInterface::METHOD_INTERACTIVE)->save();
      return ['review_uuid' => $review->uuid(), 'decision' => $parameters['decision']];
    }
    catch (\Throwable $exception) {
      $transaction->rollBack();
      throw $exception;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getActionState(): ?ChecklistActionState {
    if (!$this->getItem()->isComplete()) {
      return NULL;
    }
    $review = $this->getItem()->get('outcomes')->get('review')->getValue();
    if (!$review || !$review->access('view')) {
      return NULL;
    }
    $reviewer = $review->get('reviewer')->entity;
    return new ChecklistActionState(
      stage: 'reviewed',
      message: (string) $this->t('@decision — @reviewer (@role)', [
        '@decision' => $review->get('decision_label')->value,
        '@reviewer' => $reviewer ? $reviewer->label() : $this->t('Deleted account'),
        '@role' => $review->get('role_label')->value ?: $review->get('role')->value,
      ]),
      updatedAt: (int) $review->get('created')->value,
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getActionResource(): ?ChecklistActionResource {
    if (!$this->getContext('document')->hasContextValue()) {
      return NULL;
    }
    $document = $this->getContextValue('document');
    $document = $document->id() ? $this->entityTypes->getStorage('document')->loadUnchanged($document->id()) : NULL;
    if (!$document || !$document->access('view')) {
      return NULL;
    }
    $build = $this->entityTypes->getViewBuilder('document')->view($document);
    $item = $this->getItem();
    $checklist = $item->get('checklist')->checklist;
    $host = $checklist->getEntity();
    if ($host->id() && $item->access('view action state')) {
      $build['#document_review_history_url'] = Url::fromRoute('document_checklist.review_history', [
        'entity_type' => $host->getEntityTypeId(),
        'entity_id' => $host->id(),
        'checklist' => $checklist->getKey(),
        'item_name' => $item->getName(),
      ]);
      // The link depends on the checklist address, not just this document.
      $build['#cache']['max-age'] = 0;
    }
    return new ChecklistActionResource('document:' . $document->uuid(), $build, (string) $this->t('Document'), closeable: FALSE, pinned: TRUE);
  }

}
