<?php

namespace justinholtweb\passer\controllers;

use Craft;
use justinholtweb\passer\models\MigrationPlan;
use justinholtweb\passer\models\plan\DomainMapping;
use justinholtweb\passer\models\plan\PostTypeMapping;
use justinholtweb\passer\models\plan\TaxonomyMapping;
use justinholtweb\passer\Plugin;
use justinholtweb\passer\progress\NullProgress;
use justinholtweb\passer\queue\RunJob;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Step three: review and adjust the mapping, provision, preview, then run.
 */
class PlanController extends BaseController
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requireMigratePermission();

        return true;
    }

    public function actionIndex(int $planId): Response
    {
        $plan = $this->plugin()->plans->find($planId);
        $inventory = $this->plugin()->plans->inventoryFor($planId);

        if ($plan === null) {
            throw new NotFoundHttpException('Plan not found.');
        }

        $source = $this->plugin()->sources->create($plan->sourceType, $plan->sourceConfig);

        return $this->renderTemplate('passer/plan/index', [
            'planId' => $planId,
            'plan' => $plan,
            'inventory' => $inventory,
            'capabilities' => $source->capabilities(),
            'destinations' => $this->destinationOptions(),
            'sections' => Craft::$app->getEntries()->getAllSections(),
            'volumes' => Craft::$app->getVolumes()->getAllVolumes(),
            'sites' => Craft::$app->getSites()->getAllSites(),
            'userGroups' => Craft::$app->getUserGroups()->getAllGroups(),
        ]);
    }

    public function actionSave(int $planId): Response
    {
        $this->requirePostRequest();

        $plan = $this->plugin()->plans->find($planId);

        if ($plan === null) {
            throw new NotFoundHttpException('Plan not found.');
        }

        $this->applyRequest($plan);
        $this->plugin()->plans->save($plan);

        $this->setSuccessFlash('Plan saved.');

        return $this->redirect('passer/plan/' . $planId);
    }

    /**
     * Show what provisioning would create, or actually create it.
     */
    public function actionProvision(int $planId): Response
    {
        $this->requirePostRequest();
        $this->requirePermission(Plugin::PERMISSION_PROVISION);

        $plan = $this->plugin()->plans->find($planId);

        if ($plan === null) {
            throw new NotFoundHttpException('Plan not found.');
        }

        $this->applyRequest($plan);
        $this->plugin()->plans->save($plan);

        $apply = (bool)$this->request->getBodyParam('apply', false);
        $result = $apply
            ? $this->plugin()->provisioner->apply($plan)
            : $this->plugin()->provisioner->dryRun($plan);

        if ($this->request->getAcceptsJson()) {
            return $this->asJson([
                'success' => $result->isOk(),
                'applied' => $result->applied,
                'created' => $result->created,
                'existing' => $result->existing,
                'errors' => $result->errors,
            ]);
        }

        if ($result->isOk() && $apply) {
            $this->setSuccessFlash(sprintf('Created %d things.', count($result->created)));
        } elseif (!$result->isOk()) {
            $this->setFailFlash(implode(' ', $result->errors));
        }

        return $this->redirect('passer/plan/' . $planId);
    }

    /**
     * Import a handful of records so the mapping can be checked before the real run.
     *
     * This is the step that saves a migration: five posts imported and looked at will tell you
     * more about whether the mapping is right than any amount of reading the plan screen.
     */
    public function actionPreview(int $planId): Response
    {
        $this->requirePostRequest();

        $plan = $this->plugin()->plans->find($planId);

        if ($plan === null) {
            throw new NotFoundHttpException('Plan not found.');
        }

        $limit = min(25, max(1, (int)$this->request->getBodyParam('limit', 5)));

        $run = $this->plugin()->runner->createRun($plan, true, $planId);
        $report = $this->plugin()->runner->run($run, $plan, new NullProgress(), null, $limit);

        return $this->renderTemplate('passer/plan/preview', [
            'planId' => $planId,
            'plan' => $plan,
            'report' => $report,
            'run' => $run,
        ]);
    }

    /**
     * Queue the real run.
     */
    public function actionStart(int $planId): Response
    {
        $this->requirePostRequest();

        $plan = $this->plugin()->plans->find($planId);

        if ($plan === null) {
            throw new NotFoundHttpException('Plan not found.');
        }

        $dryRun = (bool)$this->request->getBodyParam('dryRun', false);
        $run = $this->plugin()->runner->createRun($plan, $dryRun, $planId);

        Craft::$app->getQueue()->push(new RunJob(['runId' => $run->id]));

        $this->setSuccessFlash('Migration queued.');

        return $this->redirect('passer/runs/' . $run->id);
    }

    // -----------------------------------------------------------------------------------------
    // Request handling
    // -----------------------------------------------------------------------------------------

    /**
     * Apply the mapping form's values to a plan.
     *
     * Everything is read defensively: the form is large, browsers omit unchecked checkboxes, and
     * a missing key must mean "off" rather than "leave as it was" for those.
     */
    private function applyRequest(MigrationPlan $plan): void
    {
        $plan->name = (string)$this->request->getBodyParam('name', $plan->name);
        $plan->updateExisting = (bool)$this->request->getBodyParam('updateExisting', false);
        $plan->rewriteUrls = (bool)$this->request->getBodyParam('rewriteUrls', false);
        $plan->expandShortcodes = (bool)$this->request->getBodyParam('expandShortcodes', false);

        $plan->importUsers = (bool)$this->request->getBodyParam('importUsers', false);
        $plan->importNonAuthors = (bool)$this->request->getBodyParam('importNonAuthors', false);
        $plan->activateUsers = (bool)$this->request->getBodyParam('activateUsers', false);

        $plan->importMedia = (bool)$this->request->getBodyParam('importMedia', false);
        $plan->importOrphanedMedia = (bool)$this->request->getBodyParam('importOrphanedMedia', false);
        $plan->volume = (string)$this->request->getBodyParam('volume', $plan->volume);
        $plan->folderStrategy = (string)$this->request->getBodyParam('folderStrategy', $plan->folderStrategy);

        $fallback = $this->request->getBodyParam('fallbackAuthorId');
        $plan->fallbackAuthorId = is_numeric($fallback) ? (int)$fallback : $plan->fallbackAuthorId;

        $siteId = $this->request->getBodyParam('defaultSiteId');
        $plan->defaultSiteId = is_numeric($siteId) ? (int)$siteId : $plan->defaultSiteId;

        $roleMap = $this->request->getBodyParam('roleMap');
        if (is_array($roleMap)) {
            $plan->roleMap = array_filter(array_map('strval', $roleMap));
        }

        $languageMap = $this->request->getBodyParam('languageMap');
        if (is_array($languageMap)) {
            $plan->languageMap = array_filter(array_map('intval', $languageMap));
        }

        foreach ((array)$this->request->getBodyParam('postTypes', []) as $postType => $values) {
            if (!is_array($values)) {
                continue;
            }

            $mapping = $plan->postTypes[(string)$postType] ?? new PostTypeMapping(['postType' => (string)$postType]);

            $mapping->enabled = !empty($values['enabled']);
            $mapping->section = (string)($values['section'] ?? $mapping->section);
            $mapping->sectionName = (string)($values['sectionName'] ?? $mapping->sectionName);
            $mapping->sectionType = (string)($values['sectionType'] ?? $mapping->sectionType);
            $mapping->entryType = (string)($values['entryType'] ?? $mapping->entryType);
            $mapping->entryTypeName = (string)($values['entryTypeName'] ?? $mapping->entryTypeName);
            $mapping->contentField = ($values['contentField'] ?? '') !== '' ? (string)$values['contentField'] : null;
            $mapping->contentFormat = (string)($values['contentFormat'] ?? $mapping->contentFormat);
            $mapping->excerptField = ($values['excerptField'] ?? '') !== '' ? (string)$values['excerptField'] : null;
            $mapping->featuredImageField = ($values['featuredImageField'] ?? '') !== '' ? (string)$values['featuredImageField'] : null;
            $mapping->includeTrashed = !empty($values['includeTrashed']);
            $mapping->preserveUris = !empty($values['preserveUris']);

            if (isset($values['fieldMap']) && is_array($values['fieldMap'])) {
                // A blank handle means "do not import this meta key", which is how the form lets
                // a user drop one without a separate control.
                $mapping->fieldMap = array_filter(array_map('strval', $values['fieldMap']));
            }

            if (isset($values['taxonomyFields']) && is_array($values['taxonomyFields'])) {
                $mapping->taxonomyFields = array_filter(array_map('strval', $values['taxonomyFields']));
            }

            if (isset($values['siteIds']) && is_array($values['siteIds'])) {
                $mapping->siteIds = array_values(array_filter(array_map('intval', $values['siteIds'])));
            }

            $plan->postTypes[(string)$postType] = $mapping;
        }

        foreach ((array)$this->request->getBodyParam('taxonomies', []) as $taxonomy => $values) {
            if (!is_array($values)) {
                continue;
            }

            $mapping = $plan->taxonomies[(string)$taxonomy] ?? new TaxonomyMapping(['taxonomy' => (string)$taxonomy]);

            $mapping->enabled = !empty($values['enabled']);
            $mapping->destination = (string)($values['destination'] ?? $mapping->destination);
            $mapping->handle = (string)($values['handle'] ?? $mapping->handle);
            $mapping->name = (string)($values['name'] ?? $mapping->name);
            $mapping->entryType = ($values['entryType'] ?? '') !== '' ? (string)$values['entryType'] : null;

            $plan->taxonomies[(string)$taxonomy] = $mapping;
        }

        foreach ((array)$this->request->getBodyParam('domains', []) as $domain => $values) {
            if (!is_array($values)) {
                continue;
            }

            $mapping = $plan->domains[(string)$domain] ?? new DomainMapping(['domain' => (string)$domain]);

            $mapping->enabled = !empty($values['enabled']);
            $mapping->destination = (string)($values['destination'] ?? $mapping->destination);

            if (isset($values['options']) && is_array($values['options'])) {
                $mapping->options = $values['options'];
            }

            $plan->domains[(string)$domain] = $mapping;
        }
    }

    /**
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function destinationOptions(): array
    {
        $registry = $this->plugin()->destinations;
        $out = [];

        foreach (['commerce', 'seo', 'redirects', 'menus', 'widgets', 'forms', 'comments'] as $domain) {
            foreach ($registry->forDomain($domain) as $destination) {
                $out[$domain][] = [
                    'value' => $destination->handle,
                    'label' => $destination->name . ($destination->available ? '' : ' — not installed'),
                    'disabled' => !$destination->available,
                    'description' => $destination->description,
                    'unavailableReason' => $destination->unavailableReason,
                ];
            }
        }

        return $out;
    }
}
