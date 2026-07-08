<?php

/**
 * Resolves typed source field expressions on behalf of IchibanCascade.
 *
 * Expressions:
 *   "inherit"                → empty (caller handles)
 *   "Some literal string"    → returned as-is
 *   "field:fieldname"        → pull value from page field
 *   "field:combo.image"      → pull nested value from object-like fields
 *   "field:matrix.type.image" → pull nested values using ProcessWire dot notation
 *   "field:table.*.image"    → pull first non-empty value from Table rows
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

		// SeoMaestro-style field token: {splash} or {combo.image}
		if (preg_match('/^\{([A-Za-z0-9_][A-Za-z0-9_:.]*(?:\|[A-Za-z0-9_:-]+)*)\}$/', $expression, $matches)) {
			return $this->resolveField($page, $matches[1], $group, $key);
		}

		// field:name or field:name|truncate:N
		if (str_starts_with($expression, 'field:')) {
			return $this->resolveField($page, substr($expression, 6), $group, $key);
		}

		// Image fields may be entered as combo.image or combo:image.
		if ($group === 'og' && $key === 'image' && preg_match('/^[A-Za-z0-9_]+[.:][A-Za-z0-9_:.]*(?:\|[A-Za-z0-9_:-]+)*$/', $expression)) {
			$resolved = $this->resolveField($page, $expression, $group, $key);
			if ($resolved !== '') return $resolved;
		}

		// custom: prefix (legacy compat)
		if (str_starts_with($expression, 'custom:')) {
			return $this->normalizeLiteral(substr($expression, 7), $group, $key);
		}

		// Literal
		return $this->normalizeLiteral($expression, $group, $key);
	}

	protected function resolveField(\ProcessWire\Page $page, string $spec, string $group = '', string $key = ''): string {
		// Split field name and modifiers
		$parts    = explode('|', $spec);
		$fieldName = array_shift($parts);
		$value     = $this->resolveFieldPath($page, $fieldName);
		$imageWidth = ($group === 'og' && $key === 'image') ? 1200 : 0;
		$imageHeight = ($group === 'og' && $key === 'image') ? 630 : 0;

		$value = $this->stringValue($value, $imageWidth, $imageHeight);
		if ($this->shouldResolveAsPlainText($group, $key)) {
			$value = $this->plainText($value);
		}

		// Apply modifiers
		foreach ($parts as $mod) {
			if (str_starts_with($mod, 'truncate:')) {
				$len   = (int)substr($mod, 9);
				$value = $this->truncate($value, $len);
			}
		}

		return $value;
	}

	protected function resolveFieldPath(\ProcessWire\Page $page, string $path): mixed {
		$path = str_replace(':', '.', trim($path));
		if ($path === '') return null;

		$segments = array_values(array_filter(explode('.', $path), 'strlen'));
		if (!$segments) return null;

		$parentName = array_shift($segments);
		$fieldObj = \ProcessWire\wire('fields')->get($parentName);
		$ftName = $fieldObj && $fieldObj->type ? $fieldObj->type->className() : '';

		if ($segments && $ftName === 'FieldtypeCombo') {
			return $this->resolveComboPath($page, $parentName, $segments);
		}

		if ($segments && $ftName === 'FieldtypeTable') {
			return $this->resolveTablePath($page, $parentName, $segments);
		}

		if ($segments && $ftName === 'FieldtypeRepeaterMatrix') {
			return $this->resolveRepeaterMatrixPath($page, $parentName, $segments, $fieldObj);
		}

		$value = $page->get($parentName);
		foreach ($segments as $segment) {
			$value = $this->readSegment($value, $segment);
			if ($value === null) return null;
		}

		return $value;
	}

	protected function resolveComboPath(\ProcessWire\Page $page, string $fieldName, array $segments): mixed {
		try {
			$value = $page->getUnformatted($fieldName);
			foreach ($segments as $segment) {
				$value = $this->readSegment($value, $segment);
				if ($value === null) return null;
			}
			return $value;
		} catch (\Throwable $e) {
			return null;
		}
	}

	protected function resolveTablePath(\ProcessWire\Page $page, string $fieldName, array $segments): mixed {
		try {
			$rows = $page->getUnformatted($fieldName);
			if (!$rows || !count($rows)) return null;

			if (($segments[0] ?? '') === '*') {
				$column = $segments[1] ?? '';
				if ($column === '') return null;
				foreach ($rows as $row) {
					$value = $this->readSegment($row, $column);
					if ($value !== null && $value !== '') return $value;
				}
				return null;
			}

			$row = $rows->first();
			if (!$row) return null;
			$value = $this->readSegment($row, array_shift($segments));
			foreach ($segments as $segment) {
				$value = $this->readSegment($value, $segment);
				if ($value === null) return null;
			}
			return $value;
		} catch (\Throwable $e) {
			return null;
		}
	}

	protected function resolveRepeaterMatrixPath(\ProcessWire\Page $page, string $fieldName, array $segments, mixed $fieldObj = null): mixed {
		try {
			$items = $page->get($fieldName);
			if (!$items || !count($items)) return null;

			$seg1 = $segments[0] ?? '';
			$seg2 = $segments[1] ?? '';
			$seg3 = $segments[2] ?? '';

			if ($seg1 === '*') {
				foreach ($items as $item) {
					$value = $seg3 !== ''
						? ($this->matrixTypeName($item, $fieldObj) === $seg2 ? $item->get($seg3) : null)
						: $item->get($seg2);
					if ($value !== null && $value !== '') return $value;
				}
				return null;
			}

			if ($seg2 !== '') {
				$matrixItem = $this->firstMatrixItemOfType($items, $seg1, $fieldObj);
				if ($matrixItem) {
					if ($seg3 !== '') {
						$repeaterValue = $this->resolveRepeater($matrixItem, $seg2);
						if ($repeaterValue instanceof \ProcessWire\PageArray && count($repeaterValue)) {
							return $repeaterValue->first()->get($seg3);
						}
						return $matrixItem->get($seg3);
					}
					return $matrixItem->get($seg2);
				}

				$first = $items->first();
				if (!$first) return null;
				$repeaterValue = $this->resolveRepeater($first, $seg1);
				if ($repeaterValue instanceof \ProcessWire\PageArray && count($repeaterValue)) {
					return $repeaterValue->first()->get($seg2);
				}
				return $first->get($seg2);
			}

			$first = $items->first();
			return $first ? $first->get($seg1) : null;
		} catch (\Throwable $e) {
			return null;
		}
	}

	protected function readSegment(mixed $value, string $segment): mixed {
		if ($value === null || $segment === '') return null;

		if (is_array($value)) {
			return $value[$segment] ?? null;
		}

		if (is_object($value)) {
			if (method_exists($value, 'get')) return $value->get($segment);
			return $value->{$segment} ?? null;
		}

		return null;
	}

	protected function stringValue(mixed $value, int $imageWidth = 0, int $imageHeight = 0): string {
		if ($value instanceof \ProcessWire\Pageimage) {
			return $this->imageUrl($value, $imageWidth, $imageHeight);
		}
		if ($value instanceof \ProcessWire\Pageimages) {
			return $value->first() ? $this->imageUrl($value->first(), $imageWidth, $imageHeight) : '';
		}
		if ($value instanceof \ProcessWire\Page) {
			return (string)($value->title ?: $value->name);
		}
		if ($value instanceof \ProcessWire\PageArray) {
			$first = $value->first();
			return $first ? (string)($first->title ?: $first->name) : '';
		}
		if ($value instanceof \ProcessWire\WireArray) {
			$first = $value->first();
			return $first ? $this->stringValue($first, $imageWidth, $imageHeight) : '';
		}
		if (is_array($value)) {
			$first = reset($value);
			return $first === false ? '' : $this->stringValue($first, $imageWidth, $imageHeight);
		}
		if (is_object($value)) return '';
		return (string)($value ?? '');
	}

	protected function shouldResolveAsPlainText(string $group, string $key): bool {
		if (in_array("{$group}.{$key}", ['meta.title', 'meta.description', 'og.title', 'og.description'], true)) {
			return true;
		}
		if ($group !== 'schema') return false;
		return !in_array($key, ['image', 'logo', 'photo'], true);
	}

	protected function plainText(string $value): string {
		if ($value === '') return '';
		$value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
		$value = preg_replace('/<[^>]*>/u', ' ', $value) ?? $value;
		$value = preg_replace('/\s+/u', ' ', $value) ?? $value;
		return trim($value);
	}

	protected function normalizeLiteral(string $value, string $group, string $key): string {
		return $this->shouldResolveAsPlainText($group, $key) ? $this->plainText($value) : $value;
	}

	protected function resolveRepeater(mixed $item, string $fieldName): mixed {
		$value = $this->readSegment($item, $fieldName);
		if (is_int($value) || (is_string($value) && ctype_digit($value))) {
			$page = \ProcessWire\wire('pages')->get((int)$value);
			return ($page && $page->id) ? $page->children() : null;
		}
		return $value;
	}

	protected function firstMatrixItemOfType(mixed $items, string $typeName, mixed $fieldObj = null): mixed {
		foreach ($items as $item) {
			if ($this->matrixTypeName($item, $fieldObj) === $typeName) return $item;
		}
		return null;
	}

	protected function matrixTypeName(mixed $item, mixed $fieldObj = null): string {
		try {
			if (method_exists($item, 'matrix')) return (string)$item->matrix('name');
		} catch (\Throwable $e) {
		}

		try {
			$n = (int)$item->getUnformatted('repeater_matrix_type');
			if ($n > 0 && $fieldObj) {
				return (string)$fieldObj->get("matrix{$n}_name");
			}
		} catch (\Throwable $e) {
		}

		return '';
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
