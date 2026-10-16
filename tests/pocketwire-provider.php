<?php
$module = (string)file_get_contents(dirname(__DIR__) . '/Ichiban.module.php');
$trait = (string)file_get_contents(dirname(__DIR__) . '/src/McpProviderTrait.php');
$checks = [
	'PocketWire opt-in is explicit' => str_contains($module, "'pocketWireProvider' => true"),
	'PocketWire SDK methods are exposed' => str_contains($trait, 'pocketWireProviderInfo()') && str_contains($trait, 'pocketWireManifest(User $actor)') && str_contains($trait, 'pocketWireRead(string $resource, string $identifier, array $query, User $actor)'),
	'only one read-only SEO resource is advertised' => str_contains($trait, "'status' => \$ready ? 'native_read_only' : 'unsupported'") && str_contains($trait, "'id' => 'seo'") && str_contains($trait, "'actions' => []"),
	'PocketWire actor must match ProcessWire request context' => str_contains($trait, "wire('user')->id") && str_contains($trait, '$actor->id') && str_contains($trait, 'WirePermissionException'),
	'PocketWire requires page-view permission' => str_contains($trait, "hasPermission('page-view')"),
	'published viewability remains enforced' => str_contains($trait, '$page->viewable()') && str_contains($trait, '$page->isUnpublished()') && str_contains($trait, '$page->isTrash()'),
	'preview remains side-effect-free' => str_contains($trait, 'setSeoImageVariationsEnabled(false)') && str_contains($trait, "'actions' => []"),
];
if(in_array(false, $checks, true)) { fwrite(STDERR, "Ichiban PocketWire provider contract failed.\n"); exit(1); }
echo "Ichiban PocketWire provider contract passed: " . count($checks) . " checks.\n";
