<?php

/**
 * IchibanBacklinks — saved backlink snapshots and cache.
 *
 * Every Moz refresh is stored as a snapshot so the Backlinks screen can render
 * cached data without spending API quota on page reloads.
 */
class IchibanBacklinks {

	protected object $ichiban;

	public function __construct(object $ichiban) {
		$this->ichiban = $ichiban;
	}

	public function refreshFromMoz(string $view, string $target, string $scope, int $limit): array {
		$this->ensureTables();
		$moz = $this->ichiban->getBacklinksMoz();
		if ($view === 'domains') {
			$rows = $moz->getLinkingRootDomains($target, $limit, $scope);
		} elseif ($view === 'anchors') {
			$rows = $moz->getAnchorText($target, $limit, $scope);
		} else {
			$rows = $moz->getLinks($target, $limit, $scope);
		}
		if ($moz->getLastError()) {
			return ['snapshot' => null, 'rows' => [], 'error' => $moz->getLastError()];
		}
		$snapshot = $this->saveSnapshot($view, $target, $scope, $limit, $rows);
		return ['snapshot' => $snapshot, 'rows' => $rows, 'error' => ''];
	}

	public function getLatestSnapshot(string $view, string $target, string $scope): ?array {
		$this->ensureTables();
		$stmt = $this->ichiban->wire('database')->prepare(
			"SELECT * FROM ichiban_backlink_snapshots
			WHERE view=:view AND target=:target AND scope=:scope
			ORDER BY fetched_at DESC, id DESC LIMIT 1"
		);
		$stmt->execute([':view' => $view, ':target' => $target, ':scope' => $scope]);
		$row = $stmt->fetch(\PDO::FETCH_ASSOC);
		return $row ?: null;
	}

	public function getRowsForSnapshot(int $snapshotId): array {
		$this->ensureTables();
		$stmt = $this->ichiban->wire('database')->prepare("SELECT raw_json FROM ichiban_backlink_rows WHERE snapshot_id=:id ORDER BY id ASC");
		$stmt->execute([':id' => $snapshotId]);
		$rows = [];
		while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
			$data = json_decode((string)$row['raw_json'], true);
			if (is_array($data)) $rows[] = $data;
		}
		return $rows;
	}

	public function getHistory(string $view, string $target, string $scope, int $limit = 5): array {
		$this->ensureTables();
		$stmt = $this->ichiban->wire('database')->prepare(
			"SELECT * FROM ichiban_backlink_snapshots
			WHERE view=:view AND target=:target AND scope=:scope
			ORDER BY fetched_at DESC, id DESC LIMIT :limit"
		);
		$stmt->bindValue(':view', $view);
		$stmt->bindValue(':target', $target);
		$stmt->bindValue(':scope', $scope);
		$stmt->bindValue(':limit', max(1, min(25, $limit)), \PDO::PARAM_INT);
		$stmt->execute();
		return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
	}

	public function refreshQuotaFromMoz(): array {
		$this->ensureTables();
		$quota = $this->ichiban->getBacklinksMoz()->getQuota();
		if (!$quota) return [];
		$db = $this->ichiban->wire('database');
		$stmt = $db->prepare(
			"INSERT INTO ichiban_backlink_quota (provider, path, allotted, used, period_start, period_reset, raw_json, fetched_at)
			VALUES ('moz', :path, :allotted, :used, :period_start, :period_reset, :raw_json, NOW())"
		);
		$raw = json_encode($quota, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		$stmt->execute([
			':path' => (string)($quota['path'] ?? 'api.limits.data.rows'),
			':allotted' => (int)($quota['allotted'] ?? 0),
			':used' => (int)($quota['used'] ?? 0),
			':period_start' => (int)($quota['period_start'] ?? 0),
			':period_reset' => (int)($quota['period_reset'] ?? 0),
			':raw_json' => $raw !== false ? $raw : '{}',
		]);
		return $this->getLatestQuota() ?: [];
	}

	public function getLatestQuota(): ?array {
		$this->ensureTables();
		$row = $this->ichiban->wire('database')->query("SELECT * FROM ichiban_backlink_quota WHERE provider='moz' ORDER BY fetched_at DESC, id DESC LIMIT 1")->fetch(\PDO::FETCH_ASSOC);
		return $row ?: null;
	}

	protected function saveSnapshot(string $view, string $target, string $scope, int $limit, array $rows): array {
		$db = $this->ichiban->wire('database');
		$db->beginTransaction();
		try {
			$stmt = $db->prepare(
				"INSERT INTO ichiban_backlink_snapshots (provider, view, target, scope, row_limit, row_count, fetched_at)
				VALUES ('moz', :view, :target, :scope, :row_limit, :row_count, NOW())"
			);
			$stmt->execute([
				':view' => $view,
				':target' => $target,
				':scope' => $scope,
				':row_limit' => $limit,
				':row_count' => count($rows),
			]);
			$snapshotId = (int)$db->lastInsertId();
			$rowStmt = $db->prepare(
				"INSERT INTO ichiban_backlink_rows
				(snapshot_id, row_key, source_url, target_url, source_domain, anchor_text, http_code, domain_authority, spam_score, last_crawled, raw_json)
				VALUES (:snapshot_id, :row_key, :source_url, :target_url, :source_domain, :anchor_text, :http_code, :domain_authority, :spam_score, :last_crawled, :raw_json)"
			);
			foreach ($rows as $row) {
				if (!is_array($row)) continue;
				$raw = json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
				if ($raw === false) $raw = '{}';
				$rowStmt->execute([
					':snapshot_id' => $snapshotId,
					':row_key' => $this->rowKey($view, $row),
					':source_url' => $this->stringValue($row, ['source.page', 'source_url', 'source_page', 'page']),
					':target_url' => $this->stringValue($row, ['target.page', 'target_url', 'target_page']),
					':source_domain' => $this->stringValue($row, ['source.root_domain', 'root_domain', 'source_root_domain', 'domain']),
					':anchor_text' => $this->stringValue($row, ['anchor_text', 'anchor']),
					':http_code' => (int)$this->stringValue($row, ['source.http_code', 'http_code']),
					':domain_authority' => (int)$this->stringValue($row, ['source.domain_authority', 'domain_authority', 'source_domain_authority']),
					':spam_score' => (int)$this->stringValue($row, ['source.spam_score', 'spam_score', 'source_spam_score']),
					':last_crawled' => $this->stringValue($row, ['source.last_crawled', 'last_crawled']),
					':raw_json' => $raw,
				]);
			}
			$db->commit();
		} catch (\Throwable $e) {
			$db->rollBack();
			throw $e;
		}
		return $this->getSnapshotById($snapshotId) ?: [];
	}

	protected function getSnapshotById(int $id): ?array {
		$stmt = $this->ichiban->wire('database')->prepare("SELECT * FROM ichiban_backlink_snapshots WHERE id=:id");
		$stmt->execute([':id' => $id]);
		$row = $stmt->fetch(\PDO::FETCH_ASSOC);
		return $row ?: null;
	}

	public function ensureTables(): void {
		$db = $this->ichiban->wire('database');
		$db->exec("CREATE TABLE IF NOT EXISTS `ichiban_backlink_snapshots` (
			`id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
			`provider` VARCHAR(32) NOT NULL DEFAULT 'moz',
			`view` VARCHAR(32) NOT NULL DEFAULT 'links',
			`target` VARCHAR(255) NOT NULL DEFAULT '',
			`scope` VARCHAR(32) NOT NULL DEFAULT 'root_domain',
			`row_limit` INT UNSIGNED NOT NULL DEFAULT 5,
			`row_count` INT UNSIGNED NOT NULL DEFAULT 0,
			`fetched_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (`id`),
			KEY `lookup` (`view`, `target`, `scope`, `fetched_at`)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

		$db->exec("CREATE TABLE IF NOT EXISTS `ichiban_backlink_rows` (
			`id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
			`snapshot_id` INT UNSIGNED NOT NULL,
			`row_key` CHAR(40) NOT NULL DEFAULT '',
			`source_url` VARCHAR(1024) NOT NULL DEFAULT '',
			`target_url` VARCHAR(1024) NOT NULL DEFAULT '',
			`source_domain` VARCHAR(255) NOT NULL DEFAULT '',
			`anchor_text` VARCHAR(512) NOT NULL DEFAULT '',
			`http_code` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
			`domain_authority` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
			`spam_score` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
			`last_crawled` VARCHAR(32) NOT NULL DEFAULT '',
			`raw_json` MEDIUMTEXT NOT NULL,
			PRIMARY KEY (`id`),
			KEY `snapshot_id` (`snapshot_id`),
			KEY `row_key` (`row_key`),
			KEY `source_domain` (`source_domain`(191))
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

		$db->exec("CREATE TABLE IF NOT EXISTS `ichiban_backlink_quota` (
			`id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
			`provider` VARCHAR(32) NOT NULL DEFAULT 'moz',
			`path` VARCHAR(128) NOT NULL DEFAULT '',
			`allotted` INT UNSIGNED NOT NULL DEFAULT 0,
			`used` INT UNSIGNED NOT NULL DEFAULT 0,
			`period_start` INT UNSIGNED NOT NULL DEFAULT 0,
			`period_reset` INT UNSIGNED NOT NULL DEFAULT 0,
			`raw_json` TEXT NOT NULL,
			`fetched_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (`id`),
			KEY `provider_fetched` (`provider`, `fetched_at`)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
	}

	protected function rowKey(string $view, array $row): string {
		if ($view === 'anchors') {
			$value = $this->stringValue($row, ['anchor_text', 'anchor', 'text']);
		} elseif ($view === 'domains') {
			$value = $this->stringValue($row, ['root_domain', 'source.root_domain', 'source_root_domain', 'domain']);
		} else {
			$value = implode('|', [
				$this->stringValue($row, ['source.page', 'source_url', 'source_page', 'page']),
				$this->stringValue($row, ['target.page', 'target_url', 'target_page']),
				$this->stringValue($row, ['anchor_text', 'anchor']),
			]);
		}
		return sha1($value);
	}

	protected function stringValue(array $row, array $keys): string {
		foreach ($keys as $key) {
			$value = $this->nestedValue($row, $key);
			if ($value !== null && $value !== '') return is_scalar($value) ? (string)$value : '';
		}
		return '';
	}

	protected function nestedValue(array $row, string $key) {
		if (array_key_exists($key, $row)) return $row[$key];
		if (strpos($key, '.') === false) return null;
		$value = $row;
		foreach (explode('.', $key) as $part) {
			if (!is_array($value) || !array_key_exists($part, $value)) return null;
			$value = $value[$part];
		}
		return $value;
	}
}
