<?php

namespace Drupal\Tests\document_checklist\Functional;

use Drupal\document\Entity\Document;
use Drupal\document\Entity\DocumentReview;
use Drupal\document\Entity\DocumentType;
use Drupal\Tests\BrowserTestBase;

/**
 * Tests authorized, bounded history with historical labels and safe rendering.
 *
 * @group document
 */
class ReviewHistoryTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['document'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * View-only users can read evidence without reviewing or seeing private data.
   */
  public function testReviewHistory(): void {
    $viewer = $this->drupalCreateUser(['view any document']);
    $reviewer = $this->drupalCreateUser();
    DocumentType::create(['id' => 'agreement', 'label' => 'Agreement'])->save();
    $document = Document::create(['type' => 'agreement', 'label' => 'Agreement', 'status' => 'received']);
    $document->save();
    $other = Document::create(['type' => 'agreement', 'label' => 'Other agreement']);
    $other->save();
    $fingerprint = $this->container->get('document.reviewer')->fingerprint($document);
    for ($i = 0; $i < 27; $i++) {
      DocumentReview::create([
        'document' => $document->id(),
        'reviewer' => $reviewer->id(),
        'revision' => $document->getRevisionId(),
        'role' => 'staff',
        'role_label' => 'Original staff label',
        'decision' => 'approved',
        'decision_label' => 'Original approval label',
        'fingerprint' => $i === 0 ? 'earlier-fingerprint' : $fingerprint,
        'reason' => $i === 26 ? '<script>alert("unsafe")</script>' : "Evidence $i",
        'analysis' => '{"private":"Hidden analysis payload"}',
        'receipt' => 'test-history-' . $i,
      ])->save();
    }
    DocumentReview::create([
      'document' => $other->id(),
      'reviewer' => $reviewer->id(),
      'reason' => 'Other document evidence',
      'receipt' => 'other',
    ])->save();
    $path = '/document/' . $document->id() . '/reviews';
    $this->drupalGet($path);
    $this->assertSession()->statusCodeEquals(403);
    $this->drupalLogin($viewer);
    $this->drupalGet($path);
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->elementsCount('css', '.document-review-history-entry', 25);
    $this->assertSession()->pageTextContains('Original staff label');
    $this->assertSession()->pageTextContains('Original approval label');
    $this->assertSession()->pageTextContains('Current version');
    $this->assertSession()->pageTextContains('User ' . $reviewer->id());
    $this->assertSession()->pageTextNotContains($reviewer->getAccountName());
    $this->assertSession()->responseContains('&lt;script&gt;');
    $this->assertSession()->responseNotContains('<script>alert');
    $this->assertSession()->pageTextNotContains('Hidden analysis payload');
    $this->assertSession()->pageTextNotContains('Other document evidence');
    $this->assertSession()->pageTextNotContains('Evidence 0');
    $this->clickLink('Older reviews');
    $this->assertSession()->elementsCount('css', '.document-review-history-entry', 2);
    $this->assertSession()->pageTextContains('Earlier version');
    $this->assertSession()->pageTextContains('Evidence 0');
    $this->assertSession()->linkNotExists('Older reviews');
    $this->clickLink('Latest reviews');
    $this->assertSession()->elementsCount('css', '.document-review-history-entry', 25);
    $this->drupalGet($path, ['query' => ['before' => '-1']]);
    $this->assertSession()->statusCodeEquals(400);
    $this->drupalGet('/document/' . $other->id() . '/reviews');
    $this->assertSession()->elementsCount('css', '.document-review-history-entry', 1);
    $this->assertSession()->pageTextNotContains('Original staff label');
  }

}
