<?php

declare(strict_types=1);

$moduleRoot = dirname(__DIR__);
$installBase = 'site/modules/Ichiban';
$files = [
	'Ichiban.module.php',
	'ProcessIchiban.module.php',
	'InputfieldIchiban.module.php',
	'src/Ai/SquadBridge.php',
];

$outputDir = $moduleRoot . '/languages/processwire';
$catalogFile = $moduleRoot . '/languages/catalog.json';

if (!is_dir($outputDir) && !mkdir($outputDir, 0775, true) && !is_dir($outputDir)) {
	fwrite(STDERR, "Unable to create output directory: {$outputDir}\n");
	exit(1);
}

$catalog = [];

foreach ($files as $relativeFile) {
	$sourceFile = $moduleRoot . '/' . $relativeFile;
	if (!is_file($sourceFile)) {
		fwrite(STDERR, "Missing source file: {$sourceFile}\n");
		continue;
	}

	$installedFile = $installBase . '/' . $relativeFile;
	$textdomain = filenameToTextdomain($installedFile);
	$strings = extractTranslations($sourceFile);
	$translations = [];

	foreach ($strings as $entry) {
		$hash = md5($entry['text'] . $entry['context']);
		$catalog[] = [
			'file' => $installedFile,
			'textdomain' => $textdomain,
			'hash' => $hash,
			'text' => $entry['text'],
			'context' => $entry['context'],
			'plural' => $entry['plural'],
			'line' => $entry['line'],
		];

		if ($entry['plural'] !== '') {
			$pluralHash = md5($entry['plural'] . $entry['context']);
			$catalog[] = [
				'file' => $installedFile,
				'textdomain' => $textdomain,
				'hash' => $pluralHash,
				'text' => $entry['plural'],
				'context' => $entry['context'],
				'plural' => '',
				'line' => $entry['line'],
			];
		}
	}

	$data = [
		'file' => $installedFile,
		'textdomain' => $textdomain,
		'translations' => $translations,
	];

	$outputFile = $outputDir . '/' . $textdomain . '.json';
	file_put_contents($outputFile, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
	printf("Wrote %s (%d strings)\n", str_replace($moduleRoot . '/', '', $outputFile), count($strings));
}

file_put_contents($catalogFile, json_encode($catalog, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
printf("Wrote %s (%d catalog entries)\n", str_replace($moduleRoot . '/', '', $catalogFile), count($catalog));

function filenameToTextdomain(string $filename): string {
	$textdomain = str_replace(['/', '\\'], '--', ltrim($filename, '/'));
	$textdomain = str_replace('.', '-', $textdomain);
	return strtolower($textdomain);
}

function extractTranslations(string $file): array {
	$entries = [];
	$lines = file($file, FILE_IGNORE_NEW_LINES);
	if ($lines === false) return $entries;

	foreach ($lines as $index => $line) {
		$lineNumber = $index + 1;

		if (preg_match('/(?:^|[\s.=>(\\\\,])(?:__|->_)\(\s*([\'"])(.+?)(?<!\\\\)\1/u', $line, $match)) {
			$entries[] = [
				'text' => unescapePhpString($match[2]),
				'context' => '',
				'plural' => '',
				'line' => $lineNumber,
			];
			continue;
		}

		if (preg_match('/(?:^|[\s.=>(\\\\,])_x\(\s*([\'"])(.+?)(?<!\\\\)\1\s*,\s*([\'"])(.+?)(?<!\\\\)\3/u', $line, $match)) {
			$entries[] = [
				'text' => unescapePhpString($match[2]),
				'context' => unescapePhpString($match[4]),
				'plural' => '',
				'line' => $lineNumber,
			];
			continue;
		}

		if (preg_match('/(?:^|[\s.=>(\\\\,])_n\(\s*([\'"])(.+?)(?<!\\\\)\1\s*,\s*([\'"])(.+?)(?<!\\\\)\3/u', $line, $match)) {
			$entries[] = [
				'text' => unescapePhpString($match[2]),
				'context' => '',
				'plural' => unescapePhpString($match[4]),
				'line' => $lineNumber,
			];
		}
	}

	return dedupeEntries($entries);
}

function dedupeEntries(array $entries): array {
	$seen = [];
	$deduped = [];

	foreach ($entries as $entry) {
		$key = $entry['text'] . "\0" . $entry['context'] . "\0" . $entry['plural'];
		if (isset($seen[$key])) continue;
		$seen[$key] = true;
		$deduped[] = $entry;
	}

	return $deduped;
}

function unescapePhpString(string $text): string {
	return str_replace(['\\"', "\\'", '\\$', '\\n', '\\\\'], ['"', "'", '$', "\n", '\\'], $text);
}
