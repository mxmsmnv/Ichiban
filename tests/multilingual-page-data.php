<?php

namespace ProcessWire {
	function wire(string $name): mixed { return null; }
	class Page {
		public object $template;
		public int $id = 1;
		private array $fields;
		public function __construct(array $fields = []) { $this->fields = $fields; $this->template = (object)['name' => 'basic-page']; }
		public function get(string $name): mixed { return $this->fields[$name] ?? ''; }
		public function hasField(string $name): bool { return array_key_exists($name, $this->fields); }
		public function httpUrl(): string { return 'https://example.test/page/'; }
	}
}

namespace {
	require_once __DIR__ . '/../src/LocalizedPageData.php';
	require_once __DIR__ . '/../src/Cascade.php';

	final class TestIchiban {
		public array $settings = [];
		public function resolveSourceValue($page, string $group, string $key, string $expression): string {
			if (str_starts_with($expression, 'field:')) return (string)$page->get(substr($expression, 6));
			return str_starts_with($expression, 'custom:') ? substr($expression, 7) : $expression;
		}
		public function resolvedSeoValue($page, string $group, string $key, string $value): string { return $value; }
		public function get(string $key): mixed { return $this->settings[$key] ?? null; }
		public function getSeoFieldName(): string { return 'seo'; }
		public function siteSetting(string $key, mixed $fallback = ''): mixed { return $fallback; }
		public function pageHttpUrl($page): string { return $page->httpUrl(); }
	}

	$assertions = 0;
	$assert = static function(bool $condition, string $message) use (&$assertions): void {
		if (!$condition) throw new RuntimeException($message);
		$assertions++;
	};

	$defaultId = 1;
	$frenchId = 2;
	$germanId = 3;
	$data = [
		'meta_title' => ['mode' => 'custom', 'value' => 'Legacy default'],
		'meta_description' => ['mode' => 'field', 'value' => 'summary'],
		'meta_noindex' => true,
	];

	[$exists, $value] = IchibanLocalizedPageData::read($data, 'meta_title', $frenchId, $defaultId);
	$assert($exists && $value['value'] === 'Legacy default', 'A missing translation must fall back to the legacy/default value.');

	IchibanLocalizedPageData::write($data, 'meta_title', ['mode' => 'custom', 'value' => 'Titre français'], $frenchId, $defaultId);
	IchibanLocalizedPageData::write($data, 'meta_title', ['mode' => 'custom', 'value' => 'Deutscher Titel'], $germanId, $defaultId);
	$assert($data['meta_title']['value'] === 'Legacy default', 'Saving a translation must not overwrite the default value.');
	$assert($data['_languages']['2']['meta_title']['value'] === 'Titre français', 'French data must use its own language bucket.');
	$assert($data['_languages']['3']['meta_title']['value'] === 'Deutscher Titel', 'German data must use its own language bucket.');
	$assert($data['meta_noindex'] === true && !isset($data['_languages']['2']['meta_noindex']), 'Language-neutral controls must remain shared.');
	$roundTrip = json_decode(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), true);
	$assert($roundTrip['_languages']['2']['meta_title']['value'] === 'Titre français', 'Language buckets must survive the field JSON save/wakeup round trip.');

	$page = new \ProcessWire\Page(['title' => 'Page title', 'summary' => 'Localized summary']);
	$ichiban = new TestIchiban();
	$default = new IchibanCascade($ichiban, $page, $data, $defaultId, $defaultId);
	$french = new IchibanCascade($ichiban, $page, $data, $frenchId, $defaultId);
	$german = new IchibanCascade($ichiban, $page, $data, $germanId, $defaultId);
	$assert($default->resolve('meta', 'title') === 'Legacy default', 'Default-language resolution must keep the legacy value.');
	$assert($french->resolve('meta', 'title') === 'Titre français', 'French resolution must select the French override.');
	$assert($german->resolve('meta', 'title') === 'Deutscher Titel', 'German resolution must remain isolated.');
	$assert($french->resolve('meta', 'description') === 'Localized summary', 'Missing translated source entries must retain the legacy field source.');

	$ichiban->settings['template_defaults'] = ['basic-page' => ['meta_title' => ['mode' => 'custom', 'value' => 'Template title']]];
	IchibanLocalizedPageData::write($data, 'meta_title', ['mode' => 'inherit', 'value' => ''], $frenchId, $defaultId);
	$frenchInherited = new IchibanCascade($ichiban, $page, $data, $frenchId, $defaultId);
	$assert($frenchInherited->resolve('meta', 'title') === 'Template title', 'Explicit translated inherit must bypass the default-language custom override.');

	IchibanLocalizedPageData::remove($data, 'meta_title', $frenchId, $defaultId);
	$frenchFallback = new IchibanCascade($ichiban, $page, $data, $frenchId, $defaultId);
	$assert($frenchFallback->resolve('meta', 'title') === 'Legacy default', 'Removing a translated override must restore default-language fallback.');

	IchibanLocalizedPageData::write($data, 'canonical_url', 'https://example.test/fr/', $frenchId, $defaultId);
	$frenchDirect = new IchibanCascade($ichiban, $page, $data, $frenchId, $defaultId);
	$assert($frenchDirect->resolve('meta', 'canonical') === 'https://example.test/fr/', 'Localized direct overrides must resolve in the selected language.');

	echo "Multilingual page data tests passed ({$assertions} assertions).\n";
}
