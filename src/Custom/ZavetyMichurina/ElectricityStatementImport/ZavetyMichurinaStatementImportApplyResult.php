<?php

namespace App\Custom\ZavetyMichurina\ElectricityStatementImport;

final class ZavetyMichurinaStatementImportApplyResult
{
    /**
     * @var array<string, int>
     */
    private array $created = [];

    /**
     * @var array<string, int>
     */
    private array $reused = [];

    /**
     * @var array<string, int>
     */
    private array $skipped = [];

    /**
     * @var list<string>
     */
    private array $warnings = [];

    public function created(string $type): void
    {
        $this->created[$type] = ($this->created[$type] ?? 0) + 1;
    }

    public function reused(string $type): void
    {
        $this->reused[$type] = ($this->reused[$type] ?? 0) + 1;
    }

    public function skipped(string $type, ?string $warning = null): void
    {
        $this->skipped[$type] = ($this->skipped[$type] ?? 0) + 1;

        if ($warning !== null && trim($warning) !== '') {
            $this->warnings[] = trim($warning);
        }
    }

    public function warn(string $warning): void
    {
        $warning = trim($warning);

        if ($warning !== '') {
            $this->warnings[] = $warning;
        }
    }

    public function createdTotal(): int
    {
        return array_sum($this->created);
    }

    public function reusedTotal(): int
    {
        return array_sum($this->reused);
    }

    public function skippedTotal(): int
    {
        return array_sum($this->skipped);
    }

    /**
     * @return array{
     *     created: array<string, int>,
     *     reused: array<string, int>,
     *     skipped: array<string, int>,
     *     warnings: list<string>,
     *     totals: array{created: int, reused: int, skipped: int}
     * }
     */
    public function toArray(): array
    {
        ksort($this->created);
        ksort($this->reused);
        ksort($this->skipped);

        return [
            'created' => $this->created,
            'reused' => $this->reused,
            'skipped' => $this->skipped,
            'warnings' => $this->warnings,
            'totals' => [
                'created' => $this->createdTotal(),
                'reused' => $this->reusedTotal(),
                'skipped' => $this->skippedTotal(),
            ],
        ];
    }
}
