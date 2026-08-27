<?php

namespace justinholtweb\passer\controllers;

use Craft;
use justinholtweb\passer\Plugin;
use yii\web\Response;

/**
 * Passer's settings screen.
 */
class SettingsController extends BaseController
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requireAdmin();

        return true;
    }

    public function actionIndex(): Response
    {
        return $this->renderTemplate('passer/settings/index', [
            'plugin' => $this->plugin(),
            'settings' => $this->plugin()->getSettings(),
            'installed' => $this->plugin()->destinations->installedTargets(),
            'bridgeStatus' => $this->plugin()->bridge->status(),
        ]);
    }

    public function actionSave(): Response
    {
        $this->requirePostRequest();

        $plugin = $this->plugin();
        $settings = $this->request->getBodyParam('settings', []);

        if (!is_array($settings)) {
            $settings = [];
        }

        // Craft namespaces plugin settings HTML itself, so the values arrive nested under
        // `settings` and every checkbox that is off is simply absent.
        foreach (['downloadMedia', 'continueOnError', 'rewriteUrls', 'expandShortcodes', 'preserveSourceIds', 'useWpImportBridge', 'pruneMapWithRun'] as $key) {
            $settings[$key] = !empty($settings[$key]);
        }

        if (!Craft::$app->getPlugins()->savePluginSettings($plugin, $settings)) {
            $this->setFailFlash('Could not save settings.');

            return $this->renderTemplate('passer/settings/index', [
                'plugin' => $plugin,
                'settings' => $plugin->getSettings(),
                'installed' => $plugin->destinations->installedTargets(),
                'bridgeStatus' => $plugin->bridge->status(),
            ]);
        }

        $this->setSuccessFlash('Settings saved.');

        return $this->redirectToPostedUrl();
    }
}
