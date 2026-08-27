<?php

namespace justinholtweb\passer\services;

use craft\base\Component;
use craft\db\Query;
use craft\helpers\Db;
use craft\helpers\Json;
use justinholtweb\passer\records\MapRecord;

/**
 * The ID map: what every WordPress record became.
 *
 * Three jobs, all of which a migration is broken without.
 *
 * 1. **Idempotence.** A second run updates what the first created rather than duplicating it. A
 *    real migration is run repeatedly against a moving source, so this is the normal case.
 * 2. **Reference resolution.** A menu item points at post 412; a redirect's destination is post
 *    99; a Woo order line references product 3021. None of those can be resolved at the moment
 *    they are read, because the target may not be imported yet. They are all resolved through
 *    this table afterwards.
 * 3. **URL rewriting.** Absolute links in post content that point at the old site are rewritten
 *    by looking up the old URL here.
 *
 * Keys are namespaced by `sourceKey` because WordPress ID spaces overlap — post 12, term 12 and
 * user 12 are three unrelated things — and by `sourceHash` because one Craft site may be the
 * destination of more than one WordPress site.
 */
class IdMap extends Component
{
    public const KEY_POST = 'post';
    public const KEY_TERM = 'term';
    public const KEY_USER = 'user';
    public const KEY_USER_LOGIN = 'userLogin';
    public const KEY_ATTACHMENT = 'attachment';
    public const KEY_COMMENT = 'comment';
    public const KEY_MENU = 'menu';
    public const KEY_MENU_ITEM = 'menuItem';
    public const KEY_PRODUCT = 'product';
    public const KEY_VARIATION = 'variation';
    public const KEY_ORDER = 'order';
    public const KEY_COUPON = 'coupon';
    public const KEY_FORM = 'form';
    public const KEY_SUBMISSION = 'submission';
    public const KEY_WIDGET = 'widget';

    private string $sourceHash = '';

    /** @var array<string, array{destId: int|null, destUid: string|null, destType: string}> */
    private array $cache = [];

    private bool $cacheWarmed = false;

    public function setSourceHash(string $hash): void
    {
        if ($hash !== $this->sourceHash) {
            $this->cache = [];
            $this->cacheWarmed = false;
        }

        $this->sourceHash = $hash;
    }

    public function getSourceHash(): string
    {
        return $this->sourceHash;
    }

    /**
     * Record what a WordPress record became.
     */
    public function record(
        string $sourceKey,
        int|string $sourceId,
        string $destType,
        ?int $destId,
        ?string $destUid = null,
        ?int $siteId = null,
        ?string $contentHash = null,
        ?string $sourceUrl = null,
        ?int $runId = null,
    ): void {
        $condition = [
            'sourceHash' => $this->sourceHash,
            'sourceKey' => $sourceKey,
            'sourceId' => (string)$sourceId,
            'siteId' => $siteId,
        ];

        $values = [
            'destType' => $destType,
            'destId' => $destId,
            'destUid' => $destUid,
            'contentHash' => $contentHash,
            'sourceUrl' => $sourceUrl,
            'runId' => $runId,
            'dateUpdated' => Db::prepareDateForDb(new \DateTime()),
        ];

        $existing = (new Query())
            ->select(['id'])
            ->from([MapRecord::TABLE])
            ->where($condition)
            ->scalar();

        if ($existing !== false && $existing !== null) {
            // Db::update()'s fourth argument is query params, not a flag — passing `true` there
            // is a TypeError, and the timestamp is already in $values anyway.
            Db::update(MapRecord::TABLE, $values, ['id' => $existing]);
        } else {
            Db::insert(MapRecord::TABLE, $condition + $values + [
                'dateCreated' => Db::prepareDateForDb(new \DateTime()),
                'uid' => \craft\helpers\StringHelper::UUID(),
            ]);
        }

        $this->cache[$this->cacheKey($sourceKey, $sourceId, $siteId)] = [
            'destId' => $destId,
            'destUid' => $destUid,
            'destType' => $destType,
        ];
    }

    /**
     * The Craft element ID a WordPress record became, or null.
     */
    public function lookup(string $sourceKey, int|string $sourceId, ?int $siteId = null): ?int
    {
        return $this->entry($sourceKey, $sourceId, $siteId)['destId'] ?? null;
    }

    /**
     * @return array{destId: int|null, destUid: string|null, destType: string}|null
     */
    public function entry(string $sourceKey, int|string $sourceId, ?int $siteId = null): ?array
    {
        $key = $this->cacheKey($sourceKey, $sourceId, $siteId);

        if (array_key_exists($key, $this->cache)) {
            return $this->cache[$key];
        }

        $row = (new Query())
            ->select(['destId', 'destUid', 'destType'])
            ->from([MapRecord::TABLE])
            ->where([
                'sourceHash' => $this->sourceHash,
                'sourceKey' => $sourceKey,
                'sourceId' => (string)$sourceId,
                'siteId' => $siteId,
            ])
            ->one();

        if ($row === null) {
            $row = $siteId !== null
                // Users, assets and anything else recorded once rather than per site.
                ? $this->rowFor($sourceKey, $sourceId, null)
                // The reverse, which is the case that matters most: entries are recorded per
                // site, and the phases that resolve references to them — menus, redirects, SEO,
                // comments, commerce line items — ask without naming one. Without this, every
                // one of those silently resolves to nothing.
                : $this->anySiteRowFor($sourceKey, $sourceId);
        }

        $result = $row === null ? null : [
            'destId' => $row['destId'] !== null ? (int)$row['destId'] : null,
            'destUid' => $row['destUid'],
            'destType' => (string)$row['destType'],
        ];

        return $this->cache[$key] = $result;
    }

    /**
     * One row for an exact site, without the fallbacks.
     *
     * @return array<string, mixed>|null
     */
    private function rowFor(string $sourceKey, int|string $sourceId, ?int $siteId): ?array
    {
        return (new Query())
            ->select(['destId', 'destUid', 'destType'])
            ->from([MapRecord::TABLE])
            ->where([
                'sourceHash' => $this->sourceHash,
                'sourceKey' => $sourceKey,
                'sourceId' => (string)$sourceId,
                'siteId' => $siteId,
            ])
            ->one() ?: null;
    }

    /**
     * A row for any site, preferring the primary one.
     *
     * @return array<string, mixed>|null
     */
    private function anySiteRowFor(string $sourceKey, int|string $sourceId): ?array
    {
        $primaryId = \Craft::$app->getSites()->getPrimarySite()->id;

        return (new Query())
            ->select(['destId', 'destUid', 'destType'])
            ->from([MapRecord::TABLE])
            ->where([
                'sourceHash' => $this->sourceHash,
                'sourceKey' => $sourceKey,
                'sourceId' => (string)$sourceId,
            ])
            // The primary site's row first, then the earliest of the rest, so the answer is
            // stable rather than whatever the database happens to return.
            ->orderBy([new \yii\db\Expression('CASE WHEN [[siteId]] = :primary THEN 0 ELSE 1 END', [':primary' => $primaryId]), 'id' => SORT_ASC])
            ->one() ?: null;
    }

    public function has(string $sourceKey, int|string $sourceId, ?int $siteId = null): bool
    {
        return $this->entry($sourceKey, $sourceId, $siteId) !== null;
    }

    /**
     * The content hash recorded for a source record, used to skip unchanged records on a re-run.
     */
    public function contentHash(string $sourceKey, int|string $sourceId, ?int $siteId = null): ?string
    {
        $hash = (new Query())
            ->select(['contentHash'])
            ->from([MapRecord::TABLE])
            ->where([
                'sourceHash' => $this->sourceHash,
                'sourceKey' => $sourceKey,
                'sourceId' => (string)$sourceId,
                'siteId' => $siteId,
            ])
            ->scalar();

        return is_string($hash) && $hash !== '' ? $hash : null;
    }

    /**
     * Resolve a source URL to the Craft element that replaced it.
     *
     * This is how links inside imported content get rewritten, and it has to be tolerant: the
     * stored URL may or may not have a trailing slash, a scheme, or a www.
     */
    public function lookupByUrl(string $url): ?array
    {
        $candidates = $this->urlVariants($url);

        $row = (new Query())
            ->select(['destId', 'destUid', 'destType'])
            ->from([MapRecord::TABLE])
            ->where(['sourceHash' => $this->sourceHash])
            ->andWhere(['sourceUrl' => $candidates])
            ->one();

        if ($row === null) {
            return null;
        }

        return [
            'destId' => $row['destId'] !== null ? (int)$row['destId'] : null,
            'destUid' => $row['destUid'],
            'destType' => (string)$row['destType'],
        ];
    }

    /**
     * @return string[]
     */
    private function urlVariants(string $url): array
    {
        $url = trim($url);
        $variants = [$url];

        $trimmed = rtrim($url, '/');
        $variants[] = $trimmed;
        $variants[] = $trimmed . '/';

        foreach ([$url, $trimmed] as $base) {
            if (str_starts_with($base, 'https://')) {
                $variants[] = 'http://' . substr($base, 8);
            } elseif (str_starts_with($base, 'http://')) {
                $variants[] = 'https://' . substr($base, 7);
            }
        }

        return array_values(array_unique($variants));
    }

    /**
     * Load the whole map for this source into memory.
     *
     * Worth it before the phases that do nothing but resolve references — menus, redirects,
     * commerce line items — where the alternative is one query per reference.
     */
    public function warm(): void
    {
        if ($this->cacheWarmed) {
            return;
        }

        $rows = (new Query())
            ->select(['sourceKey', 'sourceId', 'siteId', 'destId', 'destUid', 'destType'])
            ->from([MapRecord::TABLE])
            ->where(['sourceHash' => $this->sourceHash])
            ->all();

        $primaryId = \Craft::$app->getSites()->getPrimarySite()->id;

        foreach ($rows as $row) {
            $siteId = $row['siteId'] !== null ? (int)$row['siteId'] : null;

            $entry = [
                'destId' => $row['destId'] !== null ? (int)$row['destId'] : null,
                'destUid' => $row['destUid'],
                'destType' => (string)$row['destType'],
            ];

            $this->cache[$this->cacheKey((string)$row['sourceKey'], (string)$row['sourceId'], $siteId)] = $entry;

            // Mirror the query path's site-agnostic fallback, or a warmed cache answers
            // differently from a cold one.
            $agnostic = $this->cacheKey((string)$row['sourceKey'], (string)$row['sourceId'], null);

            if (!isset($this->cache[$agnostic]) || $siteId === $primaryId) {
                $this->cache[$agnostic] = $entry;
            }
        }

        $this->cacheWarmed = true;
    }

    /**
     * Every mapping for a key, as sourceId => destId.
     *
     * @return array<string, int>
     */
    public function allFor(string $sourceKey): array
    {
        $rows = (new Query())
            ->select(['sourceId', 'destId'])
            ->from([MapRecord::TABLE])
            ->where(['sourceHash' => $this->sourceHash, 'sourceKey' => $sourceKey])
            ->andWhere(['not', ['destId' => null]])
            ->all();

        $out = [];

        foreach ($rows as $row) {
            $out[(string)$row['sourceId']] = (int)$row['destId'];
        }

        return $out;
    }

    public function countFor(string $sourceKey): int
    {
        return (int)(new Query())
            ->from([MapRecord::TABLE])
            ->where(['sourceHash' => $this->sourceHash, 'sourceKey' => $sourceKey])
            ->count();
    }

    /**
     * Drop every mapping for this source. Only ever called deliberately, from the CP or console,
     * because it turns the next run back into a first run.
     */
    public function forget(): int
    {
        $this->cache = [];
        $this->cacheWarmed = false;

        return Db::delete(MapRecord::TABLE, ['sourceHash' => $this->sourceHash]);
    }

    /**
     * Remove rows whose Craft element has since been deleted, so a re-run recreates them rather
     * than trying to update something that is gone.
     */
    public function prune(): int
    {
        $removed = 0;

        $rows = (new Query())
            ->select(['id', 'destType', 'destId'])
            ->from([MapRecord::TABLE])
            ->where(['sourceHash' => $this->sourceHash])
            ->andWhere(['not', ['destId' => null]])
            ->all();

        $byType = [];

        foreach ($rows as $row) {
            $byType[(string)$row['destType']][(int)$row['destId']] = (int)$row['id'];
        }

        foreach ($byType as $type => $idsToRows) {
            if (!class_exists($type) || !is_subclass_of($type, \craft\base\ElementInterface::class)) {
                continue;
            }

            $existing = $type::find()
                ->id(array_keys($idsToRows))
                ->status(null)
                ->siteId('*')
                ->unique()
                ->trashed(null)
                ->ids();

            $missing = array_diff(array_keys($idsToRows), array_map('intval', $existing));

            if ($missing === []) {
                continue;
            }

            $rowIds = array_map(static fn(int $elementId) => $idsToRows[$elementId], $missing);
            $removed += Db::delete(MapRecord::TABLE, ['id' => $rowIds]);
        }

        $this->cache = [];
        $this->cacheWarmed = false;

        return $removed;
    }

    private function cacheKey(string $sourceKey, int|string $sourceId, ?int $siteId): string
    {
        return $sourceKey . ':' . $sourceId . ':' . ($siteId ?? '*');
    }

    /**
     * A stable hash of a payload, for deciding whether a record has changed since last run.
     */
    public function hashPayload(mixed $payload): string
    {
        return sha1(Json::encode($payload));
    }
}
