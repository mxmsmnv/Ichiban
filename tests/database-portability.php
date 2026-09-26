<?php

$root = dirname(__DIR__);
$process = (string)file_get_contents($root . '/ProcessIchiban.module.php');
$module = (string)file_get_contents($root . '/Ichiban.module.php');
$audit = (string)file_get_contents($root . '/src/Audit/AuditEngine.php');

$checks = [
	'migration backup uses portable create-as-select' => str_contains($process, 'CREATE TABLE `$backup` AS SELECT * FROM `$table`'),
	'migration backup does not use MySQL CREATE TABLE LIKE' => !str_contains($process, 'CREATE TABLE `$backup` LIKE `$table`'),
	'audit upserts refresh the timestamp without relying on MySQL ON UPDATE' => str_contains($audit, 'indexed_at=UTC_TIMESTAMP()'),
	'release version is synchronized' => str_contains($module, "'version'  => 35") && str_contains($module, '@version 0.3.5-alpha'),
];

$failed = [];
foreach($checks as $label => $passed) {
	echo ($passed ? 'PASS' : 'FAIL') . ' ' . $label . PHP_EOL;
	if(!$passed) $failed[] = $label;
}
exit($failed ? 1 : 0);
