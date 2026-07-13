<?php

/**
 * IchibanSquadBridge — Squad-backed AI gateway for Ichiban admin features.
 */
class IchibanSquadBridge {

	protected object $ichiban;

	public function __construct(object $ichiban) {
		$this->ichiban = $ichiban;
	}

	public function isConfigured(): bool {
		$squad = $this->squad();
		if (!$squad || !method_exists($squad, 'getProvidersStatus')) return false;
		foreach ($squad->getProvidersStatus() as $status) {
			if (!empty($status['active'])) return true;
		}
		return false;
	}

	public function providerLabel(): string {
		$squad = $this->squad();
		if (!$squad) return 'Squad AI';
		$provider = $this->providerKey();
		$statuses = method_exists($squad, 'getProvidersStatus') ? $squad->getProvidersStatus() : [];
		$label = (string)($statuses[$provider]['label'] ?? $provider);
		return $label !== '' ? 'Squad AI / ' . $label : 'Squad AI';
	}

	public function activeModel(): string {
		$model = trim((string)$this->ichiban->get('ai_model'));
		if ($model !== '') return $model;
		$squad = $this->squad();
		if (!$squad) return '';
		try {
			$provider = method_exists($squad, 'getProvider') ? $squad->getProvider($this->providerKey()) : null;
			if ($provider && method_exists($provider, 'getModel')) return (string)$provider->getModel();
		} catch (\Throwable $e) {
			return '';
		}
		return '';
	}

	public function settingsUrl(): string {
		try {
			return rtrim((string)\ProcessWire\wire('config')->urls->admin, '/') . '/module/edit?name=Squad';
		} catch (\Throwable $e) {
			return \ProcessWire\Ichiban::adminPageUrl(false, 'settings/');
		}
	}

	public function chat(array $options): array {
		$contextFiles = [];
		$messages = $options['messages'] ?? [];
		if (!is_array($messages) || !$messages) return ['error' => __('AI request has no messages.')];

		$contextData = '';
		if (!empty($options['include_context'])) {
			$contextData = $this->contextExportSnippet($contextFiles);
		}

		[$message, $history, $systemPrompt] = $this->normalizeMessages($messages, (string)($options['system'] ?? ''));
		if ($contextData !== '') {
			$prefix = "You are analyzing a real ProcessWire site. The following files are exported by the Context module and are the authoritative site context for this request. Base recommendations on these files; cite page, template, field, or module names when possible. If a detail is missing, say exactly which detail is missing instead of saying you cannot access the site.\n\n" . $contextData;
			$message = $prefix . "\n\n" . $message;
		}

		$squad = $this->squad();
		if (!$squad || !method_exists($squad, 'ask')) return ['error' => __('Squad module is not installed or is not available.')];
		if (!$this->isConfigured()) return ['error' => __('AI is not configured. Configure an active provider key in Squad settings.')];

		$request = [
			'provider' => $this->providerKey(),
			'systemPrompt' => $systemPrompt,
			'maxTokens' => (int)($options['max_tokens'] ?? ($this->ichiban->get('ai_max_tokens') ?: 1024)),
			'temperature' => (float)($options['temperature'] ?? ($this->ichiban->get('ai_temperature') ?: 0.7)),
			'history' => $history,
			'timeout' => max(5, (int)($this->ichiban->get('ai_timeout') ?: 30)),
			'cache' => false,
		];
		$model = $this->activeModel();
		if ($model !== '') $request['model'] = $model;

		$start = microtime(true);
		try {
			$result = $squad->ask($message, $request);
		} catch (\Throwable $e) {
			return [
				'error' => $e->getMessage(),
				'duration_ms' => (int)round((microtime(true) - $start) * 1000),
				'model' => $model,
				'provider' => 'squad',
				'context_files' => $contextFiles,
			];
		}
		$duration = (int)round((microtime(true) - $start) * 1000);
		if (empty($result['success'])) {
			return [
				'error' => (string)($result['message'] ?? __('Squad request failed.')),
				'duration_ms' => $duration,
				'model' => $model,
				'provider' => 'squad',
				'context_files' => $contextFiles,
				'usage' => $result['usage'] ?? [],
			];
		}

		$content = trim((string)($result['content'] ?? ''));
		$raw = is_array($result['raw'] ?? null) ? $result['raw'] : [];
		$choice = $raw['choices'][0] ?? [];
		$finishReason = (string)($choice['finish_reason'] ?? ($choice['native_finish_reason'] ?? ($raw['stop_reason'] ?? '')));
		return [
			'content' => $content,
			'finish_reason' => $finishReason,
			'empty_reason' => $content === '' ? $this->emptyResponseReason($finishReason, $result) : '',
			'usage' => $result['usage'] ?? [],
			'status_code' => 200,
			'duration_ms' => $duration,
			'model' => $model !== '' ? $model : $this->activeModel(),
			'provider' => 'squad',
			'context_files' => $contextFiles,
		];
	}

	public function contextExportFiles(): array {
		$dir = $this->contextExportPath();
		if ($dir === '' || !is_dir($dir)) return [];
		$files = [];
		foreach ([
			'tree.toon',
			'structure.toon',
			'templates.toon',
			'config.toon',
			'modules.toon',
			'matrix-templates.toon',
			'tree.json',
			'structure.json',
			'templates.json',
			'config.json',
			'modules.json',
			'matrix-templates.json',
			'metadata/field-definitions.json',
			'metadata/routes.json',
			'samples/_all-samples.json',
		] as $file) {
			$path = $dir . $file;
			if (is_file($path) && is_readable($path)) $files[$file] = $path;
		}
		return $files;
	}

	protected function squad(): ?object {
		try {
			$modules = \ProcessWire\wire('modules');
			if (!$modules->isInstalled('Squad')) return null;
			$squad = $modules->get('Squad');
			return is_object($squad) ? $squad : null;
		} catch (\Throwable $e) {
			return null;
		}
	}

	protected function providerKey(): string {
		$configured = trim((string)$this->ichiban->get('ai_provider'));
		if ($configured !== '' && $configured !== 'openrouter') return $configured;
		$squad = $this->squad();
		if ($squad && method_exists($squad, 'getDefaultProviderKey')) return (string)$squad->getDefaultProviderKey();
		return 'anthropic';
	}

	protected function normalizeMessages(array $messages, string $requestSystem): array {
		$systemParts = [];
		$globalSystem = trim((string)$this->ichiban->get('ai_system_prompt'));
		if ($globalSystem !== '') $systemParts[] = $globalSystem;
		$requestSystem = trim($requestSystem);
		if ($requestSystem !== '') $systemParts[] = $requestSystem;

		$history = [];
		$current = '';
		foreach ($messages as $message) {
			if (!is_array($message)) continue;
			$role = (string)($message['role'] ?? 'user');
			$content = $this->messageContent($message);
			if ($content === '') continue;
			if ($role === 'system') {
				$systemParts[] = $content;
				continue;
			}
			$history[] = ['role' => $role === 'assistant' ? 'assistant' : 'user', 'content' => $content];
		}
		if ($history) {
			$last = array_pop($history);
			$current = (string)$last['content'];
		}
		return [$current, $history, trim(implode("\n\n", $systemParts))];
	}

	protected function messageContent(array $message): string {
		$content = $message['content'] ?? '';
		if (is_string($content)) return trim($content);
		if (is_array($content)) {
			$parts = [];
			foreach ($content as $part) {
				if (is_string($part)) {
					$parts[] = $part;
				} elseif (is_array($part)) {
					$parts[] = (string)($part['text'] ?? $part['content'] ?? '');
				}
			}
			return trim(implode("\n", array_filter($parts, 'strlen')));
		}
		return '';
	}

	protected function emptyResponseReason(string $finishReason, array $result): string {
		$usage = is_array($result['usage'] ?? null) ? $result['usage'] : [];
		$outputTokens = (int)($usage['output_tokens'] ?? ($usage['completion_tokens'] ?? 0));
		if ($finishReason !== '') {
			return sprintf(__('Squad returned an empty message body. Finish reason: %s. Output tokens: %d.'), $finishReason, $outputTokens);
		}
		return sprintf(__('Squad returned an empty message body with no finish reason. Output tokens: %d.'), $outputTokens);
	}

	protected function contextExportSnippet(array &$usedFiles): string {
		$files = $this->contextExportFiles();
		if (!$files) return '';
		$chunks = [];
		$remaining = 24000;
		foreach ($files as $name => $path) {
			if ($remaining <= 0) break;
			$bytes = file_get_contents($path, false, null, 0, min($remaining, 9000));
			if (!is_string($bytes) || trim($bytes) === '') continue;
			$chunks[] = "### {$name}\n" . trim($bytes);
			$usedFiles[] = $name;
			$remaining -= strlen($bytes);
		}
		return implode("\n\n", $chunks);
	}

	protected function contextExportPath(): string {
		try {
			if (!\ProcessWire\wire('modules')->isInstalled('Context')) return '';
			$data = \ProcessWire\wire('modules')->getModuleConfigData('Context');
			$path = trim((string)($data['export_path'] ?? 'site/assets/cache/context/'));
			if ($path === '') $path = 'site/assets/cache/context/';
			if (str_starts_with($path, '/')) return rtrim($path, '/') . '/';
			return rtrim(\ProcessWire\wire('config')->paths->root, '/') . '/' . trim($path, '/') . '/';
		} catch (\Throwable $e) {
			return '';
		}
	}
}

/**
 * Backward-compatible alias for older project code calling getOpenRouter().
 */
class IchibanOpenRouter extends IchibanSquadBridge {}
