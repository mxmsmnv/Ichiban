<?php

/**
 * Resolves typed source field expressions on behalf of IchibanCascade.
 *
 * Expressions:
 *   "inherit"                → empty (caller handles)
 *   "Some literal string"    → returned as-is
 *   "field:fieldname"        → pull value from page field
 *   "field:fieldname|truncate:N" → pull and truncate
 */
class IchibanSourceResolver {

	protected object $ichiban;

	public function __construct(object $ichiban) {
		$this->ichiban = $ichiban;
	}

	/**
	 * Resolve expression for given page.
	 * Hookable via Ichiban::resolveSourceValue.
	 */
	public function resolve(\ProcessWire\Page $page, string $group, string $key, string $expression): string {
		if ($expression === '' || $expression === 'inherit') return '';

		// SeoMaestro-style field token: {splash}
		if (preg_match('/^\{([A-Za-z0-9_]+)\}$/', $expression, $matches)) {
			return $this->resolveField($page, $matches[1], $group, $key);
		}

		// field:name or field:name|truncate:N
		if (str_starts_with($expression, 'field:')) {
			return $this->resolveField($page, substr($expression, 6), $group, $key);
		}

		// custom: prefix (legacy compat)
		if (str_starts_with($expression, 'custom:')) {
			return substr($expression, 7);
		}

		// Literal
		return $expression;
	}

	protected function resolveField(\ProcessWire\Page $page, string $spec, string $group = '', string $key = ''): string {
		// Split field name and modifiers
		$parts    = explode('|', $spec);
		$fieldName = array_shift($parts);
		$value     = $page->get($fieldName);
		$imageWidth = ($group === 'og' && $key === 'image') ? 1200 : 0;
		$imageHeight = ($group === 'og' && $key === 'image') ? 630 : 0;

		if ($value instanceof \ProcessWire\Page) {
			$value = $value->title ?: $value->name;
		} elseif ($value instanceof \ProcessWire\PageArray) {
			$value = $value->first() ? ($value->first()->title ?: '') : '';
		} elseif ($value instanceof \ProcessWire\Pageimage) {
			$value = $this->imageUrl($value, $imageWidth, $imageHeight);
		} elseif ($value instanceof \ProcessWire\Pageimages) {
			$value = $value->first() ? $this->imageUrl($value->first(), $imageWidth, $imageHeight) : '';
		}

		$value = (string)($value ?? '');

		// Apply modifiers
		foreach ($parts as $mod) {
			if (str_starts_with($mod, 'truncate:')) {
				$len   = (int)substr($mod, 9);
				$value = $this->truncate($value, $len);
			}
		}

		return $value;
	}

	protected function imageUrl(\ProcessWire\Pageimage $image, int $width = 0, int $height = 0): string {
		if ($width > 0 && $height > 0) {
			try {
				$image = $image->size($width, $height);
			} catch (\Throwable $e) {
				// Fall back to original image if variation generation fails.
			}
		}
		$url = (string)($image->httpUrl ?? '');
		if ($url !== '') return $url;
		$url = (string)($image->url ?? '');
		if (preg_match('!^https?://!i', $url)) return $url;
		return rtrim((string)\ProcessWire\wire('config')->urls->httpRoot, '/') . '/' . ltrim($url, '/');
	}

	protected function truncate(string $text, int $maxLen): string {
		if (mb_strlen($text) <= $maxLen) return $text;
		// Truncate to last complete word within limit
		$cut = mb_substr($text, 0, $maxLen - 1);
		$pos = mb_strrpos($cut, ' ');
		return ($pos ? mb_substr($cut, 0, $pos) : $cut) . '…';
	}
}
