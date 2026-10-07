<?php

namespace Drupal\document;

use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Drupal\document\Entity\DocumentInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Builds bounded, read-only pages of document review evidence.
 */
class DocumentReviewHistory {

  use StringTranslationTrait;

  /**
   * Constructs the history builder.
   */
  public function __construct(
    protected EntityTypeManagerInterface $entityTypes,
    protected DocumentReviewer $reviewer,
    protected DateFormatterInterface $dateFormatter,
  ) {}

  /**
   * Displays evidence with historical labels and current-version attribution.
   *
   * The document and each review must be viewable. No review permission is
   * required, and analysis payloads are deliberately excluded. The cursor uses
   * immutable review IDs so new reviews cannot shift older pages underneath us.
   */
  public function build(DocumentInterface $document, int $before, Url $url): array {
    $document = $document->id() ? $this->entityTypes->getStorage('document')->loadUnchanged($document->id()) : NULL;
    if (!$document || !$document->access('view')) {
      throw new AccessDeniedHttpException();
    }
    $storage = $this->entityTypes->getStorage('document_review');
    // Entity query access is not provided by this entity type. Enforce document
    // access above and review access below before rendering evidence.
    $query = $storage->getQuery()->accessCheck(FALSE)->condition('document', $document->id())->sort('id', 'DESC')->range(0, 26);
    if ($before > 0) {
      $query->condition('id', $before, '<');
    }
    $ids = array_values($query->execute());
    $more = count($ids) > 25;
    $ids = array_slice($ids, 0, 25);
    $reviews = array_filter($storage->loadMultiple($ids), fn($review) => $review->access('view'));
    $account_ids = array_unique(array_map(fn($review) => $review->get('reviewer')->target_id, $reviews));
    $accounts = $this->entityTypes->getStorage('user')->loadMultiple($account_ids);
    $fingerprint = $this->reviewer->fingerprint($document);
    $build = [
      '#type' => 'container',
      '#title' => $this->t('Review history'),
      '#cache' => ['max-age' => 0],
      '#attached' => ['library' => ['document/review_history']],
      '#attributes' => ['class' => ['document-review-history']],
      'description' => [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $this->t('Reviews of @document, most recently recorded first. See Required reviews on the document for current approval progress.', ['@document' => $document->label()]),
      ],
      'reviews' => ['#type' => 'container'],
      'navigation' => ['#type' => 'container', '#attributes' => ['class' => ['document-review-history-navigation']]],
    ];
    foreach ($reviews as $id => $review) {
      $account_id = $review->get('reviewer')->target_id;
      $account = $accounts[$account_id] ?? NULL;
      $person = $account && $account->access('view') ? $account->label() : $this->t('User @id', ['@id' => $account_id]);
      $current = $review->get('fingerprint')->value === $fingerprint;
      $build['reviews'][$id] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['document-review-history-entry']],
        'decision' => [
          '#type' => 'html_tag',
          '#tag' => 'h3',
          '#value' => $this->t('@decision — @role', [
            '@decision' => $review->get('decision_label')->value ?: $review->get('decision')->value,
            '@role' => $review->get('role_label')->value ?: $review->get('role')->value,
          ]),
        ],
        'reviewer' => [
          '#type' => 'html_tag',
          '#tag' => 'p',
          '#value' => $this->t('@person · @date', [
            '@person' => $person,
            '@date' => $this->dateFormatter->format((int) $review->get('created')->value, 'custom', 'j M Y H:i:s T'),
          ]),
        ],
        'version' => [
          '#type' => 'html_tag',
          '#tag' => 'p',
          '#attributes' => ['class' => ['document-review-history-version']],
          '#value' => $current ? $this->t('Current version') : $this->t('Earlier version'),
        ],
      ];
      if ($review->get('reason')->value !== NULL && $review->get('reason')->value !== '') {
        $build['reviews'][$id]['reason'] = [
          '#type' => 'container',
          '#attributes' => ['class' => ['document-review-history-reason']],
          'text' => ['#plain_text' => $review->get('reason')->value],
        ];
      }
    }
    if (!$reviews) {
      $build['reviews']['empty'] = ['#markup' => $this->t('No reviews to display.')];
    }
    if ($more) {
      $next = clone $url;
      $next->setOption('query', ['before' => end($ids)]);
      $build['navigation']['older'] = ['#type' => 'link', '#title' => $this->t('Older reviews'), '#url' => $next];
    }
    if ($before > 0) {
      $build['navigation']['latest'] = ['#type' => 'link', '#title' => $this->t('Latest reviews'), '#url' => $url];
    }
    return $build;
  }

}
