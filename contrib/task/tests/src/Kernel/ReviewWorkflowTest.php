<?php

namespace Drupal\Tests\document_task\Kernel;

use Drupal\document\Entity\Document;
use Drupal\Core\Database\Database;
use Drupal\document\Event\ReviewRequirementsCompleted;
use Drupal\document\Event\ReviewRecorded;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\document\Entity\DocumentType;
use Drupal\KernelTests\KernelTestBase;
use Drupal\task\Entity\Task;
use Drupal\task_job\Entity\Job;
use Drupal\user\Entity\User;

/**
 * Tests one review occurrence feeding waiting work and job creation atomically.
 *
 * @group document
 */
class ReviewWorkflowTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system', 'user', 'field', 'file', 'text', 'filter', 'options', 'datetime',
    'entity', 'document', 'document_task', 'task', 'task_dependency', 'task_context',
    'task_dependency_job', 'task_job', 'entity_template', 'typed_data',
    'typed_data_plus', 'typed_data_context_assignment', 'checklist',
    'plugin_reference', 'typed_data_reference', 'inline_entity_form',
    'ctools', 'token', 'flexiform', 'field_ui', 'views',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    foreach (['user', 'file', 'document', 'document_review', 'task', 'task_dependency'] as $entity) {
      $this->installEntitySchema($entity);
    }
    $this->installSchema('file', ['file_usage']);
    $this->installSchema('task_dependency', [
      'task_dependency_target_lock',
      'task_dependency_request',
      'task_dependency_history',
    ]);
    $this->installSchema('task_job', ['task_job_trigger_index']);
    $this->installEntitySchema('checklist_item');
    $this->installSchema('checklist', ['checklist_attempt', 'checklist_attempt_head', 'checklist_attempt_event']);
    $this->installConfig(['system', 'user']);
    $user = User::create(['name' => 'Admin']);
    $user->save();
    $this->container->get('current_user')->setAccount($user);
    DocumentType::create(['id' => 'agreement', 'label' => 'Agreement'])->save();
  }

  /**
   * Tests correlated waits, job creation, rollback, and late registration.
   */
  public function testReviewDrivesBothConsumers(): void {
    $documents = $this->container->get('entity_type.manager')->getStorage('document');
    $document = Document::create(['type' => 'agreement', 'label' => 'Agreement', 'status' => 'received']);
    $document->save();
    $other = Document::create(['type' => 'agreement', 'label' => 'Other']);
    $other->save();
    $manager = $this->container->get('task_dependency.manager');
    $tasks = [];
    foreach ([$document, $other] as $target) {
      $task = Task::create(['title' => 'Wait for reviews']);
      $task->event_dependencies[] = $manager->create($task, 'document.reviews_completed', [], 'activate', $target);
      $task->save();
      $tasks[] = $task;
    }
    $job = Job::create([
      'id' => 'after_review',
      'label' => 'After review',
      'triggers' => [
        'reviewed' => [
          'id' => 'dependency_event:document.reviews_completed',
          'key' => 'reviewed',
          'template' => ['id' => 'default', 'uuid' => 'reviewed', 'label' => 'Follow up', 'components' => []],
        ],
      ],
    ]);
    $job->save();
    $reviewer = $this->container->get('document.reviewer');
    $fingerprint = $reviewer->fingerprint($document);
    $reviewer->record($document, $fingerprint, 'rejected', 'staff');
    $this->assertFalse($this->met($tasks[0]));
    $transaction = $this->container->get('database')->startTransaction();
    $reviewer->record($document, $fingerprint, 'approved', 'staff');
    $this->assertTrue($this->met($tasks[0]));
    $transaction->rollBack();
    unset($transaction);
    $this->assertFalse($this->met($tasks[0]));
    $task_storage = $this->container->get('entity_type.manager')->getStorage('task');
    $task_storage->resetCache();
    $this->assertCount(0, $task_storage->loadByProperties(['job' => $job->id()]));
    $reviewer->record($document, $fingerprint, 'approved', 'staff');
    $this->assertTrue($this->met($tasks[0]));
    $this->assertFalse($this->met($tasks[1]));
    $this->assertCount(1, $task_storage->loadByProperties(['job' => $job->id()]));
    $reviewer->record($document, $fingerprint, 'approved', 'staff');
    $this->assertCount(1, $task_storage->loadByProperties(['job' => $job->id()]));
    $scheduler = $this->container->get('task_dependency.scheduler');
    foreach ($this->container->get('task_dependency.workflow')->reserve() as $message) {
      $scheduler->run($message);
    }
    $this->assertSame('active', $task_storage->loadUnchanged($tasks[0]->id())->status->value);
    $this->assertSame('waiting', $task_storage->loadUnchanged($tasks[1]->id())->status->value);
    $late = Task::create(['title' => 'Registered later']);
    $late->event_dependencies[] = $manager->create($late, 'document.reviews_completed', [], 'activate', $document);
    $late->save();
    $this->assertFalse($this->met($late));
    $document = $documents->loadUnchanged($document->id());
    $document->setNewRevision(TRUE);
    $document->save();
    $this->assertSame('pending', $reviewer->summary($document)['status']);
    $this->assertTrue($this->met($tasks[0]));
    $this->assertSame('received', $document->getStatus());
  }

  /**
   * Individual review conditions and mapped outcomes drive follow-up tasks.
   */
  public function testDecisionFollowUp(): void {
    $type = DocumentType::load('agreement');
    $staff = $type->getReview('staff');
    $staff['options'] += ['incomplete' => 'Incomplete', 'partial' => 'Partially approved'];
    $type->set('reviews', ['staff' => $staff, 'client' => ['label' => 'Client review'] + $staff])->save();
    $job = Job::create([
      'id' => 'review_follow_up',
      'label' => 'Follow up document review',
      'context' => [
        'document' => ['type' => 'entity:document', 'label' => 'Document', 'required' => TRUE],
        'review' => ['type' => 'entity:document_review', 'label' => 'Review', 'required' => TRUE],
      ],
      'triggers' => [
        'staff_follow_up' => [
          'id' => 'document.review_recorded',
          'key' => 'staff_follow_up',
          'template' => [
            'id' => 'default',
            'uuid' => 'default',
            'label' => 'Review follow-up',
            'conditions' => [
              ['id' => 'condition_string', 'condition_string' => 'review.role.value == "staff"'],
              ['id' => 'condition_string', 'condition_string' => 'review.decision.value != "approved"'],
            ],
            'components' => [
              'title' => [
                'id' => 'field.widget_input:task.title',
                'uuid' => 'title',
                'field_type' => 'string',
                'value' => [['value' => 'Follow up {{ document.label.value }}: {{ review.decision_label.value }}']],
              ],
              'document' => [
                'id' => 'task_context.data_select',
                'uuid' => 'document',
                'task_context' => 'document',
                'selector' => 'document',
              ],
              'review' => [
                'id' => 'task_context.data_select',
                'uuid' => 'review',
                'task_context' => 'review',
                'selector' => 'review',
              ],
            ],
          ],
        ],
      ],
    ]);
    $job->save();
    $document = Document::create(['type' => 'agreement', 'label' => 'Agreement', 'status' => 'received']);
    $document->save();
    $reviewer = $this->container->get('document.reviewer');
    $fingerprint = $reviewer->fingerprint($document);
    $tasks = $this->container->get('entity_type.manager')->getStorage('task');
    foreach (['rejected', 'incomplete', 'partial'] as $decision) {
      $review = $reviewer->record($document, $fingerprint, $decision, 'staff', 'Follow-up reason', $decision);
      $created = $tasks->loadByProperties([
        'job' => $job->id(),
        'title' => 'Follow up Agreement: ' . $staff['options'][$decision],
      ]);
      $this->assertCount(1, $created);
      $task = reset($created);
      $this->assertSame($review->id(), $task->get('context')->get('review')->getValue()->id());
      $this->assertSame($document->id(), $task->get('context')->get('document')->getValue()->id());
    }
    $reviewer->record($document, $fingerprint, 'approved', 'staff');
    $reviewer->record($document, $fingerprint, 'incomplete', 'client');
    $this->assertCount(3, $tasks->loadByProperties(['job' => $job->id()]));
    try {
      $reviewer->record($document, $fingerprint, 'rejected', 'staff', '', 'rejected');
      $this->fail('A replayed receipt must not create another review or task.');
    }
    catch (EntityStorageException) {
      $this->assertCount(3, $tasks->loadByProperties(['job' => $job->id()]));
    }
    // A consumer failure after task creation must undo both task and evidence.
    $reviews = $this->container->get('entity_type.manager')->getStorage('document_review');
    $count = count($reviews->loadByProperties(['document' => $document->id()]));
    $this->container->get('event_dispatcher')->addListener(ReviewRecorded::class, static function () {
      throw new \RuntimeException('Follow-up failed.');
    }, -100);
    try {
      $reviewer->record($document, $fingerprint, 'incomplete', 'staff');
      $this->fail('The consumer exception should propagate.');
    }
    catch (\RuntimeException $exception) {
      $this->assertSame('Follow-up failed.', $exception->getMessage());
    }
    $tasks->resetCache();
    $reviews->resetCache();
    $this->assertCount(3, $tasks->loadByProperties(['job' => $job->id()]));
    $this->assertCount($count, $reviews->loadByProperties(['document' => $document->id()]));
    $this->assertSame('received', $document->getStatus());
  }

  /**
   * A pre-existing MySQL snapshot must not hide another reviewer's commit.
   */
  public function testCommittedEvidenceAfterSnapshot(): void {
    $database = $this->container->get('database');
    if ($database->driver() !== 'mysql') {
      $this->markTestSkipped('Exercises MySQL repeatable-read snapshots.');
    }
    $type = DocumentType::load('agreement');
    $definition = $type->getReview('staff');
    $type->set('reviews', ['staff' => $definition, 'client' => $definition])->save();
    $document = Document::create(['type' => 'agreement', 'label' => 'Concurrent reviews']);
    $document->save();
    $reviewer = $this->container->get('document.reviewer');
    $fingerprint = $reviewer->fingerprint($document);
    $review = $reviewer->record($document, $fingerprint, 'rejected', 'staff');
    $record = $database->select('document_review', 'r')->fields('r')->condition('id', $review->id())->execute()->fetchAssoc();
    unset($record['id']);
    $record['uuid'] = $this->container->get('uuid')->generate();
    $record['decision'] = 'approved';
    $record['decision_label'] = 'Approve';
    $events = [];
    $this->container->get('event_dispatcher')->addListener(ReviewRequirementsCompleted::class, static function ($event) use (&$events) {
      $events[] = $event;
    });
    $options = Database::getConnectionInfo('default')['default'];
    Database::addConnectionInfo('review_writer', 'default', $options);
    $writer = Database::getConnection('default', 'review_writer');
    $database->query('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    $transaction = $database->startTransaction();
    try {
      $this->assertSame('rejected', $database->select('document_review', 'r')->fields('r', ['decision'])->execute()->fetchField());
      // Simulate evidence committed after the caller established its snapshot,
      // but before it takes the document lock to record the remaining review.
      $writer->insert('document_review')->fields($record)->execute();
      $reviewer->record($document, $fingerprint, 'approved', 'client');
      $this->assertCount(1, $events);
      $this->assertTrue($events[0]->requirements['staff']['met']);
      $this->assertTrue($events[0]->requirements['client']['met']);
    }
    finally {
      $transaction->rollBack();
      unset($transaction);
      Database::closeConnection('default', 'review_writer');
      $database->query('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');
    }
  }

  /**
   * Reads the persisted receipt without the entity cache.
   */
  protected function met(Task $task): bool {
    $storage = $this->container->get('entity_type.manager')->getStorage('task_dependency');
    $storage->resetCache();
    return (bool) $storage->loadUnchanged($task->event_dependencies->target_id)->met->value;
  }

}
