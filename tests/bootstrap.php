<?php

/**
 * PHPUnit bootstrap for Passer's Craft-free unit tests.
 *
 * These cover the parsing and normalisation that is the substance of a WordPress migration —
 * serialized meta, Gutenberg block markup, shortcode syntax, CF7 tags, SEO template variables —
 * none of which needs a database or a Craft application to exercise.
 *
 * Set PASSER_AUTOLOAD to point at a host project's vendor/autoload.php when running outside a
 * standalone `composer install`.
 */

declare(strict_types=1);

error_reporting(E_ALL);

$autoload = getenv('PASSER_AUTOLOAD') ?: __DIR__ . '/../vendor/autoload.php';

if (!is_file($autoload)) {
    fwrite(STDERR, "Autoloader not found at $autoload. Run `composer install` or set PASSER_AUTOLOAD.\n");
    exit(1);
}

require $autoload;
require dirname(__DIR__) . '/vendor/yiisoft/yii2/Yii.php';
