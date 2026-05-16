<?php

/**
 * IchibanPageFieldValue — value object returned by $page->seo.
 *
 * Provides structured access: $page->seo->meta->title, $page->seo->og->image, etc.
 */
class IchibanPageFieldValue extends \ProcessWire\WireData {

	protected array $rawData = [];
	protected ?\ProcessWire\Page $page = null;
	protected object $ichiban;

	/** @var IchibanSeoGroup */
	public IchibanSeoGroup $meta;
	/** @var IchibanSeoGroup */
	public IchibanSeoGroup $og;
	/** @var IchibanSeoGroup */
	public IchibanSeoGroup $twitter;
	/** @var IchibanSeoGroup */
	public IchibanSeoGroup $schema;
	/** @var IchibanSeoGroup */
	public IchibanSeoGroup $sitemap;
	/** @var IchibanSeoGroup */
	public IchibanSeoGroup $advanced;

	// Index column caches (set by FieldtypeIchiban::wakeupValue)
	public bool $_meta_noindex     = false;
	public bool $_sitemap_include  = true;
	public float $_sitemap_priority = 0.5;
	public bool $_meta_inherit     = false;
	public bool $_og_inherit       = false;
	public bool $_schema_inherit   = false;

	public function __construct(object $ichiban) {
		parent::__construct();
		$this->ichiban = $ichiban;
		$this->meta     = new IchibanSeoGroup($this, 'meta');
		$this->og       = new IchibanSeoGroup($this, 'og');
		$this->twitter  = new IchibanSeoGroup($this, 'twitter');
		$this->schema   = new IchibanSeoGroup($this, 'schema');
		$this->sitemap  = new IchibanSeoGroup($this, 'sitemap');
		$this->advanced = new IchibanSeoGroup($this, 'advanced');
	}

	public function setPage(\ProcessWire\Page $page): void {
		$this->page = $page;
		foreach ([$this->meta, $this->og, $this->twitter, $this->schema, $this->sitemap, $this->advanced] as $group) {
			$group->setPage($page);
		}
	}

	public function setData(array $data): void {
		$this->rawData = $data;
		// Invalidate cached cascades so resolved values reflect new data
		foreach ([$this->meta, $this->og, $this->twitter, $this->schema, $this->sitemap, $this->advanced] as $group) {
			$group->invalidateCascade();
		}
	}

	public function getData(): array {
		return $this->rawData;
	}

	/**
	 * Render all head tags for this page.
	 * Proxies to Ichiban::renderHead().
	 */
	public function render(): string {
		if (!$this->page) return '';
		return $this->ichiban->renderHead($this->page);
	}

	public function __toString(): string {
		return $this->render();
	}
}

/**
 * Proxy group: $page->seo->meta->title resolves via IchibanCascade.
 */
class IchibanSeoGroup {

	protected IchibanPageFieldValue $value;
	protected string $group;
	protected ?\ProcessWire\Page $page = null;
	protected ?IchibanCascade $cascade = null;

	public function __construct(IchibanPageFieldValue $value, string $group) {
		$this->value = $value;
		$this->group = $group;
	}

	public function setPage(\ProcessWire\Page $page): void {
		$this->page    = $page;
		$this->cascade = null; // reset cascade when page changes
	}

	public function invalidateCascade(): void {
		$this->cascade = null;
	}

	public function __get(string $name): mixed {
		if ($this->cascade === null) {
			$this->cascade = new IchibanCascade(
				\ProcessWire\wire('modules')->get('Ichiban'),
				$this->page ?? new \ProcessWire\NullPage(),
				$this->value->getData()
			);
		}
		return $this->cascade->resolve($this->group, $name);
	}
}
