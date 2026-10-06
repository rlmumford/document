<?php

namespace Drupal\document\Entity;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Entity\EntityAccessControlHandler;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Review evidence is visible only to readers of its document.
 */
class DocumentReviewAccessControlHandler extends EntityAccessControlHandler {

  /**
   * {@inheritdoc}
   */
  protected function checkAccess(EntityInterface $entity, $operation, AccountInterface $account) {
    if ($operation !== 'view' || !$entity->get('document')->entity) {
      return AccessResult::forbidden();
    }
    return $entity->get('document')->entity->access('view', $account, TRUE)->addCacheableDependency($entity);
  }

  /**
   * {@inheritdoc}
   */
  protected function checkCreateAccess(AccountInterface $account, array $context, $entity_bundle = NULL) {
    // Review creation must pass the document-specific service checks.
    return AccessResult::forbidden();
  }

}
