<?php

class IchibanIndexingQuotaExceeded extends RuntimeException {}

class IchibanIndexingQuota {

	public function __construct(private string $path, private int $limit) {}

	public function status(): array {
		return $this->withLock(false);
	}

	public function reserve(): void {
		$this->withLock(true);
	}

	private function withLock(bool $reserve): array {
		$lock = @fopen($this->path . '.lock', 'c');
		if ($lock === false) throw new RuntimeException('Ichiban cannot open the indexing quota ledger.');
		try {
			if (!flock($lock, $reserve ? LOCK_EX : LOCK_SH)) {
				throw new RuntimeException('Ichiban cannot lock the indexing quota ledger.');
			}

			$now = new DateTimeImmutable('now', new DateTimeZone('America/Los_Angeles'));
			$day = $now->format('Y-m-d');
			$used = $this->usedOn($day);
			if ($reserve) {
				if ($used >= $this->limit) {
					throw new IchibanIndexingQuotaExceeded('Ichiban reached its daily Indexing API limit.');
				}
				$this->write($day, ++$used);
			}

			return [
				'source' => 'ichiban',
				'limit' => $this->limit,
				'used' => $used,
				'remaining' => max(0, $this->limit - $used),
				'resets_at' => $now->modify('tomorrow')->setTime(0, 0)->format(DATE_ATOM),
			];
		} finally {
			fclose($lock);
		}
	}

	private function usedOn(string $day): int {
		clearstatcache(true, $this->path);
		if (!file_exists($this->path)) return 0;
		$data = json_decode((string)@file_get_contents($this->path), true);
		if (!is_array($data) || !is_string($data['day'] ?? null)
			|| !preg_match('/^\d{4}-\d{2}-\d{2}$/D', $data['day'])
			|| $data['day'] > $day || !is_int($data['used'] ?? null) || $data['used'] < 0) {
			throw new RuntimeException('Ichiban cannot read the indexing quota ledger.');
		}
		return $data['day'] === $day ? $data['used'] : 0;
	}

	private function write(string $day, int $used): void {
		$temp = @tempnam(dirname($this->path), '.ichiban-quota-');
		if ($temp === false) throw new RuntimeException('Ichiban cannot create an indexing quota record.');
		try {
			$record = json_encode(['day' => $day, 'used' => $used], JSON_THROW_ON_ERROR);
			if (realpath(dirname($temp)) !== realpath(dirname($this->path))
				|| @file_put_contents($temp, $record) !== strlen($record)
				|| !@rename($temp, $this->path)) {
				throw new RuntimeException('Ichiban cannot save the indexing quota ledger.');
			}
		} finally {
			if (is_file($temp)) @unlink($temp);
		}
	}
}
