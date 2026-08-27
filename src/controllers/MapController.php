<?php

namespace justinholtweb\passer\controllers;

use craft\db\Query;
use justinholtweb\passer\Plugin;
use justinholtweb\passer\records\MapRecord;
use yii\web\Response;

/**
 * The ID map: what became what, and the controls for clearing it.
 */
class MapController extends BaseController
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requirePermission(Plugin::PERMISSION_MANAGE_MAP);

        return true;
    }

    public function actionIndex(): Response
    {
        $sourceHash = (string)$this->request->getParam('sourceHash', '');
        $key = (string)$this->request->getParam('key', '');
        $page = max(1, (int)$this->request->getParam('page', 1));
        $perPage = 100;

        $query = (new Query())->from([MapRecord::TABLE])->orderBy(['id' => SORT_DESC]);

        if ($sourceHash !== '') {
            $query->andWhere(['sourceHash' => $sourceHash]);
        }

        if ($key !== '') {
            $query->andWhere(['sourceKey' => $key]);
        }

        $total = (int)(clone $query)->count();
        $rows = $query->offset(($page - 1) * $perPage)->limit($perPage)->all();

        return $this->renderTemplate('passer/map/index', [
            'rows' => $rows,
            'total' => $total,
            'page' => $page,
            'perPage' => $perPage,
            'sourceHash' => $sourceHash,
            'key' => $key,
            'sources' => $this->sourceSummary(),
            'keys' => $this->keySummary(),
        ]);
    }

    /**
     * Remove map rows whose Craft element has been deleted.
     */
    public function actionPrune(): Response
    {
        $this->requirePostRequest();

        $sourceHash = (string)$this->request->getRequiredBodyParam('sourceHash');

        $map = $this->plugin()->map;
        $map->setSourceHash($sourceHash);
        $removed = $map->prune();

        $this->setSuccessFlash(sprintf(
            '%d stale mapping%s removed. Those records will be recreated on the next run.',
            $removed,
            $removed === 1 ? '' : 's'
        ));

        return $this->redirect('passer/map');
    }

    /**
     * Forget everything about a source.
     */
    public function actionClear(): Response
    {
        $this->requirePostRequest();

        $sourceHash = (string)$this->request->getRequiredBodyParam('sourceHash');

        $map = $this->plugin()->map;
        $map->setSourceHash($sourceHash);
        $removed = $map->forget();

        $this->setSuccessFlash(sprintf(
            '%d mapping%s cleared. The next run from this source will create new content rather '
            . 'than updating what is already here.',
            $removed,
            $removed === 1 ? '' : 's'
        ));

        return $this->redirect('passer/map');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function sourceSummary(): array
    {
        return (new Query())
            ->select(['sourceHash', 'count(*) as total', 'max(dateUpdated) as lastUpdated'])
            ->from([MapRecord::TABLE])
            ->groupBy(['sourceHash'])
            ->orderBy(['total' => SORT_DESC])
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function keySummary(): array
    {
        return (new Query())
            ->select(['sourceKey', 'count(*) as total'])
            ->from([MapRecord::TABLE])
            ->groupBy(['sourceKey'])
            ->orderBy(['total' => SORT_DESC])
            ->all();
    }
}
