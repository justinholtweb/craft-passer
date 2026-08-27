<?php

namespace justinholtweb\passer\services;

use Craft;
use craft\base\Component;
use craft\db\Query;
use craft\helpers\Db;
use craft\helpers\Json;
use craft\helpers\StringHelper;
use justinholtweb\passer\importers\CommentImporter;
use justinholtweb\passer\importers\CommerceImporter;
use justinholtweb\passer\importers\ContentImporter;
use justinholtweb\passer\importers\FormImporter;
use justinholtweb\passer\importers\ImporterInterface;
use justinholtweb\passer\importers\MediaImporter;
use justinholtweb\passer\importers\MenuImporter;
use justinholtweb\passer\importers\RedirectImporter;
use justinholtweb\passer\importers\RunContext;
use justinholtweb\passer\importers\SeoImporter;
use justinholtweb\passer\importers\TaxonomyImporter;
use justinholtweb\passer\importers\UserImporter;
use justinholtweb\passer\importers\WidgetImporter;
use justinholtweb\passer\models\MigrationPlan;
use justinholtweb\passer\models\RunReport;
use justinholtweb\passer\progress\NullProgress;
use justinholtweb\passer\progress\ProgressInterface;
use justinholtweb\passer\Plugin;
use justinholtweb\passer\records\LogRecord;
use justinholtweb\passer\records\RunRecord;
use justinholtweb\passer\sources\SourceInterface;

/**
 * Executes a migration plan, one phase at a time, and can pick up where it left off.
 *
 * Resumability is the point. A migration of a real site takes hours, and the things that
 * interrupt it — a PHP timeout, a queue worker restart, a source that stops responding, someone
 * pressing stop — are all normal. Each phase writes a checkpoint when it finishes a batch, and
 * a run that is started again continues from there rather than doing the first four hours over.
 */
class Runner extends Component
{
    /**
     * @return array<string, class-string<ImporterInterface>>
     */
    public function importers(): array
    {
        return [
            'users' => UserImporter::class,
            'media' => MediaImporter::class,
            'taxonomies' => TaxonomyImporter::class,
            'content' => ContentImporter::class,
            'comments' => CommentImporter::class,
            'commerce' => CommerceImporter::class,
            'menus' => MenuImporter::class,
            'widgets' => WidgetImporter::class,
            'forms' => FormImporter::class,
            'seo' => SeoImporter::class,
            'redirects' => RedirectImporter::class,
        ];
    }

    /**
     * Create a run record for a plan, ready to be executed.
     */
    public function createRun(
        MigrationPlan $plan,
        bool $dryRun = false,
        ?int $planId = null,
        ?int $userId = null,
    ): RunRecord {
        $run = new RunRecord();
        $run->planId = $planId;
        $run->name = $plan->name;
        $run->sourceType = $plan->sourceType;
        $run->sourceConfig = Json::encode(
            Plugin::getInstance()->sources->encryptSecrets($plan->sourceType, $plan->sourceConfig)
        );
        $run->status = RunRecord::STATUS_PENDING;
        $run->dryRun = $dryRun;
        $run->userId = $userId ?? Craft::$app->getUser()->getId();
        $run->totals = Json::encode([]);
        $run->checkpoint = Json::encode([]);
        $run->save(false);

        return $run;
    }

    /**
     * Execute a run.
     *
     * @param string[]|null $onlyPhases Restrict to these phases; null runs the plan's own list.
     */
    public function run(
        RunRecord $run,
        MigrationPlan $plan,
        ?ProgressInterface $progress = null,
        ?array $onlyPhases = null,
        ?int $limit = null,
    ): RunReport {
        $sources = Plugin::getInstance()->sources;
        $source = $sources->create($run->sourceType, $sources->decodeConfig($run->sourceConfig));

        $run->status = RunRecord::STATUS_RUNNING;
        $run->dateStarted ??= Db::prepareDateForDb(new \DateTime());
        $run->save(false);

        $context = $this->buildContext($run, $plan, $source, $progress ?? new NullProgress(), $limit);

        $checkpoints = $this->decode($run->checkpoint);
        $counts = $this->decode($run->totals);
        $context->counts = $counts;

        $phases = $onlyPhases ?? $plan->phases();
        $phases = $this->filterBySource($phases, $source, $context);

        // Assigned in the finally block, which always runs — declared here so the value is
        // unambiguously defined for the notify() call below.
        $report = new RunReport();

        try {
            $source->connect();

            foreach ($phases as $phase) {
                $class = $this->importers()[$phase] ?? null;

                if ($class === null) {
                    continue;
                }

                // A phase already marked done is not re-run. Its own checkpoint governs whether
                // it is partially done and needs resuming.
                if (($checkpoints[$phase]['_done'] ?? false) === true && $onlyPhases === null) {
                    continue;
                }

                $run->phase = $phase;
                $run->save(false);

                /** @var ImporterInterface $importer */
                $importer = new $class();

                $resumeFrom = $checkpoints[$phase] ?? [];
                unset($resumeFrom['_done']);

                $checkpoint = $importer->import($context, $resumeFrom);

                $checkpoints[$phase] = $checkpoint + ['_done' => true];
                $run->checkpoint = Json::encode($checkpoints);
                $run->totals = Json::encode($context->counts);
                $run->save(false);
            }

            $run->status = RunRecord::STATUS_COMPLETED;
        } catch (\Throwable $e) {
            $run->status = RunRecord::STATUS_FAILED;

            $this->log($run->id, LogRecord::LEVEL_ERROR, $e->getMessage(), [
                'phase' => $run->phase,
                'exception' => $e::class,
            ]);

            Craft::error(
                sprintf("Passer run %d failed in phase %s: %s\n%s", $run->id, (string)$run->phase, $e->getMessage(), $e->getTraceAsString()),
                'passer'
            );
        } finally {
            $source->close();

            $run->totals = Json::encode($context->counts);
            $run->checkpoint = Json::encode($checkpoints);
            $run->dateFinished = Db::prepareDateForDb(new \DateTime());

            $report = $this->buildReport($run, $plan, $source);
            $run->report = Json::encode($report->toArray());
            $run->save(false);
        }

        $this->notify($report, $run);

        return $report;
    }

    /**
     * Build the context an importer runs against.
     */
    public function buildContext(
        RunRecord $run,
        MigrationPlan $plan,
        SourceInterface $source,
        ProgressInterface $progress,
        ?int $limit = null,
    ): RunContext {
        $settings = Plugin::getInstance()->getSettings();
        $map = Plugin::getInstance()->map;
        $map->setSourceHash($source->sourceHash());

        $context = new RunContext();
        $context->source = $source;
        $context->plan = $plan;
        $context->map = $map;
        $context->progress = $progress;
        $context->runId = $run->id;
        $context->dryRun = (bool)$run->dryRun;
        $context->updateExisting = $plan->updateExisting;
        $context->continueOnError = $settings->continueOnError;
        $context->batchSize = $settings->batchSize;
        $context->limit = $limit;

        $runId = $run->id;
        $context->logger = function (string $level, string $message, array $extra) use ($runId): void {
            $this->log($runId, $level, $message, $extra);
        };

        return $context;
    }

    /**
     * Drop phases the chosen source cannot supply, saying so rather than importing nothing.
     *
     * @param string[] $phases
     * @return string[]
     */
    private function filterBySource(array $phases, SourceInterface $source, RunContext $context): array
    {
        $capabilities = $source->capabilities();
        $kept = [];

        foreach ($phases as $phase) {
            if ($capabilities->supports($phase)) {
                $kept[] = $phase;
                continue;
            }

            $context->log(LogRecord::LEVEL_WARNING, sprintf(
                'The %s phase was skipped: a %s source cannot read %s. %s',
                $phase,
                $source::displayName(),
                $phase,
                $this->alternativeFor($phase)
            ), ['phase' => $phase]);
        }

        return $kept;
    }

    private function alternativeFor(string $phase): string
    {
        return match ($phase) {
            'commerce', 'forms', 'redirects', 'widgets' =>
                'Migrate from the database or over wp-cli to bring this across.',
            'menus' =>
                'Migrate from the database, a WXR export or wp-cli to bring menus across.',
            default => 'Choose a source with fuller access to bring this across.',
        };
    }

    // -----------------------------------------------------------------------------------------
    // Logging and reporting
    // -----------------------------------------------------------------------------------------

    /**
     * @param array<string, mixed> $extra
     */
    public function log(?int $runId, string $level, string $message, array $extra = []): void
    {
        if ($runId === null) {
            return;
        }

        try {
            Db::insert(LogRecord::TABLE, [
                'runId' => $runId,
                'level' => $level,
                'phase' => $extra['phase'] ?? null,
                'sourceKey' => $extra['sourceKey'] ?? null,
                'sourceId' => isset($extra['sourceId']) ? (string)$extra['sourceId'] : null,
                'sourceTitle' => isset($extra['sourceTitle']) ? mb_substr((string)$extra['sourceTitle'], 0, 255) : null,
                // The message can be an entire SQL statement plus a stack trace when a database
                // error is what is being logged. Truncating keeps one bad record from failing
                // the insert that is trying to describe it.
                'message' => mb_substr($message, 0, 60000),
                'context' => Json::encode(array_diff_key($extra, array_flip(['phase', 'sourceKey', 'sourceId', 'sourceTitle']))),
                'dateCreated' => Db::prepareDateForDb(new \DateTime()),
                'dateUpdated' => Db::prepareDateForDb(new \DateTime()),
                'uid' => StringHelper::UUID(),
            ]);
        } catch (\Throwable $e) {
            // Writing the log must never be what fails the run. When the database itself is what
            // went wrong — a connection dropped mid-migration is the usual case — an unguarded
            // insert here replaces the real error with a confusing one about logging.
            Craft::error('Passer could not write to its run log: ' . $e->getMessage(), 'passer');
        }

        if ($level === LogRecord::LEVEL_ERROR) {
            Craft::error($message, 'passer');
        }
    }

    public function buildReport(RunRecord $run, MigrationPlan $plan, ?SourceInterface $source = null): RunReport
    {
        $report = new RunReport();
        $report->runId = $run->id;
        $report->planName = $run->name;
        $report->sourceType = $run->sourceType;
        $report->dryRun = (bool)$run->dryRun;
        $report->siteUrl = $source?->siteUrl();

        $report->started = $this->toDate($run->dateStarted);
        $report->finished = $this->toDate($run->dateFinished);

        // Counts are stored flat as `phase.key`; the report wants them grouped.
        foreach ($this->decode($run->totals) as $key => $value) {
            if (!str_contains($key, '.')) {
                continue;
            }

            [$phase, $counter] = explode('.', $key, 2);
            $report->phases[$phase][$counter] = (int)$value;
        }

        foreach ($report->phases as $phase => $counts) {
            $report->phases[$phase] = $counts + ['created' => 0, 'updated' => 0, 'skipped' => 0, 'failed' => 0];
        }

        $rows = (new Query())
            ->select(['level', 'phase', 'message', 'sourceTitle'])
            ->from([LogRecord::TABLE])
            ->where(['runId' => $run->id])
            ->andWhere(['not', ['level' => LogRecord::LEVEL_INFO]])
            ->orderBy(['id' => SORT_ASC])
            // A run over a large site can log tens of thousands of per-record failures. The
            // report shows the first thousand; the full log stays in the database.
            ->limit(1000)
            ->all();

        foreach ($rows as $row) {
            $report->messages[] = [
                'level' => (string)$row['level'],
                'phase' => $row['phase'] !== null ? (string)$row['phase'] : null,
                'message' => (string)$row['message'],
                'sourceTitle' => $row['sourceTitle'] !== null ? (string)$row['sourceTitle'] : null,
            ];
        }

        return $report;
    }

    private function notify(RunReport $report, RunRecord $run): void
    {
        $email = Plugin::getInstance()->getSettings()->reportEmail;

        if ($email === null || $email === '') {
            return;
        }

        $email = \craft\helpers\App::parseEnv($email);

        if (!is_string($email) || $email === '') {
            return;
        }

        try {
            Craft::$app->getMailer()
                ->compose()
                ->setTo($email)
                ->setSubject(sprintf('Passer: %s', $report->summary()))
                ->setTextBody($report->toText())
                ->send();
        } catch (\Throwable $e) {
            Craft::warning('Could not send the Passer run report: ' . $e->getMessage(), 'passer');
        }
    }

    // -----------------------------------------------------------------------------------------
    // Run management
    // -----------------------------------------------------------------------------------------

    public function cancel(RunRecord $run): void
    {
        $run->status = RunRecord::STATUS_CANCELLED;
        $run->dateFinished = Db::prepareDateForDb(new \DateTime());
        $run->save(false);
    }

    /**
     * Reset a run so the next attempt starts over rather than resuming.
     */
    public function reset(RunRecord $run): void
    {
        $run->checkpoint = Json::encode([]);
        $run->totals = Json::encode([]);
        $run->status = RunRecord::STATUS_PENDING;
        $run->phase = null;
        $run->dateStarted = null;
        $run->dateFinished = null;
        $run->save(false);

        Db::delete(LogRecord::TABLE, ['runId' => $run->id]);
    }

    private function toDate(mixed $value): ?\DateTime
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof \DateTime) {
            return $value;
        }

        try {
            return new \DateTime((string)$value);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (is_string($value) && $value !== '') {
            $decoded = Json::decodeIfJson($value);

            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return [];
    }

    /**
     * Estimated record counts per phase, used to weight the progress bar.
     *
     * @return array<string, int>
     */
    public function phaseWeights(MigrationPlan $plan, SourceInterface $source): array
    {
        $weights = [];
        $context = new RunContext();
        $context->source = $source;
        $context->plan = $plan;
        $context->map = Plugin::getInstance()->map;
        $context->progress = new NullProgress();

        foreach ($plan->phases() as $phase) {
            $class = $this->importers()[$phase] ?? null;

            if ($class === null) {
                continue;
            }

            /** @var ImporterInterface $importer */
            $importer = new $class();

            // Media is weighted far above its record count because each record is an HTTP
            // download, and a bar that treats one image as one post lurches badly.
            $estimate = $importer->estimate($context) ?? 100;
            $weights[$phase] = $phase === 'media' ? $estimate * 5 : $estimate;
        }

        return $weights;
    }
}
