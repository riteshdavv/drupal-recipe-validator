<?php

declare(strict_types=1);

namespace DrupalRecipeValidator\Report;

/**
 * Aggregates validation results across one or more files.
 *
 * Used by the CLI command to collect per-file results and then
 * render a summary or produce JSON output.
 */
class ValidationReport
{
    /** @var array<string, array> */
    private array $results = [];

    public function addResult(string $filePath, array $result): void
    {
        $this->results[$filePath] = $result;
    }

    /** @return array<string, array> */
    public function getResults(): array
    {
        return $this->results;
    }

    public function hasFailures(): bool
    {
        foreach ($this->results as $result) {
            if (!$result['passed']) {
                return true;
            }
        }
        return false;
    }

    public function passedCount(): int
    {
        return count(array_filter($this->results, fn($r) => $r['passed']));
    }

    public function failedCount(): int
    {
        return count(array_filter($this->results, fn($r) => !$r['passed']));
    }

    /**
     * Serialize to a plain array suitable for JSON encoding.
     */
    public function toArray(): array
    {
        $output = [
            'summary' => [
                'total'  => count($this->results),
                'passed' => $this->passedCount(),
                'failed' => $this->failedCount(),
            ],
            'files' => [],
        ];

        foreach ($this->results as $filePath => $result) {
            $output['files'][] = [
                'file'     => $filePath,
                'passed'   => $result['passed'],
                'stage'    => $result['stage'] ?? 'all',
                'errors'   => $result['errors'] ?? [],
                'warnings' => $result['warnings'] ?? [],
            ];
        }

        return $output;
    }
}
