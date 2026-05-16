<?php

/**
 * IchibanSitemap — integrated XML sitemap generator for Ichiban.
 *
 * Adapted from the standalone Sitemap module into a service class that stores
 * settings in Ichiban's module config and uses Ichiban page-level SEO data.
 */
class IchibanSitemap {

	public const MAX_URLS_PER_FILE = 50000;
	public const SITEMAP_NS = 'http://www.sitemaps.org/schemas/sitemap/0.9';
	public const DEFAULT_DIR = 'sitemaps';
	public const LOCK_FILE = 'ichiban-sitemap.lock';

	protected object $ichiban;
	protected ?string $_cronMethod = null;

	public function __construct(object $ichiban) {
		$this->ichiban = $ichiban;
	}

	public static function getDefaultSettings(): array {
		return [
			'enabled'              => 1,
			'sitemap_dir'          => self::DEFAULT_DIR,
			'chunk_size'           => 1000,
			'lastmod_format'       => 'Y-m-d',
			'include_templates'    => '',
			'exclude_templates'    => '',
			'include_hidden'       => 0,
			'include_unpublished'  => 0,
			'respect_noindex'      => 1,
			'default_priority'     => '0.5',
			'default_changefreq'   => 'weekly',
			'homepage_priority'    => '1.0',
			'homepage_changefreq'  => 'daily',
			'include_images'       => 0,
			'multilang_hreflang'   => 0,
			'auto_regenerate'      => 1,
			'regenerate_interval'  => 86400,
			'custom_urls'          => '',
			'exclude_url_patterns' => '',
		];
	}

	public function loadSettings(): array {
		$out = self::getDefaultSettings();
		foreach (array_keys($out) as $key) {
			$value = $this->ichiban->get('sitemap_' . $key);
			if ($value !== null && $value !== '') $out[$key] = $value;
		}
		return $out;
	}

	public function setting(string $name): mixed {
		$settings = $this->loadSettings();
		return $settings[$name] ?? null;
	}

	public function init(): void {
		if (!$this->setting('enabled')) return;
		$this->ichiban->addHookBefore('ProcessPageView::execute', $this, 'hookServeSitemap');
		$this->ichiban->addHookAfter('Pages::saved', $this, 'hookPageChanged');
		$this->ichiban->addHookAfter('Pages::trashed', $this, 'hookPageChanged');
		$this->ichiban->addHookAfter('Pages::deleted', $this, 'hookPageChanged');

		if (!$this->setting('auto_regenerate')) return;
		if (!$this->wire('modules')->isInstalled('LazyCron')) {
			$lastWarn = $this->wire('cache')->get('Ichiban_sitemap_lazycron_warn');
			if (!$lastWarn || (time() - (int)$lastWarn) > 86400) {
				$this->wire('log')->save('ichiban', 'Sitemap auto-regenerate is enabled, but LazyCron is not installed. Install LazyCron from Modules > Core.');
				$this->wire('cache')->save('Ichiban_sitemap_lazycron_warn', time(), \ProcessWire\WireCache::expireNever);
			}
			return;
		}

		$this->_cronMethod = $this->cronMethodForInterval((int)($this->setting('regenerate_interval') ?: 86400));
		$this->ichiban->addHook('LazyCron::' . $this->_cronMethod, $this, 'hookCronRegenerate');
	}

	public function hookServeSitemap(\ProcessWire\HookEvent $event): void {
		$url = $this->wire('input')->url();
		$sitemapDir = trim((string)($this->setting('sitemap_dir') ?: self::DEFAULT_DIR), '/');
		$patterns = [
			'|^/' . preg_quote($sitemapDir, '|') . '/sitemap[^/]*\.xml$|',
			'|^/sitemap\.xml$|',
			'|^/sitemap-index\.xml$|',
		];
		$matched = false;
		foreach ($patterns as $pattern) {
			if (preg_match($pattern, $url)) {
				$matched = true;
				break;
			}
		}
		if (!$matched) return;

		$filename = basename($url) === 'sitemap-index.xml' ? 'sitemap.xml' : basename($url);
		$file = $this->getSitemapFilePath($filename);
		if (!$file || !file_exists($file)) {
			$this->generate();
			$file = $this->getSitemapFilePath($filename);
		}
		if ($file && file_exists($file)) {
			header('Content-Type: application/xml; charset=utf-8');
			header('X-Robots-Tag: noindex');
			header('Last-Modified: ' . gmdate('D, d M Y H:i:s', filemtime($file)) . ' GMT');
			readfile($file);
			exit;
		}

		$event->return = $this->wire('pages')->get(404)->render();
	}

	public function hookCronRegenerate(\ProcessWire\HookEvent $event): void {
		$lastGen = $this->wire('cache')->get('Ichiban_sitemap_last_generated');
		$interval = (int)($this->setting('regenerate_interval') ?: 86400);
		$elapsed = $lastGen ? time() - (int)$lastGen : null;
		$this->wire('log')->save('ichiban', sprintf(
			'Sitemap LazyCron::%s fired. last=%s interval=%ds elapsed=%s',
			$this->_cronMethod ?: $this->cronMethodForInterval($interval),
			$lastGen ? date('Y-m-d H:i:s', (int)$lastGen) : 'never',
			$interval,
			$elapsed !== null ? $elapsed . 's' : 'n/a'
		));
		if (!$lastGen || $elapsed >= $interval) {
			$this->generate();
		}
	}

	public function hookPageChanged(\ProcessWire\HookEvent $event): void {
		$this->wire('cache')->save('Ichiban_sitemap_needs_regen', true, \ProcessWire\WireCache::expireNever);
	}

	public function generate(bool $force = false): array {
		$t = microtime(true);
		if (!$force && $this->isLocked()) return ['error' => 'Generation already in progress.'];
		$this->lock();
		try {
			$dir = $this->ensureSitemapDir();
			$urls = $this->collectUrls();
			$files = $this->writeFiles($dir, $urls);
			$this->wire('cache')->save('Ichiban_sitemap_last_generated', time(), \ProcessWire\WireCache::expireNever);
			$this->wire('cache')->save('Ichiban_sitemap_needs_regen', false, \ProcessWire\WireCache::expireNever);
			$elapsed = round(microtime(true) - $t, 3);
			$this->wire('log')->save('ichiban', "Sitemap generated: " . count($files) . " files, " . count($urls) . " URLs in {$elapsed}s");
			return ['files' => count($files), 'urls' => count($urls), 'time' => $elapsed];
		} finally {
			$this->unlock();
		}
	}

	protected function collectUrls(): array {
		$urls = [];
		$s = $this->loadSettings();
		$includeTemplates = array_filter(array_map('trim', explode(',', (string)$s['include_templates'])));
		$excludeTemplates = array_filter(array_map('trim', explode(',', (string)$s['exclude_templates'])));

		$selector = 'id>0, check_access=0';
		if (!$s['include_hidden']) $selector .= ', status!=hidden';
		if (!$s['include_unpublished']) $selector .= ', status<unpublished';
		if ($includeTemplates) $selector .= ', template=' . implode('|', $includeTemplates);

		$start = 0;
		$pageSize = 500;
		while (true) {
			$chunk = $this->wire('pages')->find("$selector, start=$start, limit=$pageSize");
			if (!$chunk->count()) break;
			foreach ($chunk as $page) {
				if (!empty($excludeTemplates) && in_array($page->template->name, $excludeTemplates, true)) continue;
				if ($page->template->flags & \ProcessWire\Template::flagSystem) continue;
				if ($page->rootParent->id === $this->wire('config')->adminRootPageID) continue;
				if ($s['respect_noindex'] && $this->pageHasNoindex($page)) continue;
				if (!$this->pageIncludedInSitemap($page)) continue;

				$pageUrl = $page->httpUrl();
				if (!$pageUrl) continue;
				if ($this->matchesExcludePattern($pageUrl, (string)$s['exclude_url_patterns'])) continue;

				$isHome = $page->id === $this->wire('config')->rootPageID;
				$pageSettings = $this->pageSitemapSettings($page);
				$priority = $isHome ? $s['homepage_priority'] : ($pageSettings['priority'] ?? $s['default_priority']);
				$changefreq = $isHome ? $s['homepage_changefreq'] : ($pageSettings['changefreq'] ?? $s['default_changefreq']);

				$entry = [
					'loc'        => $pageUrl,
					'lastmod'    => $page->modified ? date((string)$s['lastmod_format'], $page->modified) : null,
					'changefreq' => $changefreq,
					'priority'   => number_format((float)$priority, 1),
					'template'   => $page->template->name,
				];
				if ($s['include_images']) $entry['images'] = $this->collectPageImages($page);
				if ($s['multilang_hreflang']) $entry['hreflang'] = $this->collectHreflang($page);
				$urls[] = $entry;
			}

			$count = $chunk->count();
			$chunk->resetTrackChanges();
			unset($chunk);
			$this->wire('pages')->uncacheAll();
			if ($count < $pageSize) break;
			$start += $pageSize;
		}

		foreach ($this->customUrls((string)$s['custom_urls']) as $custom) {
			$urls[] = [
				'loc'        => $custom['loc'],
				'lastmod'    => $custom['lastmod'] ?? null,
				'changefreq' => $custom['changefreq'] ?? $s['default_changefreq'],
				'priority'   => number_format((float)($custom['priority'] ?? $s['default_priority']), 1),
				'template'   => 'custom',
			];
		}

		return $urls;
	}

	protected function pageIncludedInSitemap(\ProcessWire\Page $page): bool {
		$fn = $this->ichiban->getSeoFieldName();
		if (!$page->hasField($fn)) return true;
		$seo = $page->getUnformatted($fn);
		if ($seo instanceof \IchibanPageFieldValue) {
			$data = $seo->getData();
			return array_key_exists('sitemap_include', $data) ? (bool)$data['sitemap_include'] : true;
		}
		return true;
	}

	protected function pageSitemapSettings(\ProcessWire\Page $page): array {
		$fn = $this->ichiban->getSeoFieldName();
		if (!$page->hasField($fn)) return [];
		$seo = $page->getUnformatted($fn);
		if (!$seo instanceof \IchibanPageFieldValue) return [];
		$data = $seo->getData();
		return [
			'priority' => $data['sitemap_priority'] ?? null,
			'changefreq' => $data['sitemap_changefreq'] ?? null,
		];
	}

	protected function pageHasNoindex(\ProcessWire\Page $page): bool {
		$fn = $this->ichiban->getSeoFieldName();
		if ($page->hasField($fn)) {
			$seo = $page->getUnformatted($fn);
			if ($seo instanceof \IchibanPageFieldValue) {
				$data = $seo->getData();
				if (!empty($data['meta_noindex'])) return true;
			}
		}
		foreach (['seo_noindex', 'noindex', 'meta_noindex', 'robots_noindex'] as $field) {
			if ($page->hasField($field) && $page->get($field)) return true;
		}
		return false;
	}

	protected function matchesExcludePattern(string $url, string $patterns): bool {
		foreach (array_filter(array_map('trim', preg_split('/\R+/', $patterns) ?: [])) as $pattern) {
			if (strlen($pattern) > 2 && @preg_match($pattern, '') !== false && preg_match($pattern, $url)) return true;
			if (str_contains($url, $pattern)) return true;
		}
		return false;
	}

	protected function customUrls(string $raw): array {
		$decoded = json_decode($raw, true);
		if (is_array($decoded)) return array_values(array_filter($decoded, static fn($row) => !empty($row['loc'])));
		$out = [];
		foreach (array_filter(array_map('trim', preg_split('/\R+/', $raw) ?: [])) as $line) {
			$parts = array_map('trim', str_getcsv($line));
			if (!empty($parts[0])) {
				$out[] = [
					'loc' => $parts[0],
					'lastmod' => $parts[1] ?? null,
					'changefreq' => $parts[2] ?? null,
					'priority' => $parts[3] ?? null,
				];
			}
		}
		return $out;
	}

	protected function collectPageImages(\ProcessWire\Page $page): array {
		$images = [];
		foreach ($page->fields as $field) {
			if (!($field->type instanceof \ProcessWire\FieldtypeImage)) continue;
			$pageImages = $page->get($field->name);
			if (!$pageImages) continue;
			if ($pageImages instanceof \ProcessWire\Pageimage) $pageImages = [$pageImages];
			foreach ($pageImages as $image) {
				$images[] = ['loc' => $image->httpUrl, 'title' => $image->description ?: $page->title, 'caption' => $image->description];
				if (count($images) >= 1000) break 2;
			}
		}
		return $images;
	}

	protected function collectHreflang(\ProcessWire\Page $page): array {
		$out = [];
		$languages = $this->wire('languages');
		if (!$languages) return $out;
		foreach ($languages as $lang) {
			if (!$page->viewable($lang)) continue;
			$url = $page->localHttpUrl($lang);
			if ($url) $out[] = ['hreflang' => $lang->name === 'default' ? 'x-default' : $lang->name, 'href' => $url];
		}
		return $out;
	}

	protected function writeFiles(string $dir, array $urls): array {
		$maxPerFile = max(1, min((int)$this->setting('chunk_size') ?: 1000, self::MAX_URLS_PER_FILE));
		$groups = [];
		foreach ($urls as $entry) {
			$template = preg_replace('/[^a-z0-9_-]/i', '-', $entry['template'] ?? 'pages');
			$groups[$template][] = $entry;
		}

		$generated = [];
		if (!$groups) {
			$this->writeSitemapFile($dir . '/sitemap.xml', []);
			$generated[] = 'sitemap.xml';
		} else {
			foreach ($groups as $template => $entries) {
				foreach (array_chunk($entries, $maxPerFile) as $index => $chunk) {
					$name = 'sitemap-' . $template . ($index > 0 ? '-' . ($index + 1) : '') . '.xml';
					$this->writeSitemapFile($dir . '/' . $name, $chunk);
					$generated[] = $name;
				}
			}
			$this->writeSitemapIndex($dir . '/sitemap.xml', $generated);
		}
		$this->cleanupOldFiles($dir, $generated);
		return $generated;
	}

	protected function writeSitemapFile(string $filepath, array $urls): void {
		$s = $this->loadSettings();
		$xml = new \XMLWriter();
		$xml->openUri($filepath);
		$xml->startDocument('1.0', 'UTF-8');
		$xml->startElement('urlset');
		$xml->writeAttribute('xmlns', self::SITEMAP_NS);
		if ($s['include_images']) $xml->writeAttribute('xmlns:image', 'http://www.google.com/schemas/sitemap-image/1.1');
		if ($s['multilang_hreflang']) $xml->writeAttribute('xmlns:xhtml', 'http://www.w3.org/1999/xhtml');
		foreach ($urls as $entry) {
			$xml->startElement('url');
			$xml->writeElement('loc', $entry['loc']);
			if (!empty($entry['lastmod'])) $xml->writeElement('lastmod', $entry['lastmod']);
			if (!empty($entry['changefreq'])) $xml->writeElement('changefreq', $entry['changefreq']);
			if (isset($entry['priority'])) $xml->writeElement('priority', $entry['priority']);
			foreach ($entry['images'] ?? [] as $image) {
				$xml->startElement('image:image');
				$xml->writeElement('image:loc', $image['loc']);
				if (!empty($image['caption'])) $xml->writeElement('image:caption', $image['caption']);
				if (!empty($image['title'])) $xml->writeElement('image:title', $image['title']);
				$xml->endElement();
			}
			foreach ($entry['hreflang'] ?? [] as $alt) {
				$xml->startElement('xhtml:link');
				$xml->writeAttribute('rel', 'alternate');
				$xml->writeAttribute('hreflang', $alt['hreflang']);
				$xml->writeAttribute('href', $alt['href']);
				$xml->endElement();
			}
			$xml->endElement();
		}
		$xml->endElement();
		$xml->endDocument();
		$xml->flush();
	}

	protected function writeSitemapIndex(string $filepath, array $files): void {
		$dir = trim((string)($this->setting('sitemap_dir') ?: self::DEFAULT_DIR), '/');
		$baseUrl = rtrim($this->ichiban->siteUrl(), '/');
		$xml = new \XMLWriter();
		$xml->openUri($filepath);
		$xml->startDocument('1.0', 'UTF-8');
		$xml->startElement('sitemapindex');
		$xml->writeAttribute('xmlns', self::SITEMAP_NS);
		foreach ($files as $file) {
			$xml->startElement('sitemap');
			$xml->writeElement('loc', $baseUrl . '/' . $dir . '/' . $file);
			$xml->writeElement('lastmod', date('Y-m-d'));
			$xml->endElement();
		}
		$xml->endElement();
		$xml->endDocument();
		$xml->flush();
	}

	protected function cleanupOldFiles(string $dir, array $keep): void {
		foreach (glob($dir . '/sitemap*.xml') ?: [] as $file) {
			$base = basename($file);
			if (!in_array($base, $keep, true) && $base !== 'sitemap.xml') @unlink($file);
		}
	}

	public function ensureSitemapDir(): string {
		$dir = rtrim($this->wire('config')->paths->root, '/') . '/' . trim((string)($this->setting('sitemap_dir') ?: self::DEFAULT_DIR), '/');
		if (!is_dir($dir) && !$this->wire('files')->mkdir($dir, true)) {
			throw new \ProcessWire\WireException("Ichiban sitemap: cannot create directory: {$dir}");
		}
		if (!is_writable($dir)) throw new \ProcessWire\WireException("Ichiban sitemap: directory not writable: {$dir}");
		if (!file_exists($dir . '/.htaccess')) file_put_contents($dir . '/.htaccess', "Options -Indexes\n");
		return $dir;
	}

	public function getSitemapFilePath(string $filename): string {
		return rtrim($this->wire('config')->paths->root, '/') . '/' . trim((string)($this->setting('sitemap_dir') ?: self::DEFAULT_DIR), '/') . '/' . $filename;
	}

	public function getStatus(): array {
		$sitemapDir = trim((string)($this->setting('sitemap_dir') ?: self::DEFAULT_DIR), '/');
		$autoRegenerate = (bool)$this->setting('auto_regenerate');
		$regenerateInterval = (int)($this->setting('regenerate_interval') ?: 86400);
		$lazyCronInstalled = $this->wire('modules')->isInstalled('LazyCron');
		$dir = rtrim($this->wire('config')->paths->root, '/') . '/' . $sitemapDir;
		$files = is_dir($dir) ? (glob($dir . '/sitemap*.xml') ?: []) : [];
		$totalSize = 0;
		$totalUrls = 0;
		$fileList = [];
		foreach ($files as $path) {
			$size = filesize($path);
			$urlCount = 0;
			if ($fh = fopen($path, 'r')) {
				while (($line = fgets($fh)) !== false) $urlCount += substr_count($line, '<url>');
				fclose($fh);
			}
			$isIndex = basename($path) === 'sitemap.xml' && $urlCount === 0;
			$totalSize += $size;
			if (!$isIndex) $totalUrls += $urlCount;
			$fileList[] = [
				'name' => basename($path),
				'size' => $size,
				'modified' => filemtime($path),
				'urls' => $urlCount,
				'is_index' => $isIndex,
			];
		}
		usort($fileList, static fn($a, $b) => strcmp($a['name'], $b['name']));
		return [
			'last_generated' => $this->wire('cache')->get('Ichiban_sitemap_last_generated'),
			'needs_regen' => $this->wire('cache')->get('Ichiban_sitemap_needs_regen'),
			'is_locked' => $this->isLocked(),
			'files' => $fileList,
			'file_count' => count($fileList),
			'total_size' => $totalSize,
			'total_urls' => $totalUrls,
			'dir' => $dir,
			'dir_exists' => is_dir($dir),
			'dir_writable' => is_dir($dir) && is_writable($dir),
			'sitemap_dir' => $sitemapDir,
			'auto_regenerate' => $autoRegenerate,
			'regenerate_interval' => $regenerateInterval,
			'lazy_cron_installed' => $lazyCronInstalled,
			'cron_method' => $autoRegenerate && $lazyCronInstalled ? ($this->_cronMethod ?: $this->cronMethodForInterval($regenerateInterval)) : '',
		];
	}

	protected function cronMethodForInterval(int $interval): string {
		if ($interval <= 60) return 'everyMinute';
		if ($interval <= 3600) return 'everyHour';
		if ($interval <= 21600) return 'every6Hours';
		if ($interval <= 43200) return 'every12Hours';
		if ($interval <= 86400) return 'everyDay';
		if ($interval <= 604800) return 'everyWeek';
		return 'every4Weeks';
	}

	protected function getLockPath(): string {
		return $this->wire('config')->paths->cache . self::LOCK_FILE;
	}

	protected function isLocked(): bool {
		$file = $this->getLockPath();
		if (!file_exists($file)) return false;
		if (time() - filemtime($file) > 600) {
			@unlink($file);
			return false;
		}
		return true;
	}

	protected function lock(): void {
		file_put_contents($this->getLockPath(), (string)time());
	}

	protected function unlock(): void {
		@unlink($this->getLockPath());
	}

	protected function wire(string $name): mixed {
		return $this->ichiban->wire($name);
	}
}
