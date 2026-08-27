<?php

namespace justinholtweb\passer\records;

use craft\db\ActiveRecord;

/**
 * The ID map: one row per WordPress record that became something in Craft.
 *
 * This outlives the run that created it. It is what makes a second run an update rather than a
 * duplicate, and what lets a menu item imported in phase nine find the post imported in phase four.
 *
 * `sourceKey` namespaces the ID, because WordPress ID spaces overlap: post 12, term 12, user 12
 * and order 12 are four different things.
 *
 * @property int $id
 * @property string $sourceHash
 * @property string $sourceKey
 * @property string $sourceId
 * @property string $destType
 * @property int|null $destId
 * @property string|null $destUid
 * @property int|null $siteId
 * @property string|null $contentHash
 * @property string|null $sourceUrl
 * @property int|null $runId
 */
class MapRecord extends ActiveRecord
{
    public const TABLE = '{{%passer_map}}';

    public static function tableName(): string
    {
        return self::TABLE;
    }
}
