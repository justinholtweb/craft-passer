---
title: Usage
slug: usage
order: 30
summary: The seven phases, running one domain at a time, the console commands, and the ID map.
---

## The seven phases

```
connect → analyse → plan → provision → test-import → run → report
```

Every phase is re-runnable, and the pipeline is built on the assumption that you will run it more
than once.

### 1. Connect

Choose a source and prove it works. Every connection is read-only; the database source attempts a
write on connect and refuses the credentials if it succeeds.

### 2. Analyse

Measures the site before anything moves: post types and counts, taxonomies, a meta-key histogram,
and the WordPress plugins it finds.

Detection is **by evidence** — a table, an option, a meta key — not by asking WordPress which
plugins are active. A plugin deactivated last year still has all its data, and that data is exactly
what needs migrating.

### 3. Plan

Proposes a full mapping you can override row by row. Plans are saved, re-runnable and exportable.

### 4. Provision

Creates the sections, entry types, fields, category groups and user groups the plan needs — the day
of clicking other tools leave you to do by hand.

```bash
php craft passer/migrate/provision --plan=1 -d   # dry run first
php craft passer/migrate/provision --plan=1
```

It is idempotent: running it twice creates nothing the second time.

### 5. Test-import

Brings across five of everything so you can look at the result before committing to it.

### 6. Run

Works through the queue, one phase per job, checkpointing as it goes. A run interrupted by a
timeout, a worker restart or someone pressing stop resumes rather than starting over:

```bash
php craft passer/migrate/resume 7
```

### 7. Report

What came across, what did not, and why. Unhandled Gutenberg blocks are named. Orders that do not
reconcile are reported rather than quietly adjusted. Form features that cannot migrate —
conditional logic, payment gateways, captcha keys — are listed.

## Running one domain at a time

Phases can be run on their own, which is how you fix one domain without re-importing the site:

```bash
php craft passer/migrate/run --plan=1 --phases=seo,redirects
```

## Console commands

Everything the control panel does, for when a large site is better migrated from a terminal:

```bash
php craft passer/migrate/plans                    # list saved plans
php craft passer/migrate/analyze --plan=1         # what's in the source
php craft passer/migrate/provision --plan=1 -d    # dry-run the content model
php craft passer/migrate/run --plan=1 --provision # provision, then migrate
php craft passer/migrate/run --plan=1 --phases=seo,redirects
php craft passer/migrate/resume 7                 # continue an interrupted run
php craft passer/migrate/report 7
```

## The ID map

Every WordPress record that became something in Craft is recorded. This is what makes the whole
thing work rather than a nice extra:

- a second run **updates** rather than duplicating
- a menu item imported in the ninth phase finds the post imported in the fourth
- an absolute link in ten-year-old content resolves to the entry that replaced it

It is what makes it safe to migrate a copy in week one and re-run against the live site on cutover
day. The map is keyed per plan, so re-run the *original* plan rather than creating a new one.
