<?php

/**
 * IchibanBacklinksMoz — small Moz Links API client.
 *
 * Keeps provider-specific authentication and response normalization out of the
 * Process module so the Backlinks section can support other providers later.
 */
class IchibanBacklinksMoz {

	protected object $ichiban;
	protected string $lastError = '';
	protected int $lastHttpCode = 0;

	public function __construct(object $ichiban) {
		$this->ichiban = $ichiban;
	}

	public function isConfigured(): bool {
		return $this->getApiToken() !== '' || ($this->getAccessId() !== '' && $this->getSecretKey() !== '');
	}

	public function getLastError(): string {
		return $this->lastError;
	}

	public function getLastHttpCode(): int {
		return $this->lastHttpCode;
	}

	public function getLinks(string $target, int $limit = 25, string $scope = 'root_domain'): array {
		$data = $this->post('/links', [
			'target' => $target,
			'target_scope' => $scope,
			'filter' => 'external',
			'sort' => 'source_domain_authority',
			'limit' => $this->clampLimit($limit),
		]);
		return $this->normalizeRows($data);
	}

	public function getLinkingRootDomains(string $target, int $limit = 25, string $scope = 'root_domain'): array {
		$data = $this->post('/linking_root_domains', [
			'target' => $target,
			'target_scope' => $scope,
			'sort' => 'source_domain_authority',
			'limit' => $this->clampLimit($limit),
		]);
		return $this->normalizeRows($data);
	}

	public function getAnchorText(string $target, int $limit = 25, string $scope = 'root_domain'): array {
		$data = $this->post('/anchor_text', [
			'target' => $target,
			'target_scope' => $scope,
			'limit' => $this->clampLimit($limit),
		]);
		return $this->normalizeRows($data);
	}

	public function getQuota(string $path = 'api.limits.data.rows'): array {
		$this->lastError = '';
		$this->lastHttpCode = 0;
		$apiToken = $this->getApiToken();
		if ($apiToken === '') {
			$this->lastError = 'Moz quota lookup requires the new API token.';
			return [];
		}
		if (!function_exists('curl_init')) {
			$this->lastError = 'PHP cURL is not available.';
			return [];
		}
		$body = json_encode([
			'jsonrpc' => '2.0',
			'id' => $this->uuid(),
			'method' => 'quota.lookup',
			'params' => [
				'data' => [
					'path' => $path,
				],
			],
		]);
		if ($body === false) {
			$this->lastError = 'Could not encode Moz quota request.';
			return [];
		}
		$ch = curl_init('https://api.moz.com/jsonrpc');
		curl_setopt_array($ch, [
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_POST => true,
			CURLOPT_POSTFIELDS => $body,
			CURLOPT_CONNECTTIMEOUT => 10,
			CURLOPT_TIMEOUT => $this->getTimeout(),
			CURLOPT_HTTPHEADER => [
				'Content-Type: application/json',
				'Accept: application/json',
				'x-moz-token: ' . $apiToken,
			],
		]);
		$response = curl_exec($ch);
		$error = curl_error($ch);
		$this->lastHttpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);
		if ($response === false) {
			$this->lastError = $error ?: 'Moz quota request failed.';
			return [];
		}
		$data = json_decode((string)$response, true);
		if (!is_array($data)) {
			$this->lastError = 'Moz quota response was unreadable.';
			return [];
		}
		if ($this->lastHttpCode >= 400 || isset($data['error'])) {
			$message = $data['error']['message'] ?? $data['message'] ?? '';
			$this->lastError = $message ? "Moz quota HTTP {$this->lastHttpCode}: {$message}" : "Moz quota HTTP {$this->lastHttpCode}.";
			return [];
		}
		$quota = $data['result']['quota'] ?? $data['quota'] ?? [];
		return is_array($quota) ? $quota : [];
	}

	protected function post(string $path, array $payload): array {
		$this->lastError = '';
		$this->lastHttpCode = 0;

		if (!$this->isConfigured()) {
			$this->lastError = 'Moz API credentials are missing.';
			return [];
		}
		if (!function_exists('curl_init')) {
			$this->lastError = 'PHP cURL is not available.';
			return [];
		}

		$url = rtrim($this->getBaseUrl(), '/') . '/' . ltrim($path, '/');
		$body = json_encode($payload);
		if ($body === false) {
			$this->lastError = 'Could not encode Moz API request.';
			return [];
		}

		$ch = curl_init($url);
		$headers = [
			'Content-Type: application/json',
			'Accept: application/json',
		];
		$apiToken = $this->getApiToken();
		if ($apiToken !== '') {
			$headers[] = 'x-moz-token: ' . $apiToken;
		}
		curl_setopt_array($ch, [
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_POST => true,
			CURLOPT_POSTFIELDS => $body,
			CURLOPT_CONNECTTIMEOUT => 10,
			CURLOPT_TIMEOUT => $this->getTimeout(),
			CURLOPT_HTTPHEADER => $headers,
		]);
		if ($apiToken === '') {
			curl_setopt($ch, CURLOPT_USERPWD, $this->getAccessId() . ':' . $this->getSecretKey());
			curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
		}
		$response = curl_exec($ch);
		$error = curl_error($ch);
		$this->lastHttpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);

		if ($response === false) {
			$this->lastError = $error ?: 'Moz API request failed.';
			return [];
		}

		$data = json_decode((string)$response, true);
		if (!is_array($data)) {
			$this->lastError = 'Moz API returned an unreadable response.';
			return [];
		}

		if ($this->lastHttpCode >= 400) {
			$message = $data['error']['message'] ?? $data['message'] ?? $data['error'] ?? '';
			$this->lastError = $message ? "Moz API HTTP {$this->lastHttpCode}: {$message}" : "Moz API HTTP {$this->lastHttpCode}.";
			return [];
		}

		return $data;
	}

	protected function normalizeRows(array $data): array {
		foreach (['results', 'items', 'rows', 'data'] as $key) {
			if (isset($data[$key]) && is_array($data[$key])) return $data[$key];
		}
		if ($this->isListArray($data)) return $data;
		return [];
	}

	protected function isListArray(array $data): bool {
		$i = 0;
		foreach ($data as $key => $_value) {
			if ($key !== $i++) return false;
		}
		return true;
	}

	protected function clampLimit(int $limit): int {
		return max(1, min(1000, $limit));
	}

	protected function getBaseUrl(): string {
		$url = trim((string)($this->ichiban->get('moz_api_base_url') ?: ''));
		return $url !== '' ? $url : 'https://lsapi.seomoz.com/v2';
	}

	protected function getApiToken(): string {
		return trim((string)($this->ichiban->get('moz_api_token') ?: ''));
	}

	protected function getAccessId(): string {
		return trim((string)($this->ichiban->get('moz_access_id') ?: ''));
	}

	protected function getSecretKey(): string {
		return trim((string)($this->ichiban->get('moz_secret_key') ?: ''));
	}

	protected function getTimeout(): int {
		return max(5, min(120, (int)($this->ichiban->get('moz_timeout') ?: 20)));
	}

	protected function uuid(): string {
		$data = random_bytes(16);
		$data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
		$data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
		return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
	}
}
