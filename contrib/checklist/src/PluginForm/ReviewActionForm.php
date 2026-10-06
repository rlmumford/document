<?php

namespace Drupal\document_checklist\PluginForm;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\PluginFormBase;
use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Uses the same review operation as API clients.
 */
class ReviewActionForm extends PluginFormBase {

  use StringTranslationTrait;

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $input = $this->plugin->reviewInput();
    $form['review_name'] = [
      '#type' => 'item',
      '#title' => $this->t('Review requirement'),
      '#plain_text' => $input['label'],
    ];
    $form['instructions'] = ['#plain_text' => $input['instructions']];
    // Retain the version shown at build time through validation and submit.
    $form['fingerprint'] = ['#type' => 'hidden', '#default_value' => $input['fingerprint']];
    $form['reason'] = ['#type' => 'textarea', '#title' => $this->t('Reason')];
    $submit = $form['actions']['complete'] ?? ['#type' => 'submit'];
    unset($form['actions']['complete']);
    foreach ($input['options'] as $name => $label) {
      $form['actions']['review_' . $name] = array_replace($submit, [
        '#value' => $label,
        '#name' => 'document_review_' . $name,
        '#review_decision' => $name,
      ]);
    }
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateConfigurationForm(array &$form, FormStateInterface $form_state) {
    $input = $this->plugin->reviewInput();
    if ($input['fingerprint'] !== $form_state->getValue('fingerprint')) {
      $form_state->setErrorByName('reason', $this->t('The document changed. Reload it before reviewing.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    $this->plugin->executeActionOperation('review', [
      'decision' => $form_state->getTriggeringElement()['#review_decision'] ?? '',
      'fingerprint' => $form_state->getValue('fingerprint'),
      'reason' => $form_state->getValue('reason') ?? '',
    ]);
  }

}
