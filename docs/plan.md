# Passer — build plan

**Passer** is a paid Craft CMS 5 plugin that migrates a WordPress site into Craft. It is a
*superset* of `craftcms/wp-import`: it does everything that plugin does, from four source modes
instead of one, through a control-panel wizard instead of a CLI prompt, and it covers the six
domains wp-import explicitly refuses.

- Composer `justinholtweb/craft-passer`, namespace `justinholtweb\passer`, handle `passer`.
- Craft `^5.3.0`, PHP `^8.2`. Single paid edition.

---

## Why it exists

`craftcms/wp-import` handles posts, pages, CPTs, users, media, taxonomies, 25+ ACF field types,
34 Gutenberg blocks and comments. It does **not** handle WooCommerce, Yoast/RankMath SEO,
redirects, menus, widgets or forms — and it reads WordPress over the **REST API only**, from the
**command line only**.

The REST-only constraint is the deeper problem. A site being migrated is frequently one that is
half-broken, locked behind Basic auth, has `rest_api_init` disabled by a security plugin, or exists
now only as a `.sql` dump on someone's laptop. And the data those six missing domains live in —
`wp_options` for menus and widgets, `wp_postmeta` for SEO, the `wc_*` tables for commerce — is
mostly not exposed over REST at all. Fixing the domains means fixing the source layer first.

## The four source modes

All four implement one `SourceInterface` and yield the same record models, so every importer
downstream is source-agnostic.

| Mode | Reach | When |
| --- | --- | --- |
| `DatabaseSource` | Everything | A live DB, a tunnel, or a restored `.sql` dump. The only complete source. |
| `WxrSource` | Posts, pages, CPTs, terms, comments, meta | Tools → Export. Offline, no credentials. Streamed with `XMLReader`. |
| `RestSource` | What REST exposes | Parity with wp-import. Easiest, weakest. |
| `WpCliSource` | Everything | `wp` over SSH on the origin host. Reaches sites we cannot connect to directly. |

`SourceCapabilities` declares per-mode what a source can answer. The wizard greys out — and the
planner refuses to plan — anything the chosen source cannot supply, rather than silently importing
nothing.

## Pipeline

```
connect → analyse → plan → provision → import → verify → report
```

1. **Connect** — pick a source mode, supply credentials, `testConnection()`.
2. **Analyse** — `Analyzer` walks the source and returns an `Inventory`: post types and counts,
   taxonomies, meta-key histogram, user roles, media count and bytes, and a list of detected
   WordPress plugins (ACF, Woo, Yoast, RankMath, AIOSEO, SEOPress, Redirection, CF7, Gravity
   Forms, WPForms, Ninja, Formidable, WPML/Polylang).
3. **Plan** — `Planner` proposes a `MigrationPlan` mapping post types to sections and entry types,
   taxonomies to category/tag groups, meta keys to fields, roles to user groups, and each optional
   domain to a **destination** the `DestinationRegistry` found installed. The wizard lets the user
   override every row. The plan is a saved, re-runnable, exportable record.
4. **Provision** — `Provisioner` creates the sections, entry types, fields, category groups,
   volumes and user groups the plan calls for, as a single project-config write, with a dry run
   that reports exactly what it would create.
5. **Import** — importers run in dependency order behind a queue job, checkpointing after every
   batch. Every created element is written to the **ID map**, which makes re-runs idempotent,
   makes `--update` real, and is what lets cross-references (a post referencing an attachment
   referencing an author) resolve regardless of import order.
6. **Verify** — post-run checks: unresolved references, missing media, empty required fields.
7. **Report** — a stored, printable run report; also emailed, also available on the console.

## Import order

```
users → media → taxonomies → posts/pages/CPTs → comments
      → woocommerce → menus → widgets → forms → seo → redirects
```

The second row depends on the first (a menu item points at a post; a Woo order points at a
customer and a product), which is why the six "missing" domains run last and why they are the ones
that most need the ID map.

## Destinations

Passer never hard-requires a destination plugin. `DestinationRegistry` probes what is installed and
offers what it finds, always with a dependency-free fallback:

| Domain | Preferred | Fallback |
| --- | --- | --- |
| SEO | SEOmatic, `ether/seo`, Sprout | Native `metaTitle`/`metaDescription`/`ogImage` fields Passer creates |
| Redirects | Retour, `venveo/craft-redirect` | Friends rules, or Craft URL rules written to a config file |
| Menus | FreeNav, Verbb Navigation | A structure section of link entries |
| Forms | Formie | Bandage, or plain field-layout capture |
| Comments | Verbb Comments | Skipped, reported |
| Widgets | — | Global sets, or a Matrix field on a page entry |
| Commerce | Craft Commerce | Skipped, reported |

## Phases

| Phase | Contents |
| --- | --- |
| 0 | Scaffold: plugin, settings, install migration, records, permissions, CP nav |
| 1 | Source layer: interface, capabilities, the four sources, WP record models, PHP-serialize helper |
| 2 | Analysis: inventory, plugin detection, meta histogram |
| 3 | Planning + provisioning: plan model, planner, destination registry, provisioner |
| 4 | Core content: users, media, taxonomies, posts, comments; Gutenberg + ACF + shortcodes; the wp-import bridge |
| 5 | The six domains: Woo, SEO, redirects, menus, widgets, forms |
| 6 | Runner: queue jobs, checkpoints, ID map, CP wizard, history, reports |
| 7 | Console commands, verification, docs, tests |

## Non-goals

- Passer does not write to WordPress. Every source connection is read-only, and the database
  source refuses credentials that can write.
- Passer does not attempt a visual/theme migration. Templates are the developer's job.
- Passer does not proxy media at serve time. Attachments are downloaded into a Craft volume.
