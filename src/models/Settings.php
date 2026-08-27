<?php

namespace justinholtweb\passer\models;

use craft\base\Model;
use craft\behaviors\EnvAttributeParserBehavior;
use Psr\Log\LogLevel;

/**
 * Passer's plugin settings.
 *
 * Everything here is a default the wizard can override per run — nothing in this model is
 * required, because a `required` rule on a settings model blocks a fresh install (Craft
 * validates settings before the user has ever seen the settings screen).
 */
class Settings extends Model
{
    /**
     * @var int How many source records to pull, transform and save per batch.
     * Smaller batches survive weaker hosts; larger ones finish sooner.
     */
    public int $batchSize = 50;

    /**
     * @var int Seconds to allow a single source request before giving up.
     */
    public int $requestTimeout = 30;

    /**
     * @var bool Download attachments into a Craft volume during the media phase.
     * When false, attachment records are still mapped so references resolve, but no bytes move.
     */
    public bool $downloadMedia = true;

    /**
     * @var int Maximum size, in megabytes, of a single attachment Passer will download.
     */
    public int $maxAssetSizeMb = 128;

    /**
     * @var bool Keep going when one record fails, logging it, rather than failing the run.
     */
    public bool $continueOnError = true;

    /**
     * @var int Times to retry a failed source request before treating it as an error.
     */
    public int $maxRetries = 3;

    /**
     * @var bool Rewrite absolute WordPress URLs found in imported content to their Craft
     * equivalents, using the ID map.
     */
    public bool $rewriteUrls = true;

    /**
     * @var bool Expand known WordPress shortcodes in post content instead of leaving them as text.
     */
    public bool $expandShortcodes = true;

    /**
     * @var bool Preserve WordPress post IDs in a `wpId` field on imported elements, so a
     * migration can be audited after the ID map is gone.
     */
    public bool $preserveSourceIds = true;

    /**
     * @var bool Use `craftcms/wp-import`'s ACF adapters and Gutenberg block transformers when
     * that plugin is installed, before falling back to Passer's own.
     */
    public bool $useWpImportBridge = true;

    /**
     * @var string|null Email address to send a run report to. Null sends to nobody.
     */
    public ?string $reportEmail = null;

    /**
     * @var bool Delete the ID map when a run is deleted. Off by default: the map is what makes a
     * later top-up run idempotent, and it is small.
     */
    public bool $pruneMapWithRun = false;

    /**
     * @var string Monolog level for Passer's own log target.
     */
    public string $logLevel = LogLevel::INFO;

    public function behaviors(): array
    {
        return [
            'parser' => [
                'class' => EnvAttributeParserBehavior::class,
                'attributes' => ['reportEmail'],
            ],
        ];
    }

    public function rules(): array
    {
        return [
            [['batchSize'], 'integer', 'min' => 1, 'max' => 1000],
            [['requestTimeout'], 'integer', 'min' => 1, 'max' => 600],
            [['maxAssetSizeMb'], 'integer', 'min' => 1, 'max' => 4096],
            [['maxRetries'], 'integer', 'min' => 0, 'max' => 10],
            [['reportEmail'], 'email', 'skipOnEmpty' => true],
            [
                ['logLevel'],
                'in',
                'range' => [
                    LogLevel::DEBUG,
                    LogLevel::INFO,
                    LogLevel::NOTICE,
                    LogLevel::WARNING,
                    LogLevel::ERROR,
                ],
            ],
        ];
    }

    public function attributeLabels(): array
    {
        return [
            'batchSize' => 'Batch size',
            'requestTimeout' => 'Request timeout',
            'downloadMedia' => 'Download media',
            'maxAssetSizeMb' => 'Maximum asset size (MB)',
            'continueOnError' => 'Continue on error',
            'maxRetries' => 'Maximum retries',
            'rewriteUrls' => 'Rewrite URLs',
            'expandShortcodes' => 'Expand shortcodes',
            'preserveSourceIds' => 'Preserve WordPress IDs',
            'useWpImportBridge' => 'Use wp-import adapters',
            'reportEmail' => 'Report email',
            'pruneMapWithRun' => 'Prune ID map with run',
            'logLevel' => 'Log level',
        ];
    }
}
