<?php

namespace justinholtweb\passer\progress;

/**
 * Reports nothing. Used by tests and by preview runs.
 */
class NullProgress implements ProgressInterface
{
    public function startPhase(string $phase, ?int $total = null): void
    {
    }

    public function advance(int $done, ?string $label = null): void
    {
    }

    public function endPhase(string $phase): void
    {
    }

    public function message(string $message): void
    {
    }
}
