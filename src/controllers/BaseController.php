<?php

namespace justinholtweb\passer\controllers;

use craft\web\Controller;
use justinholtweb\passer\Plugin;

/**
 * Shared behaviour for Passer's control-panel controllers.
 */
abstract class BaseController extends Controller
{
    protected function plugin(): Plugin
    {
        return Plugin::getInstance();
    }

    protected function requireMigratePermission(): void
    {
        $this->requirePermission(Plugin::PERMISSION_MIGRATE);
    }
}
