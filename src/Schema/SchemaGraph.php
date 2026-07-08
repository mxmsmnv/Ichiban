<?php

/**
 * IchibanSchemaGraph — builds connected @id JSON-LD graph.
 *
 * Nodes: WebSite, WebPage/Article/BlogPosting, BreadcrumbList,
 *        Organization or Person (from Identity), ImageObject
 */
class IchibanSchemaGraph {

	protected object $ichiban;

	public function __construct(object $ichiban) {
		$this->ichiban = $ichiban;
	}

	/**
	 * Hookable: build graph array for a page.
	 *
	 * @hook Ichiban::renderSchemaGraph (called upstream in Ichiban.module.php)
	 * @return array JSON-LD @graph nodes
	 */
	public function build(\ProcessWire\Page $page): array {
		$graph = [];
		$siteUrl = $this->ichiban->wire('config')->urls->httpRoot;
		$siteUrl = rtrim($siteUrl, '/');

		// WebSite (always)
		$graph[] = $this->buildWebSite($siteUrl);

		// Identity (Organization or Person)
		$graph[] = $this->buildIdentity($siteUrl);

		// WebPage / Article / BlogPosting
		$fn      = $this->ichiban->getSeoFieldName();
		$seoRaw  = $page->hasField($fn) ? $page->getUnformatted($fn) : null;
		$seoData = ($seoRaw instanceof \IchibanPageFieldValue) ? $seoRaw->getData() : [];
		$seo = $page->hasField($fn) ? $page->get($fn) : null;
		$schemaType = $seo instanceof \IchibanPageFieldValue ? (string)$seo->schema->type : 'WebPage';
		$selectedBuilderSchemaId = $this->selectedBuilderSchemaId((string)$schemaType);
		if ($schemaType !== 'none' && $selectedBuilderSchemaId === 0) {
			$graph[] = $this->buildWebPage($page, $schemaType, $siteUrl);
		}

		// BreadcrumbList (if page has parents beyond root)
		if ($page->parents->count() > 0) {
			$graph[] = $this->buildBreadcrumbs($page);
		}

		foreach ($this->buildMappedSchemas($page, $siteUrl, $selectedBuilderSchemaId) as $node) {
			$graph[] = $node;
		}

		// ImageObject (if OG image set)
		$ogImage = $seoData['og_image'] ?? '';
		if ($page->hasField($fn) && $ogImage) {
			$graph[] = $this->buildImageObject($page, $siteUrl, $ogImage);
		}

		return array_values(array_filter($graph));
	}

	// -------------------------------------------------------------------------

	protected function buildWebSite(string $siteUrl): array {
		$node = [
			'@type' => 'WebSite',
			'@id'   => $siteUrl . '/#website',
			'url'   => $siteUrl . '/',
			'name'  => $this->ichiban->get('entity_name') ?: $this->ichiban->wire('config')->httpHost,
		];
		// SearchAction
		$searchPage = $this->ichiban->wire('pages')->find('template=search, limit=1')->first();
		if ($searchPage) {
			$node['potentialAction'] = [
				'@type'       => 'SearchAction',
				'target'      => ['@type' => 'EntryPoint', 'urlTemplate' => $searchPage->httpUrl() . '?q={search_term_string}'],
				'query-input' => 'required name=search_term_string',
			];
		}
		return $node;
	}

	protected function buildIdentity(string $siteUrl): array {
		$type = $this->ichiban->get('entity_type') ?: 'Organization';
		$node = [
			'@type' => $type,
			'@id'   => $siteUrl . '/#' . strtolower($type),
			'name'  => $this->ichiban->get('entity_name') ?: '',
			'url'   => $this->ichiban->get('entity_url') ?: $siteUrl . '/',
		];
		// Logo (Organization only)
		if ($type === 'Organization' && $this->ichiban->get('entity_logo')) {
			$node['logo'] = [
				'@type' => 'ImageObject',
				'@id'   => $siteUrl . '/#logo',
				'url'   => $this->ichiban->get('entity_logo'),
			];
		}
		// sameAs (social profiles)
		$sameAs = array_filter([
			$this->ichiban->get('social_twitter'),
			$this->ichiban->get('social_linkedin'),
			$this->ichiban->get('social_facebook'),
			$this->ichiban->get('social_github'),
			$this->ichiban->get('social_instagram'),
		]);
		if ($sameAs) $node['sameAs'] = array_values($sameAs);
		return $this->ichiban->buildIdentity($node);
	}

	protected function buildWebPage(\ProcessWire\Page $page, string $type, string $siteUrl): array {
		$fn  = $this->ichiban->getSeoFieldName();
		$seo = $page->hasField($fn) ? $page->get($fn) : null;
		$pageId = rtrim($siteUrl, '/') . '/' . ltrim($page->url, '/');
		$node   = [
			'@type'      => $type,
			'@id'        => $pageId . '#webpage',
			'url'        => $page->httpUrl(),
			'name'       => $seo ? $seo->meta->title : $page->title,
			'description'=> $seo ? $seo->meta->description : '',
			'isPartOf'   => ['@id' => $siteUrl . '/#website'],
		];
		// Article extras
		if (in_array($type, ['Article', 'BlogPosting'])) {
			if ($page->created) {
				$node['datePublished'] = date('c', $page->created);
			}
			if ($page->modified) {
				$node['dateModified'] = date('c', $page->modified);
			}
			$entityType = $this->ichiban->get('entity_type') ?: 'Organization';
			$node['publisher'] = ['@id' => $siteUrl . '/#' . strtolower($entityType)];
		}
		if ($seo && $seo->og->image) {
			$node['image'] = ['@id' => $pageId . '#primaryimage'];
		}
		return $node;
	}

	protected function buildBreadcrumbs(\ProcessWire\Page $page): array {
		$items    = [];
		$position = 1;
		// Build chain — include root as first item
		$ancestors = $page->parents;
		foreach ($ancestors as $ancestor) {
			$items[] = [
				'@type'    => 'ListItem',
				'position' => $position++,
				'name'     => $ancestor->title ?: $ancestor->name,
				'item'     => $ancestor->httpUrl(),
			];
		}
		$items[] = [
			'@type'    => 'ListItem',
			'position' => $position,
			'name'     => $page->title ?: $page->name,
			'item'     => $page->httpUrl(),
		];
		return [
			'@type'           => 'BreadcrumbList',
			'@id'             => $page->httpUrl() . '#breadcrumb',
			'itemListElement' => $items,
		];
	}

	protected function buildImageObject(\ProcessWire\Page $page, string $siteUrl, string $imgUrl = ''): array {
		$pageUrl = rtrim($siteUrl, '/') . '/' . ltrim($page->url, '/');
		if (!$imgUrl) $imgUrl = $page->get($this->ichiban->getSeoFieldName())->og->image;
		return [
			'@type'      => 'ImageObject',
			'@id'        => $pageUrl . '#primaryimage',
			'url'        => $imgUrl,
			'contentUrl' => $imgUrl,
		];
	}

	protected function buildMappedSchemas(\ProcessWire\Page $page, string $siteUrl, int $selectedBuilderSchemaId = 0): array {
		$schemas = $this->schemaMappings();
		if (!$schemas) return [];

		$nodes = [];
		foreach ($schemas as $index => $schema) {
			if (!is_array($schema)) continue;
			$id = (int)($schema['id'] ?? 0);
			$type = trim((string)($schema['type'] ?? ''));
			$fields = $schema['fields'] ?? [];
			if ($type === '' || !is_array($fields)) continue;
			$isSelected = $selectedBuilderSchemaId > 0 && $id === $selectedBuilderSchemaId;
			if (!$isSelected && !$this->schemaMatchesPage($schema, $page)) continue;

			$node = [
				'@type' => $type,
				'@id' => rtrim($page->httpUrl(), '/') . '#schema-' . $this->schemaSlug((string)($schema['name'] ?? $type), (int)$index),
				'url' => $page->httpUrl(),
				'isPartOf' => ['@id' => $siteUrl . '/#website'],
			];
			foreach ($fields as $property => $expression) {
				$property = trim((string)$property);
				if ($property === '') continue;
				$value = $this->ichiban->resolveSourceValue($page, 'schema', $property, (string)$expression);
				if ($value === '') continue;
				if (in_array($property, ['image', 'logo', 'photo'], true)) {
					$node[$property] = preg_match('!^https?://!i', $value) ? $value : rtrim($siteUrl, '/') . '/' . ltrim($value, '/');
				} else {
					$node[$property] = $value;
				}
			}
			if ($isSelected && !isset($node['name'])) $node['name'] = (string)($page->title ?: $page->name);
			if (count($node) > 4) $nodes[] = $node;
		}
		return $nodes;
	}

	protected function schemaMappings(): array {
		$db = $this->ichiban->wire('database');
		try {
			$db->exec("CREATE TABLE IF NOT EXISTS `ichiban_schemas` (
				`id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
				`name`        VARCHAR(190) NOT NULL DEFAULT '',
				`schema_type` VARCHAR(128) NOT NULL DEFAULT 'Thing',
				`templates`   VARCHAR(512) NOT NULL DEFAULT '',
				`fields_json` MEDIUMTEXT NOT NULL,
				`enabled`     TINYINT(1) NOT NULL DEFAULT 1,
				`sort`        INT UNSIGNED NOT NULL DEFAULT 0,
				`created_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
				`updated_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
				PRIMARY KEY (`id`),
				KEY `enabled_sort` (`enabled`, `sort`),
				KEY `schema_type` (`schema_type`)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
			$total = (int)$db->query("SELECT COUNT(*) FROM `ichiban_schemas`")->fetchColumn();
			$rows = $db->query("SELECT * FROM `ichiban_schemas` WHERE enabled=1 ORDER BY sort ASC, id ASC")->fetchAll(\PDO::FETCH_ASSOC);
		} catch (\Throwable $e) {
			$rows = [];
			$total = 0;
		}
		if ($rows) {
			return array_map(static function(array $row): array {
				return [
					'id' => (int)$row['id'],
					'name' => (string)$row['name'],
					'type' => (string)$row['schema_type'],
					'templates' => (string)$row['templates'],
					'fields' => json_decode((string)$row['fields_json'], true) ?: [],
				];
			}, $rows);
		}
		if ($total > 0) return [];
		$schemas = $this->ichiban->get('schema_mappings') ?: [];
		if (is_string($schemas)) $schemas = json_decode($schemas, true) ?: [];
		return is_array($schemas) ? $schemas : [];
	}

	protected function selectedBuilderSchemaId(string $schemaType): int {
		if (!preg_match('/^builder:(\d+)$/', $schemaType, $m)) return 0;
		return max(0, (int)$m[1]);
	}

	protected function schemaMatchesPage(array $schema, \ProcessWire\Page $page): bool {
		$templates = array_filter(array_map('trim', explode(',', (string)($schema['templates'] ?? ''))));
		if (!$templates) return false;
		return in_array($page->template->name, $templates, true);
	}

	protected function schemaSlug(string $name, int $fallback): string {
		$slug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $name) ?: '');
		$slug = trim($slug, '-');
		return $slug !== '' ? $slug : 'custom-' . $fallback;
	}
}
