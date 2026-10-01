<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$module = (string) file_get_contents($root . '/Ichiban.module.php');
$processModule = (string) file_get_contents($root . '/ProcessIchiban.module.php');
$configCss = (string) file_get_contents($root . '/assets/css/config.css');
$processCss = (string) file_get_contents($root . '/assets/css/process.css');

$checks = [
	'config stylesheet is loaded with cache busting' => str_contains($module, "assets/css/config.css?v=") && str_contains($module, '@filemtime($configCss)'),
	'process stylesheet is loaded with cache busting' => str_contains($processModule, "'version'  => 26") && str_contains($processModule, "assets/css/process.css?v=") && str_contains($processModule, '@filemtime($cssPath)'),
	'config code can wrap on narrow screens' => str_contains($configCss, '#ModuleEditForm code') && str_contains($configCss, 'overflow-wrap: anywhere'),
	'config form columns may shrink' => str_contains($configCss, '#ModuleEditForm .InputfieldContent') && str_contains($configCss, 'min-width: 0'),
	'config Tracy bar is contained without a vendor patch' => str_contains($configCss, '#tracy-debug-bar') && str_contains($configCss, 'max-width: 100vw'),
	'dashboard grid children may shrink' => str_contains($processCss, '.ichiban-dashboard > *') && str_contains($processCss, '.ichiban-quick-stats > *'),
	'process code can wrap on narrow screens' => str_contains($processCss, '.ichiban-dashboard code') && str_contains($processCss, 'word-break: break-word'),
	'dashboard mobile sections are width-contained' => str_contains($processCss, '.ichiban-battery-score') && str_contains($processCss, 'max-width: 100%'),
	'table panels scroll inside the module viewport' => str_contains($processCss, '.ichiban-bulk-table-wrap') && str_contains($processCss, '.ichiban-backlinks-table-panel') && str_contains($processCss, 'overflow-x: auto'),
	'process Tracy bar is contained without a vendor patch' => str_contains($processCss, '#tracy-debug-bar') && str_contains($processCss, 'max-width: 100vw'),
];

$failed = false;
foreach ($checks as $label => $passed) {
	echo ($passed ? 'PASS' : 'FAIL') . ": {$label}\n";
	$failed = $failed || !$passed;
}

exit($failed ? 1 : 0);
