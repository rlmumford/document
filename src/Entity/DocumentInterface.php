<?php

namespace Drupal\document\Entity;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityChangedInterface;
use Drupal\user\EntityOwnerInterface;

/**
 * A file people treat as one thing.
 */
interface DocumentInterface extends ContentEntityInterface, EntityChangedInterface, EntityOwnerInterface {

  /**
   * Gets the status.
   *
   * @return string
   *   One of the status field's allowed values.
   */
  public function getStatus(): string;

  /**
   * Sets the status.
   *
   * @param string $status
   *   One of the status field's allowed values.
   *
   * @return $this
   */
  public function setStatus(string $status);

  /**
   * Gets the file this document is.
   *
   * @return \Drupal\file\FileInterface|null
   *   The file, or NULL for a document that has been asked for and not yet
   *   given - which is a normal state, not a broken one.
   */
  public function getFile();

  /**
   * Gets the files this document is made of.
   *
   * @return \Drupal\file\FileInterface[]
   *   The files as they were given to us. Empty for a document nobody has
   *   sent yet - which is a normal state, not a broken one.
   */
  public function getFiles(): array;

  /**
   * What the giver said about whether this contains special-category data.
   *
   * @return bool|null
   *   TRUE or FALSE if they were asked, NULL if nobody ever has. The three are
   *   different: never asked is not the same as told no, and only the second
   *   is something to rely on.
   */
  public function declaredSpecialCategory(): ?bool;

  /**
   * Records what they said, and what they were asked.
   *
   * @param bool $contains
   *   Their answer.
   * @param string $question
   *   The exact wording they answered, stored rather than referenced - an
   *   answer means nothing without the question, and the question may change.
   * @param string|null $when
   *   When, in storage format. Defaults to now.
   *
   * @return $this
   */
  public function declareSpecialCategory(bool $contains, string $question, ?string $when = NULL);

  /**
   * Gets what has been worked out about this document.
   *
   * @param string|null $key
   *   A single key to read, or NULL for everything.
   *
   * @return mixed
   *   The analysis, the value at $key, or NULL if there is none.
   */
  public function getAnalysis(?string $key = NULL);

  /**
   * Records something worked out about this document.
   *
   * @param string $key
   *   What kind of analysis this is.
   * @param mixed $value
   *   The result. Store what produced it and when alongside it - an analysis
   *   nobody can attribute is one nobody can re-run or disbelieve.
   *
   * @return $this
   */
  public function setAnalysis(string $key, $value);

}
