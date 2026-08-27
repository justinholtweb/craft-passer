<?php

namespace justinholtweb\passer\importers;

use craft\base\Model;
use justinholtweb\passer\models\MigrationPlan;
use justinholtweb\passer\progress\ProgressInterface;
use justinholtweb\passer\services\IdMap;
use justinholtweb\passer\sources\SourceInterface;

/**
 * Everything an importer needs, in one object.
 *
 * Importers take a context rather than reaching for the plugin's services directly, so a single
 * phase can be run in isolation from a test or a console command without booting a whole run.
 */
class RunContext extends Model
{
    public SourceInterface $source;
    public MigrationPlan $plan;
    public IdMap $map;
    public ProgressInterface $progress;

    public ?int $runId = null;
    public bool $dryRun = false;

    /** @var bool Update elements already in the map, rather than skipping them. */
    public bool $updateExisting = true;

    /** @var bool Keep going past a failed record. */
    public bool $continueOnError = true;

    public int $batchSize = 50;

    /** @var int|null Stop after this many records in the current phase. For previews. */
    public ?int $limit = null;

    /** @var array<string, int> Per-phase running totals: created, updated, skipped, failed. */
    public array $counts = [];

    /**
     * @var callable(string, string, array<string, mixed>): void A logger the run supplies,
     * called as ($level, $message, $context).
     */
    public $logger;

    public function count(string $phase, string $key, int $by = 1): void
    {
        $this->counts[$phase . '.' . $key] = ($this->counts[$phase . '.' . $key] ?? 0) + $by;
    }

    public function total(string $phase, string $key): int
    {
        return $this->counts[$phase . '.' . $key] ?? 0;
    }

    /**
     * @param array<string, mixed> $context
     */
    public function log(string $level, string $message, array $context = []): void
    {
        if (is_callable($this->logger)) {
            ($this->logger)($level, $message, $context);
        }
    }
}
