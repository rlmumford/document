<?php

namespace Drupal\Tests\document\Unit;

use Drupal\document\Entity\Document;
use Drupal\Tests\UnitTestCase;

/**
 * Checks the statuses a document can be in.
 *
 * The set matters more than it looks. "Needed" is the one that justifies the
 * entity existing at all: a document nobody has sent yet is still a thing the
 * system has to hold an opinion about, and a bare file cannot represent the
 * absence of itself. "Superseded" is the other: replacing a document must not
 * mean destroying the one it replaced.
 *
 * @coversDefaultClass \Drupal\document\Entity\Document
 *
 * @group document
 */
class DocumentStatusTest extends UnitTestCase {

  /**
   * The statuses ported from CounselKit, which callers may rely on existing.
   */
  protected const EXPECTED = [
    'needed',
    'not_needed',
    'received',
    'refused',
    'approved',
    'rejected',
    'expired',
    'superseded',
  ];

  /**
   * Tests the status list is the one that was ported.
   *
   * A reflection test rather than a container one: the point is to pin the
   * vocabulary, so that removing a value is a deliberate act with a failing
   * test attached rather than a tidy-up somebody does in passing.
   */
  public function testTheStatusVocabularyIsPinned() {
    $source = file_get_contents((new \ReflectionClass(Document::class))->getFileName());
    preg_match("/'status'.*?setSetting\('allowed_values', \[(.*?)\]\)/s", $source, $matches);
    $this->assertNotEmpty($matches, 'The status field still declares allowed values.');

    preg_match_all("/'([a-z_]+)' => \(string\) new TranslatableMarkup/", $matches[1], $found);

    $this->assertSame(static::EXPECTED, $found[1]);
  }

  /**
   * Tests that a document asked for but not yet given is representable.
   *
   * @covers ::getStatus
   */
  public function testNeededIsTheDefault() {
    $source = file_get_contents((new \ReflectionClass(Document::class))->getFileName());
    $this->assertStringContainsString(
      "->setDefaultValue('needed')",
      $source,
      'A new document starts as one we are waiting for, not one we have.'
    );
  }

}
