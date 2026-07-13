<?php
// No namespace — this file registers a global autoloader for non-namespaced Ichiban src classes.

/**
 * Ichiban autoloader — loaded via Ichiban.module.php init() or via PW autoload.
 * Maps IchibanXxx class names to src/ files.
 */
spl_autoload_register(function(string $class): void {
	// Strip namespace if present
	$class = ltrim($class, '\\');
	if (!str_starts_with($class, 'Ichiban') && $class !== 'IchibanSeoGroup') return;

	$map = [
		'IchibanPageFieldValue'    => 'src/PageFieldValue.php',
		'IchibanSeoGroup'          => 'src/PageFieldValue.php',
		'IchibanCascade'           => 'src/Cascade.php',
		'IchibanSourceResolver'    => 'src/Source/SourceResolver.php',
		'IchibanSchemaGraph'       => 'src/Schema/SchemaGraph.php',
		'IchibanAuditEngine'       => 'src/Audit/AuditEngine.php',
		'IchibanRedirectManager'   => 'src/Redirects/RedirectManager.php',
		'IchibanSeoRevisions'      => 'src/Revisions/SeoRevisions.php',
		'IchibanBacklinks'         => 'src/Backlinks/Backlinks.php',
		'IchibanSearchStatistics'  => 'src/SearchStatistics/SearchStatistics.php',
		'IchibanBacklinksMoz'      => 'src/Backlinks/Moz.php',
		'IchibanEmailReports'      => 'src/EmailReports/EmailReports.php',
		'IchibanOpenRouter'        => 'src/Ai/OpenRouter.php',
		'IchibanCrawlCleanup'      => 'src/Cleanup/Cleanup.php',
		'IchibanSearchCleanup'     => 'src/Cleanup/Cleanup.php',
		'IchibanSitemap'           => 'src/Sitemap/Sitemap.php',
		'IchibanUpdater'           => 'src/Updates/Updater.php',
	];

	$base = dirname(__FILE__) . '/';
	$file = $map[$class] ?? null;
	if (!$file) return;
	$fullPath = $base . $file;
	if (file_exists($fullPath)) {
		require_once $fullPath;
	} else {
		error_log("IchibanAutoload: file not found for class '$class': $fullPath");
	}
});
