<?php
$module = (string)file_get_contents(dirname(__DIR__) . '/Ichiban.module.php');
$trait = (string)file_get_contents(dirname(__DIR__) . '/src/McpProviderTrait.php');
$checks = [str_contains($module, "'mcpProvider' => true"), str_contains($trait, "'ichiban_seo_preview'"), str_contains($trait, '$page->viewable()'), str_contains($trait, 'setSeoImageVariationsEnabled(false)'), str_contains($trait, "'additionalProperties' => false")];
if(in_array(false, $checks, true)) { fwrite(STDERR, "Ichiban MCP provider contract failed.\n"); exit(1); }
echo "Ichiban MCP provider contract passed.\n";
