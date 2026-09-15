<?php

namespace Drupal\document\Entity;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityChangedInterface;
use Drupal\Core\Entity\RevisionLogInterface;
use Drupal\user\EntityOwnerInterface;

/**
 * A file people treat as one thing.
 */
interface DocumentInterface extends ContentEntityInterface, EntityChangedInterface, EntityOwnerInterface, RevisionLogInterface {

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
   * The person this document is about.
   *
   * The owner is whoever provided it and is answerable for it; this is whose
   * life it describes. A case worker scanning a client's bank statement or a
   * recruiter uploading somebody else's CV makes them different, and that is
   * the case worth getting right - offering a document back to the wrong
   * person is the failure this prevents.
   *
   * @return int|null
   *   The user id, falling back to the owner where nobody said otherwise.
   */
  public function getPersonId(): ?int;

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
