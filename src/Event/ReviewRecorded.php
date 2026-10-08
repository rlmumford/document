<?php

namespace Drupal\document\Event;

use Drupal\document\Entity\DocumentInterface;
use Drupal\document\Entity\DocumentReview;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * One authorized decision was recorded inside the review transaction.
 *
 * Consumers may persist follow-up work in this transaction. External effects
 * must be dispatched after commit, since later consumers may still roll back.
 */
final class ReviewRecorded extends Event {

  public function __construct(public readonly DocumentInterface $document, public readonly DocumentReview $review) {}

}
