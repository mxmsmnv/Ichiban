<?php namespace ProcessWire;

/** MCP tools for public, viewable SEO output only. */
trait IchibanMcpProviderTrait {
	public function pocketWireProviderInfo(): array {
		return ['id' => 'ichiban', 'title' => 'Ichiban', 'module' => 'Ichiban', 'moduleVersion' => '0.3.9-alpha'];
	}

	public function pocketWireManifest(User $actor): array {
		$ready = $this->pocketWireCanRead($actor);
		return [
			'id' => 'ichiban', 'title' => 'Ichiban', 'module' => 'Ichiban', 'moduleVersion' => '0.3.9-alpha',
			'status' => $ready ? 'native_read_only' : 'unsupported',
			'permissions' => ['scope' => 'providers.read', 'processWire' => ['page-view']],
			'presentation' => ['layout' => 'list', 'icon' => 'search-outline', 'summary' => 'Rendered SEO metadata for published pages visible to this account.', 'accentColor' => '#16A34A'],
			'resources' => $ready ? [[
				'id' => 'seo', 'title' => 'Page SEO preview', 'method' => 'GET',
				'endpoint' => '/api/pocketwire/v1/providers/ichiban/seo/{id}',
			]] : [],
			'actions' => [],
		];
	}

	public function pocketWireRead(string $resource, string $identifier, array $query, User $actor): array {
		if($resource !== 'seo' || !preg_match('/^[1-9][0-9]*$/D', $identifier)) throw new Wire404Exception('An Ichiban page identifier is required.');
		if(!$this->pocketWireCanRead($actor)) throw new WirePermissionException('PocketWire actor context or page-view permission is unavailable.');
		return $this->mcpIchibanSeoPreview((int)$identifier);
	}

	private function pocketWireCanRead(User $actor): bool {
		return (int)$this->wire('user')->id === (int)$actor->id
			&& ($actor->isSuperuser() || $actor->hasPermission('page-view'));
	}

    public function mcpProviderInfo(): array {
        return ['name' => 'ichiban', 'title' => 'Ichiban', 'version' => '0.3.9-alpha'];
    }

    public function mcpTools(): array {
        return [[
            'name' => 'ichiban_seo_preview',
            'title' => 'Ichiban public SEO preview',
            'description' => 'Render SEO meta tags and Schema.org JSON-LD for one public ProcessWire page that is viewable to the current request.',
            'handler' => [$this, 'mcpIchibanSeoPreview'],
            'scope' => 'read', 'read_only' => true, 'destructive' => false,
            'idempotent' => true, 'open_world' => false,
            'input_schema' => [
                'type' => 'object',
                'properties' => ['page_id' => ['type' => 'integer', 'minimum' => 1]],
                'required' => ['page_id'], 'additionalProperties' => false,
            ],
        ]];
    }

    public function mcpIchibanSeoPreview(int $page_id): array {
        $page = $this->wire('pages')->get($page_id);
        if(!$page->id || !$page->viewable() || $page->isUnpublished() || $page->isTrash()) {
            throw new Wire404Exception('A public viewable page was not found.');
        }
        $variationsEnabled = $this->seoImageVariationsEnabled();
        $this->setSeoImageVariationsEnabled(false);
        try {
            return [
                'page_id' => (int)$page->id,
                'url' => $this->pageHttpUrl($page),
                'meta_html' => $this->renderMetaTags($page),
                'schema_json_ld' => $this->renderSchemaGraph($page),
            ];
        } finally {
            $this->setSeoImageVariationsEnabled($variationsEnabled);
        }
    }
}
