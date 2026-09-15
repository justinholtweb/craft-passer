---
title: Troubleshooting
slug: troubleshooting
order: 40
summary: Blocked REST endpoints, refused credentials, greyed-out phases, and runs that stopped.
---

## The REST API returns 401 or 403

Common on a site behind Basic auth, or one where a security plugin blocks `/wp-json/`. This is
exactly why Passer has four sources: if REST is blocked and you cannot get it unblocked, use a
database connection, a WXR export, or wp-cli over SSH instead. The mapping you build does not
change with the source.

## Passer refuses my database credentials

The database source attempts a write on connect and refuses the credentials if it succeeds. Create
a read-only MySQL user with `SELECT` only and use that.

## A phase is greyed out

The source you connected cannot answer it. A WXR export cannot see widgets, because WordPress does
not export `wp_options`; it also cannot see SEO metadata or WooCommerce. Passer greys those phases
out and says why, rather than importing nothing and reporting success. Switch to a database or
wp-cli source for full reach.

## The run stopped part-way

Runs checkpoint as they go. Resume rather than restarting:

```bash
php craft passer/migrate/resume 7
```

If jobs are timing out, lower `batchSize` in `config/passer.php`.

## A second run duplicated everything

It should not — the ID map exists to prevent it. This happens when the second run uses a *different
plan* than the first, since the map is keyed per plan. Re-run the original plan rather than
creating a new one.

## Imported users cannot log in

Expected. WordPress hashes passwords with phpass or bcrypt in its own format, and Craft cannot
verify either. Imported users need a password reset, and the report says so rather than implying
otherwise.

## Some Gutenberg blocks came through as raw markup

A block nothing handles keeps its rendered markup rather than vanishing, and is named in the
report. If it is a block you need handled properly, an adapter in `config/wp-import/` is picked up
when `craftcms/wp-import` is installed.

## A shortcode is still sitting in the content

Deliberate. A shortcode with no handler is left in place rather than deleted, because it marks
something that needs a decision — usually a template or a Craft plugin that should replace it.

## Order totals do not match WooCommerce

Orders are imported as history, keeping the totals the customer was actually charged rather than
letting Commerce recalculate them against today's tax rates. Anything that does not reconcile is
listed in the report rather than quietly adjusted.

## Links to the old site did not get rewritten

Link rewriting runs against the ID map, so the target has to have been imported already. If you ran
a partial migration, re-run the content phases and then the link pass. Check `rewriteLinks` is not
set to `false` in `config/passer.php`.

## Getting help

[Open an issue on GitHub](https://github.com/justinholtweb/craft-passer/issues).
