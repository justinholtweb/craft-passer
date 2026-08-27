<?php

namespace justinholtweb\passer\records;

use craft\db\ActiveRecord;

/**
 * A saved, re-runnable mapping of a WordPress site onto this Craft install.
 *
 * @property int $id
 * @property string $name
 * @property string $sourceType
 * @property array|null $sourceConfig
 * @property array|null $config
 * @property array|null $inventory
 * @property int|null $userId
 */
class PlanRecord extends ActiveRecord
{
    public const TABLE = '{{%passer_plans}}';

    public static function tableName(): string
    {
        return self::TABLE;
    }
}
