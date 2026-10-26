<?php

namespace Drupal\document_task_links\Plugin\views\field;

use Drupal\document_task_links\LinkPresentation;
use Drupal\views\Plugin\views\field\FieldPluginBase;
use Drupal\views\ResultRow;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Shows saved context and outcome links without multiplying task rows.
 *
 * @ViewsField("document_task_links")
 */
final class DocumentRelations extends FieldPluginBase {

  /**
   * Constructs the relationship field.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, private readonly LinkPresentation $presentation) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static($configuration, $plugin_id, $plugin_definition, $container->get('document_task_links.presentation'));
  }

  /**
   * {@inheritdoc}
   */
  public function render(ResultRow $values) {
    $task = $this->getEntity($values);
    return $task ? $this->presentation->relations($task, (int) ($this->view->args[0] ?? 0)) : [];
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheMaxAge() {
    return 0;
  }

}
