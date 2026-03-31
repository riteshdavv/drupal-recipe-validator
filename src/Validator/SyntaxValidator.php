<?php

declare(strict_types=1);

namespace DrupalRecipeValidator\Validator;

use Symfony\Component\Yaml\Yaml;
use Symfony\Component\Yaml\Exception\ParseException;

/**
 * Stage 1 - Syntax Validation.
 *
 * Uses Symfony's YAML parser (the same library in Drupal core via Composer)
 * to detect malformed YAML before any schema checks are attempted.
 *
 * Returns:
 *   passed  bool    - whether the file parsed successfully
 *   errors  array   - error messages with line numbers if parsing failed
 *   parsed  array   - the parsed PHP array on success (passed to Stage 2)
 */
class SyntaxValidator
{
    public function validate(string $filePath): array
    {
        if (!file_exists($filePath)) {
            return [
                'passed' => false,
                'errors' => ["File not found: {$filePath}"],
                'parsed' => [],
            ];
        }

        $content = file_get_contents($filePath);

        if ($content === false || trim($content) === '') {
            return [
                'passed' => false,
                'errors' => ['File is empty or unreadable.'],
                'parsed' => [],
            ];
        }

        try {
            // Symfony\Component\Yaml\Yaml::parse() throws ParseException
            // on malformed YAML - same behaviour as Drupal core.
            $parsed = Yaml::parse($content);

            if (!is_array($parsed)) {
                return [
                    'passed' => false,
                    'errors' => ['YAML did not parse to an array. Expected a mapping at the top level.'],
                    'parsed' => [],
                ];
            }

            return [
                'passed' => true,
                'errors' => [],
                'parsed' => $parsed,
            ];
        } catch (ParseException $e) {
            return [
                'passed' => false,
                'errors' => [
                    sprintf(
                        'YAML syntax error on line %d: %s',
                        $e->getParsedLine(),
                        $e->getMessage()
                    )
                ],
                'parsed' => [],
            ];
        }
    }
}
