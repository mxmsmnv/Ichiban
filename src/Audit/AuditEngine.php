<?php

/**
 * IchibanAuditEngine — builds and queries the ichiban_index table.
 *
 * Index stores resolved, denormalized SEO data for all published pages
 * that have the seo field. Audit rules run against this index for speed.
 */
class IchibanAuditEngine {

	protected object $ichiban;

	// Audit rule definitions: [id, check, severity, weight]
	protected array $rules = [
		['TitlePresent',       'meta_title != ""',             'critical', 30],
		['TitleLength',        'meta_title_len BETWEEN 30 AND 70', 'warning', 15],
		['TitleUnique',        null,                            'error',   20],  // special
		['DescriptionPresent', 'meta_description != ""',       'warning', 20],
		['DescriptionLength',  'meta_desc_len BETWEEN 50 AND 160', 'warning', 10],
		['DescriptionUnique',  null,                            'error',   15],  // special
		['OgImagePresent',     'has_og_image = 1',             'warning', 10],
		['CanonicalValid',     'canonical_url LIKE "http%"',   'error',   10],
		['NoindexOnPublic',    'is_noindex = 0',               'warning',  5],
		['UrlNoUnderscores',   'url NOT LIKE "%\\\\_%" ESCAPE "\\\\"', 'info', 3],
		['SchemaPresent',      'schema_type != ""',            'info',     5],
	];

	public function __construct(object $ichiban) {
		$this->ichiban = $ichiban;
	}

	// -------------------------------------------------------------------------
	// Index rebuild
	// -------------------------------------------------------------------------

	public function rebuildIndex(): void {
		$db    = $this->ichiban->wire('database');
		$pages = $this->ichiban->wire('pages');
		$log   = $this->ichiban->wire('log');
		$this->ensureIndexSchema();

		// Find all published pages that have the seo field
		$fieldName = $this->ichiban->getSeoFieldName();
		$seoField  = $this->ichiban->wire('fields')->get($fieldName);
		if (!$seoField) {
			throw new \RuntimeException("Field '$fieldName' not found (FieldtypeIchiban field is missing)");
		}

		// Get all templates using this field
		$templates = [];
		foreach ($this->ichiban->wire('templates') as $tpl) {
			if ($tpl->hasField($seoField)) $templates[] = $tpl->name;
		}
		if (!$templates) {
			throw new \RuntimeException("Field '$fieldName' is not assigned to any template");
		}

		$selector = 'template=' . implode('|', $templates) . ', status<' . \ProcessWire\Page::statusUnpublished . ', include=hidden, limit=0';
		$allPages  = $pages->find($selector);
		if (!$allPages->count()) {
			throw new \RuntimeException("No published pages found for templates: " . implode(', ', $templates));
		}

		$db->beginTransaction();
		try {
			$db->exec("DELETE FROM ichiban_index");

			$stmt = $this->prepareUpsertStatement();

			foreach ($allPages as $page) {
				$row = $this->buildPageRow($page, $fieldName);
				if ($row) $stmt->execute($row);
			}
			$db->commit();
			$log->save('ichiban-audit', "Indexed " . $allPages->count() . " pages.");
		} catch (\Throwable $ex) {
			$db->rollBack();
			$log->save('ichiban-audit', "ERROR: " . $ex->getMessage());
			throw $ex;
		}
	}

	public function refreshPage(\ProcessWire\Page $page): void {
		$db = $this->ichiban->wire('database');
		$this->ensureIndexSchema();

		$fieldName = $this->ichiban->getSeoFieldName();
		$row = $this->buildPageRow($page, $fieldName);
		if (!$row) {
			$stmt = $db->prepare("DELETE FROM ichiban_index WHERE page_id=:page_id");
			$stmt->execute([':page_id' => (int)$page->id]);
			return;
		}

		$this->prepareUpsertStatement()->execute($row);
	}

	protected function prepareUpsertStatement(): \PDOStatement {
		$db = $this->ichiban->wire('database');
		return $db->prepare("INSERT INTO ichiban_index
			(page_id, template_name, url, canonical_url, meta_title, meta_description, meta_title_len, meta_desc_len, is_noindex, has_og_image, schema_type, word_count)
			VALUES (:page_id,:tpl,:url,:canonical,:title,:desc,:title_len,:desc_len,:noindex,:og_image,:schema,:words)
			ON DUPLICATE KEY UPDATE
				template_name=VALUES(template_name),
				url=VALUES(url),
				canonical_url=VALUES(canonical_url),
				meta_title=VALUES(meta_title),
				meta_description=VALUES(meta_description),
				meta_title_len=VALUES(meta_title_len),
				meta_desc_len=VALUES(meta_desc_len),
				is_noindex=VALUES(is_noindex),
				has_og_image=VALUES(has_og_image),
				schema_type=VALUES(schema_type),
				word_count=VALUES(word_count)");
	}

	protected function buildPageRow(\ProcessWire\Page $page, string $fieldName): ?array {
		if (!$page->id || !$page->hasField($fieldName)) return null;
		if ($page->status >= \ProcessWire\Page::statusUnpublished) return null;

		$seo = $page->get($fieldName);
		$title = $seo ? (string)$seo->meta->title : (string)$page->title;
		$renderedTitle = method_exists($this->ichiban, 'formatMetaTitle') ? $this->ichiban->formatMetaTitle($title) : $title;
		$desc = $seo ? (string)$seo->meta->description : '';
		$seoData = $seo && method_exists($seo, 'getData') ? $seo->getData() : [];
		$schema = $seoData['schema_type'] ?? 'WebPage';
		$canonical = $seo ? (string)$seo->meta->canonical : $page->httpUrl();
		$ogImg = $seo ? (string)$seo->og->image : '';

		return [
			':page_id'   => (int)$page->id,
			':tpl'       => (string)$page->template->name,
			':url'       => (string)$page->httpUrl(),
			':canonical' => $canonical ?: (string)$page->httpUrl(),
			':title'     => $title,
			':desc'      => $desc,
			':title_len' => mb_strlen($renderedTitle),
			':desc_len'  => mb_strlen($desc),
			':noindex'   => !empty($seoData['meta_noindex']) ? 1 : 0,
			':og_image'  => $ogImg ? 1 : 0,
			':schema'    => $schema,
			':words'     => $this->countWords($page),
		];
	}

	protected function ensureIndexSchema(): void {
		$db = $this->ichiban->wire('database');
		try {
			$exists = $db->query("SHOW COLUMNS FROM ichiban_index LIKE 'canonical_url'")->fetchColumn();
			if (!$exists) {
				$db->exec("ALTER TABLE ichiban_index ADD canonical_url VARCHAR(1024) NOT NULL DEFAULT '' AFTER url");
			}
		} catch (\Throwable $e) {
			// The caller will surface table-level failures during rebuild/report.
		}
	}

	protected function countWords(\ProcessWire\Page $page): int {
		// Try common content fields
		foreach (['body', 'content', 'text', 'summary'] as $f) {
			if ($page->hasField($f)) {
				return str_word_count(strip_tags((string)$page->get($f)));
			}
		}
		return 0;
	}

	// -------------------------------------------------------------------------
	// Report
	// -------------------------------------------------------------------------

	public function getReport(): array {
		$db = $this->ichiban->wire('database');
		try {
			$total = (int) $db->query("SELECT COUNT(*) FROM `ichiban_index`")->fetchColumn();
		} catch (\Throwable $e) {
			return ['rules' => [], 'total' => 0, 'score' => 0];
		}
		if (!$total) return ['rules' => [], 'total' => 0, 'score' => 0];

		$ruleResults = [];
		$scoreSum    = 0;
		$weightSum   = 0;

		$rules = $this->ichiban->auditRules($this->rules);
		foreach ($rules as [$id, $check, $severity, $weight]) {
			if ($check === null) {
				// Unique checks
				$col     = ($id === 'TitleUnique') ? 'meta_title' : 'meta_description';
				$dupes   = (int) $db->query("SELECT COUNT(*) FROM (SELECT {$col}, COUNT(*) as cnt FROM ichiban_index WHERE {$col}!='' GROUP BY {$col} HAVING cnt>1) x")->fetchColumn();
				$passing = ($dupes === 0);
				$pages   = $dupes;
			} else {
				$passing = (int) $db->query("SELECT COUNT(*) FROM ichiban_index WHERE {$check}")->fetchColumn();
				$pages   = $total - $passing;
			}

			$pass = ($check === null) ? ($pages === 0 ? $total : max(0, $total - $pages)) : $passing;
			$ruleResults[] = [
				'name'     => $id,
				'severity' => $severity,
				'issues'   => $pages,
				'pages'    => $pages,
				'pass'     => $pass,
			];

			// Weighted score contribution
			$scoreSum  += ($pass / $total) * $weight;
			$weightSum += $weight;
		}

		$score = $weightSum ? (int) round(($scoreSum / $weightSum) * 100) : 0;
		return ['rules' => $ruleResults, 'total' => $total, 'score' => $score];
	}

	public function getQuickStats(): array {
		$db = $this->ichiban->wire('database');
		try {
			$score = $this->getReport()['score'] ?? 0;
			return [
				'score'                     => $score,
				'pages_missing_title'       => (int) $db->query("SELECT COUNT(*) FROM `ichiban_index` WHERE meta_title=''")->fetchColumn(),
				'pages_missing_description' => (int) $db->query("SELECT COUNT(*) FROM `ichiban_index` WHERE meta_description=''")->fetchColumn(),
				'pages_missing_og_image'    => (int) $db->query("SELECT COUNT(*) FROM `ichiban_index` WHERE has_og_image=0")->fetchColumn(),
				'pages_noindex'             => (int) $db->query("SELECT COUNT(*) FROM `ichiban_index` WHERE is_noindex=1")->fetchColumn(),
				'indexed_at'                => $db->query("SELECT MAX(indexed_at) FROM `ichiban_index`")->fetchColumn(),
			];
		} catch (\Throwable $e) {
			return ['score' => 0, 'pages_missing_title' => 0, 'pages_missing_description' => 0, 'pages_missing_og_image' => 0, 'pages_noindex' => 0, 'indexed_at' => null];
		}
	}
}
