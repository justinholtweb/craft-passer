<?php

namespace justinholtweb\passer\records;

use craft\db\ActiveRecord;

/**
 * A per-record outcome within a run — the raw material of the run report.
 *
 * @property int $id
 * @property int $runId
 * @property string $level
 * @property string|null $phase
 * @property string|null $sourceKey
 * @property string|null $sourceId
 * @property string|null $sourceTitle
 * @property string $message
 * @property array|null $context
 */
class LogRecord extends ActiveRecord
{
    public const TABLE = '{{%passer_log}}';

    public const LEVEL_INFO = 'info';
    public const LEVEL_NOTICE = 'notice';
    public const LEVEL_WARNING = 'warning';
    public const LEVEL_ERROR = 'error';

    public static function tableName(): string
    {
        return self::TABLE;
    }
}
