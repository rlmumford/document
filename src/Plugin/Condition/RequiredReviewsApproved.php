<?php

namespace Drupal\document\Plugin\Condition;

use Drupal\Component\Plugin\Exception\MissingValueContextException;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\document\DocumentReviewer;
use Drupal\typed_data_plus\Plugin\Condition\ContextAwareCondition;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Checks current document evidence rather than a remembered completion event.
 *
 * @Condition(
 *   id = "document_reviews_approved",
 *   label = @Translation("Document required reviews approved"),
 *   context_definitions = {
 *     "document" = @ContextDefinition("entity:document", label = @Translation("Document"), required = TRUE)
 *   }
 * )
 */
class RequiredReviewsApproved extends ContextAwareCondition implements ContainerFactoryPluginInterface {

  public function __construct(array $configuration, $plugin_id, $plugin_definition, protected DocumentReviewer $reviewer) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static($configuration, $plugin_id, $plugin_definition, $container->get('document.reviewer'));
  }

  /**
   * {@inheritdoc}
   */
  public function getContextDefinitions() {
    return $this->pluginDefinition['context_definitions'];
  }

  /**
   * {@inheritdoc}
   */
  public function evaluate() {
    $document = $this->getContextValue('document');
    if (!$document || $document->isNew()) {
      throw new MissingValueContextException(['document']);
    }
    try {
      $requirements = $this->reviewer->requirements($document);
    }
    catch (AccessDeniedHttpException) {
      // Unavailable data is not FALSE: negation must not turn denied access
      // into permission to act. Checklist callers treat this as unresolved.
      throw new MissingValueContextException(['document']);
    }
    return $requirements && !in_array(FALSE, array_column($requirements, 'met'), TRUE);
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheMaxAge() {
    // Both evidence and related/global reviewer contexts can change separately
    // from the document entity passed into the condition.
    return 0;
  }

  /**
   * {@inheritdoc}
   */
  public function summary() {
    return $this->isNegated()
      ? $this->t('The current document does not have all required reviews approved.')
      : $this->t('The current document has all required reviews approved.');
  }

}
