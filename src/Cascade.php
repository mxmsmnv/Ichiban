<?php

/**
 * IchibanCascade — resolves SEO values through 3-level inheritance:
 *   1. Global defaults (module config)
 *   2. Template defaults (module config per template)
 *   3. Page value (field data)
 *
 * Source field format:
 *   "inherit"                → use level above
 *   "custom:Some Title"      → literal value
 *   "field:title"            → pull from page field
 *   "field:summary|truncate:160" → pull + truncate
 */
class IchibanCascade {

	protected object $ichiban;
	protected \ProcessWire\Page $page;
	protected ?array $rawData;

	public function __construct(object $ichiban, \ProcessWire\Page $page, ?array $rawData = null) {
		$this->ichiban = $ichiban;
		$this->page    = $page;
		$this->rawData = $rawData;
	}

	/**
	 * Resolve a single SEO value.
	 *
	 * @hook Ichiban::resolveSourceValue
	 */
	public function resolve(string $group, string $key): string {
		// 1. Page-level value
		$pageData = $this->getPageData($group, $key);
		if ($this->shouldIgnoreStoredDefaultSchemaType($group, $key, $pageData)) {
			$pageData = 'inherit';
		}
		if ($pageData !== null && $pageData !== 'inherit') {
			$value = $this->ichiban->resolveSourceValue($this->page, $group, $key, $pageData);
			return $this->ichiban->resolvedSeoValue($this->page, $group, $key, $value);
		}

		// 2. Template-level default
		$tplData = $this->getTemplateDefault($group, $key);
		if ($tplData !== null && $tplData !== 'inherit') {
			$value = $this->ichiban->resolveSourceValue($this->page, $group, $key, $tplData);
			return $this->ichiban->resolvedSeoValue($this->page, $group, $key, $value);
		}

		// 3. Global default
		$globalData = $this->getGlobalDefault($group, $key);
		if ($globalData !== null) {
			$value = $this->ichiban->resolveSourceValue($this->page, $group, $key, $globalData);
			return $this->ichiban->resolvedSeoValue($this->page, $group, $key, $value);
		}

		// Built-in fallbacks
		return $this->ichiban->resolvedSeoValue($this->page, $group, $key, $this->builtinFallback($group, $key));
	}

	// -------------------------------------------------------------------------

	protected function getPageData(string $group, string $key): ?string {
		// Use injected raw data if available; otherwise read from page
		$data = $this->rawData;
		if ($data === null) {
			$fn = $this->ichiban->getSeoFieldName();
			if (!$this->page->hasField($fn)) return null;
			$seo = $this->page->getUnformatted($fn);
			if (!$seo instanceof \IchibanPageFieldValue) return null;
			$data = $seo->getData();
		}
		$fieldKey = "{$group}_{$key}";
		$fieldKey = $this->aliasFieldKey($group, $key, $fieldKey);
		if (!isset($data[$fieldKey])) return null;
		$entry = $this->normalizeSourceEntry($data[$fieldKey], $group, $key);
		if ($entry === 'inherit') return 'inherit';
		if ($entry === null) return null;
		return $entry;
	}

	protected function normalizeSourceEntry(mixed $entry, string $group, string $key): ?string {
		if (is_array($entry)) {
			$mode = $entry['mode'] ?? 'inherit';
			if ($mode === 'inherit') return 'inherit';
			$value = trim((string)($entry['value'] ?? ''));
			if ($value === '' && $this->isSourceField($group, $key)) return 'inherit';
			if ($mode === 'custom')  return $value;
			if ($mode === 'field')   return 'field:' . $value;
		}
		return is_string($entry) ? trim($entry) : null;
	}

	protected function getTemplateDefault(string $group, string $key): ?string {
		$tplName   = $this->page->template ? $this->page->template->name : '';
		$defaults  = $this->ichiban->get('template_defaults') ?: [];
		if (is_string($defaults)) $defaults = json_decode($defaults, true) ?: [];
		$entry = is_array($defaults[$tplName] ?? null) ? $this->defaultEntry($defaults[$tplName], $group, $key) : null;
		if ($entry === null || $entry === '' || $entry === 'inherit') return null;
		return $entry;
	}

	protected function getGlobalDefault(string $group, string $key): ?string {
		$defaults = $this->ichiban->get('global_defaults') ?: [];
		if (is_string($defaults)) $defaults = json_decode($defaults, true) ?: [];
		$entry = $this->defaultEntry(is_array($defaults) ? $defaults : [], $group, $key);
		if ($entry === null || $entry === '' || $entry === 'inherit') return null;
		return $entry;
	}

	protected function defaultEntry(array $defaults, string $group, string $key): ?string {
		$fieldKey = $this->aliasFieldKey($group, $key, "{$group}_{$key}");
		if (array_key_exists($fieldKey, $defaults)) return $this->normalizeSourceEntry($defaults[$fieldKey], $group, $key);
		$dotKey = "{$group}.{$key}";
		if (array_key_exists($dotKey, $defaults)) return $this->normalizeSourceEntry($defaults[$dotKey], $group, $key);
		if (isset($defaults[$group]) && is_array($defaults[$group]) && array_key_exists($key, $defaults[$group])) {
			return $this->normalizeSourceEntry($defaults[$group][$key], $group, $key);
		}
		return null;
	}

	protected function aliasFieldKey(string $group, string $key, string $default): string {
		return match ("{$group}.{$key}") {
			'meta.canonical' => 'canonical_url',
			'meta.noindex' => 'meta_noindex',
			'meta.nofollow' => 'meta_nofollow',
			'advanced.robots_meta' => 'robots_meta',
			'advanced.jsonld_override' => 'jsonld_override',
			default => $default,
		};
	}

	protected function isSourceField(string $group, string $key): bool {
		return in_array("{$group}.{$key}", ['meta.title', 'meta.description', 'og.title', 'og.description'], true);
	}

	protected function builtinFallback(string $group, string $key): string {
		return match ("{$group}.{$key}") {
			'meta.title'       => (string)$this->page->get('title'),
			'meta.description' => $this->plainText((string)($this->page->get('summary') ?: '')),
			'meta.canonical'   => $this->page->id && method_exists($this->ichiban, 'pageHttpUrl') ? $this->ichiban->pageHttpUrl($this->page) : ($this->page->id ? $this->page->httpUrl() : ''),
			'og.title'         => $this->resolve('meta', 'title'),
			'og.description'   => $this->resolve('meta', 'description'),
			'og.type'          => 'website',
			'twitter.card'     => 'summary_large_image',
			'sitemap.include'  => '1',
			'sitemap.priority' => '0.5',
			'sitemap.changefreq' => 'weekly',
			default            => '',
		};
	}

	protected function shouldIgnoreStoredDefaultSchemaType(string $group, string $key, ?string $pageData): bool {
		if ($group !== 'schema' || $key !== 'type' || $pageData !== 'WebPage') return false;
		$templateDefault = $this->getTemplateDefault($group, $key);
		if ($templateDefault !== null && $templateDefault !== '' && $templateDefault !== 'WebPage') return true;
		$globalDefault = $this->getGlobalDefault($group, $key);
		return $globalDefault !== null && $globalDefault !== '' && $globalDefault !== 'WebPage';
	}

	protected function plainText(string $value): string {
		if ($value === '') return '';
		$value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
		$value = preg_replace('/<[^>]*>/u', ' ', $value) ?? $value;
		$value = preg_replace('/\s+/u', ' ', $value) ?? $value;
		return trim($value);
	}
}
