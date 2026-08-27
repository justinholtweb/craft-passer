<?php

namespace justinholtweb\passer\progress;

use Craft;
use yii\helpers\Console;

/**
 * Reports a run's progress to a terminal.
 */
class ConsoleProgress implements ProgressInterface
{
    private ?int $total = null;
    private int $lastReported = -1;
    private string $phase = '';

    public function __construct(private bool $verbose = false)
    {
    }

    public function startPhase(string $phase, ?int $total = null): void
    {
        $this->phase = $phase;
        $this->total = $total;
        $this->lastReported = -1;

        Craft::$app->getController()?->stdout(
            sprintf(
                "\n%s%s\n",
                Console::ansiFormat(ucfirst($phase), [Console::BOLD]),
                $total !== null ? ' (' . number_format($total) . ')' : ''
            )
        );
    }

    public function advance(int $done, ?string $label = null): void
    {
        // Redrawing on every record makes a large import slower than the import itself; once
        // per percent is plenty.
        $percent = $this->total !== null && $this->total > 0
            ? (int)floor(($done / $this->total) * 100)
            : null;

        if ($percent !== null && $percent === $this->lastReported && !$this->verbose) {
            return;
        }

        $this->lastReported = $percent ?? $this->lastReported;

        $line = $percent !== null
            ? sprintf('  %3d%%  %s of %s', $percent, number_format($done), number_format((int)$this->total))
            : sprintf('  %s', number_format($done));

        if ($this->verbose && $label !== null) {
            $line .= '  ' . $label;
        }

        Craft::$app->getController()?->stdout("\r" . str_pad($line, 78));
    }

    public function endPhase(string $phase): void
    {
        Craft::$app->getController()?->stdout("\r" . str_pad('  done', 78) . "\n");
    }

    public function message(string $message): void
    {
        if ($this->verbose) {
            Craft::$app->getController()?->stdout('  ' . $message . "\n");
        }
    }
}
