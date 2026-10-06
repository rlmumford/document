<?php

namespace Drupal\Tests\document_checklist\Kernel;

use Drupal\Core\Form\FormState;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\document\Entity\Document;
use Drupal\document\Entity\DocumentType;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\SchemaCheckTestTrait;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Tests independent review evidence and checklist submission paths.
 *
 * @group document
 */
class DocumentReviewTest extends KernelTestBase {

  use SchemaCheckTestTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system', 'user', 'field', 'file', 'text', 'filter', 'options', 'entity',
    'document', 'document_checklist', 'checklist', 'checklist_context_test',
    'plugin_reference', 'typed_data', 'typed_data_plus', 'typed_data_reference',
    'typed_data_context_assignment', 'inline_entity_form',
  ];

  /**
   * Document shared by the reviewers.
   */
  protected Document $document;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    foreach (['user', 'file', 'document', 'document_review', 'checklist_item'] as $type) {
      $this->installEntitySchema($type);
    }
    $this->installSchema('file', ['file_usage']);
    $this->installSchema('checklist', ['checklist_attempt', 'checklist_attempt_head', 'checklist_attempt_event']);
    $this->installConfig(['system', 'user']);
    FieldStorageConfig::create(['field_name' => 'work', 'entity_type' => 'user', 'type' => 'checklist'])->save();
    FieldConfig::create(['field_name' => 'work', 'entity_type' => 'user', 'bundle' => 'user'])->save();
    Role::create(['id' => 'reviewer', 'label' => 'Reviewer', 'permissions' => ['review documents', 'view any document']])->save();
    // Keep the tested reviewers out of the superuser special case.
    User::create(['name' => 'root'])->save();
    DocumentType::create([
      'id' => 'agreement',
      'label' => 'Agreement',
      'review' => [
        'instructions' => 'Check the names and terms before approving.',
        'options' => ['approved' => 'I approve', 'rejected' => 'Changes needed'],
      ],
    ])->save();
    $this->document = Document::create(['type' => 'agreement', 'label' => 'Agreement', 'status' => 'received']);
    $this->document->save();
  }

  /**
   * Builds one independently owned review checklist.
   */
  protected function handler(string $role = 'client') {
    $user = User::create([
      'name' => $this->randomMachineName(),
      'roles' => ['reviewer'],
      'work' => [
        'id' => 'context_test',
        'configuration' => [
          'default_items' => [
            'review' => [
              'title' => 'Review agreement',
              'handler' => 'document_review',
              'handler_configuration' => ['role' => $role],
            ],
            'consumer' => [
              'title' => 'Use the review decision',
              'handler' => 'context_consumer',
              'handler_configuration' => ['context_mapping' => ['value' => 'item:review:decision']],
            ],
          ],
        ],
      ],
    ]);
    $user->save();
    $this->container->get('current_user')->setAccount($user);
    $handler = $user->work->checklist->getItem('review')->getHandler();
    $handler->setContextValue('document', $this->document);
    return $handler;
  }

  /**
   * Two people approve independently through form and operation paths.
   */
  public function testIndependentReviews(): void {
    $first = $this->handler();
    $input = $first->reviewInput();
    $this->assertConfigSchema($this->container->get('config.typed'), 'document.type.agreement', DocumentType::load('agreement')->toArray());
    $this->assertConfigSchema($this->container->get('config.typed'), 'checklist_item_handler.document_review', $first->getConfiguration());
    $this->assertSame(['approved', 'rejected'], $first->actionOperations()['review']['parameters_schema']['properties']['decision']['enum']);
    $this->assertSame(['I approve', 'Changes needed'], $first->actionOperations()['review']['parameters_schema']['properties']['decision']['x-enum-labels']);
    $form_plugin = $this->container->get('plugin_form.factory')->createInstance($first, 'action');
    $state = (new FormState())->setMethod('POST');
    $form = $form_plugin->buildConfigurationForm([], $state);
    $this->assertSame('Check the names and terms before approving.', $form['instructions']['#plain_text']);
    $this->assertSame('I approve', $form['actions']['review_approved']['#value']);
    $state->setValues(['fingerprint' => $input['fingerprint'], 'reason' => 'Names checked']);
    $state->setTriggeringElement($form['actions']['review_approved']);
    $form_plugin->validateConfigurationForm($form, $state);
    $this->assertFalse($state->hasAnyErrors());
    $form_plugin->submitConfigurationForm($form, $state);
    $this->assertTrue($first->getItem()->isComplete());
    $this->assertStringContainsString('(client)', $first->getActionState()->message);
    $review1 = $first->getItem()->get('outcomes')->get('review')->getValue();
    $this->assertTrue($first->getItem()->get('checklist')->checklist->process());
    $this->assertSame([['approved', NULL]], $this->container->get('state')->get('checklist_context_test.runs'));
    $second = $this->handler();
    $result = $second->executeActionOperation('review', [
      'decision' => 'approved',
      'fingerprint' => $input['fingerprint'],
    ]);
    $this->assertNotSame($review1->uuid(), $result['review_uuid']);
    $reviews = $this->container->get('entity_type.manager')->getStorage('document_review')->loadMultiple();
    $this->assertCount(2, $reviews);
    $this->assertCount(2, array_unique(array_map(static fn($review) => $review->get('reviewer')->target_id, $reviews)));
    foreach ($reviews as $review) {
      $this->assertSame('client', $review->get('role')->value);
      $this->assertSame('approved', $review->get('decision')->value);
      $this->assertSame('I approve', $review->get('decision_label')->value);
      $this->assertSame($input['fingerprint'], $review->get('fingerprint')->value);
      $this->assertNotEmpty($review->get('source')->value);
      $this->assertNotEmpty($review->get('created')->value);
      $this->assertTrue($review->access('view'));
      $this->assertFalse($review->access('update'));
    }
    $first_id = $first->getItem()->id();
    $storage = $this->container->get('entity_type.manager')->getStorage('checklist_item');
    $storage->resetCache([$first_id]);
    $stored = $storage->load($first_id);
    $this->assertSame('approved', $stored->get('outcomes')->get('decision')->getValue());
    $this->assertSame($review1->uuid(), $stored->get('outcomes')->get('review')->getValue()->uuid());
    $this->assertSame('received', Document::load($this->document->id())->getStatus());
    $this->assertSame('document:' . $this->document->uuid(), $second->getActionResource()->getKey());
    $this->assertSame('approved', $second->getItem()->get('outcomes')->get('decision')->getValue());
  }

  /**
   * Replacing files without a new revision also rejects stale submissions.
   */
  public function testStaleReview(): void {
    $handler = $this->handler('staff');
    $input = $handler->reviewInput();
    $this->document->set('file', ['target_id' => 123]);
    $this->document->save();
    try {
      $handler->executeActionOperation('review', [
        'decision' => 'approved',
        'fingerprint' => $input['fingerprint'],
      ]);
      $this->fail('A stale review was accepted.');
    }
    catch (\DomainException $exception) {
      $this->assertStringContainsString('changed', $exception->getMessage());
    }
    $this->assertCount(0, $this->container->get('entity_type.manager')->getStorage('document_review')->loadMultiple());
    $this->assertFalse($handler->getItem()->isComplete());
  }

  /**
   * The operation cannot choose an unavailable value or spoof identity/role.
   */
  public function testInvalidInput(): void {
    $handler = $this->handler();
    $input = $handler->reviewInput();
    foreach ([
      ['decision' => 'other'],
      ['decision' => 'approved', 'role' => 'staff'],
      ['decision' => 'approved', 'reviewer' => 1],
    ] as $parameters) {
      try {
        $handler->executeActionOperation('review', $parameters + ['fingerprint' => $input['fingerprint']]);
        $this->fail('Invalid review input was accepted.');
      }
      catch (\InvalidArgumentException) {
        $this->assertFalse($handler->getItem()->isComplete());
      }
    }
    $this->assertCount(0, $this->container->get('entity_type.manager')->getStorage('document_review')->loadMultiple());
  }

  /**
   * A replayed source attempt cannot insert duplicate evidence.
   */
  public function testSubmissionReceipt(): void {
    $handler = $this->handler();
    $service = $this->container->get('document.reviewer');
    $fingerprint = $handler->reviewInput()['fingerprint'];
    $first = $service->record($this->document, $fingerprint, 'approved', 'client', source: 'item-one', attempt: 'attempt-one');
    // A different attempt preserves the old evidence.
    $second = $service->record($this->document, $fingerprint, 'rejected', 'client', source: 'item-one', attempt: 'attempt-two');
    $this->assertNotSame($first->id(), $second->id());
    try {
      $service->record($this->document, $fingerprint, 'approved', 'client', source: 'item-one', attempt: 'attempt-one');
      $this->fail('Duplicate receipt accepted.');
    }
    catch (EntityStorageException) {
      $this->assertCount(2, $this->container->get('entity_type.manager')->getStorage('document_review')->loadMultiple());
    }
    $first->set('decision', 'rejected');
    $this->expectException(EntityStorageException::class);
    $first->save();
  }

  /**
   * Document access is required even for someone allowed to record reviews.
   */
  public function testAccess(): void {
    $handler = $this->handler();
    $input = $handler->reviewInput();
    $user = $this->container->get('current_user')->getAccount();
    $user->removeRole('reviewer')->save();
    $this->container->get('entity_type.manager')->getAccessControlHandler('document')->resetCache();
    $this->assertNull($handler->getActionResource());
    $this->assertSame([], $handler->actionOperations());
    $this->expectException(AccessDeniedHttpException::class);
    $handler->executeActionOperation('review', [
      'decision' => 'approved',
      'fingerprint' => $input['fingerprint'],
    ]);
  }

}
