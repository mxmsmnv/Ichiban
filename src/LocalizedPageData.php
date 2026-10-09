<?php

/**
 * Language-aware access to page-level Ichiban data stored in the existing JSON blob.
 *
 * Legacy/default-language values remain at the top level. Non-default values are
 * stored under `_languages[language-id]`, so no database schema migration is needed.
 */
class IchibanLocalizedPageData {

	public const STORAGE_KEY = '_languages';

	protected const LOCALIZED_KEYS = [
		'meta_title', 'meta_description', 'canonical_url',
		'og_title', 'og_description', 'og_image', 'og_image_alt',
		'jsonld_override',
	];

	public static function keys(): array {
		return self::LOCALIZED_KEYS;
	}

	public static function isLocalized(string $key): bool {
		return in_array($key, self::LOCALIZED_KEYS, true);
	}

	/** @return array{0: bool, 1: mixed} */
	public static function read(array $data, string $key, int $languageId, int $defaultLanguageId, bool $fallbackToDefault = true): array {
		if (!self::isLocalized($key) || $languageId <= 0 || $languageId === $defaultLanguageId) {
			return [array_key_exists($key, $data), $data[$key] ?? null];
		}

		$localized = $data[self::STORAGE_KEY][(string)$languageId] ?? null;
		if (is_array($localized) && array_key_exists($key, $localized)) {
			return [true, $localized[$key]];
		}
		if ($fallbackToDefault) return [array_key_exists($key, $data), $data[$key] ?? null];
		return [false, null];
	}

	public static function write(array &$data, string $key, mixed $value, int $languageId, int $defaultLanguageId): void {
		if (!self::isLocalized($key) || $languageId <= 0 || $languageId === $defaultLanguageId) {
			$data[$key] = $value;
			return;
		}
		$data[self::STORAGE_KEY][(string)$languageId][$key] = $value;
	}

	public static function remove(array &$data, string $key, int $languageId, int $defaultLanguageId): void {
		if (!self::isLocalized($key) || $languageId <= 0 || $languageId === $defaultLanguageId) {
			unset($data[$key]);
			return;
		}
		unset($data[self::STORAGE_KEY][(string)$languageId][$key]);
		if (empty($data[self::STORAGE_KEY][(string)$languageId])) unset($data[self::STORAGE_KEY][(string)$languageId]);
		if (empty($data[self::STORAGE_KEY])) unset($data[self::STORAGE_KEY]);
	}

	public static function defaultLanguageId(mixed $languages): int {
		if (!$languages) return 0;
		try {
			$default = method_exists($languages, 'getDefault') ? $languages->getDefault() : null;
			if ($default && isset($default->id)) return (int)$default->id;
			foreach ($languages as $language) return (int)($language->id ?? 0);
		} catch (\Throwable $e) {
			return 0;
		}
		return 0;
	}

	public static function activeLanguageId(mixed $languages, mixed $user): int {
		$defaultId = self::defaultLanguageId($languages);
		try {
			$language = $user && isset($user->language) ? $user->language : null;
			return $language && isset($language->id) ? (int)$language->id : $defaultId;
		} catch (\Throwable $e) {
			return $defaultId;
		}
	}
}
