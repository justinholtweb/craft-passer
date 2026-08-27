<?php

namespace justinholtweb\passer\controllers;

use justinholtweb\passer\Plugin;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Step two: show what the source contains before anything is imported.
 */
class AnalyzeController extends BaseController
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requireMigratePermission();

        return true;
    }

    public function actionIndex(int $planId): Response
    {
        $plan = $this->plugin()->plans->find($planId);
        $inventory = $this->plugin()->plans->inventoryFor($planId);

        if ($plan === null || $inventory === null) {
            throw new NotFoundHttpException('Plan not found.');
        }

        return $this->renderTemplate('passer/analyze/index', [
            'planId' => $planId,
            'plan' => $plan,
            'inventory' => $inventory,
        ]);
    }

    /**
     * Re-scan the source, in case the WordPress site has changed since the plan was made.
     */
    public function actionRescan(int $planId): Response
    {
        $this->requirePostRequest();

        $plan = $this->plugin()->plans->find($planId);

        if ($plan === null) {
            throw new NotFoundHttpException('Plan not found.');
        }

        try {
            $source = $this->plugin()->sources->create($plan->sourceType, $plan->sourceConfig);
            $inventory = $this->plugin()->analyzer->analyze($source);
            $source->close();

            $this->plugin()->plans->save($plan, $inventory);

            $this->setSuccessFlash('Source re-scanned.');
        } catch (\Throwable $e) {
            $this->setFailFlash($e->getMessage());
        }

        return $this->redirect('passer/analyze/' . $planId);
    }
}
