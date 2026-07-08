<?php namespace ProcessWire;

/**
 * FieldtypeIchiban — stores per-page SEO data.
 *
 * Table: field_seo (standard PW fieldtype table)
 * Additional columns: meta_title, meta_noindex, og_image, sitemap_include,
 *                     sitemap_priority, meta_inherit, og_inherit, schema_inherit
 */
class FieldtypeIchiban extends Fieldtype {

	public static function getModuleInfo(): array {
		return [
			'title'   => 'Ichiban',
			'summary' => 'SEO fieldtype for Ichiban module.',
			'author'  => 'Maxim Semenov',
			'href'     => 'https://smnv.org',
			'version' => 15,
		];
	}

	// -------------------------------------------------------------------------
	// Fieldtype interface
	// -------------------------------------------------------------------------

	public function getBlankValue(Page $page, Field $field): \IchibanPageFieldValue {
		$value = new \IchibanPageFieldValue($this->wire('modules')->get('Ichiban'));
		$value->setPage($page);
		return $value;
	}

	public function sanitizeValue(Page $page, Field $field, $value): \IchibanPageFieldValue {
		if ($value instanceof \IchibanPageFieldValue) return $value;
		return $this->getBlankValue($page, $field);
	}

	public function ___wakeupValue(Page $page, Field $field, $value): \IchibanPageFieldValue {
		$obj = $this->getBlankValue($page, $field);
		if (!$value || !is_array($value)) return $obj;

		// Decode JSON blob
		$data = $value['data'] ?? '{}';
		if (is_string($data)) $data = json_decode($data, true) ?: [];
		$data = $this->normalizeStoredData($data);
		$obj->setData($data);

		// Index columns
		$obj->_meta_noindex      = (bool) ($value['meta_noindex'] ?? false);
		$obj->_sitemap_include   = (bool) ($value['sitemap_include'] ?? true);
		$obj->_sitemap_priority  = (float) ($value['sitemap_priority'] ?? 0.5);
		$obj->_meta_inherit      = (bool) ($value['meta_inherit'] ?? false);
		$obj->_og_inherit        = (bool) ($value['og_inherit'] ?? false);
		$obj->_schema_inherit    = (bool) ($value['schema_inherit'] ?? false);

		return $obj;
	}

	public function ___sleepValue(Page $page, Field $field, $value): array {
		/** @var \IchibanPageFieldValue $value */
		$data = $value->getData();
		// Read index columns directly from rawData — never call Cascade here
		$metaTitle   = '';
		if (isset($data['meta_title'])) {
			$mt = $data['meta_title'];
			$metaTitle = is_array($mt) ? ($mt['value'] ?? '') : (string)$mt;
		}
		$ogImage = is_string($data['og_image'] ?? null) ? $data['og_image'] : '';
		return [
			'data'             => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
			'meta_title'       => $metaTitle,
			'meta_noindex'     => (int) !empty($data['meta_noindex']),
			'og_image'         => $ogImage,
			'sitemap_include'  => isset($data['sitemap_include']) ? (int)(bool)$data['sitemap_include'] : 1,
			'sitemap_priority' => (float)($data['sitemap_priority'] ?? 0.5),
			'meta_inherit'     => (int) !empty($data['meta_inherit']),
			'og_inherit'       => (int) !empty($data['og_inherit']),
			'schema_inherit'   => (int) !empty($data['schema_inherit']),
		];
	}

	public function ___loadPageField(Page $page, Field $field): mixed {
		$table = $field->getTable();
		$db    = $this->wire('database');
		$query = $db->prepare("SELECT * FROM `$table` WHERE pages_id=:id");
		$query->execute([':id' => $page->id]);
		$row = $query->fetch(\PDO::FETCH_ASSOC);
		return $row ?: [];
	}

	public function ___savePageField(Page $page, Field $field): bool {
		$value = $page->get($field->name);
		if (!$value instanceof \IchibanPageFieldValue) return false;
		$table = $field->getTable();
		$db    = $this->wire('database');
		$sleep = $this->sleepValue($page, $field, $value);

		$cols       = array_keys($sleep);
		$colList    = '`pages_id`,' . implode(',', array_map(fn($c) => "`$c`", $cols));
		$valList    = ':pages_id,' . implode(',', array_map(fn($c) => ":i_$c", $cols));
		$updateList = implode(',', array_map(fn($c) => "`$c`=:u_$c", $cols));

		$stmt = $db->prepare("INSERT INTO `$table` ($colList) VALUES ($valList) ON DUPLICATE KEY UPDATE $updateList");

		$params = [':pages_id' => $page->id];
		foreach ($cols as $c) {
			$params[":i_$c"] = $sleep[$c];
			$params[":u_$c"] = $sleep[$c];
		}
		$stmt->execute($params);
		return true;
	}

	public function ___deletePageField(Page $page, Field $field): bool {
		$table = $field->getTable();
		$stmt  = $this->wire('database')->prepare("DELETE FROM `$table` WHERE pages_id=:id");
		$stmt->execute([':id' => $page->id]);
		return true;
	}

	// -------------------------------------------------------------------------
	// Schema
	// -------------------------------------------------------------------------

	public function getDatabaseSchema(Field $field): array {
		$schema = parent::getDatabaseSchema($field);
		$schema['data']             = 'MEDIUMTEXT NOT NULL';
		$schema['meta_title']       = 'VARCHAR(255) NOT NULL DEFAULT ""';
		$schema['meta_noindex']     = 'TINYINT(1) NOT NULL DEFAULT 0';
		$schema['og_image']         = 'VARCHAR(512) NOT NULL DEFAULT ""';
		$schema['sitemap_include']  = 'TINYINT(1) NOT NULL DEFAULT 1';
		$schema['sitemap_priority'] = 'DECIMAL(2,1) NOT NULL DEFAULT 0.5';
		$schema['meta_inherit']     = 'TINYINT(1) NOT NULL DEFAULT 0';
		$schema['og_inherit']       = 'TINYINT(1) NOT NULL DEFAULT 0';
		$schema['schema_inherit']   = 'TINYINT(1) NOT NULL DEFAULT 0';
		unset($schema['keys']['data']); // not indexed
		return $schema;
	}

	// -------------------------------------------------------------------------
	// Inputfield
	// -------------------------------------------------------------------------

	public function getInputfield(Page $page, Field $field): InputfieldIchiban {
		/** @var InputfieldIchiban $input */
		$input = $this->wire('modules')->get('InputfieldIchiban');
		$input->setField($field);
		$input->setPage($page);
		return $input;
	}

	public function getCompatibleFieldtypes(Field $field): ?Fieldtypes {
		return null;
	}

	/**
	 * Normalize legacy SEO field payloads into Ichiban's current data shape.
	 *
	 * SeoMaestro stores page SEO values as a flat JSON object in the same `data`
	 * column. Supporting that shape lets an existing SeoMaestro field be switched
	 * to Ichiban without deleting the field and losing per-page data.
	 */
	protected function normalizeStoredData(array $data): array {
		return $this->looksLikeSeoMaestroData($data) ? $this->convertSeoMaestroData($data) : $data;
	}

	/**
	 * Convert SeoMaestro's flat JSON data to Ichiban's page-level data.
	 */
	public function convertSeoMaestroData(array $data): array {
		$out = [];
		$this->copySource($data, $out, 'meta_title', 'meta_title');
		$this->copySource($data, $out, 'meta_description', 'meta_description');
		$this->copySource($data, $out, 'opengraph_title', 'og_title');
		$this->copySource($data, $out, 'opengraph_description', 'og_description');

		$this->copyValue($data, $out, 'meta_canonicalUrl', 'canonical_url');
		$this->copyValue($data, $out, 'opengraph_image', 'og_image');
		$this->copyValue($data, $out, 'opengraph_imageAlt', 'og_image_alt');
		$this->copyValue($data, $out, 'opengraph_type', 'og_type');
		$this->copyValue($data, $out, 'twitter_card', 'twitter_card');
		$this->copyValue($data, $out, 'twitter_creator', 'twitter_creator');
		$this->copyValue($data, $out, 'sitemap_priority', 'sitemap_priority');
		$this->copyValue($data, $out, 'sitemap_changeFrequency', 'sitemap_changefreq');

		if (array_key_exists('robots_noIndex', $data) && $data['robots_noIndex'] !== 'inherit') {
			$out['meta_noindex'] = (bool) $data['robots_noIndex'];
		}
		if (array_key_exists('robots_noFollow', $data) && $data['robots_noFollow'] !== 'inherit') {
			$out['meta_nofollow'] = (bool) $data['robots_noFollow'];
		}
		if (array_key_exists('sitemap_include', $data) && $data['sitemap_include'] !== 'inherit') {
			$out['sitemap_include'] = (bool) $data['sitemap_include'];
		}

		$out['schema_type'] = 'WebPage';
		return $out;
	}

	protected function looksLikeSeoMaestroData(array $data): bool {
		foreach (['meta_canonicalUrl', 'opengraph_image', 'robots_noIndex', 'sitemap_changeFrequency'] as $key) {
			if (array_key_exists($key, $data)) return true;
		}
		return false;
	}

	protected function copySource(array $from, array &$to, string $oldKey, string $newKey): void {
		if (!array_key_exists($oldKey, $from) || $from[$oldKey] === 'inherit') return;
		$value = trim((string) $from[$oldKey]);
		if ($value === '') return;
		if (preg_match('/^\{([A-Za-z0-9_]+)\}$/', $value, $matches)) {
			$to[$newKey] = ['mode' => 'field', 'value' => $matches[1]];
			return;
		}
		$to[$newKey] = ['mode' => 'custom', 'value' => $value];
	}

	protected function copyValue(array $from, array &$to, string $oldKey, string $newKey): void {
		if (!array_key_exists($oldKey, $from) || $from[$oldKey] === 'inherit') return;
		$value = trim((string) $from[$oldKey]);
		if ($value === '') return;
		$to[$newKey] = $value;
	}
}
