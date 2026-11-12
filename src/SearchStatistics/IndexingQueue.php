<?php

/** Persist the result for each URL so editing the list preserves progress. */
class IchibanIndexingQueue {

	public function __construct(private object $ichiban) {}

	public function status(): array {
		return $this->process(false);
	}

	public function run(): array {
		return $this->process(true);
	}

	public function report(string $filter, int $page): array {
		$publisher = new IchibanIndexingPublisher($this->ichiban);
		$results = $this->read($publisher->queueStatePath());
		$urls = $this->urls();
		$pending = array_diff($urls, array_keys($results));
		$counts = ['pending' => count($pending), 'notified' => 0, 'review' => 0];
		$current = $counts;
		foreach (array_intersect_key($results, array_fill_keys($urls, true)) as $result) {
			$current[$result['status']]++;
		}
		foreach ($results as $result) {
			$counts[$result['status']]++;
		}
		$rows = match ($filter) {
			'pending' => array_fill_keys($pending, ['status' => 'pending']),
			'notified', 'review' => array_filter($results, static fn($row) => $row['status'] === $filter),
			default => $results + array_fill_keys($pending, ['status' => 'pending']),
		};
		uasort($rows, static fn($a, $b) => ($b['attempted_at'] ?? '') <=> ($a['attempted_at'] ?? ''));
		$pages = max(1, (int)ceil(count($rows) / 50));
		$page = min($pages, max(1, $page));
		return [
			'counts' => $counts,
			'current' => $current,
			'enabled' => (bool)$this->ichiban->get('indexing_queue_enabled'),
			'batch' => $this->batchSize(),
			'quota' => $publisher->quotaStatus(),
			'page' => $page,
			'pages' => $pages,
			'rows' => array_slice($rows, ($page - 1) * 50, 50, true),
		];
	}

	private function urls(): array {
		$input = (string)$this->ichiban->get('indexing_queue_urls');
		return array_values(array_unique(array_filter(array_map('trim', preg_split('/\R/', $input)))));
	}

	private function read(string $path): array {
		if (!file_exists($path)) return [];
		$results = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
		if (!is_array($results)) throw new RuntimeException('The indexing queue results could not be read.');
		return $results;
	}

	private function batchSize(): int {
		$batch = filter_var($this->ichiban->get('indexing_queue_batch') ?: 25, FILTER_VALIDATE_INT, [
			'options' => ['min_range' => 1, 'max_range' => 25],
		]);
		if ($batch === false) throw new RuntimeException('Set an indexing queue batch size from 1 to 25.');
		return $batch;
	}

	private function process(bool $send): array {
		$enabled = (bool)$this->ichiban->get('indexing_queue_enabled');
		$urls = $this->urls();
		if (count($urls) > 2000) throw new RuntimeException('Use at most 2,000 URLs in the indexing queue.');
		$batch = $this->batchSize();
		$publisher = new IchibanIndexingPublisher($this->ichiban);
		$path = $publisher->queueStatePath();
		$lock = @fopen($path . '.lock', 'c');
		if ($lock === false) throw new RuntimeException('Unable to open the indexing queue lock.');

		try {
			if (!flock($lock, ($send ? LOCK_EX : LOCK_SH) | LOCK_NB)) {
				return ['status' => 'running', 'enabled' => $enabled, 'total' => count($urls)];
			}
			$results = $this->read($path);
			$quota = $publisher->quotaStatus();
			if ($send && $enabled && $quota['remaining'] > 0) {
				$this->send($publisher, $path, $urls, $results, min($batch, $quota['remaining']));
				$quota = $publisher->quotaStatus();
			}
			$current = array_intersect_key($results, array_fill_keys($urls, true));
			$notified = count(array_filter($current, static fn($result) => $result['status'] === 'notified'));
			return [
				'status' => 'ready',
				'enabled' => $enabled,
				'total' => count($urls),
				'pending' => count($urls) - count($current),
				'notified' => $notified,
				'review' => count($current) - $notified,
				'quota' => $quota,
				'issues' => array_filter($current, static fn($result) => $result['status'] !== 'notified'),
			];
		} finally {
			fclose($lock);
		}
	}

	private function send(IchibanIndexingPublisher $publisher, string $path, array $urls, array &$results, int $limit): void {
		foreach (array_slice(array_diff($urls, array_keys($results)), 0, $limit) as $url) {
			// Save the intent first so an interrupted request can be reviewed.
			$attempt = ['attempted_at' => gmdate(DATE_ATOM)];
			$results[$url] = ['status' => 'review', 'error' => 'The previous attempt was interrupted.'] + $attempt;
			$this->save($path, $results);
			try {
				$publisher->publish($url);
				$results[$url] = ['status' => 'notified'] + $attempt;
			} catch (IchibanIndexingQuotaExceeded) {
				unset($results[$url]);
				$this->save($path, $results);
				break;
			} catch (RuntimeException $error) {
				$results[$url] = ['status' => 'review', 'error' => $error->getMessage()] + $attempt;
			}
			$this->save($path, $results);
			if (str_contains($results[$url]['error'] ?? '', 'request failed')) break;
		}
	}

	private function save(string $path, array $results): void {
		$temp = @tempnam(dirname($path), '.ichiban-queue-');
		if ($temp === false) throw new RuntimeException('Unable to create the indexing queue state file.');
		try {
			$data = json_encode($results, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
			if (dirname($temp) !== dirname($path)
				|| @file_put_contents($temp, $data) !== strlen($data) || !@rename($temp, $path)) {
				throw new RuntimeException('Unable to save the indexing queue results.');
			}
		} finally {
			if (is_file($temp)) @unlink($temp);
		}
	}
}
