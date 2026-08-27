<?php

namespace justinholtweb\passer\importers;

/**
 * One phase of a migration.
 */
interface ImporterInterface
{
    /**
     * The phase name, matching what MigrationPlan::phases() returns.
     */
    public static function phase(): string;

    /**
     * How many records this phase expects to process, for the progress bar. Null when unknowable.
     */
    public function estimate(RunContext $context): ?int;

    /**
     * Run the phase.
     *
     * `$resumeFrom` is the checkpoint the last attempt reached, so a run that was paused or
     * crashed picks up rather than starting over. Importers that cannot resume mid-phase should
     * ignore it and rely on the ID map to make repeated work cheap.
     *
     * @param array<string, mixed> $resumeFrom
     * @return array<string, mixed> The checkpoint reached, passed back on the next attempt.
     */
    public function import(RunContext $context, array $resumeFrom = []): array;
}
