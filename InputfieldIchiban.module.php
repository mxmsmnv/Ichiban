<?php namespace ProcessWire;

/**
 * InputfieldIchiban — Admin UI for per-page SEO editing.
 * UIkit tabs, no Alpine dependency, working processInput/save cycle.
 */
class InputfieldIchiban extends Inputfield {

	public static function getModuleInfo(): array {
		return [
			'title'   => 'Inputfield Ichiban',
			'summary' => 'Admin UI for Ichiban SEO fieldtype.',
			'author'  => 'Maxim Semenov',
			'href'     => 'https://smnv.org',
			'version' => 15,
		];
	}

	protected ?Page $page = null;
	protected ?Field $field = null;

	public function setPage(Page $page): void  { $this->page  = $page; }
	public function setField(Field $field): void { $this->field = $field; }

	public function init(): void {
		parent::init();
		$url = $this->wire('config')->urls->Ichiban;
		$this->wire('config')->styles->add($url . 'assets/css/inputfield.css');
		$this->wire('config')->scripts->add($url . 'assets/js/inputfield.js');
		$this->wire('config')->js('Ichiban', ['adminUrl' => Ichiban::adminPageUrl(false)]);
	}

	public function ___render(): string {
		$value = $this->value;
		if (!$value instanceof \IchibanPageFieldValue) {
			$value = $this->wire('modules')->get('Ichiban')->getBlankValue(
				$this->page ?? new NullPage(),
				$this->field ?? new Field()
			);
		}
		if (!$value->getData() && $this->page && $this->page->id) {
			$value->setPage($this->page);
		}

		$name  = $this->attr('name');
		$data  = $value->getData();
		$tabId = 'ichiban-tabs-' . $this->attr('id');
		$resolved = [
			'meta_title'       => (string)$value->meta->title,
			'meta_description' => (string)$value->meta->description,
			'canonical_url'    => (string)$value->meta->canonical,
			'og_title'         => (string)$value->og->title,
			'og_description'   => (string)$value->og->description,
			'og_image'         => (string)$value->og->image,
			'og_type'          => (string)$value->og->type,
		];

		$out  = "<div class='ichiban-wrap'>\n";
		$out .= $this->renderDebugBox($name, $data, $value);
		$out .= "<ul class='uk-tab' uk-tab=\"connect: #{$tabId}\">\n";
		foreach ([__('Meta'), __('Social'), __('Schema'), __('Sitemap'), __('Advanced')] as $i => $label) {
			$active = $i === 0 ? " class='uk-active'" : '';
			$out .= "<li{$active}><a href='#'>{$label}</a></li>\n";
		}
		$out .= "</ul>\n";
		$out .= "<ul id='{$tabId}' class='uk-switcher uk-margin'>\n";

		// META
		$out .= "<li class='uk-active'>\n";
		$out .= "<div class='ichiban-tab-layout'>\n";
		$out .= $this->renderSerpPreview($name, $resolved);
		$out .= "<div class='ichiban-panel'><div class='ichiban-panel-heading'><h3>" . __('Search result fields') . "</h3><p>" . __('Control the title, description, canonical URL, and robots directives shown to search engines.') . "</p></div>\n";
		$out .= $this->renderSourceField($name, 'meta_title',       __('Meta Title'),       $data['meta_title']       ?? [], 60,  70, $resolved['meta_title'], __('Recommended: 30–70 characters. Use a field name like title, an expression like title|truncate:70, or a token like {headline}.'), __('title'));
		$out .= $this->renderSourceField($name, 'meta_description', __('Meta Description'), $data['meta_description'] ?? [], 120, 160, $resolved['meta_description'], __('Recommended: 50–160 characters. Use summary, summary|truncate:160, or a token like {intro}.'), __('summary|truncate:160'));
		$out .= $this->renderTextInput($name, 'canonical_url', __('Canonical URL'), $data['canonical_url'] ?? '', __('Leave empty to use the current page URL.'), $resolved['canonical_url']);
		$out .= "<div class='ichiban-toggle-row'>"
			. $this->renderCheckbox($name, 'meta_noindex',  __('Noindex'),  !empty($data['meta_noindex']), __('Ask search engines not to index this page.'))
			. $this->renderCheckbox($name, 'meta_nofollow', __('Nofollow'), !empty($data['meta_nofollow']), __('Ask search engines not to follow links from this page.'))
			. "</div>\n";
		$out .= "</div></div>\n";
		$out .= "</li>\n";

		// SOCIAL
		$out .= "<li>\n";
		$out .= "<div class='ichiban-tab-layout'>\n";
		$out .= $this->renderSocialPreview($name);
		$out .= "<div class='ichiban-panel'><div class='ichiban-panel-heading'><h3>" . __('Open Graph') . "</h3><p>" . __('Define how this page appears when shared on Facebook, LinkedIn, X, Slack, and other platforms.') . "</p></div>\n";
		$out .= $this->renderSelectInput($name, 'og_type', __('OG Type'), $data['og_type'] ?? 'website',
			['website' => 'website', 'article' => 'article', 'product' => 'product'], __('Controls the Open Graph object type shared to social networks.'));
		$out .= $this->renderSourceField($name, 'og_title',       __('OG Title'),       $data['og_title']       ?? [], 60,  80, $resolved['og_title'], __('Falls back to the meta title when empty. You can also use title, headline, or {headline}.'), __('title'));
		$out .= $this->renderSourceField($name, 'og_description', __('OG Description'), $data['og_description'] ?? [], 120, 200, $resolved['og_description'], __('Falls back to the meta description when empty. You can also use summary|truncate:200.'), __('summary|truncate:200'));
		$out .= $this->renderTextInput($name, 'og_image',     __('OG Image URL'), $data['og_image']     ?? '', __('Use an absolute image URL, field expression like field:splash, or ProFields dot notation like field:combo.image, field:matrix.type.image, or {combo.image}. Image fields are cropped to 1200×630 for Open Graph.'), '', 'og_image', $resolved['og_image']);
		$out .= $this->renderTextInput($name, 'og_image_alt', __('OG Image Alt'), $data['og_image_alt'] ?? '', __('Short accessible description of the social image.'));
		$out .= "</div>\n";
		$out .= "<div class='ichiban-panel'><div class='ichiban-panel-heading'><h3>" . __('Twitter / X') . "</h3><p>" . __('Choose the card format and optional creator attribution.') . "</p></div>\n";
		$out .= $this->renderSelectInput($name, 'twitter_card', __('Card Type'), $data['twitter_card'] ?? 'summary_large_image',
			['summary' => 'summary', 'summary_large_image' => 'summary_large_image'], __('Large image cards usually perform better for editorial and marketing pages.'));
		$out .= $this->renderTextInput($name, 'twitter_creator', __('Twitter Creator Handle'), $data['twitter_creator'] ?? '', __('Example: @username'));
		$out .= "</div></div>\n";
		$out .= "</li>\n";

		// SCHEMA
		$schemaOptions = $this->schemaTypeOptions();
		$currentSchemaType = (string)($value->schema->type ?: 'WebPage');
		$schemaPreviewType = str_starts_with($currentSchemaType, 'builder:')
			? ($this->builderSchemaLabel($currentSchemaType) ?: $currentSchemaType)
			: $currentSchemaType;
		$out .= "<li>\n";
		$out .= "<div class='ichiban-panel ichiban-narrow-panel'><div class='ichiban-panel-heading'><h3>" . __('Structured data') . "</h3><p>" . __('Pick the schema type that best describes this page. Ichiban will generate a baseline JSON-LD graph from the page data.') . "</p></div>\n";
		$out .= $this->renderSelectInput($name, 'schema_type', __('Schema Type'), $currentSchemaType,
			$schemaOptions, __('Use a standard Schema.org type or pick one of the custom schemas created in SEO > Schemas.'));
		$out .= "<div class='ichiban-schema-note'><strong>" . __('Generated output') . "</strong><code>{ \"@type\": \"" . $this->wire('sanitizer')->entities($schemaPreviewType) . "\" }</code></div></div>\n";
		$out .= "</li>\n";

		// SITEMAP
		$out .= "<li>\n";
		$out .= "<div class='ichiban-panel ichiban-narrow-panel'><div class='ichiban-panel-heading'><h3>" . __('Sitemap entry') . "</h3><p>" . __('Control whether this page is listed in sitemap.xml and how strongly it is prioritized for crawlers.') . "</p></div>\n";
		$out .= $this->renderCheckbox($name, 'sitemap_include', __('Include in sitemap'), isset($data['sitemap_include']) ? (bool)$data['sitemap_include'] : true, __('Disable for utility pages, thank-you pages, or internal-only content.'));
		$out .= $this->renderSelectInput($name, 'sitemap_priority', __('Priority'), (string)($data['sitemap_priority'] ?? '0.5'),
			['0.1'=>'0.1','0.2'=>'0.2','0.3'=>'0.3','0.4'=>'0.4','0.5'=>'0.5','0.6'=>'0.6','0.7'=>'0.7','0.8'=>'0.8','0.9'=>'0.9','1.0'=>'1.0'], __('Relative importance within this site. Most pages should stay around 0.5.'));
		$out .= $this->renderSelectInput($name, 'sitemap_changefreq', __('Change Frequency'), $data['sitemap_changefreq'] ?? 'weekly',
			['always'=>'always','hourly'=>'hourly','daily'=>'daily','weekly'=>'weekly','monthly'=>'monthly','yearly'=>'yearly','never'=>'never'], __('Crawler hint for how often the content usually changes.'));
		$out .= "</div>\n";
		$out .= "</li>\n";

		// ADVANCED
		$out .= "<li>\n";
		$out .= "<div class='ichiban-panel'><div class='ichiban-panel-heading'><h3>" . __('Advanced overrides') . "</h3><p>" . __('Use only when this page needs precise robots or JSON-LD output beyond the standard controls.') . "</p></div>\n";
		$out .= $this->renderTextInput($name, 'robots_meta', __('Custom robots meta value'), $data['robots_meta'] ?? '', __('Example: max-snippet:-1, max-image-preview:large'));
		$out .= $this->renderTextarea($name, 'jsonld_override', __('JSON-LD Override (raw)'), $data['jsonld_override'] ?? '', __('Replaces generated JSON-LD for this page. Must be valid JSON-LD.'));
		$out .= "</div>\n";
		$out .= $this->renderRevisionHistory();
		$out .= $this->renderGscWidget();
		$out .= "</li>\n";

		$out .= "</ul>\n</div>\n";
		return $out;
	}

	// -------------------------------------------------------------------------
	// Renderers
	// -------------------------------------------------------------------------

	protected function renderSourceField(string $name, string $key, string $label, $fieldData, int $warnAt, int $maxAt, string $resolved = '', string $help = '', string $placeholder = ''): string {
		$mode = is_array($fieldData) ? ($fieldData['mode']  ?? 'field') : 'field';
		$val  = is_array($fieldData) ? ($fieldData['value'] ?? '')       : '';
		$san  = $this->wire('sanitizer');
		$out  = "<div class='uk-margin ichiban-source-field'>\n";
		$out .= "<label class='uk-form-label'>{$label}</label>\n";
		if ($help !== '') $out .= "<p class='ichiban-field-help'>" . $san->entities($help) . "</p>\n";
		$out .= "<div class='uk-form-controls'><div class='ichiban-source-grid'>\n";
		$out .= "<select name='{$name}[{$key}_mode]' class='uk-select ichiban-source-mode' data-source-key='{$key}'>\n";
		foreach (['inherit' => __('Inherit'), 'field' => __('From field'), 'custom' => __('Custom')] as $m => $ml) {
			$sel  = $mode === $m ? ' selected' : '';
			$out .= "<option value='{$m}'{$sel}>{$ml}</option>\n";
		}
		$out .= "</select>\n";
		$out .= "<div><input type='text' name='{$name}[{$key}_value]'"
			. " value='" . $san->entities($val) . "' class='uk-input ichiban-source-value'"
			. " placeholder='" . $san->entities($placeholder) . "' data-key='{$key}' data-resolved='" . $san->entities($resolved) . "' data-warn-at='{$warnAt}' data-max-at='{$maxAt}'>"
			. "<div class='ichiban-resolved-value'" . ($resolved === '' ? " hidden" : "") . "><span>" . __('Resolved value:') . "</span> <code>" . $san->entities($resolved) . "</code></div>"
			. "<div class='ichiban-counter-row'>"
			. "<div class='ichiban-len-bar'><div class='ichiban-len-fill' data-warn='{$warnAt}' data-max='{$maxAt}'></div></div>"
			. "<span class='ichiban-char-counter uk-text-small uk-text-muted'></span>"
			. "</div></div>\n";
		$out .= "</div></div></div>\n";
		return $out;
	}

	protected function debugEnabled(): bool {
		return (bool)$this->wire('input')->get('ichiban_debug');
	}

	protected function renderDebugBox(string $name, array $data, \IchibanPageFieldValue $value): string {
		if (!$this->debugEnabled()) return '';
		$san = $this->wire('sanitizer');
		$page = $this->page;
		$field = $this->field;
		$pageId = $page && $page->id ? (int)$page->id : 0;
		$fieldName = $field && $field->name ? (string)$field->name : '';
		$table = $field && $field->name ? $field->getTable() : '';
		$hasField = ($page && $fieldName !== '' && $page->hasField($fieldName)) ? 'yes' : 'no';
		$rowJson = '';

		if ($pageId && $table !== '') {
			try {
				$db = $this->wire('database');
				$stmt = $db->prepare("SELECT * FROM `$table` WHERE pages_id=:id");
				$stmt->execute([':id' => $pageId]);
				$row = $stmt->fetch(\PDO::FETCH_ASSOC);
				$rowJson = $row ? json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : 'no row';
			} catch (\Throwable $e) {
				$rowJson = get_class($e) . ': ' . $e->getMessage();
			}
		}

		$info = [
			'input_name' => $name,
			'page_id' => $pageId,
			'template' => $page && $page->template ? $page->template->name : '',
			'field_name' => $fieldName,
			'field_table' => $table,
			'page_has_field' => $hasField,
			'field_data' => $data,
			'value_data' => $value->getData(),
			'database_row' => $rowJson,
		];

		return "<details class='uk-alert uk-alert-warning ichiban-debug' open>"
			. "<summary><strong>Ichiban debug</strong></summary>"
			. "<pre style='white-space:pre-wrap;max-height:360px;overflow:auto'>"
			. $san->entities(json_encode($info, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))
			. "</pre></details>\n";
	}

	protected function renderTextInput(string $name, string $key, string $label, string $value, string $help = '', string $placeholder = '', string $dataKey = '', string $resolved = ''): string {
		$san = $this->wire('sanitizer');
		$attrs = '';
		if ($dataKey !== '') {
			$attrs .= " data-key='" . $san->entities($dataKey) . "'";
			$attrs .= " data-resolved='" . $san->entities($resolved) . "'";
		}
		$resolvedHint = '';
		if ($dataKey !== '') {
			$resolvedHint = "<div class='ichiban-resolved-value'" . ($resolved === '' ? " hidden" : "") . "><span>" . __('Resolved image:') . "</span> <a href='" . $san->entities($resolved) . "' target='_blank' rel='noopener'>" . $san->entities($resolved) . "</a></div>";
		}
		return "<div class='uk-margin'>\n<label class='uk-form-label'>{$label}</label>\n"
			. ($help !== '' ? "<p class='ichiban-field-help'>" . $san->entities($help) . "</p>\n" : '')
			. "<div class='uk-form-controls'><input type='text' name='{$name}[{$key}]'"
			. " value='" . $san->entities($value) . "' placeholder='" . $san->entities($placeholder) . "' class='uk-input'{$attrs}>{$resolvedHint}</div>\n</div>\n";
	}

	protected function renderCheckbox(string $name, string $key, string $label, bool $checked, string $help = ''): string {
		$chk = $checked ? ' checked' : '';
		return "<div class='uk-margin ichiban-switch-field'><label><input class='uk-checkbox' type='checkbox'"
			. " name='{$name}[{$key}]' value='1'{$chk}> <span>{$label}</span></label>"
			. ($help !== '' ? "<p class='ichiban-field-help'>" . $this->wire('sanitizer')->entities($help) . "</p>" : '')
			. "</div>\n";
	}

	protected function renderSelectInput(string $name, string $key, string $label, string $current, array $options, string $help = ''): string {
		$san = $this->wire('sanitizer');
		$out = "<div class='uk-margin'>\n<label class='uk-form-label'>{$label}</label>\n"
			. ($help !== '' ? "<p class='ichiban-field-help'>" . $san->entities($help) . "</p>\n" : '')
			. "<div class='uk-form-controls'><select name='{$name}[{$key}]' class='uk-select'>\n";
		foreach ($options as $v => $l) {
			if (is_array($l)) {
				$out .= "<optgroup label='" . $san->entities((string)$v) . "'>\n";
				foreach ($l as $groupValue => $groupLabel) {
					$sel = $current === (string)$groupValue ? ' selected' : '';
					$out .= "<option value='" . $san->entities((string)$groupValue) . "'{$sel}>" . $san->entities((string)$groupLabel) . "</option>\n";
				}
				$out .= "</optgroup>\n";
				continue;
			}
			$sel = $current === (string)$v ? ' selected' : '';
			$out .= "<option value='" . $san->entities((string)$v) . "'{$sel}>" . $san->entities((string)$l) . "</option>\n";
		}
		return $out . "</select></div>\n</div>\n";
	}

	protected function schemaTypeOptions(): array {
		$options = [
			__('Standard types') => [
				'WebPage' => 'WebPage',
				'Article' => 'Article',
				'BlogPosting' => 'BlogPosting',
				'Product' => 'Product',
				'none' => __('None'),
			],
		];
		$builder = $this->builderSchemaOptions();
		if ($builder) $options[__('Builder schemas')] = $builder;
		return $options;
	}

	protected function builderSchemaOptions(): array {
		try {
			$rows = $this->wire('database')->query("SELECT id, name, schema_type, templates FROM `ichiban_schemas` WHERE enabled=1 ORDER BY sort ASC, id ASC")->fetchAll(\PDO::FETCH_ASSOC);
		} catch (\Throwable $e) {
			return [];
		}
		$options = [];
		foreach ($rows as $row) {
			$id = (int)($row['id'] ?? 0);
			if ($id <= 0) continue;
			$name = trim((string)($row['name'] ?? '')) ?: (string)($row['schema_type'] ?? 'Thing');
			$type = trim((string)($row['schema_type'] ?? 'Thing')) ?: 'Thing';
			$templates = trim((string)($row['templates'] ?? ''));
			$label = $name . ' (' . $type . ')';
			if ($templates !== '') $label .= ' · ' . $templates;
			$options['builder:' . $id] = $label;
		}
		return $options;
	}

	protected function builderSchemaLabel(string $value): string {
		if (!preg_match('/^builder:(\d+)$/', $value, $m)) return '';
		$id = (int)$m[1];
		try {
			$stmt = $this->wire('database')->prepare("SELECT name, schema_type FROM `ichiban_schemas` WHERE id=:id LIMIT 1");
			$stmt->execute([':id' => $id]);
			$row = $stmt->fetch(\PDO::FETCH_ASSOC);
		} catch (\Throwable $e) {
			return '';
		}
		if (!$row) return '';
		$name = trim((string)($row['name'] ?? '')) ?: (string)($row['schema_type'] ?? 'Thing');
		$type = trim((string)($row['schema_type'] ?? 'Thing')) ?: 'Thing';
		return $name . ' / ' . $type;
	}

	protected function renderTextarea(string $name, string $key, string $label, string $value, string $help = ''): string {
		return "<div class='uk-margin'>\n<label class='uk-form-label'>{$label}</label>\n"
			. ($help !== '' ? "<p class='ichiban-field-help'>" . $this->wire('sanitizer')->entities($help) . "</p>\n" : '')
			. "<div class='uk-form-controls'><textarea name='{$name}[{$key}]' class='uk-textarea' rows='5'>"
			. $this->wire('sanitizer')->entities($value) . "</textarea></div>\n</div>\n";
	}

	protected function renderSerpPreview(string $name, array $resolved = []): string {
		$san      = $this->wire('sanitizer');
		$page     = $this->page;
		$httpUrl  = ($page && $page->id) ? $page->httpUrl() : '';
		$parsed   = $httpUrl ? parse_url($httpUrl) : [];
		$host     = $san->entities($parsed['host'] ?? $_SERVER['HTTP_HOST'] ?? '');
		$path     = $parsed['path'] ?? '/';
		$pathParts = array_filter(explode('/', trim($path, '/')));
		$pathCrumbs = $host . ($pathParts ? ' › ' . $san->entities(implode(' › ', $pathParts)) : '');
		$sitename = $san->entities($this->wire('config')->siteName ?? $host);
		$title = trim((string)($resolved['meta_title'] ?? ''));
		$desc = trim((string)($resolved['meta_description'] ?? ''));
		$titleText = $title !== '' ? $san->entities($title) : __('Untitled page');
		$descText = $desc !== '' ? $san->entities($desc) : $san->entities($this->pageExcerpt());

		return "<div class='ichiban-serp uk-margin' data-field='{$name}' data-host='{$host}' data-path='" . $san->entities($path) . "' data-fallback-desc='{$descText}'>\n"
			. "<div class='ichiban-preview-heading'><div><strong>" . __('Google preview') . "</strong><span>" . __('This is an approximation of how the page can appear in search results.') . "</span></div></div>\n"
			. "<div class='ichiban-serp-toggle uk-margin-small-bottom'>\n"
			. "<button type='button' class='ichiban-serp-btn ichiban-serp-btn--active' data-mode='desktop'>" . __('Desktop') . "</button>\n"
			. "<button type='button' class='ichiban-serp-btn' data-mode='mobile'>" . __('Mobile') . "</button>\n"
			. "</div>\n"
			. "<div class='ichiban-serp-card' data-mode='desktop'>\n"
			. "<div class='ichiban-serp-meta'>\n"
			. "<img class='ichiban-serp-favicon' src='https://{$host}/favicon.ico' onerror=\"this.style.display='none'\" alt=''>\n"
			. "<div>\n"
			. "<div class='ichiban-serp-sitename'>{$sitename}</div>\n"
			. "<div class='ichiban-serp-breadcrumb'>{$pathCrumbs}</div>\n"
			. "</div>\n"
			. "<span class='ichiban-serp-menu'>&#8942;</span>\n"
			. "</div>\n"
			. "<div class='ichiban-serp-title-text" . ($title === '' ? " is-empty" : "") . "'>{$titleText}</div>\n"
			. "<div class='ichiban-serp-desc-text'><span class='ichiban-serp-snippet'>{$descText}</span></div>\n"
			. "</div>\n"
			. "</div>\n";
	}

	protected function renderSocialPreview(string $name): string {
		$san     = $this->wire('sanitizer');
		$page    = $this->page;
		$httpUrl = ($page && $page->id) ? $page->httpUrl() : '';
		$parsed  = $httpUrl ? parse_url($httpUrl) : [];
		$host    = $san->entities($parsed['host'] ?? $_SERVER['HTTP_HOST'] ?? '');

		$fb = "<div>\n"
			. "<p class='uk-text-bold uk-text-small uk-margin-small-bottom'>Facebook / OpenGraph</p>\n"
			. "<div class='ichiban-fb-card'>\n"
			. "<div class='ichiban-fb-image'></div>\n"
			. "<div class='ichiban-fb-body'>\n"
			. "<div class='ichiban-fb-domain'>{$host}</div>\n"
			. "<div class='ichiban-fb-title'></div>\n"
			. "<div class='ichiban-fb-desc'></div>\n"
			. "</div>\n"
			. "</div>\n"
			. "</div>\n";

		$tw = "<div>\n"
			. "<p class='uk-text-bold uk-text-small uk-margin-small-bottom'>Twitter / X</p>\n"
			. "<div class='ichiban-tw-card'>\n"
			. "<div class='ichiban-tw-image'></div>\n"
			. "<div class='ichiban-tw-body'>\n"
			. "<div class='ichiban-tw-title'></div>\n"
			. "<div class='ichiban-tw-domain'>{$host}</div>\n"
			. "</div>\n"
			. "</div>\n"
			. "</div>\n";

		$li = "<div>\n"
			. "<p class='uk-text-bold uk-text-small uk-margin-small-bottom'>LinkedIn</p>\n"
			. "<div class='ichiban-li-card'>\n"
			. "<div class='ichiban-li-image'></div>\n"
			. "<div class='ichiban-li-body'>\n"
			. "<div class='ichiban-li-title'></div>\n"
			. "<div class='ichiban-li-domain'>{$host}</div>\n"
			. "</div>\n"
			. "</div>\n"
			. "</div>\n";

		return "<div class='ichiban-social-preview uk-margin' data-field='{$name}'>\n"
			. "<div class='ichiban-preview-heading'><div><strong>" . __('Social preview') . "</strong><span>" . __('Approximate cards for the most common sharing surfaces.') . "</span></div></div>\n"
			. "<div class='uk-grid uk-grid-medium uk-child-width-1-3@m' uk-grid>\n"
			. $fb . $tw . $li
			. "</div>\n"
			. "</div>\n";
	}

	protected function pageExcerpt(int $limit = 155): string {
		$page = $this->page;
		if (!$page || !$page->id) return __('Add a meta description to preview the search result snippet.');
		foreach (['summary', 'headline', 'body'] as $fieldName) {
			if (!$page->hasField($fieldName)) continue;
			$text = trim(strip_tags((string)$page->get($fieldName)));
			if ($text === '') continue;
			$text = preg_replace('/\s+/', ' ', $text) ?: $text;
			if (mb_strlen($text) <= $limit) return $text;
			return mb_substr($text, 0, $limit - 1) . '…';
		}
		return __('Add a meta description to preview the search result snippet.');
	}

	protected function renderRevisionHistory(): string {
		$page = $this->page;
		if (!$page || !$page->id) return '';
		try {
			$revs = $this->wire('modules')->get('Ichiban')->getSeoRevisions()->getRevisions((int)$page->id, 10);
		} catch (\Throwable $e) { return ''; }
		if (!$revs) return '';
		$san = $this->wire('sanitizer');
		$out = "<div class='uk-margin'><h4 class='uk-heading-bullet'>" . __('SEO Revision History') . "</h4>"
			. "<table class='uk-table uk-table-small uk-table-divider'><thead><tr>"
			. "<th>" . __('Date') . "</th><th>" . __('User') . "</th><th>" . __('Changes') . "</th><th></th>"
			. "</tr></thead><tbody>\n";
		foreach ($revs as $rev) {
			$changes = json_decode($rev['changes'], true) ?: [];
			$summary = $san->entities(implode(', ', array_map(fn($c) => $c['field'] ?? '', $changes)));
			$user    = $this->wire('users')->get((int)$rev['user_id']);
			$userName = ($user && $user->id) ? $san->entities($user->name) : '—';
			$out .= "<tr><td>" . $san->entities($rev['created_at']) . "</td><td>{$userName}</td>"
				. "<td>{$summary}</td><td><a href='#' class='ichiban-rev-restore' data-rev-id='" . (int)$rev['id'] . "'>" . __('Restore') . "</a></td></tr>\n";
		}
		return $out . "</tbody></table></div>\n";
	}

	protected function renderGscWidget(): string {
		try { $ok = (bool)$this->wire('modules')->get('Ichiban')->get('gsc_access_token'); }
		catch (\Throwable $e) { return ''; }
		if (!$ok) return '';
		$id = $this->page ? (int)$this->page->id : 0;
		return "<div class='uk-card uk-card-default uk-card-small uk-card-body uk-margin ichiban-gsc-widget' data-page-id='{$id}'>"
			. "<h4 class='uk-card-title'>" . __('Search Performance') . "</h4>"
			. "<p class='uk-text-muted'>" . __('Loading…') . "</p></div>\n";
	}

	// -------------------------------------------------------------------------
	// Process input
	// -------------------------------------------------------------------------

	public function ___processInput(WireInputData $input): self {
		$name = $this->attr('name');
		$post = $input[$name] ?? null;
		if ($post instanceof WireInputData) $post = $post->getArray();
		if (!is_array($post)) return $this;

		$value = $this->value;
		if (!$value instanceof \IchibanPageFieldValue) {
			$value = $this->wire('modules')->get('Ichiban')->getBlankValue(
				$this->page ?? new NullPage(),
				$this->field ?? new Field()
			);
		}

		$data = $value->getData();
		$san  = $this->wire('sanitizer');
		$debug = [
			'input_name' => $name,
			'page_id' => $this->page && $this->page->id ? (int)$this->page->id : 0,
			'field_name' => $this->field && $this->field->name ? (string)$this->field->name : '',
			'post_keys' => array_keys($post),
			'post_meta_title' => $post['meta_title'] ?? null,
			'post_meta_title_mode' => $post['meta_title_mode'] ?? null,
			'post_meta_title_value' => $post['meta_title_value'] ?? null,
		];

		foreach (['meta_title', 'meta_description', 'og_title', 'og_description'] as $key) {
			$source = null;
			if (isset($post[$key]) && is_array($post[$key])) {
				$source = $post[$key];
			} elseif (array_key_exists("{$key}_mode", $post) || array_key_exists("{$key}_value", $post)) {
				$source = [
					'mode' => $post["{$key}_mode"] ?? '',
					'value' => $post["{$key}_value"] ?? '',
				];
			}
			if (!is_array($source)) continue;
			$data[$key] = [
				'mode'  => in_array($source['mode'] ?? '', ['inherit', 'field', 'custom']) ? $source['mode'] : 'field',
				'value' => $san->text($source['value'] ?? ''),
			];
		}
		if ($this->debugEnabled()) {
			$debug['saved_data'] = $data;
			$this->wire('log')->save('ichiban-debug', json_encode($debug, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
		}

		foreach (['og_image_alt', 'og_type', 'twitter_card',
		          'twitter_creator', 'schema_type', 'sitemap_priority', 'sitemap_changefreq', 'robots_meta'] as $key) {
			if (array_key_exists($key, $post)) $data[$key] = $san->text($post[$key]);
		}
		// URL fields — use sanitizer->url() to preserve query strings and fragments
		if (array_key_exists('canonical_url', $post)) {
			$data['canonical_url'] = $san->url($post['canonical_url'], ['allowRelative' => true, 'allowSchemes' => ['http', 'https']]);
		}
		if (array_key_exists('og_image', $post)) {
			$data['og_image'] = $this->sanitizeUrlOrSourceExpression($post['og_image']);
		}

		foreach (['meta_noindex', 'meta_nofollow', 'sitemap_include'] as $key) {
			$data[$key] = !empty($post[$key]);
		}

		if (array_key_exists('jsonld_override', $post)) {
			$data['jsonld_override'] = $san->textarea($post['jsonld_override']);
		}

		$value->setData($data);
		$this->setAttribute('value', $value);
		if ($this->page && $this->field && $this->field->name && $this->page->hasField($this->field->name)) {
			$this->page->set($this->field->name, $value);
			$this->page->trackChange($this->field->name);
		}
		$this->trackChange('value');
		return $this;
	}

	protected function sanitizeUrlOrSourceExpression(mixed $value): string {
		$value = trim((string)$value);
		if ($value === '') return '';

		$fieldPath = '[A-Za-z0-9_][A-Za-z0-9_:.]*(?:\|[A-Za-z0-9_:-]+)*';
		if (preg_match('/^(?:field:)?' . $fieldPath . '$/', $value) || preg_match('/^\{' . $fieldPath . '\}$/', $value)) {
			return $this->wire('sanitizer')->text($value);
		}

		return $this->wire('sanitizer')->url($value, ['allowRelative' => true, 'allowSchemes' => ['http', 'https']]);
	}

	public function isEmpty(): bool { return false; }
}
