<?php

/**
 * IchibanCli — command line interface for maintenance and smoke checks.
 */
class IchibanCli {

	protected object $ichiban;

	public function __construct(object $ichiban) {
		$this->ichiban = $ichiban;
	}

	public function dispatch(): void {
		if (PHP_SAPI !== 'cli' || empty($_SERVER['argv'])) return;
		$argv = array_map('strval', $_SERVER['argv']);
		if (!$this->hasIchibanArgument($argv)) return;

		$options = getopt('', [
			'ichiban-help::',
			'ichiban-format:',
			'ichiban-force',
			'ichiban-status',
			'ichiban-bulk-list',
			'ichiban-bulk-fix:',
			'ichiban-bulk-import:',
			'ichiban-template:',
			'ichiban-issue:',
			'ichiban-limit:',
			'ichiban-title:',
			'ichiban-description:',
			'ichiban-inherit-title',
			'ichiban-inherit-description',
			'ichiban-no-rebuild',
			'ichiban-audit-rebuild',
			'ichiban-sitemap-generate',
			'ichiban-sitemap-status',
			'ichiban-sitemap-delete',
			'ichiban-robots',
			'ichiban-llms',
			'ichiban-settings',
			'ichiban-page:',
		]);

		try {
			$result = $this->run(is_array($options) ? $options : []);
			$this->emit($result, $this->format($options ?: []));
			exit(0);
		} catch (\Throwable $e) {
			$this->emit(['ok' => false, 'error' => $e->getMessage()], $this->format($options ?: []), true);
			exit(1);
		}
	}

	public function commands(): array {
		$root = 'php index.php';
		return [
			'help' => [
				'title' => 'Show CLI help',
				'usage' => "{$root} --ichiban-help[=command]",
				'description' => 'Print all commands or detailed help for one command.',
				'options' => ['--ichiban-format=json for machine-readable command metadata.'],
				'examples' => ["{$root} --ichiban-help", "{$root} --ichiban-help=sitemap-generate"],
			],
			'status' => [
				'title' => 'Show module status',
				'usage' => "{$root} --ichiban-status [--ichiban-format=json]",
				'description' => 'Print SEO field, template count, audit stats, sitemap state, and Squad readiness.',
				'options' => ['--ichiban-format=json'],
				'examples' => ["{$root} --ichiban-status", "{$root} --ichiban-status --ichiban-format=json"],
			],
			'bulk-list' => [
				'title' => 'List Bulk Editor rows',
				'usage' => "{$root} --ichiban-bulk-list [--ichiban-template=NAME] [--ichiban-issue=ISSUE] [--ichiban-limit=N] [--ichiban-format=json]",
				'description' => 'List indexed pages using the same issue filters as the Bulk Editor.',
				'options' => ['--ichiban-template=NAME', '--ichiban-issue=missing_title|missing_description|title_length|description_length', '--ichiban-limit=N', '--ichiban-format=json'],
				'examples' => ["{$root} --ichiban-bulk-list --ichiban-issue=missing_title", "{$root} --ichiban-bulk-list --ichiban-template=blog-post --ichiban-format=json"],
			],
			'bulk-fix' => [
				'title' => 'Fix one Bulk Editor row',
				'usage' => "{$root} --ichiban-bulk-fix=PAGE_ID [--ichiban-title=TEXT] [--ichiban-description=TEXT] [--ichiban-inherit-title] [--ichiban-inherit-description]",
				'description' => 'Save custom or inherited meta title and description values for one page through the Ichiban field API.',
				'options' => ['--ichiban-title=TEXT', '--ichiban-description=TEXT', '--ichiban-inherit-title', '--ichiban-inherit-description', '--ichiban-no-rebuild', '--ichiban-format=json'],
				'examples' => ["{$root} --ichiban-bulk-fix=123 --ichiban-title='New SEO title' --ichiban-description='Search snippet.'", "{$root} --ichiban-bulk-fix=123 --ichiban-inherit-title"],
			],
			'bulk-import' => [
				'title' => 'Apply Bulk Editor fixes from a file',
				'usage' => "{$root} --ichiban-bulk-import=/path/to/fixes.csv [--ichiban-no-rebuild] [--ichiban-format=json]",
				'description' => 'Apply multiple meta title/description fixes from CSV or JSON. CSV columns: page_id,title,description,inherit_title,inherit_description.',
				'options' => ['--ichiban-no-rebuild', '--ichiban-format=json'],
				'examples' => ["{$root} --ichiban-bulk-import=/tmp/ichiban-fixes.csv", "{$root} --ichiban-bulk-import=/tmp/ichiban-fixes.json --ichiban-format=json"],
			],
			'audit-rebuild' => [
				'title' => 'Rebuild audit index',
				'usage' => "{$root} --ichiban-audit-rebuild [--ichiban-format=json]",
				'description' => 'Rebuild ichiban_index from current page SEO values and print the resulting quick stats.',
				'options' => ['--ichiban-format=json'],
				'examples' => ["{$root} --ichiban-audit-rebuild"],
			],
			'sitemap-generate' => [
				'title' => 'Generate sitemap files',
				'usage' => "{$root} --ichiban-sitemap-generate [--ichiban-format=json]",
				'description' => 'Force-generate sitemap.xml and template sitemap files using current sitemap settings.',
				'options' => ['--ichiban-format=json'],
				'examples' => ["{$root} --ichiban-sitemap-generate"],
			],
			'sitemap-status' => [
				'title' => 'Show sitemap status',
				'usage' => "{$root} --ichiban-sitemap-status [--ichiban-format=json]",
				'description' => 'Print sitemap directory, URL count, files, LazyCron state, and regeneration state.',
				'options' => ['--ichiban-format=json'],
				'examples' => ["{$root} --ichiban-sitemap-status"],
			],
			'sitemap-delete' => [
				'title' => 'Delete generated sitemap files',
				'usage' => "{$root} --ichiban-sitemap-delete --ichiban-force [--ichiban-format=json]",
				'description' => 'Delete generated sitemap*.xml files. Requires --ichiban-force.',
				'options' => ['--ichiban-force', '--ichiban-format=json'],
				'examples' => ["{$root} --ichiban-sitemap-delete --ichiban-force"],
			],
			'robots' => [
				'title' => 'Render robots.txt',
				'usage' => "{$root} --ichiban-robots",
				'description' => 'Print the dynamic robots.txt output exactly as Ichiban would serve it.',
				'options' => [],
				'examples' => ["{$root} --ichiban-robots"],
			],
			'llms' => [
				'title' => 'Render llms.txt',
				'usage' => "{$root} --ichiban-llms",
				'description' => 'Print the optional llms.txt output generated from Ichiban settings.',
				'options' => [],
				'examples' => ["{$root} --ichiban-llms"],
			],
			'settings' => [
				'title' => 'Export website settings',
				'usage' => "{$root} --ichiban-settings [--ichiban-format=json]",
				'description' => 'Print merged website settings from siteSettings() for templates and integrations.',
				'options' => ['--ichiban-format=json'],
				'examples' => ["{$root} --ichiban-settings --ichiban-format=json"],
			],
			'page' => [
				'title' => 'Inspect one page SEO value',
				'usage' => "{$root} --ichiban-page=ID [--ichiban-format=json]",
				'description' => 'Print resolved page SEO data for a page that has the Ichiban SEO field.',
				'options' => ['--ichiban-format=json'],
				'examples' => ["{$root} --ichiban-page=123", "{$root} --ichiban-page=123 --ichiban-format=json"],
			],
		];
	}

	public function help(?string $command = null): array {
		$commands = $this->commands();
		if ($command !== null && $command !== '') {
			$key = $this->normalizeCommand($command);
			if (!isset($commands[$key])) throw new \InvalidArgumentException("Unknown Ichiban CLI command: {$command}");
			return ['ok' => true, 'command' => $key, 'help' => $commands[$key]];
		}
		return ['ok' => true, 'title' => 'Ichiban CLI', 'commands' => $commands];
	}

	protected function run(array $options): array|string {
		if (array_key_exists('ichiban-help', $options)) {
			$value = $options['ichiban-help'];
			return $this->help(is_string($value) ? $value : null);
		}
		if (array_key_exists('ichiban-status', $options)) return $this->status();
		if (array_key_exists('ichiban-bulk-list', $options)) return $this->bulkList($options);
		if (array_key_exists('ichiban-bulk-fix', $options)) return $this->bulkFix((int)$options['ichiban-bulk-fix'], $options);
		if (array_key_exists('ichiban-bulk-import', $options)) return $this->bulkImport((string)$options['ichiban-bulk-import'], $options);
		if (array_key_exists('ichiban-audit-rebuild', $options)) return $this->auditRebuild();
		if (array_key_exists('ichiban-sitemap-generate', $options)) return $this->sitemapGenerate();
		if (array_key_exists('ichiban-sitemap-status', $options)) return $this->sitemapStatus();
		if (array_key_exists('ichiban-sitemap-delete', $options)) return $this->sitemapDelete(!empty($options['ichiban-force']));
		if (array_key_exists('ichiban-robots', $options)) return $this->ichiban->renderRobotsTxt();
		if (array_key_exists('ichiban-llms', $options)) return $this->ichiban->renderLlmsTxt();
		if (array_key_exists('ichiban-settings', $options)) return ['ok' => true, 'settings' => $this->ichiban->siteSettings()];
		if (array_key_exists('ichiban-page', $options)) return $this->page((int)$options['ichiban-page']);
		return $this->help();
	}

	protected function status(): array {
		$fieldName = $this->ichiban->getSeoFieldName();
		$field = $this->ichiban->wire('fields')->get($fieldName);
		$templateNames = [];
		if ($field) {
			foreach ($this->ichiban->wire('templates') as $template) {
				if ($template->hasField($field)) $templateNames[] = (string)$template->name;
			}
		}
		$ai = $this->ichiban->getSquadBridge();
		return [
			'ok' => true,
			'version' => (string)($this->ichiban::getModuleInfo()['version'] ?? ''),
			'admin_url' => \ProcessWire\Ichiban::adminPageUrl(true),
			'seo_field' => $fieldName,
			'seo_field_exists' => (bool)$field,
			'template_count' => count($templateNames),
			'templates' => $templateNames,
			'audit' => $this->ichiban->getAuditEngine()->getQuickStats(),
			'sitemap' => $this->compactSitemapStatus($this->ichiban->getSitemap()->getStatus()),
			'ai' => [
				'configured' => $ai->isConfigured(),
				'provider' => $ai->providerLabel(),
				'model' => $ai->activeModel(),
			],
		];
	}

	protected function auditRebuild(): array {
		$this->ichiban->getAuditEngine()->rebuildIndex();
		return ['ok' => true, 'message' => 'Audit index rebuilt.', 'audit' => $this->ichiban->getAuditEngine()->getQuickStats()];
	}

	protected function bulkList(array $options): array {
		$issue = $this->issueOption((string)($options['ichiban-issue'] ?? ''));
		$template = trim((string)($options['ichiban-template'] ?? ''));
		$limit = max(1, min(500, (int)($options['ichiban-limit'] ?? 50)));
		[$where, $params] = $this->bulkWhere($issue, $template);
		$db = $this->ichiban->wire('database');
		$stmt = $db->prepare("SELECT page_id, template_name, url, meta_title, meta_description, meta_title_len, meta_desc_len, is_noindex, has_og_image, schema_type FROM ichiban_index{$where} ORDER BY (meta_title='') DESC, (meta_description='') DESC, template_name, url LIMIT :limit");
		foreach ($params as $name => $value) $stmt->bindValue($name, $value);
		$stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
		$stmt->execute();
		$rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
		foreach ($rows as &$row) {
			$row['page_id'] = (int)$row['page_id'];
			$row['meta_title_len'] = (int)$row['meta_title_len'];
			$row['meta_desc_len'] = (int)$row['meta_desc_len'];
			$row['is_noindex'] = (bool)$row['is_noindex'];
			$row['has_og_image'] = (bool)$row['has_og_image'];
		}
		return [
			'ok' => true,
			'filter' => ['issue' => $issue, 'template' => $template, 'limit' => $limit],
			'count' => count($rows),
			'rows' => $rows,
			'csv_columns' => ['page_id', 'title', 'description', 'inherit_title', 'inherit_description'],
		];
	}

	protected function bulkFix(int $pageId, array $options): array {
		if ($pageId < 1) throw new \InvalidArgumentException('Page ID must be a positive integer.');
		$hasTitle = array_key_exists('ichiban-title', $options);
		$hasDescription = array_key_exists('ichiban-description', $options);
		$inheritTitle = array_key_exists('ichiban-inherit-title', $options);
		$inheritDescription = array_key_exists('ichiban-inherit-description', $options);
		if (!$hasTitle && !$hasDescription && !$inheritTitle && !$inheritDescription) {
			throw new \InvalidArgumentException('Nothing to save. Pass --ichiban-title, --ichiban-description, --ichiban-inherit-title, or --ichiban-inherit-description.');
		}
		$result = $this->saveBulkPage($pageId, [
			'title' => $hasTitle ? (string)$options['ichiban-title'] : null,
			'description' => $hasDescription ? (string)$options['ichiban-description'] : null,
			'inherit_title' => $inheritTitle,
			'inherit_description' => $inheritDescription,
		]);
		$this->afterBulkSave(!array_key_exists('ichiban-no-rebuild', $options), [$pageId]);
		return ['ok' => true, 'message' => 'Bulk SEO fix saved.', 'saved' => 1, 'skipped' => 0, 'pages' => [$result]];
	}

	protected function bulkImport(string $path, array $options): array {
		$path = $this->resolvePath($path);
		if (!is_file($path) || !is_readable($path)) throw new \InvalidArgumentException("Import file is not readable: {$path}");
		$rows = $this->readBulkImportRows($path);
		if (!$rows) throw new \InvalidArgumentException('Import file contains no rows.');
		$saved = 0;
		$skipped = 0;
		$pages = [];
		$pageIds = [];
		foreach ($rows as $row) {
			$pageId = (int)($row['page_id'] ?? $row['id'] ?? 0);
			if ($pageId < 1) {
				$skipped++;
				continue;
			}
			try {
				$page = $this->saveBulkPage($pageId, [
					'title' => array_key_exists('title', $row) ? (string)$row['title'] : (array_key_exists('meta_title', $row) ? (string)$row['meta_title'] : null),
					'description' => array_key_exists('description', $row) ? (string)$row['description'] : (array_key_exists('meta_description', $row) ? (string)$row['meta_description'] : null),
					'inherit_title' => $this->truthy($row['inherit_title'] ?? false),
					'inherit_description' => $this->truthy($row['inherit_description'] ?? false),
				]);
				$saved++;
				$pageIds[] = $pageId;
				$pages[] = $page;
			} catch (\Throwable $e) {
				$skipped++;
				$pages[] = ['page_id' => $pageId, 'ok' => false, 'error' => $e->getMessage()];
			}
		}
		$this->afterBulkSave(!array_key_exists('ichiban-no-rebuild', $options), $pageIds);
		return ['ok' => true, 'message' => 'Bulk SEO fixes imported.', 'saved' => $saved, 'skipped' => $skipped, 'pages' => $pages];
	}

	protected function sitemapGenerate(): array {
		$result = $this->ichiban->getSitemap()->generate(true);
		if (isset($result['error'])) return ['ok' => false, 'error' => (string)$result['error']];
		return ['ok' => true, 'message' => 'Sitemap generated.', 'result' => $result, 'status' => $this->compactSitemapStatus($this->ichiban->getSitemap()->getStatus())];
	}

	protected function sitemapStatus(): array {
		return ['ok' => true, 'sitemap_url' => $this->ichiban->getSitemapUrl(), 'status' => $this->ichiban->getSitemap()->getStatus()];
	}

	protected function sitemapDelete(bool $force): array {
		if (!$force) throw new \InvalidArgumentException('Refusing to delete sitemap files without --ichiban-force.');
		$status = $this->ichiban->getSitemap()->getStatus();
		$deleted = [];
		foreach ($status['files'] as $file) {
			$path = rtrim((string)$status['dir'], '/') . '/' . (string)$file['name'];
			if (is_file($path) && @unlink($path)) $deleted[] = (string)$file['name'];
		}
		return ['ok' => true, 'message' => 'Sitemap files deleted.', 'deleted' => $deleted];
	}

	protected function page(int $id): array {
		if ($id < 1) throw new \InvalidArgumentException('Page ID must be a positive integer.');
		$page = $this->ichiban->wire('pages')->get($id);
		if (!$page || !$page->id) throw new \InvalidArgumentException("Page not found: {$id}");
		$fieldName = $this->ichiban->getSeoFieldName();
		if (!$page->hasField($fieldName)) throw new \InvalidArgumentException("Page {$id} does not have Ichiban field '{$fieldName}'.");
		$seo = $page->get($fieldName);
		return [
			'ok' => true,
			'page' => [
				'id' => (int)$page->id,
				'title' => (string)$page->title,
				'template' => (string)$page->template->name,
				'url' => method_exists($this->ichiban, 'pageHttpUrl') ? $this->ichiban->pageHttpUrl($page) : $page->httpUrl(),
			],
			'seo' => [
				'title' => (string)($seo->meta->title ?? ''),
				'rendered_title' => $this->ichiban->formatMetaTitle((string)($seo->meta->title ?? $page->title)),
				'description' => (string)($seo->meta->description ?? ''),
				'canonical' => (string)($seo->meta->canonical ?? ''),
				'robots' => [
					'noindex' => !empty($seo->meta->noindex),
					'nofollow' => !empty($seo->meta->nofollow),
				],
				'og_image' => (string)($seo->og->image ?? ''),
				'schema_type' => (string)($seo->schema->type ?? ''),
			],
		];
	}

	protected function saveBulkPage(int $pageId, array $changes): array {
		$page = $this->ichiban->wire('pages')->get($pageId);
		if (!$page || !$page->id) throw new \InvalidArgumentException("Page not found: {$pageId}");
		$fieldName = $this->ichiban->getSeoFieldName();
		if (!$page->hasField($fieldName)) throw new \InvalidArgumentException("Page {$pageId} does not have Ichiban field '{$fieldName}'.");

		$page->of(false);
		$seo = $page->get($fieldName);
		if (!$seo instanceof \IchibanPageFieldValue) throw new \RuntimeException("Page {$pageId} has no writable Ichiban value.");
		$data = $seo->getData();
		$san = $this->ichiban->wire('sanitizer');

		$changed = [];
		if (!empty($changes['inherit_title'])) {
			$data['meta_title'] = ['mode' => 'inherit', 'value' => ''];
			$changed[] = 'meta_title';
		} elseif (array_key_exists('title', $changes) && $changes['title'] !== null) {
			$title = $san->text((string)$changes['title']);
			$data['meta_title'] = $title === '' ? ['mode' => 'inherit', 'value' => ''] : ['mode' => 'custom', 'value' => $title];
			$changed[] = 'meta_title';
		}

		if (!empty($changes['inherit_description'])) {
			$data['meta_description'] = ['mode' => 'inherit', 'value' => ''];
			$changed[] = 'meta_description';
		} elseif (array_key_exists('description', $changes) && $changes['description'] !== null) {
			$description = $san->text((string)$changes['description']);
			$data['meta_description'] = $description === '' ? ['mode' => 'inherit', 'value' => ''] : ['mode' => 'custom', 'value' => $description];
			$changed[] = 'meta_description';
		}

		if (!$changed) throw new \InvalidArgumentException("Page {$pageId}: nothing to save.");
		$seo->setData($data);
		$page->set($fieldName, $seo);
		$page->trackChange($fieldName);
		$page->save($fieldName);

		return [
			'ok' => true,
			'page_id' => (int)$page->id,
			'title' => (string)$page->title,
			'url' => method_exists($this->ichiban, 'pageHttpUrl') ? $this->ichiban->pageHttpUrl($page) : $page->httpUrl(),
			'changed' => array_values(array_unique($changed)),
		];
	}

	protected function afterBulkSave(bool $rebuild, array $pageIds): void {
		$engine = $this->ichiban->getAuditEngine();
		if ($rebuild) {
			$engine->rebuildIndex();
			return;
		}
		foreach (array_values(array_unique(array_map('intval', $pageIds))) as $pageId) {
			$page = $this->ichiban->wire('pages')->get($pageId);
			if ($page && $page->id) $engine->refreshPage($page);
		}
	}

	protected function readBulkImportRows(string $path): array {
		$ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
		if ($ext === 'json') {
			$data = json_decode((string)file_get_contents($path), true);
			if (isset($data['rows']) && is_array($data['rows'])) $data = $data['rows'];
			if (!is_array($data)) throw new \InvalidArgumentException('JSON import must be an array or an object with rows.');
			return array_values(array_filter($data, 'is_array'));
		}

		$fh = fopen($path, 'r');
		if (!$fh) throw new \InvalidArgumentException("Cannot open import file: {$path}");
		$header = fgetcsv($fh);
		if (!$header) {
			fclose($fh);
			return [];
		}
		$header = array_map(static fn($h) => strtolower(trim((string)$h)), $header);
		$rows = [];
		while (($row = fgetcsv($fh)) !== false) {
			$item = [];
			foreach ($header as $i => $name) {
				if ($name !== '') $item[$name] = $row[$i] ?? '';
			}
			$rows[] = $item;
		}
		fclose($fh);
		return $rows;
	}

	protected function bulkWhere(string $issue, string $template): array {
		$where = [];
		$params = [];
		if ($template !== '') {
			$where[] = 'template_name=:template';
			$params[':template'] = $template;
		}
		$issueMap = [
			'missing_title' => "meta_title=''",
			'missing_description' => "meta_description=''",
			'title_length' => "meta_title!='' AND NOT (meta_title_len BETWEEN 30 AND 70)",
			'description_length' => "meta_description!='' AND NOT (meta_desc_len BETWEEN 50 AND 160)",
		];
		if ($issue !== '' && isset($issueMap[$issue])) $where[] = $issueMap[$issue];
		return [$where ? ' WHERE ' . implode(' AND ', $where) : '', $params];
	}

	protected function issueOption(string $issue): string {
		$issue = trim($issue);
		if ($issue === '') return '';
		$allowed = ['missing_title', 'missing_description', 'title_length', 'description_length'];
		if (!in_array($issue, $allowed, true)) throw new \InvalidArgumentException('Unknown bulk issue filter: ' . $issue);
		return $issue;
	}

	protected function resolvePath(string $path): string {
		$path = trim($path);
		if ($path === '') throw new \InvalidArgumentException('Import path is required.');
		if (str_starts_with($path, '/')) return $path;
		return rtrim((string)$this->ichiban->wire('config')->paths->root, '/') . '/' . $path;
	}

	protected function truthy(mixed $value): bool {
		if (is_bool($value)) return $value;
		$value = strtolower(trim((string)$value));
		return in_array($value, ['1', 'yes', 'true', 'on', 'inherit'], true);
	}

	protected function compactSitemapStatus(array $status): array {
		return [
			'url' => $this->ichiban->getSitemapUrl(),
			'dir' => $status['dir'] ?? '',
			'dir_exists' => !empty($status['dir_exists']),
			'dir_writable' => !empty($status['dir_writable']),
			'file_count' => (int)($status['file_count'] ?? 0),
			'total_urls' => (int)($status['total_urls'] ?? 0),
			'last_generated' => $status['last_generated'] ?? null,
			'needs_regen' => !empty($status['needs_regen']),
		];
	}

	protected function emit(array|string $result, string $format, bool $stderr = false): void {
		$out = $format === 'json' ? $this->json($result) : (is_string($result) ? rtrim($result) . "\n" : $this->text($result));
		if ($stderr) {
			fwrite(STDERR, $out);
		} else {
			echo $out;
		}
	}

	protected function text(array $result): string {
		if (isset($result['commands']) && is_array($result['commands'])) return $this->textHelp($result);
		if (isset($result['help']) && is_array($result['help'])) return $this->textCommandHelp((string)($result['command'] ?? ''), $result['help']);
		$lines = [];
		$this->flattenText($result, $lines);
		return implode("\n", $lines) . "\n";
	}

	protected function textHelp(array $result): string {
		$lines = ["Ichiban CLI", '', 'Usage: php index.php --ichiban-help[=command]', '', 'Commands:'];
		foreach ($result['commands'] as $key => $command) {
			$lines[] = '  ' . str_pad($key, 18) . (string)$command['title'];
		}
		$lines[] = '';
		$lines[] = 'Run php index.php --ichiban-help=command for command-specific help.';
		return implode("\n", $lines) . "\n";
	}

	protected function textCommandHelp(string $key, array $command): string {
		$lines = ["Ichiban CLI: {$key}", '', (string)$command['description'], '', 'Usage:', '  ' . (string)$command['usage']];
		if (!empty($command['options'])) {
			$lines[] = '';
			$lines[] = 'Options:';
			foreach ((array)$command['options'] as $option) $lines[] = '  ' . (string)$option;
		}
		if (!empty($command['examples'])) {
			$lines[] = '';
			$lines[] = 'Examples:';
			foreach ((array)$command['examples'] as $example) $lines[] = '  ' . (string)$example;
		}
		return implode("\n", $lines) . "\n";
	}

	protected function flattenText(mixed $value, array &$lines, string $prefix = ''): void {
		if (is_array($value)) {
			foreach ($value as $key => $item) {
				$name = $prefix === '' ? (string)$key : $prefix . '.' . (string)$key;
				if (is_array($item)) {
					if ($this->isList($item)) {
						$lines[] = $name . ': ' . implode(', ', array_map(static fn($v) => is_scalar($v) ? (string)$v : json_encode($v), $item));
					} else {
						$this->flattenText($item, $lines, $name);
					}
				} else {
					$lines[] = $name . ': ' . $this->scalarText($item);
				}
			}
			return;
		}
		$lines[] = $prefix . ': ' . $this->scalarText($value);
	}

	protected function scalarText(mixed $value): string {
		if (is_bool($value)) return $value ? 'yes' : 'no';
		if ($value === null) return '';
		return (string)$value;
	}

	protected function json(array|string $result): string {
		return json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
	}

	protected function format(array $options): string {
		return (string)($options['ichiban-format'] ?? '') === 'json' ? 'json' : 'text';
	}

	protected function normalizeCommand(string $command): string {
		$command = preg_replace('/^--?ichiban-/', '', trim($command));
		return str_replace('_', '-', (string)$command);
	}

	protected function hasIchibanArgument(array $argv): bool {
		foreach ($argv as $arg) {
			if (str_starts_with($arg, '--ichiban-')) return true;
		}
		return false;
	}

	protected function isList(array $array): bool {
		return array_keys($array) === range(0, count($array) - 1);
	}
}
