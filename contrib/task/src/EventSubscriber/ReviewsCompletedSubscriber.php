<?php

namespace Drupal\document_task\EventSubscriber;

use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\document\Event\ReviewRequirementsCompleted;
use Drupal\task_dependency\DependencyManager;
use Drupal\task_job\Plugin\JobTrigger\JobTriggerManagerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Delivers the same occurrence to existing waits and optional job triggers.
 */
final class ReviewsCompletedSubscriber implements EventSubscriberInterface {

  public function __construct(protected DependencyManager $dependencies, protected ModuleHandlerInterface $modules, protected ?JobTriggerManagerInterface $jobs = NULL) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [ReviewRequirementsCompleted::class => 'completed'];
  }

  /**
   * Runs within the review transaction; consumer failures roll back evidence.
   */
  public function completed(ReviewRequirementsCompleted $event): void {
    $this->dependencies->recordOccurrence('document.reviews_completed', $event->document);
    if ($this->modules->moduleExists('task_dependency_job')) {
      $this->jobs->handleTrigger('dependency_event:document.reviews_completed', ['document' => $event->document]);
    }
  }

}
