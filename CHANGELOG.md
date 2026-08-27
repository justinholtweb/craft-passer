# Release Notes for Passer

## 5.0.0

Initial release.

### Sources

- Read WordPress from a **MySQL database**, a **WXR export file**, the **REST API**, or **wp-cli
  over SSH**. Every source produces the same records, so the mapping does not change with the
  connection.
- The database source detects the table prefix, handles multisite blogs, streams unbuffered, and
  refuses credentials that can write to WordPress.
- The WXR source streams with `XMLReader` and accepts a split export as several files.
- `SourceCapabilities` declares what each source can answer, and the planner refuses to schedule
  a phase the source cannot serve rather than importing nothing and reporting success.

### Content

- Posts, pages and custom post types, with hierarchy rebuilt in a second pass.
- Users, media, categories, tags and any custom taxonomy.
- Comments, threading included, into Verbb Comments.
- Gutenberg blocks: paragraphs, headings, lists (both storage formats), images, galleries (all
  three formats), covers, embeds, quotes, code, tables, buttons, separators, groups, columns,
  media-and-text, files, and the shortcode block.
- Shortcodes expanded, including `[caption]`, `[gallery]`, `[embed]` and the page builders'
  layout wrappers.
- ACF, including repeaters and flexible content reassembled from their flattened meta rows, and
  local JSON field groups.
- Absolute links to the old site rewritten into Craft reference tags, so they survive the URI
  changes the migration itself makes.

### The parts wp-import does not do

- **WooCommerce → Craft Commerce.** Products, variations, attributes, categories, coupons and
  orders — including High-Performance Order Storage. Orders keep the totals the customer was
  charged rather than being recalculated.
- **SEO.** Yoast, Rank Math, All in One SEO, SEOPress and The SEO Framework, with their template
  variables expanded, into SEOmatic, `ether/seo`, or plain Craft fields.
- **Redirects.** Redirection, Rank Math, All in One SEO, Safe Redirect Manager, Simple 301
  Redirects, the EPS plugin and `.htaccess`, into Retour, Venveo Redirect, Friends, or a
  `config/redirects.php`. Destinations are resolved to imported content, not frozen URLs.
- **Menus.** Into FreeNav, Verbb Navigation, or a structure section — with items related to the
  entries they point at.
- **Widgets.** Into global sets or entries, with the configuration-only widgets described rather
  than faked.
- **Forms.** Contact Form 7, Gravity Forms, WPForms, Ninja Forms and Formidable, into Formie or
  Bandage, with stored entries.

### Running it

- A control-panel wizard: connect, scan, map, provision, test-import, run.
- A `Provisioner` that creates the sections, entry types, fields, category groups and user groups
  the plan needs, with a dry run first.
- An **ID map** that makes re-runs idempotent and cross-domain references resolvable.
- Queue-backed runs that checkpoint per phase and resume rather than restart.
- A run report, on screen, by email, downloadable, and on the console.
- Console commands for every step.
- Reuses `craftcms/wp-import`'s ACF adapters and block transformers when it is installed.
