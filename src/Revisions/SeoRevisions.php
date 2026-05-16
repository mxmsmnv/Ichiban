<?php

/**
 * IchibanSeoRevisions — tracks and restores SEO field changes per page.
 *
 * On every Pages::saved hook: compares current seo field values to last
 * stored revision. If any value changed, stores a new revision record with
 * a JSON diff of {field, old_value, new_value} pairs.
 */
class IchibanSeoRevisions {

	protected object $ichiban;
	protected bool $restoring = false;
	protected int $maxPerPage = 20;

	// Fields to track (flattened keys)
	protected array $trackedKeys = [
		'meta_title', 'meta_description', 'canonical_url',
		'meta_noindex', 'meta_nofollow',
		'og_title', 'og_description', 'og_image', 'og_image_alt', 'og_type',
		'twitter_card', 'twitter_creator',
		'schema_type', 'sitemap_include', 'sitemap_priority', 'sitemap_changefreq',
		'robots_meta', 'jsonld_override',
	];

	public function __construct(object $ichiban) {
		$this->ichiban   = $ichiban;
		$this->maxPerPage = (int)($ichiban->get('revisions_max') ?: 20);
	}

	// -------------------------------------------------------------------------
	// Save diff on page save
	// -------------------------------------------------------------------------

	public function saveDiff(\ProcessWire\Page $page, ?array $oldSnapshot = null): void {
		if ($this->restoring) return; // skip revision tracking during restore
		$fn = $this->ichiban->getSeoFieldName();
		if (!$page->hasField($fn)) return;

		// Use post-save data from page
		$seo = $page->getUnformatted($fn);
		if (!$seo instanceof IchibanPageFieldValue) return;
		$current  = $this->flattenData($seo->getData());

		// Use pre-save snapshot if provided, otherwise fall back to last revision
		$lastRevision = $oldSnapshot !== null
			? $this->flattenData($oldSnapshot)
			: $this->getLastRevisionData($page->id);
		$changes  = [];

		foreach ($this->trackedKeys as $key) {
			$old = $lastRevision[$key] ?? null;
			$new = $current[$key] ?? null;
			if ($old === $new) continue;
			$changes[] = ['field' => $key, 'old_value' => $old, 'new_value' => $new];
		}

		if (!$changes) return;

		$db   = $this->ichiban->wire('database');
		$user = $this->ichiban->wire('user');
		$stmt = $db->prepare("INSERT INTO ichiban_revisions (page_id, changes, user_id) VALUES (:pid, :changes, :uid)");
		$stmt->execute([
			':pid'     => $page->id,
			':changes' => json_encode($changes, JSON_UNESCAPED_UNICODE),
			':uid'     => $user ? $user->id : 0,
		]);

		// Prune old revisions
		$this->prune($page->id);
	}

	/** Alias called from Ichiban::hookPageSaved with pre-save snapshot. */
	public function saveDiffWithSnapshot(\ProcessWire\Page $page, ?array $oldData): void {
		$this->saveDiff($page, $oldData);
	}

	protected function flattenData(array $data): array {
		$flat = [];
		foreach ($this->trackedKeys as $key) {
			$val = $data[$key] ?? null;
			if (is_array($val)) {
				$flat[$key] = ($val['mode'] ?? '') . ':' . ($val['value'] ?? '');
			} else {
				$flat[$key] = (string)($val ?? '');
			}
		}
		return $flat;
	}

	protected function getLastRevisionData(int $pageId): array {
		$db   = $this->ichiban->wire('database');
		// Get all revisions oldest-first to build cumulative snapshot
		$stmt = $db->prepare("SELECT changes FROM ichiban_revisions WHERE page_id=:pid ORDER BY id ASC");
		$stmt->execute([':pid' => $pageId]);
		$rows = $stmt->fetchAll(\PDO::FETCH_COLUMN);
		if (!$rows) return [];
		// Apply each revision's new_values in order to build final state
		$snapshot = [];
		foreach ($rows as $row) {
			$changes = json_decode($row, true) ?: [];
			foreach ($changes as $c) {
				$snapshot[$c['field']] = $c['new_value'];
			}
		}
		return $snapshot;
	}

	protected function prune(int $pageId): void {
		$db  = $this->ichiban->wire('database');
		// Get the Nth id to keep (the oldest we want to preserve)
		$stmt = $db->prepare("SELECT id FROM ichiban_revisions WHERE page_id=:pid ORDER BY id DESC LIMIT 1 OFFSET :offset");
		$stmt->bindValue(':pid', $pageId, \PDO::PARAM_INT);
		$stmt->bindValue(':offset', $this->maxPerPage - 1, \PDO::PARAM_INT);
		$stmt->execute();
		$cutoffId = $stmt->fetchColumn();
		if (!$cutoffId) return;
		// Delete all revisions older than the cutoff
		$del = $db->prepare("DELETE FROM ichiban_revisions WHERE page_id=:pid AND id < :cutoff");
		$del->execute([':pid' => $pageId, ':cutoff' => $cutoffId]);
	}

	// -------------------------------------------------------------------------
	// Read
	// -------------------------------------------------------------------------

	public function getRevisions(int $pageId, int $limit = 20): array {
		$stmt = $this->ichiban->wire('database')->prepare(
			"SELECT * FROM ichiban_revisions WHERE page_id=:pid ORDER BY id DESC LIMIT :lim"
		);
		$stmt->bindValue(':pid', $pageId, \PDO::PARAM_INT);
		$stmt->bindValue(':lim', $limit, \PDO::PARAM_INT);
		$stmt->execute();
		return $stmt->fetchAll(\PDO::FETCH_ASSOC);
	}

	public function getAllRevisions(int $limit = 100): array {
		$stmt = $this->ichiban->wire('database')->prepare(
			"SELECT * FROM ichiban_revisions ORDER BY id DESC LIMIT :lim"
		);
		$stmt->bindValue(':lim', $limit, \PDO::PARAM_INT);
		$stmt->execute();
		return $stmt->fetchAll(\PDO::FETCH_ASSOC);
	}

	// -------------------------------------------------------------------------
	// Restore
	// -------------------------------------------------------------------------

	public function restore(int $revisionId): bool {
		$db   = $this->ichiban->wire('database');
		$stmt = $db->prepare("SELECT * FROM ichiban_revisions WHERE id=:id LIMIT 1");
		$stmt->execute([':id' => $revisionId]);
		$rev = $stmt->fetch(\PDO::FETCH_ASSOC);
		if (!$rev) return false;

		$page = $this->ichiban->wire('pages')->get((int)$rev['page_id']);
		$fn = $this->ichiban->getSeoFieldName();
		if (!$page->id || !$page->hasField($fn)) return false;

		$changes = json_decode($rev['changes'], true) ?: [];
		$page->of(false);
		$seo  = $page->get($fn);
		$data = $seo->getData();

		foreach ($changes as $c) {
			// Restore old_value
			$data[$c['field']] = $this->expandStoredValue($c['field'], $c['old_value']);
		}
		$seo->setData($data);
		$this->restoring = true;
		try {
			$page->save($fn);
		} finally {
			$this->restoring = false;
		}
		return true;
	}

	protected function expandStoredValue(string $field, mixed $value): mixed {
		if (in_array($field, ['meta_title', 'meta_description', 'og_title', 'og_description'], true) && is_string($value)) {
			$parts = explode(':', $value, 2);
			if (count($parts) === 2 && in_array($parts[0], ['inherit', 'field', 'custom'], true)) {
				return ['mode' => $parts[0], 'value' => $parts[1]];
			}
		}
		return $value;
	}
}
