<?php

namespace Drupal\document_ui\Controller;

use Drupal\Core\Controller\ControllerBase;

class ConfigurationController extends ControllerBase {

  /**
   * Documents description page.
   *
   * @return array
   */
  public function overviewDocuments() {
    return [
      '#plain_text' => $this->t('Documents store the data of a pdf file.'),
    ];
  }

}
