<?php

namespace Drupal\document\Form;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\Context\ContextHandlerInterface;
use Drupal\Core\Plugin\Context\EntityContext;
use Drupal\document\Entity\DocumentType;
use Drupal\document\Review\AnalysisSchema;
use Drupal\document\Review\ReviewerContext;
use Drupal\user\PermissionHandlerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Edits one type-owned review without copying it into job configuration.
 */
class ReviewDefinitionForm extends FormBase {

  public function __construct(protected EntityTypeManagerInterface $entityTypes, protected ContextHandlerInterface $contexts, protected PermissionHandlerInterface $permissions) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static($container->get('entity_type.manager'), $container->get('context.handler'), $container->get('user.permissions'));
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'document_review_definition';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?DocumentType $document_type = NULL, string $review_name = '') {
    if ($review_name !== '' && !isset($document_type->getReviews()[$review_name])) {
      throw new NotFoundHttpException();
    }
    $form_state->set('document_type', $document_type->id());
    $form_state->set('review_name', $review_name);
    $definition = $review_name !== '' ? $document_type->getReview($review_name) : $this->entityTypes->getStorage('document_type')->create([])->getReview('staff');
    $form['#prefix'] = '<div id="document-review-definition">';
    $form['#suffix'] = '</div>';
    $form['label'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Review name'),
      '#default_value' => $review_name !== '' ? $definition['label'] : '',
      '#required' => TRUE,
      '#maxlength' => 255,
    ];
    $form['name'] = [
      '#type' => 'machine_name',
      '#default_value' => $review_name,
      '#disabled' => $review_name !== '',
      '#machine_name' => ['exists' => [$this, 'reviewExists']],
      '#maxlength' => 128,
    ];
    $form['required'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Required for document approval'),
      '#default_value' => $definition['required'],
    ];
    $form['instructions'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Human review instructions'),
      '#default_value' => $definition['instructions'],
    ];
    $lines = [];
    foreach ($definition['options'] as $name => $label) {
      $lines[] = $name . '|' . $label;
    }
    $form['options'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Decisions'),
      '#default_value' => implode("\n", $lines),
      '#required' => TRUE,
      '#description' => $this->t('One machine_name|Label per line. Use approved, rejected, incomplete and partial as appropriate. Partial accepts a narrower scope; incomplete rejects an insufficient submission. Follow-up work is configured separately.'),
    ];
    $mode = $form_state->getValue('eligibility', $form_state->getUserInput()['eligibility'] ?? $definition['eligibility']);
    $form['eligibility'] = [
      '#type' => 'select',
      '#title' => $this->t('Who may perform this review?'),
      '#options' => [
        'permission' => $this->t('Anyone with a specific permission'),
        'context' => $this->t('A related person resolved from the document'),
      ],
      '#default_value' => $mode,
      '#ajax' => ['callback' => '::refresh', 'wrapper' => 'document-review-definition'],
    ];
    $form['update_reviewer'] = [
      '#type' => 'submit',
      '#value' => $this->t('Update reviewer choices'),
      '#limit_validation_errors' => [],
      '#submit' => ['::rebuildForm'],
      '#attributes' => ['class' => ['js-hide']],
    ];
    $permissions = [];
    foreach ($this->permissions->getPermissions() as $name => $permission) {
      $permissions[$name] = $permission['title'];
    }
    $form['permission'] = [
      '#type' => 'select',
      '#title' => $this->t('Required permission'),
      '#options' => $permissions,
      '#default_value' => $definition['permission'],
      '#access' => $mode === 'permission',
      '#required' => $mode === 'permission',
    ];
    $document = $this->entityTypes->getStorage('document')->create(['type' => $document_type->id()]);
    $form['context_mapping'] = $this->contexts->getContextAssignmentElement(ReviewerContext::fromDefinition($definition), ['document' => EntityContext::fromEntity($document)]);
    $form['context_mapping']['#access'] = $mode === 'context';
    $form['prompt'] = [
      '#type' => 'textarea',
      '#title' => $this->t('AI analysis prompt'),
      '#default_value' => $definition['prompt'],
      '#description' => $this->t('Optional preparation contract for an AI integration, using document placeholders such as {{document.label.value}}. Configuring a prompt does not enable automatic execution.'),
    ];
    $form['analysis_schema'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Analysis data schema'),
      '#default_value' => $definition['analysis_schema'],
      '#rows' => 8,
      '#description' => $this->t('A JSON Schema Draft 7 object describing the data analysis must collect. Required fields and allowed values are validated whenever analysis is supplied. Human review can proceed without automated analysis.'),
    ];
    $form['actions']['#type'] = 'actions';
    $form['actions']['submit'] = ['#type' => 'submit', '#value' => $this->t('Save review requirement')];
    return $form;
  }

  /**
   * Refreshes reviewer mapping when its eligibility mode changes.
   */
  public function refresh(array &$form, FormStateInterface $form_state): array {
    return $form;
  }

  /**
   * Rebuilds reviewer settings without saving the definition.
   */
  public function rebuildForm(array &$form, FormStateInterface $form_state): void {
    $form_state->setRebuild();
  }

  /**
   * Checks machine-name uniqueness within the type.
   */
  public function reviewExists(string $name, array $element, FormStateInterface $form_state): bool {
    $type = $this->entityTypes->getStorage('document_type')->load($form_state->get('document_type'));
    return $name === 'add' || isset($type->getReviews()[$name]);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    $options = [];
    foreach (explode("\n", trim($form_state->getValue('options'))) as $line) {
      $parts = array_map('trim', explode('|', $line, 2));
      if (count($parts) !== 2 || !preg_match('/^[a-z][a-z0-9_]*$/D', $parts[0]) || $parts[1] === '' || mb_strlen($parts[0]) > 128 || mb_strlen($parts[1]) > 255 || isset($options[$parts[0]])) {
        $form_state->setErrorByName('options', $this->t('Use unique machine_name|Label pairs (names up to 128 characters; labels up to 255).'));
        return;
      }
      $options[$parts[0]] = $parts[1];
    }
    if ($form_state->getValue('required') && !isset($options['approved'])) {
      $form_state->setErrorByName('options', $this->t('A required review must define an approved decision.'));
    }
    if ($form_state->getValue('eligibility') === 'context' && !$form_state->getValue(['context_mapping', 'reviewer'])) {
      $form_state->setErrorByName('context_mapping', $this->t('Select the required reviewer.'));
    }
    try {
      AnalysisSchema::parse(trim($form_state->getValue('analysis_schema')));
    }
    catch (\InvalidArgumentException $exception) {
      $form_state->setErrorByName('analysis_schema', $exception->getMessage());
    }
    $form_state->set('decisions', $options);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $type = $this->entityTypes->getStorage('document_type')->loadUnchanged($form_state->get('document_type'));
    $definitions = $type->getReviews();
    $name = $form_state->get('review_name') ?: $form_state->getValue('name');
    $definitions[$name] = [
      'label' => $form_state->getValue('label'),
      'instructions' => $form_state->getValue('instructions'),
      'options' => $form_state->get('decisions'),
      'required' => (bool) $form_state->getValue('required'),
      'eligibility' => $form_state->getValue('eligibility'),
      'permission' => $form_state->getValue('permission') ?? '',
      'context_mapping' => $form_state->getValue('context_mapping') ?? [],
      'prompt' => $form_state->getValue('prompt'),
      'analysis_schema' => trim($form_state->getValue('analysis_schema')),
    ];
    $type->set('reviews', $definitions)->save();
    $this->messenger()->addStatus($this->t('Saved the review requirement.'));
    $form_state->setRedirect('entity.document_type.edit_form', ['document_type' => $type->id()]);
  }

}
