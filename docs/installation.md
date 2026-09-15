---
title: Installation
slug: installation
order: 10
summary: Requirements, install, the optional destination plugins, and connecting to WordPress.
---

## Requirements

- Craft CMS 5.3 or later
- PHP 8.2 or later
- One of: a readable MySQL database, a WXR export, REST API access, or an SSH host with wp-cli

## Install

```bash
composer require justinholtweb/craft-passer
php craft plugin/install passer
```

Or install it from **Settings → Plugins** in the control panel.

## Editions

| | Lite | Pro |
| --- | --- | --- |
| Price | Free | $99 one-off, $79/year renewal |
| Sources | WXR export, REST API | **and** MySQL database, wp-cli over SSH |
| Content, users, media, taxonomies | ✓ | ✓ |
| Gutenberg, classic content, shortcodes, ACF | ✓ | ✓ |
| Comments | ✓ | ✓ |
| Plan, provision, test-import, run, report | ✓ | ✓ |
| The ID map and re-runnable migrations | ✓ | ✓ |
| WooCommerce → Craft Commerce | — | ✓ |
| SEO metadata | — | ✓ |
| Redirects | — | ✓ |
| Menus | — | ✓ |
| Widgets | — | ✓ |
| Forms and their entries | — | ✓ |

Lite is roughly parity with `craftcms/wp-import`, from the control panel rather than the command
line. Pro adds the two sources that can reach `wp_options`, `wp_postmeta` and the `wc_*` tables,
and the six domains that live in them.

## Optional destination plugins

Passer hard-requires none of these. Every domain has a fallback that needs nothing but Craft, and
the wizard shows you what it found. Install the ones you want **before** provisioning, so the plan
can target them.

| Domain | Install |
| --- | --- |
| Commerce | `craftcms/commerce` |
| Rich text | `craftcms/ckeditor` |
| SEO | `nystudio107/craft-seomatic` |
| Redirects | `nystudio107/craft-retour` or `justinholtweb/craft-friends` |
| Menus | `justinholtweb/craft-free-nav` |
| Forms | `verbb/formie`, or `justinholtweb/craft-bandage` for submissions |
| Comments | `verbb/comments` |

If `craftcms/wp-import` is installed, Passer reuses its ACF adapters and Gutenberg block
transformers for anything it does not handle itself. It is not required.

## Connecting to WordPress

Passer never writes to WordPress. Pick whichever source you can actually reach — every source
produces the same records, so the mapping you build does not change with the connection you happen
to have.

### MySQL database

The only complete source. Use a read-only MySQL user with `SELECT` and nothing else:

```sql
CREATE USER 'passer'@'%' IDENTIFIED BY '…';
GRANT SELECT ON wordpress.* TO 'passer'@'%';
```

Passer attempts a write on connect and **refuses the credentials if it succeeds**. A migration has
no business holding write access to the site it is reading, and the failure mode if it does is
unrecoverable.

### WXR export file

**Tools → Export → All content** in WordPress. No credentials, no network, and it works on a site
that is already switched off. It cannot see widgets, SEO metadata or WooCommerce, because
WordPress does not export `wp_options`, `wp_postmeta` in full, or the `wc_*` tables.

### REST API

Parity with `craftcms/wp-import`: easiest to set up, weakest data. Needs `/wp-json/` reachable and
an application password for anything non-public.

### wp-cli over SSH

For a managed host that gives you a shell but not a database port. Reach is the same as the
database source, because it is the database source with `wp db query` in front of it.

## What each source can answer

| | Reach | When you'd use it |
| --- | --- | --- |
| **MySQL database** | Everything | A live database, a tunnel, or a restored dump. |
| **WXR export file** | Content, terms, users, comments | No credentials, no network, works on a dead site. |
| **REST API** | What REST exposes | Easiest to set up, weakest data. |
| **wp-cli over SSH** | Everything | A shell but no database port. |

Passer declares, per source, what it can and cannot answer, and greys out the phases a given source
cannot reach — rather than importing nothing and reporting success.
