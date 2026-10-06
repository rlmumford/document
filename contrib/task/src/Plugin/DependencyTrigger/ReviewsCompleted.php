<?php

namespace Drupal\document_task\Plugin\DependencyTrigger;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Plugin\PluginBase;
use Drupal\Core\Plugin\ContextAwarePluginTrait;
use Drupal\document\Entity\DocumentInterface;
use Drupal\task_dependency\OccurrenceTriggerInterface;

/**
 * All required people approved the document version being reviewed.
 *
 * @DependencyTrigger(
 *   id = "document.reviews_completed",
 *   label = @Translation("Document required reviews completed"),
 *   context_definitions = {
 *     "document" = @ContextDefinition("entity:document", label = @Translation("Reviewed document"))
 *   }
 * )
 */
final class ReviewsCompleted extends PluginBase implements OccurrenceTriggerInterface {

  use ContextAwarePluginTrait;

  /**
   * {@inheritdoc}
   */
  public function validateTarget(EntityInterface $target): void {
    if (!$target instanceof DocumentInterface) {
      throw new \InvalidArgumentException('Document reviews require a document binding.');
    }
  }

  /**
   * {@inheritdoc}
   */
  public function matches(EntityInterface $entity, ?EntityInterface $original): bool {
    return FALSE;
  }

}
