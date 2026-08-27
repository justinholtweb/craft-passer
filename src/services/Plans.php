<?php

namespace justinholtweb\passer\services;

use Craft;
use craft\base\Component;
use craft\helpers\Json;
use justinholtweb\passer\models\Inventory;
use justinholtweb\passer\models\MigrationPlan;
use justinholtweb\passer\Plugin;
use justinholtweb\passer\records\PlanRecord;

/**
 * Saving, loading and listing migration plans.
 */
class Plans extends Component
{
    public function save(MigrationPlan $plan, ?Inventory $inventory = null, ?int $userId = null): PlanRecord
    {
        $record = $plan->id !== null ? PlanRecord::findOne($plan->id) : null;
        $record ??= new PlanRecord();

        $record->name = $plan->name;
        $record->sourceType = $plan->sourceType;
        $record->sourceConfig = Json::encode(
            Plugin::getInstance()->sources->encryptSecrets($plan->sourceType, $plan->sourceConfig)
        );
        $record->config = Json::encode($plan->toConfig());

        if ($inventory !== null) {
            $record->inventory = Json::encode($inventory->toArray());
        }

        $record->userId ??= $userId ?? Craft::$app->getUser()->getId();
        $record->save(false);

        $plan->id = $record->id;

        return $record;
    }

    public function find(int $id): ?MigrationPlan
    {
        $record = PlanRecord::findOne($id);

        return $record !== null ? $this->fromRecord($record) : null;
    }

    public function fromRecord(PlanRecord $record): MigrationPlan
    {
        $config = $this->decode($record->config);
        $plan = MigrationPlan::fromConfig($config);

        $plan->id = $record->id;
        $plan->name = $record->name;
        $plan->sourceType = $record->sourceType;
        $plan->sourceConfig = Plugin::getInstance()->sources->decryptSecrets(
            $record->sourceType,
            $this->decode($record->sourceConfig)
        );

        return $plan;
    }

    public function inventoryFor(int $planId): ?Inventory
    {
        $record = PlanRecord::findOne($planId);

        if ($record === null || $record->inventory === null) {
            return null;
        }

        $data = $this->decode($record->inventory);

        if ($data === []) {
            return null;
        }

        $inventory = new Inventory();

        foreach ($data as $key => $value) {
            if ($key === 'plugins') {
                foreach ((array)$value as $row) {
                    $inventory->plugins[] = new \justinholtweb\passer\models\DetectedPlugin(is_array($row) ? $row : []);
                }

                continue;
            }

            if ($inventory->canSetProperty($key)) {
                $inventory->$key = $value;
            }
        }

        return $inventory;
    }

    /**
     * @return PlanRecord[]
     */
    public function all(): array
    {
        return PlanRecord::find()->orderBy(['dateUpdated' => SORT_DESC])->all();
    }

    public function delete(int $id): bool
    {
        $record = PlanRecord::findOne($id);

        return $record !== null && $record->delete() !== false;
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
}
