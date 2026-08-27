<?php

namespace justinholtweb\passer\controllers;

use yii\web\Response;

/**
 * The saved-plans list.
 */
class PlansController extends BaseController
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
        return $this->renderTemplate('passer/plans/index', [
            'plans' => $this->plugin()->plans->all(),
            'sourceTypes' => $this->plugin()->sources->types(),
        ]);
    }

    public function actionDelete(): Response
    {
        $this->requirePostRequest();

        $id = (int)$this->request->getRequiredBodyParam('id');

        if ($this->plugin()->plans->delete($id)) {
            $this->setSuccessFlash('Plan deleted. Its runs and the ID map are kept.');
        } else {
            $this->setFailFlash('Could not delete that plan.');
        }

        return $this->redirect('passer/plans');
    }
}
