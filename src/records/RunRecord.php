<?php

namespace justinholtweb\passer\records;

use craft\db\ActiveRecord;

/**
 * One execution of a migration plan.
 *
 * @property int $id
 * @property int|null $planId
 * @property string $name
 * @property string $sourceType
 * @property array|null $sourceConfig
 * @property string $status
 * @property string|null $phase
 * @property array|null $totals
 * @property array|null $checkpoint
 * @property string|null $report
 * @property bool $dryRun
 * @property int|null $userId
 * @property \DateTime|null $dateStarted
 * @property \DateTime|null $dateFinished
 */
class RunRecord extends ActiveRecord
{
    public const TABLE = '{{%passer_runs}}';

    public const STATUS_PENDING = 'pending';
    public const STATUS_RUNNING = 'running';
    public const STATUS_PAUSED = 'paused';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CANCELLED = 'cancelled';

    public static function tableName(): string
    {
        return self::TABLE;
    }
}
