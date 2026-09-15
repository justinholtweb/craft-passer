# Passer

**The complete WordPress to Craft CMS migration.**

Named for the genus of the sparrows — the small brown bird that follows people from house to
house, carrying nothing much and losing none of it.

Requires Craft CMS 5.3+ and PHP 8.2+.

---

## The problem

Craft's own `craftcms/wp-import` is good, and it stops halfway. It handles posts, pages, custom
post types, users, media, taxonomies, ACF and Gutenberg. It explicitly does not handle
**WooCommerce**, **SEO metadata**, **redirects**, **menus**, **widgets** or **forms** — and it
reads WordPress over the **REST API only**, from the **command line only**.

The REST-only part is the deeper problem, and it is easy to miss until you are in the middle of a
migration. A site being moved off WordPress is very often one that is half-broken: behind Basic
auth, with `/wp-json/` blocked by a security plugin, on a host that will not open a port, or
existing now only as a `.sql` file on someone's laptop. And the data those six missing domains
live in — `wp_options` for menus and widgets, `wp_postmeta` for SEO, the `wc_*` tables for
commerce — is barely exposed over REST at all.

So fixing the domains means fixing the source layer first. That is what Passer is.

---

## Four ways in

Every source produces the same records, so the mapping you build does not change with the
connection you happen to have.

| | Reach | When you'd use it |
| --- | --- | --- |
| **MySQL database** | Everything | A live database, a tunnel, or a restored dump. The only complete source. |
| **WXR export file** | Content, terms, users, comments | Tools → Export. No credentials, no network, works on a dead site. |
| **REST API** | What REST exposes | Parity with wp-import. Easiest to set up, weakest data. |
| **wp-cli over SSH** | Everything | A managed host that gives you a shell but not a database port. |

Passer declares, per source, what it can and cannot answer. A WXR export cannot see widgets,
because WordPress does not export `wp_options` — so Passer greys that phase out and says why,
rather than importing nothing and reporting success. That distinction matters more than it
sounds: silently importing zero of something is the most misleading thing a migration tool can do.

**The database source is read-only by construction.** It attempts a write on connect and refuses
the credentials if it succeeds. A migration has no business holding write access to the site it is
reading, and the failure mode if it does is unrecoverable.

---

## What comes across

### The content

Posts, pages and custom post types, with hierarchy rebuilt in a second pass — a WordPress page
tree has no ordering guarantee and children are routinely stored before their parents. Users,
media, categories, tags and any custom taxonomy. Comments, threading and all, into Verbb Comments.

**Gutenberg** blocks are parsed properly, not regexed: paragraphs, headings, lists in both storage
formats, images, galleries in all three formats WordPress has used, covers, embeds, quotes, code,
tables, buttons, separators, groups, columns, media-and-text, files. A block nothing handles keeps
its rendered markup rather than vanishing, and is named in the report.

**Classic content** gets the treatment WordPress applies at render time and never stores:
paragraphs from double newlines, and bare URLs turned into links. Without the second one, every
bare link to the old domain survives the migration as unlinked, un-rewritable text.

**Shortcodes** are expanded — `[caption]`, `[gallery]`, `[embed]`, and the page builders' layout
wrappers. One with no handler is left in place rather than deleted, because it marks something
that needs a decision.

**ACF** values are read through their field definitions, including repeaters and flexible content
reassembled out of their flattened meta rows, and field groups kept in local JSON.

**Links** to the old site become Craft reference tags, so they keep working after the migration
changes every URI on the site.

### The half wp-import refuses

**WooCommerce → Craft Commerce.** Products, variations, attributes, categories, coupons,
customers and orders — reading either legacy post-based orders or High-Performance Order Storage,
whichever this shop uses. Orders are imported as history, keeping the totals the customer was
actually charged rather than letting Commerce recalculate them against today's tax rates. Anything
that does not reconcile is reported, not quietly adjusted.

**SEO.** Yoast, Rank Math, All in One SEO, SEOPress and The SEO Framework. Their template
variables are expanded, so a title stored as `%%title%% %%sep%% %%sitename%%` arrives as a title.
On a site that switched plugins and never cleaned up, Passer takes whichever plugin has more data
*for that post*, so old posts keep their old metadata and new ones keep their new.

**Redirects.** Redirection, Rank Math, All in One SEO, Safe Redirect Manager, Simple 301
Redirects, the EPS plugin, and a pasted `.htaccess`. Destinations are resolved through the ID map
to the imported content, so a redirect points at the entry rather than at a URL the migration is
about to change.

**Menus.** Items that point at posts become relations, not frozen URLs.

**Widgets.** The ones with real content are imported; the ones that are configuration — recent
posts, search, a nav menu — are described rather than faked, because reproducing them is the
template's job.

**Forms.** Contact Form 7 (parsed out of its tag syntax), Gravity Forms, WPForms, Ninja Forms and
Formidable, with their stored entries. What cannot come across — conditional logic, payment
gateways, captcha keys — is listed explicitly rather than silently dropped.

---

## Editions

| | Lite | Pro |
| --- | --- | --- |
| Price | Free | $99 one-off, $79/year renewal |
| Sources | WXR export, REST API | **and** MySQL database, wp-cli over SSH |
| Content, users, media, taxonomies, comments | ✓ | ✓ |
| Gutenberg, classic content, shortcodes, ACF | ✓ | ✓ |
| Plan, provision, test-import, run, report | ✓ | ✓ |
| The ID map and re-runnable migrations | ✓ | ✓ |
| WooCommerce, SEO, redirects, menus, widgets, forms | — | ✓ |

Lite is roughly parity with `craftcms/wp-import`, from the control panel rather than the command
line. Pro adds the two sources that can reach `wp_options`, `wp_postmeta` and the `wc_*` tables —
and the six domains that live in them.

---

## Nothing is required

Passer hard-requires none of the plugins it writes to. Every domain has a fallback that needs
nothing but Craft, and the wizard shows you what it found:

| Domain | Preferred | Fallback |
| --- | --- | --- |
| SEO | SEOmatic, `ether/seo` | Plain Craft fields Passer creates |
| Redirects | Retour, Venveo Redirect, Friends | A `config/redirects.php` of URL rules |
| Menus | FreeNav, Verbb Navigation | A structure section of link entries |
| Forms | Formie | Bandage, or a written specification |
| Comments | Verbb Comments | Counted and reported |
| Widgets | — | Global sets, or a widgets section |
| Commerce | Craft Commerce | Products as plain entries |

---

## How a migration goes

```
connect → analyse → plan → provision → test-import → run → report
```

**Analyse** measures the site before anything moves: post types and counts, taxonomies, a meta-key
histogram, and the WordPress plugins it finds. Detection is by evidence — a table, an option, a
meta key — not by asking WordPress which plugins are active, because a plugin deactivated last
year still has all its data and that data is exactly what needs migrating.

**Plan** proposes a full mapping you can override row by row. It is saved, re-runnable and
exportable, which matters because a real migration is never run once: it is run against a copy,
examined, corrected, and run again over weeks while the WordPress site is still being edited.

**Provision** creates the sections, entry types, fields, category groups and user groups the plan
needs — the day of clicking that wp-import leaves you to do by hand — with a dry run first, and
idempotently, so running it twice creates nothing the second time.

**Test-import** brings across five of everything so you can look at the result before committing.

**Run** goes through the queue, one phase per job, checkpointing as it goes. A run interrupted by
a timeout, a worker restart or someone pressing stop resumes rather than starting over.

### The ID map

Every WordPress record that became something in Craft is recorded. This is what makes the whole
thing work rather than a nice extra:

- a second run **updates** rather than duplicating
- a menu item imported in the ninth phase finds the post imported in the fourth
- an absolute link in ten-year-old content resolves to the entry that replaced it

---

## Console

Everything the control panel does, for when a large site is better migrated from a terminal.

```bash
php craft passer/migrate/plans                    # list saved plans
php craft passer/migrate/analyze --plan=1         # what's in the source
php craft passer/migrate/provision --plan=1 -d    # dry-run the content model
php craft passer/migrate/run --plan=1 --provision # provision, then migrate
php craft passer/migrate/run --plan=1 --phases=seo,redirects
php craft passer/migrate/resume 7                 # continue an interrupted run
php craft passer/migrate/report 7
```

---

## wp-import

If `craftcms/wp-import` is installed, Passer reuses its ACF adapters and Gutenberg block
transformers for anything it does not handle itself — including adapters you wrote in
`config/wp-import/`. The coupling is deliberately loose (wp-import ships as `dev-main` with no
tagged releases), so a bridge that cannot load leaves Passer's own adapters in place rather than
failing the migration.

It is not required, and Passer does everything it does without it.

---

## What Passer does not do

- **Write to WordPress.** Every connection is read-only, and the database source enforces it.
- **Migrate your theme.** Templates are the developer's job, and always will be.
- **Bring passwords across.** WordPress hashes with phpass or bcrypt in its own format; Craft
  cannot verify either. Imported users need a reset. Passer says so in the report rather than
  implying otherwise.
- **Proxy your old media.** Attachments are downloaded into a Craft volume.
