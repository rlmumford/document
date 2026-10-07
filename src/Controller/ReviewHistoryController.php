<?php

namespace Drupal\document\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\document\DocumentReviewHistory;
use Drupal\document\Entity\DocumentInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Displays the document's review evidence without executing review actions.
 */
class ReviewHistoryController extends ControllerBase {

  /**
   * Constructs the history controller.
   */
  public function __construct(protected DocumentReviewHistory $history) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static($container->get('document.review_history'));
  }

  /**
   * Displays a page of review evidence.
   */
  public function view(DocumentInterface $document, Request $request): array {
    return $this->history->build($document, $this->cursor($request), Url::fromRoute('document.review_history', ['document' => $document->id()]));
  }

  /**
   * Validates the optional pagination cursor.
   */
  public static function cursor(Request $request): int {
    $before = filter_var($request->query->all()['before'] ?? 0, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
    if ($before === FALSE) {
      throw new BadRequestHttpException('Invalid review history cursor.');
    }
    return $before;
  }

}
