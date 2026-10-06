<?php

namespace Drupal\document_checklist\PluginForm;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Plugin\Context\ContextHandlerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\PluginFormBase;
use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Configures the workflow role; the document context uses standard mapping.
 */
class ReviewConfigureForm extends PluginFormBase implements ContainerInjectionInterface {

  use StringTranslationTrait;

  public function __construct(protected ContextHandlerInterface $contexts, protected EntityTypeManagerInterface $entityTypes) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static($container->get('context.handler'), $container->get('entity_type.manager'));
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form['#tree'] = TRUE;
    $form['context_mapping'] = $this->contexts->getContextAssignmentElement($this->plugin, $form_state->getTemporaryValue('gathered_contexts') ?? []);
    $options = [];
    foreach ($this->entityTypes->getStorage('document_type')->loadMultiple() as $type) {
      foreach ($type->getReviews() as $name => $definition) {
        $options[$name] = $definition['label'] . ' (' . $name . ')';
      }
    }
    $form['role'] = [
      '#type' => 'select',
      '#title' => $this->t('Review requirement'),
      '#description' => $this->t('The mapped document type must define this named review. It owns the decisions and reviewer eligibility.'),
      '#default_value' => $this->plugin->getConfiguration()['role'],
      '#required' => TRUE,
      '#options' => $options,
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    $this->plugin->setConfiguration([
      'role' => trim($form_state->getValue('role')),
      'context_mapping' => $form_state->getValue('context_mapping', []),
    ] + $this->plugin->getConfiguration());
  }

}
