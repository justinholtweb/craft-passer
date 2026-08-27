<?php

namespace justinholtweb\passer\progress;

/**
 * How a run reports where it has got to.
 *
 * The same run is driven from a queue job, a console command and (for previews) a web request,
 * and each wants progress reported differently.
 */
interface ProgressInterface
{
    /**
     * Begin a phase. `$total` may be null when the source cannot count in advance.
     */
    public function startPhase(string $phase, ?int $total = null): void;

    /**
     * Report progress within the current phase.
     */
    public function advance(int $done, ?string $label = null): void;

    public function endPhase(string $phase): void;

    public function message(string $message): void;
}
