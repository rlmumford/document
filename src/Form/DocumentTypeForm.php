<?php

namespace Drupal\document\Form;

use Drupal\Core\Entity\BundleEntityFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Adds and edits document types.
 */
class DocumentTypeForm extends BundleEntityFormBase {

  /**
   * {@inheritdoc}
   */
  public function form(array $form, FormStateInterface $form_state) {
    $form = parent::form($form, $form_state);
    /** @var \Drupal\document\Entity\DocumentType $type */
    $type = $this->entity;

    $form['label'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Name'),
      '#default_value' => $type->label(),
      '#required' => TRUE,
    ];

    $form['id'] = [
      '#type' => 'machine_name',
      '#default_value' => $type->id(),
      '#machine_name' => ['exists' => '\Drupal\document\Entity\DocumentType::load'],
      '#disabled' => !$type->isNew(),
    ];

    $form['description'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Description'),
      '#description' => $this->t('What this kind of document is for, and anything someone filing one needs to know.'),
      '#default_value' => $type->getDescription(),
    ];

    $review = $type->getReview();
    $form['review_instructions'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Review instructions'),
      '#default_value' => $review['instructions'],
    ];
    $lines = [];
    foreach ($review['options'] as $name => $label) {
      $lines[] = $name . '|' . $label;
    }
    $form['review_options'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Review decisions'),
      '#description' => $this->t('One machine_name|Label per line. These record individual reviews; they do not change the document status.'),
      '#default_value' => implode("\n", $lines),
      '#required' => TRUE,
    ];
    return $this->protectBundleIdElement($form);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    parent::validateForm($form, $form_state);
    $options = [];
    foreach (explode("\n", trim($form_state->getValue('review_options'))) as $line) {
      $parts = array_map('trim', explode('|', $line, 2));
      if (count($parts) !== 2 || !preg_match('/^[a-z][a-z0-9_]*$/D', $parts[0]) || $parts[1] === '' || mb_strlen($parts[0]) > 128 || mb_strlen($parts[1]) > 255 || isset($options[$parts[0]])) {
        $form_state->setErrorByName('review_options', $this->t('Use unique machine_name|Label pairs (names up to 128 characters; labels up to 255).'));
        return;
      }
      $options[$parts[0]] = $parts[1];
    }
    $form_state->set('review_policy', [
      'instructions' => $form_state->getValue('review_instructions'),
      'options' => $options,
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state) {
    $this->entity->set('review', $form_state->get('review_policy'));
    $status = $this->entity->save();
    $this->messenger()->addStatus($this->t('Saved the %label document type.', ['%label' => $this->entity->label()]));
    $form_state->setRedirectUrl($this->entity->toUrl('collection'));
    return $status;
  }

}
