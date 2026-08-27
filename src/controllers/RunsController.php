<?php

namespace justinholtweb\passer\controllers;

use Craft;
use craft\db\Query;
use craft\helpers\Json;
use justinholtweb\passer\Plugin;
use justinholtweb\passer\queue\RunJob;
use justinholtweb\passer\records\LogRecord;
use justinholtweb\passer\records\RunRecord;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Watching, resuming and reading migrations.
 */
class RunsController extends BaseController
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requireMigratePermission();

        return true;
    }

    public function actionIndex(): Response
    {
        return $this->renderTemplate('passer/runs/index', [
            'runs' => RunRecord::find()->orderBy(['id' => SORT_DESC])->limit(100)->all(),
        ]);
    }

    public function actionDetail(int $runId): Response
    {
        $run = RunRecord::findOne($runId);

        if ($run === null) {
            throw new NotFoundHttpException('Run not found.');
        }

        $plan = $run->planId !== null ? $this->plugin()->plans->find($run->planId) : null;
        $report = $plan !== null ? $this->plugin()->runner->buildReport($run, $plan) : null;

        return $this->renderTemplate('passer/runs/detail', [
            'run' => $run,
            'plan' => $plan,
            'report' => $report,
            'counts' => $this->levelCounts($runId),
        ]);
    }

    /**
     * The per-record log, paged and filterable — the run report is a summary, this is the detail.
     */
    public function actionLog(int $runId): Response
    {
        $run = RunRecord::findOne($runId);

        if ($run === null) {
            throw new NotFoundHttpException('Run not found.');
        }

        $level = $this->request->getParam('level');
        $phase = $this->request->getParam('phase');
        $page = max(1, (int)$this->request->getParam('page', 1));
        $perPage = 100;

        $query = (new Query())
            ->from([LogRecord::TABLE])
            ->where(['runId' => $runId])
            ->orderBy(['id' => SORT_ASC]);

        if (is_string($level) && $level !== '') {
            $query->andWhere(['level' => $level]);
        }

        if (is_string($phase) && $phase !== '') {
            $query->andWhere(['phase' => $phase]);
        }

        $total = (int)(clone $query)->count();

        $rows = $query->offset(($page - 1) * $perPage)->limit($perPage)->all();

        if ($this->request->getAcceptsJson()) {
            return $this->asJson(['rows' => $rows, 'total' => $total, 'page' => $page]);
        }

        return $this->renderTemplate('passer/runs/log', [
            'run' => $run,
            'rows' => $rows,
            'total' => $total,
            'page' => $page,
            'perPage' => $perPage,
            'level' => $level,
            'phase' => $phase,
            'phases' => $this->phasesIn($runId),
        ]);
    }

    /**
     * Live status, for the run screen to poll.
     */
    public function actionStatus(int $runId): Response
    {
        $this->requireAcceptsJson();

        $run = RunRecord::findOne($runId);

        if ($run === null) {
            throw new NotFoundHttpException('Run not found.');
        }

        $totals = Json::decodeIfJson((string)$run->totals);

        return $this->asJson([
            'status' => $run->status,
            'phase' => $run->phase,
            'totals' => is_array($totals) ? $totals : [],
            'finished' => in_array($run->status, [
                RunRecord::STATUS_COMPLETED,
                RunRecord::STATUS_FAILED,
                RunRecord::STATUS_CANCELLED,
            ], true),
        ]);
    }

    /**
     * Continue a run that was paused, cancelled or interrupted.
     */
    public function actionResume(int $runId): Response
    {
        $this->requirePostRequest();

        $run = RunRecord::findOne($runId);

        if ($run === null) {
            throw new NotFoundHttpException('Run not found.');
        }

        $run->status = RunRecord::STATUS_PENDING;
        $run->save(false);

        Craft::$app->getQueue()->push(new RunJob(['runId' => $runId]));

        $this->setSuccessFlash('Run resumed.');

        return $this->redirect('passer/runs/' . $runId);
    }

    public function actionCancel(int $runId): Response
    {
        $this->requirePostRequest();

        $run = RunRecord::findOne($runId);

        if ($run === null) {
            throw new NotFoundHttpException('Run not found.');
        }

        $this->plugin()->runner->cancel($run);

        $this->setSuccessFlash('Run cancelled. Its checkpoint is kept, so it can be resumed.');

        return $this->redirect('passer/runs/' . $runId);
    }

    /**
     * Throw away a run's progress so the next attempt starts from the beginning.
     */
    public function actionReset(int $runId): Response
    {
        $this->requirePostRequest();

        $run = RunRecord::findOne($runId);

        if ($run === null) {
            throw new NotFoundHttpException('Run not found.');
        }

        $this->plugin()->runner->reset($run);

        $this->setSuccessFlash('Run reset. The ID map is untouched, so a fresh run will still update rather than duplicate.');

        return $this->redirect('passer/runs/' . $runId);
    }

    public function actionDownloadReport(int $runId): Response
    {
        $run = RunRecord::findOne($runId);

        if ($run === null) {
            throw new NotFoundHttpException('Run not found.');
        }

        $plan = $run->planId !== null ? $this->plugin()->plans->find($run->planId) : null;

        if ($plan === null) {
            throw new NotFoundHttpException('The plan for this run no longer exists.');
        }

        $report = $this->plugin()->runner->buildReport($run, $plan);

        return $this->response->sendContentAsFile(
            $report->toText(),
            sprintf('passer-run-%d.txt', $runId),
            ['mimeType' => 'text/plain']
        );
    }

    /**
     * @return array<string, int>
     */
    private function levelCounts(int $runId): array
    {
        $rows = (new Query())
            ->select(['level', 'count(*) as c'])
            ->from([LogRecord::TABLE])
            ->where(['runId' => $runId])
            ->groupBy(['level'])
            ->all();

        $out = [];

        foreach ($rows as $row) {
            $out[(string)$row['level']] = (int)$row['c'];
        }

        return $out;
    }

    /**
     * @return string[]
     */
    private function phasesIn(int $runId): array
    {
        return (new Query())
            ->select(['phase'])
            ->from([LogRecord::TABLE])
            ->where(['runId' => $runId])
            ->andWhere(['not', ['phase' => null]])
            ->distinct()
            ->column();
    }
}
