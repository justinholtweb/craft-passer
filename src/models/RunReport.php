<?php

namespace justinholtweb\passer\models;

use craft\base\Model;

/**
 * What a run actually did, in a form a person can read.
 *
 * A migration's report is not decoration. It is the only way to answer the question everyone
 * asks afterwards — "did everything come across?" — and the only place the honest answers to the
 * hard parts live: which shortcodes could not be expanded, which forms need rebuilding, which
 * orders did not reconcile.
 */
class RunReport extends Model
{
    public ?int $runId = null;
    public string $planName = '';
    public string $sourceType = '';
    public ?string $siteUrl = null;
    public bool $dryRun = false;

    public ?\DateTime $started = null;
    public ?\DateTime $finished = null;

    /** @var array<string, array{created: int, updated: int, skipped: int, failed: int}> */
    public array $phases = [];

    /** @var array<int, array{level: string, phase: string|null, message: string, sourceTitle: string|null}> */
    public array $messages = [];

    public function duration(): ?int
    {
        if ($this->started === null || $this->finished === null) {
            return null;
        }

        return $this->finished->getTimestamp() - $this->started->getTimestamp();
    }

    public function durationForHumans(): string
    {
        $seconds = $this->duration();

        if ($seconds === null) {
            return 'unknown';
        }

        if ($seconds < 60) {
            return $seconds . ' second' . ($seconds === 1 ? '' : 's');
        }

        $minutes = intdiv($seconds, 60);

        if ($minutes < 60) {
            return $minutes . ' minute' . ($minutes === 1 ? '' : 's');
        }

        $hours = intdiv($minutes, 60);
        $remainder = $minutes % 60;

        return sprintf('%d hour%s %d minute%s', $hours, $hours === 1 ? '' : 's', $remainder, $remainder === 1 ? '' : 's');
    }

    public function total(string $key): int
    {
        $total = 0;

        foreach ($this->phases as $counts) {
            $total += $counts[$key] ?? 0;
        }

        return $total;
    }

    /**
     * @return array<int, array{level: string, phase: string|null, message: string, sourceTitle: string|null}>
     */
    public function messagesAtLevel(string $level): array
    {
        return array_values(array_filter($this->messages, static fn(array $m) => $m['level'] === $level));
    }

    public function hasProblems(): bool
    {
        return $this->total('failed') > 0 || $this->messagesAtLevel('error') !== [];
    }

    /**
     * A one-paragraph summary, which is what most people will read and nothing else.
     */
    public function summary(): string
    {
        $created = $this->total('created');
        $updated = $this->total('updated');
        $failed = $this->total('failed');

        $parts = [];

        if ($created > 0) {
            $parts[] = number_format($created) . ' created';
        }

        if ($updated > 0) {
            $parts[] = number_format($updated) . ' updated';
        }

        if ($failed > 0) {
            $parts[] = number_format($failed) . ' failed';
        }

        if ($parts === []) {
            $parts[] = 'nothing to do';
        }

        return sprintf(
            '%s%s in %s: %s.',
            $this->dryRun ? 'Dry run of ' : '',
            $this->planName,
            $this->durationForHumans(),
            implode(', ', $parts)
        );
    }

    /**
     * Render the whole report as plain text, for the console and for email.
     */
    public function toText(): string
    {
        $lines = [];
        $lines[] = $this->planName;
        $lines[] = str_repeat('=', mb_strlen($this->planName));
        $lines[] = '';
        $lines[] = $this->summary();

        if ($this->siteUrl !== null) {
            $lines[] = 'Source: ' . $this->siteUrl . ' (' . $this->sourceType . ')';
        }

        $lines[] = '';

        if ($this->phases !== []) {
            $lines[] = 'By phase';
            $lines[] = '--------';

            foreach ($this->phases as $phase => $counts) {
                $lines[] = sprintf(
                    '  %-12s %6s created  %6s updated  %6s skipped  %6s failed',
                    $phase,
                    number_format($counts['created'] ?? 0),
                    number_format($counts['updated'] ?? 0),
                    number_format($counts['skipped'] ?? 0),
                    number_format($counts['failed'] ?? 0)
                );
            }

            $lines[] = '';
        }

        foreach (['error' => 'Errors', 'warning' => 'Warnings', 'notice' => 'Worth knowing'] as $level => $heading) {
            $messages = $this->messagesAtLevel($level);

            if ($messages === []) {
                continue;
            }

            $lines[] = $heading;
            $lines[] = str_repeat('-', mb_strlen($heading));

            foreach ($messages as $message) {
                $prefix = $message['sourceTitle'] !== null && $message['sourceTitle'] !== ''
                    ? $message['sourceTitle'] . ': '
                    : '';

                $lines[] = '  ' . $prefix . str_replace("\n", "\n  ", $message['message']);
            }

            $lines[] = '';
        }

        return implode("\n", $lines);
    }
}
