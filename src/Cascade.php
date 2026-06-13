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
		$entry = $data[$fieldKey];
		if (is_array($entry)) {
			$mode = $entry['mode'] ?? 'inherit';
			if ($mode === 'inherit') return 'inherit';
			if ($mode === 'custom')  return $entry['value'] ?? '';
			if ($mode === 'field')   return 'field:' . ($entry['value'] ?? '');
		}
		return is_string($entry) ? $entry : null;
	}

	protected function getTemplateDefault(string $group, string $key): ?string {
		$tplName   = $this->page->template ? $this->page->template->name : '';
		$defaults  = $this->ichiban->get('template_defaults') ?: [];
		if (is_string($defaults)) $defaults = json_decode($defaults, true) ?: [];
		$fieldKey = $this->aliasFieldKey($group, $key, "{$group}_{$key}");
		return $defaults[$tplName][$fieldKey] ?? null;
	}

	protected function getGlobalDefault(string $group, string $key): ?string {
		$defaults = $this->ichiban->get('global_defaults') ?: [];
		if (is_string($defaults)) $defaults = json_decode($defaults, true) ?: [];
		$fieldKey = $this->aliasFieldKey($group, $key, "{$group}_{$key}");
		return $defaults[$fieldKey] ?? null;
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

	protected function builtinFallback(string $group, string $key): string {
		return match ("{$group}.{$key}") {
			'meta.title'       => (string)$this->page->get('title'),
			'meta.description' => (string)($this->page->get('summary') ?: ''),
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
}
