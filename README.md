# Ichiban

**SEO control center for ProcessWire CMS.**

Ichiban adds a page SEO field and a full SEO workspace to the ProcessWire admin. It handles meta tags, social previews, structured data, audits, redirects, sitemap generation, Search Console insights, backlink snapshots, revisions, cleanup tools, reports, migration from SeoMaestro, and AI-assisted SEO prompts.

![Ichiban](assets/Ichiban.png)

## Requirements

- ProcessWire 3.0.200+
- PHP 8.1+
- MySQL 5.7+ or MariaDB 10.3+

## Installation

1. Copy the `Ichiban/` folder to `site/modules/`.
2. In ProcessWire admin, run **Modules > Refresh**.
3. Install **Ichiban**.
4. Create an **Ichiban** field, usually named `seo`.
5. Add the field to editable templates.
6. Render SEO tags in your template:

```php
echo $page->seo;
```

Automatic head injection is available in module settings, but use it only when your templates do not already output SEO tags.

## Main Features

- Page-level SEO field with Meta, Social, Schema, Sitemap, and Advanced tabs.
- Source expressions for resolving values from ProcessWire fields, including nested ProFields paths such as `field:combo.image`.
- Global title formatting, for example `{meta_title} | {site_name}`.
- Dashboard with site score, issue counts, Search Console highlights, redirects, revisions, cleanup, and schema status.
- Bulk Editor for indexed page titles and descriptions.
- Audit index with rule checks, affected page links, CSV export, and hookable rules.
- Redirect manager with manual redirects, regex rules, status codes, hit counts, CSV import/export, and automatic redirects after path changes.
- Google Search Console insights and URL Inspection sampling.
- Moz backlink snapshots with cached history and quota snapshots.
- XML sitemap generator with images, hreflang alternates, custom URLs, exclusions, chunks, and LazyCron regeneration.
- Schema.org mapping builder.
- SeoMaestro migration tool for page data, template defaults and
  template-specific title formats.
- Scheduled report workspace with email tests and DOCX export.
- Squad-backed AI prompt workspace.
- CLI commands for audit rebuilds, sitemap maintenance, status checks, and generated text previews.
- Hook API for custom source resolution, final SEO values, audit rules, rendering, redirects, and identity schema.

## CLI

Run commands from the ProcessWire site root:

```bash
php index.php --ichiban-help
php index.php --ichiban-status
php index.php --ichiban-bulk-list --ichiban-issue=missing_title
php index.php --ichiban-bulk-fix=123 --ichiban-title="New SEO title"
php index.php --ichiban-audit-rebuild
php index.php --ichiban-sitemap-generate
```

Use `--ichiban-help=command` for command-specific help and `--ichiban-format=json` where structured output is useful.

## Documentation

See [docs/FUNCTIONALITY.md](docs/FUNCTIONALITY.md) for the functional overview, examples, hooks, and integration notes.

## Author

Ichiban is built and maintained by [Maxim Semenov](https://smnv.org).

## Support

If this project helps your work, consider supporting future development through [GitHub Sponsors](https://github.com/sponsors/mxmsmnv) or [smnv.org/sponsor](https://smnv.org/sponsor/).

## License

MIT
