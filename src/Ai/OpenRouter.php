<?php

/**
 * IchibanOpenRouter — small OpenRouter client for Ichiban AI features.
 */
class IchibanOpenRouter {

	protected object $ichiban;

	public function __construct(object $ichiban) {
		$this->ichiban = $ichiban;
	}

	public function isConfigured(): bool {
		return $this->isContextConfigured() || $this->isLocalConfigured();
	}

	public function providerLabel(): string {
		if ($this->isContextConfigured()) return 'Context AI Gateway';
		if ($this->isLocalConfigured()) return 'Ichiban OpenRouter';
		if ($this->contextGateway()) return 'Context AI Gateway';
		return 'Ichiban OpenRouter';
	}

	public function activeModel(): string {
		if ($this->isContextConfigured()) {
			try {
				$data = \ProcessWire\wire('modules')->getModuleConfigData('Context');
				return (string)($data['ai_model'] ?? 'anthropic/claude-sonnet-4-6');
			} catch (\Throwable $e) {
				return 'anthropic/claude-sonnet-4-6';
			}
		}
		return (string)($this->ichiban->get('ai_model') ?: 'anthropic/claude-sonnet-4-6');
	}

	public function settingsUrl(): string {
		return rtrim(\ProcessWire\wire('config')->urls->admin, '/') . '/ichiban/settings/';
	}

	public function chat(array $options): array {
		$contextFiles = [];
		if (!empty($options['include_context'])) {
			$contextData = $this->contextExportSnippet($contextFiles);
			if ($contextData !== '') {
				$prefix = "You are analyzing a real ProcessWire site. The following files are exported by the Context module and are the authoritative site context for this request. Base recommendations on these files; cite page, template, field, or module names when possible. If a detail is missing, say exactly which detail is missing instead of saying you cannot access the site.\n\n" . $contextData;
				$messages = $options['messages'] ?? [];
				if (is_array($messages)) {
					array_unshift($messages, ['role' => 'user', 'content' => $prefix]);
					$options['messages'] = $messages;
				}
			}
		}
		$contextAi = $this->contextGateway();
		if ($contextAi && $this->isContextConfigured()) {
			if (empty($options['caller'])) $options['caller'] = 'Ichiban';
			$result = $contextAi->gateway($options);
			$result['provider'] = 'context';
			$result['context_files'] = $contextFiles;
			return $result;
		}
		if (!$this->isLocalConfigured()) {
			return ['error' => __('AI is not configured. Configure Context AI Gateway or enable Ichiban OpenRouter settings.')];
		}
		if (!function_exists('curl_init')) return ['error' => __('PHP cURL extension is required for OpenRouter requests.')];
		$model = (string)($options['model'] ?? $this->activeModel());
		$messages = $options['messages'] ?? [];
		if (!is_array($messages) || !$messages) return ['error' => __('AI request has no messages.')];
		$requestSystem = trim((string)($options['system'] ?? ''));
		if ($requestSystem !== '') array_unshift($messages, ['role' => 'system', 'content' => $requestSystem]);
		$system = trim((string)$this->ichiban->get('ai_system_prompt'));
		if ($system !== '') array_unshift($messages, ['role' => 'system', 'content' => $system]);
		$payload = [
			'model' => $model,
			'messages' => $messages,
			'max_tokens' => (int)($options['max_tokens'] ?? ($this->ichiban->get('ai_max_tokens') ?: 1024)),
			'temperature' => (float)($options['temperature'] ?? ($this->ichiban->get('ai_temperature') ?: 0.7)),
		];
		$siteUrl = trim((string)$this->ichiban->get('ai_site_url')) ?: (string)\ProcessWire\wire('config')->httpHost;
		$siteName = trim((string)$this->ichiban->get('ai_site_name')) ?: ((string)\ProcessWire\wire('config')->siteName ?: 'Ichiban');
		$headers = [
			'Content-Type: application/json',
			'Authorization: Bearer ' . trim((string)$this->ichiban->get('ai_api_key')),
			'HTTP-Referer: https://' . preg_replace('!^https?://!i', '', $siteUrl),
			'X-Title: ' . $siteName . ' / ' . (string)($options['caller'] ?? 'Ichiban'),
		];
		$start = microtime(true);
		$ch = curl_init('https://openrouter.ai/api/v1/chat/completions');
		curl_setopt_array($ch, [
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_POST => true,
			CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
			CURLOPT_HTTPHEADER => $headers,
			CURLOPT_TIMEOUT => max(5, (int)($this->ichiban->get('ai_timeout') ?: 30)),
			CURLOPT_CONNECTTIMEOUT => 10,
			CURLOPT_SSL_VERIFYPEER => true,
		]);
		$body = curl_exec($ch);
		$status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$error = curl_error($ch);
		curl_close($ch);
		$duration = (int)round((microtime(true) - $start) * 1000);
		if ($error !== '') return ['error' => 'cURL error: ' . $error, 'status_code' => 0, 'duration_ms' => $duration, 'model' => $model];
		$data = json_decode((string)$body, true);
		if ($status < 200 || $status >= 300) {
			return [
				'error' => (string)($data['error']['message'] ?? ('HTTP ' . $status)),
				'status_code' => $status,
				'duration_ms' => $duration,
				'model' => $model,
			];
		}
		$choice = $data['choices'][0] ?? [];
		$message = is_array($choice['message'] ?? null) ? $choice['message'] : [];
		$content = $this->messageContent($message);
		$finishReason = (string)($choice['finish_reason'] ?? ($choice['native_finish_reason'] ?? ''));
		return [
			'content' => $content,
			'finish_reason' => $finishReason,
			'empty_reason' => $content === '' ? $this->emptyResponseReason($finishReason, $data) : '',
			'usage' => $data['usage'] ?? [],
			'status_code' => $status,
			'duration_ms' => $duration,
			'model' => $model,
			'provider' => 'ichiban',
			'context_files' => $contextFiles,
		];
	}

	protected function messageContent(array $message): string {
		$content = $message['content'] ?? '';
		if (is_string($content)) {
			$text = trim($content);
			if ($text !== '') return $text;
		} elseif (is_array($content)) {
			$parts = [];
			foreach ($content as $part) {
				if (is_string($part)) {
					$parts[] = $part;
				} elseif (is_array($part)) {
					$parts[] = (string)($part['text'] ?? $part['content'] ?? '');
				}
			}
			$text = trim(implode("\n", array_filter($parts, 'strlen')));
			if ($text !== '') return $text;
		}
		foreach (['reasoning', 'reasoning_content', 'refusal'] as $key) {
			if (!empty($message[$key]) && is_string($message[$key])) return trim($message[$key]);
		}
		return '';
	}

	protected function emptyResponseReason(string $finishReason, array $data): string {
		$usage = is_array($data['usage'] ?? null) ? $data['usage'] : [];
		$completionTokens = (int)($usage['completion_tokens'] ?? 0);
		if ($finishReason === 'length') {
			return __('OpenRouter returned no visible text before the output token limit was reached. Increase Max tokens or shorten the Context export sent with the request.');
		}
		if ($finishReason !== '') {
			return sprintf(__('OpenRouter returned an empty message body. Finish reason: %s. Output tokens: %d.'), $finishReason, $completionTokens);
		}
		return sprintf(__('OpenRouter returned an empty message body with no finish reason. Output tokens: %d.'), $completionTokens);
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

	protected function isLocalConfigured(): bool {
		return !empty($this->ichiban->get('ai_enabled')) && trim((string)$this->ichiban->get('ai_api_key')) !== '';
	}

	protected function isContextConfigured(): bool {
		$ai = $this->contextGateway();
		return $ai && method_exists($ai, 'isEnabled') && (bool)$ai->isEnabled();
	}

	protected function contextGateway(): ?object {
		try {
			$modules = \ProcessWire\wire('modules');
			if (!$modules->isInstalled('Context')) return null;
			$context = null;
			try {
				$context = \ProcessWire\wire('context');
			} catch (\Throwable $e) {
				$context = null;
			}
			if (!$context || !method_exists($context, 'ai')) {
				$context = $modules->get('Context');
			}
			if (!$context || !method_exists($context, 'ai')) return null;
			$ai = $context->ai();
			return is_object($ai) && method_exists($ai, 'gateway') ? $ai : null;
		} catch (\Throwable $e) {
			return null;
		}
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
