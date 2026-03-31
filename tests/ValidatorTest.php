<?php

declare(strict_types=1);

namespace DrupalRecipeValidator\Tests;

use DrupalRecipeValidator\Validator\SyntaxValidator;
use DrupalRecipeValidator\Validator\SchemaValidator;
use DrupalRecipeValidator\Validator\MachineNameValidator;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * Full test suite for the three-stage Drupal Recipe validation pipeline.
 *
 * Run with: composer test
 */
class ValidatorTest extends TestCase
{
    private SyntaxValidator $syntaxValidator;
    private SchemaValidator $schemaValidator;
    private MachineNameValidator $machineNameValidator;

    private string $validFixturesDir;
    private string $invalidFixturesDir;

    protected function setUp(): void
    {
        $this->syntaxValidator      = new SyntaxValidator();
        $this->schemaValidator      = new SchemaValidator();
        $this->machineNameValidator = new MachineNameValidator();

        $this->validFixturesDir   = __DIR__ . '/fixtures/valid';
        $this->invalidFixturesDir = __DIR__ . '/fixtures/invalid';
    }

    // ── Stage 1: Syntax ───────────────────────────────────────────────────

    #[Test]
    public function syntaxValidatorPassesOnWellFormedYaml(): void
    {
        $result = $this->syntaxValidator->validate($this->validFixturesDir . '/team_members.yml');

        $this->assertTrue($result['passed']);
        $this->assertEmpty($result['errors']);
        $this->assertIsArray($result['parsed']);
        $this->assertEquals('Team Members', $result['parsed']['name']);
    }

    #[Test]
    public function syntaxValidatorFailsOnMalformedYaml(): void
    {
        $result = $this->syntaxValidator->validate($this->invalidFixturesDir . '/syntax_error.yml');

        $this->assertFalse($result['passed']);
        $this->assertNotEmpty($result['errors']);
        $this->assertStringContainsString('syntax error', strtolower($result['errors'][0]));
    }

    #[Test]
    public function syntaxValidatorFailsOnMissingFile(): void
    {
        $result = $this->syntaxValidator->validate('/nonexistent/path/recipe.yml');

        $this->assertFalse($result['passed']);
        $this->assertNotEmpty($result['errors']);
    }

    // ── Stage 2: Schema ───────────────────────────────────────────────────

    #[Test]
    public function schemaValidatorPassesOnValidRecipe(): void
    {
        $parsed = [
            'name'        => 'Team Members',
            'type'        => 'Recipe',
            'description' => 'Adds a Team Member content type.',
            'install'     => ['node', 'field_ui'],
        ];

        $result = $this->schemaValidator->validate($parsed);

        $this->assertTrue($result['passed']);
        $this->assertEmpty($result['errors']);
    }

    #[Test]
    public function schemaValidatorFailsOnMissingName(): void
    {
        $parsed = ['type' => 'Recipe'];

        $result = $this->schemaValidator->validate($parsed);

        $this->assertFalse($result['passed']);
        $this->assertContains("Missing required key: 'name'.", $result['errors']);
    }

    #[Test]
    public function schemaValidatorFailsOnMissingType(): void
    {
        $parsed = ['name' => 'My Recipe'];

        $result = $this->schemaValidator->validate($parsed);

        $this->assertFalse($result['passed']);
        $this->assertContains("Missing required key: 'type'.", $result['errors']);
    }

    #[Test]
    public function schemaValidatorFailsOnWrongType(): void
    {
        $parsed = ['name' => 'My Recipe', 'type' => 'Module'];

        $result = $this->schemaValidator->validate($parsed);

        $this->assertFalse($result['passed']);
        $this->assertNotEmpty(array_filter($result['errors'], fn($e) => str_contains($e, "'type' must be exactly 'Recipe'")));
    }

    #[Test]
    public function schemaValidatorFailsWhenInstallIsNotArray(): void
    {
        $parsed = ['name' => 'My Recipe', 'type' => 'Recipe', 'install' => 'node'];

        $result = $this->schemaValidator->validate($parsed);

        $this->assertFalse($result['passed']);
        $this->assertNotEmpty(array_filter($result['errors'], fn($e) => str_contains($e, "'install' must be an array")));
    }

    #[Test]
    public function schemaValidatorWarnsOnUnknownTopLevelKey(): void
    {
        $parsed = ['name' => 'My Recipe', 'type' => 'Recipe', 'unknown_key' => 'value'];

        $result = $this->schemaValidator->validate($parsed);

        $this->assertTrue($result['passed']); // warnings don't fail
        $this->assertNotEmpty(array_filter($result['warnings'] ?? [], fn($w) => str_contains($w, 'unknown_key')));
    }

    // ── Stage 3: Machine Names ────────────────────────────────────────────

    #[Test]
    public function machineNameValidatorPassesOnValidNames(): void
    {
        $parsed = [
            'install' => ['node', 'field_ui', 'my_custom_module'],
        ];

        $result = $this->machineNameValidator->validate($parsed);

        $this->assertTrue($result['passed']);
        $this->assertEmpty($result['errors']);
    }

    #[Test]
    public function machineNameValidatorFailsOnCamelCase(): void
    {
        $parsed = ['install' => ['node', 'FieldUI', 'MyModule']];

        $result = $this->machineNameValidator->validate($parsed);

        $this->assertFalse($result['passed']);
        $this->assertCount(2, $result['errors']); // FieldUI and MyModule both fail
    }

    #[Test]
    public function machineNameValidatorFailsOnHyphenatedName(): void
    {
        $parsed = ['install' => ['my-module']];

        $result = $this->machineNameValidator->validate($parsed);

        $this->assertFalse($result['passed']);
        $this->assertNotEmpty(array_filter($result['errors'], fn($e) => str_contains($e, 'hyphen')));
    }

    #[Test]
    public function machineNameValidatorFailsOnUnderscoreStart(): void
    {
        $parsed = ['install' => ['_bad_name']];

        $result = $this->machineNameValidator->validate($parsed);

        $this->assertFalse($result['passed']);
    }

    #[Test]
    public function machineNameValidatorFailsOnDigitStart(): void
    {
        $parsed = ['install' => ['1startsWithDigit']];

        $result = $this->machineNameValidator->validate($parsed);

        $this->assertFalse($result['passed']);
    }

    #[Test]
    public function machineNameValidatorWarnsOnUnknownModule(): void
    {
        $parsed = ['install' => ['node', 'some_contrib_module']];

        $result = $this->machineNameValidator->validate($parsed);

        // Valid machine name but unknown → warning not error
        $this->assertTrue($result['passed']);
        $this->assertNotEmpty(array_filter($result['warnings'], fn($w) => str_contains($w, 'some_contrib_module')));
    }

    // ── End-to-end: Valid fixture files ───────────────────────────────────

    #[Test]
    public function fullPipelinePassesOnTeamMembersFixture(): void
    {
        $syntaxResult = $this->syntaxValidator->validate($this->validFixturesDir . '/team_members.yml');
        $this->assertTrue($syntaxResult['passed'], 'Stage 1 failed: ' . implode(', ', $syntaxResult['errors']));

        $schemaResult = $this->schemaValidator->validate($syntaxResult['parsed']);
        $this->assertTrue($schemaResult['passed'], 'Stage 2 failed: ' . implode(', ', $schemaResult['errors']));

        $nameResult = $this->machineNameValidator->validate($syntaxResult['parsed']);
        $this->assertTrue($nameResult['passed'], 'Stage 3 failed: ' . implode(', ', $nameResult['errors']));
    }

    #[Test]
    public function fullPipelinePassesOnBlogFixture(): void
    {
        $syntaxResult = $this->syntaxValidator->validate($this->validFixturesDir . '/blog.yml');
        $this->assertTrue($syntaxResult['passed']);

        $schemaResult = $this->schemaValidator->validate($syntaxResult['parsed']);
        $this->assertTrue($schemaResult['passed']);

        $nameResult = $this->machineNameValidator->validate($syntaxResult['parsed']);
        $this->assertTrue($nameResult['passed']);
    }

    // ── End-to-end: Invalid fixture files ────────────────────────────────

    #[Test]
    public function fullPipelineFailsOnMissingNameFixture(): void
    {
        $syntaxResult = $this->syntaxValidator->validate($this->invalidFixturesDir . '/missing_name.yml');
        $this->assertTrue($syntaxResult['passed']); // Valid YAML syntax

        $schemaResult = $this->schemaValidator->validate($syntaxResult['parsed']);
        $this->assertFalse($schemaResult['passed']); // Fails schema
    }

    #[Test]
    public function fullPipelineFailsOnBadMachineNamesFixture(): void
    {
        $syntaxResult = $this->syntaxValidator->validate($this->invalidFixturesDir . '/bad_machine_names.yml');
        $this->assertTrue($syntaxResult['passed']); // Valid YAML syntax

        $schemaResult = $this->schemaValidator->validate($syntaxResult['parsed']);
        $this->assertTrue($schemaResult['passed']); // Valid schema

        $nameResult = $this->machineNameValidator->validate($syntaxResult['parsed']);
        $this->assertFalse($nameResult['passed']); // Fails machine names
        $this->assertGreaterThanOrEqual(3, count($nameResult['errors'])); // FieldUI, my-custom-module, _bad_start
    }

    #[Test]
    public function fullPipelineFailsOnSyntaxErrorFixture(): void
    {
        $syntaxResult = $this->syntaxValidator->validate($this->invalidFixturesDir . '/syntax_error.yml');
        $this->assertFalse($syntaxResult['passed']); // Fails at stage 1
    }
}
