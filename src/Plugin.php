<?php

namespace justinholtweb\passer;

use Craft;
use craft\base\Plugin as BasePlugin;
use craft\events\RegisterUrlRulesEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\helpers\UrlHelper;
use craft\log\MonologTarget;
use craft\services\UserPermissions;
use craft\web\UrlManager;
use justinholtweb\passer\acf\AcfConverter;
use justinholtweb\passer\acf\AcfReader;
use justinholtweb\passer\bridge\WpImportBridge;
use justinholtweb\passer\content\ContentTransformer;
use justinholtweb\passer\models\Settings;
use justinholtweb\passer\services\Analyzer;
use justinholtweb\passer\services\DestinationRegistry;
use justinholtweb\passer\services\FormReader;
use justinholtweb\passer\services\IdMap;
use justinholtweb\passer\services\Plans;
use justinholtweb\passer\services\Planner;
use justinholtweb\passer\services\PluginDetector;
use justinholtweb\passer\services\Provisioner;
use justinholtweb\passer\services\RedirectReader;
use justinholtweb\passer\services\Runner;
use justinholtweb\passer\services\SeoReader;
use justinholtweb\passer\services\Sources;
use justinholtweb\passer\services\WooReader;
use yii\base\Event;

/**
 * Passer — the complete WordPress to Craft migration.
 *
 * @property-read Sources $sources
 * @property-read Analyzer $analyzer
 * @property-read PluginDetector $pluginDetector
 * @property-read Planner $planner
 * @property-read Plans $plans
 * @property-read Provisioner $provisioner
 * @property-read DestinationRegistry $destinations
 * @property-read Runner $runner
 * @property-read IdMap $map
 * @property-read ContentTransformer $contentTransformer
 * @property-read AcfReader $acfReader
 * @property-read AcfConverter $acfConverter
 * @property-read WpImportBridge $bridge
 * @property-read WooReader $woo
 * @property-read SeoReader $seo
 * @property-read RedirectReader $redirects
 * @property-read FormReader $forms
 * @property-read Settings $settings
 *
 * @method Settings getSettings()
 */
class Plugin extends BasePlugin
{
    public const PERMISSION_MIGRATE = 'passer:migrate';
    public const PERMISSION_PROVISION = 'passer:provision';
    public const PERMISSION_MANAGE_MAP = 'passer:manageMap';

    public string $schemaVersion = '1.0.0';
    public bool $hasCpSection = true;
    public bool $hasCpSettings = true;

    public static function config(): array
    {
        return [
            'components' => [
                'sources' => Sources::class,
                'analyzer' => Analyzer::class,
                'pluginDetector' => PluginDetector::class,
                'planner' => Planner::class,
                'plans' => Plans::class,
                'provisioner' => Provisioner::class,
                'destinations' => DestinationRegistry::class,
                'runner' => Runner::class,
                'map' => IdMap::class,
                'contentTransformer' => ContentTransformer::class,
                'acfReader' => AcfReader::class,
                'acfConverter' => AcfConverter::class,
                'bridge' => WpImportBridge::class,
                'woo' => WooReader::class,
                'seo' => SeoReader::class,
                'redirects' => RedirectReader::class,
                'forms' => FormReader::class,
            ],
        ];
    }

    public function init(): void
    {
        parent::init();

        $this->registerLogging();
        $this->registerCpUrlRules();
        $this->registerPermissions();
    }

    /**
     * Send Passer's own log entries to storage/logs/passer.log.
     *
     * A migration produces a great deal of logging, and mixing it into Craft's main log makes
     * both harder to read.
     */
    private function registerLogging(): void
    {
        Craft::getLogger()->dispatcher->targets[] = new MonologTarget([
            'name' => 'passer',
            'categories' => ['passer'],
            'level' => $this->getSettings()->logLevel,
            'logContext' => false,
            'allowLineBreaks' => true,
            'maxFiles' => 10,
        ]);
    }

    public function getCpNavItem(): ?array
    {
        $item = parent::getCpNavItem();
        $user = Craft::$app->getUser();

        $subnav = [];

        if ($user->checkPermission(self::PERMISSION_MIGRATE)) {
            $subnav['migrate'] = ['label' => Craft::t('passer', 'Migrate'), 'url' => 'passer/connect'];
            $subnav['plans'] = ['label' => Craft::t('passer', 'Plans'), 'url' => 'passer/plans'];
            $subnav['runs'] = ['label' => Craft::t('passer', 'Runs'), 'url' => 'passer/runs'];
        }

        if ($user->checkPermission(self::PERMISSION_MANAGE_MAP)) {
            $subnav['map'] = ['label' => Craft::t('passer', 'ID map'), 'url' => 'passer/map'];
        }

        if ($user->getIsAdmin()) {
            $subnav['settings'] = ['label' => Craft::t('passer', 'Settings'), 'url' => 'passer/settings'];
        }

        $item['subnav'] = $subnav;

        return $item;
    }

    protected function createSettingsModel(): Settings
    {
        return new Settings();
    }

    protected function settingsHtml(): ?string
    {
        return Craft::$app->getView()->renderTemplate('passer/_settings', [
            'settings' => $this->getSettings(),
        ]);
    }

    public function getSettingsResponse(): mixed
    {
        return Craft::$app->getResponse()->redirect(UrlHelper::cpUrl('passer/settings'));
    }

    private function registerCpUrlRules(): void
    {
        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_CP_URL_RULES,
            static function (RegisterUrlRulesEvent $event) {
                $event->rules['passer'] = 'passer/connect/index';

                // The wizard, in the order it is walked.
                $event->rules['passer/connect'] = 'passer/connect/index';
                $event->rules['passer/connect/test'] = 'passer/connect/test';
                $event->rules['passer/analyze/<planId:\d+>'] = 'passer/analyze/index';
                $event->rules['passer/plan/<planId:\d+>'] = 'passer/plan/index';
                $event->rules['passer/plan/<planId:\d+>/provision'] = 'passer/plan/provision';
                $event->rules['passer/plan/<planId:\d+>/preview'] = 'passer/plan/preview';
                $event->rules['passer/plan/<planId:\d+>/start'] = 'passer/plan/start';

                $event->rules['passer/plans'] = 'passer/plans/index';
                $event->rules['passer/plans/<planId:\d+>'] = 'passer/plan/index';

                $event->rules['passer/runs'] = 'passer/runs/index';
                $event->rules['passer/runs/<runId:\d+>'] = 'passer/runs/detail';
                $event->rules['passer/runs/<runId:\d+>/log'] = 'passer/runs/log';

                $event->rules['passer/map'] = 'passer/map/index';
                $event->rules['passer/settings'] = 'passer/settings/index';
            }
        );
    }

    private function registerPermissions(): void
    {
        Event::on(
            UserPermissions::class,
            UserPermissions::EVENT_REGISTER_PERMISSIONS,
            static function (RegisterUserPermissionsEvent $event) {
                $event->permissions[] = [
                    'heading' => Craft::t('passer', 'Passer'),
                    'permissions' => [
                        self::PERMISSION_MIGRATE => [
                            'label' => Craft::t('passer', 'Connect to WordPress and run migrations'),
                            'info' => Craft::t('passer', 'Includes reading the source site\'s database credentials.'),
                        ],
                        self::PERMISSION_PROVISION => [
                            'label' => Craft::t('passer', 'Create sections, fields and volumes from a plan'),
                            'info' => Craft::t('passer', 'Provisioning writes to project config.'),
                        ],
                        self::PERMISSION_MANAGE_MAP => [
                            'label' => Craft::t('passer', 'View and clear the ID map'),
                            'info' => Craft::t('passer', 'Clearing the map makes the next run create duplicates rather than update.'),
                        ],
                    ],
                ];
            }
        );
    }
}
