---
title: Configuration
slug: configuration
order: 20
summary: Plans, the config file, choosing a destination per domain, and the wp-import bridge.
---

## Plans, not settings

Passer is configured per **migration plan**, in the control panel under **Passer**. A plan holds
the source connection, the mapping and the phase selection. It is saved, re-runnable and
exportable, because a real migration is never run once: it is run against a copy, examined,
corrected, and run again over weeks while the WordPress site is still being edited.

## The config file

Plugin-wide defaults can be set under **Settings → Passer**, or in `config/passer.php` so they can
differ per environment:

```php
<?php
return [
    // Where downloaded WordPress attachments land
    'mediaVolumeHandle' => 'images',

    // Records per queue job. Lower it on constrained hosts.
    'batchSize' => 100,

    // How many of each element a test-import brings across
    'testImportLimit' => 5,

    // Rewrite links to the old site as Craft reference tags
    'rewriteLinks' => true,

    // Keep unhandled Gutenberg blocks as rendered markup rather than dropping them
    'preserveUnknownBlocks' => true,

    // Refuse a database connection that turns out to be writable
    'requireReadOnlySource' => true,
];
```

## Choosing destinations

During **plan**, each domain gets a destination. Passer detects what is installed and offers the
preferred target, falling back to something that needs nothing but Craft:

| Domain | Preferred | Fallback |
| --- | --- | --- |
| SEO | SEOmatic, `ether/seo` | Plain Craft fields Passer creates |
| Redirects | Retour, Venveo Redirect, Friends | A `config/redirects.php` of URL rules |
| Menus | FreeNav, Verbb Navigation | A structure section of link entries |
| Forms | Formie | Bandage, or a written specification |
| Comments | Verbb Comments | Counted and reported |
| Widgets | — | Global sets, or a widgets section |
| Commerce | Craft Commerce | Products as plain entries |

## Mapping

The plan proposes a full mapping you can override row by row:

- which post type becomes which section and entry type
- where each meta key lands, or that it is deliberately dropped
- which taxonomy becomes which category group
- which ACF field group becomes which field layout

The proposal comes out of **analyse**, so it is shaped by what is actually in the database rather
than by what WordPress claims is installed.

## The wp-import bridge

If `craftcms/wp-import` is installed, Passer reuses its ACF adapters and Gutenberg block
transformers for anything it does not handle itself — including adapters you wrote in
`config/wp-import/`.

The coupling is deliberately loose. `wp-import` ships as `dev-main` with no tagged releases, so a
bridge that cannot load leaves Passer's own adapters in place rather than failing the migration.
