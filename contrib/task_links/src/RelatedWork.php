<?php

namespace Drupal\document_task_links;

use Drupal\Component\Plugin\PluginManagerInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Query\SelectInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\task\TaskInterface;
use Drupal\typed_reference_index\EntityReferences;
use Drupal\typed_reference_index\ReferenceIndex;

/**
 * Finds work through saved contexts, outcomes and checklist host references.
 */
final class RelatedWork {

  /**
   * Constructs the read-only relationship lookup.
   */
  public function __construct(private readonly Connection $database, private readonly EntityTypeManagerInterface $entities, private readonly EntityFieldManagerInterface $fields, private readonly EntityReferences $references, private readonly ReferenceIndex $index, private readonly PluginManagerInterface $checklistTypes, private readonly ModuleHandlerInterface $modules) {}

  /**
   * Returns checklist bundles whose host reference is a task.
   */
  private function taskChecklistTypes(): array {
    return array_keys(array_filter($this->checklistTypes->getDefinitions(), static fn(array $definition) => $definition['entity_type'] === 'task'));
  }

  /**
   * Returns a task-ID query for a document; normal Views task access also runs.
   */
  public function taskQuery(EntityInterface $document): SelectInterface {
    $query = $this->database->select('task', 'related_task')->fields('related_task', ['id']);
    $matches = $query->orConditionGroup();
    $matches->condition('related_task.id', $this->index->referringSources('document', (string) $document->id(), 'task', 'context'), 'IN');
    if ($bundles = $this->taskChecklistTypes()) {
      $outcomes = $this->index->referringSources('document', (string) $document->id(), 'checklist_item', 'outcomes');
      $storage = $this->entities->getStorage('checklist_item');
      $mapping = $storage->getTableMapping();
      $field = $this->fields->getFieldStorageDefinitions('checklist_item')['checklist'];
      $column = $mapping->getFieldColumnName($field, 'target_id');
      $items = $this->database->select($mapping->getFieldTableName('checklist'), 'item')->fields('item', [$column])
        ->condition('item.checklist_type', $bundles, 'IN')->condition('item.id', $outcomes, 'IN');
      $matches->condition('related_task.id', $items, 'IN');
    }
    // Ordinary reference fields already have queryable SQL storage. Do not
    // duplicate them in the typed-reference index.
    $storage = $this->entities->getStorage('task');
    $mapping = $storage->getTableMapping();
    foreach ($this->fields->getFieldStorageDefinitions('task') as $name => $field) {
      if ($field->isComputed() || $field->getSetting('target_type') !== 'document') {
        continue;
      }
      $dedicated = $mapping->requiresDedicatedTableStorage($field);
      $references = $this->database->select($mapping->getFieldTableName($name), 'reference')
        ->fields('reference', [$dedicated ? 'entity_id' : 'id'])
        ->condition($mapping->getFieldColumnName($field, 'target_id'), $document->id());
      if ($dedicated) {
        $references->condition('deleted', 0);
      }
      $matches->condition('related_task.id', $references, 'IN');
    }
    $query->condition($matches);
    $this->modules->alter('document_task_links_query', $query, $document);
    return $query;
  }

  /**
   * Applies relationship boundaries independently of viewer access.
   */
  public function compatible(TaskInterface $task, EntityInterface $document): bool {
    $allowed = TRUE;
    $this->modules->alter('document_task_links_compatible', $allowed, $task, $document);
    return $allowed;
  }

  /**
   * Reads current relationships; completed tasks retain their saved outcomes.
   *
   * These values are also used to label query results. They do not come from
   * the reverse index, and no missing/removed relationship is reconstructed.
   */
  public function references(TaskInterface $task): array {
    $result = [];
    foreach ($task->getFields(FALSE) as $name => $field) {
      if (!$field->access('view')) {
        continue;
      }
      if ($name === 'context') {
        foreach ($this->references->collect($field) as $reference) {
          if ($reference['entity_type'] === 'document') {
            $result[] = [
              'document_id' => $reference['entity_id'],
              'kind' => 'context',
              'name' => $reference['path'],
              'label' => $reference['label'],
            ];
          }
        }
      }
      elseif ($field->getSetting('target_type') === 'document') {
        foreach ($field as $item) {
          if ($item->target_id) {
            $result[] = [
              'document_id' => $item->target_id,
              'kind' => 'context',
              'name' => $name,
              'label' => (string) $field->getFieldDefinition()->getLabel(),
            ];
          }
        }
      }
    }
    $bundles = $this->taskChecklistTypes();
    if (!$bundles) {
      return $result;
    }
    $storage = $this->entities->getStorage('checklist_item');
    $ids = $storage->getQuery()->accessCheck(FALSE)->condition('checklist_type', $bundles, 'IN')->condition('checklist.target_id', $task->id())->execute();
    foreach ($storage->loadMultiple($ids) as $item) {
      if (!$item->access('view action state') || !$item->get('outcomes')->access('view')) {
        continue;
      }
      foreach ($this->references->collect($item->get('outcomes')) as $reference) {
        if ($reference['entity_type'] === 'document') {
          $result[] = [
            'document_id' => $reference['entity_id'],
            'kind' => 'outcome',
            'name' => $reference['path'],
            'label' => $reference['label'],
            'item_name' => $item->get('name')->value,
            'item_title' => $item->get('title')->value,
          ];
        }
      }
    }
    return $result;
  }

}
