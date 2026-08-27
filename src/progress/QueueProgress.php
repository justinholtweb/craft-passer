<?php

namespace justinholtweb\passer\progress;

use craft\queue\QueueInterface;
use yii\queue\Queue;

/**
 * Reports a run's progress to Craft's queue, so the control panel's progress bar moves.
 *
 * Craft's queue takes one 0–1 float for the whole job, but a migration is a sequence of phases
 * of very different sizes. Weighting each phase equally would make the bar lurch; weighting by
 * record count would make the media phase — which is slow but not numerous — appear stuck. So
 * phases are given a share of the bar in proportion to their expected count, and progress within
 * a phase moves only that share.
 */
class QueueProgress implements ProgressInterface
{
    private string $phase = '';
    private ?int $total = null;
    private float $phaseStart = 0.0;
    private float $phaseSpan = 1.0;
    private string $label = '';

    /**
     * @param array<string, int> $phaseWeights Phase name => expected record count.
     */
    public function __construct(
        private Queue|QueueInterface $queue,
        private array $phaseWeights = [],
    ) {
    }

    public function startPhase(string $phase, ?int $total = null): void
    {
        $this->phase = $phase;
        $this->total = $total;

        [$this->phaseStart, $this->phaseSpan] = $this->shareFor($phase);

        $this->report(0.0, $this->humanize($phase));
    }

    public function advance(int $done, ?string $label = null): void
    {
        if ($label !== null) {
            $this->label = $label;
        }

        $fraction = $this->total !== null && $this->total > 0
            ? min(1.0, $done / $this->total)
            // With no total, report the phase as half done: honest about being unmeasurable,
            // and it still moves when the phase ends.
            : 0.5;

        $this->report($fraction, sprintf(
            '%s — %s%s',
            $this->humanize($this->phase),
            number_format($done),
            $this->total !== null ? ' of ' . number_format($this->total) : ''
        ));
    }

    public function endPhase(string $phase): void
    {
        $this->report(1.0, $this->humanize($phase) . ' — done');
    }

    public function message(string $message): void
    {
        $this->label = $message;
    }

    private function report(float $fractionOfPhase, string $label): void
    {
        $overall = $this->phaseStart + ($this->phaseSpan * max(0.0, min(1.0, $fractionOfPhase)));

        $this->queue->setProgress((int)round($overall * 100), $label);
    }

    /**
     * @return array{0: float, 1: float} Start and span of this phase's share of the bar.
     */
    private function shareFor(string $phase): array
    {
        if ($this->phaseWeights === []) {
            return [0.0, 1.0];
        }

        // Weights are floored so a phase with no records still gets a visible sliver, rather
        // than the bar jumping over it.
        $weights = array_map(static fn(int $w) => max(1, $w), $this->phaseWeights);
        $total = array_sum($weights);

        $start = 0;

        foreach ($weights as $name => $weight) {
            if ($name === $phase) {
                return [$start / $total, $weight / $total];
            }

            $start += $weight;
        }

        return [0.0, 1.0];
    }

    private function humanize(string $phase): string
    {
        return match ($phase) {
            'users' => 'Importing users',
            'media' => 'Importing media',
            'taxonomies' => 'Importing categories and tags',
            'content' => 'Importing content',
            'comments' => 'Importing comments',
            'commerce' => 'Importing WooCommerce',
            'menus' => 'Importing menus',
            'widgets' => 'Importing widgets',
            'forms' => 'Importing forms',
            'seo' => 'Importing SEO metadata',
            'redirects' => 'Importing redirects',
            default => ucfirst($phase),
        };
    }
}
