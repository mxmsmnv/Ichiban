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
		$siteUrl = rtrim($this->ichiban->siteUrl(), '/');

		// WebSite (always)
		$graph[] = $this->buildWebSite($siteUrl);

		// Identity (Organization or Person)
		$graph[] = $this->buildIdentity($siteUrl);

		// WebPage / Article / BlogPosting
		$fn      = $this->ichiban->getSeoFieldName();
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
		$ogImage = $seo instanceof \IchibanPageFieldValue ? (string)$seo->og->image : '';
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
			'name'  => $this->ichiban->get('entity_name') ?: $this->ichiban->siteSetting('brand_name', $this->ichiban->wire('config')->httpHost),
		];
		// SearchAction
		$searchPage = $this->ichiban->wire('pages')->find('template=search, limit=1')->first();
		if ($searchPage) {
			$node['potentialAction'] = [
				'@type'       => 'SearchAction',
				'target'      => ['@type' => 'EntryPoint', 'urlTemplate' => $this->ichiban->pageHttpUrl($searchPage, null, false) . '?q={search_term_string}'],
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
			'name'  => $this->ichiban->get('entity_name') ?: $this->ichiban->siteSetting('legal_name', $this->ichiban->siteSetting('brand_name', '')),
			'url'   => $this->ichiban->get('entity_url') ?: $this->ichiban->siteSetting('site_url', $siteUrl . '/'),
		];
		// Logo (Organization only)
		$logoUrl = $this->ichiban->get('entity_logo') ?: $this->ichiban->siteSetting('logo_url', '');
		if ($type === 'Organization' && $logoUrl) {
			$node['logo'] = [
				'@type' => 'ImageObject',
				'@id'   => $siteUrl . '/#logo',
				'url'   => $this->ichiban->canonicalUrl((string)$logoUrl),
			];
		}
		// sameAs (social profiles)
		$sameAs = array_filter([
			$this->ichiban->get('social_twitter') ?: $this->ichiban->siteSetting('social_x', ''),
			$this->ichiban->get('social_linkedin') ?: $this->ichiban->siteSetting('social_linkedin', ''),
			$this->ichiban->get('social_facebook') ?: $this->ichiban->siteSetting('social_facebook', ''),
			$this->ichiban->get('social_github') ?: $this->ichiban->siteSetting('social_github', ''),
			$this->ichiban->get('social_instagram') ?: $this->ichiban->siteSetting('social_instagram', ''),
			$this->ichiban->siteSetting('social_youtube', ''),
			$this->ichiban->siteSetting('social_telegram', ''),
			$this->ichiban->siteSetting('social_vk', ''),
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
			'url'        => $this->ichiban->pageHttpUrl($page, null, false),
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
				'item'     => $this->ichiban->pageHttpUrl($ancestor, null, false),
			];
		}
		$items[] = [
			'@type'    => 'ListItem',
			'position' => $position,
			'name'     => $page->title ?: $page->name,
			'item'     => $this->ichiban->pageHttpUrl($page, null, false),
		];
		return [
			'@type'           => 'BreadcrumbList',
			'@id'             => $this->ichiban->pageHttpUrl($page, null, false) . '#breadcrumb',
			'itemListElement' => $items,
		];
	}

	protected function buildImageObject(\ProcessWire\Page $page, string $siteUrl, string $imgUrl = ''): array {
		$pageUrl = rtrim($siteUrl, '/') . '/' . ltrim($page->url, '/');
		if (!$imgUrl) $imgUrl = $page->get($this->ichiban->getSeoFieldName())->og->image;
		$imgUrl = $this->ichiban->canonicalUrl((string)$imgUrl);
		return [
			'@type'      => 'ImageObject',
			'@id'        => $pageUrl . '#primaryimage',
			'url'        => $imgUrl,
			'contentUrl' => $imgUrl,
		];
	}

	protected function buildMappedSchemas(\ProcessWire\Page $page, string $siteUrl, int $selectedBuilderSchemaId = 0): array {
		$schemas = $this->getMappings(true);
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
				'@id' => rtrim($this->ichiban->pageHttpUrl($page, null, false), '/') . '#schema-' . $this->schemaSlug((string)($schema['name'] ?? $type), (int)$index),
				'url' => $this->ichiban->pageHttpUrl($page, null, false),
				'isPartOf' => ['@id' => $siteUrl . '/#website'],
			];
			foreach ($fields as $property => $expression) {
				$property = trim((string)$property);
				if ($property === '') continue;
				$value = $this->ichiban->resolveSourceValue($page, 'schema', $property, (string)$expression);
				if ($value === '') continue;
				if (in_array($property, ['image', 'logo', 'photo'], true)) {
					$node[$property] = $this->ichiban->canonicalUrl($value);
				} else {
					$node[$property] = $value;
				}
			}
			if ($isSelected && !isset($node['name'])) $node['name'] = (string)($page->title ?: $page->name);
			if (in_array($type, ['Article', 'BlogPosting', 'NewsArticle'], true)) {
				if (!isset($node['datePublished']) && $page->created) $node['datePublished'] = date(DATE_ATOM, (int)$page->created);
				if (!isset($node['dateModified']) && $page->modified) $node['dateModified'] = date(DATE_ATOM, (int)$page->modified);
				if (!isset($node['publisher'])) {
					$entityType = $this->ichiban->get('entity_type') ?: 'Organization';
					$node['publisher'] = ['@id' => $siteUrl . '/#' . strtolower($entityType)];
				}
			}
			if (count($node) > 4) $nodes[] = $node;
		}
		return $nodes;
	}

	/**
	 * Return persisted Schema Builder mappings.
	 *
	 * When the database table is empty, legacy schema_mappings configuration
	 * remains readable so older installations can migrate without data loss.
	 */
	public function getMappings(bool $enabledOnly = false): array {
		$db = $this->ichiban->wire('database');
		try {
			$this->ensureSchemaTable();
			$total = (int)$db->query("SELECT COUNT(*) FROM `ichiban_schemas`")->fetchColumn();
			$where = $enabledOnly ? ' WHERE enabled=1' : '';
			$rows = $db->query("SELECT * FROM `ichiban_schemas`{$where} ORDER BY sort ASC, id ASC")->fetchAll(\PDO::FETCH_ASSOC);
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
					'enabled' => (int)($row['enabled'] ?? 1),
				];
			}, $rows);
		}
		if ($total > 0) return [];
		$schemas = $this->ichiban->get('schema_mappings') ?: [];
		if (is_string($schemas)) $schemas = json_decode($schemas, true) ?: [];
		if (!is_array($schemas)) return [];
		$schemas = array_values(array_filter($schemas, 'is_array'));
		foreach ($schemas as &$schema) {
			if (!array_key_exists('enabled', $schema)) $schema['enabled'] = 1;
		}
		unset($schema);
		if ($enabledOnly) {
			$schemas = array_values(array_filter($schemas, static fn(array $schema): bool => !empty($schema['enabled'])));
		}
		return $schemas;
	}

	/**
	 * Atomically replace Schema Builder mappings through the module API.
	 *
	 * @return int Number of saved mappings.
	 */
	public function replaceMappings(array $schemas): int {
		$db = $this->ichiban->wire('database');
		$this->ensureSchemaTable();
		$normalized = [];
		foreach ($schemas as $schema) {
			if (!is_array($schema)) continue;
			$type = preg_replace('/[^A-Za-z0-9_]/', '', (string)($schema['type'] ?? $schema['schema_type'] ?? '')) ?: 'Thing';
			$name = trim((string)($schema['name'] ?? $type));
			$name = mb_substr($name !== '' ? $name : $type, 0, 190);
			$templateNames = is_array($schema['templates'] ?? null)
				? $schema['templates']
				: explode(',', (string)($schema['templates'] ?? ''));
			$templateNames = array_values(array_unique(array_filter(array_map(
				static fn(mixed $template): string => preg_replace('/[^A-Za-z0-9_-]/', '', trim((string)$template)) ?: '',
				$templateNames
			))));
			$fields = [];
			foreach (($schema['fields'] ?? []) as $property => $expression) {
				$property = preg_replace('/[^A-Za-z0-9_@.-]/', '', (string)$property) ?: '';
				$expression = is_scalar($expression) ? trim((string)$expression) : '';
				if ($property === '' || $expression === '') continue;
				$fields[$property] = $expression;
			}
			$normalized[] = [
				'name' => $name,
				'type' => $type,
				'templates' => implode(',', $templateNames),
				'fields' => $fields,
				'enabled' => !array_key_exists('enabled', $schema) || !empty($schema['enabled']) ? 1 : 0,
			];
		}

		$db->beginTransaction();
		try {
			$db->exec("DELETE FROM `ichiban_schemas`");
			$stmt = $db->prepare("INSERT INTO `ichiban_schemas` (name, schema_type, templates, fields_json, enabled, sort) VALUES (:name, :type, :templates, :fields, :enabled, :sort)");
			foreach ($normalized as $sort => $schema) {
				$stmt->execute([
					':name' => $schema['name'],
					':type' => $schema['type'],
					':templates' => $schema['templates'],
					':fields' => json_encode($schema['fields'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
					':enabled' => $schema['enabled'],
					':sort' => $sort,
				]);
			}
			$db->commit();
		} catch (\Throwable $e) {
			if ($db->inTransaction()) $db->rollBack();
			throw $e;
		}
		return count($normalized);
	}

	protected function ensureSchemaTable(): void {
		$this->ichiban->wire('database')->exec("CREATE TABLE IF NOT EXISTS `ichiban_schemas` (
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
