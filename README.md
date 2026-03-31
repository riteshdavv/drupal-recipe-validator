# Drupal Recipe Validator

A standalone CLI tool to validate Drupal Recipe YAML files against the official schema - built as a pre-proposal proof-of-concept for the **AI-Powered Drupal Recipe Generator for Starshot** GSoC 2026 project.

## What it does

Validates `recipe.yml` files through a **three-stage sequential pipeline** - the same pipeline architecture proposed in the GSoC 2026 project:

| Stage | What it checks |
|-------|---------------|
| **Stage 1 - Syntax** | Symfony YAML parser (same library in Drupal core via Composer) - detects malformed YAML, invalid indentation, unclosed strings |
| **Stage 2 - Schema** | Required keys (`name`, `type`), `type` must be exactly `'Recipe'`, `install` must be an array, `config` key structure |
| **Stage 3 - Machine Names** | All module names match `/^[a-z][a-z0-9_]*$/` - catches camelCase, hyphens, digit-starts, underscores-starts |

Each stage only runs if the previous stage passed - syntax errors are caught before schema checks, schema errors before machine name checks.

## Installation

```bash
git clone https://github.com/riteshdavv/drupal-recipe-validator
cd drupal-recipe-validator
composer install
chmod +x bin/validate-recipe
```

## Usage

```bash
# Validate a single file
bin/validate-recipe path/to/recipe.yml

# Validate an entire directory
bin/validate-recipe path/to/recipes/

# JSON output (for CI integration)
bin/validate-recipe recipe.yml --json

# Treat warnings as errors (strict mode)
bin/validate-recipe recipe.yml --strict
```

## Example output

**Valid recipe:**
```
 Drupal Recipe Validator
 ✓ team_members.yml - all stages passed

 [OK] All 1 recipe(s) passed validation.
```

**Invalid recipe (bad machine names):**
```
 Drupal Recipe Validator
 ✗ bad_machine_names.yml [machine_names]
     ✗ 'FieldUI' in 'install' contains uppercase letters. Drupal machine names must be lowercase.
     ✗ 'my-custom-module' in 'install' contains a hyphen. Use underscores instead (e.g., 'my_custom_module').
     ✗ '_bad_start' in 'install' starts with a non-letter character.

 [ERROR] 1 of 1 recipe(s) failed validation.
```

**JSON output (for CI):**
```json
{
    "summary": {
        "total": 1,
        "passed": 0,
        "failed": 1
    },
    "files": [
        {
            "file": "bad_machine_names.yml",
            "passed": false,
            "stage": "machine_names",
            "errors": [
                "'FieldUI' in 'install' contains uppercase letters."
            ],
            "warnings": []
        }
    ]
}
```

## Run the tests

```bash
composer test
```

The test suite covers all three stages independently and as end-to-end pipeline tests using fixture files in `tests/fixtures/`.

## Connection to GSoC 2026

This tool implements **Component C - Validation Pipeline** from the proposed [AI-Powered Drupal Recipe Generator for Starshot](https://www.drupal.org/project/gsoc/issues/3573833) as a standalone prototype.

The three-stage validation logic here will be integrated into the full Drupal module as the `RecipeSchemaValidator` and `ConflictResolver` services, with Stage 3 extended to cross-reference generated machine names against active site configuration via Drupal's `ConfigFactory`.

## Project structure

```
drupal-recipe-validator/
├── bin/
│   └── validate-recipe          # CLI entry point
├── src/
│   ├── Command/
│   │   └── ValidateCommand.php  # Symfony Console command
│   ├── Validator/
│   │   ├── SyntaxValidator.php  # Stage 1
│   │   ├── SchemaValidator.php  # Stage 2
│   │   └── MachineNameValidator.php  # Stage 3
│   └── Report/
│       └── ValidationReport.php # Result aggregator
├── tests/
│   ├── fixtures/
│   │   ├── valid/               # Valid recipe YAML fixtures
│   │   └── invalid/             # Invalid recipe YAML fixtures
│   └── ValidatorTest.php        # Full test suite
├── composer.json
└── phpunit.xml
```

## Author

**Ritesh Kumar Singh** - GSoC 2025 contributor (Drupal Association)
- Drupal.org: [riteshdavv](https://www.drupal.org/u/riteshdavv)
- GitHub: [riteshdavv](https://github.com/riteshdavv)
