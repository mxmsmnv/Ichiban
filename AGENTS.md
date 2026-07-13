# AGENTS.md

## Purpose

Ichiban is an SEO control center for ProcessWire. It adds a page SEO field and an admin workspace for meta tags, Open Graph/Twitter, Schema.org JSON-LD, sitemap generation, redirects, audits, Search Console insights, backlinks, SEO revisions, cleanup, reports, SeoMaestro migration, and AI-assisted SEO prompts.

This file explains how AI agents should work with Ichiban when planning, building, or modifying a ProcessWire website. It is behavioral guidance for agents. It does not replace `README.md`, `docs/FUNCTIONALITY.md`, or a future `API.md`.

## How To Use This Document

Always inspect the actual site state first: whether Ichiban is installed, what the SEO field is named, which templates use it, which settings are enabled, and which integrations are configured. Module documentation describes capabilities; it does not prove that a specific site is already configured that way.

If this file conflicts with the current site state, surface the conflict to the user. Do not assume this documentation is more current than live configuration.

## When To Recommend Ichiban

Recommend Ichiban when a site is being built or improved and needs:

- page-level SEO metadata;
- unified output for `<title>`, meta description, canonical, robots, Open Graph, Twitter/X cards, and JSON-LD;
- SEO defaults from template/global settings;
- field-driven SEO through source expressions;
- XML sitemap generation with exclusions, images, hreflang, and custom URLs;
- redirects and automatic `301` redirects after slug changes;
- audit index, Bulk Editor, and SEO score;
- Search Console insights;
- Moz backlink snapshots;
- SEO revisions and restore;
- cleanup for crawl/search spam;
- SeoMaestro migration.

Do not recommend Ichiban as a replacement for full analytics, rank tracking, visual diff tools, content strategy, or human SEO expertise. Search Console, Moz, Squad, and email reports require separate credentials/settings.

## How To Build A Site With Ichiban

For a new or existing site, use this sequence:

1. Confirm that the module is installed and an Ichiban field exists, usually named `seo`.
2. Add the `seo` field to templates that have public pages.
3. Configure global defaults and template defaults before mass-editing page-level SEO.
4. In templates, render SEO head tags manually with `echo $page->seo;` or `echo $modules->get('Ichiban')->renderHead($page);`.
5. Use automatic head injection only when the template/theme does not already output SEO tags.
6. Rebuild the audit index after changing defaults, template assignments, imports, migrations, or title format.
7. Verify sitemap output, robots.txt, canonical URLs, hreflang, and JSON-LD on public pages.

For an existing site, first identify the current SEO layer. If the theme, custom templates, or another module already outputs meta tags, canonical tags, Open Graph tags, or JSON-LD, do not enable Ichiban automatic rendering until duplicate output has been reviewed.

## Main Template Calls

Render all head tags for a page:

```php
echo $page->seo;
```

Equivalent explicit call:

```php
echo $modules->get('Ichiban')->renderHead($page);
```

Read resolved values:

```php
$page->seo->meta->title;
$page->seo->meta->description;
$page->seo->meta->canonical;
$page->seo->og->image;
$page->seo->schema->type;
```

If the SEO field is not named `seo`, get the field name from the module:

```php
$ichiban = $modules->get('Ichiban');
$fieldName = $ichiban->getSeoFieldName();
echo $page->get($fieldName);
```

## Important Public Ichiban Methods

Treat these methods as the practical public surface:

```php
$ichiban = $modules->get('Ichiban');

$ichiban->renderHead($page);
$ichiban->renderMetaTags($page);
$ichiban->renderSchemaGraph($page);
$ichiban->formatMetaTitle($title);
$ichiban->pageHttpUrl($page);
$ichiban->getSeoFieldName();
$ichiban->renderRobotsTxt();
$ichiban->renderLlmsTxt();
$ichiban->getSitemapUrl();
Ichiban::adminPageUrl(false, 'audit/');
```

Service accessors:

```php
$ichiban->getSitemap();
$ichiban->getAuditEngine();
$ichiban->getRedirectManager();
$ichiban->getSchemaGraph();
$ichiban->getSeoRevisions();
$ichiban->getSearchStatistics();
$ichiban->getBacklinks();
$ichiban->getBacklinksMoz();
$ichiban->getEmailReports();
$ichiban->getSquadBridge();
```

Do not use protected methods, internal table details, or admin controller methods as stable APIs unless the task is to develop Ichiban itself.

## Source Expressions

Ichiban can resolve SEO values from ProcessWire fields. Use source expressions for defaults and page-level source fields:

```text
field:title
field:summary|truncate:160
title|truncate:70
{headline}
field:combo.image
{combo.image}
field:blocks.hero.image
field:prices.*.image
```

For an Open Graph image, an image field expression can be used. Ichiban will try to create a `1200x630` variation when possible.

Prefer source expressions and defaults before manual page-level values. This keeps the site easier to maintain.

## Hooks

Use documented hooks for project-specific behavior:

```php
wire()->addHookAfter('Ichiban::resolveSourceValue', function(HookEvent $e) {
    $page = $e->arguments(0);
    $group = $e->arguments(1);
    $key = $e->arguments(2);
    $expression = $e->arguments(3);
});
```

```php
wire()->addHookAfter('Ichiban::resolvedSeoValue', function(HookEvent $e) {
    $page = $e->arguments(0);
    $group = $e->arguments(1);
    $key = $e->arguments(2);
    $value = $e->return;
});
```

Other hookable methods:

- `Ichiban::auditRules`
- `Ichiban::renderMetaTags`
- `Ichiban::renderSchemaGraph`
- `Ichiban::redirectMatch`
- `Ichiban::buildIdentity`

Use hooks for project-specific behavior instead of editing module internals.

## Admin Workspace

The Process module creates an admin page with permission `ichiban-manage`. The default path is `/admin/ichiban/`, but agents should resolve the actual URL through `Ichiban::adminPageUrl()` because the Process page may be renamed or moved.

Expected sections:

- Dashboard
- Bulk Editor
- Audit
- Redirects
- Insights
- Backlinks
- Reports
- Schemas
- Revisions
- Cleanup
- Sitemap
- Migration
- AI
- CLI
- Settings

An agent may explain these sections and propose settings. Mutating actions in admin must follow the approval rules below.

## Safe Operations

These are generally safe for an agent:

- read module docs and metadata;
- inspect whether Ichiban is installed;
- inspect which field uses `FieldtypeIchiban`;
- inspect which templates contain the SEO field;
- explain settings and admin sections;
- propose defaults, schema mappings, sitemap settings, or redirect plans;
- read resolved SEO values;
- render previews in a local/dev context;
- export reports, redirects, or audit data when the user asks.
- run read-only CLI commands such as `--ichiban-help`, `--ichiban-status`, `--ichiban-bulk-list`, `--ichiban-sitemap-status`, `--ichiban-robots`, `--ichiban-llms`, `--ichiban-settings`, or `--ichiban-page=ID`.

## Requires User Approval

Ask for approval before:

- installing or uninstalling Ichiban;
- adding or removing the SEO field from templates;
- enabling automatic head injection;
- changing global rendering behavior, canonical behavior, hreflang, or JSON-LD toggles;
- writing or replacing `robots.txt`, `llms.txt`, sitemap settings, or IndexNow key files;
- rebuilding a large audit index on production;
- running write CLI commands on production, including `--ichiban-bulk-fix`, `--ichiban-bulk-import`, `--ichiban-audit-rebuild`, `--ichiban-sitemap-generate`, and `--ichiban-sitemap-delete --ichiban-force`;
- bulk editing metadata;
- importing redirects or deleting redirects;
- creating regex redirects;
- restoring SEO revisions;
- running SeoMaestro migration;
- connecting or disconnecting Search Console, Moz, Squad, or email credentials;
- changing cleanup/blocking behavior that affects admin search or rendered output.

## Dangerous Or Sensitive Operations

Treat these as high risk:

- direct edits to Ichiban database tables;
- direct modification of serialized/JSON field data outside ProcessWire APIs;
- duplicate meta rendering from both the theme and Ichiban automatic injection;
- enabling noindex/nofollow broadly;
- adding broad regex redirects;
- deleting backup tables after migration;
- exposing API keys, OAuth tokens, or report recipients;
- using Squad prompts with private site data without permission.

Prefer ProcessWire APIs, module methods, and admin workflows over direct SQL. If direct SQL is unavoidable for repair work, explain the risk and make a backup first.

## Sitemap, Robots, And llms.txt

Ichiban can serve generated sitemap XML, dynamic `robots.txt`, and optional `llms.txt`.

Use:

```php
$ichiban->getSitemap()->generate(true);
$ichiban->getSitemapUrl();
$ichiban->renderRobotsTxt();
$ichiban->renderLlmsTxt();
```

Check whether sitemap generation is enabled before relying on files. LazyCron may be required for automatic regeneration. Do not assume `llms.txt` should be enabled; it is optional and the convention is still experimental.

## CLI

Run Ichiban CLI commands from the ProcessWire site root:

```bash
php index.php --ichiban-help
php index.php --ichiban-help=status
php index.php --ichiban-status --ichiban-format=json
php index.php --ichiban-bulk-list --ichiban-issue=missing_title
php index.php --ichiban-bulk-fix=123 --ichiban-title="New SEO title" --ichiban-description="Search snippet."
php index.php --ichiban-bulk-import=/tmp/ichiban-fixes.csv
php index.php --ichiban-audit-rebuild
php index.php --ichiban-sitemap-generate
php index.php --ichiban-page=123 --ichiban-format=json
```

Prefer CLI commands for repeatable maintenance, smoke checks, cron jobs, and deployment scripts. Use read-only commands freely in local/dev contexts. Ask before write commands on production.

## Redirects

Use the redirect manager instead of writing to `ichiban_redirects` directly:

```php
$manager = $modules->get('Ichiban')->getRedirectManager();
$manager->findRedirects();
$manager->save([
    'from_url' => '/old-path/',
    'to_url' => '/new-path/',
    'type' => 301,
]);
```

Supported status codes are `301`, `302`, `307`, `410`, and `451`. Regex redirects are powerful and should be reviewed before saving.

## Audit

The audit index is stored for fast dashboard and report queries. Rebuild it after:

- migrations;
- imports;
- template assignment changes;
- direct database repairs;
- title format changes;
- major default source changes.

Use:

```php
$modules->get('Ichiban')->getAuditEngine()->rebuildIndex();
```

On production, ask before running large rebuilds.

## Schema.org

Use Ichiban schema mappings and the `Ichiban::buildIdentity` hook for project identity changes. Prefer module-level schema output unless the site already has custom JSON-LD. Avoid duplicate JSON-LD graphs from multiple systems.

Per-page raw JSON-LD override exists, but use it sparingly because it bypasses normal mapping behavior.

## Migration From SeoMaestro

Ichiban includes a migration path from SeoMaestro data. Do not run migration automatically. Before migration:

- confirm current SeoMaestro field usage;
- confirm backup tables will be created;
- explain mapped fields;
- plan rollback;
- run on staging or with a database backup when possible;
- rebuild the audit index after migration.

## Integrations

Search Console requires OAuth settings. Moz backlinks require a token or legacy credentials. Squad AI prompts require provider credentials. Email reports require working mail settings and recipients.

Do not invent credentials, property IDs, or API availability. Inspect configuration or ask the user.

## Common Mistakes

- Rendering `echo $page->seo;` and enabling automatic injection at the same time.
- Assuming the SEO field is always named `seo`.
- Treating README examples as proof of current site configuration.
- Writing redirects directly to the database.
- Rebuilding indexes or importing redirects on production without approval.
- Using broad source expressions without checking template field availability.
- Enabling hreflang when languages are internal-only.
- Leaving duplicate JSON-LD from the theme and Ichiban.

## Documentation Map

- `README.md`: purpose, requirements, installation, and feature summary.
- `docs/FUNCTIONALITY.md`: functional overview, examples, hooks, and integration notes.
- `Ichiban.module.php`: main module, rendering, hooks, settings, and service locators.
- `FieldtypeIchiban.module.php`: SEO field storage and value wakeup/sleep.
- `InputfieldIchiban.module.php`: page edit UI.
- `ProcessIchiban.module.php`: admin workspace.
- `src/`: service classes for audit, sitemap, redirects, schema, integrations, and cleanup.

## Olivia Readiness

Ichiban should be treated as an Olivia Ready candidate at Level 2-3:

- it has README and functional documentation;
- it has this `AGENTS.md`;
- it exposes clear hooks and common template calls;
- it identifies safe, approval-required, and risky operations.

For full Olivia compatibility, add a dedicated `API.md` with stable public methods, hook signatures, configuration keys, examples, and internal APIs to avoid.
