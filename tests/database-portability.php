<?php

$root = dirname(__DIR__);
$process = (string)file_get_contents($root . '/ProcessIchiban.module.php');
$module = (string)file_get_contents($root . '/Ichiban.module.php');
$audit = (string)file_get_contents($root . '/src/Audit/AuditEngine.php');
$searchStatistics = (string)file_get_contents($root . '/src/SearchStatistics/SearchStatistics.php');

require_once $root . '/src/SearchStatistics/SearchStatistics.php';

final class IchibanSearchStatisticsPortabilityProbe extends IchibanSearchStatistics {
	public function cacheRowIsFresh(array $row, int $now): bool {
		return $this->isCacheRowFresh($row, $now);
	}
}

$cacheProbe = new IchibanSearchStatisticsPortabilityProbe(new stdClass());
$now = strtotime('2026-09-26 12:00:00');

$checks = [
	'migration backup uses portable create-as-select' => str_contains($process, 'CREATE TABLE `$backup` AS SELECT * FROM `$table`'),
	'migration backup does not use MySQL CREATE TABLE LIKE' => !str_contains($process, 'CREATE TABLE `$backup` LIKE `$table`'),
	'audit upserts refresh the timestamp without relying on MySQL ON UPDATE' => str_contains($audit, 'indexed_at=UTC_TIMESTAMP()'),
	'Search Console cache lookup avoids unsupported MySQL date arithmetic' => !str_contains($searchStatistics, 'TIMESTAMPDIFF('),
	'fresh Search Console cache rows remain usable' => $cacheProbe->cacheRowIsFresh(['cached_at' => '2026-09-26 11:59:59'], $now),
	'expired Search Console cache rows are rejected at the TTL boundary' => !$cacheProbe->cacheRowIsFresh(['cached_at' => '2026-09-26 06:00:00'], $now),
	'invalid Search Console cache timestamps are rejected' => !$cacheProbe->cacheRowIsFresh(['cached_at' => 'not-a-date'], $now),
	'release version is synchronized' => str_contains($module, "'version'  => 37") && str_contains($module, '@version 0.3.7-alpha'),
];

$failed = [];
foreach($checks as $label => $passed) {
	echo ($passed ? 'PASS' : 'FAIL') . ' ' . $label . PHP_EOL;
	if(!$passed) $failed[] = $label;
}
exit($failed ? 1 : 0);
