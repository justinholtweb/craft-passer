<?php

namespace justinholtweb\passer\queue;

use Craft;
use craft\queue\BaseJob;
use justinholtweb\passer\models\MigrationPlan;
use justinholtweb\passer\Plugin;
use justinholtweb\passer\progress\QueueProgress;
use justinholtweb\passer\records\RunRecord;

/**
 * Runs a migration in the queue.
 *
 * A phase is one job, not the whole migration, so a large site is a chain of jobs rather than one
 * that runs for four hours and dies to a `max_execution_time` nobody can change on shared
 * hosting. Each job queues the next when it finishes, and the run's checkpoint carries the state
 * between them.
 */
class RunJob extends BaseJob
{
    public int $runId;

    /** @var string|null The single phase to run. Null runs the next unfinished one. */
    public ?string $phase = null;

    public function execute($queue): void
    {
        $run = RunRecord::findOne($this->runId);

        if ($run === null || $run->status === RunRecord::STATUS_CANCELLED) {
            return;
        }

        $plugin = Plugin::getInstance();
        $plan = $this->planFor($run);

        if ($plan === null) {
            $run->status = RunRecord::STATUS_FAILED;
            $run->save(false);

            $plugin->runner->log($run->id, 'error', 'The plan for this run could not be loaded.');

            return;
        }

        $sources = $plugin->sources;
        $source = $sources->create($run->sourceType, $sources->decodeConfig($run->sourceConfig));

        $remaining = $this->remainingPhases($run, $plan);

        if ($remaining === []) {
            return;
        }

        $phase = $this->phase ?? $remaining[0];

        $progress = new QueueProgress($queue, $plugin->runner->phaseWeights($plan, $source));

        $plugin->runner->run($run, $plan, $progress, [$phase]);

        $run->refresh();

        if ($run->status === RunRecord::STATUS_FAILED || $run->status === RunRecord::STATUS_CANCELLED) {
            return;
        }

        $next = $this->remainingPhases($run, $plan);

        if ($next !== []) {
            // Still work to do: hand the rest to a fresh job with a fresh execution budget.
            $run->status = RunRecord::STATUS_RUNNING;
            $run->save(false);

            Craft::$app->getQueue()->push(new self([
                'runId' => $this->runId,
                'phase' => $next[0],
            ]));
        } else {
            $run->status = RunRecord::STATUS_COMPLETED;
            $run->save(false);
        }
    }

    /**
     * @return string[]
     */
    private function remainingPhases(RunRecord $run, MigrationPlan $plan): array
    {
        $checkpoints = \craft\helpers\Json::decodeIfJson((string)$run->checkpoint);
        $checkpoints = is_array($checkpoints) ? $checkpoints : [];

        return array_values(array_filter(
            $plan->phases(),
            static fn(string $phase) => ($checkpoints[$phase]['_done'] ?? false) !== true
        ));
    }

    private function planFor(RunRecord $run): ?MigrationPlan
    {
        $plugin = Plugin::getInstance();

        if ($run->planId !== null) {
            $plan = $plugin->plans->find($run->planId);

            if ($plan !== null) {
                return $plan;
            }
        }

        return null;
    }

    protected function defaultDescription(): string
    {
        $run = RunRecord::findOne($this->runId);

        return $run !== null
            ? Craft::t('passer', 'Migrating {name} from WordPress', ['name' => $run->name])
            : Craft::t('passer', 'WordPress migration');
    }
}
