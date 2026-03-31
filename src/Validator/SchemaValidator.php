<?php

declare(strict_types=1);

namespace DrupalRecipeValidator\Validator;

/**
 * Stage 2 - Schema Conformance Validation.
 *
 * Checks the parsed YAML array against the Drupal Recipe schema:
 *   - Required keys: name, type
 *   - type must be exactly 'Recipe'
 *   - install must be an array (if present)
 *   - config key structure must be valid (if present)
 *   - description must be a string (if present)
 *
 * Schema reference:
 *   https://www.drupal.org/docs/drupal-apis/recipes/drupal-recipe-file-format
 */
class SchemaValidator
{
    /**
     * Required top-level keys in every Drupal Recipe.
     */
    private const REQUIRED_KEYS = ['name', 'type'];

    /**
     * The only valid value for the 'type' key.
     */
    private const VALID_TYPE = 'Recipe';

    /**
     * Allowed top-level keys - anything else triggers a warning (not an error)
     * to allow forward compatibility with new Drupal core schema additions.
     */
    private const KNOWN_KEYS = ['name', 'type', 'description', 'install', 'config'];

    public function validate(array $parsed): array
    {
        $errors   = [];
        $warnings = [];

        // ── Required keys ─────────────────────────────────────────────
        foreach (self::REQUIRED_KEYS as $key) {
            if (!array_key_exists($key, $parsed)) {
                $errors[] = "Missing required key: '{$key}'.";
            }
        }

        // If required keys are missing, skip further checks that depend on them
        if (!empty($errors)) {
            return ['passed' => false, 'errors' => $errors, 'warnings' => $warnings];
        }

        // ── name ──────────────────────────────────────────────────────
        if (!is_string($parsed['name']) || trim($parsed['name']) === '') {
            $errors[] = "'name' must be a non-empty string.";
        }

        // ── type ──────────────────────────────────────────────────────
        if ($parsed['type'] !== self::VALID_TYPE) {
            $errors[] = sprintf(
                "'type' must be exactly '%s', got '%s'.",
                self::VALID_TYPE,
                $parsed['type']
            );
        }

        // ── description (optional) ────────────────────────────────────
        if (array_key_exists('description', $parsed) && !is_string($parsed['description'])) {
            $errors[] = "'description' must be a string if present.";
        }

        // ── install (optional) ────────────────────────────────────────
        if (array_key_exists('install', $parsed)) {
            if (!is_array($parsed['install'])) {
                $errors[] = "'install' must be an array of module machine names.";
            } elseif (array_is_list($parsed['install']) === false) {
                $errors[] = "'install' must be a sequential list (YAML sequence), not a mapping.";
            } else {
                foreach ($parsed['install'] as $index => $module) {
                    if (!is_string($module)) {
                        $errors[] = "'install[{$index}]' must be a string module name, got: " . gettype($module) . ".";
                    }
                }
            }
        }

        // ── config (optional) ─────────────────────────────────────────
        if (array_key_exists('config', $parsed)) {
            if (!is_array($parsed['config'])) {
                $errors[] = "'config' must be a mapping (array) if present.";
            } else {
                $this->validateConfigKey($parsed['config'], $errors, $warnings);
            }
        }

        // ── Unknown top-level keys → warnings ─────────────────────────
        foreach (array_keys($parsed) as $key) {
            if (!in_array($key, self::KNOWN_KEYS, true)) {
                $warnings[] = "Unknown top-level key '{$key}' - not part of the current Drupal Recipe schema.";
            }
        }

        return [
            'passed'   => empty($errors),
            'errors'   => $errors,
            'warnings' => $warnings,
        ];
    }

    /**
     * Validate the structure of the 'config' key.
     *
     * Drupal Recipe config key supports:
     *   config:
     *     import:
     *       field.storage.*: '*'
     *     actions:
     *       user.settings:
     *         simple_config_update: ...
     */
    private function validateConfigKey(array $config, array &$errors, array &$warnings): void
    {
        $knownConfigKeys = ['import', 'actions'];

        foreach (array_keys($config) as $key) {
            if (!in_array($key, $knownConfigKeys, true)) {
                $warnings[] = "Unknown 'config' sub-key '{$key}'.";
            }
        }

        if (array_key_exists('import', $config) && !is_array($config['import'])) {
            $errors[] = "'config.import' must be a mapping if present.";
        }

        if (array_key_exists('actions', $config) && !is_array($config['actions'])) {
            $errors[] = "'config.actions' must be a mapping if present.";
        }
    }
}
