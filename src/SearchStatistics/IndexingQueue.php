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

	private function process(bool $send): array {
		$enabled = (bool)$this->ichiban->get('indexing_queue_enabled');
		$input = (string)$this->ichiban->get('indexing_queue_urls');
		$urls = array_values(array_unique(array_filter(array_map('trim', preg_split('/\R/', $input)))));
		if (count($urls) > 2000) throw new RuntimeException('Use at most 2,000 URLs in the indexing queue.');
		$batch = filter_var($this->ichiban->get('indexing_queue_batch') ?: 25, FILTER_VALIDATE_INT, [
			'options' => ['min_range' => 1, 'max_range' => 25],
		]);
		if ($batch === false) throw new RuntimeException('Set an indexing queue batch size from 1 to 25.');
		$publisher = new IchibanIndexingPublisher($this->ichiban);
		$path = $publisher->queueStatePath();
		$lock = @fopen($path . '.lock', 'c');
		if ($lock === false) throw new RuntimeException('Unable to open the indexing queue lock.');

		try {
			if (!flock($lock, ($send ? LOCK_EX : LOCK_SH) | LOCK_NB)) {
				return ['status' => 'running', 'enabled' => $enabled, 'total' => count($urls)];
			}
			$results = file_exists($path) ? json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR) : [];
			if (!is_array($results)) throw new RuntimeException('The indexing queue results could not be read.');
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
			$results[$url] = ['status' => 'review', 'error' => 'The previous attempt was interrupted.'];
			$this->save($path, $results);
			try {
				$publisher->publish($url);
				$results[$url] = ['status' => 'notified'];
			} catch (IchibanIndexingQuotaExceeded) {
				unset($results[$url]);
				$this->save($path, $results);
				break;
			} catch (RuntimeException $error) {
				$results[$url] = ['status' => 'review', 'error' => $error->getMessage()];
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
