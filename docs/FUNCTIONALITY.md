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

A field expression must be the complete source value. It cannot be
concatenated with literal text. For example, `field:title - Site Name` is
invalid because the resolver treats everything after `field:` as a field
specification. Use `field:title` as the source and add shared prefixes or
suffixes with **Title Format**.

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

Image expressions resolve image fields to URLs. For Open Graph images, Ichiban
uses the configured variation mode:

- `existing` reuses a prepared `1200×630` file and otherwise returns the
  original image without resizing during the request;
- `on_demand` creates the variation during resolution (legacy behavior);
- `original` always returns the original image.

Use `existing` on public sites and generate social-image variations through a
bounded, resumable media warmup process. Nested ProFields paths use dot
notation.

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
- trusted custom `<head>` and end-of-body markup

Manual rendering is recommended unless your templates do not already output SEO tags. Automatic injection can be enabled in settings.

### Custom Code

The **Custom Code** settings accept complete trusted markup for analytics,
consent, verification, and other global integrations. Head code is appended by
`renderHead()`, so it is also included when a template renders `$page->seo`:

```php
echo $page->seo;
```

Templates can render body-end code immediately before `</body>`:

```php
echo $modules->get('Ichiban')->renderBodyEnd();
```

Alternatively, enable the independent automatic body-end injection setting.
Do not enable automatic injection when the template already calls the method.
Both fields are output without sanitizing so JavaScript can execute. Access to
these settings must be restricted to trusted administrators.

Settings also include separate rendering toggles for frontend hreflang links and JSON-LD schema. Disable hreflang when ProcessWire languages are used internally but the public site should not advertise alternate language URLs. Disable JSON-LD when templates or another SEO layer already generates structured data.

### ProcessWire Languages

Ichiban resolves ProcessWire fields in the active user/page language, so
localized fields used by source expressions automatically provide localized
titles, descriptions, social copy, and image alt text. Localized page names
are used for canonical and social URLs when `LanguageSupportPageNames` is
installed. Frontend head output and sitemap entries can include localized
`hreflang` alternates.

Ichiban stores per-language page overrides inside the field's existing JSON
payload. The legacy top-level value is the default-language value; non-default
language values are keyed by ProcessWire language ID and fall back to the
default-language value when absent. Editors can override title/description
sources, canonical URL, Open Graph title/description/image/image alt, and raw
JSON-LD separately. Robots directives, social/schema types, Twitter creator,
and sitemap controls remain language-neutral because they describe shared page
behavior. Revisions capture and restore the complete language map.

The Bulk Editor writes the active administrator language. CLI `bulk-fix`,
`bulk-import`, and `page` commands accept `--ichiban-language=NAME|ID`; import
rows may contain a `language` column. Omitting the option preserves the
default-language behavior used before multilingual overrides were added.

### Title Format

Settings include a global **Title Format** field for rendered titles:

```text
{meta_title} · {tagline} · {site_name}
```

Supported placeholders:

- `{meta_title}`
- `{site_name}`
- `{entity_name}`
- `{host}`
- `{tagline}`
- `{parent_title}`
- `{template_title}`

For example, combine a field-driven title with a site suffix by setting
`meta_title` to `field:title` and Title Format to
`{meta_title} - {site_name}`. Do not append the suffix directly to the
`field:title` source expression.

The optional maximum title length trims only the page-specific title while
preserving the shared suffix. Meta descriptions have independent minimum and
maximum guards plus a page-aware fallback with `{title}`, `{site_name}`, and
`{tagline}` placeholders. Audit and Bulk Editor length checks use the rendered
values. Rebuild the audit index after changing these settings.

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
- **Settings**: identity, defaults, rendering, custom code, verification, Search Console, Moz, sitemap, robots/llms, reports, cleanup, and AI.

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

Use `--ichiban-help=command` for detailed help and `--ichiban-format=json` for machine-readable output. Bulk import CSV columns are `page_id,title,description,inherit_title,inherit_description,language`; `language` is optional. Destructive sitemap cleanup requires `--ichiban-force`.

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
- application-defined URL segments through `Ichiban::collectSitemapUrlSegments`
- noindex and page-level sitemap settings
- template and pattern exclusions
- manual generation
- LazyCron regeneration

Sitemap exclusion entries are literal URL substrings by default. Prefix an
entry with `regex:` to use an explicit regular expression, for example
`regex:~^/private(?:/|$)~`.

Ichiban also appends the configured sitemap URL when serving dynamic `robots.txt`.

ProcessWire records whether a template accepts URL segments or page numbers,
but it does not store the application-defined routes or result counts. Provide
enumerable routes from site code for Pages whose templates enable URL segments
or pagination. Provider results may be calculated dynamically during sitemap
generation:

```php
$wire->addHookAfter('Ichiban::collectSitemapUrlSegments', function(HookEvent $event) {
    /** @var Page $page */
    $page = $event->arguments(0);
    $routes = (array)$event->return;

    if ($page->template->name === 'blog-authors') {
        $authorRole = wire('roles')->get('blog-author');
        foreach (wire('users')->find("roles=$authorRole, sort=title") as $author) {
            $slug = wire('sanitizer')->pageName($author->title);
            if ($slug) $routes[] = ['segment' => $slug . '/', 'lastmod' => date('c', $author->modified)];
        }
    }

    if ($page->template->name === 'blog-posts') {
        $totalPages = (int)ceil(wire('pages')->count('template=blog-post') / 8);
        for ($n = 2; $n <= $totalPages; $n++) {
            $routes[] = wire('config')->pageNumUrlPrefix . $n . '/';
        }
    }

    $event->return = $routes;
});
```

Each returned item may be a relative segment string or an array containing
`segment` (or an absolute `loc`) with optional `lastmod`, `changefreq`,
`priority`, and `template` overrides. Segment entries inherit the base Page
metadata and pass through sitemap exclusions, validation, and deduplication.

## Search Console

The Insights section connects to Google Search Console with OAuth. It caches Search Analytics data and shows date ranges for 7, 28, 90, 180, and 365 days.

Page Indexing counts the URLs checked through URL Inspection. It shows
coverage, whether indexing is allowed, the last crawl, and both canonical URLs.
Use **Open Page Indexing** for the property-wide totals in Google Search
Console. Use **Check URL status** to inspect one public ProcessWire page, then
**Inspect in Google** to open its Search Console result and request indexing
manually.

To enable **Notify Google**, enable the Google Indexing API on a dedicated
Google Cloud project and authorize a dedicated service account as a delegated
owner of the appropriate Search Console property. Store its JSON credentials
outside the web root and source control. Set
`$config->ichibanGoogleIndexingCredentialsFile` in the local ProcessWire
configuration to the absolute path of the file, readable by PHP. The module
uses the credential only for the separate Indexing API, with the
`https://www.googleapis.com/auth/indexing` scope.

The action validates the public page and its eligible JSON-LD. By default
it checks the live HTTP response. A site can hook
`Ichiban::indexingEligibilityHead` to supply the same public canonical,
robots, and JSON-LD head from its trusted rendering code. Return null to
use HTTP, or an empty string to refuse a URL. Use this hook for sites
where outbound server requests to their own public host are filtered.
Confirm crawler reachability independently in Search Console. Eligible pages are individual job postings
with `JobPosting` or livestreams with `BroadcastEvent` in `VideoObject`.
Google's acceptance confirms receipt of the notification. Use **Check URL
status** to check the index independently.

[Google Indexing API prerequisites](https://developers.google.com/search/apis/indexing-api/v3/prereqs)
and [Page indexing report](https://support.google.com/webmasters/answer/7440203).

### Daily Indexing API budget

Insights shows the remaining budget for notifications submitted by this
Ichiban installation. Read the same values from cron with:

```sh
php index.php --ichiban-indexing-quota --ichiban-format=json
```

The command reports `limit`, `used`, `remaining`, and `resets_at`.
The default budget is 200. Set `$config->ichibanGoogleIndexingDailyLimit`
to match the project's quota or to keep a lower budget. It resets at
midnight in `America/Los_Angeles`. Every publish attempt reserves a unit
before contacting Google, including attempts with an uncertain outcome.
Preview and URL Inspection use separate APIs and do not reserve units.

The ledger starts counting when enabled. Enable it after the daily reset
or lower the first day's budget to account for requests already sent.
It uses the service account's project ID and a shared file lock. The
credential's directory is the default ledger directory. Use
`$config->ichibanGoogleIndexingQuotaDirectory` to provide a writable
directory outside the web root. The web process and cron must share this
directory and be able to read and write the ledger. Deploy multiple
instances with a common directory only when its filesystem supports
locking and atomic renames.

When a notification batch reaches the local limit it returns
`quota_exhausted: true` and leaves `next_offset` at the first unsent line.
A cron caller can resume at that offset after `resets_at`. This budget
covers Ichiban only. Google Cloud Monitoring quota metrics arrive with a
delay, and the Google API still enforces its project-wide quota.

### URL batches and sitemap eligibility

Use a deduplicated UTF-8 text file with one canonical public URL per line.

```sh
php index.php --ichiban-indexing=inspect --ichiban-url-file=urls.txt --ichiban-format=json
php index.php --ichiban-indexing=preview --ichiban-url-file=urls.txt --ichiban-format=json
php index.php --ichiban-indexing=notify --ichiban-url-file=eligible-urls.txt --ichiban-format=json
```

Each invocation reads up to 25 lines. Keep the file unchanged and resume with
`--ichiban-offset` set to the previous response's `next_offset`. The response
records errors per URL. Retry failed URLs from a separate input file.
Inspection supports public canonical ProcessWire pages across templates and
stores results in the existing Insights cache. Preview validates the public
markup without sending a notification. Notify revalidates each URL and sends
an update for eligible pages. Run notifications within the property's available
Google quota. Discover other indexable content through the XML sitemap and
use the Search Console inspection link for manual requests.

`Ichiban::isSitemapPageEligible(Page $page): bool` lets applications veto
inclusion using their content lifecycle. Return false after the hook to omit
the page and its generated URL segments. The default permits the page subject
to the existing sitemap settings. Content-specific publication rules belong
in the application hook.

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
