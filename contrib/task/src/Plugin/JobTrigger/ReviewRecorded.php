<?php

namespace Drupal\document_task\Plugin\JobTrigger;

use Drupal\task_job\Plugin\JobTrigger\JobTriggerBase;

/**
 * Creates configured follow-up work from an individual review decision.
 *
 * @JobTrigger(
 *   id = "document.review_recorded",
 *   label = @Translation("Document review recorded"),
 *   category = @Translation("Document"),
 *   context_definitions = {
 *     "document" = @ContextDefinition("entity:document", label = @Translation("Reviewed document")),
 *     "review" = @ContextDefinition("entity:document_review", label = @Translation("Recorded review"))
 *   }
 * )
 */
class ReviewRecorded extends JobTriggerBase {

  /**
   * {@inheritdoc}
   */
  protected function getDefaultKey(): string {
    return $this->getPluginId();
  }

  /**
   * {@inheritdoc}
   */
  public function getLabel() {
    return $this->pluginDefinition['label'];
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription() {
    return $this->t('Run after an individual review is recorded. Use conditions on the recorded review to select its role and decision.');
  }

}
