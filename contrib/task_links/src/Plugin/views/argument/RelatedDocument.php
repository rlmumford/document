<?php

namespace Drupal\document_task_links\Plugin\views\argument;

use Drupal\document_task_links\RelatedWork;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\views\Plugin\views\argument\ArgumentPluginBase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Restricts tasks through a subquery, preserving distinct rows and counts.
 *
 * @ViewsArgument("document_task_links")
 */
final class RelatedDocument extends ArgumentPluginBase {

  /**
   * Constructs the contextual filter.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, private readonly RelatedWork $work, private readonly EntityTypeManagerInterface $entities) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static($configuration, $plugin_id, $plugin_definition, $container->get('document_task_links.work'), $container->get('entity_type.manager'));
  }

  /**
   * {@inheritdoc}
   */
  public function query($group_by = FALSE) {
    $this->ensureMyTable();
    $document = ctype_digit((string) $this->argument) ? $this->entities->getStorage('document')->load($this->argument) : NULL;
    if (!$document || !$document->access('view')) {
      $this->query->addWhereExpression(0, '1 = 0');
      return;
    }
    $query = $this->work->taskQuery($document);
    $this->query->addWhere(0, $this->tableAlias . '.id', $query, 'IN');
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheMaxAge() {
    return 0;
  }

}
