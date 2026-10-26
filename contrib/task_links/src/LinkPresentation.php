<?php

namespace Drupal\document_task_links;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Link;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\task\TaskInterface;

/**
 * Presents the saved context and outcome relationships.
 */
final class LinkPresentation {
  use StringTranslationTrait;

  /**
   * Constructs the presenter.
   */
  public function __construct(private readonly RelatedWork $work, private readonly EntityTypeManagerInterface $entities) {}

  /**
   * Lists documents referenced by this task and its checklist outcomes.
   */
  public function documents(TaskInterface $task): array {
    if (!$task->access('view')) {
      return [];
    }
    $references = $this->work->references($task);
    $ids = array_unique(array_column($references, 'document_id'));
    $items = [];
    foreach ($this->entities->getStorage('document')->loadMultiple($ids) as $document) {
      if ($document->access('view') && $this->work->compatible($task, $document)) {
        $items[] = [
          'document' => $document->hasLinkTemplate('canonical') ? Link::fromTextAndUrl($document->label(), $document->toUrl())->toRenderable() : ['#plain_text' => $document->label()],
          'relations' => $this->labels(array_filter($references, static fn(array $reference) => $reference['document_id'] == $document->id())),
        ];
      }
    }
    return $items ? [
      '#type' => 'details',
      '#title' => $this->t('Related documents'),
      '#open' => TRUE,
      '#cache' => ['max-age' => 0],
      'items' => ['#theme' => 'item_list', '#items' => $items],
    ] : [];
  }

  /**
   * Shows the context or the originating checklist item and outcome name.
   */
  public function relations(TaskInterface $task, int $document_id): array {
    $document = $this->entities->getStorage('document')->load($document_id);
    if (!$task->access('view') || !$document || !$document->access('view') || !$this->work->compatible($task, $document)) {
      return [];
    }
    return $this->labels(array_filter($this->work->references($task), static fn(array $reference) => $reference['document_id'] == $document_id));
  }

  /**
   * Formats saved relationship identities without asserting document creation.
   */
  private function labels(array $references): array {
    $items = [];
    foreach ($references as $reference) {
      $label = $reference['kind'] === 'context'
        ? $this->t('Context: @label (@name)', ['@label' => $reference['label'], '@name' => $reference['name']])
        : $this->t('Outcome: @label (@name) — @item (@item_name)', [
          '@label' => $reference['label'],
          '@name' => $reference['name'],
          '@item' => $reference['item_title'] ?: $reference['item_name'],
          '@item_name' => $reference['item_name'],
        ]);
      $items[(string) $label] = ['#markup' => $label];
    }
    return ['#theme' => 'item_list', '#items' => array_values($items), '#cache' => ['max-age' => 0]];
  }

}
