<?php

/**
 * IchibanCrawlCleanup — removes unnecessary link tags from <head>.
 *
 * Each item is independently toggled in module config.
 */
class IchibanCrawlCleanup {

	protected object $ichiban;

	protected array $cleanupMap = [
		'remove_rsd'          => '/<link[^>]+type=["\']application\/rsd\+xml["\'][^>]*>/i',
		'remove_wlw'          => '/<link[^>]+wlwmanifest[^>]*>/i',
		'remove_shortlink'    => '/<link[^>]+rel=["\']shortlink["\'][^>]*>/i',
		'remove_prev_next'    => '/<link[^>]+rel=["\'](?:prev|next)["\'][^>]*>/i',
		'remove_generator'    => '/<meta[^>]+name=["\']generator["\'][^>]*>/i',
	];

	public function __construct(object $ichiban) {
		$this->ichiban = $ichiban;
	}

	public function init(): void {
		$active = array_filter($this->cleanupMap, fn($_, $key) => $this->ichiban->get($key), ARRAY_FILTER_USE_BOTH);
		if (!$active) return;
		$this->ichiban->wire()->addHookAfter('Page::render', function(\ProcessWire\HookEvent $e) use ($active) {
			$html = $e->return;
			foreach ($active as $pattern) {
				$html = preg_replace($pattern, '', $html);
			}
			$e->return = $html;
		});
	}
}

/**
 * IchibanSearchCleanup — blocks spam search queries and logs them.
 *
 * Intercepts ProcessPageSearch or custom search template requests.
 * Blocks queries matching configurable regex patterns.
 * Logs blocked queries to ichiban_cleanup_log.
 */
class IchibanSearchCleanup {

	protected object $ichiban;

	// Built-in spam patterns
	protected array $builtinPatterns = [
		'/[\x{4E00}-\x{9FFF}\x{3000}-\x{303F}]/u',   // CJK characters
		'/^(?:TALK:|QQ:)/i',                            // TALK/QQ prefix spam
		'/[^\w\s\-\.%+@\'",!?#&=]/u',                  // excessive special chars
	];

	public function __construct(object $ichiban) {
		$this->ichiban = $ichiban;
	}

	public function init(): void {
		if (!$this->ichiban->get('search_cleanup_enabled')) return;
		$this->ichiban->wire()->addHookBefore('ProcessPageSearch::execute', $this, 'checkQuery');
	}

	public function checkQuery(\ProcessWire\HookEvent $e): void {
		$query = $this->ichiban->wire('input')->get('q') ?: $this->ichiban->wire('input')->get('search') ?: '';
		$query = (string)$query;
		if (!$query) return;

		$patterns = array_merge($this->builtinPatterns, $this->getCustomPatterns());
		$matched  = null;
		foreach ($patterns as $pattern) {
			if (@preg_match($pattern, $query)) {
				$matched = $pattern;
				break;
			}
		}
		if (!$matched) return;

		$this->log($query, $matched);

		$action = $this->ichiban->get('search_cleanup_action') ?: 'redirect';
		if ($action === 'redirect') {
			$this->ichiban->wire('session')->redirect($this->ichiban->wire('config')->urls->root, 302);
		} else {
			// Return 400
			header('HTTP/1.1 400 Bad Request');
			exit;
		}
	}

	protected function getCustomPatterns(): array {
		$raw = $this->ichiban->get('search_cleanup_patterns') ?: '';
		return array_filter(array_map('trim', explode("\n", $raw)));
	}

	protected function log(string $query, string $pattern): void {
		$db = $this->ichiban->wire('database');
		$stmt = $db->prepare("INSERT INTO ichiban_cleanup_log (query, pattern, ip) VALUES (:q, :p, :ip)");
		$stmt->execute([
			':q'  => mb_substr($query, 0, 512),
			':p'  => mb_substr($pattern, 0, 255),
			':ip' => $this->ichiban->wire('session')->getIP(),
		]);
	}
}
