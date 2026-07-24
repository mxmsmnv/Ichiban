# Ichiban Functionality

This document summarizes what Ichiban provides inside ProcessWire and how the main pieces fit together.

## Page SEO Field

The Ichiban field stores page-level SEO data and renders five tabs:

- **Meta**: title, description, canonical URL, noindex, nofollow, Google-style preview.
- **Social**: Open Graph fields, Twitter/X fields, social preview cards, OG image source support.
- **Schema**: page schema type and output preview.
- **Sitemap**: include flag, priority, and change frequency.
- **Advanced**: custom robots meta and raw JSON-LD override.

Render the field from a template with:

```php
echo $page->seo;
```

Resolved values are also available directly:

```php
$page->seo->meta->title;
$page->seo->meta->description;
$page->seo->meta->canonical;
$page->seo->og->image;
$page->seo->schema->type;
```

## Source Expressions

SEO values can be custom text or field-driven expressions:

Global and template defaults can use the same flat keys as Ichiban page data:

```json
{
  "meta_title": "field:title",
  "meta_description": "field:summary|truncate:160"
}
```

The resolver also accepts dot or nested notation such as `meta.title` or `{ "meta": { "title": "field:title" } }`. Empty page-level source fields inherit from template and global defaults.

```text
title
title|truncate:70
summary|truncate:160
field:title
field:summary|truncate:160
{headline}
{splash}
field:combo.image
{combo.image}
field:blocks.hero.image
field:prices.*.image
```

Image expressions resolve image fields to URLs. For Open Graph images, Ichiban creates a `1200x630` variation when possible. Nested ProFields paths use dot notation.

## Rendering

Ichiban can output:

- `<title>` and meta description
- canonical URL
- Open Graph tags
- Twitter/X card tags
- robots directives
- verification tags
- hreflang alternates
- optional Meta Pixel
- JSON-LD schema graph

Manual rendering is recommended unless your templates do not already output SEO tags. Automatic injection can be enabled in settings.

Settings also include separate rendering toggles for frontend hreflang links and JSON-LD schema. Disable hreflang when ProcessWire languages are used internally but the public site should not advertise alternate language URLs. Disable JSON-LD when templates or another SEO layer already generates structured data.

### Title Format

Settings include a global **Title Format** field for rendered titles:

```text
{meta_title} | {site_name}
```

Supported placeholders:

- `{meta_title}`
- `{site_name}`
- `{entity_name}`
- `{host}`

Audit and Bulk Editor title length checks use the formatted title length. Rebuild the audit index after changing the format.

## Admin Workspace

The Process module adds an SEO workspace with these sections:

- **Dashboard**: site score, quick stats, Search Console highlights, indexing issues, schemas, redirects, revisions, reports, and cleanup status.
- **Bulk Editor**: inline editing for indexed meta titles and descriptions, grouped by Critical, Warnings, and Healthy.
- **Audit**: rule report from `ichiban_index`, score, priority cards, affected page links, full rule table, rebuild action, CSV export.
- **Redirects**: manual redirects, regex redirects, `301`, `302`, `307`, `410`, `451`, hit counts, notes, CSV import/export.
- **Insights**: Google Search Console OAuth, Search Analytics cache, top pages, queries, countries, devices, search appearance, URL Inspection sampling.
- **Backlinks**: Moz API token or legacy credentials, saved backlink snapshots, links/domains/anchors views, quota snapshots.
- **Reports**: scheduled report settings, manual report generation, test email, latest report export as DOCX.
- **Schemas**: database-backed Schema.org mapping builder with built-in and custom types.
- **Revisions**: tracked SEO field changes with restore support.
- **Cleanup**: crawl tag cleanup and spam search query blocking.
- **Migration**: SeoMaestro to Ichiban converter with backup tables.
- **AI**: Squad-backed SEO prompt test workspace.
- **CLI**: command reference for audit, sitemap, status, robots/llms, settings, and page SEO inspection.
- **Settings**: identity, defaults, rendering, verification, Search Console, Moz, sitemap, robots/llms, reports, cleanup, and AI.

## CLI

Ichiban exposes maintenance commands through ProcessWire's normal CLI bootstrap. Run commands from the site root:

```bash
php index.php --ichiban-help
```

Common commands:

```bash
php index.php --ichiban-status
php index.php --ichiban-bulk-list --ichiban-issue=missing_title
php index.php --ichiban-bulk-fix=123 --ichiban-title="New SEO title" --ichiban-description="Search snippet."
php index.php --ichiban-bulk-import=/tmp/ichiban-fixes.csv
php index.php --ichiban-audit-rebuild
php index.php --ichiban-sitemap-generate
php index.php --ichiban-sitemap-status
php index.php --ichiban-page=123 --ichiban-format=json
```

Use `--ichiban-help=command` for detailed help and `--ichiban-format=json` for machine-readable output. Bulk import CSV columns are `page_id,title,description,inherit_title,inherit_description`. Destructive sitemap cleanup requires `--ichiban-force`.

## Audit

The audit index stores resolved SEO data in `ichiban_index` for fast dashboard and report queries.

Current checks include:

- meta title present
- meta title length
- meta title uniqueness
- meta description present
- meta description length
- meta description uniqueness
- Open Graph image present
- canonical URL validity
- noindex on public pages
- underscores in URLs
- schema type present

Scores are grouped as:

- **Critical**: below 60
- **Warnings**: 60 to 79
- **Healthy**: 80 and above

Use **Rebuild Index** after migrations, imports, template assignment changes, direct database edits, or title format changes.

## Sitemap

Ichiban can generate and serve XML sitemap files from the configured sitemap directory, usually `/sitemaps/sitemap.xml`.

Supported sitemap features:

- template-specific files
- chunked URL sets
- image sitemap entries
- multilingual hreflang alternates
- custom URLs
- noindex and page-level sitemap settings
- template and pattern exclusions
- manual generation
- LazyCron regeneration

Ichiban also appends the configured sitemap URL when serving dynamic `robots.txt`.

## Search Console

The Insights section connects to Google Search Console with OAuth. It caches Search Analytics data and shows date ranges for 7, 28, 90, 180, and 365 days.

Google does not expose the full Page indexing report through the Search Analytics API, so Ichiban provides a cached URL Inspection sample scan for practical indexing checks.

## Moz Backlinks

Backlinks uses Moz Links API data and stores every refresh as a database snapshot. Ordinary page reloads use cached rows instead of spending API quota. Quota checks are manual.

Saved data lives in:

- `ichiban_backlink_snapshots`
- `ichiban_backlink_rows`
- `ichiban_backlink_quota`

## SeoMaestro Migration

The migration tool:

- finds fields using `FieldtypeSeoMaestro`
- shows row counts and template usage
- creates backup tables
- converts stored SeoMaestro JSON to Ichiban data
- switches the field type to `FieldtypeIchiban`
- imports template-level metadata defaults and title formats
- leaves the potentially long audit rebuild as an explicit Audit/CLI step

Mapped fields include meta title, meta description, canonical URL, Open Graph fields, Twitter card, robots flags, and sitemap settings.

## Hooks

### Custom Source Expressions

Use `Ichiban::resolveSourceValue` to customize expression resolution.

```php
wire()->addHookAfter('Ichiban::resolveSourceValue', function(HookEvent $e) {
    $page = $e->arguments(0);
    $group = $e->arguments(1);
    $key = $e->arguments(2);
    $expression = $e->arguments(3);
});
```

### Final Resolved Values

Use `Ichiban::resolvedSeoValue` to adjust final values after page, template, global, and built-in fallbacks resolve. Dashboard stats, Audit, Bulk Editor, previews, and rendered tags all see this value.

```php
wire()->addHookAfter('Ichiban::resolvedSeoValue', function(HookEvent $e) {
    $page  = $e->arguments(0);
    $group = $e->arguments(1);
    $key   = $e->arguments(2);
    $value = $e->return;

    if (in_array($page->template->name, ['person', 'blog-post'], true) && $group === 'meta' && $key === 'description') {
        $source = $value !== '' ? $value : wire('sanitizer')->textarea($page->get('summary|body'));
        $e->return = wire('sanitizer')->truncate($source, 155);
    }

    if ($group === 'og' && $key === 'image' && $value === '' && $page->template->name === 'blog-post' && $page->images->count()) {
        $e->return = $page->images->first()->httpUrl;
    }
});
```

### Other Hooks

- `Ichiban::auditRules`
- `Ichiban::renderMetaTags`
- `Ichiban::renderSchemaGraph`
- `Ichiban::redirectMatch`
- `Ichiban::buildIdentity`

## Notes

- Prefer manual rendering while integrating with an existing theme.
- Do not use automatic injection together with template-level SEO tags.
- Google Search Console requires an OAuth client.
- Moz free API quota is small, so refresh intentionally.
- `llms.txt` support is optional because the convention is still experimental.
- Rank tracking and visual revision diffs are not included.
