<?php

namespace Drupal\Tests\document_checklist\Kernel;

use Symfony\Component\HttpFoundation\Request;
use Drupal\document_checklist\Controller\ReviewHistoryController;
use Drupal\checklist\Event\ChecklistEvents;
use Drupal\document\Event\ReviewRequirementsCompleted;
use Drupal\Core\Form\FormState;
use Drupal\Core\Plugin\Context\EntityContext;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\document\Entity\Document;
use Drupal\document\Entity\DocumentType;
use Drupal\document\Review\AnalysisSchema;
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
    $definition = [
      'label' => 'Client review',
      'instructions' => 'Check the names and terms before approving.',
      'options' => ['approved' => 'I approve', 'rejected' => 'Changes needed'],
      'required' => TRUE,
      'eligibility' => 'permission',
      'permission' => 'review documents',
      'context_mapping' => [],
      'prompt' => '',
      'analysis_schema' => '',
    ];
    DocumentType::create([
      'id' => 'agreement',
      'label' => 'Agreement',
      'reviews' => ['client' => $definition, 'staff' => ['label' => 'Staff review'] + $definition],
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
   * Resource history resolves the item's document and enforces host access.
   */
  public function testHistoryResource(): void {
    $handler = $this->handler();
    $item = $handler->getItem();
    $host = $item->get('checklist')->checklist->getEntity();
    $configuration = $host->get('work')->first()->getValue();
    $configuration['configuration']['default_items']['review']['handler_configuration']['context_mapping']['document'] = 'history_document';
    $host->set('work', $configuration)->save();
    $this->container->get('event_dispatcher')->addListener(ChecklistEvents::COLLECT_RUNTIME_CONTEXTS, function ($event) {
      $event->addContext('history_document', EntityContext::fromEntity($this->document));
    });
    $reviewer = $this->container->get('document.reviewer');
    $reviewer->record($this->document, $reviewer->fingerprint($this->document), 'approved', 'client', 'History evidence');
    $controller = ReviewHistoryController::create($this->container);
    $request = Request::create('/');
    $build = $controller->view($request, 'user', $host->id(), 'work:0', 'review');
    $this->assertCount(2, $build['reviews']);
    $entries = array_filter($build['reviews'], 'is_array');
    $this->assertSame('History evidence', reset($entries)['reason']['text']['#plain_text']);
    $request->query->set('_wrapper_format', 'drupal_ajax');
    $response = $controller->view($request, 'user', $host->id(), 'work:0', 'review');
    $commands = $response->getCommands();
    $this->assertSame('checklistOpenResource', $commands[0]['command']);
    $this->assertStringContainsString('History evidence', $commands[0]['data']);
    $this->assertStringContainsString('data-checklist-history', $commands[0]['data']);
    $this->container->get('current_user')->setAccount(new AnonymousUserSession());
    $this->container->get('entity_type.manager')->getAccessControlHandler('user')->resetCache();
    $this->expectException(AccessDeniedHttpException::class);
    $controller->view($request, 'user', $host->id(), 'work:0', 'review');
  }

  /**
   * Completion is an edge; partial, empty, and stale evidence do not qualify.
   */
  public function testCompletionEvent(): void {
    $this->handler();
    $reviewer = $this->container->get('document.reviewer');
    $events = [];
    $this->container->get('event_dispatcher')->addListener(ReviewRequirementsCompleted::class, static function ($event) use (&$events) {
      $events[] = $event;
    });
    $fingerprint = $reviewer->fingerprint($this->document);
    $reviewer->record($this->document, $fingerprint, 'approved', 'client');
    $this->assertSame('pending', $reviewer->summary($this->document)['status']);
    $this->assertCount(0, $events);
    $reviewer->record($this->document, $fingerprint, 'approved', 'staff');
    $this->assertSame('complete', $reviewer->summary($this->document)['status']);
    $this->assertCount(1, $events);
    $reviewer->record($this->document, $fingerprint, 'approved', 'staff');
    $this->assertCount(1, $events);
    $this->document->setNewRevision(TRUE);
    $this->document->save();
    $this->assertSame('pending', $reviewer->summary($this->document)['status']);
    $fingerprint = $reviewer->fingerprint($this->document);
    $reviewer->record($this->document, $fingerprint, 'approved', 'client');
    $reviewer->record($this->document, $fingerprint, 'approved', 'staff');
    $this->assertCount(2, $events);
    $this->assertSame('received', $this->document->getStatus());
    $type = DocumentType::load('agreement');
    $type->set('reviews', [])->save();
    $this->assertSame('not_required', $reviewer->summary($this->document)['status']);
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
    $this->assertStringContainsString('(Client review)', $first->getActionState()->message);
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

  /**
   * Requirement names, relationship identities and document versions agree.
   */
  public function testRelationshipRequirements(): void {
    $type = DocumentType::load('agreement');
    $base = $type->getReview('client');
    $type->set('reviews', [
      'debtor' => [
        'label' => 'Debtor review',
        'eligibility' => 'context',
        'context_mapping' => ['reviewer' => 'document.person.entity'],
      ] + $base,
      'creditor' => [
        'label' => 'Creditor review',
        'eligibility' => 'context',
        'context_mapping' => ['reviewer' => 'document.owner.entity'],
        'options' => ['approved' => 'Approve', 'partial' => 'Partially approve', 'incomplete' => 'Incomplete'],
      ] + $base,
    ])->save();
    $debtor = $this->handler('debtor');
    $debtor_user = $this->container->get('current_user')->getAccount();
    $creditor = $this->handler('creditor');
    $creditor_user = $this->container->get('current_user')->getAccount();
    $this->document->set('person', $debtor_user)->set('owner', $creditor_user)->save();
    $service = $this->container->get('document.reviewer');
    $this->assertSame([], $debtor->actionOperations());
    try {
      $service->record($this->document, $service->fingerprint($this->document), 'approved', 'debtor');
      $this->fail('The creditor cannot perform the debtor review.');
    }
    catch (AccessDeniedHttpException) {
      $this->assertFalse($service->requirements($this->document)['debtor']['met']);
    }
    $this->container->get('current_user')->setAccount($debtor_user);
    $debtor->executeActionOperation('review', [
      'decision' => 'approved',
      'fingerprint' => $debtor->reviewInput()['fingerprint'],
    ]);
    $status = $service->requirements($this->document);
    $this->assertTrue($status['debtor']['met']);
    $this->assertFalse($status['creditor']['met']);
    $this->container->get('current_user')->setAccount($creditor_user);
    $creditor->executeActionOperation('review', [
      'decision' => 'partial',
      'fingerprint' => $creditor->reviewInput()['fingerprint'],
    ]);
    $this->assertFalse($service->requirements($this->document)['creditor']['met']);
    $service->record($this->document, $service->fingerprint($this->document), 'incomplete', 'creditor');
    $this->assertFalse($service->requirements($this->document)['creditor']['met']);
    $service->record($this->document, $service->fingerprint($this->document), 'approved', 'creditor');
    $this->assertTrue($service->requirements($this->document)['creditor']['met']);
    $this->document->setNewRevision(TRUE);
    $this->document->save();
    $status = $service->requirements($this->document);
    $this->assertFalse($status['debtor']['met']);
    $this->assertFalse($status['creditor']['met']);
    $this->assertSame('received', $this->document->getStatus());
  }

  /**
   * Analysis uses the named prompt and schema, without masquerading as AI.
   */
  public function testAnalysisContract(): void {
    $type = DocumentType::load('agreement');
    $reviews = $type->getReviews();
    $reviews['client']['prompt'] = 'Check {{document.label.value}}.';
    $reviews['client']['analysis_schema'] = '{"type":"object","properties":{"name_matches":{"type":"boolean"},"quality":{"type":"string","enum":["clear","unreadable"]}},"required":["name_matches","quality"],"additionalProperties":false}';
    $type->set('reviews', $reviews)->save();
    $handler = $this->handler();
    $service = $this->container->get('document.reviewer');
    $prepared = $service->prepareAnalysis($this->document, 'client');
    $this->assertSame('Check Agreement.', $prepared['prompt']);
    $this->assertSame(['clear', 'unreadable'], $prepared['analysis_schema']->properties->quality->enum);
    try {
      $handler->executeActionOperation('review', [
        'decision' => 'approved',
        'fingerprint' => $prepared['fingerprint'],
        'analysis' => ['name_matches' => TRUE, 'quality' => 'invented'],
      ]);
      $this->fail('Invalid analysis accepted.');
    }
    catch (\InvalidArgumentException) {
      $this->assertFalse($handler->getItem()->isComplete());
    }
    $handler->executeActionOperation('review', [
      'decision' => 'approved',
      'fingerprint' => $prepared['fingerprint'],
      'analysis' => ['name_matches' => TRUE, 'quality' => 'clear'],
    ]);
    $review = $handler->getItem()->get('outcomes')->get('review')->getValue();
    $this->assertSame('Client review', $review->get('role_label')->value);
    $this->assertSame(['name_matches' => TRUE, 'quality' => 'clear'], json_decode($review->get('analysis')->value, TRUE));
    $this->assertSame($reviews['client']['analysis_schema'], $review->get('analysis_schema')->value);
    $this->assertSame((string) $this->container->get('current_user')->id(), $review->get('reviewer')->target_id);
  }

  /**
   * An empty relationship mapping never falls back to an arbitrary reviewer.
   */
  public function testMissingRelationship(): void {
    $type = DocumentType::load('agreement');
    $reviews = $type->getReviews();
    $reviews['client']['eligibility'] = 'context';
    $reviews['client']['context_mapping'] = ['reviewer' => 'document.person.entity'];
    $type->set('reviews', $reviews)->save();
    $handler = $this->handler();
    $this->assertSame([], $handler->actionOperations());
    $this->expectException(AccessDeniedHttpException::class);
    $handler->reviewInput();
  }

  /**
   * A base review permission does not grant the staff-review permission.
   */
  public function testStaffPermission(): void {
    $type = DocumentType::load('agreement');
    $reviews = $type->getReviews();
    $reviews['staff']['permission'] = 'review documents as staff';
    $type->set('reviews', $reviews)->save();
    $handler = $this->handler('staff');
    $this->assertSame([], $handler->actionOperations());
    $this->expectException(AccessDeniedHttpException::class);
    $handler->reviewInput();
  }

  /**
   * Invalid or external analysis contracts are rejected before execution.
   *
   * @dataProvider invalidSchemas
   */
  public function testInvalidAnalysisSchema(string $schema): void {
    $this->expectException(\InvalidArgumentException::class);
    AnalysisSchema::parse($schema);
  }

  /**
   * Invalid analysis schemas, including references that must never be fetched.
   */
  public static function invalidSchemas(): array {
    return [
      ['not json'],
      ['{"type":"array"}'],
      ['{"type":"object","properties":{"x":{"type":"not_a_type"}}}'],
      ['{"type":"object","properties":{"x":{"$ref":"file:///etc/passwd"}}}'],
      ['{"type":"object","definitions":{"value":{"type":"string"}},"properties":{"x":{"$ref":"#/definitions/value"}}}'],
      ['{"type":"object","$id":"https://example.com/schema"}'],
    ];
  }

  /**
   * A prompt with an unresolved field cannot silently omit review checks.
   */
  public function testUnresolvedPrompt(): void {
    $type = DocumentType::load('agreement');
    $reviews = $type->getReviews();
    $reviews['client']['prompt'] = 'Check {{document.nonexistent}}.';
    $type->set('reviews', $reviews)->save();
    $this->handler();
    $this->expectException(\DomainException::class);
    $this->container->get('document.reviewer')->prepareAnalysis($this->document, 'client');
  }

  /**
   * Existing single-policy configuration becomes a named staff review.
   */
  public function testLegacyPolicyUpdate(): void {
    $storage = $this->container->get('config.storage');
    $data = $storage->read('document.type.agreement');
    unset($data['reviews']);
    $data['review'] = [
      'instructions' => 'Existing instructions',
      'options' => ['approved' => 'Approved', 'incomplete' => 'Incomplete'],
    ];
    $storage->write('document.type.agreement', $data);
    $this->container->get('config.factory')->reset('document.type.agreement');
    require_once DRUPAL_ROOT . '/' . $this->container->get('extension.list.module')->getPath('document') . '/document.install';
    document_update_10002();
    $config = $this->container->get('config.factory')->get('document.type.agreement');
    $this->assertNull($config->get('review'));
    $this->assertSame('Existing instructions', $config->get('reviews.staff.instructions'));
    $this->assertSame('Incomplete', $config->get('reviews.staff.options.incomplete'));
    $this->assertSame('review documents as staff', $config->get('reviews.staff.permission'));
    $this->assertConfigSchema($this->container->get('config.typed'), 'document.type.agreement', $config->getRawData());
  }

  /**
   * Native conditions follow live evidence, including new revisions and denial.
   */
  public function testReviewCondition(): void {
    $handler = $this->handler();
    $reviewer = $this->container->get('document.reviewer');
    $manager = $this->container->get('plugin.manager.condition');
    $condition = $manager->createInstance('document_reviews_approved');
    $condition->setContextValue('document', $this->document);
    $this->assertFalse($condition->execute());
    $this->assertSame(0, $condition->getCacheMaxAge());
    $this->assertConfigSchema($this->container->get('config.typed'), 'condition.plugin.document_reviews_approved', $condition->getConfiguration());
    $input = $handler->reviewInput();
    $handler->executeActionOperation('review', ['decision' => 'approved', 'fingerprint' => $input['fingerprint']]);
    $this->assertFalse($condition->execute());
    $reviewer->record($this->document, $input['fingerprint'], 'approved', 'staff');
    $this->assertTrue($condition->execute());
    // A later checklist gate maps the document outcome through the shared
    // context handler, without a second document selector or frozen result.
    $checklist = $handler->getItem()->get('checklist')->checklist;
    $configuration = ['id' => 'document_reviews_approved', 'context_mapping' => ['document' => 'item:review:document']];
    $evaluator = $this->container->get('checklist.condition_evaluator');
    $this->assertTrue($evaluator->evaluate($checklist, $configuration));
    $this->document->setNewRevision(TRUE);
    $this->document->save();
    $this->assertFalse($condition->execute());
    $this->assertFalse($evaluator->evaluate($checklist, $configuration));
    $configuration['negate'] = TRUE;
    $this->assertTrue($evaluator->evaluate($checklist, $configuration));
    $type = DocumentType::load('agreement');
    $type->set('reviews', [])->save();
    $this->assertFalse($condition->execute());
    $this->container->get('current_user')->setAccount(new AnonymousUserSession());
    $this->assertNull($evaluator->evaluate($checklist, $configuration));
  }

  /**
   * Tests the standard selector and the declared module dependency.
   */
  public function testReviewConditionForm(): void {
    $condition = $this->container->get('plugin.manager.condition')->createInstance('document_reviews_approved');
    $context = EntityContext::fromEntity($this->document);
    $condition->setExpectedContexts(['source' => $context->getContextDefinition()]);
    $state = new FormState();
    $state->setTemporaryValue('gathered_contexts', ['source' => $context]);
    $form = $condition->buildConfigurationForm([], $state);
    $this->assertArrayHasKey('document', $form['context_mapping']);
    $this->assertArrayHasKey('negate', $form);
    $dependencies = $this->container->get('checklist.condition_evaluator')->calculateDependencies([['id' => 'document_reviews_approved']]);
    $this->assertContains('document', $dependencies['module']);
  }

}
