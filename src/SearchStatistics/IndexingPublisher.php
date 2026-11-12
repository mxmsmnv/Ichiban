<?php

/**
 * Google Indexing API write access is separate from Search Console Insights.
 */
class IchibanIndexingPublisher {

	public function __construct(private object $ichiban) {}

	private function credentialsPath(): string {
		$config = $this->ichiban->wire('config');
		$path = realpath((string)($config->ichibanGoogleIndexingCredentialsFile ?? ''));
		$root = realpath((string)$config->paths->root);
		if (!$path || !$root || str_starts_with($path, rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR)) return '';
		return is_file($path) && is_readable($path) ? $path : '';
	}

	public function isConfigured(): bool {
		return $this->credentialsPath() !== '' && function_exists('curl_init') && function_exists('openssl_sign') && class_exists('DOMDocument');
	}

	public function quotaStatus(): array {
		$path = $this->credentialsPath();
		if ($path === '') throw new RuntimeException('The Indexing API service account is not configured.');
		$account = json_decode((string)file_get_contents($path), true);
		return $this->quota($path, $account['project_id'] ?? null)->status();
	}

	private function quota(string $credentialsPath, mixed $project): IchibanIndexingQuota {
		if (!is_string($project) || $project === '') {
			throw new RuntimeException('The Indexing API service account has no Google Cloud project ID.');
		}
		$config = $this->ichiban->wire('config');
		$limit = filter_var($config->ichibanGoogleIndexingDailyLimit ?? 200, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
		if ($limit === false) throw new RuntimeException('Set a positive daily Indexing API limit.');
		$dir = realpath((string)($config->ichibanGoogleIndexingQuotaDirectory ?? dirname($credentialsPath)));
		$root = realpath((string)$config->paths->root);
		if (!$dir || !$root || $dir === $root || str_starts_with($dir, $root . DIRECTORY_SEPARATOR) || !is_dir($dir)) {
			throw new RuntimeException('Use an existing indexing quota directory outside the web root.');
		}
		return new IchibanIndexingQuota($dir . '/ichiban-indexing-quota-' . hash('sha256', $project) . '.json', $limit);
	}

	public function publish(string $url): void {
		if (!$this->isConfigured()) throw new RuntimeException('The Indexing API service account is not configured.');
		$this->assertEligible($url);
		$account = json_decode((string)file_get_contents($this->credentialsPath()), true);
		if (($account['type'] ?? '') !== 'service_account' || empty($account['client_email']) || empty($account['private_key'])) {
			throw new RuntimeException('Invalid Indexing API service account file.');
		}
		$quota = $this->quota($this->credentialsPath(), $account['project_id'] ?? null);
		if ($quota->status()['remaining'] === 0) {
			throw new IchibanIndexingQuotaExceeded('Ichiban reached its daily Indexing API limit.');
		}
		$encode = static fn(string $data): string => rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
		$now = time();
		$claims = [
			'iss' => $account['client_email'],
			'scope' => 'https://www.googleapis.com/auth/indexing',
			'aud' => 'https://oauth2.googleapis.com/token',
			'iat' => $now,
			'exp' => $now + 300,
		];
		$jwt = $encode('{"alg":"RS256","typ":"JWT"}') . '.' . $encode(json_encode($claims, JSON_THROW_ON_ERROR));
		$key = openssl_pkey_get_private($account['private_key']);
		if (!$key || !openssl_sign($jwt, $signature, $key, OPENSSL_ALGO_SHA256)) {
			throw new RuntimeException('Unable to sign the Indexing API token request.');
		}
		$token = $this->request('https://oauth2.googleapis.com/token', http_build_query([
			'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
			'assertion' => $jwt . '.' . $encode($signature),
		]), ['Content-Type: application/x-www-form-urlencoded']);
		$accessToken = $token['access_token'] ?? '';
		if (!is_string($accessToken) || !preg_match('/^[A-Za-z0-9._~-]+$/D', $accessToken)) {
			throw new RuntimeException('Google did not return an Indexing API access token.');
		}
		$quota->reserve();
		$notification = $this->request('https://indexing.googleapis.com/v3/urlNotifications:publish', json_encode([
			'url' => $url,
			'type' => 'URL_UPDATED',
		], JSON_THROW_ON_ERROR), ['Content-Type: application/json', 'Authorization: Bearer ' . $accessToken]);
		if (($notification['urlNotificationMetadata']['url'] ?? '') !== $url) {
			throw new RuntimeException('Google did not confirm the notification URL.');
		}
	}

	public function assertEligible(string $url): void {
		$site = parse_url($this->ichiban->siteUrl());
		$parts = parse_url($url);
		if (($site['scheme'] ?? '') !== 'https' || ($parts['scheme'] ?? '') !== 'https'
			|| empty($site['host']) || strcasecmp($site['host'], $parts['host'] ?? '') !== 0
			|| isset($parts['port']) || isset($site['port'])
			|| isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
			throw new RuntimeException('Enter an HTTPS URL on this site with no query or fragment.');
		}
		$page = $this->ichiban->wire('pages')->get($parts['path'] ?? '/');
		if (!$page->id || !$page->isPublic() || $this->ichiban->pageHttpUrl($page, null, false) !== $url) {
			throw new RuntimeException('Use the public URL of an existing page on this site.');
		}

		$localHead = $this->ichiban->indexingEligibilityHead($page);
		if ($localHead !== null) {
			if (!self::hasEligibleMarkup('<html><head>' . $localHead . '</head></html>', $url)) {
				throw new RuntimeException('The public page needs eligible structured data and a matching canonical URL.');
			}
			return;
		}

		$headers = '';
		$ch = curl_init($url);
		curl_setopt_array($ch, [
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_FOLLOWLOCATION => false,
			CURLOPT_CONNECTTIMEOUT => 5,
			CURLOPT_TIMEOUT => 12,
			CURLOPT_MAXFILESIZE => 2 * 1024 * 1024,
			CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$headers): int {
				if (stripos($line, 'X-Robots-Tag:') === 0) $headers .= $line;
				return strlen($line);
			},
		]);
		$html = curl_exec($ch);
		$status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);
		if ($status !== 200 || !is_string($html) || $html === '') {
			throw new RuntimeException(sprintf('The public URL did not return readable HTML (HTTP %d).', $status));
		}
		if (preg_match('/\b(noindex|none)\b/i', $headers) || !self::hasEligibleMarkup($html, $url)) {
			throw new RuntimeException('The public URL needs indexable JobPosting or livestream markup and a matching canonical URL.');
		}
	}

	public static function hasEligibleMarkup(string $html, string $url): bool {
		$document = new DOMDocument();
		if (!$document->loadHTML($html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)) return false;
		$xpath = new DOMXPath($document);
		foreach ($xpath->query('//meta[@name]') as $meta) {
			if (in_array(strtolower(trim($meta->getAttribute('name'))), ['robots', 'googlebot'], true)
				&& preg_match('/\b(noindex|none)\b/i', $meta->getAttribute('content'))) return false;
		}
		$canonicals = $xpath->query('//head/link[@rel="canonical"]');
		if ($canonicals->length !== 1) return false;
		foreach ($canonicals as $canonical) {
			if ($canonical->getAttribute('href') !== $url) return false;
		}
		foreach ($xpath->query('//script[@type="application/ld+json"]') as $script) {
			$data = json_decode($script->textContent, true);
			if (is_array($data) && self::eligibleNode($data)) return true;
		}
		return false;
	}

	private static function eligibleNode(array $node): bool {
		$types = (array)($node['@type'] ?? []);
		if (in_array('JobPosting', $types, true) && self::isOpenJob($node)) return true;
		if (in_array('VideoObject', $types, true) && is_array($node['publication'] ?? null)) {
			$publications = array_is_list($node['publication']) ? $node['publication'] : [$node['publication']];
			foreach ($publications as $publication) {
				if (is_array($publication) && in_array('BroadcastEvent', (array)($publication['@type'] ?? []), true)) return true;
			}
		}
		$children = $node['@graph'] ?? (array_is_list($node) ? $node : []);
		foreach ((array)$children as $child) {
			if (is_array($child) && self::eligibleNode($child)) return true;
		}
		return false;
	}

	private static function isOpenJob(array $job): bool {
		foreach (['title', 'description', 'datePosted'] as $field) {
			if (!is_string($job[$field] ?? null) || trim(strip_tags($job[$field])) === '') return false;
		}
		$organization = $job['hiringOrganization']['name'] ?? null;
		if (!is_string($organization) || trim($organization) === '') return false;
		$posted = self::postingDate($job['datePosted']);
		if (!$posted || $posted->getTimestamp() > time()) return false;

		if (array_key_exists('validThrough', $job)) {
			$expires = self::postingDate($job['validThrough']);
			if (!$expires) return false;
			if (strlen($job['validThrough']) === 10) $expires = $expires->setTime(23, 59, 59);
			if ($expires->getTimestamp() < time()) return false;
		}
		$locations = $job['jobLocation'] ?? [];
		if (is_array($locations)) {
			$locations = array_is_list($locations) ? $locations : [$locations];
			foreach ($locations as $location) {
				$country = $location['address']['addressCountry'] ?? null;
				if (is_array($country)) $country = $country['name'] ?? null;
				if (is_string($country) && trim($country) !== '') return true;
			}
		}
		if (!in_array('TELECOMMUTE', (array)($job['jobLocationType'] ?? []), true)) return false;
		$regions = $job['applicantLocationRequirements'] ?? [];
		if (!is_array($regions)) return false;
		$regions = array_is_list($regions) ? $regions : [$regions];
		foreach ($regions as $region) {
			$name = $region['name'] ?? null;
			if (is_string($name) && trim($name) !== '') return true;
		}
		return false;
	}

	private static function postingDate(mixed $value): ?DateTimeImmutable {
		if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}(?:T\d{2}:\d{2}(?::\d{2}(?:\.\d+)?)?(?:Z|[+-]\d{2}:?\d{2})?)?$/D', $value)) return null;
		try {
			$date = new DateTimeImmutable($value);
			$errors = DateTimeImmutable::getLastErrors();
			return ($errors['warning_count'] ?? 0) || ($errors['error_count'] ?? 0) ? null : $date;
		} catch (Exception) {
			return null;
		}
	}

	private function request(string $url, string $body, array $headers): array {
		$ch = curl_init($url);
		curl_setopt_array($ch, [
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_POST => true,
			CURLOPT_POSTFIELDS => $body,
			CURLOPT_HTTPHEADER => $headers,
			CURLOPT_CONNECTTIMEOUT => 5,
			CURLOPT_TIMEOUT => 12,
		]);
		$response = curl_exec($ch);
		$status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);
		$data = is_string($response) ? json_decode($response, true) : null;
		if ($status !== 200 || !is_array($data)) {
			throw new RuntimeException("Google Indexing API request failed (HTTP {$status}). Check configuration and quota.");
		}
		return $data;
	}
}
