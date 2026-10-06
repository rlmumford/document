<?php

namespace Drupal\document\Review;

use JsonSchema\Uri\UriRetriever;
use JsonSchema\Validator;

/**
 * Validates optional structured analysis independently of checklist or AI.
 */
final class AnalysisSchema {

  /**
   * Parses a self-contained Draft 7 object schema.
   */
  public static function parse(string $json): \stdClass {
    try {
      $schema = json_decode($json !== '' ? $json : '{"type":"object","additionalProperties":false}', FALSE, 64, JSON_THROW_ON_ERROR);
    }
    catch (\JsonException $exception) {
      throw new \InvalidArgumentException('Analysis schema must be valid JSON.', 0, $exception);
    }
    if (!$schema instanceof \stdClass || ($schema->type ?? NULL) !== 'object') {
      throw new \InvalidArgumentException('Analysis schema must describe an object.');
    }
    if (isset($schema->{'$schema'}) && $schema->{'$schema'} !== 'http://json-schema.org/draft-07/schema#') {
      throw new \InvalidArgumentException('Use JSON Schema Draft 7.');
    }
    $schema->{'$schema'} = 'http://json-schema.org/draft-07/schema#';
    self::checkReferences($schema);
    // The library maps this fixed URI to its bundled Draft 7 meta-schema.
    // Validate the definition itself, including properties absent from a probe.
    $validator = new Validator();
    $validator->validate($schema, (new UriRetriever())->retrieve('http://json-schema.org/draft-07/schema#'));
    if (!$validator->isValid()) {
      throw new \InvalidArgumentException('The analysis schema is not a valid Draft 7 schema.');
    }
    return $schema;
  }

  /**
   * Prevents configured schemas from retrieving external files or URLs.
   */
  private static function checkReferences(mixed $value): void {
    if (is_object($value) || is_array($value)) {
      foreach ($value as $key => $child) {
        if (in_array($key, ['$id', 'id'], TRUE) && is_string($child)) {
          throw new \InvalidArgumentException('Analysis schemas must not declare a base URI.');
        }
        if ($key === '$ref') {
          throw new \InvalidArgumentException('Inline analysis schema definitions; references are not supported.');
        }
        self::checkReferences($child);
      }
    }
  }

  /**
   * Checks structured analysis and returns its lossless JSON representation.
   */
  public static function validate(string $schema_json, array|\stdClass $analysis): string {
    $json = json_encode((object) $analysis, JSON_THROW_ON_ERROR);
    $data = json_decode($json);
    $validator = new Validator();
    $validator->validate($data, self::parse($schema_json));
    if (!$validator->isValid()) {
      throw new \InvalidArgumentException('Analysis does not match the review definition schema.');
    }
    return $json;
  }

}
