<?php

namespace Drupal\document\Event;

use Drupal\document\Entity\DocumentInterface;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * All required reviews became approved inside the review transaction.
 */
final class ReviewRequirementsCompleted extends Event {

  public function __construct(public readonly DocumentInterface $document, public readonly array $requirements) {}

}
