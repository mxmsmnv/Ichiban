<?php namespace ProcessWire;

if (PHP_SAPI !== 'cli') {
	fwrite(STDERR, "CLI only\n");
	exit(2);
}

$siteRoot = rtrim((string)($argv[1] ?? ''), '/');
if ($siteRoot === '') {
	echo "SKIP Search statistics runtime test requires a ProcessWire root.\n";
	exit(0);
}
if (!is_file($siteRoot . '/index.php')) {
	fwrite(STDERR, "Usage: php tests/search-statistics-runtime.php /path/to/processwire\n");
	exit(2);
}

require $siteRoot . '/index.php';

$wire = wire();
$module = $wire->modules->get('Ichiban');
if (!$module instanceof Ichiban) {
	throw new \RuntimeException('Ichiban is not installed.');
}

$database = $wire->database;
$service = $module->getSearchStatistics();
$getCache = new \ReflectionMethod($service, 'getCache');
$fixtureUrl = 'https://ichiban-portability.invalid/';
$fixtureKey = 'dashboard_runtime_portability';
$delete = $database->prepare("DELETE FROM ichiban_gsc_cache WHERE page_url=:url AND `query`=:query");
$delete->execute([':url' => $fixtureUrl, ':query' => $fixtureKey]);

try {
	$insert = $database->prepare("INSERT INTO ichiban_gsc_cache
		(page_url, `query`, clicks, impressions, ctr, position, date_range)
		VALUES (:url, :query, 12, 345, 6.5, 7.25, '28d')");
	$insert->execute([
		':url' => $fixtureUrl,
		':query' => $fixtureKey,
	]);

	$fresh = $getCache->invoke($service, $fixtureUrl, $fixtureKey);
	if ($fresh !== ['clicks' => 12, 'impressions' => 345, 'ctr' => '6.5%', 'position' => 7.25]) {
		throw new \RuntimeException('Fresh Search Console cache row was not returned correctly: ' . json_encode($fresh));
	}

	$expire = $database->prepare("UPDATE ichiban_gsc_cache SET cached_at=:cached_at WHERE page_url=:url AND `query`=:query");
	$expire->execute([
		':cached_at' => '2000-01-01 00:00:00',
		':url' => $fixtureUrl,
		':query' => $fixtureKey,
	]);
	if ($getCache->invoke($service, $fixtureUrl, $fixtureKey) !== null) {
		throw new \RuntimeException('Expired Search Console cache row was returned.');
	}
} finally {
	$delete->execute([':url' => $fixtureUrl, ':query' => $fixtureKey]);
}

echo json_encode([
	'driver' => $database->getAttribute(\PDO::ATTR_DRIVER_NAME),
	'status' => 'ok',
], JSON_UNESCAPED_SLASHES) . PHP_EOL;
