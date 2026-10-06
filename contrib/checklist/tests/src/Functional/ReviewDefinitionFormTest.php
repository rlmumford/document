<?php

namespace Drupal\Tests\document_checklist\Functional;

use Drupal\document\Entity\DocumentType;
use Drupal\Tests\BrowserTestBase;

/**
 * Tests type creation and separate named-review configuration forms.
 *
 * @group document
 */
class ReviewDefinitionFormTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['document'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * Type-owned requirements survive editing, context rebuilds and parent saves.
   */
  public function testNamedReviewEditing(): void {
    $this->drupalLogin($this->drupalCreateUser(['administer documents']));
    $this->drupalGet('/admin/structure/document-types/add');
    $this->submitForm(['label' => 'Schedules', 'id' => 'schedules'], 'Save');
    $this->drupalGet('/admin/structure/document-types/schedules');
    $this->assertSession()->pageTextContains('Staff review');
    $this->clickLink('Add review requirement');
    $this->submitForm([
      'label' => 'Debtor review',
      'name' => 'debtor',
      'eligibility' => 'context',
    ], 'Update reviewer choices');
    $this->assertSession()->fieldExists('context_mapping[reviewer]');
    $this->submitForm([
      'label' => 'Debtor review',
      'name' => 'debtor',
      'eligibility' => 'context',
      'context_mapping[reviewer]' => 'document.person.entity',
      'instructions' => 'Confirm the schedules describe your information.',
      'options' => "approved|I approve\nrejected|Corrections needed",
      'prompt' => 'Check {{document.label.value}}.',
      'analysis_schema' => '{"type":"object","properties":{"confirmed":{"type":"boolean"}},"required":["confirmed"]}',
    ], 'Save review requirement');
    $this->assertSession()->pageTextContains('Saved the review requirement.');
    $type = DocumentType::load('schedules');
    $this->assertCount(2, $type->getReviews());
    $this->assertSame('document.person.entity', $type->getReview('debtor')['context_mapping']['reviewer']);
    $this->assertSame('Check {{document.label.value}}.', $type->getReview('debtor')['prompt']);
    $this->submitForm(['description' => 'Schedules for review.'], 'Save');
    $this->container->get('entity_type.manager')->getStorage('document_type')->resetCache();
    $this->assertCount(2, DocumentType::load('schedules')->getReviews());
    $this->drupalGet('/admin/structure/document-types/schedules/reviews/debtor');
    $this->submitForm(['analysis_schema' => 'invalid json'], 'Save review requirement');
    $this->assertSession()->pageTextContains('Analysis schema must be valid JSON.');
    $this->container->get('entity_type.manager')->getStorage('document_type')->resetCache();
    $this->assertStringContainsString('confirmed', DocumentType::load('schedules')->getReview('debtor')['analysis_schema']);
  }

}
