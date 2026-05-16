<?php

/**
 * IchibanSearchStatistics — Google Search Console integration.
 *
 * OAuth 2.0 flow via google/apiclient (optional Composer dependency).
 * Falls back to raw HTTP if library not available.
 * Caches responses in ichiban_gsc_cache table (TTL: 6 hours).
 */
class IchibanSearchStatistics {

	const CACHE_TTL = 21600; // 6 hours in seconds

	protected object $ichiban;
	protected string $lastError = '';

	public function __construct(object $ichiban) {
		$this->ichiban = $ichiban;
	}

	// -------------------------------------------------------------------------
	// OAuth
	// -------------------------------------------------------------------------

	public function isConnected(): bool {
		return (bool)$this->ichiban->get('gsc_access_token');
	}

	public function getAuthUrl(): string {
		$clientId    = $this->ichiban->get('gsc_client_id') ?: '';
		$redirectUri = $this->getRedirectUri();
		$scope       = urlencode('https://www.googleapis.com/auth/webmasters.readonly');
		return "https://accounts.google.com/o/oauth2/v2/auth"
			. "?client_id=" . urlencode($clientId)
			. "&redirect_uri=" . urlencode($redirectUri)
			. "&response_type=code"
			. "&scope={$scope}"
			. "&access_type=offline"
			. "&prompt=consent";
	}

	public function handleCallback(string $code): bool {
		$clientId     = $this->ichiban->get('gsc_client_id') ?: '';
		$clientSecret = $this->ichiban->get('gsc_client_secret') ?: '';
		$redirectUri  = $this->getRedirectUri();

		$http     = $this->ichiban->wire(new \ProcessWire\WireHttp());
		$response = $http->post('https://oauth2.googleapis.com/token', [
			'code'          => $code,
			'client_id'     => $clientId,
			'client_secret' => $clientSecret,
			'redirect_uri'  => $redirectUri,
			'grant_type'    => 'authorization_code',
		]);
		$data = json_decode($response, true);
		if (empty($data['access_token'])) return false;

		$existing = $this->ichiban->wire('modules')->getModuleConfigData('Ichiban');
		$this->ichiban->wire('modules')->saveModuleConfigData('Ichiban', array_merge($existing, [
			'gsc_access_token'  => $data['access_token'],
			'gsc_refresh_token' => $data['refresh_token'] ?? '',
			'gsc_token_expiry'  => time() + (int)($data['expires_in'] ?? 3600),
		]));
		return true;
	}

	public function disconnect(): void {
		$existing = $this->ichiban->wire('modules')->getModuleConfigData('Ichiban');
		$this->ichiban->wire('modules')->saveModuleConfigData('Ichiban', array_merge($existing, [
			'gsc_access_token'  => '',
			'gsc_refresh_token' => '',
			'gsc_token_expiry'  => 0,
		]));
		$this->ichiban->wire('database')->exec("DELETE FROM ichiban_gsc_cache");
		$this->ensureIndexingCacheTable();
		$this->ichiban->wire('database')->exec("DELETE FROM ichiban_gsc_indexing_cache");
	}

	protected function getRedirectUri(): string {
		$adminUrl = $this->ichiban->wire('config')->urls->httpAdmin;
		return rtrim($adminUrl, '/') . '/ichiban/search-statistics/';
	}

	// -------------------------------------------------------------------------
	// Data fetch
	// -------------------------------------------------------------------------

	public function getDashboardData(int $days = 28): array {
		$siteUrl  = $this->getSiteUrl();
		$endDate  = date('Y-m-d');
		$startDate = date('Y-m-d', strtotime("-{$days} days"));

		$cacheKey  = "dashboard_{$days}";
		$cached    = $this->getCache($siteUrl, $cacheKey);
		if ($cached) return $cached;

		$token    = $this->getAccessToken();
		if (!$token) return [];

		$payload  = json_encode([
			'startDate'  => $startDate,
			'endDate'    => $endDate,
			'dimensions' => [],
		]);
		$response = $this->apiRequest("https://searchconsole.googleapis.com/webmasters/v3/sites/" . urlencode($siteUrl) . "/searchAnalytics/query", $token, $payload);
		$data     = json_decode($response, true);
		$row      = $data['rows'][0] ?? [];
		$result   = [
			'clicks'      => $row['clicks']      ?? 0,
			'impressions' => $row['impressions']  ?? 0,
			'ctr'         => isset($row['ctr']) ? round($row['ctr'] * 100, 2) . '%' : '0%',
			'position'    => isset($row['position']) ? round($row['position'], 1) : 0,
		];
		$this->setCache($siteUrl, $cacheKey, $result);
		return $result;
	}

	public function getTopPages(int $days = 28, int $limit = 25): array {
		return $this->getDimensionRows(['page'], $days, $limit, "pages_{$days}_{$limit}");
	}

	public function getTopQueries(int $days = 28, int $limit = 25): array {
		return $this->getDimensionRows(['query'], $days, $limit, "queries_{$days}_{$limit}");
	}

	public function getDailyRows(int $days = 28): array {
		$rows = $this->getDimensionRows(['date'], $days, $days + 2, "dates_{$days}");
		return $this->fillDailyRows($rows, $days);
	}

	public function getTopCountries(int $days = 28, int $limit = 25): array {
		return $this->getDimensionRows(['country'], $days, $limit, "countries_{$days}_{$limit}");
	}

	public function getTopDevices(int $days = 28, int $limit = 25): array {
		return $this->getDimensionRows(['device'], $days, $limit, "devices_{$days}_{$limit}");
	}

	public function getSearchAppearances(int $days = 28, int $limit = 25): array {
		return $this->getDimensionRows(['searchAppearance'], $days, $limit, "appearance_{$days}_{$limit}");
	}

	public function getPageData(string $pageUrl, int $days = 28): array {
		$rows = $this->getDimensionRows(['page'], $days, 1, 'page_' . md5($pageUrl), [
			['dimension' => 'page', 'operator' => 'equals', 'expression' => $pageUrl],
		]);
		return $rows[0] ?? ['clicks' => 0, 'impressions' => 0, 'ctr' => '0%', 'position' => 0];
	}

	public function refreshIndexingIssues(int $limit = 50): int {
		$this->lastError = '';
		$this->ensureIndexingCacheTable();
		$token = $this->getAccessToken();
		if (!$token) {
			$this->lastError = 'Missing Google access token. Reconnect Search Console.';
			return 0;
		}

		$count = 0;
		$urls = $this->getInspectableUrls($limit);
		if (!$urls) {
			$this->lastError = 'No public URLs found to inspect.';
			return 0;
		}
		foreach ($urls as $url) {
			$result = $this->inspectUrl($url, $token);
			if (!$result) continue;
			$this->setIndexingCache($url, $result);
			$count++;
		}
		if ($count === 0 && $this->lastError === '') {
			$this->lastError = 'Google returned no URL Inspection results.';
		}
		return $count;
	}

	public function getLastError(): string {
		return $this->lastError;
	}

	public function getIndexingIssues(int $limit = 10): array {
		$this->ensureIndexingCacheTable();
		$db = $this->ichiban->wire('database');
		$rows = $db->query("SELECT * FROM ichiban_gsc_indexing_cache ORDER BY checked_at DESC, url ASC")->fetchAll(\PDO::FETCH_ASSOC) ?: [];
		$summary = [
			'total' => 0,
			'indexed' => 0,
			'issues' => 0,
			'checked_at' => 0,
			'groups' => [],
			'rows' => [],
		];
		foreach ($rows as $row) {
			$summary['total']++;
			$checked = strtotime((string)($row['checked_at'] ?? '')) ?: 0;
			if ($checked > $summary['checked_at']) $summary['checked_at'] = $checked;
			$verdict = strtoupper((string)($row['verdict'] ?? ''));
			$coverage = trim((string)($row['coverage_state'] ?? ''));
			$isIndexed = $this->isIndexedVerdict($verdict, $coverage);
			if ($isIndexed) {
				$summary['indexed']++;
				continue;
			}
			$summary['issues']++;
			$key = $coverage !== '' ? $coverage : ($verdict ?: 'Unknown');
			if (!isset($summary['groups'][$key])) {
				$summary['groups'][$key] = ['reason' => $key, 'count' => 0, 'examples' => []];
			}
			$summary['groups'][$key]['count']++;
			if (count($summary['groups'][$key]['examples']) < 3) {
				$summary['groups'][$key]['examples'][] = (string)$row['url'];
			}
			if (count($summary['rows']) < $limit) {
				$summary['rows'][] = [
					'url' => (string)$row['url'],
					'verdict' => $verdict,
					'coverage_state' => $coverage,
					'last_crawl_time' => (string)($row['last_crawl_time'] ?? ''),
					'inspection_link' => (string)($row['inspection_link'] ?? ''),
				];
			}
		}
		usort($summary['groups'], static fn(array $a, array $b): int => $b['count'] <=> $a['count']);
		return $summary;
	}

	protected function getDimensionRows(array $dimensions, int $days, int $limit, string $cacheKey, array $dimensionFilterGroups = []): array {
		$siteUrl  = $this->getSiteUrl();
		$token = $this->getAccessToken();
		if (!$token) return [];

		$rows = [];
		$rowLimit = $limit > 0 ? $limit : 25000;
		$startRow = 0;
		do {
			$payload = [
				'startDate' => date('Y-m-d', strtotime("-{$days} days")),
				'endDate' => date('Y-m-d'),
				'dimensions' => $dimensions,
				'rowLimit' => min($rowLimit, 25000),
				'startRow' => $startRow,
			];
			if ($dimensionFilterGroups) {
				$payload['dimensionFilterGroups'] = [[
					'filters' => $dimensionFilterGroups,
				]];
			}
			$response = $this->apiRequest("https://searchconsole.googleapis.com/webmasters/v3/sites/" . urlencode($siteUrl) . "/searchAnalytics/query", $token, json_encode($payload));
			$data = json_decode($response, true);
			$batch = $data['rows'] ?? [];
			foreach ($batch as $row) {
				$key = $row['keys'][0] ?? '';
				$rows[] = [
					'key' => $key,
					'clicks' => (int)($row['clicks'] ?? 0),
					'impressions' => (int)($row['impressions'] ?? 0),
					'ctr' => isset($row['ctr']) ? round($row['ctr'] * 100, 2) . '%' : '0%',
					'position' => isset($row['position']) ? round($row['position'], 1) : 0,
				];
			}
			$startRow += count($batch);
			if ($limit > 0 || count($batch) < 25000) break;
		} while (count($batch) > 0);

		return $rows;
	}

	protected function inspectUrl(string $url, string $token): array {
		$payload = json_encode([
			'inspectionUrl' => $url,
			'siteUrl' => $this->getSiteUrl(),
		]);
		$response = $this->apiRequest('https://searchconsole.googleapis.com/v1/urlInspection/index:inspect', $token, $payload);
		$data = json_decode($response, true);
		if (!empty($data['error']['message'])) {
			$this->lastError = (string)$data['error']['message'];
			return [];
		}
		$result = $data['inspectionResult'] ?? [];
		$index = $result['indexStatusResult'] ?? [];
		if (!$index) {
			$this->lastError = 'URL Inspection response did not include indexStatusResult.';
			return [];
		}
		return [
			'verdict' => (string)($index['verdict'] ?? ''),
			'coverage_state' => (string)($index['coverageState'] ?? ''),
			'indexing_state' => (string)($index['indexingState'] ?? ''),
			'last_crawl_time' => (string)($index['lastCrawlTime'] ?? ''),
			'google_canonical' => (string)($index['googleCanonical'] ?? ''),
			'user_canonical' => (string)($index['userCanonical'] ?? ''),
			'inspection_link' => (string)($result['inspectionResultLink'] ?? ''),
		];
	}

	protected function getInspectableUrls(int $limit): array {
		$urls = [];
		foreach ($this->getTopPages(28, min(50, $limit)) as $row) {
			$url = (string)($row['key'] ?? '');
			if (preg_match('!^https?://!i', $url)) $urls[$url] = true;
		}

		$pages = $this->ichiban->wire('pages');
		foreach ($pages->find("template!=admin, include=hidden, limit={$limit}, sort=path") as $page) {
			if (method_exists($page, 'isPublic') && !$page->isPublic()) continue;
			$url = method_exists($page, 'httpUrl') ? $page->httpUrl() : '';
			if ($url && preg_match('!^https?://!i', $url)) $urls[$url] = true;
			if (count($urls) >= $limit) break;
		}
		return array_slice(array_keys($urls), 0, $limit);
	}

	protected function isIndexedVerdict(string $verdict, string $coverage): bool {
		if ($verdict === 'PASS') return true;
		return stripos($coverage, 'indexed') !== false && stripos($coverage, 'not indexed') === false;
	}

	protected function fillDailyRows(array $rows, int $days): array {
		if (!$rows) return [];

		$byDate = [];
		foreach ($rows as $row) {
			$key = (string)($row['key'] ?? '');
			if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $key)) continue;
			$byDate[$key] = $row;
		}
		if (!$byDate) return $rows;

		ksort($byDate);
		$dates = array_keys($byDate);
		$end = new \DateTimeImmutable(end($dates));
		$start = $end->modify('-' . max(0, $days - 1) . ' days');

		$filled = [];
		for ($date = $start; $date <= $end; $date = $date->modify('+1 day')) {
			$key = $date->format('Y-m-d');
			$filled[] = $byDate[$key] ?? [
				'key' => $key,
				'clicks' => 0,
				'impressions' => 0,
				'ctr' => '0%',
				'position' => 0,
			];
		}
		return $filled;
	}

	// -------------------------------------------------------------------------
	// Token management
	// -------------------------------------------------------------------------

	protected function getAccessToken(): ?string {
		$token   = $this->ichiban->get('gsc_access_token');
		$expiry  = (int)$this->ichiban->get('gsc_token_expiry');
		if (!$token && $this->ichiban->get('gsc_refresh_token')) {
			$token = $this->refreshToken();
		}
		if (!$token) return null;
		if ($expiry && $expiry < time() + 60) {
			$token = $this->refreshToken();
		}
		return $token ?: null;
	}

	protected function getSiteUrl(): string {
		$configured = trim((string)($this->ichiban->get('gsc_site_url') ?: ''));
		if ($configured !== '') {
			if (stripos($configured, 'sc-domain:') === 0) return $configured;
			if (!preg_match('~^https?://~i', $configured)) return 'sc-domain:' . trim($configured, '/');
			return rtrim($configured, '/') . '/';
		}
		return rtrim($this->ichiban->wire('config')->urls->httpRoot, '/') . '/';
	}

	public function getPropertyId(): string {
		return $this->getSiteUrl();
	}

	protected function refreshToken(): ?string {
		$clientId     = $this->ichiban->get('gsc_client_id') ?: '';
		$clientSecret = $this->ichiban->get('gsc_client_secret') ?: '';
		$refreshToken = $this->ichiban->get('gsc_refresh_token') ?: '';
		if (!$refreshToken) return null;

		$http     = $this->ichiban->wire(new \ProcessWire\WireHttp());
		$response = $http->post('https://oauth2.googleapis.com/token', [
			'client_id'     => $clientId,
			'client_secret' => $clientSecret,
			'refresh_token' => $refreshToken,
			'grant_type'    => 'refresh_token',
		]);
		$data = json_decode($response, true);
		if (empty($data['access_token'])) return null;

		$existing = $this->ichiban->wire('modules')->getModuleConfigData('Ichiban');
		$this->ichiban->wire('modules')->saveModuleConfigData('Ichiban', array_merge($existing, [
			'gsc_access_token' => $data['access_token'],
			'gsc_token_expiry' => time() + (int)($data['expires_in'] ?? 3600),
		]));
		return $data['access_token'];
	}

	protected function apiRequest(string $url, string $token, string $body = ''): string {
		if (!function_exists('curl_init')) {
			$this->lastError = 'PHP cURL is not available.';
			return '';
		}
		$ch = curl_init($url);
		curl_setopt_array($ch, [
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_POST           => true,
			CURLOPT_POSTFIELDS     => $body,
			CURLOPT_CONNECTTIMEOUT => 10,
			CURLOPT_TIMEOUT        => 20,
			CURLOPT_HTTPHEADER     => [
				'Authorization: Bearer ' . $token,
				'Content-Type: application/json',
			],
		]);
		$response = curl_exec($ch);
		$error = curl_error($ch);
		$httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);
		if ($response === false) {
			$this->lastError = $error ?: 'cURL request failed.';
			return '';
		}
		if ($httpCode >= 400) {
			$data = json_decode((string)$response, true);
			$message = $data['error']['message'] ?? '';
			$this->lastError = $message ? "Google API HTTP {$httpCode}: {$message}" : "Google API HTTP {$httpCode}.";
		}
		return $response ?: '';
	}

	// -------------------------------------------------------------------------
	// Cache
	// -------------------------------------------------------------------------

	protected function getCache(string $pageUrl, string $cacheKey): ?array {
		$db   = $this->ichiban->wire('database');
		$stmt = $db->prepare("SELECT * FROM ichiban_gsc_cache WHERE page_url=:url AND `query`=:q AND TIMESTAMPDIFF(SECOND, cached_at, NOW()) < :ttl LIMIT 1");
		$stmt->bindValue(':url', $pageUrl);
		$stmt->bindValue(':q', $cacheKey);
		$stmt->bindValue(':ttl', self::CACHE_TTL, \PDO::PARAM_INT);
		$stmt->execute();
		$row = $stmt->fetch(\PDO::FETCH_ASSOC);
		if (!$row) return null;
		// clicks/impressions/ctr/position stored as columns — reassemble array
		return [
			'clicks'      => (int)$row['clicks'],
			'impressions' => (int)$row['impressions'],
			'ctr'         => $row['ctr'] . '%',
			'position'    => (float)$row['position'],
		];
	}

	public function getLastCacheTime(): int {
		$db = $this->ichiban->wire('database');
		$stmt = $db->prepare("SELECT UNIX_TIMESTAMP(MAX(cached_at)) FROM ichiban_gsc_cache WHERE page_url=:url");
		$stmt->execute([':url' => $this->getSiteUrl()]);
		return (int)$stmt->fetchColumn();
	}

	protected function setCache(string $pageUrl, string $cacheKey, array $data): void {
		$db     = $this->ichiban->wire('database');
		$clicks = (int)($data['clicks'] ?? 0);
		$impr   = (int)($data['impressions'] ?? 0);
		$ctr    = (float)(str_replace('%', '', $data['ctr'] ?? '0'));
		$pos    = (float)($data['position'] ?? 0);
		// Use separate insert/update params to avoid PDO duplicate placeholder issue
		$stmt = $db->prepare("INSERT INTO ichiban_gsc_cache (page_url, `query`, clicks, impressions, ctr, position, date_range)
			VALUES (:url, :q, :i_clicks, :i_impr, :i_ctr, :i_pos, '28d')
			ON DUPLICATE KEY UPDATE clicks=:u_clicks, impressions=:u_impr, ctr=:u_ctr, position=:u_pos, cached_at=NOW()");
		$stmt->execute([
			':url'     => $pageUrl,
			':q'       => $cacheKey,
			':i_clicks' => $clicks, ':u_clicks' => $clicks,
			':i_impr'   => $impr,   ':u_impr'   => $impr,
			':i_ctr'    => $ctr,    ':u_ctr'    => $ctr,
			':i_pos'    => $pos,    ':u_pos'    => $pos,
		]);
	}

	protected function ensureIndexingCacheTable(): void {
		$db = $this->ichiban->wire('database');
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
	}

	protected function setIndexingCache(string $url, array $data): void {
		$db = $this->ichiban->wire('database');
		$stmt = $db->prepare("INSERT INTO ichiban_gsc_indexing_cache
			(url, verdict, coverage_state, indexing_state, last_crawl_time, google_canonical, user_canonical, inspection_link)
			VALUES (:url, :verdict, :coverage, :indexing, :crawl, :google, :user, :link)
			ON DUPLICATE KEY UPDATE verdict=:u_verdict, coverage_state=:u_coverage, indexing_state=:u_indexing,
				last_crawl_time=:u_crawl, google_canonical=:u_google, user_canonical=:u_user,
				inspection_link=:u_link, checked_at=NOW()");
		$params = [
			':url' => $url,
			':verdict' => (string)($data['verdict'] ?? ''),
			':coverage' => (string)($data['coverage_state'] ?? ''),
			':indexing' => (string)($data['indexing_state'] ?? ''),
			':crawl' => (string)($data['last_crawl_time'] ?? ''),
			':google' => (string)($data['google_canonical'] ?? ''),
			':user' => (string)($data['user_canonical'] ?? ''),
			':link' => (string)($data['inspection_link'] ?? ''),
			':u_verdict' => (string)($data['verdict'] ?? ''),
			':u_coverage' => (string)($data['coverage_state'] ?? ''),
			':u_indexing' => (string)($data['indexing_state'] ?? ''),
			':u_crawl' => (string)($data['last_crawl_time'] ?? ''),
			':u_google' => (string)($data['google_canonical'] ?? ''),
			':u_user' => (string)($data['user_canonical'] ?? ''),
			':u_link' => (string)($data['inspection_link'] ?? ''),
		];
		$stmt->execute($params);
	}

}
