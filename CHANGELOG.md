# Changelog

All notable changes to Ichiban will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).

## [0.1.0-alpha] - 2026-05-16

### Added
- Backlinks admin section backed by Moz Links API with links, linking domains, and anchor text views.
- Moz API token authentication using the `x-moz-token` header, with legacy Access ID / Secret Key fallback.
- Saved backlink snapshots in `ichiban_backlink_snapshots` and `ichiban_backlink_rows` so Backlinks reloads use cached data instead of spending Moz quota.
- Moz quota snapshots in `ichiban_backlink_quota`, with a manual Refresh quota action.
- AI test results now show finish reason and output token count when providers return that metadata.
- Empty OpenRouter responses now display a diagnostic message instead of an empty result panel.
- Single-page audit index refresh after Ichiban SEO field saves.

### Changed
- Backlinks refresh now defaults to 5 rows to protect small/free Moz API quotas.
- AI test requests now use the configured Max tokens setting instead of a hard-coded test limit.
- The AI settings button now opens Ichiban settings instead of Context module settings.
- Settings now persist the automatic head injection toggle.
- Page-edit saves now persist nested Ichiban SEO field data before revision diffs are recorded.
- README now documents every Ichiban admin section: Dashboard, Bulk Editor, Audit, Redirects, Insights, Sitemap, Schemas, Revisions, Cleanup, Migration, Reports, and AI.
- README now describes Ichiban's built-in XML sitemap generator instead of the old companion-module wording.
- README now documents the duplicate-tag risk when automatic head injection is used together with theme-level SEO tags.
- Tightened Ichiban admin navigation spacing.
- Improved Audit severity badge contrast and removed circular backgrounds from Issues/Affected counts.

### Fixed
- Automatic head injection could be enabled in the config form but was not saved by the Ichiban Settings UI.
- SEO revisions could miss page-edit changes when ProcessWire did not persist nested field value changes before the revision hook ran.
- Audit, Bulk Editor, and Dashboard data could remain stale after an individual page save until a manual Rebuild Index.

## [0.1.0-alpha-preview] - 2026-05-10

### Added
- Meta / OpenGraph / Twitter fields with typed source system (inherit / custom / field:name / field:name|truncate:N)
- SERP preview (desktop/mobile toggle) with live character counters
- Facebook, Twitter/X, LinkedIn social preview cards
- Schema.org JSON-LD graph: WebSite, WebPage, Article, BlogPosting, BreadcrumbList, Organization/Person, ImageObject
- Identity object (global Organization or Person) with sameAs social profiles
- Connected @id graph linking all schema nodes
- Site-wide SEO Audit with 9 rules (TitlePresent, TitleLength, TitleUnique, DescriptionPresent, DescriptionLength, DescriptionUnique, OgImagePresent, NoindexOnPublic, SchemaPresent)
- Scored audit report (0-100) with per-rule breakdown and CSV export
- ichiban_index table for fast audit queries without per-page loading
- Redirect Manager: 301, 302, 307, 410, 451; regex support; hit counter; import/export CSV
- Redirect rows include quick open links for source and target URLs
- Auto-redirect on page slug change (Pages::saveReady hook)
- Bulk SEO Editor: inline edit meta title and description for all pages
- robots.txt visual editor
- llms.txt generation (auto and manual mode)
- Webmaster verification: Google, Bing, Yandex
- Index Now ping on page publish
- hreflang alternate tags for LanguageSupportPageNames
- Native URL segment handling for canonical, og:url, twitter:url, and hreflang URLs
- SEO Revisions: JSON diff of changes on every save, restore to any revision, configurable max per page
- Email Reports: weekly or monthly via LazyCron + WireMail; includes score, top issues, GSC summary
- Crawl Cleanup: remove RSD, WLW manifest, shortlink, prev/next, generator tags
- Search Cleanup: block spam queries matching CJK/TALK:/QQ: patterns; log to ichiban_cleanup_log
- ProcessIchiban admin panel: Dashboard, Bulk Editor, Audit, Redirects, Insights, Sitemap, Schemas, Revisions, Cleanup, Migration, Reports, AI, and Settings
- Google Search Console integration: OAuth 2.0, property URL setting, clicks/impressions/CTR/position, 6-hour cache in ichiban_gsc_cache
- Built-in XML sitemap generator with public sitemap serving, template-specific files, image entries, hreflang alternates, custom URLs, exclusions, and LazyCron regeneration
- Hookable API: renderMetaTags, renderSchemaGraph, resolveSourceValue, auditRules, redirectMatch, buildIdentity

### Alpha limitations
- Google Search Console requires manual OAuth client configuration.
- Rank tracking, visual revision diffs, and deeper companion-module integrations are not included yet.
- llms.txt support is optional and experimental because the convention is not formally standardized.
