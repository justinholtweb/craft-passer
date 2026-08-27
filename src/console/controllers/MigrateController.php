<?php

namespace justinholtweb\passer\console\controllers;

use Craft;
use craft\console\Controller;
use craft\helpers\Console;
use justinholtweb\passer\models\MigrationPlan;
use justinholtweb\passer\Plugin;
use justinholtweb\passer\progress\ConsoleProgress;
use justinholtweb\passer\records\RunRecord;
use yii\console\ExitCode;

/**
 * Driving a migration from the command line.
 *
 * Everything the control panel can do is here too, because a migration of a large site is often
 * better run from a terminal — no request timeout, no browser tab to keep open, and the output
 * is something you can pipe into a file and read afterwards.
 */
class MigrateController extends Controller
{
    public $defaultAction = 'run';

    /** @var int|null The plan to work with. */
    public ?int $plan = null;

    /** @var string|null Comma-separated phases to run. Default runs the plan's own list. */
    public ?string $phases = null;

    /** @var bool Import nothing; report what would happen. */
    public bool $dryRun = false;

    /** @var int|null Stop after this many records per phase. */
    public ?int $limit = null;

    /** @var bool Start over rather than resuming from the last checkpoint. */
    public bool $fresh = false;

    /** @var bool Create the sections, fields and groups the plan needs first. */
    public bool $provision = false;

    /** @var bool Print more per record. */
    public bool $verbose = false;

    public function options($actionID): array
    {
        return match ($actionID) {
            'run' => ['plan', 'phases', 'dryRun', 'limit', 'fresh', 'provision', 'verbose'],
            'provision' => ['plan', 'dryRun'],
            'analyze' => ['plan'],
            'resume' => ['verbose'],
            default => [],
        };
    }

    public function optionAliases(): array
    {
        return ['p' => 'plan', 'v' => 'verbose', 'd' => 'dryRun'];
    }

    /**
     * List the saved plans.
     */
    public function actionPlans(): int
    {
        $plans = Plugin::getInstance()->plans->all();

        if ($plans === []) {
            $this->stdout("No plans yet. Create one in the control panel at Passer → Migrate.\n");

            return ExitCode::OK;
        }

        $this->stdout("\n");

        foreach ($plans as $plan) {
            $this->stdout(sprintf(
                "  %-4s %-40s %-10s %s\n",
                $plan->id,
                mb_substr($plan->name, 0, 40),
                $plan->sourceType,
                $plan->dateUpdated
            ));
        }

        $this->stdout("\n");

        return ExitCode::OK;
    }

    /**
     * Scan the source and print what is in it.
     */
    public function actionAnalyze(): int
    {
        $plan = $this->resolvePlan();

        if ($plan === null) {
            return ExitCode::USAGE;
        }

        $plugin = Plugin::getInstance();
        $source = $plugin->sources->create($plan->sourceType, $plan->sourceConfig);

        $this->stdout("Connecting… ");
        $this->stdout($source->testConnection() . "\n\n", Console::FG_GREEN);

        $inventory = $plugin->analyzer->analyze($source);

        $this->stdout("Content\n", Console::BOLD);

        foreach ($inventory->contentPostTypes() as $type => $count) {
            $this->stdout(sprintf("  %-30s %s\n", $type, number_format($count)));
        }

        $this->stdout("\nTaxonomies\n", Console::BOLD);

        foreach ($inventory->contentTaxonomies() as $taxonomy => $count) {
            $this->stdout(sprintf("  %-30s %s\n", $taxonomy, number_format($count)));
        }

        $this->stdout("\nEverything else\n", Console::BOLD);

        foreach ([
            'Users' => $inventory->users,
            'Media' => $inventory->attachments,
            'Comments' => $inventory->comments,
            'Menus' => $inventory->menus,
            'Widgets' => $inventory->widgets,
            'Products' => $inventory->products,
            'Orders' => $inventory->orders,
            'Forms' => $inventory->forms,
            'Redirects' => $inventory->redirects,
        ] as $label => $count) {
            $this->stdout(sprintf("  %-30s %s\n", $label, $count > 0 ? number_format($count) : '—'));
        }

        if ($inventory->plugins !== []) {
            $this->stdout("\nWordPress plugins found\n", Console::BOLD);

            foreach ($inventory->plugins as $detected) {
                $this->stdout(sprintf(
                    "  %s %-28s %s\n",
                    $detected->supported ? '✓' : '·',
                    $detected->name,
                    $detected->handling ?? ''
                ), $detected->supported ? Console::FG_GREEN : Console::FG_YELLOW);
            }
        }

        if ($inventory->warnings !== []) {
            $this->stdout("\nWorth knowing\n", Console::BOLD);

            foreach ($inventory->warnings as $warning) {
                $this->stdout('  ' . wordwrap($warning, 76, "\n  ") . "\n", Console::FG_YELLOW);
            }
        }

        $source->close();
        $this->stdout("\n");

        return ExitCode::OK;
    }

    /**
     * Create the Craft content model a plan needs.
     */
    public function actionProvision(): int
    {
        $plan = $this->resolvePlan();

        if ($plan === null) {
            return ExitCode::USAGE;
        }

        $provisioner = Plugin::getInstance()->provisioner;
        $result = $this->dryRun ? $provisioner->dryRun($plan) : $provisioner->apply($plan);

        $this->stdout("\n");

        foreach ($result->existing as $row) {
            $this->stdout(sprintf("  = %-12s %s\n", $row['type'], $row['handle']), Console::FG_GREY);
        }

        foreach ($result->created as $row) {
            $this->stdout(sprintf(
                "  %s %-12s %-24s %s\n",
                $this->dryRun ? '?' : '+',
                $row['type'],
                $row['handle'],
                $row['detail']
            ), Console::FG_GREEN);
        }

        foreach ($result->errors as $error) {
            $this->stderr('  ! ' . $error . "\n", Console::FG_RED);
        }

        $this->stdout(sprintf(
            "\n%s %d things; %d already existed.\n\n",
            $this->dryRun ? 'Would create' : 'Created',
            count($result->created),
            count($result->existing)
        ));

        return $result->isOk() ? ExitCode::OK : ExitCode::UNSPECIFIED_ERROR;
    }

    /**
     * Run a migration.
     */
    public function actionRun(): int
    {
        $plan = $this->resolvePlan();

        if ($plan === null) {
            return ExitCode::USAGE;
        }

        $plugin = Plugin::getInstance();

        if ($this->provision) {
            $result = $plugin->provisioner->apply($plan);

            foreach ($result->errors as $error) {
                $this->stderr('  ! ' . $error . "\n", Console::FG_RED);
            }

            if (!$result->isOk()) {
                return ExitCode::UNSPECIFIED_ERROR;
            }

            $this->stdout(sprintf("Provisioned %d things.\n", count($result->created)), Console::FG_GREEN);
        }

        $phases = $this->phases !== null
            ? array_values(array_filter(array_map('trim', explode(',', $this->phases))))
            : null;

        $run = $plugin->runner->createRun($plan, $this->dryRun, $plan->id);

        if ($this->fresh) {
            $plugin->runner->reset($run);
        }

        $this->stdout(sprintf(
            "\n%s \"%s\"%s\n",
            $this->dryRun ? 'Dry run of' : 'Running',
            $plan->name,
            $phases !== null ? ' (' . implode(', ', $phases) . ')' : ''
        ), Console::BOLD);

        $report = $plugin->runner->run($run, $plan, new ConsoleProgress($this->verbose), $phases, $this->limit);

        $this->stdout("\n" . $report->toText() . "\n");

        return $report->hasProblems() ? ExitCode::UNSPECIFIED_ERROR : ExitCode::OK;
    }

    /**
     * Continue a run that stopped part-way.
     */
    public function actionResume(int $runId): int
    {
        $run = RunRecord::findOne($runId);

        if ($run === null) {
            $this->stderr("No run with ID $runId.\n", Console::FG_RED);

            return ExitCode::DATAERR;
        }

        if ($run->planId === null) {
            $this->stderr("That run has no plan attached and cannot be resumed.\n", Console::FG_RED);

            return ExitCode::DATAERR;
        }

        $plugin = Plugin::getInstance();
        $plan = $plugin->plans->find($run->planId);

        if ($plan === null) {
            $this->stderr("The plan for that run has been deleted.\n", Console::FG_RED);

            return ExitCode::DATAERR;
        }

        $report = $plugin->runner->run($run, $plan, new ConsoleProgress($this->verbose));

        $this->stdout("\n" . $report->toText() . "\n");

        return $report->hasProblems() ? ExitCode::UNSPECIFIED_ERROR : ExitCode::OK;
    }

    /**
     * Print a past run's report.
     */
    public function actionReport(int $runId): int
    {
        $run = RunRecord::findOne($runId);

        if ($run === null || $run->planId === null) {
            $this->stderr("No run with ID $runId, or its plan has been deleted.\n", Console::FG_RED);

            return ExitCode::DATAERR;
        }

        $plugin = Plugin::getInstance();
        $plan = $plugin->plans->find($run->planId);

        if ($plan === null) {
            $this->stderr("The plan for that run has been deleted.\n", Console::FG_RED);

            return ExitCode::DATAERR;
        }

        $this->stdout("\n" . $plugin->runner->buildReport($run, $plan)->toText() . "\n");

        return ExitCode::OK;
    }

    /**
     * Remove ID-map rows whose Craft element has been deleted.
     */
    public function actionPruneMap(string $sourceHash): int
    {
        $map = Plugin::getInstance()->map;
        $map->setSourceHash($sourceHash);
        $removed = $map->prune();

        $this->stdout(sprintf("Removed %d stale mapping%s.\n", $removed, $removed === 1 ? '' : 's'), Console::FG_GREEN);

        return ExitCode::OK;
    }

    private function resolvePlan(): ?MigrationPlan
    {
        $plugin = Plugin::getInstance();

        if ($this->plan !== null) {
            $plan = $plugin->plans->find($this->plan);

            if ($plan === null) {
                $this->stderr("No plan with ID {$this->plan}. Run `craft passer/migrate/plans` to list them.\n", Console::FG_RED);

                return null;
            }

            return $plan;
        }

        $plans = $plugin->plans->all();

        if ($plans === []) {
            $this->stderr("No plans exist. Create one in the control panel at Passer → Migrate.\n", Console::FG_RED);

            return null;
        }

        if (count($plans) === 1) {
            return $plugin->plans->fromRecord($plans[0]);
        }

        $this->stderr("Several plans exist; say which with --plan=<id>. Run `craft passer/migrate/plans` to list them.\n", Console::FG_RED);

        return null;
    }
}
