<?php

namespace Drupal\document\Form;

use Drupal\Core\Entity\BundleEntityFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;

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

    $form['review_definitions'] = [
      '#type' => 'table',
      '#caption' => $this->t('Named review requirements'),
      '#header' => [$this->t('Review'), $this->t('Required'), $this->t('Reviewer eligibility'), $this->t('Operations')],
      '#empty' => $this->t('Save the document type, then add its review requirements.'),
    ];
    if (!$type->isNew()) {
      foreach ($type->getReviews() as $name => $definition) {
        $form['review_definitions'][$name]['label'] = ['#plain_text' => $definition['label']];
        $form['review_definitions'][$name]['required'] = ['#plain_text' => !empty($definition['required']) ? $this->t('Yes') : $this->t('No')];
        $form['review_definitions'][$name]['eligibility'] = ['#plain_text' => $definition['eligibility'] === 'context' ? $this->t('Related person') : $this->t('Permission')];
        $form['review_definitions'][$name]['operations'] = [
          '#type' => 'operations',
          '#links' => [
            'edit' => [
              'title' => $this->t('Edit'),
              'url' => Url::fromRoute('document.review_definition.edit', [
                'document_type' => $type->id(),
                'review_name' => $name,
              ]),
            ],
          ],
        ];
      }
      $form['add_review'] = [
        '#type' => 'link',
        '#title' => $this->t('Add review requirement'),
        '#url' => Url::fromRoute('document.review_definition.add', ['document_type' => $type->id()]),
        '#attributes' => ['class' => ['button']],
      ];
    }
    return $this->protectBundleIdElement($form);
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state) {
    // The table is a projection, not an editable value of the config property.
    if (!$this->entity->isNew()) {
      $stored = $this->entityTypeManager->getStorage('document_type')->loadUnchanged($this->entity->id());
      $this->entity->set('reviews', $stored->getReviews());
    }
    $status = $this->entity->save();
    $this->messenger()->addStatus($this->t('Saved the %label document type.', ['%label' => $this->entity->label()]));
    $form_state->setRedirectUrl($this->entity->toUrl('collection'));
    return $status;
  }

}
