<?php

namespace Drupal\document\Review;

use Drupal\Core\Plugin\Context\ContextDefinition;
use Drupal\Core\Plugin\ContextAwarePluginInterface;
use Drupal\Core\Plugin\ContextAwarePluginTrait;
use Drupal\Core\Plugin\PluginBase;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Adapts the required reviewer to Drupal's context handler and mapping widget.
 */
final class ReviewerContext extends PluginBase implements ContextAwarePluginInterface {

  use ContextAwarePluginTrait;

  /**
   * Creates the context container for a named review definition.
   */
  public static function fromDefinition(array $definition): self {
    return new self(['context_mapping' => $definition['context_mapping']], 'document_review', [
      'context_definitions' => [
        'reviewer' => ContextDefinition::create('entity:user')
          ->setLabel(new TranslatableMarkup('Required reviewer'))
          ->setRequired(TRUE),
      ],
    ]);
  }

}
