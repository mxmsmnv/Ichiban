<?php

/**
 * IchibanRedirectManager — manages the ichiban_redirects table.
 *
 * Supports: 301, 302, 307, 410, 451; regex patterns; auto-redirects on slug change;
 * hit counter; import/export CSV.
 */
class IchibanRedirectManager {

	protected object $ichiban;

	public function __construct(object $ichiban) {
		$this->ichiban = $ichiban;
	}

	// -------------------------------------------------------------------------
	// Match
	// -------------------------------------------------------------------------

	/**
	 * Return matching redirect for a URL path, or null.
	 */
	public function match(string $url): ?array {
		$db   = $this->ichiban->wire('database');
		// Exact match first
		$stmt = $db->prepare("SELECT * FROM ichiban_redirects WHERE from_url=:url AND is_regex=0 LIMIT 1");
		$stmt->execute([':url' => $url]);
		$row  = $stmt->fetch(\PDO::FETCH_ASSOC);
		if ($row) {
			$this->incrementHit((int)$row['id']);
			return $this->ichiban->redirectMatch($url, $row);
		}
		// Regex match (limited to 500 to prevent memory issues on large tables)
		$stmt2 = $db->query("SELECT * FROM ichiban_redirects WHERE is_regex=1 LIMIT 500");
		while ($row = $stmt2->fetch(\PDO::FETCH_ASSOC)) {
			$pattern = '@' . str_replace('@', '\\@', $row['from_url']) . '@';
			if (@preg_match($pattern, $url)) {
				$toUrl = preg_replace($pattern, $row['to_url'], $url);
				$this->incrementHit((int)$row['id']);
				return $this->ichiban->redirectMatch($url, array_merge($row, ['to_url' => $toUrl]));
			}
		}
		return null;
	}

	protected function incrementHit(int $id): void {
		$db   = $this->ichiban->wire('database');
		$stmt = $db->prepare("UPDATE ichiban_redirects SET hits=hits+1, last_hit=NOW() WHERE id=:id");
		$stmt->execute([':id' => $id]);
	}

	// -------------------------------------------------------------------------
	// CRUD
	// -------------------------------------------------------------------------

	public function findRedirects(string $search = ''): array {
		$db = $this->ichiban->wire('database');
		if ($search !== '') {
			$stmt = $db->prepare("SELECT * FROM ichiban_redirects WHERE from_url LIKE :q OR to_url LIKE :q OR note LIKE :q ORDER BY created_at DESC");
			$stmt->execute([':q' => '%' . $search . '%']);
			return $stmt->fetchAll(\PDO::FETCH_ASSOC);
		}
		return $db->query("SELECT * FROM ichiban_redirects ORDER BY created_at DESC")->fetchAll(\PDO::FETCH_ASSOC);
	}

	public function importCsvString(string $csv): int {
		$count = 0;
		$lines = preg_split('/\R+/', trim($csv));
		foreach ($lines as $i => $line) {
			if ($line === '') continue;
			$row = str_getcsv($line);
			if ($i === 0 && isset($row[0]) && strtolower($row[0]) === 'from_url') continue;
			if (count($row) < 2) continue;
			$this->save([
				'from_url' => $row[0],
				'to_url' => $row[1],
				'type' => (int)($row[2] ?? 301),
				'is_regex' => (int)($row[3] ?? 0),
				'note' => $row[4] ?? '',
			]);
			$count++;
		}
		return $count;
	}

	public function save(array $data): int {
		$db    = $this->ichiban->wire('database');
		$san   = $this->ichiban->wire('sanitizer');
		$regex = !empty($data['is_regex']);
		$type  = in_array((int)($data['type'] ?? 301), [301, 302, 307, 410, 451], true) ? (int)$data['type'] : 301;
		// Regex patterns must not be sanitized — preserve all chars including ^ $ () .*
		$from  = $regex
			? trim($data['from_url'] ?? '')
			: '/' . ltrim($san->text($data['from_url'] ?? ''), '/');
		$to   = ($type === 410 || $type === 451) ? '' : ($data['to_url'] ?? '');
		$regex = !empty($data['is_regex']) ? 1 : 0;
		$note  = $san->text($data['note'] ?? '');
		$auto  = !empty($data['auto']) ? 1 : 0;
		$id    = isset($data['id']) ? (int)$data['id'] : 0;
		if ($id) {
			$stmt = $db->prepare("UPDATE ichiban_redirects SET from_url=:from,to_url=:to,type=:type,is_regex=:regex,note=:note WHERE id=:id");
			$stmt->execute([':from'=>$from,':to'=>$to,':type'=>$type,':regex'=>$regex,':note'=>$note,':id'=>$id]);
			return $id;
		}
		$stmt = $db->prepare("INSERT INTO ichiban_redirects (from_url,to_url,type,is_regex,note,auto) VALUES (:from,:to,:type,:regex,:note,:auto)");
		$stmt->execute([':from'=>$from,':to'=>$to,':type'=>$type,':regex'=>$regex,':note'=>$note,':auto'=>$auto]);
		return (int)$db->lastInsertId();
	}

	public function saveFromPost(\ProcessWire\WireInputData $post): int {
		$isRegex = (bool)$post->int('is_regex');
		return $this->save([
			'id'       => (int)$post->int('id'),
			'from_url' => $isRegex ? (string)($post['from_url'] ?? '') : (string)$post->text('from_url'),
			'to_url'   => (string)$post->text('to_url'),
			'type'     => (int)$post->int('type'),
			'is_regex' => $isRegex,
			'note'     => (string)$post->text('note'),
		]);
	}

	public function delete(int $id): void {
		$stmt = $this->ichiban->wire('database')->prepare("DELETE FROM ichiban_redirects WHERE id=:id");
		$stmt->execute([':id' => $id]);
	}

	// -------------------------------------------------------------------------
	// Auto-redirect on slug change
	// -------------------------------------------------------------------------

	public function createAutoRedirect(string $oldPath, \ProcessWire\Page $page): void {
		$newPath = $page->url; // will be updated after save
		if ($oldPath === $newPath) return;
		// Check if redirect already exists
		$db   = $this->ichiban->wire('database');
		$stmt = $db->prepare("SELECT id FROM ichiban_redirects WHERE from_url=:from LIMIT 1");
		$stmt->execute([':from' => $oldPath]);
		if ($stmt->fetchColumn()) return;
		$this->save(['from_url' => $oldPath, 'to_url' => $newPath, 'type' => 301, 'auto' => 1]);
	}

	// -------------------------------------------------------------------------
	// Import / Export
	// -------------------------------------------------------------------------

	public function importCsv(string $filePath): int {
		$count = 0;
		if (($fh = fopen($filePath, 'r')) === false) return 0;
		$header = fgetcsv($fh);
		while (($row = fgetcsv($fh)) !== false) {
			if (count($row) < 2) continue;
			$this->save(['from_url' => $row[0], 'to_url' => $row[1], 'type' => (int)($row[2] ?? 301)]);
			$count++;
		}
		fclose($fh);
		return $count;
	}

	public function exportCsv(): string {
		$rows   = $this->findRedirects();
		$output = "from_url,to_url,type,is_regex,note\n";
		foreach ($rows as $row) {
			$output .= implode(',', [
				'"' . str_replace('"', '""', $row['from_url']) . '"',
				'"' . str_replace('"', '""', $row['to_url']) . '"',
				$row['type'],
				$row['is_regex'],
				'"' . str_replace('"', '""', $row['note']) . '"',
			]) . "\n";
		}
		return $output;
	}
}
