<?php

namespace Drupal\document_checklist\Controller;

use Drupal\checklist\Ajax\OpenResourceCommand;
use Drupal\checklist\ChecklistActionResource;
use Drupal\checklist\ChecklistActionResourcePaneBuilder;
use Drupal\checklist\ChecklistContextPreparer;
use Drupal\checklist\ChecklistResolver;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Url;
use Drupal\document\Controller\ReviewHistoryController as DocumentHistoryController;
use Drupal\document\DocumentReviewHistory;
use Drupal\document_checklist\Plugin\ChecklistItemHandler\ReviewDocument;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Opens document review history in an authorized checklist's resource pane.
 */
class ReviewHistoryController extends ControllerBase {

  /**
   * Constructs the resource controller.
   */
  public function __construct(
    protected EntityTypeManagerInterface $entityManager,
    protected ChecklistResolver $resolver,
    protected ChecklistContextPreparer $preparer,
    protected DocumentReviewHistory $history,
    protected ChecklistActionResourcePaneBuilder $paneBuilder,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static($container->get('entity_type.manager'), $container->get('checklist.resolver'), $container->get('checklist.context_preparer'), $container->get('document.review_history'), $container->get('checklist.action_resource_pane_builder'));
  }

  /**
   * Resolves the document from the item, never from caller-supplied context.
   */
  public function view(Request $request, string $entity_type, string $entity_id, string $checklist, string $item_name): array|AjaxResponse {
    if (!$this->entityManager->hasDefinition($entity_type) || !preg_match('/^([^:]+)(?::(0|[1-9][0-9]*))?$/D', $checklist, $parts)) {
      throw new NotFoundHttpException();
    }
    $host = $this->entityManager->getStorage($entity_type)->loadUnchanged($entity_id);
    if (!$host instanceof FieldableEntityInterface) {
      throw new NotFoundHttpException();
    }
    $graph = $this->resolver->resolve($host, $parts[1], (int) ($parts[2] ?? 0));
    $item = $graph->getItem($item_name);
    if (!$item || !$item->access('view action state') || !$item->getHandler() instanceof ReviewDocument || !$this->preparer->prepare($graph, $item)) {
      throw new NotFoundHttpException();
    }
    $handler = $item->getHandler();
    if (!$handler->getContext('document')->hasContextValue()) {
      throw new NotFoundHttpException();
    }
    $document = $handler->getContextValue('document');
    $parameters = compact('entity_type', 'entity_id', 'checklist', 'item_name');
    $build = $this->history->build($document, DocumentHistoryController::cursor($request), Url::fromRoute('document_checklist.review_history', $parameters));
    if ($request->query->get('_wrapper_format') !== 'drupal_ajax') {
      return $build;
    }
    foreach (['older', 'latest'] as $name) {
      if (isset($build['navigation'][$name])) {
        $build['navigation'][$name]['#attributes']['class'][] = 'use-ajax';
      }
    }
    $key = 'document-review-history:' . $document->uuid();
    $resource = new ChecklistActionResource($key, $build, (string) $this->t('Review history'));
    $pane = $this->paneBuilder->build([$key => ['resource' => $resource, 'owners' => [$item_name]]], $graph);
    // Preserve this read-only resource across checklist workspace refreshes.
    $pane['panels'][$key]['#attributes']['data-checklist-history'] = 'true';
    $pane['#attached']['library'][] = 'checklist/interactive_checklist';
    return (new AjaxResponse())->addCommand(new OpenResourceCommand('#' . $this->paneBuilder->getWorkspaceId($graph), $pane));
  }

}
