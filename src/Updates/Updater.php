<?php

/**
 * IchibanUpdater — GitHub release checks and guarded module self-updates.
 */
class IchibanUpdater {

	protected const CACHE_NAME = 'Ichiban.update.status';
	protected const CACHE_TTL = 21600;
	protected const DEFAULT_REPO = 'mxmsmnv/Ichiban';

	protected object $ichiban;
	protected string $rootPath;

	public function __construct(object $ichiban) {
		$this->ichiban = $ichiban;
		$this->rootPath = dirname(__DIR__, 2);
	}

	public function getStatus(bool $force = false): array {
		$cache = $this->ichiban->wire('cache');
		$cacheName = self::CACHE_NAME . '.' . md5($this->repo() . '|' . $this->channel());
		if (!$force) {
			$cached = $cache->get($cacheName);
			if (is_array($cached)) return $cached;
		}

		$status = [
			'ok' => false,
			'update_available' => false,
			'current_version' => $this->currentVersionLabel(),
			'latest_version' => '',
			'latest_name' => '',
			'tag_name' => '',
			'release_url' => '',
			'zipball_url' => '',
			'published_at' => '',
			'body' => '',
			'error' => '',
		];

		try {
			$release = $this->latestRelease();
			if (!$release) {
				$status['error'] = 'No compatible GitHub release was found.';
				return $status;
			}
			$latestVersion = $this->normalizeVersion((string)($release['tag_name'] ?? ''));
			$currentVersion = $this->normalizeVersion($status['current_version']);
			$status = array_merge($status, [
				'ok' => true,
				'update_available' => $latestVersion !== '' && $currentVersion !== '' && version_compare($latestVersion, $currentVersion, '>'),
				'latest_version' => $latestVersion ?: (string)($release['tag_name'] ?? ''),
				'latest_name' => (string)($release['name'] ?? ''),
				'tag_name' => (string)($release['tag_name'] ?? ''),
				'release_url' => (string)($release['html_url'] ?? ''),
				'zipball_url' => (string)($release['zipball_url'] ?? ''),
				'published_at' => (string)($release['published_at'] ?? ''),
				'body' => (string)($release['body'] ?? ''),
			]);
		} catch (\Throwable $e) {
			$status['error'] = $e->getMessage();
		}

		$cache->save($cacheName, $status, self::CACHE_TTL);
		return $status;
	}

	public function installLatest(): array {
		$status = $this->getStatus(true);
		if (empty($status['ok'])) {
			throw new \RuntimeException($status['error'] ?: 'Unable to check for updates.');
		}
		if (empty($status['update_available'])) {
			return ['ok' => true, 'message' => 'Ichiban is already up to date.', 'backup_path' => ''];
		}
		if (!class_exists('ZipArchive')) {
			throw new \RuntimeException('PHP ZipArchive extension is required to install updates.');
		}

		$tmpZip = tempnam(sys_get_temp_dir(), 'ichiban-update-');
		$tmpDir = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'ichiban-update-' . bin2hex(random_bytes(6));
		if (!$tmpZip || !mkdir($tmpDir, 0775, true)) {
			throw new \RuntimeException('Unable to create a temporary update workspace.');
		}

		try {
			$url = $status['zipball_url'] ?: 'https://github.com/' . $this->repo() . '/archive/refs/tags/' . rawurlencode($status['tag_name']) . '.zip';
			$this->download($url, $tmpZip);

			$zip = new \ZipArchive();
			if ($zip->open($tmpZip) !== true) {
				throw new \RuntimeException('Downloaded update archive could not be opened.');
			}
			$zip->extractTo($tmpDir);
			$zip->close();

			$source = $this->findSourceRoot($tmpDir);
			$this->validateSourceRoot($source);
			$backup = $this->backupCurrentVersion();
			$this->copyRecursive($source, $this->rootPath, ['.git', '.github', '.DS_Store']);
			$this->ichiban->wire('modules')->refresh();
			$this->ichiban->wire('cache')->delete(self::CACHE_NAME . '.' . md5($this->repo() . '|' . $this->channel()));

			return [
				'ok' => true,
				'message' => 'Ichiban was updated to ' . ($status['latest_version'] ?: $status['tag_name']) . '.',
				'backup_path' => $backup,
			];
		} finally {
			if (is_file($tmpZip)) @unlink($tmpZip);
			$this->removeRecursive($tmpDir);
		}
	}

	protected function latestRelease(): ?array {
		$url = 'https://api.github.com/repos/' . $this->repo() . '/releases';
		$json = $this->fetch($url);
		$releases = json_decode($json, true);
		if (!is_array($releases)) {
			throw new \RuntimeException('GitHub returned an invalid release response.');
		}
		foreach ($releases as $release) {
			if (!empty($release['draft'])) continue;
			if ($this->channel() !== 'alpha' && !empty($release['prerelease'])) continue;
			return is_array($release) ? $release : null;
		}
		return null;
	}

	protected function repo(): string {
		$repo = trim((string)($this->ichiban->get('updates_repo') ?: self::DEFAULT_REPO));
		return preg_match('{^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$}', $repo) ? $repo : self::DEFAULT_REPO;
	}

	protected function channel(): string {
		return (string)($this->ichiban->get('updates_channel') ?: 'alpha');
	}

	protected function currentVersionLabel(): string {
		$file = $this->rootPath . '/Ichiban.module.php';
		$contents = is_file($file) ? (string)file_get_contents($file) : '';
		if (preg_match('/@version\s+([^\s]+)/', $contents, $m)) return $m[1];
		return '0.0.0';
	}

	protected function normalizeVersion(string $version): string {
		$version = trim($version);
		$version = preg_replace('/^release[-_]?/i', '', $version);
		$version = ltrim($version, "vV \t\n\r\0\x0B");
		if (preg_match('/\d+(?:\.\d+){0,3}(?:-[A-Za-z0-9.-]+)?/', $version, $m)) return $m[0];
		return '';
	}

	protected function fetch(string $url): string {
		$context = stream_context_create([
			'http' => [
				'method' => 'GET',
				'timeout' => 20,
				'header' => "User-Agent: Ichiban-Updater\r\nAccept: application/vnd.github+json\r\n",
			],
		]);
		$body = @file_get_contents($url, false, $context);
		if ($body === false || $body === '') {
			throw new \RuntimeException('Unable to contact GitHub releases API.');
		}
		return $body;
	}

	protected function download(string $url, string $path): void {
		$context = stream_context_create([
			'http' => [
				'method' => 'GET',
				'timeout' => 60,
				'header' => "User-Agent: Ichiban-Updater\r\nAccept: application/octet-stream\r\n",
			],
		]);
		$body = @file_get_contents($url, false, $context);
		if ($body === false || $body === '') {
			throw new \RuntimeException('Unable to download update archive.');
		}
		if (file_put_contents($path, $body, LOCK_EX) === false) {
			throw new \RuntimeException('Unable to write downloaded update archive.');
		}
	}

	protected function findSourceRoot(string $dir): string {
		$candidates = glob(rtrim($dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR) ?: [];
		foreach ($candidates as $candidate) {
			if (is_file($candidate . '/Ichiban.module.php')) return $candidate;
		}
		if (is_file($dir . '/Ichiban.module.php')) return $dir;
		throw new \RuntimeException('Update archive does not contain an Ichiban module root.');
	}

	protected function validateSourceRoot(string $source): void {
		foreach (['Ichiban.module.php', 'ProcessIchiban.module.php', 'IchibanAutoload.php'] as $file) {
			if (!is_file($source . '/' . $file)) {
				throw new \RuntimeException('Update archive is missing ' . $file . '.');
			}
		}
	}

	protected function backupCurrentVersion(): string {
		$base = rtrim($this->ichiban->wire('config')->paths->assets, '/') . '/cache/Ichiban-updates';
		if (!is_dir($base) && !mkdir($base, 0775, true)) {
			throw new \RuntimeException('Unable to create update backup directory.');
		}
		$backup = $base . '/backup-' . date('Ymd-His');
		$this->copyRecursive($this->rootPath, $backup, ['.git', '.DS_Store']);
		return $backup;
	}

	protected function copyRecursive(string $source, string $target, array $exclude = []): void {
		if (!is_dir($target) && !mkdir($target, 0775, true)) {
			throw new \RuntimeException('Unable to create directory: ' . $target);
		}
		$items = scandir($source);
		if (!$items) return;
		foreach ($items as $item) {
			if ($item === '.' || $item === '..' || in_array($item, $exclude, true)) continue;
			$from = $source . DIRECTORY_SEPARATOR . $item;
			$to = $target . DIRECTORY_SEPARATOR . $item;
			if (is_dir($from)) {
				$this->copyRecursive($from, $to, $exclude);
			} elseif (!copy($from, $to)) {
				throw new \RuntimeException('Unable to copy update file: ' . $item);
			}
		}
	}

	protected function removeRecursive(string $path): void {
		if (!is_dir($path)) return;
		$items = scandir($path);
		if ($items) {
			foreach ($items as $item) {
				if ($item === '.' || $item === '..') continue;
				$child = $path . DIRECTORY_SEPARATOR . $item;
				is_dir($child) ? $this->removeRecursive($child) : @unlink($child);
			}
		}
		@rmdir($path);
	}
}
