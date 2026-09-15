---
title: FAQ
slug: faq
order: 50
summary: How Passer differs from wp-import, what it refuses to do, and what needs a decision.
---

## Is Passer free?

Lite is, and it is not a trial. It brings across posts, pages, custom post types, users, media,
taxonomies and comments from a WXR export or the REST API, with the full Gutenberg, classic-content,
shortcode and ACF handling and the whole plan-provision-run pipeline.

Pro is a one-off **$99** with a **$79/year** renewal, and adds the database and wp-cli sources plus
the six domains that only they can reach: WooCommerce, SEO metadata, redirects, menus, widgets and
forms.

## How is this different from `craftcms/wp-import`?

Two things: reach and sources.

wp-import handles posts, pages, custom post types, users, media, taxonomies, ACF and Gutenberg. It
explicitly does not handle **WooCommerce**, **SEO metadata**, **redirects**, **menus**, **widgets**
or **forms** — and it reads WordPress over the REST API only, from the command line only.

Passer covers those six domains, and reads from a database, a WXR export, the REST API or wp-cli
over SSH. The REST-only part is the deeper problem: the data those domains live in — `wp_options`
for menus and widgets, `wp_postmeta` for SEO, the `wc_*` tables for commerce — is barely exposed
over REST at all.

## Do I have to uninstall wp-import?

No. If it is installed, Passer reuses its ACF adapters and Gutenberg block transformers for
anything it does not handle itself, including adapters you wrote in `config/wp-import/`. It is not
required either way.

## Does Passer write to WordPress?

Never. Every connection is read-only, and the database source enforces it by attempting a write on
connect and refusing the credentials if it succeeds.

## Will it migrate my theme?

No. Templates are the developer's job, and always will be.

## Can I run it more than once?

That is the design. Every WordPress record that became something in Craft is in the ID map, so a
second run updates rather than duplicating. Migrate a copy in week one, correct the mapping, and
re-run against the live site on cutover day.

## What happens to passwords?

Imported users need a reset. WordPress hashes with phpass or bcrypt in its own format and Craft
cannot verify either. Passer says so in the report rather than implying otherwise.

## Do I need SEOmatic, Retour, Formie and the rest?

No. Passer hard-requires none of the plugins it writes to. Every domain has a fallback that needs
nothing but Craft — plain fields for SEO, a `config/redirects.php` for redirects, a structure
section for menus — and the wizard shows you what it found.

## What about WooCommerce orders?

Products, variations, attributes, categories, coupons, customers and orders all come across,
reading either legacy post-based orders or High-Performance Order Storage. Orders arrive as
history, keeping the totals the customer was actually charged rather than letting Commerce
recalculate them against today's tax rates.

## My WordPress site is already switched off. Can I still migrate it?

Yes, if you have a `.sql` dump or a WXR export. The database source works against a restored dump,
and a WXR file needs no credentials and no network at all.

## What does it do with a block or shortcode it does not recognise?

Keeps it. An unhandled Gutenberg block keeps its rendered markup and is named in the report; an
unhandled shortcode is left in place. Both mark something that needs a decision, and deleting them
would hide that.

## Which versions are supported?

Craft CMS 5.3+ and PHP 8.2+. WooCommerce import needs Craft Commerce 5.0+.
