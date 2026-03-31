<?php

declare(strict_types=1);

namespace DrupalRecipeValidator\Command;

use DrupalRecipeValidator\Validator\SyntaxValidator;
use DrupalRecipeValidator\Validator\SchemaValidator;
use DrupalRecipeValidator\Validator\MachineNameValidator;
use DrupalRecipeValidator\Report\ValidationReport;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * CLI command: validate one or more Drupal Recipe YAML files.
 *
 * Usage:
 *   bin/validate-recipe recipe.yml
 *   bin/validate-recipe recipes/           (validate entire directory)
 *   bin/validate-recipe recipe.yml --json  (machine-readable output)
 */
class ValidateCommand extends Command
{
    protected static string $defaultName = 'validate';

    protected function configure(): void
    {
        $this
            ->setName('validate')
            ->setDescription('Validate a Drupal Recipe YAML file against the official schema.')
            ->addArgument(
                'path',
                InputArgument::REQUIRED,
                'Path to a recipe.yml file or a directory containing recipe files.'
            )
            ->addOption(
                'json',
                null,
                InputOption::VALUE_NONE,
                'Output results as JSON (useful for CI integration).'
            )
            ->addOption(
                'strict',
                null,
                InputOption::VALUE_NONE,
                'Treat warnings as errors.'
            )
            ->setHelp(
                "Validates Drupal Recipe YAML files through a three-stage pipeline:\n\n" .
                "  Stage 1 - Syntax:    Symfony YAML parser detects malformed syntax\n" .
                "  Stage 2 - Schema:    Checks required keys, valid type values, install array format\n" .
                "  Stage 3 - Names:     Validates all machine names match /^[a-z][a-z0-9_]*$/\n\n" .
                "Exit codes:\n" .
                "  0 = All files valid\n" .
                "  1 = One or more files failed validation\n" .
                "  2 = Invalid arguments / path not found\n"
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $path = $input->getArgument('path');
        $jsonMode = $input->getOption('json');
        $strict = $input->getOption('strict');

        // Resolve files to validate
        $files = $this->resolveFiles($path);

        if (empty($files)) {
            $io->error("No recipe YAML files found at: {$path}");
            return Command::INVALID;
        }

        // Run the three-stage pipeline
        $syntaxValidator   = new SyntaxValidator();
        $schemaValidator   = new SchemaValidator();
        $machineNameValidator = new MachineNameValidator();

        $report = new ValidationReport();

        foreach ($files as $file) {
            $result = $this->validateFile(
                $file,
                $syntaxValidator,
                $schemaValidator,
                $machineNameValidator,
                $strict
            );
            $report->addResult($file, $result);
        }

        // Output
        if ($jsonMode) {
            $output->writeln(json_encode($report->toArray(), JSON_PRETTY_PRINT));
        } else {
            $this->renderHumanOutput($io, $report, count($files));
        }

        return $report->hasFailures() ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * Run all three validation stages for a single file.
     * Stops at the first stage that fails - later stages depend on earlier ones.
     */
    private function validateFile(
        string $filePath,
        SyntaxValidator $syntax,
        SchemaValidator $schema,
        MachineNameValidator $machineNames,
        bool $strict
    ): array {
        // Stage 1: Syntax
        $syntaxResult = $syntax->validate($filePath);
        if (!$syntaxResult['passed']) {
            return [
                'passed'  => false,
                'stage'   => 'syntax',
                'errors'  => $syntaxResult['errors'],
                'warnings'=> [],
            ];
        }

        // Stage 2: Schema conformance
        $schemaResult = $schema->validate($syntaxResult['parsed']);
        if (!$schemaResult['passed']) {
            return [
                'passed'  => false,
                'stage'   => 'schema',
                'errors'  => $schemaResult['errors'],
                'warnings'=> $schemaResult['warnings'] ?? [],
            ];
        }

        // Stage 3: Machine name validation
        $nameResult = $machineNames->validate($syntaxResult['parsed']);
        $failed = !$nameResult['passed'] || ($strict && !empty($nameResult['warnings']));

        return [
            'passed'  => !$failed,
            'stage'   => $failed ? 'machine_names' : 'all',
            'errors'  => $nameResult['errors'],
            'warnings'=> $nameResult['warnings'],
        ];
    }

    /**
     * Resolve a path argument to an array of file paths.
     */
    private function resolveFiles(string $path): array
    {
        if (is_file($path)) {
            return [$path];
        }

        if (is_dir($path)) {
            $files = [];
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS)
            );
            foreach ($iterator as $file) {
                if ($file->getExtension() === 'yml' || $file->getExtension() === 'yaml') {
                    $files[] = $file->getPathname();
                }
            }
            sort($files);
            return $files;
        }

        return [];
    }

    /**
     * Render human-readable output with pass/fail indicators per file.
     */
    private function renderHumanOutput(SymfonyStyle $io, ValidationReport $report, int $total): void
    {
        $io->title('Drupal Recipe Validator');

        foreach ($report->getResults() as $file => $result) {
            $label = basename($file);

            if ($result['passed']) {
                $io->writeln(" <fg=green>✓</> {$label} <fg=gray>- all stages passed</>");
            } else {
                $io->writeln(" <fg=red>✗</> {$label} <fg=yellow>[{$result['stage']}]</>");
                foreach ($result['errors'] as $error) {
                    $io->writeln("     <fg=red>✗</> {$error}");
                }
                foreach ($result['warnings'] as $warning) {
                    $io->writeln("     <fg=yellow>⚠</> {$warning}");
                }
            }
        }

        $io->newLine();

        $passed  = $report->passedCount();
        $failed  = $report->failedCount();

        if ($report->hasFailures()) {
            $io->error("{$failed} of {$total} recipe(s) failed validation.");
        } else {
            $io->success("All {$total} recipe(s) passed validation.");
        }

        // Summary table
        $io->definitionList(
            ['Total files'  => $total],
            ['Passed'       => "<fg=green>{$passed}</>"],
            ['Failed'       => "<fg=red>{$failed}</>"],
        );
    }
}
