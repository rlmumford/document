<?php

namespace Drupal\document_checklist\PluginForm;

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

  public function __construct(protected ContextHandlerInterface $contexts) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static($container->get('context.handler'));
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form['#tree'] = TRUE;
    $form['context_mapping'] = $this->contexts->getContextAssignmentElement($this->plugin, $form_state->getTemporaryValue('gathered_contexts') ?? []);
    $form['role'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Reviewing role'),
      '#description' => $this->t('The capacity in which the person reviews, such as staff or client. This does not grant a Drupal role.'),
      '#default_value' => $this->plugin->getConfiguration()['role'],
      '#required' => TRUE,
      '#maxlength' => 255,
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
