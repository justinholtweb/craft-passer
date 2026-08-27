<?php

namespace justinholtweb\passer\controllers;

use Craft;
use craft\helpers\FileHelper;
use craft\web\UploadedFile;
use justinholtweb\passer\Plugin;
use justinholtweb\passer\sources\SourceException;
use yii\web\Response;

/**
 * Step one of the wizard: connect to WordPress.
 */
class ConnectController extends BaseController
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requireMigratePermission();

        return true;
    }

    public function actionIndex(): Response
    {
        $sources = $this->plugin()->sources;

        $types = [];

        foreach ($sources->types() as $handle => $class) {
            $types[$handle] = [
                'handle' => $handle,
                'name' => $class::displayName(),
                'capabilities' => (new $class())->capabilities(),
            ];
        }

        return $this->renderTemplate('passer/connect/index', [
            'types' => $types,
            'plans' => $this->plugin()->plans->all(),
            'installed' => $this->plugin()->destinations->installedTargets(),
            'bridgeStatus' => $this->plugin()->bridge->status(),
        ]);
    }

    /**
     * Test a connection without saving anything.
     */
    public function actionTest(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $type = $this->request->getRequiredBodyParam('sourceType');
        $config = $this->configFromRequest($type);

        try {
            $source = $this->plugin()->sources->create($type, $config);
            $summary = $source->testConnection();
            $capabilities = $source->capabilities();
            $source->close();
        } catch (SourceException $e) {
            return $this->asJson(['success' => false, 'error' => $e->getMessage()]);
        } catch (\Throwable $e) {
            return $this->asJson([
                'success' => false,
                'error' => Craft::t('passer', 'Unexpected error: {message}', ['message' => $e->getMessage()]),
            ]);
        }

        return $this->asJson([
            'success' => true,
            'summary' => $summary,
            'supports' => $capabilities->supportedDomains(),
            'limitations' => $capabilities->limitations,
        ]);
    }

    /**
     * Connect, analyse and create a plan, then move to step two.
     */
    public function actionStart(): Response
    {
        $this->requirePostRequest();

        $type = $this->request->getRequiredBodyParam('sourceType');
        $config = $this->configFromRequest($type);

        try {
            $source = $this->plugin()->sources->create($type, $config);
            $source->connect();

            $inventory = $this->plugin()->analyzer->analyze($source);
            $plan = $this->plugin()->planner->propose($inventory, $source->capabilities());
            $plan->sourceType = $type;
            $plan->sourceConfig = $config;

            $record = $this->plugin()->plans->save($plan, $inventory);

            $source->close();
        } catch (\Throwable $e) {
            $this->setFailFlash($e->getMessage());

            return $this->redirect('passer/connect');
        }

        return $this->redirect('passer/analyze/' . $record->id);
    }

    /**
     * Read the source configuration out of the request, handling the WXR file upload.
     *
     * @return array<string, mixed>
     */
    private function configFromRequest(string $type): array
    {
        $config = $this->request->getBodyParam('config', []);
        $config = is_array($config) ? ($config[$type] ?? $config) : [];

        // Numeric fields arrive as strings from a form, and the source classes are typed.
        foreach (['port', 'blogId', 'timeout', 'requestTimeout', 'perPage'] as $key) {
            if (isset($config[$key]) && $config[$key] !== '') {
                $config[$key] = (int)$config[$key];
            } else {
                unset($config[$key]);
            }
        }

        foreach (['enforceReadOnly', 'insecure', 'allowRoot'] as $key) {
            if (isset($config[$key])) {
                $config[$key] = (bool)$config[$key];
            }
        }

        if ($type === 'wxr') {
            $config['paths'] = $this->storeUploads();
        }

        return array_filter($config, static fn($v) => $v !== '' && $v !== null);
    }

    /**
     * Move uploaded WXR files somewhere they will survive the request.
     *
     * @return string[]
     */
    private function storeUploads(): array
    {
        $files = UploadedFile::getInstancesByName('wxrFiles');
        $paths = [];

        if ($files === []) {
            // Re-submitting the form after a validation failure keeps the already-stored paths.
            $existing = $this->request->getBodyParam('config')['wxr']['paths'] ?? [];

            return is_array($existing) ? array_values(array_filter($existing, 'is_file')) : [];
        }

        $directory = Craft::$app->getPath()->getStoragePath() . '/passer/wxr';
        FileHelper::createDirectory($directory);

        foreach ($files as $file) {
            if ($file->getHasError()) {
                continue;
            }

            $extension = strtolower($file->getExtension());

            // WXR is XML. Refusing anything else keeps an arbitrary upload out of storage.
            if (!in_array($extension, ['xml', 'wxr', 'txt'], true)) {
                throw new \yii\web\BadRequestHttpException(
                    'A WordPress export must be an XML file. ' . $file->name . ' is not.'
                );
            }

            $target = $directory . '/' . uniqid('wxr_', false) . '-' . FileHelper::sanitizeFilename($file->name);

            if ($file->saveAs($target)) {
                $paths[] = $target;
            }
        }

        return $paths;
    }
}
