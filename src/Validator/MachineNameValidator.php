<?php

declare(strict_types=1);

namespace DrupalRecipeValidator\Validator;

/**
 * Stage 3 - Machine Name Validation.
 *
 * Validates that all machine names in the Recipe follow Drupal's conventions:
 *   - Module names in 'install': /^[a-z][a-z0-9_]*$/
 *   - Config entity names in 'config.import': valid config entity key format
 *
 * Drupal machine name rules:
 *   - Lowercase letters only
 *   - May contain digits and underscores
 *   - Must start with a letter (not a digit or underscore)
 *   - No hyphens, spaces, or uppercase letters
 *   - Maximum 64 characters (Drupal's limit)
 */
class MachineNameValidator
{
    /**
     * Valid Drupal machine name pattern.
     * Must start with a lowercase letter, followed by lowercase letters, digits, or underscores.
     */
    private const MACHINE_NAME_PATTERN = '/^[a-z][a-z0-9_]*$/';

    /**
     * Maximum length for a Drupal machine name.
     */
    private const MAX_LENGTH = 64;

    /**
     * Known Drupal core modules - used to warn when a listed module is not recognised.
     * Not exhaustive - just common ones to catch obvious typos.
     */
    private const KNOWN_CORE_MODULES = [
        'node', 'user', 'field', 'field_ui', 'text', 'image', 'file',
        'media', 'views', 'views_ui', 'taxonomy', 'path', 'menu_ui',
        'block', 'block_content', 'system', 'filter', 'editor', 'ckeditor5',
        'layout_builder', 'layout_discovery', 'link', 'datetime', 'options',
        'number', 'email', 'telephone', 'comment', 'history', 'search',
        'shortcut', 'toolbar', 'help', 'tour', 'contextual', 'aggregator',
        'book', 'contact', 'forum', 'locale', 'language', 'content_translation',
        'config_translation', 'interface_translation', 'migrate', 'statistics',
        'tracker', 'dblog', 'syslog', 'update', 'automated_cron', 'ban',
        'basic_auth', 'big_pipe', 'breakpoint', 'color', 'config',
        'content_moderation', 'workflows', 'dynamic_page_cache', 'page_cache',
        'rest', 'serialization', 'hal', 'jsonapi',
    ];

    public function validate(array $parsed): array
    {
        $errors   = [];
        $warnings = [];

        // Validate module names in 'install'
        if (!empty($parsed['install']) && is_array($parsed['install'])) {
            foreach ($parsed['install'] as $module) {
                if (!is_string($module)) {
                    continue; // Already caught by SchemaValidator
                }

                $moduleErrors = $this->validateMachineName($module, 'install');
                $errors = array_merge($errors, $moduleErrors);

                // Warn if the module name looks valid but is not a known core module
                // (could be a contrib module, so this is a warning not an error)
                if (
                    empty($moduleErrors)
                    && !in_array($module, self::KNOWN_CORE_MODULES, true)
                ) {
                    $warnings[] = "Module '{$module}' in 'install' is not a recognised Drupal core module. "
                        . "If this is a contrib module, ensure it is listed as a dependency.";
                }
            }
        }

        // Validate config entity machine names in 'config.import'
        if (!empty($parsed['config']['import']) && is_array($parsed['config']['import'])) {
            foreach (array_keys($parsed['config']['import']) as $configKey) {
                $this->validateConfigImportKey((string) $configKey, $errors, $warnings);
            }
        }

        return [
            'passed'   => empty($errors),
            'errors'   => $errors,
            'warnings' => $warnings,
        ];
    }

    /**
     * Validate a single machine name string.
     * Returns an array of error strings (empty if valid).
     */
    private function validateMachineName(string $name, string $context): array
    {
        $errors = [];

        if (strlen($name) > self::MAX_LENGTH) {
            $errors[] = "'{$name}' in '{$context}' exceeds maximum machine name length of " . self::MAX_LENGTH . " characters.";
        }

        if (!preg_match(self::MACHINE_NAME_PATTERN, $name)) {
            if (preg_match('/[A-Z]/', $name)) {
                $errors[] = "'{$name}' in '{$context}' contains uppercase letters. Drupal machine names must be lowercase.";
            } elseif (str_starts_with($name, '_') || str_starts_with($name, '-')) {
                $errors[] = "'{$name}' in '{$context}' starts with a non-letter character. Machine names must start with a lowercase letter.";
            } elseif (str_contains($name, '-')) {
                $errors[] = "'{$name}' in '{$context}' contains a hyphen. Use underscores instead (e.g., '" . str_replace('-', '_', $name) . "').";
            } elseif (str_contains($name, ' ')) {
                $errors[] = "'{$name}' in '{$context}' contains spaces. Machine names cannot contain spaces.";
            } else {
                $errors[] = "'{$name}' in '{$context}' is not a valid Drupal machine name. "
                    . "Must match /^[a-z][a-z0-9_]*$/.";
            }
        }

        return $errors;
    }

    /**
     * Validate a config.import key.
     *
     * Config import keys follow the pattern: entity_type.bundle.name
     * Wildcards are allowed: field.storage.*: '*'
     *
     * Examples of valid keys:
     *   field.storage.node.body
     *   node.type.article
     *   views.view.frontpage
     *   field.storage.*
     */
    private function validateConfigImportKey(string $key, array &$errors, array &$warnings): void
    {
        // Wildcard patterns are always valid
        if (str_contains($key, '*')) {
            return;
        }

        $parts = explode('.', $key);

        if (count($parts) < 2) {
            $errors[] = "Config import key '{$key}' must contain at least two dot-separated segments "
                . "(e.g., 'node.type.article').";
            return;
        }

        // Each segment (excluding wildcards) must be a valid machine name
        foreach ($parts as $part) {
            if ($part === '*') {
                continue;
            }
            $segmentErrors = $this->validateMachineName($part, "config.import key '{$key}'");
            $errors = array_merge($errors, $segmentErrors);
        }
    }
}
