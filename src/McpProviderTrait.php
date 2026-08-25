<?php namespace ProcessWire;

/** MCP tools for public, viewable SEO output only. */
trait IchibanMcpProviderTrait {
    public function mcpProviderInfo(): array {
        return ['name' => 'ichiban', 'title' => 'Ichiban', 'version' => '0.3.2-alpha'];
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
