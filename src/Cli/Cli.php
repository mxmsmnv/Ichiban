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
