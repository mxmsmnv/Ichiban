<?php namespace ProcessWire;

require_once __DIR__ . '/IchibanAutoload.php';

/**
 * Ichiban (一番) — ProcessWire SEO Module
 *
 * @author Maxim Semenov <maxim@smnv.org> (smnv.org)
 * @license MIT
 * @version 0.1.6-alpha
 */
class Ichiban extends WireData implements Module, ConfigurableModule {

	protected array $_oldPaths = [];
	protected array $_oldSeoData = [];

	public static function getModuleInfo(): array {
		return [
			'title'    => 'Ichiban',
			'summary'  => 'Comprehensive SEO module: meta/OG/schema, audit, redirects, revisions, email reports.',
			'author'   => 'Maxim Semenov',
			'version'  => 16,
			'href'     => 'https://smnv.org',
			'singular' => true,
			'autoload' => true,
			'installs' => [
				'FieldtypeIchiban',
				'InputfieldIchiban',
				'ProcessIchiban',
			],
		];
	}

	// -------------------------------------------------------------------------
	// Init
	// -------------------------------------------------------------------------

	public function init(): void {
		$this->addHookBefore('ProcessPageView::execute', $this, 'hookUtilityTextFiles');
		$this->addHookBefore('ProcessPageView::execute', $this, 'hookRedirects');
		$this->addHookAfter('Fields::getCompatibleFieldtypes', $this, 'hookSeoMaestroCompatibility');
		$this->getSitemap()->init();
		$this->addHookAfter('Pages::saved', $this, 'hookPersistSeoPost');
		$this->addHookAfter('Pages::saved', $this, 'hookPageSaved');
		$this->addHookBefore('Pages::save', $this, 'hookCaptureOldPath');
		$this->addHookBefore('Pages::save', $this, 'hookCaptureOldSeoData');
		$this->addHookAfter('Pages::saved', $this, 'hookAutoRedirect');
	}

	public function ready(): void {
		// Optional head output hook (only on front-end).
		if (!$this->wire('page') || !$this->wire('page')->template || $this->wire('page')->template->name === 'admin') return;
		if ($this->get('auto_render_head')) {
			$this->addHookAfter('Page::render', $this, 'hookInjectHead');
		}
		// Crawl/Search cleanup
		try {
			$this->getCrawlCleanup()->init();
			$this->getSearchCleanup()->init();
			$this->getEmailReports()->init();
		} catch (\Throwable $e) {
			// Tables may not exist yet on first install
		}
		// Index Now on publish
		$this->addHookAfter('Pages::saved', $this, 'hookIndexNow');
	}

	// -------------------------------------------------------------------------
	// Hooks
	// -------------------------------------------------------------------------

	/**
	 * Allow existing SeoMaestro fields to be changed to Ichiban from ProcessWire's
	 * field type dropdown. FieldtypeIchiban also understands SeoMaestro's stored
	 * JSON shape so the switch does not require deleting the field first.
	 */
	protected function hookSeoMaestroCompatibility(HookEvent $e): void {
		/** @var Field $field */
		$field = $e->arguments(0);
		if (!$field || !$field->type) return;
		if (get_class($field->type) !== 'ProcessWire\\FieldtypeSeoMaestro') return;

		$fieldtypes = $e->return;
		if (!$fieldtypes instanceof WireArray) return;

		$ichiban = $this->wire('modules')->get('FieldtypeIchiban');
		if (!$ichiban || $fieldtypes->has($ichiban)) return;

		$fieldtypes->add($ichiban);
		$e->return = $fieldtypes;
	}

	/**
	 * Serve generated robots.txt and llms.txt when enabled.
	 */
	protected function hookUtilityTextFiles(HookEvent $e): void {
		$url = ltrim($this->wire('input')->url(), '/');
		if ($url === 'robots.txt' && $this->get('robots_enabled')) {
			header('Content-Type: text/plain; charset=utf-8');
			echo $this->renderRobotsTxt();
			exit;
		}
		if ($url === 'llms.txt' && $this->get('llms_enabled')) {
			header('Content-Type: text/plain; charset=utf-8');
			echo $this->renderLlmsTxt();
			exit;
		}
	}

	/**
	 * Intercept requests and fire redirect if a match exists.
	 */
	protected function hookRedirects(HookEvent $e): void {
		$url = $this->wire('input')->url();
		try {
			$redirect = $this->getRedirectManager()->match($url);
		} catch (\Throwable $ex) {
			$this->wire('log')->save('ichiban', 'hookRedirects error: ' . $ex->getMessage());
			return;
		}
		if (!$redirect) return;
		$type = (int)$redirect['type'];
		$to   = $redirect['to_url'];
		if ($type === 410 || $type === 451) {
			http_response_code($type);
			exit;
		}
		$this->wire('session')->redirect($to, $type);
	}

	/**
	 * After page save: store SEO revision diff.
	 */
	protected function hookPageSaved(HookEvent $e): void {
		/** @var Page $page */
		$page = $e->arguments(0);
		$fn   = $this->getSeoFieldName();
		if (!$page->hasField($fn)) return;
		$oldData = $this->_oldSeoData[$page->id] ?? null;
		unset($this->_oldSeoData[$page->id]);
		$this->getSeoRevisions()->saveDiffWithSnapshot($page, $oldData);
		$this->refreshAuditIndexForPage($page);
	}

	/**
	 * Persist Ichiban page-edit POST even when ProcessWire misses nested value
	 * object changes. This keeps source modes, checkboxes, and text fields reliable.
	 */
	protected function hookPersistSeoPost(HookEvent $e): void {
		if (!$this->wire('page') || $this->wire('page')->template->name !== 'admin') return;
		/** @var Page $page */
		$page = $e->arguments(0);
		if (!$page || !$page->id) return;
		$fn = $this->getSeoFieldName();
		if (!$page->hasField($fn)) return;

		$post = $this->wire('input')->post($fn);
		if ($post instanceof WireInputData) $post = $post->getArray();
		if (!is_array($post)) return;
		if (!array_key_exists('meta_title_mode', $post)
			&& !array_key_exists('meta_title', $post)
			&& !array_key_exists('meta_noindex', $post)
			&& !array_key_exists('sitemap_include', $post)) return;

		$seo = $page->getUnformatted($fn);
		if (!$seo instanceof \IchibanPageFieldValue) {
			$field = $this->wire('fields')->get($fn);
			$seo = $this->getBlankValue($page, $field instanceof Field ? $field : null);
		}
		$data = $this->dataFromPageEditPost($seo->getData(), $post);
		$seo->setData($data);
		$page->set($fn, $seo);

		$field = $this->wire('fields')->get($fn);
		$fieldtype = $field && $field->type instanceof FieldtypeIchiban ? $field->type : $this->wire('modules')->get('FieldtypeIchiban');
		if ($field && $fieldtype) {
			$fieldtype->savePageField($page, $field);
			if ($this->wire('input')->get('ichiban_debug')) {
				$this->wire('log')->save('ichiban-debug', 'forced-save ' . json_encode([
					'page_id' => (int)$page->id,
					'field' => $fn,
					'data' => $data,
				], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
			}
		}
	}

	protected function dataFromPageEditPost(array $data, array $post): array {
		$san = $this->wire('sanitizer');
		foreach (['meta_title', 'meta_description', 'og_title', 'og_description'] as $key) {
			$source = null;
			if (isset($post[$key]) && is_array($post[$key])) {
				$source = $post[$key];
			} elseif (array_key_exists("{$key}_mode", $post) || array_key_exists("{$key}_value", $post)) {
				$source = [
					'mode' => $post["{$key}_mode"] ?? '',
					'value' => $post["{$key}_value"] ?? '',
				];
			}
			if (!is_array($source)) continue;
			$data[$key] = [
				'mode' => in_array($source['mode'] ?? '', ['inherit', 'field', 'custom'], true) ? $source['mode'] : 'field',
				'value' => $san->text($source['value'] ?? ''),
			];
		}

		foreach (['og_image_alt', 'og_type', 'twitter_card',
		          'twitter_creator', 'schema_type', 'sitemap_priority', 'sitemap_changefreq', 'robots_meta'] as $key) {
			if (array_key_exists($key, $post)) $data[$key] = $san->text($post[$key]);
		}
		if (array_key_exists('canonical_url', $post)) {
			$data['canonical_url'] = $san->url($post['canonical_url'], ['allowRelative' => true, 'allowSchemes' => ['http', 'https']]);
		}
		if (array_key_exists('og_image', $post)) {
			$data['og_image'] = $this->sanitizeUrlOrSourceExpression($post['og_image']);
		}
		foreach (['meta_noindex', 'meta_nofollow', 'sitemap_include'] as $key) {
			$data[$key] = !empty($post[$key]);
		}
		if (array_key_exists('jsonld_override', $post)) {
			$data['jsonld_override'] = $san->textarea($post['jsonld_override']);
		}
		return $data;
	}

	protected function sanitizeUrlOrSourceExpression(mixed $value): string {
		$value = trim((string)$value);
		if ($value === '') return '';

		$fieldPath = '[A-Za-z0-9_][A-Za-z0-9_:.]*(?:\|[A-Za-z0-9_:-]+)*';
		if (preg_match('/^(?:field:)?' . $fieldPath . '$/', $value) || preg_match('/^\{' . $fieldPath . '\}$/', $value)) {
			return $this->wire('sanitizer')->text($value);
		}

		return $this->wire('sanitizer')->url($value, ['allowRelative' => true, 'allowSchemes' => ['http', 'https']]);
	}

	/** Capture seo data snapshot before page save for revision diff. */
	protected function hookCaptureOldSeoData(HookEvent $e): void {
		/** @var Page $page */
		$page = $e->arguments(0);
		$fn   = $this->getSeoFieldName();
		if (!$page->hasField($fn) || !$page->id) return;
		$seo = $page->getUnformatted($fn);
		if ($seo instanceof \IchibanPageFieldValue) {
			$this->_oldSeoData[$page->id] = $seo->getData();
		}
	}

	/** Capture old path before slug change. */
	protected function hookCaptureOldPath(HookEvent $e): void {
		/** @var Page $page */
		$page = $e->arguments(0);
		if ($page->isNew() || !$page->id || !$page->isChanged('name')) return;
		// Store old path keyed by page ID before PW changes it
		$this->_oldPaths[$page->id] = $page->path;
	}

	/** After page saved: create 301 if slug changed. */
	protected function hookAutoRedirect(HookEvent $e): void {
		/** @var Page $page */
		$page = $e->arguments(0);
		if ($page->isNew() || empty($this->_oldPaths[$page->id])) return;
		$oldPath = $this->_oldPaths[$page->id];
		unset($this->_oldPaths[$page->id]);
		if ($oldPath === $page->path) return;
		$this->getRedirectManager()->createAutoRedirect($oldPath, $page);
	}

	protected function refreshAuditIndexForPage(Page $page): void {
		try {
			$this->getAuditEngine()->refreshPage($page);
		} catch (\Throwable $e) {
			$this->wire('log')->save('ichiban-audit', 'Page index refresh failed for page ' . (int)$page->id . ': ' . $e->getMessage());
		}
	}

	/**
	 * Inject <head> tags into page output.
	 */
	protected function hookInjectHead(HookEvent $e): void {
		$html = $e->return;
		if (!is_string($html) || stripos($html, '</head>') === false || str_contains($html, '<!-- Ichiban SEO -->')) return;
		$page = $e->object;
		$meta = $this->renderHead($page);
		if ($meta === '') return;
		$e->return = preg_replace('~</head>~i', $meta . "\n</head>", $html, 1) ?? $html;
	}

	/**
	 * Ping IndexNow on page save.
	 */
	protected function hookIndexNow(HookEvent $e): void {
		/** @var Page $page */
		$page = $e->arguments(0);
		if (!$page->isPublic()) return;
		$apiKey = $this->get('indexnow_key');
		if (!$apiKey) return;
		$templates = $this->get('indexnow_templates') ?: [];
		if (!is_array($templates)) $templates = [];
		if ($templates && !in_array($page->template->name, $templates)) return;
		$this->pingIndexNow($page->httpUrl(), $apiKey);
	}

	// -------------------------------------------------------------------------
	// Public API
	// -------------------------------------------------------------------------

	/**
	 * Render all <head> SEO tags for a page.
	 *
	 * Usage in templates: echo $page->seo
	 */
	public function renderHead(Page $page): string {
		$out = '';
		// Meta
		$out .= $this->renderMetaTags($page);
		// Schema graph
		$out .= $this->renderSchemaGraph($page);
		// Webmaster verification (global, home page only)
		$homeId = $this->wire('pages')->get('/')->id;
		if ($page->id === $homeId) {
			$out .= $this->renderVerificationTags();
			$out .= $this->renderFacebookPixel();
		}
		return $out;
	}

	/**
	 * Hookable: render meta/OG/Twitter tags.
	 *
	 * @hook Ichiban::renderMetaTags
	 */
	public function ___renderMetaTags(Page $page): string {
		$fn = $this->getSeoFieldName();
		if (!$page->hasField($fn)) return '';
		/** @var \IchibanPageFieldValue $seo */
		$seo = $page->get($fn);
		$out = "\n<!-- Ichiban SEO -->\n";
		$out .= '<meta charset="utf-8">' . "\n";
		// Meta title
		$title = $seo->meta->title;
		$renderedTitle = $this->formatMetaTitle((string)$title);
		$out .= '<title>' . $this->wire('sanitizer')->entities($renderedTitle) . '</title>' . "\n";
		$out .= '<meta name="description" content="' . $this->wire('sanitizer')->entities($seo->meta->description) . '">' . "\n";
		// Robots
		$robotsParts = [];
		if ($seo->meta->noindex) $robotsParts[] = 'noindex';
		if ($seo->meta->nofollow) $robotsParts[] = 'nofollow';
		// Custom robots meta override takes precedence over checkboxes
		$customRobots = $seo->advanced->robots_meta;
		if ($customRobots) {
			$out .= '<meta name="robots" content="' . $this->wire('sanitizer')->entities($customRobots) . '">' . "\n";
		} elseif ($robotsParts) {
			$out .= '<meta name="robots" content="' . implode(',', $robotsParts) . '">' . "\n";
		}
		// Canonical
		$canonical = $seo->meta->canonical ?: $this->pageHttpUrl($page);
		$out .= '<link rel="canonical" href="' . $this->wire('sanitizer')->entities($canonical) . '">' . "\n";
		// OpenGraph
		$out .= '<meta property="og:title" content="' . $this->wire('sanitizer')->entities($seo->og->title ?: $title) . '">' . "\n";
		$out .= '<meta property="og:description" content="' . $this->wire('sanitizer')->entities($seo->og->description ?: $seo->meta->description) . '">' . "\n";
		$out .= '<meta property="og:url" content="' . $this->wire('sanitizer')->entities($canonical) . '">' . "\n";
		$out .= '<meta property="og:type" content="' . $this->wire('sanitizer')->entities($seo->og->type ?: 'website') . '">' . "\n";
		$userLang = $this->wire('user')->language ?? null;
		$locale = $userLang instanceof Language ? $this->localeForLanguage($userLang) : '';
		if ($locale) {
			$out .= '<meta property="og:locale" content="' . $this->wire('sanitizer')->entities(str_replace('-', '_', $locale)) . '">' . "\n";
		}
		if ($seo->og->image) {
			$out .= '<meta property="og:image" content="' . $this->wire('sanitizer')->entities($seo->og->image) . '">' . "\n";
			if ($seo->og->image_alt) {
				$out .= '<meta property="og:image:alt" content="' . $this->wire('sanitizer')->entities($seo->og->image_alt) . '">' . "\n";
			}
		}
		// hreflang (multilingual)
		if ($this->isHeadFeatureEnabled('render_hreflang')) {
			$out .= $this->renderHreflang($page);
		}
		// Twitter/X
		$twitterCard = $seo->twitter->card ?: 'summary_large_image';
		$out .= '<meta name="twitter:card" content="' . $this->wire('sanitizer')->entities($twitterCard) . '">' . "\n";
		$out .= '<meta name="twitter:url" content="' . $this->wire('sanitizer')->entities($canonical) . '">' . "\n";
		$twitterSite = $seo->twitter->site ?: $this->get('twitter_site');
		if ($twitterSite) {
			$out .= '<meta name="twitter:site" content="' . $this->wire('sanitizer')->entities($twitterSite) . '">' . "\n";
		}
		if ($seo->twitter->creator) {
			$out .= '<meta name="twitter:creator" content="' . $this->wire('sanitizer')->entities($seo->twitter->creator) . '">' . "\n";
		}
		return $out;
	}

	/**
	 * Hookable: render Schema.org JSON-LD graph.
	 *
	 * @hook Ichiban::renderSchemaGraph
	 */
	public function ___renderSchemaGraph(Page $page): string {
		if (!$this->isHeadFeatureEnabled('render_jsonld')) return '';
		$fn = $this->getSeoFieldName();
		if (!$page->hasField($fn)) return '';
		$seo = $page->get($fn);
		if ($seo && $seo->advanced->jsonld_override) {
			return '<script type="application/ld+json">' . "\n" . trim($seo->advanced->jsonld_override) . "\n</script>\n";
		}
		$graph = $this->getSchemaGraph()->build($page);
		if (empty($graph)) return '';
		return '<script type="application/ld+json">' . "\n"
			. json_encode(['@context' => 'https://schema.org', '@graph' => $graph], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
			. "\n</script>\n";
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	protected function renderHreflang(Page $page): string {
		$out = '';
		$languages = $this->wire('languages');
		if (!$languages) return $out;
		foreach ($languages as $lang) {
			/** @var Language $lang */
			$langUrl = $this->pageHttpUrl($page, $lang);
			if ($lang->name === 'default') {
				// Default language gets x-default plus its actual locale
				$out .= '<link rel="alternate" hreflang="x-default" href="' . $this->wire('sanitizer')->entities($langUrl) . '">' . "\n";
				$locale = $this->localeForLanguage($lang);
				if ($locale && $locale !== 'default') {
					$out .= '<link rel="alternate" hreflang="' . $locale . '" href="' . $this->wire('sanitizer')->entities($langUrl) . '">' . "\n";
				}
			} else {
				$locale = $this->localeForLanguage($lang);
				$out .= '<link rel="alternate" hreflang="' . $locale . '" href="' . $this->wire('sanitizer')->entities($langUrl) . '">' . "\n";
			}
		}
		return $out;
	}

	protected function isHeadFeatureEnabled(string $key): bool {
		$value = $this->get($key);
		if ($value === null || $value === '') return true;
		return (bool)$value;
	}

	protected function localeForLanguage(Language $lang): string {
		// Try to get ISO locale from language field, fall back to language name
		return $lang->get('locale') ?: str_replace('_', '-', $lang->name);
	}

	public function formatMetaTitle(string $title): string {
		$format = trim((string)$this->get('title_format'));
		if ($format === '') return $title;
		if (!str_contains($format, '{meta_title}')) {
			$format = '{meta_title}' . $format;
		}
		$siteName = (string)($this->get('entity_name') ?: $this->siteSetting('brand_name', $this->wire('config')->httpHost));
		$replacements = [
			'{meta_title}' => $title,
			'{site_name}' => $siteName,
			'{entity_name}' => (string)($this->get('entity_name') ?: $this->siteSetting('brand_name', '')),
			'{host}' => (string)$this->wire('config')->httpHost,
		];
		return trim(strtr($format, $replacements));
	}

	public function pageHttpUrl(Page $page, ?Language $language = null, bool $includeSegments = true): string {
		$url = $language ? $this->pageLanguageHttpUrl($page, $language) : $page->httpUrl();
		if (!$includeSegments || !$this->shouldPreserveUrlSegments($page)) return $url;
		$segments = $this->currentUrlSegmentString($page);
		if ($segments === '') return $url;
		return rtrim($url, '/') . '/' . $segments . '/';
	}

	protected function pageLanguageHttpUrl(Page $page, Language $language): string {
		if (is_callable([$page, 'localHttpUrl'])) {
			try {
				$url = (string)$page->localHttpUrl($language);
				if ($url !== '') return $url;
			} catch (\Throwable $e) {
				// Fall through for ProcessWire versions without Page::localHttpUrl().
			}
		}

		if (is_callable([$page, 'localUrl'])) {
			try {
				$localUrl = (string)$page->localUrl($language);
				if ($localUrl !== '') {
					if (preg_match('{^https?://}i', $localUrl)) return $localUrl;
					if (preg_match('{^https?://[^/]+}i', (string)$page->httpUrl(), $matches)) {
						return rtrim($matches[0], '/') . '/' . ltrim($localUrl, '/');
					}
				}
			} catch (\Throwable $e) {
				// Fall through when LanguageSupportPageNames is not installed.
			}
		}

		return (string)$page->httpUrl();
	}

	protected function shouldPreserveUrlSegments(Page $page): bool {
		if (($this->get('url_segments_mode') ?: 'preserve') !== 'preserve') return false;
		$current = $this->wire('page');
		return $current instanceof Page && $current->id && $page->id && $current->id === $page->id;
	}

	protected function currentUrlSegmentString(Page $page): string {
		if (!$page->template || !$page->template->urlSegments) return '';
		$segments = trim((string)$this->wire('input')->urlSegmentStr, '/');
		if ($segments === '') return '';
		return implode('/', array_map('rawurlencode', array_filter(explode('/', $segments), 'strlen')));
	}

	protected function renderVerificationTags(): string {
		$out = '';
		$metaTags = [
			'verify_bing' => 'msvalidate.01',
			'verify_yandex' => 'yandex-verification',
			'verify_baidu' => 'baidu-site-verification',
			'verify_sogou' => 'sogou_site_verification',
			'verify_360' => '360-site-verification',
			'verify_pinterest' => 'p:domain_verify',
			'verify_facebook_domain' => 'facebook-domain-verification',
		];
		foreach ($metaTags as $configKey => $metaName) {
			$value = trim((string)$this->get($configKey));
			if ($value === '') continue;
			$out .= '<meta name="' . $this->wire('sanitizer')->entities($metaName) . '" content="' . $this->wire('sanitizer')->entities($value) . '">' . "\n";
		}
		foreach ($this->customVerificationTags() as [$name, $content]) {
			$out .= '<meta name="' . $this->wire('sanitizer')->entities($name) . '" content="' . $this->wire('sanitizer')->entities($content) . '">' . "\n";
		}
		return $out;
	}

	protected function customVerificationTags(): array {
		$tags = [];
		$lines = preg_split('/\R+/', (string)$this->get('verify_custom_meta'));
		foreach ($lines ?: [] as $line) {
			$line = trim($line);
			if ($line === '' || !str_contains($line, '=')) continue;
			[$name, $content] = array_map('trim', explode('=', $line, 2));
			if (!preg_match('/^[A-Za-z0-9._:-]+$/', $name) || $content === '') continue;
			$tags[] = [$name, $content];
		}
		return $tags;
	}

	protected function renderFacebookPixel(): string {
		$pixelId = trim((string)$this->get('facebook_pixel_id'));
		if ($pixelId === '' || !preg_match('/^[0-9]{5,32}$/', $pixelId)) return '';
		$id = $this->wire('sanitizer')->entities($pixelId);
		return "<!-- Meta Pixel Code -->\n"
			. "<script>!function(f,b,e,v,n,t,s){if(f.fbq)return;n=f.fbq=function(){n.callMethod?"
			. "n.callMethod.apply(n,arguments):n.queue.push(arguments)};if(!f._fbq)f._fbq=n;"
			. "n.push=n;n.loaded=!0;n.version='2.0';n.queue=[];t=b.createElement(e);t.async=!0;"
			. "t.src=v;s=b.getElementsByTagName(e)[0];s.parentNode.insertBefore(t,s)}"
			. "(window,document,'script','https://connect.facebook.net/en_US/fbevents.js');"
			. "fbq('init','{$id}');fbq('track','PageView');</script>\n"
			. "<noscript><img height=\"1\" width=\"1\" style=\"display:none\" src=\"https://www.facebook.com/tr?id={$id}&ev=PageView&noscript=1\"></noscript>\n";
	}

	public function renderRobotsTxt(): string {
		$text = (string)($this->get('robots_text') ?: "User-agent: *\nAllow: /\n");
		if (!str_contains($text, 'Sitemap:')) {
			$text = rtrim($text) . "\nSitemap: " . $this->getSitemapUrl() . "\n";
		}
		return rtrim($text) . "\n";
	}

	public function getSitemapUrl(): string {
		$dir = trim((string)($this->getSitemap()->setting('sitemap_dir') ?: \IchibanSitemap::DEFAULT_DIR), '/');
		return $this->siteUrl() . '/' . $dir . '/sitemap.xml';
	}

	public function renderLlmsTxt(): string {
		$siteName = $this->get('entity_name') ?: $this->siteSetting('brand_name', $this->wire('config')->httpHost);
		$out = "# {$siteName}\n\n";
		$mode = $this->get('llms_mode') ?: 'auto';
		if ($mode === 'manual') {
			$lines = preg_split('/\R+/', (string)$this->get('llms_manual_urls'));
			foreach (array_filter(array_map('trim', $lines)) as $line) {
				$out .= "- {$line}\n";
			}
			return $out;
		}
		$templates = array_filter(array_map('trim', explode(',', (string)$this->get('llms_templates'))));
		$selector = 'status<' . Page::statusUnpublished . ', include=hidden, check_access=0, limit=200, sort=path';
		if ($templates) $selector = 'template=' . implode('|', $templates) . ', ' . $selector;
		try {
			foreach ($this->wire('pages')->find($selector) as $page) {
				if (!$page->viewable()) continue;
				$title = $page->title ?: $page->name;
				$out .= "- [{$title}]({$page->httpUrl()})\n";
			}
		} catch (\Throwable $e) {
			$out .= "- " . $this->siteUrl() . "/\n";
		}
		return $out;
	}

	public function siteUrl(): string {
		$root = (string)($this->wire('config')->urls->httpRoot ?? '');
		if ($root) return rtrim($root, '/');
		$host = (string)$this->wire('config')->httpHost;
		return $host ? 'https://' . trim($host, '/') : '';
	}

	public function siteSettings(): array {
		$settings = $this->get('website_settings') ?: [];
		if (is_string($settings)) $settings = json_decode($settings, true) ?: [];
		if (!is_array($settings)) return [];
		$custom = $settings['custom'] ?? [];
		if (is_string($custom)) $custom = json_decode($custom, true) ?: [];
		if (is_array($custom)) {
			unset($settings['custom']);
			$settings = array_merge($custom, $settings);
		}
		return $settings;
	}

	public function websiteSettings(): array {
		return $this->siteSettings();
	}

	public function siteSetting(string $key, $default = null) {
		$settings = $this->siteSettings();
		$value = $settings[$key] ?? null;
		return ($value === null || $value === '') ? $default : $value;
	}

	protected function pingIndexNow(string $url, string $apiKey): void {
		$endpoint = "https://api.indexnow.org/indexnow?url=" . urlencode($url) . "&key=" . urlencode($apiKey);
		// Non-blocking ping via HTTP
		$http = $this->wire(new WireHttp());
		$http->get($endpoint);
	}

	public function generateIndexNowKey(): string {
		return bin2hex(random_bytes(16));
	}

	public function writeIndexNowKeyFile(?string $apiKey = null): bool {
		$key = trim((string)($apiKey ?: $this->get('indexnow_key')));
		if ($key === '' || !preg_match('/^[A-Za-z0-9-]{8,128}$/', $key)) return false;
		$path = rtrim($this->wire('config')->paths->root, '/') . '/' . $key . '.txt';
		return file_put_contents($path, $key . "\n", LOCK_EX) !== false;
	}

	/**
	 * Hookable: resolve a typed source expression to a string value.
	 *
	 * @hook Ichiban::resolveSourceValue
	 */
	public function ___resolveSourceValue(Page $page, string $group, string $key, string $expression): string {
		$resolver = new \IchibanSourceResolver($this);
		return $resolver->resolve($page, $group, $key, $expression);
	}

	/**
	 * Hookable: customize the final resolved SEO value.
	 *
	 * Runs after page/template/global defaults and built-in fallbacks, so Audit,
	 * Bulk Editor, previews, and rendered tags all see the adjusted value.
	 *
	 * @hook Ichiban::resolvedSeoValue
	 */
	public function ___resolvedSeoValue(Page $page, string $group, string $key, string $value): string {
		return $value;
	}

	/** Hookable: customize audit rules before report/index checks run. */
	public function ___auditRules(array $rules): array {
		return $rules;
	}

	/** Hookable: customize redirect match result. Return null to skip. */
	public function ___redirectMatch(string $url, ?array $redirect): ?array {
		return $redirect;
	}

	/** Hookable: customize JSON-LD Identity node. */
	public function ___buildIdentity(array $identity): array {
		return $identity;
	}

	/**
	 * Proxy to FieldtypeIchiban::getBlankValue — used by InputfieldIchiban.
	 */
	public function ___getBlankValue(Page $page, ?Field $field = null): \IchibanPageFieldValue {
		$ft = $this->wire('modules')->get('FieldtypeIchiban');
		if (!$field) $field = new Field();
		return $ft->getBlankValue($page, $field);
	}

	// -------------------------------------------------------------------------
	// SEO field resolution
	// -------------------------------------------------------------------------

	protected ?string $_seoFieldName = null;

	/**
	 * Find the name of the field that uses FieldtypeIchiban.
	 * Falls back to 'seo' for backwards compatibility.
	 */
	public function getSeoFieldName(): string {
		if ($this->_seoFieldName !== null) return $this->_seoFieldName;
		foreach (['seo', 'ichiban'] as $preferred) {
			$field = $this->wire('fields')->get($preferred);
			if ($field && $field->type instanceof FieldtypeIchiban) {
				$this->_seoFieldName = $field->name;
				return $field->name;
			}
		}
		foreach ($this->wire('fields') as $f) {
			if ($f->type instanceof FieldtypeIchiban) {
				$this->_seoFieldName = $f->name;
				return $f->name;
			}
		}
		$this->_seoFieldName = 'seo';
		return 'seo';
	}

	// -------------------------------------------------------------------------
	// Service locators (lazy init)
	// -------------------------------------------------------------------------

	protected ?\IchibanSchemaGraph $_schemaGraph = null;
	protected ?\IchibanRedirectManager $_redirectManager = null;
	protected ?\IchibanSeoRevisions $_seoRevisions = null;
	protected ?\IchibanCrawlCleanup $_crawlCleanup = null;
	protected ?\IchibanSearchCleanup $_searchCleanup = null;
	protected ?\IchibanEmailReports $_emailReports = null;
	protected ?\IchibanOpenRouter $_openRouter = null;
	protected ?\IchibanSearchStatistics $_searchStatistics = null;
	protected ?\IchibanBacklinks $_backlinks = null;
	protected ?\IchibanBacklinksMoz $_backlinksMoz = null;
	protected ?\IchibanSitemap $_sitemap = null;
	protected ?\IchibanAuditEngine $_auditEngine = null;
	protected ?\IchibanUpdater $_updater = null;

	public function getSchemaGraph(): \IchibanSchemaGraph {
		if (!$this->_schemaGraph) $this->_schemaGraph = new \IchibanSchemaGraph($this);
		return $this->_schemaGraph;
	}

	public function getRedirectManager(): \IchibanRedirectManager {
		if (!$this->_redirectManager) $this->_redirectManager = new \IchibanRedirectManager($this);
		return $this->_redirectManager;
	}

	public function getSeoRevisions(): \IchibanSeoRevisions {
		if (!$this->_seoRevisions) $this->_seoRevisions = new \IchibanSeoRevisions($this);
		return $this->_seoRevisions;
	}

	public function getCrawlCleanup(): \IchibanCrawlCleanup {
		if (!$this->_crawlCleanup) $this->_crawlCleanup = new \IchibanCrawlCleanup($this);
		return $this->_crawlCleanup;
	}

	public function getSearchCleanup(): \IchibanSearchCleanup {
		if (!$this->_searchCleanup) $this->_searchCleanup = new \IchibanSearchCleanup($this);
		return $this->_searchCleanup;
	}

	public function getEmailReports(): \IchibanEmailReports {
		if (!$this->_emailReports) $this->_emailReports = new \IchibanEmailReports($this);
		return $this->_emailReports;
	}

	public function getOpenRouter(): \IchibanOpenRouter {
		if (!$this->_openRouter) $this->_openRouter = new \IchibanOpenRouter($this);
		return $this->_openRouter;
	}

	public function getSearchStatistics(): \IchibanSearchStatistics {
		if (!$this->_searchStatistics) $this->_searchStatistics = new \IchibanSearchStatistics($this);
		return $this->_searchStatistics;
	}

	public function getBacklinksMoz(): \IchibanBacklinksMoz {
		if (!$this->_backlinksMoz) $this->_backlinksMoz = new \IchibanBacklinksMoz($this);
		return $this->_backlinksMoz;
	}

	public function getBacklinks(): \IchibanBacklinks {
		if (!$this->_backlinks) $this->_backlinks = new \IchibanBacklinks($this);
		return $this->_backlinks;
	}

	public function getSitemap(): \IchibanSitemap {
		if (!$this->_sitemap) $this->_sitemap = new \IchibanSitemap($this);
		return $this->_sitemap;
	}

	public function getAuditEngine(): \IchibanAuditEngine {
		if (!$this->_auditEngine) $this->_auditEngine = new \IchibanAuditEngine($this);
		return $this->_auditEngine;
	}

	public function getUpdater(): \IchibanUpdater {
		if (!$this->_updater) $this->_updater = new \IchibanUpdater($this);
		return $this->_updater;
	}

	// -------------------------------------------------------------------------
	// Install / Uninstall
	// -------------------------------------------------------------------------

	public function ___install(): void {
		$this->createDatabaseTables();
	}

	public function ___uninstall(): void {
		// Tables dropped only if all data can be regenerated
		// Redirect table is preserved by default to avoid data loss
	}

	protected function createDatabaseTables(): void {
		$db = $this->wire('database');

		$db->exec("CREATE TABLE IF NOT EXISTS `ichiban_index` (
			`id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
			`page_id`          INT UNSIGNED NOT NULL,
			`template_name`    VARCHAR(128) NOT NULL DEFAULT '',
				`url`              VARCHAR(1024) NOT NULL DEFAULT '',
				`canonical_url`    VARCHAR(1024) NOT NULL DEFAULT '',
				`meta_title`       VARCHAR(255) NOT NULL DEFAULT '',
			`meta_description` VARCHAR(512) NOT NULL DEFAULT '',
			`meta_title_len`   SMALLINT UNSIGNED NOT NULL DEFAULT 0,
			`meta_desc_len`    SMALLINT UNSIGNED NOT NULL DEFAULT 0,
			`is_noindex`       TINYINT(1) NOT NULL DEFAULT 0,
			`has_og_image`     TINYINT(1) NOT NULL DEFAULT 0,
			`schema_type`      VARCHAR(64) NOT NULL DEFAULT '',
			`word_count`       SMALLINT UNSIGNED NOT NULL DEFAULT 0,
			`indexed_at`       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (`id`),
			UNIQUE KEY `page_id` (`page_id`),
			KEY `template_name` (`template_name`),
			KEY `is_noindex` (`is_noindex`)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

		$db->exec("CREATE TABLE IF NOT EXISTS `ichiban_redirects` (
			`id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
			`from_url`   VARCHAR(1024) NOT NULL DEFAULT '',
			`to_url`     VARCHAR(1024) NOT NULL DEFAULT '',
			`type`       SMALLINT UNSIGNED NOT NULL DEFAULT 301,
			`is_regex`   TINYINT(1) NOT NULL DEFAULT 0,
			`hits`       INT UNSIGNED NOT NULL DEFAULT 0,
			`last_hit`   DATETIME NULL DEFAULT NULL,
			`note`       VARCHAR(255) NOT NULL DEFAULT '',
			`created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
			`auto`       TINYINT(1) NOT NULL DEFAULT 0,
			PRIMARY KEY (`id`),
			KEY `from_url` (`from_url`(191))
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

		$db->exec("CREATE TABLE IF NOT EXISTS `ichiban_revisions` (
			`id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
			`page_id`    INT UNSIGNED NOT NULL,
			`changes`    TEXT NOT NULL,
			`user_id`    INT UNSIGNED NOT NULL DEFAULT 0,
			`created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (`id`),
			KEY `page_id` (`page_id`)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

		$db->exec("CREATE TABLE IF NOT EXISTS `ichiban_gsc_cache` (
			`id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
			`page_url`    VARCHAR(1024) NOT NULL DEFAULT '',
			`query`       VARCHAR(512) NOT NULL DEFAULT '',
			`clicks`      INT UNSIGNED NOT NULL DEFAULT 0,
			`impressions` INT UNSIGNED NOT NULL DEFAULT 0,
			`ctr`         DECIMAL(6,4) NOT NULL DEFAULT 0,
			`position`    DECIMAL(8,2) NOT NULL DEFAULT 0,
			`date_range`  VARCHAR(64) NOT NULL DEFAULT '',
			`cached_at`   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (`id`),
			UNIQUE KEY `page_query` (`page_url`(191), `query`(191)),
			KEY `cached_at` (`cached_at`)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

		$db->exec("CREATE TABLE IF NOT EXISTS `ichiban_gsc_indexing_cache` (
			`id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
			`url`              VARCHAR(1024) NOT NULL DEFAULT '',
			`verdict`          VARCHAR(64) NOT NULL DEFAULT '',
			`coverage_state`   VARCHAR(255) NOT NULL DEFAULT '',
			`indexing_state`   VARCHAR(64) NOT NULL DEFAULT '',
			`last_crawl_time`  VARCHAR(64) NOT NULL DEFAULT '',
			`google_canonical` VARCHAR(1024) NOT NULL DEFAULT '',
			`user_canonical`   VARCHAR(1024) NOT NULL DEFAULT '',
			`inspection_link`  VARCHAR(1024) NOT NULL DEFAULT '',
			`checked_at`       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (`id`),
			UNIQUE KEY `url` (`url`(191)),
			KEY `coverage_state` (`coverage_state`(191)),
			KEY `checked_at` (`checked_at`)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

			$db->exec("CREATE TABLE IF NOT EXISTS `ichiban_cleanup_log` (
				`id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
				`query`      VARCHAR(512) NOT NULL DEFAULT '',
				`pattern`    VARCHAR(255) NOT NULL DEFAULT '',
				`ip`         VARCHAR(45) NOT NULL DEFAULT '',
				`created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY (`id`),
				KEY `created_at` (`created_at`)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

			$db->exec("CREATE TABLE IF NOT EXISTS `ichiban_schemas` (
				`id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
				`name`        VARCHAR(190) NOT NULL DEFAULT '',
				`schema_type` VARCHAR(128) NOT NULL DEFAULT 'Thing',
				`templates`   VARCHAR(512) NOT NULL DEFAULT '',
				`fields_json` MEDIUMTEXT NOT NULL,
				`enabled`     TINYINT(1) NOT NULL DEFAULT 1,
				`sort`        INT UNSIGNED NOT NULL DEFAULT 0,
				`created_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
				`updated_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
				PRIMARY KEY (`id`),
				KEY `enabled_sort` (`enabled`, `sort`),
				KEY `schema_type` (`schema_type`)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
		}

	/**
	 * URL to the ProcessIchiban admin page.
	 *
	 * Resolved from the actual admin page so links keep working when the page is
	 * renamed or moved. Falls back to the default admin/ichiban/ location when
	 * the page cannot be found.
	 */
	public static function adminPageUrl(bool $http = false, string $path = ''): string {
		static $base = [];
		$key = $http ? 1 : 0;
		if (!isset($base[$key])) {
			$page = wire('pages')->get('process=ProcessIchiban, include=all');
			if ($page && $page->id) {
				$base[$key] = $http ? $page->httpUrl : $page->url;
			} else {
				$adminBase = $http ? wire('config')->urls->httpAdmin : wire('config')->urls->admin;
				$base[$key] = rtrim($adminBase, '/') . '/ichiban/';
			}
		}
		return rtrim($base[$key], '/') . '/' . ltrim($path, '/');
	}

	// -------------------------------------------------------------------------
	// Module config
	// -------------------------------------------------------------------------

	public static function getModuleConfigInputfields(array $data): InputfieldWrapper {
		$modules = wire('modules');
		$wrapper = new InputfieldWrapper();
		$addNotes = static function(InputfieldWrapper $target, string $text) use ($modules): void {
			$f = $modules->get('InputfieldMarkup');
			$f->label = __('Notes');
			$f->value = "<div class='uk-alert uk-alert-primary uk-margin-small'>{$text}</div>";
			$f->columnWidth = 100;
			$target->add($f);
		};
		$collapsedFor = static function(array $keys) use ($data): int {
			foreach ($keys as $key) {
				$value = $data[$key] ?? null;
				if (is_array($value) && count(array_filter($value, static fn($v) => $v !== '' && $v !== null && $v !== false)) > 0) return Inputfield::collapsedYes;
				if (!is_array($value) && trim((string)$value) !== '') return Inputfield::collapsedYes;
			}
			return Inputfield::collapsedNo;
		};

		/** @var InputfieldFieldset $fs */
		$fs = $modules->get('InputfieldFieldset');
		$fs->label = __('Identity');
		$fs->collapsed = $collapsedFor(['entity_name', 'entity_url', 'entity_logo', 'social_twitter', 'social_linkedin', 'social_facebook', 'social_github', 'social_instagram']);
		$addNotes($fs, __('Used for Organization/Person JSON-LD and sameAs profile links. Leave fields empty if the identity node should stay minimal.'));

		foreach ([
			['entity_type', 'Entity Type', 'InputfieldSelect', ['Organization' => 'Organization', 'Person' => 'Person'], 'Organization', 33],
			['entity_name', 'Name', 'InputfieldText', null, '', 33],
			['entity_url',  'URL',  'InputfieldURL',  null, '', 34],
			['entity_logo', 'Logo URL', 'InputfieldURL', null, '', 50],
			['social_twitter', 'Twitter/X Profile URL', 'InputfieldURL', null, '', 50],
			['social_linkedin', 'LinkedIn Profile URL', 'InputfieldURL', null, '', 50],
			['social_facebook', 'Facebook Profile URL', 'InputfieldURL', null, '', 50],
			['social_github', 'GitHub Profile URL', 'InputfieldURL', null, '', 50],
			['social_instagram', 'Instagram Profile URL', 'InputfieldURL', null, '', 50],
		] as [$name, $label, $type, $options, $default, $width]) {
			/** @var Inputfield $f */
			$f = $modules->get($type);
			$f->name  = $name;
			$f->label = $label;
			$f->value = $data[$name] ?? $default;
			$f->columnWidth = $width;
			if ($options) {
				foreach ($options as $k => $v) $f->addOption($k, $v);
			}
			$fs->add($f);
		}
		$wrapper->add($fs);

		$fsDefaults = $modules->get('InputfieldFieldset');
		$fsDefaults->label = __('Defaults');
		$fsDefaults->collapsed = $collapsedFor(['global_defaults', 'template_defaults']);
		$fsDefaults->columnWidth = 50;
		$addNotes($fsDefaults, __('JSON defaults are resolved before page-level values. Use field:title, field:summary|truncate:160, custom text, or inherit.'));
		foreach ([
			['global_defaults', __('Global defaults JSON'), "{\n  \"meta_title\": \"field:title\",\n  \"meta_description\": \"field:summary|truncate:160\"\n}"],
			['template_defaults', __('Template defaults JSON'), "{\n  \"basic-page\": {\n    \"schema_type\": \"WebPage\"\n  }\n}"],
		] as [$name, $label, $placeholder]) {
			$f = $modules->get('InputfieldTextarea');
			$f->name = $name;
			$f->label = $label;
			$f->description = __('Use typed source values like field:title, field:summary|truncate:160, custom text, or inherit.');
			$f->value = is_array($data[$name] ?? null) ? json_encode($data[$name], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : ($data[$name] ?? '');
			$f->notes = $placeholder;
			$f->rows = 6;
			$f->columnWidth = 50;
			$fsDefaults->add($f);
		}
		$wrapper->add($fsDefaults);

		$fsRendering = $modules->get('InputfieldFieldset');
		$fsRendering->label = __('Rendering');
		$fsRendering->collapsed = $collapsedFor(['auto_render_head', 'title_format', 'render_hreflang', 'render_jsonld']);
		$fsRendering->columnWidth = 50;
		$addNotes($fsRendering, __('By default Ichiban only renders tags when your template outputs $page->seo. Automatic injection is opt-in.'));

		$f = $modules->get('InputfieldCheckbox');
		$f->name = 'auto_render_head';
		$f->label = __('Automatically inject SEO tags into page <head>');
		$f->description = __('Enable only if your templates do not output $page->seo.');
		$f->checked = !empty($data['auto_render_head']);
		$f->columnWidth = 100;
		$fsRendering->add($f);

		$f = $modules->get('InputfieldText');
		$f->name = 'title_format';
		$f->label = __('Title Format');
		$f->description = __('Optionally format the rendered <title>. Use {meta_title} for the resolved page title, for example {meta_title} | {site_name}. Leave blank to render the title unchanged.');
		$f->notes = __('Supported placeholders: {meta_title}, {site_name}, {entity_name}, {host}. Title length checks include this format.');
		$f->value = $data['title_format'] ?? '';
		$f->columnWidth = 100;
		$fsRendering->add($f);

		$f = $modules->get('InputfieldCheckbox');
		$f->name = 'render_hreflang';
		$f->label = __('Render hreflang alternate links');
		$f->description = __('Disable this when ProcessWire languages are used internally but the public site should not advertise language alternates.');
		$f->checked = !array_key_exists('render_hreflang', $data) || !empty($data['render_hreflang']);
		$f->columnWidth = 50;
		$fsRendering->add($f);

		$f = $modules->get('InputfieldCheckbox');
		$f->name = 'render_jsonld';
		$f->label = __('Render JSON-LD schema');
		$f->description = __('Disable this when templates or another module generate custom structured data.');
		$f->checked = !array_key_exists('render_jsonld', $data) || !empty($data['render_jsonld']);
		$f->columnWidth = 50;
		$fsRendering->add($f);
		$wrapper->add($fsRendering);

		// Webmaster verification
		$fsVerify = $modules->get('InputfieldFieldset');
		$fsVerify->label = __('Webmaster Verification');
		$fsVerify->collapsed = $collapsedFor(['verify_bing', 'verify_yandex', 'verify_baidu', 'verify_sogou', 'verify_360', 'verify_pinterest', 'verify_facebook_domain', 'verify_custom_meta', 'facebook_pixel_id']);
		$fsVerify->columnWidth = 100;
		$addNotes($fsVerify, __('Verification tags and Meta Pixel are rendered on the home page only. Google Search Console verification is intentionally omitted here because current Google setup should use DNS TXT or CNAME verification.'));
		foreach ([
			['verify_bing', 'Bing Webmaster Tools', __('Microsoft Bing, Yahoo, and DuckDuckGo visibility often starts from Bing Webmaster Tools.'), 33],
			['verify_yandex', 'Yandex Webmaster', __('Yandex meta verification token.'), 33],
			['verify_baidu', 'Baidu Webmaster Tools', __('China: renders baidu-site-verification meta tag.'), 34],
			['verify_sogou', 'Sogou Webmaster', __('China: renders sogou_site_verification meta tag.'), 33],
			['verify_360', '360 Search / Haosou', __('China: renders 360-site-verification meta tag.'), 33],
			['verify_pinterest', 'Pinterest Domain Verify', __('Pinterest domain verification token.'), 34],
			['verify_facebook_domain', 'Meta/Facebook Domain Verification', __('Meta domain verification token.'), 50],
			['facebook_pixel_id', 'Meta/Facebook Pixel ID', __('Numeric Pixel ID. Renders the standard PageView pixel on the home page.'), 50],
		] as [$name, $label, $description, $width]) {
			$f = $modules->get('InputfieldText');
			$f->name  = $name;
			$f->label = $label;
			$f->description = $description;
			$f->value = $data[$name] ?? '';
			$f->columnWidth = $width;
			$fsVerify->add($f);
		}
		$f = $modules->get('InputfieldTextarea');
		$f->name = 'verify_custom_meta';
		$f->label = __('Additional verification meta tags');
		$f->description = __('One per line as meta-name=token. Use for regional tools that provide a meta verification tag.');
		$f->notes = __("Example: example-site-verification=abc123. Only simple meta names are rendered.");
		$f->value = $data['verify_custom_meta'] ?? '';
		$f->rows = 4;
		$f->columnWidth = 100;
		$fsVerify->add($f);
		$wrapper->add($fsVerify);

		$fsGsc = $modules->get('InputfieldFieldset');
		$fsGsc->label = __('Google Search Console');
		$fsGsc->collapsed = $collapsedFor(['gsc_site_url', 'gsc_client_id', 'gsc_client_secret', 'gsc_access_token', 'gsc_refresh_token']);
		$fsGsc->columnWidth = 100;
		$gscRedirectUri = self::adminPageUrl(true, 'search-statistics/');
		$addNotes($fsGsc,
			sprintf(
				__('Enable the Google Search Console API, create an OAuth Web application client, add this authorized redirect URI: %s, publish the OAuth app to production, then paste the Client ID and Client Secret below. Existing tokens are preserved when settings are saved.'),
				'<code>' . wire('sanitizer')->entities($gscRedirectUri) . '</code>'
			)
			. '<br><a href="https://developers.google.com/webmaster-tools/about" target="_blank" rel="noopener">Search Console API docs</a>'
			. ' · <a href="https://support.google.com/googleapi/answer/6158849" target="_blank" rel="noopener">OAuth client setup</a>'
			. ' · <a href="https://console.cloud.google.com/apis/library/searchconsole.googleapis.com" target="_blank" rel="noopener">Enable API</a>'
		);

		$hasGscClientId = !empty($data['gsc_client_id']);
		$hasGscClientSecret = !empty($data['gsc_client_secret']);
		$hasGscAccessToken = !empty($data['gsc_access_token']);
		$hasGscRefreshToken = !empty($data['gsc_refresh_token']);
		$gscTokenExpiry = (int)($data['gsc_token_expiry'] ?? 0);
		$gscConnected = $hasGscAccessToken || $hasGscRefreshToken;
		$gscStatus = $modules->get('InputfieldMarkup');
		$gscStatus->label = __('Connection status');
		$gscStatus->columnWidth = 100;
		$gscStatusUrl = self::adminPageUrl(false, 'search-statistics/');
		$gscTokenText = '';
		if ($gscConnected && $gscTokenExpiry > 0) {
			$gscTokenText = $gscTokenExpiry > time()
				? sprintf(__('Access token expires %s.'), wire('datetime')->date('Y-m-d H:i', $gscTokenExpiry))
				: ($hasGscRefreshToken ? __('Access token expired; refresh token is saved.') : __('Access token expired.'));
		}
		$gscStatus->value =
			"<div class='ichiban-gsc-status-card'>"
			. "<div class='ichiban-gsc-status-row'>"
			. "<span class='" . ($hasGscClientId ? 'ichiban-gsc-status-ok' : 'ichiban-gsc-status-missing') . "'>" . ($hasGscClientId ? __('Client ID saved') : __('Client ID missing')) . "</span>"
			. "<span class='" . ($hasGscClientSecret ? 'ichiban-gsc-status-ok' : 'ichiban-gsc-status-missing') . "'>" . ($hasGscClientSecret ? __('Client Secret saved') : __('Client Secret missing')) . "</span>"
			. "<span class='" . ($gscConnected ? 'ichiban-gsc-status-ok' : 'ichiban-gsc-status-missing') . "'>" . ($gscConnected ? __('Google account connected') : __('Google account not connected')) . "</span>"
			. "</div>"
			. ($gscTokenText ? "<p>" . wire('sanitizer')->entities($gscTokenText) . "</p>" : '')
			. "<p><a href='" . wire('sanitizer')->entities($gscStatusUrl) . "'>" . __('Open Insights') . "</a></p>"
			. "</div>";
		$fsGsc->add($gscStatus);

		foreach ([
			['gsc_site_url', 'Search Console property', 'InputfieldText'],
			['gsc_client_id', 'OAuth Client ID', 'InputfieldText'],
			['gsc_client_secret', 'OAuth Client Secret', 'InputfieldText'],
		] as [$name, $label, $type]) {
			$f = $modules->get($type);
			$f->name = $name;
			$f->label = $label;
			$f->value = $data[$name] ?? '';
			if ($name === 'gsc_site_url') {
				$f->description = __('Enter the Search Console domain, for example lqrs.uk. Ichiban will query it as a Domain property. You can still enter a URL-prefix property like https://example.com/ when needed.');
				$f->notes = __('Examples: lqrs.uk or https://example.com/');
				$f->columnWidth = 100;
			} else {
				$f->columnWidth = 50;
			}
			$fsGsc->add($f);
		}
		$wrapper->add($fsGsc);

		$fsMoz = $modules->get('InputfieldFieldset');
		$fsMoz->label = __('Backlinks / Moz API');
		$fsMoz->collapsed = $collapsedFor(['moz_api_token', 'moz_access_id', 'moz_secret_key', 'moz_target']);
		$fsMoz->columnWidth = 100;
		$addNotes($fsMoz, __('Moz Links API is used by the Backlinks section for inbound links, linking root domains, and anchor text. Use the new Moz API token when possible; legacy Access ID and Secret Key are only a fallback. Start with a small row limit to control monthly usage.'));

		foreach ([
			['moz_target', __('Default target'), 'InputfieldText', __('Domain or URL to monitor. Leave empty to use the current site root.'), 'example.com', 50],
			['moz_api_token', __('Moz API token'), 'InputfieldText', __('Recommended. Paste the token created in the Moz API dashboard. Ichiban sends it as the x-moz-token header.'), '', 50],
			['moz_access_id', __('Legacy Moz Access ID'), 'InputfieldText', __('Fallback only. Use Show Legacy Credentials in Moz if token auth is not available.'), '', 50],
			['moz_secret_key', __('Legacy Moz Secret Key'), 'InputfieldText', __('Fallback only. Used with Legacy Moz Access ID for Basic Auth.'), '', 50],
			['moz_api_base_url', __('Moz API base URL'), 'InputfieldURL', __('Default: https://lsapi.seomoz.com/v2'), 'https://lsapi.seomoz.com/v2', 50],
		] as [$name, $label, $type, $description, $placeholder, $width]) {
			$f = $modules->get($type);
			$f->name = $name;
			$f->label = $label;
			$f->description = $description;
			$f->placeholder = $placeholder;
			$f->value = $data[$name] ?? '';
			$f->columnWidth = $width;
			if ($name === 'moz_api_token' || $name === 'moz_secret_key') $f->attr('type', 'password');
			$fsMoz->add($f);
		}

		$f = $modules->get('InputfieldInteger');
		$f->name = 'moz_row_limit';
		$f->label = __('Rows per request');
		$f->description = __('Used by the Backlinks preview tables.');
		$f->value = (int)($data['moz_row_limit'] ?? 5);
		$f->min = 1;
		$f->max = 1000;
		$f->columnWidth = 50;
		$fsMoz->add($f);

		$f = $modules->get('InputfieldInteger');
		$f->name = 'moz_timeout';
		$f->label = __('Timeout (sec)');
		$f->value = (int)($data['moz_timeout'] ?? 20);
		$f->min = 5;
		$f->max = 120;
		$f->columnWidth = 50;
		$fsMoz->add($f);
		$wrapper->add($fsMoz);

		$fsAi = $modules->get('InputfieldFieldset');
		$fsAi->label = __('AI / OpenRouter');
		$fsAi->collapsed = $collapsedFor(['ai_enabled', 'ai_api_key', 'ai_model', 'ai_system_prompt']);
		$fsAi->columnWidth = 100;
		$addNotes($fsAi, __('OpenRouter connection used by the Ichiban AI section. This follows the same OpenAI-compatible pattern as the Context module: provider, API key, model, limits, and OpenRouter attribution headers.'));

		$f = $modules->get('InputfieldCheckbox');
		$f->name = 'ai_enabled';
		$f->label = __('Enable AI features');
		$f->checked = !empty($data['ai_enabled']);
		$f->columnWidth = 33;
		$fsAi->add($f);

		$f = $modules->get('InputfieldSelect');
		$f->name = 'ai_provider';
		$f->label = __('Provider');
		$f->addOption('openrouter', 'OpenRouter');
		$f->value = $data['ai_provider'] ?? 'openrouter';
		$f->showIf = 'ai_enabled=1';
		$f->columnWidth = 33;
		$fsAi->add($f);

		$f = $modules->get('InputfieldInteger');
		$f->name = 'ai_timeout';
		$f->label = __('Timeout (sec)');
		$f->value = (int)($data['ai_timeout'] ?? 30);
		$f->min = 5;
		$f->max = 120;
		$f->showIf = 'ai_enabled=1';
		$f->columnWidth = 34;
		$fsAi->add($f);

		$f = $modules->get('InputfieldText');
		$f->name = 'ai_api_key';
		$f->label = __('OpenRouter API key');
		$f->notes = __('Get a key at openrouter.ai/keys.');
		$f->placeholder = 'sk-or-...';
		$f->value = $data['ai_api_key'] ?? '';
		$f->attr('type', 'password');
		$f->showIf = 'ai_enabled=1';
		$f->columnWidth = 100;
		$fsAi->add($f);

		$f = $modules->get('InputfieldText');
		$f->name = 'ai_model';
		$f->label = __('Default model');
		$f->notes = __('OpenRouter format: provider/model, for example anthropic/claude-sonnet-4-6 or openai/gpt-4o-mini.');
		$f->value = $data['ai_model'] ?? 'anthropic/claude-sonnet-4-6';
		$f->showIf = 'ai_enabled=1';
		$f->columnWidth = 50;
		$fsAi->add($f);

		$f = $modules->get('InputfieldInteger');
		$f->name = 'ai_max_tokens';
		$f->label = __('Max tokens');
		$f->value = (int)($data['ai_max_tokens'] ?? 1024);
		$f->min = 64;
		$f->max = 16384;
		$f->showIf = 'ai_enabled=1';
		$f->columnWidth = 25;
		$fsAi->add($f);

		$f = $modules->get('InputfieldText');
		$f->name = 'ai_temperature';
		$f->label = __('Temperature');
		$f->notes = __('0 = deterministic, 1 = more creative.');
		$f->value = (string)($data['ai_temperature'] ?? '0.7');
		$f->showIf = 'ai_enabled=1';
		$f->columnWidth = 25;
		$fsAi->add($f);

		$f = $modules->get('InputfieldTextarea');
		$f->name = 'ai_system_prompt';
		$f->label = __('Global system prompt');
		$f->notes = __('Prepended to Ichiban AI requests.');
		$f->placeholder = __('You are a helpful SEO assistant for ProcessWire websites.');
		$f->value = $data['ai_system_prompt'] ?? '';
		$f->rows = 3;
		$f->showIf = 'ai_enabled=1';
		$f->columnWidth = 100;
		$fsAi->add($f);

		$f = $modules->get('InputfieldText');
		$f->name = 'ai_site_url';
		$f->label = __('Site URL (OpenRouter attribution)');
		$f->notes = __('Sent as HTTP-Referer header.');
		$f->value = $data['ai_site_url'] ?? '';
		$f->showIf = 'ai_enabled=1';
		$f->columnWidth = 50;
		$fsAi->add($f);

		$f = $modules->get('InputfieldText');
		$f->name = 'ai_site_name';
		$f->label = __('Site / app name (OpenRouter attribution)');
		$f->notes = __('Sent as X-Title header.');
		$f->value = $data['ai_site_name'] ?? '';
		$f->showIf = 'ai_enabled=1';
		$f->columnWidth = 50;
		$fsAi->add($f);

		$wrapper->add($fsAi);

		$fsPublishing = $modules->get('InputfieldFieldset');
		$fsPublishing->label = __('Publishing & URLs');
		$fsPublishing->collapsed = $collapsedFor(['indexnow_key', 'twitter_site', 'url_segments_mode']);
		$fsPublishing->columnWidth = 100;
		$indexNowFile = !empty($data['indexnow_key'])
			? rtrim(wire('config')->urls->httpRoot, '/') . '/' . trim((string)$data['indexnow_key']) . '.txt'
			: '';
		$indexNowNote = __('IndexNow keys are simple verification strings, not keys from an account. Generate one here or use any 8-128 character key containing letters, numbers, and hyphens. A public key file named {key}.txt must exist at the site root and contain the key.');
		if ($indexNowFile) {
			$indexNowNote .= ' ' . sprintf(__('Current key file URL: %s'), '<a href="' . wire('sanitizer')->entities($indexNowFile) . '" target="_blank" rel="noopener"><code>' . wire('sanitizer')->entities($indexNowFile) . '</code></a>');
		}
		$indexNowNote .= '<br><a href="https://www.indexnow.org/documentation" target="_blank" rel="noopener">IndexNow documentation</a>'
			. ' · <a href="https://www.indexnow.org/faq" target="_blank" rel="noopener">IndexNow FAQ</a>';
		$addNotes($fsPublishing, $indexNowNote);

		$f = $modules->get('InputfieldText');
		$f->name  = 'indexnow_key';
		$f->label = __('Index Now API Key');
		$f->description = __('Used when Ichiban pings IndexNow after a public page is saved.');
		$f->notes = __('Click Save to write/update the public key file if the site root is writable.');
		$f->value = $data['indexnow_key'] ?? '';
		$f->columnWidth = 33;
		$fsPublishing->add($f);

		$f = $modules->get('InputfieldText');
		$f->name  = 'twitter_site';
		$f->label = __('Twitter/X Site Handle (global default)');
		$f->notes = __('Example: @example');
		$f->value = $data['twitter_site'] ?? '';
		$f->columnWidth = 33;
		$fsPublishing->add($f);

		$f = $modules->get('InputfieldSelect');
		$f->name = 'url_segments_mode';
		$f->label = __('URL segments in SEO URLs');
		$f->description = __('Controls canonical, og:url, twitter:url, and hreflang on pages that use ProcessWire URL segments.');
		$f->addOption('preserve', __('Preserve current URL segments'));
		$f->addOption('collapse', __('Collapse to the page URL'));
		$f->value = $data['url_segments_mode'] ?? 'preserve';
		$f->columnWidth = 34;
		$fsPublishing->add($f);
		$wrapper->add($fsPublishing);

		$fsUpdates = $modules->get('InputfieldFieldset');
		$fsUpdates->label = __('Updates');
		$fsUpdates->collapsed = $collapsedFor(['updates_enabled', 'updates_repo', 'updates_channel', 'updates_install_enabled']);
		$fsUpdates->columnWidth = 100;
		$addNotes($fsUpdates, __('Dashboard update checks read GitHub releases and show a full-width banner when a newer Ichiban release exists. Installation is always manual, creates a local backup, and requires the ichiban-manage permission.'));

		$f = $modules->get('InputfieldCheckbox');
		$f->name = 'updates_enabled';
		$f->label = __('Check GitHub releases for updates');
		$f->checked = !array_key_exists('updates_enabled', $data) || !empty($data['updates_enabled']);
		$f->columnWidth = 33;
		$fsUpdates->add($f);

		$f = $modules->get('InputfieldCheckbox');
		$f->name = 'updates_install_enabled';
		$f->label = __('Allow one-click update installation');
		$f->description = __('When enabled, the Dashboard banner can download the latest release, back up the current module folder, and replace the module files.');
		$f->checked = !array_key_exists('updates_install_enabled', $data) || !empty($data['updates_install_enabled']);
		$f->columnWidth = 33;
		$fsUpdates->add($f);

		$f = $modules->get('InputfieldSelect');
		$f->name = 'updates_channel';
		$f->label = __('Update channel');
		$f->addOption('alpha', __('Alpha / prerelease'));
		$f->addOption('stable', __('Stable releases only'));
		$f->value = $data['updates_channel'] ?? 'alpha';
		$f->columnWidth = 34;
		$fsUpdates->add($f);

		$f = $modules->get('InputfieldText');
		$f->name = 'updates_repo';
		$f->label = __('GitHub repository');
		$f->description = __('Owner/repository used for release checks and downloads.');
		$f->value = $data['updates_repo'] ?? 'mxmsmnv/Ichiban';
		$f->columnWidth = 100;
		$fsUpdates->add($f);
		$wrapper->add($fsUpdates);

		$fsSitemap = $modules->get('InputfieldFieldset');
		$fsSitemap->label = __('Sitemap');
		$fsSitemap->collapsed = $collapsedFor(['sitemap_enabled', 'sitemap_sitemap_dir', 'sitemap_include_templates', 'sitemap_exclude_templates', 'sitemap_custom_urls']);
		$fsSitemap->columnWidth = 100;
		$sitemapDefaults = \IchibanSitemap::getDefaultSettings();
		$sitemapDir = trim((string)($data['sitemap_sitemap_dir'] ?? $sitemapDefaults['sitemap_dir']), '/');
		$sitemapUrl = rtrim(wire('config')->urls->httpRoot, '/') . '/' . $sitemapDir . '/sitemap.xml';
		$addNotes($fsSitemap, sprintf(
			__('Public index: %s. Use the Sitemap tab for generation status and manual actions.'),
			'<a href="' . wire('sanitizer')->entities($sitemapUrl) . '" target="_blank" rel="noopener"><code>' . wire('sanitizer')->entities($sitemapUrl) . '</code></a>'
		));

		$f = $modules->get('InputfieldCheckbox');
		$f->name = 'sitemap_enabled';
		$f->label = __('Enable XML sitemap');
		$f->checked = (bool)($data['sitemap_enabled'] ?? $sitemapDefaults['enabled']);
		$f->columnWidth = 33;
		$fsSitemap->add($f);

		$f = $modules->get('InputfieldText');
		$f->name = 'sitemap_sitemap_dir';
		$f->label = __('Sitemap directory');
		$f->value = $data['sitemap_sitemap_dir'] ?? $sitemapDefaults['sitemap_dir'];
		$f->notes = __('Relative to the ProcessWire web root.');
		$f->columnWidth = 33;
		$fsSitemap->add($f);

		$f = $modules->get('InputfieldInteger');
		$f->name = 'sitemap_chunk_size';
		$f->label = __('URLs per file');
		$f->value = $data['sitemap_chunk_size'] ?? $sitemapDefaults['chunk_size'];
		$f->min = 1;
		$f->max = \IchibanSitemap::MAX_URLS_PER_FILE;
		$f->columnWidth = 34;
		$fsSitemap->add($f);

		foreach ([
			['sitemap_respect_noindex', __('Respect noindex'), $sitemapDefaults['respect_noindex']],
			['sitemap_include_hidden', __('Include hidden pages'), $sitemapDefaults['include_hidden']],
			['sitemap_include_unpublished', __('Include unpublished pages'), $sitemapDefaults['include_unpublished']],
			['sitemap_include_images', __('Include image entries'), $sitemapDefaults['include_images']],
			['sitemap_multilang_hreflang', __('Include hreflang alternates'), $sitemapDefaults['multilang_hreflang']],
			['sitemap_auto_regenerate', __('Auto-regenerate with LazyCron'), $sitemapDefaults['auto_regenerate']],
		] as [$name, $label, $default]) {
			$f = $modules->get('InputfieldCheckbox');
			$f->name = $name;
			$f->label = $label;
			$f->checked = (bool)($data[$name] ?? $default);
			$f->columnWidth = 33;
			$fsSitemap->add($f);
		}

		foreach ([
			['sitemap_default_priority', __('Default priority'), $sitemapDefaults['default_priority'], 25],
			['sitemap_default_changefreq', __('Default change frequency'), $sitemapDefaults['default_changefreq'], 25],
			['sitemap_homepage_priority', __('Homepage priority'), $sitemapDefaults['homepage_priority'], 25],
			['sitemap_homepage_changefreq', __('Homepage change frequency'), $sitemapDefaults['homepage_changefreq'], 25],
			['sitemap_include_templates', __('Only include templates'), $sitemapDefaults['include_templates'], 50],
			['sitemap_exclude_templates', __('Exclude templates'), $sitemapDefaults['exclude_templates'], 50],
			['sitemap_lastmod_format', __('Lastmod date format'), $sitemapDefaults['lastmod_format'], 50],
			['sitemap_regenerate_interval', __('Regenerate interval seconds'), $sitemapDefaults['regenerate_interval'], 50],
		] as [$name, $label, $default, $width]) {
			$f = $modules->get('InputfieldText');
			$f->name = $name;
			$f->label = $label;
			$f->value = $data[$name] ?? $default;
			$f->columnWidth = $width;
			$fsSitemap->add($f);
		}

		$f = $modules->get('InputfieldTextarea');
		$f->name = 'sitemap_exclude_url_patterns';
		$f->label = __('Exclude URL patterns');
		$f->description = __('One substring or regex per line.');
		$f->value = $data['sitemap_exclude_url_patterns'] ?? $sitemapDefaults['exclude_url_patterns'];
		$f->columnWidth = 50;
		$fsSitemap->add($f);

		$f = $modules->get('InputfieldTextarea');
		$f->name = 'sitemap_custom_urls';
		$f->label = __('Custom URLs');
		$f->description = __('One CSV row per URL: loc,lastmod,changefreq,priority. JSON array is also accepted.');
		$f->value = $data['sitemap_custom_urls'] ?? $sitemapDefaults['custom_urls'];
		$f->columnWidth = 50;
		$fsSitemap->add($f);

		$f = $modules->get('InputfieldMarkup');
		$f->label = __('Actions');
		$f->value = '<p><a class="uk-button uk-button-default uk-button-small" href="' . self::adminPageUrl(false, 'sitemap/') . '">' . __('Open Sitemap dashboard') . '</a> '
			. '<a class="uk-button uk-button-default uk-button-small" href="' . wire('sanitizer')->entities($sitemapUrl) . '" target="_blank" rel="noopener">' . __('Open sitemap.xml') . '</a></p>';
		$fsSitemap->add($f);
		$wrapper->add($fsSitemap);

		$fsRobots = $modules->get('InputfieldFieldset');
		$fsRobots->label = __('robots.txt / llms.txt');
		$fsRobots->collapsed = $collapsedFor(['robots_enabled', 'robots_text', 'llms_enabled', 'llms_mode', 'llms_templates', 'llms_manual_urls']);
		$fsRobots->columnWidth = 100;
		$robotsInstalled = $modules->isInstalled('RobotsTxt');
		$robotsNote = $robotsInstalled
			? __('RobotsTxt companion module is installed. Use Setup > Robots.txt for the physical robots.txt editor and presets, or enable Ichiban robots.txt only when you want Ichiban to serve it dynamically. Avoid managing robots.txt in both places at once.')
			: __('Install the companion RobotsTxt module if you want a full physical robots.txt editor with presets, rules overview, file status, and a View file action. Ichiban can still serve a simple dynamic robots.txt when enabled below.');
		$robotsNote .= '<br><a href="https://github.com/mxmsmnv/RobotsTxt" target="_blank" rel="noopener">github.com/mxmsmnv/RobotsTxt</a>';
		$addNotes($fsRobots, $robotsNote);
		if ($robotsInstalled) {
			$f = $modules->get('InputfieldMarkup');
			$f->label = __('Actions');
			$f->value = '<p><a class="uk-button uk-button-default uk-button-small" href="' . rtrim(wire('config')->urls->admin, '/') . '/setup/robots-txt/">' . __('Open Robots.txt editor') . '</a> '
				. '<a class="uk-button uk-button-default uk-button-small" href="' . rtrim(wire('config')->urls->httpRoot, '/') . '/robots.txt" target="_blank" rel="noopener">' . __('Open robots.txt') . '</a></p>';
			$f->columnWidth = 100;
			$fsRobots->add($f);
		}
		$f = $modules->get('InputfieldCheckbox');
		$f->name = 'robots_enabled';
		$f->label = __('Serve robots.txt from Ichiban');
		$f->checked = !empty($data['robots_enabled']);
		$f->columnWidth = 50;
		$fsRobots->add($f);
		$f = $modules->get('InputfieldTextarea');
		$f->name = 'robots_text';
		$f->label = __('robots.txt content');
		$f->value = $data['robots_text'] ?? "User-agent: *\nAllow: /\n";
		$f->rows = 5;
		$f->notes = __('Ichiban appends Sitemap automatically when no Sitemap line is present.');
		$f->columnWidth = 50;
		$fsRobots->add($f);
		$f = $modules->get('InputfieldCheckbox');
		$f->name = 'llms_enabled';
		$f->label = __('Serve llms.txt from Ichiban');
		$f->checked = !empty($data['llms_enabled']);
		$f->columnWidth = 50;
		$fsRobots->add($f);
		$f = $modules->get('InputfieldSelect');
		$f->name = 'llms_mode';
		$f->label = __('llms.txt mode');
		$f->addOption('auto', __('Auto'));
		$f->addOption('manual', __('Manual'));
		$f->value = $data['llms_mode'] ?? 'auto';
		$f->columnWidth = 50;
		$fsRobots->add($f);
		$f = $modules->get('InputfieldText');
		$f->name = 'llms_templates';
		$f->label = __('Auto mode templates');
		$f->description = __('Comma-separated template names. Leave empty for all published pages.');
		$f->value = $data['llms_templates'] ?? '';
		$f->columnWidth = 50;
		$fsRobots->add($f);
		$f = $modules->get('InputfieldTextarea');
		$f->name = 'llms_manual_urls';
		$f->label = __('Manual llms.txt lines');
		$f->description = __('One Markdown link or plain URL per line.');
		$f->value = $data['llms_manual_urls'] ?? '';
		$f->rows = 6;
		$f->columnWidth = 50;
		$fsRobots->add($f);
		$wrapper->add($fsRobots);

		$fsCleanup = $modules->get('InputfieldFieldset');
		$fsCleanup->label = __('Crawl & Search Cleanup');
		$fsCleanup->collapsed = $collapsedFor(['search_cleanup_enabled', 'search_cleanup_action', 'search_cleanup_patterns', 'remove_rsd', 'remove_wlw', 'remove_shortlink', 'remove_prev_next', 'remove_generator']);
		$fsCleanup->columnWidth = 100;
		$addNotes($fsCleanup, __('Use cleanup options carefully on production. Blocked search queries are recorded in the Cleanup log.'));
		foreach ([
			['search_cleanup_enabled', __('Block spam search queries')],
			['remove_rsd', __('Remove RSD link')],
			['remove_wlw', __('Remove WLW manifest')],
			['remove_shortlink', __('Remove shortlink')],
			['remove_prev_next', __('Remove prev/next links')],
			['remove_generator', __('Remove generator meta')],
		] as [$name, $label]) {
			$f = $modules->get('InputfieldCheckbox');
			$f->name = $name;
			$f->label = $label;
			$f->checked = !empty($data[$name]);
			$f->columnWidth = 50;
			$fsCleanup->add($f);
		}
		$f = $modules->get('InputfieldSelect');
		$f->name = 'search_cleanup_action';
		$f->label = __('Blocked query action');
		$f->addOption('redirect', __('Redirect to homepage'));
		$f->addOption('400', __('Return 400'));
		$f->value = $data['search_cleanup_action'] ?? 'redirect';
		$f->columnWidth = 50;
		$fsCleanup->add($f);
		$f = $modules->get('InputfieldTextarea');
		$f->name = 'search_cleanup_patterns';
		$f->label = __('Custom block regex patterns');
		$f->description = __('One regular expression per line.');
		$f->value = $data['search_cleanup_patterns'] ?? '';
		$f->rows = 5;
		$f->columnWidth = 50;
		$fsCleanup->add($f);
		$wrapper->add($fsCleanup);

		return $wrapper;
	}
}
