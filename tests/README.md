# Passer's tests

Two harnesses, deliberately independent.

## `tests/unit` — the parsing, run with PHPUnit

Everything a WordPress migration gets wrong before it ever touches Craft: broken serialized meta,
Gutenberg's block-comment format, shortcode attributes, Contact Form 7's tag syntax, the SEO
plugins' template variables, `.htaccess` redirect directives, menu hierarchy. None of it needs a
database or a Craft application, so none of it uses one.

```
composer test
```

## `tests/integration/verify.php` — the migration, run against a real WordPress database

The unit tests cannot tell you whether a migration *works*. This does: it loads a small but
deliberately awkward WordPress database, connects to it the way a user would, and walks the whole
pipeline — connect, analyse, plan, provision, run, then run again — asserting on what actually
arrived in Craft.

The fixture in `tests/_fixtures/wordpress.sql` contains the things that break importers:

- a serialized option whose string lengths were corrupted by a domain search-and-replace
- Gutenberg and classic-editor content side by side, with a third-party block nobody handles
- a `[caption]` shortcode, a `[gallery]`, and one shortcode with no handler
- a bare URL in classic content, which WordPress only links at render time
- a page tree stored child-first
- a meta key repeated across rows, next to one holding a serialized array
- Yoast metadata written as `%%title%% %%sep%% %%sitename%%` rather than as a title
- a WooCommerce variable product, an order, and a coupon
- a menu whose items point at posts by ID
- redirects in Redirection's tables, one of them a regular expression

### Running it

It needs a Craft install with Passer enabled, and a MySQL database to read. In the shared
plugin-testing harness:

```bash
ddev mysql -uroot -proot -e "CREATE DATABASE wordpress_fixture CHARACTER SET utf8mb4;"
ddev mysql -uroot -proot wordpress_fixture < /var/www/craft-passer/tests/_fixtures/wordpress.sql
ddev mysql -uroot -proot -e "
  CREATE USER IF NOT EXISTS 'wpreader'@'%' IDENTIFIED BY 'wpreader';
  GRANT SELECT ON wordpress_fixture.* TO 'wpreader'@'%';"

cp /var/www/craft-passer/tests/integration/verify.php .
ddev exec php verify.php
```

The read-only grant is not incidental: one of the assertions is that Passer *refuses* credentials
that can write to WordPress, so the script also connects as root and expects to be turned away.

Every run resets first — it deletes everything the last run imported and clears the ID map — so a
bug fixed since the last run is actually exercised rather than skipped by the map.
