<?php namespace ProcessWire;

/**
 * ProcessIchiban — Admin Panel.
 *
 * Sections: Dashboard | Bulk Editor | Audit Report | Redirects |
 *           Insights | Reports | AI | SEO Revisions | Identity | Settings
 */
class ProcessIchiban extends Process {

	protected const MOZ_AFFILIATE_URL = '';
	protected const MOZ_SIGNUP_URL = 'https://moz.com/products/api/pricing';

	public static function getModuleInfo(): array {
		return [
			'title'    => 'Process Ichiban',
			'summary'  => 'Admin panel for Ichiban SEO module.',
			'author'   => 'Maxim Semenov',
			'version'  => 11,
			
			'page'     => [
				'name'   => 'ichiban',
				'title'  => 'SEO (Ichiban)',
				'parent' => 'admin',
				'icon'   => 'search',
			],
			'permission' => 'ichiban-manage',
			'permissions' => [
				'ichiban-manage' => 'Manage SEO settings and redirects',
			],
		];
	}

	protected $ichiban; // Ichiban module instance

	public function init(): void {
		parent::init();
		$this->ichiban = $this->wire('modules')->get('Ichiban');
		$url = $this->wire('config')->urls->Ichiban;
		$this->wire('config')->styles->add($url . 'assets/css/process.css');
		$this->wire('config')->scripts->add($url . 'assets/js/process.js');
	}

	// -------------------------------------------------------------------------
	// Execute (router)
	// -------------------------------------------------------------------------

	public function execute(): string {
		return $this->executeDashboard();
	}

	public function executeDashboard(): string {
		$this->setIchibanBreadcrumb(__('Dashboard'));
		$this->headline(__('SEO Dashboard'));

		/** @var \IchibanAuditEngine $audit */
		$auditEngine = new \IchibanAuditEngine($this->ichiban);
		$stats        = $auditEngine->getQuickStats();
		$recentRedirects = array_slice($this->ichiban->getRedirectManager()->findRedirects(), 0, 5);
		$db = $this->wire('database');
		try {
			$revisionCount = (int)$db->query("SELECT COUNT(*) FROM ichiban_revisions")->fetchColumn();
		} catch (\Throwable $e) {
			$revisionCount = 0;
		}
		try {
			$cleanupCount = (int)$db->query("SELECT COUNT(*) FROM ichiban_cleanup_log")->fetchColumn();
		} catch (\Throwable $e) {
			$cleanupCount = 0;
		}
		$schemaMappings = $this->getSchemaMappings();
		$schemaCount = count($schemaMappings);
		$gscSummary = [];
		$indexingSummary = [];
		if ($this->ichiban->get('gsc_access_token')) {
			try {
				$gsc = new \IchibanSearchStatistics($this->ichiban);
				$gscSummary = $gsc->getDashboardData(28);
				$indexingSummary = $gsc->getIndexingIssues(1);
			} catch (\Throwable $e) {
				$gscSummary = [];
				$indexingSummary = [];
			}
		}

		$out  = $this->renderAdminNav('dashboard');
		$out .= "<div class='ichiban-dashboard'>\n";
		$out .= "<div class='ichiban-dashboard-header'><div><p>" . __('A quick overview of metadata health, crawl cleanup activity, redirects, and recent SEO changes.') . "</p></div>"
			. "<div class='ichiban-dashboard-actions'><a class='uk-button uk-button-default' href='" . $this->adminUrl('audit/') . "'>" . __('Open Audit') . "</a><a class='uk-button uk-button-secondary' href='" . $this->adminUrl('bulk/') . "'>" . __('Edit Metadata') . "</a></div></div>\n";
		// Score widget
		$score = $stats['score'] ?? 0;
		$out .= "<div class='ichiban-score-widget'>"
			. $this->renderBatteryScore((int)$score, __('Site SEO Score'))
			. "<p class='uk-text-small uk-text-muted'>" . __('Site SEO Score') . "</p>"
			. "<a href='" . $this->adminUrl('audit/') . "'>" . __('Review score') . "</a>"
			. "</div>\n";
		// Quick stats
		$out .= "<div class='ichiban-quick-stats'>\n";
		foreach ([
			'pages_missing_title'       => [__('Missing Title'), __('Pages without a meta title.'), $this->adminUrl('bulk/') . '?issue=missing_title'],
			'pages_missing_description' => [__('Missing Description'), __('Pages without a search snippet.'), $this->adminUrl('bulk/') . '?issue=missing_description'],
			'pages_missing_og_image'    => [__('Missing OG Image'), __('Pages that may share poorly on social networks.'), $this->adminUrl('audit/')],
			'pages_noindex'             => [__('Noindex'), __('Published pages hidden from search engines.'), $this->adminUrl('audit/')],
		] as $key => [$label, $note, $url]) {
			$count = $stats[$key] ?? 0;
			$out .= "<a class='ichiban-stat-card' href='{$url}'><span class='ichiban-stat-value'>{$count}</span><span class='ichiban-stat-label'>{$label}</span><small>{$note}</small></a>\n";
		}
		$out .= "<a class='ichiban-stat-card' href='" . $this->adminUrl('schemas/') . "'><span class='ichiban-stat-value'>{$schemaCount}</span><span class='ichiban-stat-label'>" . __('Schemas') . "</span><small>" . __('Template-level structured data mappings.') . "</small></a>\n";
		if ($gscSummary) {
			$out .= "<a class='ichiban-stat-card' href='" . $this->adminUrl('search-statistics/') . "'><span class='ichiban-stat-value'>" . (int)($gscSummary['clicks'] ?? 0) . "</span><span class='ichiban-stat-label'>" . __('GSC clicks') . "</span><small>" . __('Last 28 days from Search Console cache.') . "</small></a>\n";
			$out .= "<a class='ichiban-stat-card' href='" . $this->adminUrl('search-statistics/') . "'><span class='ichiban-stat-value'>" . (int)($indexingSummary['issues'] ?? 0) . "</span><span class='ichiban-stat-label'>" . __('Indexing issues') . "</span><small>" . __('Cached URL Inspection scan results.') . "</small></a>\n";
		}
		$out .= "</div>\n";
		// Index status
		$indexedAt = $stats['indexed_at'] ?? null;
		$rebuildUrl = $this->adminUrl('audit/') . '?rebuild=1&' . $this->wire('session')->CSRF->getTokenName() . '=' . $this->wire('session')->CSRF->getTokenValue();
		$out .= "<div class='ichiban-index-status'><span>"
			. ($indexedAt ? sprintf(__('Index last rebuilt: %s'), $indexedAt) : __('Index not yet built.'))
			. "</span><a href='{$rebuildUrl}' class='uk-button uk-button-default'>" . __('Rebuild Index') . "</a></div>\n";
		$out .= "<div class='ichiban-dashboard-panels'>"
			. "<section><h3>" . __('Recent redirects') . "</h3>";
		if ($recentRedirects) {
			$out .= "<ul class='uk-list uk-list-divider'>\n";
			foreach ($recentRedirects as $redirect) {
				$out .= "<li><code>" . $this->wire('sanitizer')->entities($redirect['from_url']) . "</code> → "
					. "<code>" . $this->wire('sanitizer')->entities($redirect['to_url']) . "</code></li>\n";
			}
			$out .= "</ul>";
		} else {
			$out .= "<p>" . __('No redirects have been created yet.') . "</p>";
		}
		$out .= "<a href='" . $this->adminUrl('redirects/') . "'>" . __('Manage redirects') . "</a></section>"
			. "<section><h3>" . __('Structured data') . "</h3><div class='ichiban-dashboard-activity'>"
			. "<a href='" . $this->adminUrl('schemas/') . "'><strong>{$schemaCount}</strong><span>" . __('schema mappings') . "</span></a>"
			. "<a href='" . $this->adminUrl('audit/') . "'><strong>" . (int)($stats['pages_missing_og_image'] ?? 0) . "</strong><span>" . __('missing OG images') . "</span></a>"
			. "</div></section>"
			. "<section><h3>" . __('Activity') . "</h3><div class='ichiban-dashboard-activity'>"
			. "<a href='" . $this->adminUrl('revisions/') . "'><strong>{$revisionCount}</strong><span>" . __('SEO revisions') . "</span></a>"
			. "<a href='" . $this->adminUrl('cleanup/') . "'><strong>{$cleanupCount}</strong><span>" . __('blocked searches') . "</span></a>"
			. "</div></section>"
			. "</div>\n";
		$out .= "</div>\n";
		return $out;
	}

	public function executeBulk(): string {
		$this->setIchibanBreadcrumb(__('Bulk Editor'), 'bulk/');
		$this->headline(__('Bulk SEO Editor'));

		$san  = $this->wire('sanitizer');
		$db   = $this->wire('database');
		$tpl  = $san->text($this->wire('input')->get('template') ?? '');
		$issue = $san->text($this->wire('input')->get('issue') ?? '');
		$issueFilters = [
			''                    => __('All issue types'),
			'missing_title'       => __('Missing titles'),
			'missing_description' => __('Missing descriptions'),
			'title_length'        => __('Title length issues'),
			'description_length'  => __('Description length issues'),
		];
		if (!array_key_exists($issue, $issueFilters)) $issue = '';
		$page = max(1, (int)$this->wire('input')->get('p'));
		$perPage = 50;
		$offset  = ($page - 1) * $perPage;

		$whereParts = [];
		$params = [];
		if ($tpl) {
			$whereParts[] = 'template_name=:tpl';
			$params[':tpl'] = $tpl;
		}
		if ($issue === 'missing_title') {
			$whereParts[] = "meta_title=''";
		} elseif ($issue === 'missing_description') {
			$whereParts[] = "meta_description=''";
		} elseif ($issue === 'title_length') {
			$whereParts[] = "meta_title!='' AND NOT (meta_title_len BETWEEN 30 AND 70)";
		} elseif ($issue === 'description_length') {
			$whereParts[] = "meta_description!='' AND NOT (meta_desc_len BETWEEN 50 AND 160)";
		}
		$where = $whereParts ? ' WHERE ' . implode(' AND ', $whereParts) : '';
		try {
			if ($where) {
				$cStmt = $db->prepare("SELECT COUNT(*) FROM `ichiban_index`{$where}");
				$cStmt->execute($params);
			} else {
				$cStmt = $db->prepare("SELECT COUNT(*) FROM `ichiban_index`");
				$cStmt->execute();
			}
			$total = (int)$cStmt->fetchColumn();
		} catch (\Throwable $ex) {
			return "<div class='uk-alert uk-alert-warning'>" . __('Index not built yet. Please run Audit → Rebuild Index first.') . "</div>";
		}
		$statParams = $params;
		$missingTitleStmt = $db->prepare("SELECT COUNT(*) FROM `ichiban_index`{$where}" . ($where ? " AND" : " WHERE") . " meta_title=''");
		$missingTitleStmt->execute($statParams);
		$missingTitles = (int)$missingTitleStmt->fetchColumn();
		$missingDescStmt = $db->prepare("SELECT COUNT(*) FROM `ichiban_index`{$where}" . ($where ? " AND" : " WHERE") . " meta_description=''");
		$missingDescStmt->execute($statParams);
		$missingDescriptions = (int)$missingDescStmt->fetchColumn();
		$templateStmt = $db->prepare("SELECT COUNT(DISTINCT template_name) FROM `ichiban_index`{$where}");
		$templateStmt->execute($statParams);
		$templateCount = (int)$templateStmt->fetchColumn();

		$stmt = $db->prepare("SELECT * FROM ichiban_index{$where}
			ORDER BY
				(meta_title='') DESC,
				(meta_description='') DESC,
				(has_og_image=0) DESC,
				NOT (meta_title_len BETWEEN 30 AND 70) DESC,
				NOT (meta_desc_len BETWEEN 50 AND 160) DESC,
				is_noindex DESC,
				template_name,
				url
			LIMIT :lim OFFSET :off");
		foreach ($params as $name => $value) {
			$stmt->bindValue($name, $value);
		}
		$stmt->bindValue(':lim', $perPage, \PDO::PARAM_INT);
		$stmt->bindValue(':off', $offset, \PDO::PARAM_INT);
		$stmt->execute();
		$rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
		$firstShown = $total ? $offset + 1 : 0;
		$lastShown = min($offset + count($rows), $total);
		$pageCount = max(1, (int)ceil($total / $perPage));
		$queryBase = [];
		if ($tpl) $queryBase['template'] = $tpl;
		if ($issue !== '') $queryBase['issue'] = $issue;
		$filterSummary = $issue !== '' ? sprintf(__('Filtered to %s.'), $issueFilters[$issue]) : __('Showing all indexed pages.');

		$out  = $this->renderAdminNav('bulk');
		$out .= "<div class='ichiban-bulk'>\n";
		$out .= "<div class='ichiban-bulk-header'>"
			. "<div><p>" . __('Edit meta titles and descriptions across indexed pages without opening each page individually.') . "</p><small>" . $san->entities($filterSummary) . "</small></div>"
			. "<div class='ichiban-bulk-guidance'><strong>" . __('Recommended lengths') . "</strong><span>" . __('Title: 30–70 characters') . "</span><span>" . __('Description: 50–160 characters') . "</span></div>"
			. "</div>\n";
		$out .= "<div class='ichiban-bulk-stats'>"
			. "<div><strong>{$total}</strong><span>" . __('indexed pages') . "</span></div>"
			. "<div><strong>{$missingTitles}</strong><span>" . __('missing titles') . "</span></div>"
			. "<div><strong>{$missingDescriptions}</strong><span>" . __('missing descriptions') . "</span></div>"
			. "<div><strong>{$templateCount}</strong><span>" . __('templates') . "</span></div>"
			. "</div>\n";
		$out .= "<form method='get' class='ichiban-bulk-toolbar'>"
			. "<input class='uk-input' type='text' name='template' value='" . $san->entities($tpl) . "' placeholder='" . __('Filter by template name') . "'>"
			. "<select class='uk-select' name='issue'>";
		foreach ($issueFilters as $value => $label) {
			$selected = $issue === $value ? " selected" : "";
			$out .= "<option value='" . $san->entities($value) . "'{$selected}>" . $san->entities($label) . "</option>";
		}
		$out .= "</select>"
			. "<button class='uk-button uk-button-default'>" . __('Filter') . "</button>"
			. ($tpl || $issue !== '' ? "<a class='uk-button uk-button-secondary' href='" . $this->adminUrl('bulk/') . "'>" . __('Clear') . "</a>" : '')
			. "<span>" . sprintf(__('Showing %1$d-%2$d of %3$d matching indexed pages'), $firstShown, $lastShown, $total) . "</span>"
			. "</form>\n";
		$out .= "<form method='post' action='" . $this->adminUrl('bulk-save/') . "' class='ichiban-bulk-form'>\n";
		$out .= $this->wire('session')->CSRF->renderInput();
		$out .= "<div class='ichiban-bulk-actionbar'><span>" . __('Changes are saved as custom SEO values for each page.') . "</span><button type='submit' class='uk-button uk-button-primary'>" . __('Save Changes') . "</button></div>\n";
		if (!$rows) {
			$out .= "<div class='ichiban-bulk-empty'><h3>" . __('No indexed pages match this filter') . "</h3><p>" . __('Try a different template name or rebuild the audit index if pages were recently added.') . "</p></div>\n";
			$out .= "</form>\n</div>\n";
			return $out;
		}
		$out .= "<div class='ichiban-bulk-table-wrap uk-overflow-auto'><table class='ichiban-bulk-table AdminDataTable AdminDataTable--noSorting uk-table uk-table-divider uk-table-hover'>\n";
		$out .= "<thead><tr><th>" . __('Page') . "</th><th>" . __('Meta Title') . "</th><th>" . __('Meta Description') . "</th><th>" . __('Score') . "</th></tr></thead>\n<tbody>\n";
		$groups = [
			'poor' => ['label' => __('Critical'), 'note' => __('Fix these first. Important metadata is missing or too weak.'), 'rows' => []],
			'warning' => ['label' => __('Warnings'), 'note' => __('Review next. Metadata exists, but quality or coverage needs work.'), 'rows' => []],
			'good' => ['label' => __('Healthy'), 'note' => __('Low priority. These pages are in acceptable shape.'), 'rows' => []],
		];
		foreach ($rows as $row) {
			$score = $this->rowScore($row);
			$key = $score >= 80 ? 'good' : ($score >= 60 ? 'warning' : 'poor');
			$row['_ichiban_score'] = $score;
			$groups[$key]['rows'][] = $row;
		}
		foreach ($groups as $groupKey => $group) {
			if (!$group['rows']) continue;
			$out .= "<tr class='ichiban-bulk-section ichiban-bulk-section-{$groupKey}'><td colspan='4'>"
				. "<strong>" . $this->wire('sanitizer')->entities($group['label']) . "</strong>"
				. "<span>" . sprintf(__('%d visible pages'), count($group['rows'])) . "</span>"
				. "<small>" . $this->wire('sanitizer')->entities($group['note']) . "</small>"
				. "</td></tr>\n";
				foreach ($group['rows'] as $row) {
					$pid   = (int)$row['page_id'];
					$title = htmlspecialchars($row['meta_title'] ?? '', ENT_QUOTES, 'UTF-8');
					$desc  = htmlspecialchars($row['meta_description'] ?? '', ENT_QUOTES, 'UTF-8');
					$url   = $san->entities($row['url']);
					$template = $san->entities($row['template_name'] ?? '');
					$pageObj = $this->wire('pages')->get($pid);
					$editUrl = ($pageObj && $pageObj->id) ? $san->entities($pageObj->editUrl) : '';
					$titleLen = (int)($row['meta_title_len'] ?? strlen((string)($row['meta_title'] ?? '')));
					$descLen = (int)($row['meta_desc_len'] ?? strlen((string)($row['meta_description'] ?? '')));
					$score = (int)$row['_ichiban_score'];
					$scoreClass = $score >= 80 ? 'ichiban-score-good' : ($score >= 60 ? 'ichiban-score-warning' : 'ichiban-score-poor');
					$scoreReasons = implode(' · ', $this->rowScoreReasons($row));
					$titleHint = $titleLen === 0 ? __('Missing title') : sprintf(__('%d characters'), $titleLen);
					$descHint = $descLen === 0 ? __('Missing description') : sprintf(__('%d characters'), $descLen);
					$out  .= "<tr>"
						. "<td><div class='ichiban-bulk-page'><a href='{$url}' target='_blank' rel='noopener'>{$url}</a>"
						. "<div class='ichiban-bulk-page-actions'><span>{$template}</span>" . ($editUrl !== '' ? "<a href='{$editUrl}'>" . __('Edit page') . "</a>" : '') . "</div></div></td>"
						. "<td><div class='ichiban-bulk-field'><input class='uk-input' type='text' name='meta_title[{$pid}]' value=\"{$title}\" maxlength='70'><span>{$titleHint} · " . __('target 30–70') . "</span></div></td>"
						. "<td><div class='ichiban-bulk-field'><input class='uk-input' type='text' name='meta_description[{$pid}]' value=\"{$desc}\" maxlength='160'><span>{$descHint} · " . __('target 50–160') . "</span></div></td>"
						. "<td><span class='ichiban-score-badge {$scoreClass}' data-score='{$score}'>{$score}</span><small>" . $san->entities($scoreReasons) . "</small></td>"
						. "</tr>\n";
				}
		}
		$out .= "</tbody></table></div>\n";
		if ($pageCount > 1) {
			$prev = $page > 1 ? $queryBase + ['p' => $page - 1] : null;
			$next = $page < $pageCount ? $queryBase + ['p' => $page + 1] : null;
			$out .= "<div class='ichiban-bulk-toolbar'>"
				. ($prev ? "<a class='uk-button uk-button-default' href='" . $san->entities($this->adminUrl('bulk/') . '?' . http_build_query($prev)) . "'>" . __('Previous') . "</a>" : "")
				. "<span>" . sprintf(__('Page %1$d of %2$d'), $page, $pageCount) . "</span>"
				. ($next ? "<a class='uk-button uk-button-default' href='" . $san->entities($this->adminUrl('bulk/') . '?' . http_build_query($next)) . "'>" . __('Next') . "</a>" : "")
				. "</div>\n";
		}
		$out .= "<div class='ichiban-bulk-actionbar ichiban-bulk-actionbar-bottom'><span>" . __('Review changed fields, then save all visible edits together.') . "</span><button type='submit' class='uk-button uk-button-primary'>" . __('Save Changes') . "</button></div>\n";
		$out .= "</form>\n</div>\n";
		return $out;
	}

	public function executeBulkSave(): string {
		$this->wire('session')->CSRF->validate();
		$titles = $this->wire('input')->post('meta_title');
		$descs  = $this->wire('input')->post('meta_description');
		$titles = is_array($titles) ? $titles : [];
		$descs  = is_array($descs) ? $descs : [];
		$san    = $this->wire('sanitizer');
		$saved  = 0;
		$skipped = 0;

		foreach ($titles as $pageId => $title) {
			$pageId = (int)$pageId;
			$p = $this->wire('pages')->get($pageId);
			$fn = $this->ichiban->getSeoFieldName();
			if (!$p->id || !$p->hasField($fn)) {
				$skipped++;
				continue;
			}
			$p->of(false);
			$seo = $p->get($fn);
			if (!$seo instanceof \IchibanPageFieldValue) {
				$skipped++;
				continue;
			}
			$data = $seo->getData();
			$data['meta_title']       = ['mode' => 'custom', 'value' => $san->text($title)];
			$data['meta_description'] = ['mode' => 'custom', 'value' => $san->text($descs[$pageId] ?? '')];
			$seo->setData($data);
			$p->set($fn, $seo);
			$p->trackChange($fn);
			$p->save($fn);
			$saved++;
		}
		try {
			$engine = new \IchibanAuditEngine($this->ichiban);
			$engine->rebuildIndex();
		} catch (\Throwable $e) {
			$this->wire('log')->save('ichiban', 'Bulk save index rebuild failed: ' . $e->getMessage());
		}
		$this->wire('session')->message(sprintf(__('Saved SEO fields for %d pages.'), $saved));
		if ($skipped > 0) {
			$this->wire('session')->warning(sprintf(__('%d submitted pages were skipped because they no longer have an Ichiban SEO field.'), $skipped));
		}
		$this->wire('session')->redirect($this->adminUrl('bulk/'));
		return '';
	}

	public function executeAudit(): string {
		$this->setIchibanBreadcrumb(__('Audit'), 'audit/');
		$this->headline(__('SEO Audit Report'));

		if ($this->wire('input')->get('rebuild')) {
			// Verify nonce to prevent CSRF-triggered rebuilds
			$csrfName     = $this->wire('session')->CSRF->getTokenName();
			$csrfExpected = $this->wire('session')->CSRF->getTokenValue();
			$csrfGiven    = $this->wire('input')->get($csrfName);
			if (!$csrfGiven || !hash_equals($csrfExpected, $csrfGiven)) {
				$this->wire('session')->redirect($this->adminUrl('audit/'));
				return '';
			}
			try {
				$engine = new \IchibanAuditEngine($this->ichiban);
				$engine->rebuildIndex();
			} catch (\Throwable $ex) {
				$this->wire('log')->save('ichiban-audit', 'ERROR: ' . $ex->getMessage() . ' in ' . $ex->getFile() . ':' . $ex->getLine());
				$this->wire('session')->message('Ichiban Audit Error: ' . $ex->getMessage(), \ProcessWire\Notice::warning);
			}
			$this->wire('session')->redirect($this->adminUrl('audit/'));
			return '';
		}
		// Export CSV
		if ($this->wire('input')->get('export') === 'csv') {
			return $this->executeAuditCsv();
		}

		try {
			$engine = new \IchibanAuditEngine($this->ichiban);
			$report = $engine->getReport();
		} catch (\Throwable $ex) {
			return "<div class='uk-alert uk-alert-warning'>" . __('Index not built yet. Please rebuild the index first.') . "</div>";
		}

		$ruleDescriptions = [
			'TitlePresent'       => __('Every page must have a meta title. Missing titles prevent search engines from identifying page content.'),
			'TitleLength'        => __('Meta title should be 30–70 characters. Too short lacks context; too long gets truncated in search results.'),
			'TitleUnique'        => __('Each page should have a unique meta title. Duplicate titles confuse search engines about which page to rank.'),
			'DescriptionPresent' => __('Meta description should be set on every page. It appears as the snippet in search results and affects click-through rate.'),
			'DescriptionLength'  => __('Meta description should be 50–160 characters. Longer descriptions get cut off in search results.'),
			'DescriptionUnique'  => __('Each page should have a unique meta description. Duplicates reduce the value of individual pages in search rankings.'),
			'OgImagePresent'     => __('Open Graph image should be set so shared links look rich on social media.'),
			'CanonicalValid'     => __('Canonical URL must be an absolute URL starting with http. Invalid canonicals can cause indexing issues.'),
			'NoindexOnPublic'    => __('Published pages should not have noindex set unless intentionally hidden from search engines.'),
			'UrlNoUnderscores'   => __('URLs should use hyphens instead of underscores. Google treats hyphens as word separators, underscores as word joiners.'),
			'SchemaPresent'      => __('Schema.org type should be set to help search engines understand page content and enable rich results.'),
		];

		$engine2    = new \IchibanAuditEngine($this->ichiban);
		$quickStats = $engine2->getQuickStats();
		$indexedAt  = $quickStats['indexed_at'] ?? null;
		$auditScore = (int)($report['score'] ?? ($quickStats['score'] ?? 0));
		$totalPages = (int)($report['total'] ?? 0);
		$totalIssues = 0;
		$severityCounts = ['critical' => 0, 'error' => 0, 'warning' => 0, 'info' => 0];
		$priorityRules = [];
		foreach ($report['rules'] ?? [] as $rule) {
			$issues = (int)($rule['issues'] ?? 0);
			$severity = (string)($rule['severity'] ?? 'info');
			$totalIssues += $issues;
			if (array_key_exists($severity, $severityCounts)) {
				$severityCounts[$severity] += $issues;
			}
			if ($issues > 0) {
				$priorityRules[] = $rule;
			}
		}
		usort($priorityRules, function(array $a, array $b): int {
			$order = ['critical' => 0, 'error' => 1, 'warning' => 2, 'info' => 3];
			$severityCompare = ($order[$a['severity'] ?? 'info'] ?? 4) <=> ($order[$b['severity'] ?? 'info'] ?? 4);
			if ($severityCompare !== 0) return $severityCompare;
			return ((int)($b['issues'] ?? 0)) <=> ((int)($a['issues'] ?? 0));
		});
		$priorityRules = array_slice($priorityRules, 0, 3);
		$scoreClass = $auditScore >= 80 ? 'ichiban-score-good' : ($auditScore >= 60 ? 'ichiban-score-warning' : 'ichiban-score-poor');

		$out  = $this->renderAdminNav('audit');
		$out .= "<div class='ichiban-audit'>\n";
		$rebuildUrl = $this->adminUrl('audit/') . '?rebuild=1&' . $this->wire('session')->CSRF->getTokenName() . '=' . $this->wire('session')->CSRF->getTokenValue();
		$out .= "<div class='ichiban-audit-header'>"
			. "<div><p>" . __('Review technical SEO signals, metadata coverage, social sharing readiness, and schema basics across indexed pages.') . "</p></div>"
			. "<div class='ichiban-audit-actions'><a href='{$rebuildUrl}' class='uk-button uk-button-default'>" . __('Rebuild Index') . "</a>"
			. "<a href='" . $this->adminUrl('audit/') . "?export=csv' class='uk-button uk-button-secondary'>" . __('Export CSV') . "</a></div>"
			. "</div>\n";
		$out .= "<div class='ichiban-audit-overview'>"
			. "<div class='ichiban-audit-score'>" . $this->renderBatteryScore($auditScore, __('Audit score')) . "</div>"
			. "<div><strong>{$totalPages}</strong><span>" . __('indexed pages') . "</span></div>"
			. "<div><strong>{$totalIssues}</strong><span>" . __('open issues') . "</span></div>"
			. "<div><strong>" . (int)$severityCounts['critical'] . "</strong><span>" . __('critical') . "</span></div>"
			. "<div><strong>" . (int)$severityCounts['warning'] . "</strong><span>" . __('warnings') . "</span></div>"
			. "</div>\n";
		$out .= "<div class='ichiban-audit-meta'>"
			. "<span>" . ($indexedAt ? __('Last indexed:') . ' ' . $this->wire('sanitizer')->entities($indexedAt) : __('Index has not been rebuilt yet.')) . "</span>"
			. "<span>" . __('Rebuild the index after large content, template, or SEO field changes.') . "</span>"
			. "</div>\n";
		$out .= "<div class='ichiban-audit-focus'>"
			. "<div><h3>" . __('Fix first') . "</h3><p>" . __('Start with the highest severity checks that currently affect pages.') . "</p></div>";
		if ($priorityRules) {
			$out .= "<div class='ichiban-audit-focus-list'>";
			foreach ($priorityRules as $rule) {
				$name = $this->wire('sanitizer')->entities($rule['name']);
				$severity = $this->wire('sanitizer')->entities($rule['severity']);
				$desc = $this->wire('sanitizer')->entities($ruleDescriptions[$rule['name']] ?? '');
				$issues = (int)$rule['issues'];
				$affectedPages = $this->auditRuleAffectedPages((string)$rule['name'], 4);
				$pageLinks = '';
				if ($affectedPages) {
					$pageLinks .= "<div class='ichiban-audit-page-links'>";
					foreach ($affectedPages as $pageRow) {
						$pageLinks .= "<a href='" . $this->wire('sanitizer')->entities($pageRow['edit_url']) . "'>" . $this->wire('sanitizer')->entities($pageRow['url']) . "</a>";
					}
					$pageLinks .= "</div>";
				}
				$out .= "<div class='ichiban-audit-focus-item'>"
					. "<span class='uk-label ichiban-severity-{$severity}'>{$severity}</span>"
					. "<strong>{$name}</strong>"
					. "<p>{$desc}</p>"
					. "<em>" . sprintf(__('%d pages affected'), $issues) . "</em>"
					. $pageLinks
					. "</div>";
			}
			$out .= "</div>";
		} else {
			$out .= "<div class='ichiban-audit-healthy'><strong>" . __('No active audit issues') . "</strong><span>" . __('All current rules are passing for the indexed pages.') . "</span></div>";
		}
		$out .= "</div>\n";

		// Summary table
		$out .= "<div class='ichiban-audit-table-panel'><div class='ichiban-panel-heading'><h3>" . __('Rule details') . "</h3><p>" . __('Use this table to understand every rule, including checks that are currently passing.') . "</p></div>"
			. "<div class='uk-overflow-auto'><table class='AdminDataTable uk-table uk-table-divider uk-table-hover ichiban-audit-table'><thead><tr>"
			. "<th>" . __('Rule') . "</th>"
			. "<th>" . __('Severity') . "</th>"
			. "<th>" . __('Description') . "</th>"
			. "<th>" . __('Issues') . "</th>"
			. "<th>" . __('Pages Affected') . "</th>"
			. "</tr></thead><tbody>\n";
		foreach ($report['rules'] ?? [] as $rule) {
			$desc = $this->wire('sanitizer')->entities($ruleDescriptions[$rule['name']] ?? '');
			$affectedPages = (int)$rule['pages'] > 0 ? $this->auditRuleAffectedPages((string)$rule['name'], 1) : [];
			$pagesAffected = "<span class='ichiban-audit-issue-count'>" . (int)$rule['pages'] . "</span>";
			if ($affectedPages) {
				$pagesAffected = "<a class='ichiban-audit-issue-count' href='" . $this->wire('sanitizer')->entities($affectedPages[0]['edit_url']) . "' title='" . __('Open first affected page') . "'>" . (int)$rule['pages'] . "</a>";
			}
			$out .= "<tr>"
				. "<td><strong>" . $this->wire('sanitizer')->entities($rule['name']) . "</strong></td>"
				. "<td><span class='uk-label ichiban-severity-{$rule['severity']}'>{$rule['severity']}</span></td>"
				. "<td class='uk-text-small'>{$desc}</td>"
				. "<td><span class='ichiban-audit-issue-count'>" . (int)$rule['issues'] . "</span></td>"
				. "<td>{$pagesAffected}</td>"
				. "</tr>\n";
		}
		$out .= "</tbody></table></div></div>\n</div>\n";
		return $out;
	}

	public function executeRedirects(): string {
		$this->setIchibanBreadcrumb(__('Redirects'), 'redirects/');
		$this->headline(__('Redirect Manager'));
		$manager = $this->ichiban->getRedirectManager();
		$action  = $this->wire('input')->post('action');
		if ($this->wire('input')->get('export') === 'csv') {
			header('Content-Type: text/csv; charset=utf-8');
			header('Content-Disposition: attachment; filename="ichiban-redirects-' . date('Y-m-d') . '.csv"');
			echo $manager->exportCsv();
			exit;
		}

		if ($action === 'save') {
			$this->wire('session')->CSRF->validate();
			$manager->saveFromPost($this->wire('input')->post);
			$this->wire('session')->redirect($this->adminUrl('redirects/'));
		}
		if ($action === 'import') {
			$this->wire('session')->CSRF->validate();
			$count = $manager->importCsvString((string)$this->wire('input')->post('csv'));
			$this->wire('session')->message(sprintf(__('Imported %d redirects.'), $count));
			$this->wire('session')->redirect($this->adminUrl('redirects/'));
		}
		if ($action === 'delete') {
			$this->wire('session')->CSRF->validate();
			$manager->delete((int)$this->wire('input')->post('id'));
			$this->wire('session')->redirect($this->adminUrl('redirects/'));
		}

		$query   = $this->wire('sanitizer')->text($this->wire('input')->get('q') ?? '');
		$items   = $manager->findRedirects($query);
		$csrf    = $this->wire('session')->CSRF;
		$out     = $this->renderAdminNav('redirects');
		$out    .= "<div class='ichiban-redirects'>\n";
		$out .= "<div class='ichiban-redirect-header'>"
			. "<div><p>" . __('Manage manual, imported, and automatic redirects from one place.') . "</p></div>"
			. "<div class='ichiban-redirect-count'><strong>" . count($items) . "</strong><span>" . __('shown') . "</span></div>"
			. "</div>\n";
		$out .= "<form method='get' class='ichiban-redirect-toolbar'>"
			. "<input class='uk-input' type='search' name='q' value='" . $this->wire('sanitizer')->entities($query) . "' placeholder='" . __('Search by source, target, or note') . "'>"
			. "<button class='uk-button uk-button-default'>" . __('Search') . "</button>"
			. "<a class='uk-button uk-button-secondary' href='" . $this->adminUrl('redirects/') . "?export=csv'>" . __('Export CSV') . "</a>"
			. "</form>\n";
		// Add form
		$out .= "<form method='post' class='ichiban-redirect-panel ichiban-redirect-create'>{$csrf->renderInput()}"
			. "<input type='hidden' name='action' value='save'>\n"
			. "<div class='ichiban-panel-heading'><h3>" . __('Add redirect') . "</h3><p>" . __('Create a redirect for moved or removed URLs.') . "</p></div>"
			. "<div class='ichiban-redirect-form'>\n"
			. "<label class='ichiban-redirect-field'><span>" . __('From') . "</span><input class='uk-input' type='text' name='from_url' placeholder='" . __('/old-path') . "' required></label>\n"
			. "<label class='ichiban-redirect-field'><span>" . __('To') . "</span><input class='uk-input' type='text' name='to_url' placeholder='" . __('/new-path or https://…') . "' required></label>\n"
			. "<label class='ichiban-redirect-field ichiban-redirect-type'><span>" . __('Type') . "</span><select class='uk-select' name='type'><option value='301'>301</option><option value='302'>302</option>"
			. "<option value='307'>307</option><option value='410'>410</option><option value='451'>451</option></select></label>\n"
			. "<label class='ichiban-redirect-field ichiban-redirect-note'><span>" . __('Note') . "</span><input class='uk-input' type='text' name='note' placeholder='" . __('Optional note') . "'></label>\n"
			. "<label class='ichiban-redirect-pattern' title='" . __('Treat From as a regular expression pattern.') . "'><input class='uk-checkbox' type='checkbox' name='is_regex' value='1'> " . __('Pattern') . "</label>\n"
			. "<button type='submit' class='uk-button uk-button-primary'>" . __('Add Redirect') . "</button>\n"
			. "</div></form>\n";
		$out .= "<form method='post' class='ichiban-redirect-panel ichiban-redirect-import'>{$csrf->renderInput()}<input type='hidden' name='action' value='import'>"
			. "<div class='ichiban-panel-heading'><h3>" . __('Import CSV') . "</h3><p>" . __('Paste rows in the format below, then import them into the redirect list.') . "</p></div>"
			. "<div class='ichiban-csv-help'>"
			. "<strong>" . __('CSV format') . "</strong>"
			. "<code>from_url,to_url,type,is_regex,note</code>"
			. "<span>" . __('Allowed types: 301, 302, 307, 410, 451. Use 1 for regex patterns and 0 for plain paths.') . "</span>"
			. "<span>" . __('Example:') . " <code>/old-page,/new-page,301,0,Campaign cleanup</code></span>"
			. "</div>"
			. "<textarea class='uk-textarea' name='csv' rows='3' placeholder='from_url,to_url,type,is_regex,note'></textarea>"
			. "<button type='submit' class='uk-button uk-button-default'>" . __('Import') . "</button></form>\n";

		// Table
		$out .= "<div class='ichiban-redirect-panel ichiban-redirect-list'><div class='ichiban-panel-heading'><h3>" . __('Redirect list') . "</h3><p>" . __('Edit destinations, track hits, and remove obsolete redirects.') . "</p></div>"
			. "<div class='uk-overflow-auto'><table class='AdminDataTable AdminDataTable--hasCheckboxes uk-table uk-table-divider uk-table-hover ichiban-redirect-table'>\n<thead><tr>"
			. "<th>" . __('From') . "</th><th>" . __('To') . "</th><th>" . __('Type') . "</th>"
			. "<th>" . __('Hits') . "</th><th>" . __('Note') . "</th><th>" . __('Source') . "</th><th></th>"
			. "</tr></thead>\n<tbody>\n";
		foreach ($items as $item) {
			$san  = $this->wire('sanitizer');
			$id   = (int)$item['id'];
			$type = (int)$item['type'];
			$hits = (int)$item['hits'];
			$from = $san->entities($item['from_url']);
			$to = $san->entities($item['to_url']);
			$note = $san->entities($item['note']);
			$fromOpen = $this->redirectOpenUrl((string)$item['from_url']);
			$toOpen = $this->redirectOpenUrl((string)$item['to_url']);
			$sourceLabel = !empty($item['auto']) ? __('Auto') : __('Manual');
			$sourceClass = !empty($item['auto']) ? 'ichiban-source-auto' : 'ichiban-source-manual';
			$formId = "ichiban-redirect-{$id}";
			$out .= "<tr>"
				. "<td><input form='{$formId}' class='uk-input' type='text' name='from_url' value='{$from}'>"
				. "<div class='ichiban-redirect-tools'><label title='" . __('Treat From as a regular expression pattern.') . "'><input form='{$formId}' class='uk-checkbox' type='checkbox' name='is_regex' value='1'" . ($item['is_regex'] ? ' checked' : '') . "> " . __('Pattern') . "</label>"
				. ($fromOpen ? " <a class='uk-button uk-button-default' href='" . $san->entities($fromOpen) . "' target='_blank' rel='noopener'>" . __('Open') . "</a>" : '')
				. "</div></td>"
				. "<td><input form='{$formId}' class='uk-input' type='text' name='to_url' value='{$to}'>"
				. "<div class='ichiban-redirect-tools'>" . ($toOpen ? "<a class='uk-button uk-button-default' href='" . $san->entities($toOpen) . "' target='_blank' rel='noopener'>" . __('Open') . "</a>" : "<span class='uk-text-muted'>" . __('No target URL') . "</span>") . "</div></td>"
				. "<td><select form='{$formId}' class='uk-select' name='type'>";
			foreach ([301, 302, 307, 410, 451] as $optionType) {
				$out .= "<option value='{$optionType}'" . ($type === $optionType ? ' selected' : '') . ">{$optionType}</option>";
			}
			$out .= "</select></td>"
				. "<td class='ichiban-redirect-hits'>{$hits}</td>"
				. "<td><input form='{$formId}' class='uk-input' type='text' name='note' value='{$note}'></td>"
				. "<td><span class='ichiban-source-badge {$sourceClass}' title='" . (!empty($item['auto']) ? __('Created automatically after a page path change.') : __('Created manually or imported from CSV.')) . "'>{$sourceLabel}</span></td>"
				. "<td><div class='ichiban-redirect-actions'><form method='post' id='{$formId}' class='uk-margin-remove'>{$csrf->renderInput()}<input type='hidden' name='action' value='save'><input type='hidden' name='id' value='{$id}'>"
				. "<button type='submit' class='uk-button uk-button-primary'>" . __('Save') . "</button></form>"
				. "<form method='post' class='uk-margin-remove'>{$csrf->renderInput()}<input type='hidden' name='action' value='delete'>"
				. "<input type='hidden' name='id' value='{$id}'>"
				. "<button type='submit' class='uk-button uk-button-danger ichiban-btn-danger'>" . __('Delete') . "</button></form></div></td>"
				. "</tr>\n";
		}
		$out .= "</tbody></table></div></div>\n</div>\n";
		return $out;
	}

	public function executeSearchStatistics(): string {
		$this->setIchibanBreadcrumb(__('Insights'), 'search-statistics/');
		$this->headline(__('SEO Insights'));
		$gsc = new \IchibanSearchStatistics($this->ichiban);

		if ($this->wire('input')->get('connect')) {
			if (!$this->ichiban->get('gsc_client_id') || !$this->ichiban->get('gsc_client_secret')) {
				$this->wire('session')->warning(__('Add Google OAuth Client ID and Client Secret in Settings before connecting Search Console.'));
				$this->wire('session')->redirect($this->adminUrl('settings/'));
			}
			$authUrl = $gsc->getAuthUrl();
			$this->wire('session')->redirect($authUrl);
		}
		if ($this->wire('input')->post('disconnect') || $this->wire('input')->get('disconnect')) {
			if ($this->wire('session')->CSRF->hasValidToken()) {
				$gsc->disconnect();
				$this->wire('session')->message(__('Google Search Console disconnected. Cached Insights and Page Indexing scan data were cleared.'));
			} else {
				$this->wire('session')->warning(__('Security token expired. Reload the page and click Disconnect GSC again.'));
			}
			$this->wire('session')->redirect($this->adminUrl('search-statistics/'));
		}
		if ($code = $this->wire('input')->get('code')) {
			$gsc->handleCallback($this->wire('sanitizer')->text($code));
			$this->wire('session')->redirect($this->adminUrl('search-statistics/'));
		}

		if (!$gsc->isConnected()) {
			return $this->renderAdminNav('search-statistics') . $this->renderGscEmptyState();
		}

		$days = (int)$this->wire('input')->get('days');
		if (!in_array($days, [7, 28, 90, 180, 365], true)) $days = 28;
		$view = $this->wire('sanitizer')->name((string)$this->wire('input')->get('gsc_view'));
		if (!in_array($view, ['overview', 'pages', 'queries'], true)) $view = 'overview';
		if ($this->wire('input')->post('refresh_indexing') || $this->wire('input')->get('refresh_indexing')) {
			if ($this->wire('session')->CSRF->hasValidToken()) {
				$count = $gsc->refreshIndexingIssues(10);
				if ($count > 0) {
					$this->wire('session')->message(sprintf(__('Checked %d URLs with URL Inspection API.'), $count));
				} else {
					$error = $gsc->getLastError();
					$this->wire('session')->warning($error ? sprintf(__('URL Inspection scan did not return results: %s'), $error) : __('URL Inspection scan did not return results.'));
				}
			} else {
				$this->wire('session')->warning(__('Security token expired. Reload the page and run Scan indexing issues again.'));
			}
			$this->wire('session')->redirect($this->gscUrl($view, $days));
		}
		$rowLimit = $view === 'overview' ? 10 : 0;
		$data = $gsc->getDashboardData($days);
		$out  = $this->renderAdminNav('search-statistics');
		$out .= "<div class='ichiban-gsc-page'>\n";
		$out .= "<div class='ichiban-gsc-page-header'><div><p>" . __('Review Google Search Console performance, page indexing checks, and search breakdowns from cached API data.') . "</p></div>"
			. "<a class='uk-button uk-button-default' target='_blank' rel='noopener' href='https://search.google.com/search-console'>" . __('Open Search Console') . "</a></div>\n";
		$out .= $this->renderIntegrationStatus($gsc->isConnected(), __('Connected'), __('Not connected'));
		$out .= $this->renderGscToolbar($days, $view);
		$out .= "<div class='ichiban-gsc-stats'>\n";
		// Metrics overview
		foreach (['clicks' => __('Clicks'), 'impressions' => __('Impressions'), 'ctr' => __('CTR'), 'position' => __('Avg Position')] as $key => $label) {
			$val  = $data[$key] ?? 0;
			$out .= "<div class='ichiban-gsc-metric'><span class='ichiban-gsc-value'>{$val}</span><span class='ichiban-gsc-label'>{$label}</span></div>\n";
		}
		$out .= "</div>\n";
		$disconnectUrl = $this->adminUrl('search-statistics/');
		$out .= $this->renderGscTrendChart($gsc->getDailyRows($days));
		if ($view === 'pages') {
			$out .= $this->renderGscTable(__('Top Pages'), $gsc->getTopPages($days, $rowLimit), __('Page'));
		} elseif ($view === 'queries') {
			$out .= $this->renderGscTable(__('Top Queries'), $gsc->getTopQueries($days, $rowLimit), __('Query'));
		} else {
			$out .= $this->renderGscIndexingSection($gsc, $days, $view);
			$out .= "<div class='ichiban-gsc-primary-grid'>\n";
			$out .= $this->renderGscTable(__('Top Pages'), $gsc->getTopPages($days, 10), __('Page'), $this->gscUrl('pages', $days));
			$out .= $this->renderGscTable(__('Top Queries'), $gsc->getTopQueries($days, 10), __('Query'), $this->gscUrl('queries', $days));
			$out .= "</div>\n";
			$out .= "<div class='ichiban-gsc-breakdowns'>\n";
			$out .= $this->renderGscTable(__('Countries'), $gsc->getTopCountries($days, 10), __('Country'));
			$out .= $this->renderGscTable(__('Devices'), $gsc->getTopDevices($days, 10), __('Device'));
			$out .= $this->renderGscTable(__('Search Appearance'), $gsc->getSearchAppearances($days, 10), __('Appearance'));
			$out .= "</div>\n";
		}
		$out .= $this->renderGscFooter($gsc, $disconnectUrl);
		$out .= "</div>\n";
		return $out;
	}

	public function executeBacklinks(): string {
		$this->setIchibanBreadcrumb(__('Backlinks'), 'backlinks/');
		$this->headline(__('Backlinks'));

		$moz = $this->ichiban->getBacklinksMoz();
		$backlinks = $this->ichiban->getBacklinks();
		$san = $this->wire('sanitizer');
		$input = $this->wire('input');
		$view = $san->name((string)($input->post('backlinks_view') ?: $input->get('backlinks_view')));
		if (!in_array($view, ['links', 'domains', 'anchors'], true)) $view = 'links';
		$scope = $san->name((string)($input->post('scope') ?: $input->get('scope')));
		if (!in_array($scope, ['root_domain', 'subdomain', 'page'], true)) $scope = 'root_domain';
		$target = trim((string)($input->post('target') ?: $input->get('target') ?: $this->ichiban->get('moz_target') ?: $this->ichiban->siteUrl()));
		$limit = (int)($input->post('limit') ?: $input->get('limit') ?: $this->ichiban->get('moz_row_limit') ?: 5);
		$limit = max(1, min(1000, $limit));
		$didFetch = $input->post('refresh_backlinks') || $input->get('refresh');
		$rows = [];
		$snapshot = null;
		$error = '';

		if ($input->post('refresh_quota')) {
			$this->wire('session')->CSRF->validate();
			$quota = $backlinks->refreshQuotaFromMoz();
			if ($quota) {
				$this->wire('session')->message(__('Moz quota snapshot saved.'));
			} else {
				$error = $moz->getLastError() ?: __('Moz quota lookup did not return data.');
			}
		}
		if ($didFetch) {
			if ($input->post('refresh_backlinks')) {
				$this->wire('session')->CSRF->validate();
			}
			$result = $backlinks->refreshFromMoz($view, $target, $scope, $limit);
			$snapshot = $result['snapshot'];
			$rows = $result['rows'];
			$error = (string)$result['error'];
			if (!$error && $snapshot) {
				$this->wire('session')->message(sprintf(__('Saved backlink snapshot with %d rows.'), count($rows)));
			}
		} else {
			$snapshot = $backlinks->getLatestSnapshot($view, $target, $scope);
			if ($snapshot) $rows = $backlinks->getRowsForSnapshot((int)$snapshot['id']);
		}
		$history = $backlinks->getHistory($view, $target, $scope, 5);
		$quota = $backlinks->getLatestQuota();

		$out  = $this->renderAdminNav('backlinks');
		$out .= "<div class='ichiban-backlinks'>\n";
		$out .= "<div class='ichiban-backlinks-header'><div><p>" . __('Monitor inbound links, linking domains, and anchor text through the Moz Links API.') . "</p></div>"
			. "<a class='uk-button uk-button-default' href='" . $this->adminUrl('settings/') . "#wrap_Inputfield_moz_target'>" . __('Moz Settings') . "</a></div>\n";
		$out .= $this->renderBacklinksConnectionStatus($moz);
		if (!$moz->isConfigured()) {
			$out .= $this->renderBacklinksSetupGuide();
		}
		if ($moz->isConfigured()) {
			$out .= $this->renderBacklinksQuota($quota);
		}
		$out .= $this->renderBacklinksToolbar($view, $target, $scope, $limit);

		if (!$moz->isConfigured()) {
			$out .= $this->renderBacklinksEmptyState();
		} elseif ($error) {
			$out .= "<div class='uk-alert uk-alert-warning'>" . $san->entities($error) . "</div>\n";
		} elseif (!$snapshot) {
			$out .= "<div class='ichiban-backlinks-note'><strong>" . __('No saved snapshot yet') . "</strong><span>" . __('Click Refresh from Moz to fetch and save the first snapshot. Refreshing consumes Moz API requests and rows, so keep the default limit at 5 on the free plan.') . "</span></div>\n";
		} else {
			$out .= "<div class='ichiban-backlinks-summary'>"
				. "<div><strong>" . count($rows) . "</strong><span>" . __('saved rows') . "</span></div>"
				. "<div><strong>" . $san->entities($target) . "</strong><span>" . __('target') . "</span></div>"
				. "<div><strong>" . $this->formatBacklinksDate((string)$snapshot['fetched_at']) . "</strong><span>" . __('last refresh') . "</span></div>"
				. "</div>\n";
			$out .= $this->renderBacklinksHistory($history);
			$out .= $this->renderBacklinksTable($view, $rows);
		}

		$out .= "</div>\n";
		return $out;
	}

	protected function renderBacklinksToolbar(string $view, string $target, string $scope, int $limit): string {
		$san = $this->wire('sanitizer');
		$out = "<form method='post' class='ichiban-backlinks-toolbar' action='" . $this->adminUrl('backlinks/') . "'>";
		$out .= $this->wire('session')->CSRF->renderInput();
		$out .= "<div class='ichiban-backlinks-tabs'>";
		foreach (['links' => __('Links'), 'domains' => __('Domains'), 'anchors' => __('Anchors')] as $key => $label) {
			$checked = $key === $view ? ' checked' : '';
			$out .= "<label><input type='radio' name='backlinks_view' value='{$key}'{$checked}> <span>{$label}</span></label>";
		}
		$out .= "</div>";
		$out .= "<input class='uk-input' type='text' name='target' value='" . $san->entities($target) . "' placeholder='" . __('example.com') . "'>";
		$out .= "<select class='uk-select' name='scope'>";
		foreach (['root_domain' => __('Root domain'), 'subdomain' => __('Subdomain'), 'page' => __('Exact page')] as $key => $label) {
			$selected = $key === $scope ? ' selected' : '';
			$out .= "<option value='{$key}'{$selected}>{$label}</option>";
		}
		$out .= "</select>";
		$out .= "<input class='uk-input' type='number' min='1' max='1000' step='1' name='limit' value='" . (int)$limit . "'>";
		$out .= "<button class='uk-button uk-button-primary' type='submit' name='refresh_backlinks' value='1'>" . __('Refresh from Moz') . "</button>";
		return $out . "</form>\n";
	}

	protected function renderBacklinksConnectionStatus(\IchibanBacklinksMoz $moz): string {
		$isConfigured = $moz->isConfigured();
		return $this->renderIntegrationStatus($isConfigured, __('Connected'), __('Not connected'));
	}

	protected function renderIntegrationStatus(bool $connected, string $connectedLabel, string $disconnectedLabel): string {
		$class = $connected ? 'is-connected' : 'is-disconnected';
		$label = $connected ? $connectedLabel : $disconnectedLabel;
		return "<div class='ichiban-integration-status {$class}'><span aria-hidden='true'></span><strong>{$label}</strong></div>\n";
	}

	protected function renderBacklinksSetupGuide(): string {
		$settingsUrl = $this->adminUrl('settings/') . '#wrap_Inputfield_moz_target';
		$mozSignupUrl = $this->getMozSignupUrl();
		return "<section class='ichiban-backlinks-guide'>"
			. "<h3>" . __('How to connect Moz') . "</h3>"
			. "<ol>"
			. "<li>" . __('Create or open a Moz API account.') . " <a href='{$mozSignupUrl}' target='_blank' rel='noopener'>" . __('View Moz API pricing') . "</a></li>"
			. "<li>" . __('During signup, Moz asks for a debit or credit card even on the free plan. This is part of their security and abuse-prevention flow.') . "</li>"
			. "<li>" . __('Create an API token in the Moz API dashboard.') . "</li>"
			. "<li>" . sprintf(__('Paste the token into the Moz API token field in %s, then save.'), "<a href='{$settingsUrl}'>" . __('Ichiban settings') . "</a>") . "</li>"
			. "<li>" . __('Fallback: if token auth does not work for your account, open Show Legacy Credentials for the token and paste the Access ID and Secret Key instead.') . "</li>"
			. "<li>" . __('Return here, keep the default 5-row limit, and click Refresh from Moz. The free plan quota is tiny, so avoid repeated refreshes.') . "</li>"
			. "</ol>"
			. "<p>" . __('Ichiban sends the new token as the x-moz-token header. Every refresh is saved as a backlink snapshot and reused from cache on page reload. Default API base URL: https://lsapi.seomoz.com/v2. Change it only if Moz gives you a different endpoint.') . "</p>"
			. "</section>\n";
	}

	protected function getMozSignupUrl(): string {
		return self::MOZ_AFFILIATE_URL !== '' ? self::MOZ_AFFILIATE_URL : self::MOZ_SIGNUP_URL;
	}

	protected function renderBacklinksQuota(?array $quota): string {
		$out = "<section class='ichiban-backlinks-quota'>";
		$out .= "<div>";
		$out .= "<h3>" . __('Moz quota') . "</h3>";
		if ($quota) {
			$used = (int)($quota['used'] ?? 0);
			$allotted = (int)($quota['allotted'] ?? 0);
			$percent = $allotted > 0 ? min(100, round(($used / $allotted) * 100)) : 0;
			$reset = (int)($quota['period_reset'] ?? 0);
			$fetched = (string)($quota['fetched_at'] ?? '');
			$out .= "<div class='ichiban-backlinks-quota-grid'>";
			$out .= "<div class='ichiban-backlinks-quota-usage'>"
				. "<div class='ichiban-backlinks-quota-bar'><span style='width:{$percent}%'></span></div>"
				. "<strong>" . sprintf(__('%1$d / %2$d rows used (%3$d%%)'), $used, $allotted, $percent) . "</strong>"
				. "</div>";
			$out .= "<div class='ichiban-backlinks-quota-meta'>";
			if ($reset > 0) {
				$out .= "<span><small>" . __('Quota resets') . "</small><strong>" . $this->wire('datetime')->date('Y-m-d H:i', $reset) . "</strong></span>";
			}
			if ($fetched !== '') {
				$out .= "<span><small>" . __('Last checked') . "</small><strong>" . $this->formatBacklinksDate($fetched) . "</strong></span>";
			}
			$out .= "</div></div>";
		} else {
			$out .= "<strong>" . __('No quota snapshot saved yet') . "</strong>";
			$out .= "<span>" . __('Click Refresh quota to save current Moz usage inside Ichiban.') . "</span>";
		}
		$out .= "</div>";
		$out .= "<form method='post' action='" . $this->adminUrl('backlinks/') . "'>";
		$out .= $this->wire('session')->CSRF->renderInput();
		$out .= "<button class='uk-button uk-button-default' type='submit' name='refresh_quota' value='1'>" . __('Refresh quota') . "</button>";
		$out .= "</form>";
		return $out . "</section>\n";
	}

	protected function renderBacklinksEmptyState(): string {
		$settingsUrl = $this->adminUrl('settings/') . '#wrap_Inputfield_moz_target';
		return "<div class='ichiban-backlinks-empty'>"
			. "<h3>" . __('Backlinks are not working yet') . "</h3>"
			. "<p>" . __('Moz credentials are missing. Add your API token, or legacy Access ID and Secret Key, then return to this page to fetch backlink data.') . "</p>"
			. "<a class='uk-button uk-button-primary' href='{$settingsUrl}'>" . __('Open Settings') . "</a>"
			. "</div>\n";
	}

	protected function renderBacklinksTable(string $view, array $rows): string {
		if (!$rows) {
			return "<div class='ichiban-backlinks-empty'><h3>" . __('No rows returned') . "</h3><p>" . __('Moz returned an empty result for this target, scope, and limit.') . "</p></div>\n";
		}

		$columns = [
			'links' => [
				'source' => [__('Source'), ['source_url', 'source_page', 'source.page', 'source']],
				'target' => [__('Target'), ['target_url', 'target_page', 'target.page', 'target']],
				'anchor' => [__('Anchor'), ['anchor_text', 'anchor']],
				'status' => [__('HTTP'), ['source.http_code', 'http_code']],
				'da' => [__('DA'), ['source.domain_authority', 'source_domain_authority', 'domain_authority', 'domain_authority_score']],
				'spam' => [__('Spam'), ['source.spam_score', 'source_spam_score', 'spam_score']],
			],
			'domains' => [
				'domain' => [__('Domain'), ['root_domain', 'source_root_domain', 'source.root_domain', 'domain']],
				'links' => [__('Links'), ['links', 'external_pages', 'pages_to_target']],
				'da' => [__('DA'), ['domain_authority', 'source.domain_authority', 'source_domain_authority']],
				'spam' => [__('Spam'), ['spam_score', 'source.spam_score', 'source_spam_score']],
			],
			'anchors' => [
				'anchor' => [__('Anchor'), ['anchor_text', 'text', 'anchor']],
				'domains' => [__('Domains'), ['external_root_domains', 'root_domains', 'domains']],
				'pages' => [__('Pages'), ['external_pages', 'pages']],
				'links' => [__('Links'), ['links', 'count']],
			],
		][$view] ?? [];

		$out = "<div class='ichiban-backlinks-table-panel uk-overflow-auto'><table class='AdminDataTable AdminDataTable--noSorting uk-table uk-table-divider uk-table-hover'>\n<thead><tr>";
		foreach ($columns as [$label]) {
			$out .= "<th>{$label}</th>";
		}
		$out .= "</tr></thead><tbody>\n";
		foreach ($rows as $row) {
			if (!is_array($row)) continue;
			$out .= "<tr>";
			foreach ($columns as [$label, $keys]) {
				$value = $this->backlinksValue($row, $keys);
				$out .= "<td>" . $this->formatBacklinksCell($value) . "</td>";
			}
			$out .= "</tr>\n";
		}
		return $out . "</tbody></table></div>\n";
	}

	protected function renderBacklinksHistory(array $history): string {
		if (!$history) return '';
		$out = "<div class='ichiban-backlinks-history'><strong>" . __('Saved history') . "</strong><span>";
		$items = [];
		foreach ($history as $snapshot) {
			$items[] = $this->formatBacklinksDate((string)$snapshot['fetched_at']) . ' · ' . (int)$snapshot['row_count'] . ' rows';
		}
		return $out . $this->wire('sanitizer')->entities(implode(' | ', $items)) . "</span></div>\n";
	}

	protected function formatBacklinksDate(string $date): string {
		$ts = strtotime($date);
		if (!$ts) return $this->wire('sanitizer')->entities($date);
		return $this->wire('datetime')->date('Y-m-d H:i', $ts);
	}

	protected function backlinksValue(array $row, array $keys): string {
		foreach ($keys as $key) {
			$value = $this->backlinksNestedValue($row, $key);
			if ($value !== null && $value !== '') {
				if (is_scalar($value)) return (string)$value;
				$encoded = json_encode($value, JSON_UNESCAPED_SLASHES);
				return $encoded !== false ? $encoded : '';
			}
		}
		return '';
	}

	protected function backlinksNestedValue(array $row, string $key) {
		if (array_key_exists($key, $row)) return $row[$key];
		if (strpos($key, '.') === false) return null;
		$value = $row;
		foreach (explode('.', $key) as $part) {
			if (!is_array($value) || !array_key_exists($part, $value)) return null;
			$value = $value[$part];
		}
		return $value;
	}

	protected function formatBacklinksCell(string $value): string {
		$value = trim($value);
		if ($value === '') return '<span class="uk-text-muted">-</span>';
		$escaped = $this->wire('sanitizer')->entities($value);
		if (preg_match('~^https?://~i', $value)) {
			return "<a href='{$escaped}' target='_blank' rel='noopener'>{$escaped}</a>";
		}
		return $escaped;
	}

	protected function renderGscToolbar(int $currentDays, string $currentView): string {
		$out = "<div class='ichiban-gsc-toolbar'><div class='ichiban-gsc-section-nav'>";
		foreach (['overview' => __('Overview'), 'pages' => __('Top Pages'), 'queries' => __('Top Queries')] as $view => $label) {
			$class = $view === $currentView ? ' class="active"' : '';
			$out .= "<a{$class} href='" . $this->gscUrl($view, $currentDays) . "'>{$label}</a>";
		}
		$out .= "</div><div class='ichiban-gsc-range-wrap'><span>" . __('Date range') . "</span><div class='ichiban-gsc-range'>";
		foreach ([7, 28, 90, 180, 365] as $days) {
			$class = $days === $currentDays ? ' class="active"' : '';
			$url = $this->gscUrl($currentView, $days);
			$out .= "<a{$class} href='{$url}'>" . sprintf(__('%d days'), $days) . "</a>";
		}
		return $out . "</div></div></div>\n";
	}

	protected function gscUrl(string $view, int $days): string {
		return $this->adminUrl('search-statistics/') . '?gsc_view=' . rawurlencode($view) . '&days=' . (int)$days;
	}

	protected function renderGscEmptyState(): string {
		$san = $this->wire('sanitizer');
		$redirectUri = rtrim($this->wire('config')->urls->httpAdmin, '/') . '/ichiban/search-statistics/';
		$propertyUrl = trim((string)($this->ichiban->get('gsc_site_url') ?: ''));
		if ($propertyUrl === '') {
			$propertyUrl = rtrim($this->wire('config')->urls->httpRoot, '/') . '/';
		}
		$apiProperty = $propertyUrl;
		if ($apiProperty !== '' && stripos($apiProperty, 'sc-domain:') !== 0 && !preg_match('~^https?://~i', $apiProperty)) {
			$apiProperty = 'sc-domain:' . trim($apiProperty, '/');
		}
		$hasClientId = (bool)$this->ichiban->get('gsc_client_id');
		$hasClientSecret = (bool)$this->ichiban->get('gsc_client_secret');
		$hasCredentials = $hasClientId && $hasClientSecret;
		$settingsUrl = $this->adminUrl('settings/');
		$connectUrl = $this->adminUrl('search-statistics/') . '?connect=1';

		$statusClient = $hasClientId ? __('Client ID saved') : __('Client ID missing');
		$statusSecret = $hasClientSecret ? __('Client Secret saved') : __('Client Secret missing');
		$statusClassClient = $hasClientId ? 'ichiban-gsc-status-ok' : 'ichiban-gsc-status-missing';
		$statusClassSecret = $hasClientSecret ? 'ichiban-gsc-status-ok' : 'ichiban-gsc-status-missing';

		$out  = "<div class='ichiban-gsc-empty'>\n";
		$out .= "<section class='ichiban-gsc-hero'>"
			. "<div class='ichiban-gsc-hero-copy'>"
			. "<span class='ichiban-eyebrow'>" . __('Google Search Console') . "</span>"
			. "<h2>" . __('Connect search performance data') . "</h2>"
			. "<p>" . __('After connecting, Ichiban will show Search Console metrics for 7, 28, 90, 180, and 365 days, including daily trends, top pages, top queries, countries, devices, search appearance, and cached Page Indexing scans.') . "</p>"
			. "<div class='ichiban-gsc-actions'>"
			. "<a class='uk-button uk-button-primary' href='{$settingsUrl}'>" . __('Open Settings') . "</a>"
			. ($hasCredentials ? "<a class='uk-button uk-button-secondary' href='{$connectUrl}'>" . __('Connect GSC') . "</a>" : "<span class='ichiban-gsc-disabled'>" . __('Add credentials before connecting') . "</span>")
			. "</div>"
			. "<div class='ichiban-gsc-status-row'>"
			. "<span class='{$statusClassClient}'>{$statusClient}</span>"
			. "<span class='{$statusClassSecret}'>{$statusSecret}</span>"
			. "</div>"
			. "</div>"
			. "<div class='ichiban-gsc-config-card'>"
			. "<h3>" . __('Use these values') . "</h3>"
			. "<label>" . __('Authorized redirect URI') . "</label>"
			. "<code>" . $san->entities($redirectUri) . "</code>"
			. "<label>" . __('Search Console property') . "</label>"
			. "<code>" . $san->entities($propertyUrl) . "</code>"
			. "<p>" . __('Enter the exact Search Console property you use. For Domain properties, use only the bare domain, for example lqrs.uk. Ichiban automatically sends it to Google as a sc-domain property. Use https://example.com/ only for URL-prefix properties.') . "</p>"
			. "</div>"
			. "</section>\n";

		$out .= "<section class='ichiban-gsc-setup'>"
			. "<div class='ichiban-gsc-steps'>"
			. "<h3>" . __('How to connect') . "</h3>"
			. "<ol>"
			. "<li><strong>" . __('Create or choose a Google Cloud project.') . "</strong><span>" . __('Enable the Google Search Console API for that project.') . "</span></li>"
			. "<li><strong>" . __('Create OAuth credentials.') . "</strong><span>" . __('Choose Web application, then add the authorized redirect URI shown above.') . "</span></li>"
			. "<li><strong>" . __('Publish the OAuth app.') . "</strong><span>" . __('In Google Auth Platform > Audience, set Publishing status to In production. Testing mode can block sign-in with 403 access_denied for non-test users.') . "</span></li>"
			. "<li><strong>" . __('Save credentials in Ichiban.') . "</strong><span>" . __('Paste the OAuth Client ID, Client Secret, and Search Console property in Settings. For Domain properties, enter the bare domain, for example lqrs.uk.') . "</span></li>"
			. "<li><strong>" . __('Connect your Google account.') . "</strong><span>" . __('Return here, click Connect GSC, and approve read-only Search Console access.') . "</span></li>"
			. "<li><strong>" . __('Run optional indexing scan.') . "</strong><span>" . __('After connection, click Scan indexing issues to cache URL Inspection results and surface indexing problems in Ichiban.') . "</span></li>"
			. "</ol>"
			. "<div class='ichiban-gsc-links'>"
			. "<a href='https://console.cloud.google.com/apis/library/searchconsole.googleapis.com' target='_blank' rel='noopener'>" . __('Enable Search Console API') . "</a>"
			. "<a href='https://support.google.com/googleapi/answer/6158849' target='_blank' rel='noopener'>" . __('OAuth client setup') . "</a>"
			. "<a href='https://search.google.com/search-console' target='_blank' rel='noopener'>" . __('Open Search Console') . "</a>"
			. "</div>"
			. "</div>"
			. "<div class='ichiban-gsc-preview'>"
			. "<h3>" . __('What appears after connection') . "</h3>"
			. "<div class='ichiban-gsc-preview-metrics'>"
			. "<div><strong>7-365</strong><span>" . __('Date ranges') . "</span></div>"
			. "<div><strong>Chart</strong><span>" . __('Daily trend') . "</span></div>"
			. "<div><strong>100</strong><span>" . __('Rows per detail') . "</span></div>"
			. "<div><strong>Scan</strong><span>" . __('Page Indexing') . "</span></div>"
			. "</div>"
			. "<table class='uk-table uk-table-divider ichiban-gsc-preview-table'><thead><tr><th>" . __('Section') . "</th><th>" . __('Data') . "</th><th>" . __('Cache') . "</th></tr></thead><tbody>"
			. "<tr><td>" . __('Overview') . "</td><td>" . __('Metrics, trend, top pages, top queries') . "</td><td>6h</td></tr>"
			. "<tr><td>" . __('Breakdowns') . "</td><td>" . __('Countries, devices, search appearance') . "</td><td>6h</td></tr>"
			. "<tr><td>" . __('Page Indexing') . "</td><td>" . __('URL Inspection issues grouped by reason') . "</td><td>" . __('manual scan') . "</td></tr>"
			. "</tbody></table>"
			. "</div>"
			. "</section>\n";
		return $out . "</div>\n";
	}

	public function executeRevisions(): string {
		$this->setIchibanBreadcrumb(__('Revisions'), 'revisions/');
		$this->headline(__('SEO Revisions'));
		$revs = $this->ichiban->getSeoRevisions()->getAllRevisions(100);
		$pageIds = [];
		$totalChanges = 0;
		foreach ($revs as $rev) {
			$pageIds[(int)$rev['page_id']] = true;
			$totalChanges += count(json_decode($rev['changes'], true) ?: []);
		}
		$out  = $this->renderAdminNav('revisions');
		$out .= "<div class='ichiban-revisions'>\n";
		$out .= "<div class='ichiban-revisions-header'><div><p>" . __('Review tracked SEO field changes and restore previous values when a page edit needs to be rolled back.') . "</p></div>"
			. "<div class='ichiban-revisions-stats'><div><strong>" . count($revs) . "</strong><span>" . __('recent revisions') . "</span></div><div><strong>" . count($pageIds) . "</strong><span>" . __('pages touched') . "</span></div><div><strong>{$totalChanges}</strong><span>" . __('field changes') . "</span></div></div></div>\n";
		$out .= "<div class='ichiban-revisions-help'><strong>" . __('What is tracked?') . "</strong><span>" . __('Meta title, description, canonical, robots directives, Open Graph, Twitter card, schema, sitemap settings, and JSON-LD overrides.') . "</span><a href='" . $this->adminUrl('settings/') . "'>" . __('Retention is configured in Settings') . "</a></div>\n";
		if (!$revs) {
			$out .= "<div class='ichiban-empty-state'><h3>" . __('No SEO revisions yet') . "</h3><p>" . __('Revisions appear after saving a page with changed Ichiban SEO fields. This page will then show who changed what, when it happened, and which values can be restored.') . "</p><a class='uk-button uk-button-default' href='" . $this->adminUrl('bulk/') . "'>" . __('Open Bulk Editor') . "</a></div>\n</div>\n";
			return $out;
		}
		$out .= "<div class='ichiban-revisions-table-panel'><div class='uk-overflow-auto'><table class='AdminDataTable uk-table uk-table-divider uk-table-hover ichiban-revisions-table'><thead><tr>"
			. "<th>" . __('Date') . "</th><th>" . __('Page') . "</th><th>" . __('User') . "</th><th>" . __('Changes') . "</th>"
			. "</tr></thead><tbody>\n";
		foreach ($revs as $rev) {
			$changes = json_decode($rev['changes'], true) ?: [];
			$summary = "<div class='ichiban-change-list'>";
			foreach (array_slice($changes, 0, 4) as $change) {
				$field = $this->wire('sanitizer')->entities($change['field'] ?? '');
				$old = $this->wire('sanitizer')->entities($this->shortValue($change['old_value'] ?? ''));
				$new = $this->wire('sanitizer')->entities($this->shortValue($change['new_value'] ?? ''));
				$summary .= "<div><strong>{$field}</strong><span><code>{$old}</code> → <code>{$new}</code></span></div>";
			}
			if (count($changes) > 4) {
				$summary .= "<em>" . sprintf(__('%d more changes'), count($changes) - 4) . "</em>";
			}
			$summary .= "</div>";
			$page    = $this->wire('pages')->get((int)$rev['page_id']);
			$user    = $this->wire('users')->get((int)$rev['user_id']);
			$out .= "<tr>"
				. "<td>" . $this->wire('sanitizer')->entities($rev['created_at']) . "</td>"
				. "<td>" . ($page->id ? "<a href='{$page->editUrl}'>" . $this->wire('sanitizer')->entities($page->title) . "</a>" : "#{$rev['page_id']}") . "</td>"
				. "<td>" . ($user && $user->id ? $this->wire('sanitizer')->entities($user->name) : '—') . "</td>"
				. "<td>{$summary}</td>"
				. "</tr>\n";
		}
		$out .= "</tbody></table></div></div>\n</div>\n";
		return $out;
	}

	public function executeReports(): string {
		$this->setIchibanBreadcrumb(__('Reports'), 'reports/');
		$this->headline(__('SEO Reports'));

		$san = $this->wire('sanitizer');
		$reports = $this->ichiban->getEmailReports();
		if ($this->wire('input')->get('download_docx')) {
			$this->downloadReportDocx();
		}
		if ($this->wire('input')->post('_ichiban_reports')) {
			$this->wire('session')->CSRF->validate();
			$this->saveReportsSettings();
			$this->wire('session')->message(__('Report settings saved.'));
			$this->wire('session')->redirect($this->adminUrl('reports/'));
		}
		if ($this->wire('input')->post('ichiban_generate_report')) {
			$this->wire('session')->CSRF->validate();
			$report = $reports->generateReport();
			$reports->saveLastReport($report);
			$this->wire('session')->message(__('Report JSON generated.'));
			$this->wire('session')->redirect($this->adminUrl('reports/'));
		}
		if ($this->wire('input')->post('ichiban_send_test_report')) {
			$this->wire('session')->CSRF->validate();
			$to = $san->email((string)$this->wire('input')->post('test_email_to'));
			if ($to === '') {
				$this->wire('session')->warning(__('Enter a valid recipient email address for the test.'));
			} elseif ($reports->sendTest($to)) {
				$this->wire('session')->message(sprintf(__('Test report sent to %s.'), $to));
			} else {
				$this->wire('session')->warning(__('Test report could not be sent. Check the selected WireMail provider and sender settings.'));
			}
			$this->wire('session')->redirect($this->adminUrl('reports/'));
		}

		$enabled = (bool)$this->ichiban->get('email_reports_enabled');
		$frequency = (string)($this->ichiban->get('email_reports_frequency') ?: 'weekly');
		$recipients = trim((string)($this->ichiban->get('email_reports_recipients') ?: ''));
		$frequencyLabel = $frequency === 'monthly' ? __('Monthly') : __('Weekly');
		$statusLabel = __('In development');
		$statusClass = 'is-dev';
		$lastJson = $reports->lastReportJson();
		$lastReport = $reports->getLastReport();
		$lastGenerated = (int)$this->ichiban->get('email_reports_last_generated');
		$lastGeneratedText = $lastGenerated ? $this->wire('datetime')->date('Y-m-d H:i', $lastGenerated) : __('Never');
		$downloadUrl = $this->adminUrl('reports/') . '?download_docx=1';

		$out  = $this->renderAdminNav('reports');
		$out .= "<div class='ichiban-reports'>\n";
		$out .= "<section class='ichiban-reports-hero'>"
			. "<div>"
			. "<span class='ichiban-eyebrow'>" . __('Email reports') . "</span>"
			. "<h2>" . __('Reports are in development') . "</h2>"
			. "<p>" . __('LazyCron will generate the report as JSON, store the latest snapshot here, and send the same report by email. DOCX export is available for printing or sharing with a client.') . "</p>"
			. "</div>"
			. "<div class='ichiban-reports-status {$statusClass}'>"
			. "<strong>{$statusLabel}</strong>"
			. "<span>" . sprintf(__('Current schedule: %s'), $frequencyLabel) . "</span>"
			. "<span>" . ($enabled ? __('Email delivery is enabled in settings.') : __('Email delivery is disabled in settings.')) . "</span>"
			. "<span>" . sprintf(__('Last generated: %s'), $lastGeneratedText) . "</span>"
			. "<small>" . ($recipients !== '' ? sprintf(__('Recipients: %s'), $san->entities($recipients)) : __('Recipients are not configured yet.')) . "</small>"
			. "</div>"
			. "</section>\n";

		$out .= "<section class='ichiban-reports-grid'>\n";
		foreach ([
			[__('Weekly report'), __('A short operational digest: current SEO score, new critical issues, pages that need attention, Search Console movement, and indexing warnings from the latest cached scan.')],
			[__('Monthly report'), __('A broader trend summary: score change, issue totals, top winning and losing pages, query visibility, cleanup activity, redirect hits, and recommendations for the next month.')],
		] as [$title, $copy]) {
			$out .= "<article><h3>{$title}</h3><p>{$copy}</p></article>\n";
		}
		$out .= "</section>\n";

		$out .= "<section class='ichiban-reports-plan'>"
			. "<h3>" . __('Planned report data') . "</h3>"
			. "<div>"
			. "<span>" . __('SEO health score and critical audit issues') . "</span>"
			. "<span>" . __('Missing titles, descriptions, OG images, and noindex pages') . "</span>"
			. "<span>" . __('Search Console clicks, impressions, CTR, and average position') . "</span>"
			. "<span>" . __('Top pages, top queries, countries, devices, and search appearance') . "</span>"
			. "<span>" . __('Page Indexing sample scan issues') . "</span>"
			. "<span>" . __('Recent redirects, cleanup blocks, and SEO revisions') . "</span>"
			. "</div>"
			. "</section>\n";

		$out .= "<section class='ichiban-reports-output'>"
			. "<div class='ichiban-reports-output-head'><div><h3>" . __('Latest JSON report') . "</h3><p>" . __('This is the stored report snapshot generated by LazyCron or the manual Generate button.') . "</p></div>"
			. "<div class='ichiban-reports-actions'>"
			. "<form method='post' class='uk-margin-remove'>" . $this->wire('session')->CSRF->renderInput() . "<button class='uk-button uk-button-primary' type='submit' name='ichiban_generate_report' value='1'>" . __('Generate report JSON') . "</button></form>"
			. ($lastJson !== '' ? "<a class='uk-button uk-button-default' href='" . $san->entities($downloadUrl) . "'>" . __('Download DOCX') . "</a>" : '')
			. "</div></div>";
		if ($lastJson !== '') {
			$out .= "<pre class='ichiban-reports-json'>" . $san->entities($lastJson) . "</pre>";
		} else {
			$out .= "<div class='uk-alert uk-alert-primary'>" . __('No report JSON has been generated yet.') . "</div>";
		}
		if ($lastReport) {
			$out .= "<div class='ichiban-reports-summary'><div><strong>" . (int)($lastReport['audit']['score'] ?? 0) . "/100</strong><span>" . __('SEO score') . "</span></div>"
				. "<div><strong>" . count($lastReport['audit']['priority_issues'] ?? []) . "</strong><span>" . __('priority issues') . "</span></div>"
				. "<div><strong>" . (int)($lastReport['activity']['redirects'] ?? 0) . "</strong><span>" . __('redirects') . "</span></div></div>";
		}
		$out .= "</section>\n";
		$out .= $this->renderReportsSettingsFieldset();

		return $out . "</div>\n";
	}

	protected function renderReportsSettingsFieldset(): string {
		$san = $this->wire('sanitizer');
		$mailOptions = $this->wireMailModuleOptions();
		$mailModule = (string)$this->ichiban->get('email_reports_mail_module');
		if (!isset($mailOptions[$mailModule])) $mailModule = '';
		$frequency = (string)($this->ichiban->get('email_reports_frequency') ?: 'weekly');
		$enabled = !empty($this->ichiban->get('email_reports_enabled')) ? ' checked' : '';
		$includeGsc = !empty($this->ichiban->get('email_reports_include_gsc')) ? ' checked' : '';
		$recipients = $san->entities((string)$this->ichiban->get('email_reports_recipients'));
		$fromEmail = $san->entities((string)$this->ichiban->get('email_reports_from_email'));
		$fromName = $san->entities((string)$this->ichiban->get('email_reports_from_name'));

		$mailRadios = '';
		foreach ($mailOptions as $value => $label) {
			$checked = $value === $mailModule ? ' checked' : '';
			$mailRadios .= "<label><input class='uk-radio' type='radio' name='email_reports_mail_module' value='" . $san->entities($value) . "'{$checked}> " . $san->entities($label) . "</label>";
		}
		$weekly = $frequency === 'weekly' ? ' selected' : '';
		$monthly = $frequency === 'monthly' ? ' selected' : '';

		return "<fieldset class='ichiban-reports-settings'>"
			. "<legend>" . __('Report settings') . "</legend>"
			. "<form method='post' class='ichiban-reports-settings-form'>"
			. $this->wire('session')->CSRF->renderInput()
			. "<input type='hidden' name='_ichiban_reports' value='1'>"
			. "<div class='ichiban-reports-settings-grid'>"
			. "<label class='ichiban-checkbox-row'><input class='uk-checkbox' type='checkbox' name='email_reports_enabled' value='1'{$enabled}> " . __('Enable scheduled SEO email reports') . "</label>"
			. "<label><span>" . __('Frequency') . "</span><select class='uk-select' name='email_reports_frequency'><option value='weekly'{$weekly}>" . __('Weekly') . "</option><option value='monthly'{$monthly}>" . __('Monthly') . "</option></select></label>"
			. "<label><span>" . __('Recipients') . "</span><input class='uk-input' type='text' name='email_reports_recipients' value='{$recipients}' placeholder='client@example.com, team@example.com'><small>" . __('Comma-separated email addresses.') . "</small></label>"
			. "<label class='ichiban-checkbox-row'><input class='uk-checkbox' type='checkbox' name='email_reports_include_gsc' value='1'{$includeGsc}> " . __('Include Google Search Console summary') . "</label>"
			. "<div class='ichiban-reports-mailer'><span>" . __('Mailer') . "</span><div>{$mailRadios}</div><small>" . __('Same pattern as Subscribe: choose the WireMail provider module used for delivery.') . "</small></div>"
			. "<label><span>" . __('From email') . "</span><input class='uk-input' type='email' name='email_reports_from_email' value='{$fromEmail}' placeholder='" . $san->entities((string)$this->wire('config')->adminEmail) . "'></label>"
			. "<label><span>" . __('From name') . "</span><input class='uk-input' type='text' name='email_reports_from_name' value='{$fromName}' placeholder='" . $san->entities((string)$this->wire('config')->httpHost) . "'></label>"
			. "</div>"
			. "<p><button type='submit' class='uk-button uk-button-primary'>" . __('Save report settings') . "</button></p>"
			. "</form>"
			. "<form method='post' class='ichiban-reports-test-form'>"
			. $this->wire('session')->CSRF->renderInput()
			. "<h4>" . __('Send Test Mail') . "</h4>"
			. "<p class='uk-text-meta'>" . __('Save your settings first, then test. The email is sent through the currently selected WireMail provider.') . "</p>"
			. "<div><input class='uk-input' type='email' name='test_email_to' placeholder='" . __('Recipient email address') . "'><button class='uk-button uk-button-default' type='submit' name='ichiban_send_test_report' value='1'>" . __('Send test') . "</button></div>"
			. "</form>"
			. "</fieldset>\n";
	}

	protected function saveReportsSettings(): void {
		$input = $this->wire('input');
		$san = $this->wire('sanitizer');
		$save = $this->wire('modules')->getModuleConfigData('Ichiban');
		$save['email_reports_enabled'] = $input->post('email_reports_enabled') !== null ? 1 : 0;
		$save['email_reports_include_gsc'] = $input->post('email_reports_include_gsc') !== null ? 1 : 0;
		$frequency = (string)$input->post('email_reports_frequency');
		$save['email_reports_frequency'] = $frequency === 'monthly' ? 'monthly' : 'weekly';
		$save['email_reports_recipients'] = trim((string)$input->post('email_reports_recipients'));
		$fromEmail = $san->email((string)$input->post('email_reports_from_email'));
		$save['email_reports_from_email'] = $fromEmail;
		$save['email_reports_from_name'] = $san->text((string)$input->post('email_reports_from_name'));
		$mailModule = trim((string)$input->post('email_reports_mail_module'));
		$allowed = array_keys($this->wireMailModuleOptions());
		$save['email_reports_mail_module'] = in_array($mailModule, $allowed, true) ? $mailModule : '';
		$this->wire('modules')->saveModuleConfigData('Ichiban', $save);
	}

	protected function downloadReportDocx(): void {
		$reports = $this->ichiban->getEmailReports();
		$report = $reports->getLastReport();
		if (!$report) {
			$report = $reports->generateReport();
			$reports->saveLastReport($report);
		}
		$bytes = $reports->buildDocx($report);
		if ($bytes === '') throw new WireException(__('PHP ZipArchive extension is required to generate DOCX reports.'));
		$filename = 'ichiban-seo-report-' . date('Y-m-d') . '.docx';
		header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
		header('Content-Disposition: attachment; filename="' . $filename . '"');
		header('Content-Length: ' . strlen($bytes));
		echo $bytes;
		exit;
	}

	protected function wireMailModuleOptions(): array {
		$options = [
			'' => __('Default (site WireMail setting)'),
		];
		foreach ($this->wire('modules')->find('className^=WireMail') as $m) {
			$className = $m->className();
			if ($className === 'WireMail') continue;
			$options[$className] = $className;
		}
		return $options;
	}

	public function executeCleanup(): string {
		$this->setIchibanBreadcrumb(__('Cleanup'), 'cleanup/');
		$this->headline(__('Crawl & Search Cleanup'));
		$db = $this->wire('database');
		try {
			$rows = $db->query("SELECT * FROM ichiban_cleanup_log ORDER BY id DESC LIMIT 200")->fetchAll(\PDO::FETCH_ASSOC);
		} catch (\Throwable $e) {
			return $this->renderAdminNav('cleanup') . "<div class='uk-alert uk-alert-warning'>" . __('Cleanup log table is not available yet.') . "</div>";
		}
		$enabled = !empty($this->ichiban->get('search_cleanup_enabled'));
		$action = $this->ichiban->get('search_cleanup_action') ?: 'redirect';
		$patterns = trim((string)($this->ichiban->get('search_cleanup_patterns') ?: ''));
		$patternCount = $patterns === '' ? 0 : count(array_filter(array_map('trim', preg_split('/\R/', $patterns))));
		$lastBlocked = $rows[0]['created_at'] ?? '';
		$uniqueIps = count(array_unique(array_map(fn($row) => (string)($row['ip'] ?? ''), $rows)));
		$out = $this->renderAdminNav('cleanup') . "<div class='ichiban-cleanup'>\n";
		$out .= "<div class='ichiban-cleanup-header'><div><p>" . __('Monitor spam search queries and lightweight crawl-surface cleanup that keeps low-value URLs out of public search paths.') . "</p></div><a class='uk-button uk-button-default' href='" . $this->adminUrl('settings/') . "'>" . __('Open Cleanup Settings') . "</a></div>\n";
		$out .= "<div class='ichiban-cleanup-stats'>"
			. "<div><strong>" . ($enabled ? __('On') : __('Off')) . "</strong><span>" . __('query blocking') . "</span></div>"
			. "<div><strong>" . count($rows) . "</strong><span>" . __('recent blocks') . "</span></div>"
			. "<div><strong>{$uniqueIps}</strong><span>" . __('unique IPs') . "</span></div>"
			. "<div><strong>{$patternCount}</strong><span>" . __('custom patterns') . "</span></div>"
			. "</div>\n";
		$out .= "<div class='ichiban-cleanup-details'><div><strong>" . __('Current action') . "</strong><span>" . ($action === '400' ? __('Return HTTP 400') : __('Redirect to homepage')) . "</span></div><div><strong>" . __('Last blocked') . "</strong><span>" . ($lastBlocked ? $this->wire('sanitizer')->entities($lastBlocked) : __('No blocks recorded')) . "</span></div><div><strong>" . __('Logged data') . "</strong><span>" . __('Query, matched pattern, IP address, and timestamp.') . "</span></div></div>\n";
		if (!$rows) {
			$out .= "<div class='ichiban-empty-state'><h3>" . __('No blocked queries logged yet') . "</h3><p>" . __('When a search request matches a cleanup pattern, Ichiban records it here so you can see what was blocked and tune your patterns safely.') . "</p><a class='uk-button uk-button-default' href='" . $this->adminUrl('settings/') . "'>" . __('Configure patterns') . "</a></div>\n</div>";
			return $out;
		}
		$out .= "<div class='ichiban-cleanup-table-panel'><div class='uk-overflow-auto'><table class='uk-table uk-table-divider uk-table-hover ichiban-cleanup-table'><thead><tr>"
			. "<th>" . __('Date') . "</th><th>" . __('Query') . "</th><th>" . __('Pattern') . "</th><th>" . __('IP') . "</th></tr></thead><tbody>";
		foreach ($rows as $row) {
			$out .= "<tr><td>" . $this->wire('sanitizer')->entities($row['created_at']) . "</td>"
				. "<td><code>" . $this->wire('sanitizer')->entities($row['query']) . "</code></td>"
				. "<td><code>" . $this->wire('sanitizer')->entities($row['pattern']) . "</code></td>"
				. "<td>" . $this->wire('sanitizer')->entities($row['ip']) . "</td></tr>";
		}
		return $out . "</tbody></table></div></div>\n</div>";
	}

	public function executeSitemap(): string {
		$this->setIchibanBreadcrumb(__('Sitemap'), 'sitemap/');
		$this->headline(__('XML Sitemap'));
		$sitemap = $this->ichiban->getSitemap();
		$post = $this->wire('input')->post;
		$action = (string)$post->text('action');

		if ($action === 'generate') {
			$this->wire('session')->CSRF->validate();
			try {
				$result = $sitemap->generate(true);
				if (isset($result['error'])) {
					$this->wire('session')->warning($result['error']);
				} else {
					$this->wire('session')->message(sprintf(__('Generated %d sitemap file(s), %s URLs in %.2fs.'), (int)$result['files'], number_format((int)$result['urls']), (float)$result['time']));
				}
			} catch (\Throwable $e) {
				$this->wire('session')->error(__('Sitemap generation failed: ') . $e->getMessage());
			}
			$this->wire('session')->redirect($this->adminUrl('sitemap/'));
		}

		if ($action === 'delete_files') {
			$this->wire('session')->CSRF->validate();
			$status = $sitemap->getStatus();
			foreach ($status['files'] as $file) {
				$path = $status['dir'] . '/' . $file['name'];
				if (is_file($path)) @unlink($path);
			}
			$this->wire('session')->message(__('Sitemap files deleted.'));
			$this->wire('session')->redirect($this->adminUrl('sitemap/'));
		}

		if ($action === 'create_dir') {
			$this->wire('session')->CSRF->validate();
			try {
				$sitemap->ensureSitemapDir();
				$this->wire('session')->message(__('Sitemap directory created.'));
			} catch (\Throwable $e) {
				$this->wire('session')->error($e->getMessage());
			}
			$this->wire('session')->redirect($this->adminUrl('sitemap/'));
		}

		return $this->renderAdminNav('sitemap') . $this->renderSitemapDashboard($sitemap);
	}

	protected function renderSitemapDashboard(\IchibanSitemap $sitemap): string {
		$status = $sitemap->getStatus();
		$san = $this->wire('sanitizer');
		$csrf = $this->wire('session')->CSRF->renderInput();
		$sitemapUrlRaw = $this->ichiban->getSitemapUrl();
		$sitemapUrl = $san->entities($sitemapUrlRaw);
		$sitemapBaseUrl = rtrim(dirname($sitemapUrlRaw), '/');
		$settingsUrl = $this->adminUrl('settings/');
		$isLocked = !empty($status['is_locked']);
		$last = $status['last_generated'] ? date('Y-m-d H:i:s', (int)$status['last_generated']) : __('Never');
		$dirOk = $status['dir_exists'] && $status['dir_writable'];
		$lazyCronLabel = empty($status['auto_regenerate'])
			? __('Off')
			: (!empty($status['lazy_cron_installed']) ? __('On') : __('Missing'));
		$out = "<div class='ichiban-cleanup'>\n";
		$out .= "<div class='ichiban-cleanup-header'><div><p>" . __('Generate and inspect the XML sitemap files served by Ichiban.') . "</p></div>"
			. "<a class='uk-button uk-button-default' href='{$settingsUrl}#wrap_Inputfield_sitemap_enabled'>" . __('Open Sitemap Settings') . "</a></div>\n";
		$out .= "<div class='ichiban-cleanup-stats ichiban-sitemap-stats'>"
			. "<div><strong>" . (int)$status['file_count'] . "</strong><span>" . __('files') . "</span></div>"
			. "<div><strong>" . number_format((int)$status['total_urls']) . "</strong><span>" . __('URLs') . "</span></div>"
			. "<div><strong>" . $this->formatBytes((int)$status['total_size']) . "</strong><span>" . __('total size') . "</span></div>"
			. "<div><strong>" . ($dirOk ? __('OK') : __('Check')) . "</strong><span>" . __('directory') . "</span></div>"
			. "<div><strong>{$lazyCronLabel}</strong><span>" . __('LazyCron') . "</span></div>"
			. "</div>\n";
		$out .= "<div class='ichiban-cleanup-details'><div><strong>" . __('Public index') . "</strong><span><a href='{$sitemapUrl}' target='_blank' rel='noopener'>{$sitemapUrl}</a></span></div>"
			. "<div><strong>" . __('Last generated') . "</strong><span>{$last}</span></div>"
			. "<div><strong>" . __('Directory') . "</strong><span><code>" . $san->entities($status['dir']) . "</code></span></div>"
			. "<div><strong>" . __('Auto regeneration') . "</strong><span>" . $this->renderSitemapCronStatus($status) . "</span></div></div>\n";

		if (!empty($status['auto_regenerate']) && empty($status['lazy_cron_installed'])) {
			$out .= "<div class='uk-alert uk-alert-warning'>" . __('Sitemap auto-regeneration is enabled, but LazyCron is not installed. Install the core LazyCron module or use Generate Now manually.') . "</div>\n";
		}

		if (!$status['dir_exists']) {
			$out .= "<div class='uk-alert uk-alert-warning'><p>" . __('The sitemap directory does not exist yet.') . "</p>"
				. "<form method='post'>{$csrf}<input type='hidden' name='action' value='create_dir'><button class='uk-button uk-button-default'>" . __('Create Directory') . "</button></form></div>\n";
		} elseif (!$status['dir_writable']) {
			$out .= "<div class='uk-alert uk-alert-danger'>" . __('The sitemap directory is not writable.') . "</div>\n";
		}

		$out .= "<form method='post' class='uk-margin-small-right' style='display:inline-block'>{$csrf}<input type='hidden' name='action' value='generate'>"
			. "<button class='uk-button uk-button-primary' " . ($isLocked ? 'disabled' : '') . ">" . ($isLocked ? __('Generating...') : __('Generate Now')) . "</button></form>";
		if ($status['file_count'] > 0) {
			$out .= "<form method='post' style='display:inline-block' onsubmit=\"return confirm('Delete all sitemap files?')\">{$csrf}<input type='hidden' name='action' value='delete_files'>"
				. "<button class='uk-button uk-button-default'>" . __('Delete Files') . "</button></form>";
		}

		if (empty($status['files'])) {
			return $out . "<div class='ichiban-empty-state'><h3>" . __('No sitemap files yet') . "</h3><p>" . __('Generate the sitemap to create sitemap.xml and template-specific sitemap files.') . "</p></div>\n</div>";
		}

		$out .= "<div class='ichiban-cleanup-table-panel uk-margin-top'><div class='uk-overflow-auto'><table class='uk-table uk-table-divider uk-table-hover'><thead><tr>"
			. "<th>" . __('File') . "</th><th class='uk-text-right'>" . __('URLs') . "</th><th class='uk-text-right'>" . __('Size') . "</th><th>" . __('Modified') . "</th></tr></thead><tbody>";
		foreach ($status['files'] as $file) {
			$fileUrl = $sitemapBaseUrl . '/' . $file['name'];
			$fileUrlEsc = $san->entities($fileUrl);
			$out .= "<tr><td><a href='{$fileUrlEsc}' target='_blank' rel='noopener'><code>{$fileUrlEsc}</code></a> "
				. "<a class='uk-text-small' href='{$fileUrlEsc}' target='_blank' rel='noopener'>" . __('Open') . "</a></td>"
				. "<td class='uk-text-right'>" . (!empty($file['is_index']) ? __('index') : number_format((int)$file['urls'])) . "</td>"
				. "<td class='uk-text-right'>" . $this->formatBytes((int)$file['size']) . "</td>"
				. "<td>" . date('Y-m-d H:i:s', (int)$file['modified']) . "</td></tr>";
		}
		return $out . "</tbody></table></div></div>\n</div>";
	}

	protected function renderSitemapCronStatus(array $status): string {
		if (empty($status['auto_regenerate'])) return __('Disabled');
		if (empty($status['lazy_cron_installed'])) return __('Waiting for LazyCron module');
		return sprintf(
			__('%s, minimum interval %d seconds'),
			$this->wire('sanitizer')->entities((string)$status['cron_method']),
			(int)$status['regenerate_interval']
		);
	}

	protected function formatBytes(int $bytes): string {
		foreach (['B', 'KB', 'MB', 'GB'] as $unit) {
			if ($bytes < 1024 || $unit === 'GB') return round($bytes, $unit === 'B' ? 0 : 1) . ' ' . $unit;
			$bytes /= 1024;
		}
		return '0 B';
	}

	public function executeMigration(): string {
		$this->setIchibanBreadcrumb(__('Migration'), 'migration/');
		$this->headline(__('SEO Migration'));

		$notice = '';
		if ($this->wire('input')->post('ichiban_repair_duplicate_fields')) {
			$this->wire('session')->CSRF->validate();
			try {
				$result = $this->repairDuplicateIchibanFields();
				$notice = "<div class='uk-alert uk-alert-success'>"
					. sprintf(__('Repaired duplicate Ichiban fields. Kept %s, removed %s from %d template(s), copied %d row(s). Backups: %s.'), '<code>' . $this->wire('sanitizer')->entities($result['target']) . '</code>', '<code>' . $this->wire('sanitizer')->entities($result['source']) . '</code>', (int)$result['fieldgroups'], (int)$result['rows'], '<code>' . $this->wire('sanitizer')->entities(implode(', ', $result['backups'])) . '</code>')
					. "</div>";
			} catch (\Throwable $e) {
				$notice = "<div class='uk-alert uk-alert-danger'>" . $this->wire('sanitizer')->entities($e->getMessage()) . "</div>";
			}
		}
		if ($this->wire('input')->post('ichiban_migrate_field')) {
			$this->wire('session')->CSRF->validate();
			try {
				$result = $this->migrateSeoMaestroField($this->wire('sanitizer')->fieldName($this->wire('input')->post('field_name')));
				$notice = "<div class='uk-alert uk-alert-success'>"
					. sprintf(__('Converted field %s. Backup table: %s. Rows migrated: %d.'), '<code>' . $this->wire('sanitizer')->entities($result['field']) . '</code>', '<code>' . $this->wire('sanitizer')->entities($result['backup']) . '</code>', (int)$result['rows'])
					. "</div>";
			} catch (\Throwable $e) {
				$notice = "<div class='uk-alert uk-alert-danger'>" . $this->wire('sanitizer')->entities($e->getMessage()) . "</div>";
			}
		}

		$candidates = $this->seoMaestroMigrationCandidates();
		$ichibanInstalled = (bool)$this->wire('modules')->get('FieldtypeIchiban');

		$out = $this->renderAdminNav('migration') . "<div class='ichiban-migration'>\n";
		$out .= $notice;
		$out .= $this->renderDuplicateFieldRepair();
		$out .= "<div class='ichiban-migration-header'><div><p>" . __('Convert existing SeoMaestro fields to Ichiban without deleting the field or losing per-page SEO data.') . "</p></div>"
			. "<a class='uk-button uk-button-default' href='https://github.com/wanze/SeoMaestro' target='_blank' rel='noopener'>" . __('SeoMaestro reference') . "</a></div>\n";
		$out .= "<div class='ichiban-migration-flow'>"
			. "<div><strong>1</strong><span>" . __('Find SeoMaestro fields') . "</span><small>" . __('Ichiban scans installed ProcessWire fields whose current type is FieldtypeSeoMaestro.') . "</small></div>"
			. "<div><strong>2</strong><span>" . __('Create a table backup') . "</span><small>" . __('Before conversion, the complete field table is copied to a timestamped backup table.') . "</small></div>"
			. "<div><strong>3</strong><span>" . __('Convert data and switch type') . "</span><small>" . __('Each row is rewritten to Ichiban’s data shape, then the same field is changed to FieldtypeIchiban.') . "</small></div>"
			. "</div>\n";
		$out .= $this->renderMigrationDataMapping();

		if (!$ichibanInstalled) {
			$out .= "<div class='uk-alert uk-alert-warning'>" . __('FieldtypeIchiban is not installed yet. Install it from Modules before running a migration.') . "</div>\n";
		}

		if (!$candidates) {
			$out .= "<div class='ichiban-empty-state'><h3>" . __('No SeoMaestro fields found') . "</h3><p>" . __('When a field using FieldtypeSeoMaestro is installed on this site, it will appear here with row counts and a conversion action.') . "</p></div>\n</div>";
			return $out;
		}

		$out .= "<div class='ichiban-migration-table-panel'><table class='uk-table uk-table-divider uk-table-hover'><thead><tr>"
			. "<th>" . __('Field') . "</th><th>" . __('Table') . "</th><th>" . __('Rows') . "</th><th>" . __('Rows with data') . "</th><th>" . __('Templates') . "</th><th>" . __('Action') . "</th></tr></thead><tbody>\n";
		foreach ($candidates as $candidate) {
			$field = $this->wire('sanitizer')->entities($candidate['field']);
			$table = $this->wire('sanitizer')->entities($candidate['table']);
			$templates = $this->wire('sanitizer')->entities($candidate['templates'] ?: __('None'));
			$disabled = $ichibanInstalled ? '' : ' disabled';
			$out .= "<tr><td><strong>{$field}</strong></td><td><code>{$table}</code></td><td>" . (int)$candidate['rows'] . "</td><td>" . (int)$candidate['data_rows'] . "</td><td>{$templates}</td><td>"
				. "<form method='post' action='" . $this->adminUrl('migration/') . "' class='ichiban-inline-form'>"
				. $this->wire('session')->CSRF->renderInput()
				. "<input type='hidden' name='ichiban_migrate_field' value='1'>"
				. "<input type='hidden' name='field_name' value='{$field}'>"
				. "<button class='uk-button uk-button-secondary' type='submit'{$disabled} onclick=\"return confirm('" . $this->wire('sanitizer')->entities(__('Create a backup and convert this field to Ichiban?')) . "');\">" . __('Convert to Ichiban') . "</button>"
				. "</form></td></tr>\n";
		}
		$out .= "</tbody></table></div>\n";
		$out .= "<div class='ichiban-migration-note'><strong>" . __('Before you run it') . "</strong><span>" . __('If RockMigrations defines the same field, update its type to FieldtypeIchiban after conversion so the next migration cycle does not switch it back.') . "</span></div>\n";
		return $out . "</div>\n";
	}

	protected function renderMigrationDataMapping(): string {
		$rows = [
			[__('Meta title'), 'meta_title', 'meta_title', __('Field tokens like {title} become Ichiban field sources; custom text stays custom text.')],
			[__('Meta description'), 'meta_description', 'meta_description', __('Field tokens become sources, custom copy is preserved.')],
			[__('Canonical URL'), 'meta_canonicalUrl', 'canonical_url', __('Copied as the page canonical override when it is not inherited.')],
			[__('Open Graph title'), 'opengraph_title', 'og_title', __('Field tokens become sources for social title output.')],
			[__('Open Graph description'), 'opengraph_description', 'og_description', __('Field tokens become sources for social description output.')],
			[__('Open Graph image'), 'opengraph_image', 'og_image', __('Copied as the selected OG image source. Ichiban resolves image output at render time.')],
			[__('Open Graph image alt'), 'opengraph_imageAlt', 'og_image_alt', __('Copied as alternate text for the OG image.')],
			[__('Open Graph type'), 'opengraph_type', 'og_type', __('Copied when SeoMaestro stored an explicit type.')],
			[__('Twitter card'), 'twitter_card', 'twitter_card', __('Copied as the page Twitter/X card mode.')],
			[__('Twitter creator'), 'twitter_creator', 'twitter_creator', __('Copied as the page creator handle.')],
			[__('Robots noindex'), 'robots_noIndex', 'meta_noindex', __('Copied only when the value is not inherit.')],
			[__('Robots nofollow'), 'robots_noFollow', 'meta_nofollow', __('Copied only when the value is not inherit.')],
			[__('Sitemap include'), 'sitemap_include', 'sitemap_include', __('Copied only when the value is not inherit; otherwise Ichiban defaults to included.')],
			[__('Sitemap priority'), 'sitemap_priority', 'sitemap_priority', __('Copied as the page-level sitemap priority.')],
			[__('Sitemap change frequency'), 'sitemap_changeFrequency', 'sitemap_changefreq', __('Copied as the page-level change frequency.')],
		];
		$san = $this->wire('sanitizer');
		$out = "<section class='ichiban-migration-map'><div class='ichiban-migration-map-head'>"
			. "<div><h3>" . __('Data mapping') . "</h3><p>" . __('This shows how each SeoMaestro value is rewritten before the field type is switched. Values set to inherit are left out so Ichiban can use its normal cascade/defaults.') . "</p></div>"
			. "<span>" . __('Schema type defaults to WebPage') . "</span></div>";
		$out .= "<div class='uk-overflow-auto'><table class='uk-table uk-table-divider uk-table-small ichiban-migration-map-table'><thead><tr>"
			. "<th>" . __('What moves') . "</th><th>" . __('SeoMaestro key') . "</th><th>" . __('Ichiban key') . "</th><th>" . __('Result') . "</th></tr></thead><tbody>";
		foreach ($rows as [$label, $from, $to, $note]) {
			$out .= "<tr><td><strong>" . $san->entities($label) . "</strong></td>"
				. "<td><code>" . $san->entities($from) . "</code></td>"
				. "<td><code>" . $san->entities($to) . "</code></td>"
				. "<td>" . $san->entities($note) . "</td></tr>";
		}
		$out .= "</tbody></table></div>";
		$out .= "<div class='ichiban-migration-map-foot'>"
			. "<div><strong>" . __('Field tokens') . "</strong><span>" . __('A SeoMaestro value like {title} becomes an Ichiban field source named title. Plain text becomes a custom page override.') . "</span></div>"
			. "<div><strong>" . __('Backup') . "</strong><span>" . __('The original field table is copied first, so the pre-conversion JSON remains available in the backup table.') . "</span></div>"
			. "</div></section>\n";
		return $out;
	}

	protected function renderDuplicateFieldRepair(): string {
		$fields = $this->ichibanFields();
		if (count($fields) < 2) return '';
		$names = array_keys($fields);
		$target = isset($fields['seo']) ? 'seo' : $names[0];
		$source = isset($fields['ichiban']) && $target !== 'ichiban' ? 'ichiban' : ($names[1] ?? '');
		if ($source === '') return '';

		$san = $this->wire('sanitizer');
		$rows = '';
		foreach ($fields as $name => $field) {
			$templates = $this->templateNamesForField($field);
			$templateText = $templates ? implode(', ', $templates) : __('None');
			$rows .= '<tr><td><code>' . $san->entities($name) . '</code></td><td><code>' . $san->entities($field->getTable()) . '</code></td><td><strong>' . count($templates) . '</strong> ' . $san->entities($templateText) . '</td></tr>';
		}

		return "<div class='uk-alert uk-alert-warning'>"
			. "<h3>" . __('Duplicate Ichiban fields detected') . "</h3>"
			. "<p>" . sprintf(__('This site has more than one FieldtypeIchiban field. Ichiban will keep %s as the canonical SEO field and can remove %s from templates after backing up both tables.'), '<code>' . $san->entities($target) . '</code>', '<code>' . $san->entities($source) . '</code>') . "</p>"
			. "<table class='uk-table uk-table-small'><thead><tr><th>" . __('Field') . "</th><th>" . __('Table') . "</th><th>" . __('Templates') . "</th></tr></thead><tbody>{$rows}</tbody></table>"
			. "<form method='post' action='" . $this->adminUrl('migration/') . "'>"
			. $this->wire('session')->CSRF->renderInput()
			. "<input type='hidden' name='ichiban_repair_duplicate_fields' value='1'>"
			. "<button class='uk-button uk-button-secondary' type='submit' onclick=\"return confirm('" . $san->entities(__('Backup both tables, copy missing rows into seo, and remove the duplicate field from templates?')) . "');\">" . __('Repair duplicate fields') . "</button>"
			. "</form></div>\n";
	}

	public function executeSchemas(): string {
		$this->setIchibanBreadcrumb(__('Schemas'), 'schemas/');
		$this->headline(__('Schema Builder'));
		$san = $this->wire('sanitizer');
		$this->ensureSchemaTable();
		$this->migrateConfigSchemasToTable();
		if ($this->wire('input')->post('_ichiban_schemas')) {
			$this->wire('session')->CSRF->validate();
			$db = $this->wire('database');
			$payload = (string)$this->wire('input')->post('schemas_payload');
			$post = $payload !== '' ? json_decode($payload, true) : null;
			if (!is_array($post)) {
				$post = $this->wire('input')->post('schemas');
				$post = is_array($post) ? $post : [];
			}
			$db->beginTransaction();
			try {
				$db->exec("DELETE FROM `ichiban_schemas`");
				$stmt = $db->prepare("INSERT INTO `ichiban_schemas` (name, schema_type, templates, fields_json, enabled, sort) VALUES (:name, :type, :templates, :fields, :enabled, :sort)");
				$sort = 0;
				foreach ($post as $row) {
					$name = $san->text((string)($row['name'] ?? ''));
					$type = preg_replace('/[^A-Za-z0-9_]/', '', (string)($row['custom_type'] ?? '')) ?: preg_replace('/[^A-Za-z0-9_]/', '', (string)($row['type'] ?? '')) ?: 'Thing';
					$templates = trim((string)($row['templates'] ?? ''));
					$fields = $this->schemaFieldsFromPost($row['fields'] ?? []);
					$stmt->execute([
						':name' => $name ?: $type,
						':type' => $type,
						':templates' => $templates,
						':fields' => json_encode($fields, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
						':enabled' => !empty($row['enabled']) ? 1 : 0,
						':sort' => $sort++,
					]);
				}
				$db->commit();
				$this->wire('session')->message(__('Schema builder mappings saved.'));
			} catch (\Throwable $e) {
				$db->rollBack();
				$this->wire('session')->warning($e->getMessage());
			}
			$this->wire('session')->redirect($this->adminUrl('schemas/'));
		}

		$schemas = $this->getSchemaMappings();
		if (!$schemas) {
			$schemas[] = [
				'name' => 'Product',
				'type' => 'Product',
				'templates' => 'product',
				'enabled' => 1,
				'fields' => [
					'name' => 'field:title',
					'description' => 'field:summary|truncate:160',
					'image' => 'field:images',
				],
			];
		}
		$typeOptions = $this->schemaTypeOptions();
		$propertyOptions = $this->schemaPropertyOptions();

		$out = $this->renderAdminNav('schemas') . "<div class='ichiban-schemas'>"
			. "<div class='ichiban-schemas-header'><p>" . __('Create Schema.org nodes in a database-backed builder. Pick a type, choose templates, then map schema properties to ProcessWire fields or source expressions.') . "</p></div>"
			. "<form class='ichiban-schema-form' method='post' data-next-index='" . count($schemas) . "'>" . $this->wire('session')->CSRF->renderInput() . "<input type='hidden' name='_ichiban_schemas' value='1'><input class='ichiban-schema-payload' type='hidden' name='schemas_payload' value=''>"
			. "<div class='ichiban-schema-layout'>"
				. "<aside class='ichiban-schema-list'>"
					. "<div class='ichiban-schema-list-head'><strong>" . __('Schemas') . "</strong><button class='uk-button uk-button-default ichiban-schema-add' type='button'>" . __('New Schema') . "</button></div>"
					. "<div class='ichiban-schema-nav'>";
		foreach ($schemas as $i => $schema) {
			$out .= $this->renderSchemaNavItem($i, $schema, $i === 0);
		}
		$out .= "</div></aside><section class='ichiban-schema-editor-stack'>";
		foreach ($schemas as $i => $schema) {
			$out .= $this->renderSchemaEditorPanel($i, $schema, $typeOptions, $propertyOptions, $i === 0);
		}
		$blankSchema = ['name' => '', 'type' => 'Thing', 'templates' => '', 'fields' => [], 'enabled' => 1];
		$out .= "</section></div>"
			. "<div class='ichiban-schema-save'><button class='uk-button uk-button-primary' type='submit'>" . __('Save Schemas') . "</button></div>"
			. "<template id='ichiban-schema-editor-template'>" . $this->renderSchemaEditorPanel('__INDEX__', $blankSchema, $typeOptions, $propertyOptions, false) . "</template>"
			. "<template id='ichiban-schema-row-template'>" . $this->renderSchemaPropertyRow('__INDEX__', '__ROW__', '', '', $propertyOptions) . "</template>"
			. "</form></div>";
		return $out;
	}

	public function executeAi(): string {
		$this->setIchibanBreadcrumb(__('AI'), 'ai/');
		$this->headline(__('SEO AI'));
		$san = $this->wire('sanitizer');
		$ai = $this->ichiban->getOpenRouter();
		$configUrl = $ai->settingsUrl();
		$enabled = $ai->isConfigured();
		$model = $ai->activeModel();
		$providerLabel = $ai->providerLabel();
		$contextFiles = array_keys($ai->contextExportFiles());
		$modes = $this->aiModes();
		$mode = (string)$this->wire('input')->post('ai_mode');
		if (!isset($modes[$mode])) $mode = 'audit';
		$prompt = trim((string)$this->wire('input')->post('ai_test_prompt'));
		if ($prompt === '') {
			$prompt = $modes[$mode]['prompt'];
		}
		$result = null;
		if ($this->wire('input')->post('ichiban_ai_test')) {
			$this->wire('session')->CSRF->validate();
			$result = $ai->chat([
				'messages' => [['role' => 'user', 'content' => $prompt]],
				'system' => $this->aiSystemPrompt($mode),
				'caller' => 'Ichiban AI ' . $mode,
				'include_context' => true,
			]);
		}

		$out = $this->renderAdminNav('ai') . "<div class='ichiban-ai'>"
			. "<section class='ichiban-ai-hero'>"
			. "<div><span class='ichiban-eyebrow'>" . $san->entities($providerLabel) . "</span><h2>" . __('AI workspace is in development') . "</h2>"
			. "<p>" . __('This page will become the AI area for SEO suggestions, metadata drafts, schema help, and report summaries. It uses Context AI Gateway when available, with Ichiban OpenRouter settings as fallback, and attaches exported Context files to test requests.') . "</p></div>"
			. "<div class='ichiban-ai-status " . ($enabled ? 'is-ready' : 'is-missing') . "'>"
			. "<strong>" . ($enabled ? __('Configured') : __('Not configured')) . "</strong>"
			. "<span>" . sprintf(__('Provider: %s'), $san->entities($providerLabel)) . "</span>"
			. "<span>" . sprintf(__('Model: %s'), $san->entities($model)) . "</span>"
			. "<span>" . sprintf(__('Context files: %s'), $contextFiles ? $san->entities(implode(', ', $contextFiles)) : __('not found')) . "</span>"
			. "<a class='uk-button uk-button-default uk-button-small' href='{$configUrl}'>" . __('Open AI settings') . "</a>"
			. "</div></section>";

		$out .= "<section class='ichiban-ai-test'>"
			. "<div><h3>" . __('Test AI request') . "</h3><p>" . __('Save AI settings first, then send a prompt to confirm the connection, model response, and Context export ingestion.') . "</p></div>"
			. "<form method='post'>"
			. $this->wire('session')->CSRF->renderInput()
			. "<div class='ichiban-ai-modes'>";
		foreach ($modes as $key => $item) {
			$checked = $key === $mode ? " checked" : "";
			$out .= "<label class='ichiban-ai-mode" . ($key === $mode ? " is-active" : "") . "'>"
				. "<input type='radio' name='ai_mode' value='" . $san->entities($key) . "' data-prompt='" . $san->entities($item['prompt']) . "'{$checked}>"
				. "<strong>" . $san->entities($item['label']) . "</strong>"
				. "<span>" . $san->entities($item['hint']) . "</span>"
				. "</label>";
		}
		$out .= "</div>"
			. "<textarea class='uk-textarea' name='ai_test_prompt' rows='5'>" . $san->entities($prompt) . "</textarea>"
			. "<p><button class='uk-button uk-button-primary' type='submit' name='ichiban_ai_test' value='1'>" . __('Run test') . "</button></p>"
			. "</form>";
		if ($result !== null) {
			if (!empty($result['error'])) {
				$out .= "<div class='uk-alert uk-alert-danger'><strong>" . __('AI request failed') . "</strong><p>" . $san->entities((string)$result['error']) . "</p></div>";
			} else {
				$content = $san->entities((string)($result['content'] ?? ''));
				$used = !empty($result['context_files']) && is_array($result['context_files']) ? ' · Context: ' . implode(', ', $result['context_files']) : '';
				$finish = (string)($result['finish_reason'] ?? '');
				$tokens = is_array($result['usage'] ?? null) && isset($result['usage']['completion_tokens'])
					? ' · Output tokens: ' . (int)$result['usage']['completion_tokens']
					: '';
				$meta = sprintf(__('Model: %s · %d ms'), $san->entities((string)($result['model'] ?? $model)), (int)($result['duration_ms'] ?? 0))
					. ($finish !== '' ? ' · Finish: ' . $san->entities($finish) : '')
					. $san->entities($tokens . $used);
				if ($content === '') {
					$content = $san->entities((string)($result['empty_reason'] ?? __('The provider returned an empty message body. Try another model, increase Max tokens, or reduce the Context files sent with the request.')));
				}
				$out .= "<div class='ichiban-ai-result'><span>{$meta}</span><pre>{$content}</pre></div>";
			}
		}
		$out .= "</section></div>";
		return $out;
	}

	protected function aiModes(): array {
		return [
			'audit' => [
				'label' => __('Audit suggestions'),
				'hint' => __('Find practical SEO fixes from Context data.'),
				'prompt' => __('Using the attached Context export, give me 5 practical SEO improvements for the current site in concise bullets. Mention which pages, templates, or fields you used.'),
			],
			'metadata' => [
				'label' => __('Metadata drafts'),
				'hint' => __('Draft titles and descriptions for priority pages.'),
				'prompt' => __('Using the attached Context export, choose 5 priority pages or templates that need stronger metadata. For each one, draft an SEO title under 60 characters and a meta description under 155 characters.'),
			],
			'schema' => [
				'label' => __('Schema help'),
				'hint' => __('Recommend Schema.org mappings.'),
				'prompt' => __('Using the attached Context export and the existing ProcessWire templates/fields, suggest Schema.org types and field mappings for the most important page templates. Include source fields such as field:title or field:summary when possible.'),
			],
			'report' => [
				'label' => __('Report summary'),
				'hint' => __('Prepare a client-friendly report brief.'),
				'prompt' => __('Using the attached Context export, write a concise client-friendly SEO report summary: current site structure, biggest opportunities, risks, and next actions. Keep it suitable for a monthly report.'),
			],
		];
	}

	protected function aiSystemPrompt(string $mode): string {
		$base = 'You are an SEO assistant inside the Ichiban ProcessWire admin module. Use only the attached Context export and the user prompt as evidence. Be concise, practical, and specific. Do not use emoji. Do not use marketing fluff. Mention concrete ProcessWire templates, fields, URLs, or page IDs when they are present in the context. If the context does not contain enough evidence, say what is missing.';
		$modeHints = [
			'audit' => 'Prioritize actionable audit findings, grouped by impact.',
			'metadata' => 'Return copy-ready metadata drafts. Keep titles under 60 characters and descriptions under 155 characters.',
			'schema' => 'Focus on Schema.org types and property mappings that can be implemented in Ichiban Schema Builder.',
			'report' => 'Write in a client-friendly reporting style with clear next actions.',
		];
		return $base . ' ' . ($modeHints[$mode] ?? $modeHints['audit']);
	}

	protected function renderSchemaNavItem(int|string $schemaIndex, array $schema, bool $active): string {
		$san = $this->wire('sanitizer');
		$name = trim((string)($schema['name'] ?? ''));
		$type = (string)($schema['type'] ?? 'Thing');
		$templates = trim((string)($schema['templates'] ?? ''));
		$title = $name !== '' ? $name : $type;
		$meta = $templates !== '' ? $templates : __('No templates');
		return "<button class='ichiban-schema-nav-item" . ($active ? " is-active" : "") . "' type='button' data-schema-index='{$schemaIndex}'>"
			. "<span class='ichiban-schema-nav-title'>" . $san->entities($title) . "</span>"
			. "<span class='ichiban-schema-nav-meta'>" . $san->entities($type) . " · " . $san->entities($meta) . "</span>"
			. "</button>";
	}

	protected function renderSchemaEditorPanel(int|string $schemaIndex, array $schema, array $typeOptions, array $propertyOptions, bool $active): string {
		$san = $this->wire('sanitizer');
		$fields = $schema['fields'] ?? [];
		$type = (string)($schema['type'] ?? 'Thing');
		$typeInOptions = in_array($type, $typeOptions, true);
		$customType = $typeInOptions ? '' : $type;
		$selectType = $typeInOptions ? $type : 'Thing';
		$typeSelect = "<select class='uk-select ichiban-schema-type' name='schemas[{$schemaIndex}][type]'>";
		foreach ($this->schemaTypeOptionGroups() as $groupLabel => $schemaTypes) {
			$typeSelect .= "<optgroup label='" . $san->entities($groupLabel) . "'>";
			foreach ($schemaTypes as $schemaType) {
				$typeSelect .= "<option value='" . $san->entities($schemaType) . "'" . ($schemaType === $selectType ? ' selected' : '') . ">" . $san->entities($schemaType) . "</option>";
			}
			$typeSelect .= "</optgroup>";
		}
		$typeSelect .= "</select>";
		$fieldRows = $fields ?: ['' => ''];
		$rowIndex = 0;
		$rows = '';
		foreach ($fieldRows as $property => $expression) {
			$rows .= $this->renderSchemaPropertyRow($schemaIndex, $rowIndex++, (string)$property, (string)$expression, $propertyOptions);
		}

		return "<section class='ichiban-schema-editor-panel" . ($active ? " is-active" : "") . "' data-schema-index='{$schemaIndex}' data-next-row='{$rowIndex}'>"
			. "<div class='ichiban-schema-editor-toolbar'>"
				. "<label class='ichiban-schema-enabled'><input class='uk-checkbox' type='checkbox' name='schemas[{$schemaIndex}][enabled]' value='1'" . (!empty($schema['enabled']) ? ' checked' : '') . "> " . __('Enabled') . "</label>"
				. "<button class='uk-button uk-button-default ichiban-schema-remove' type='button'>" . __('Remove') . "</button>"
			. "</div>"
			. "<div class='ichiban-schema-grid'>"
				. "<label><span>" . __('Name') . "</span><input class='uk-input ichiban-schema-name' name='schemas[{$schemaIndex}][name]' value='" . $san->entities((string)($schema['name'] ?? '')) . "'></label>"
				. "<label><span>" . __('Schema type') . "</span>{$typeSelect}</label>"
				. "<label><span>" . __('Custom type') . "</span><input class='uk-input' name='schemas[{$schemaIndex}][custom_type]' value='" . $san->entities($customType) . "' placeholder='MedicalClinic'></label>"
				. "<label><span>" . __('Templates') . "</span><input class='uk-input ichiban-schema-templates' name='schemas[{$schemaIndex}][templates]' value='" . $san->entities((string)($schema['templates'] ?? '')) . "' placeholder='product, article'></label>"
			. "</div>"
			. "<div class='ichiban-schema-builder'><div class='ichiban-schema-builder-head'><span>" . __('Property') . "</span><span>" . __('Custom property') . "</span><span>" . __('Source expression') . "</span><span></span></div>"
				. "<div class='ichiban-schema-builder-rows'>{$rows}</div>"
				. "<button class='uk-button uk-button-default ichiban-schema-add-row' type='button'>" . __('Add property') . "</button>"
			. "</div>"
			. "<p>" . __('Use sources like field:title, field:summary|truncate:160, {legacy_field}, custom text, or empty string.') . "</p>"
			. "</section>";
	}

	protected function renderSchemaPropertyRow(int|string $schemaIndex, int|string $rowIndex, string $property, string $expression, array $propertyOptions): string {
		$san = $this->wire('sanitizer');
		$propertySelect = "<select class='uk-select' name='schemas[{$schemaIndex}][fields][{$rowIndex}][property]'>";
		$propertySelect .= "<option value=''>" . __('Choose property') . "</option>";
		$hasProperty = $property === '';
		foreach ($propertyOptions as $option) {
			$selected = $option === $property ? ' selected' : '';
			if ($selected) $hasProperty = true;
			$propertySelect .= "<option value='" . $san->entities($option) . "'{$selected}>" . $san->entities($option) . "</option>";
		}
		$propertySelect .= "</select>";
		$customProperty = $hasProperty ? '' : $property;
		return "<div class='ichiban-schema-builder-row'>"
			. $propertySelect
			. "<input class='uk-input' type='text' name='schemas[{$schemaIndex}][fields][{$rowIndex}][custom_property]' value='" . $san->entities($customProperty) . "' placeholder='aggregateRating'>"
			. "<input class='uk-input' type='text' name='schemas[{$schemaIndex}][fields][{$rowIndex}][expression]' value='" . $san->entities($expression) . "' placeholder='field:title'>"
			. "<button class='uk-button uk-button-default ichiban-schema-row-remove' type='button'>" . __('Remove') . "</button>"
			. "</div>";
	}

	protected function schemaFieldsFromPost(mixed $rows): array {
		$out = [];
		if (!is_array($rows)) return $out;
		foreach ($rows as $row) {
			if (!is_array($row)) continue;
			$property = preg_replace('/[^A-Za-z0-9_@.-]/', '', (string)($row['custom_property'] ?? '')) ?: preg_replace('/[^A-Za-z0-9_@.-]/', '', (string)($row['property'] ?? ''));
			$expression = trim((string)($row['expression'] ?? ''));
			if ($property === '' || $expression === '') continue;
			$out[$property] = $expression;
		}
		return $out;
	}

	protected function ensureSchemaTable(): void {
		$this->wire('database')->exec("CREATE TABLE IF NOT EXISTS `ichiban_schemas` (
			`id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
			`name`        VARCHAR(190) NOT NULL DEFAULT '',
			`schema_type` VARCHAR(128) NOT NULL DEFAULT 'Thing',
			`templates`   VARCHAR(512) NOT NULL DEFAULT '',
			`fields_json` MEDIUMTEXT NOT NULL,
			`enabled`     TINYINT(1) NOT NULL DEFAULT 1,
			`sort`        INT UNSIGNED NOT NULL DEFAULT 0,
			`created_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
			`updated_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (`id`),
			KEY `enabled_sort` (`enabled`, `sort`),
			KEY `schema_type` (`schema_type`)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
	}

	protected function migrateConfigSchemasToTable(): void {
		$db = $this->wire('database');
		try {
			$count = (int)$db->query("SELECT COUNT(*) FROM `ichiban_schemas`")->fetchColumn();
		} catch (\Throwable $e) {
			return;
		}
		if ($count > 0) return;
		$schemas = $this->ichiban->get('schema_mappings') ?: [];
		if (is_string($schemas)) $schemas = json_decode($schemas, true) ?: [];
		if (!is_array($schemas) || !$schemas) return;
		$stmt = $db->prepare("INSERT INTO `ichiban_schemas` (name, schema_type, templates, fields_json, enabled, sort) VALUES (:name, :type, :templates, :fields, 1, :sort)");
		$sort = 0;
		foreach ($schemas as $schema) {
			if (!is_array($schema)) continue;
			$fields = $schema['fields'] ?? [];
			if (!is_array($fields)) continue;
			$stmt->execute([
				':name' => (string)($schema['name'] ?? ($schema['type'] ?? 'Thing')),
				':type' => preg_replace('/[^A-Za-z0-9_]/', '', (string)($schema['type'] ?? 'Thing')) ?: 'Thing',
				':templates' => (string)($schema['templates'] ?? ''),
				':fields' => json_encode($fields, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
				':sort' => $sort++,
			]);
		}
	}

	protected function schemaTypeOptions(): array {
		return array_values(array_unique(array_merge(...array_values($this->schemaTypeOptionGroups()))));
	}

	protected function schemaTypeOptionGroups(): array {
		return [
			'Core' => ['Thing'],
			'Actions' => ['Action','AchieveAction','AssessAction','ConsumeAction','ControlAction','CreateAction','FindAction','InteractAction','MoveAction','OrganizeAction','PlayAction','SearchAction','TradeAction','TransferAction','UpdateAction'],
			'Creative works' => ['CreativeWork','Article','AdvertiserContentArticle','BlogPosting','Book','Chapter','Claim','Clip','Collection','Comment','Course','CreativeWorkSeason','CreativeWorkSeries','Dataset','DefinedTermSet','DigitalDocument','Drawing','Episode','FAQPage','HowTo','ImageObject','LearningResource','Map','MediaObject','MusicComposition','MusicPlaylist','MusicRecording','NewsArticle','Periodical','Photograph','Play','Poster','PresentationDigitalDocument','ProductCollection','ProfilePage','QAPage','Question','Recipe','Report','Review','ScholarlyArticle','Sculpture','SheetMusic','SoftwareApplication','SoftwareSourceCode','SpecialAnnouncement','Thesis','TVSeason','TVSeries','VisualArtwork','WebApplication','WebPage','WebPageElement','WebSite'],
			'Events' => ['Event','BusinessEvent','ChildrensEvent','ComedyEvent','CourseInstance','DanceEvent','DeliveryEvent','EducationEvent','EventSeries','ExhibitionEvent','Festival','FoodEvent','Hackathon','LiteraryEvent','MusicEvent','PublicationEvent','SaleEvent','ScreeningEvent','SocialEvent','SportsEvent','TheaterEvent','VisualArtsEvent'],
			'Intangibles' => ['Intangible','AlignmentObject','Audience','Brand','BroadcastChannel','BroadcastService','CategoryCode','DefinedTerm','Demand','DigitalDocumentPermission','EntryPoint','Enumeration','FinancialProduct','GameServer','Grant','HealthInsurancePlan','Invoice','ItemList','JobPosting','Language','ListItem','MediaSubscription','Menu','MenuItem','Offer','OfferCatalog','Occupation','Order','Permit','ProgramMembership','PropertyValue','Rating','Reservation','Role','Schedule','Service','ServiceChannel','SpeakableSpecification','StructuredValue','Ticket','VirtualLocation'],
			'Medical' => ['MedicalEntity','AnatomicalStructure','AnatomicalSystem','Drug','LifestyleModification','MedicalCause','MedicalCondition','MedicalDevice','MedicalGuideline','MedicalIndication','MedicalIntangible','MedicalProcedure','MedicalRiskEstimator','MedicalRiskFactor','MedicalSignOrSymptom','MedicalStudy','MedicalTest','Substance','SuperficialAnatomy'],
			'Organizations' => ['Organization','Airline','Consortium','Corporation','EducationalOrganization','FundingScheme','GovernmentOrganization','LibrarySystem','LocalBusiness','MedicalOrganization','NGO','NewsMediaOrganization','OnlineBusiness','PerformingGroup','Project','ResearchOrganization','SearchRescueOrganization','SportsOrganization','WorkersUnion'],
			'People' => ['Person','Patient'],
			'Places' => ['Place','Accommodation','AdministrativeArea','CivicStructure','Landform','LandmarksOrHistoricalBuildings','Residence','TouristAttraction','TouristDestination'],
			'Products' => ['Product','IndividualProduct','ProductGroup','ProductModel','SomeProducts','Vehicle'],
			'Other' => ['Taxon'],
		];
	}

	protected function schemaPropertyOptions(): array {
		return [
			'name','alternateName','title','description','url','mainEntityOfPage','image','logo','photo','sameAs','identifier','sku','gtin','brand','category','keywords','about','mentions',
			'author','creator','publisher','editor','contributor','dateCreated','datePublished','dateModified','headline','articleBody','articleSection','wordCount','thumbnailUrl',
			'offers','price','priceCurrency','priceRange','availability','itemCondition','aggregateRating','review','reviewRating','reviewBody','itemReviewed',
			'address','telephone','email','openingHours','geo','latitude','longitude','hasMap','areaServed','serviceType','provider','hiringOrganization','jobLocation',
			'startDate','endDate','eventAttendanceMode','eventStatus','location','organizer','performer',
			'prepTime','cookTime','totalTime','recipeYield','recipeIngredient','recipeInstructions','nutrition',
			'jobTitle','datePosted','validThrough','employmentType','worksFor','alumniOf','knowsAbout','birthDate',
			'mainEntity','acceptedAnswer','suggestedAnswer','itemListElement','position',
		];
	}

	public function executeSettings(): string {
		$this->setIchibanBreadcrumb(__('Settings'), 'settings/');
		$this->headline(__('SEO Settings'));
		if ($this->wire('input')->post('_ichiban_settings')) {
			$this->wire('session')->CSRF->validate();
			// Merge with existing config to preserve internal data (gsc tokens, etc.)
			$existing = $this->wire('modules')->getModuleConfigData('Ichiban');
			$input    = $this->wire('input');
			// Only save known config keys — never overwrite internal/runtime keys from POST
			$safeKeys = ['entity_type','entity_name','entity_url','verify_bing',
			             'verify_yandex','verify_baidu','verify_sogou','verify_360','verify_pinterest',
			             'verify_facebook_domain','verify_custom_meta','facebook_pixel_id',
			             'entity_logo','social_twitter','social_linkedin',
			             'social_facebook','social_github','social_instagram','gsc_site_url',
			             'gsc_client_id','gsc_client_secret','indexnow_key','twitter_site','global_defaults',
			             'moz_target','moz_api_token','moz_access_id','moz_secret_key','moz_api_base_url','moz_row_limit','moz_timeout',
			             'template_defaults','url_segments_mode','robots_enabled','robots_text','llms_enabled','auto_render_head',
			             'llms_mode','llms_templates','llms_manual_urls',
			             'sitemap_sitemap_dir','sitemap_chunk_size','sitemap_lastmod_format',
			             'sitemap_include_templates','sitemap_exclude_templates','sitemap_default_priority',
			             'sitemap_default_changefreq','sitemap_homepage_priority','sitemap_homepage_changefreq',
			             'sitemap_regenerate_interval','sitemap_custom_urls','sitemap_exclude_url_patterns',
			             'search_cleanup_enabled','search_cleanup_action','search_cleanup_patterns',
			             'remove_rsd','remove_wlw','remove_shortlink','remove_prev_next','remove_generator',
			             'ai_provider','ai_api_key','ai_model','ai_max_tokens','ai_temperature','ai_timeout',
			             'ai_system_prompt','ai_site_url','ai_site_name'];
			$save = $existing;
			foreach ($safeKeys as $key) {
				$value = $input->post($key);
				if ($value !== null) $save[$key] = $value;
			}
			foreach (['robots_enabled','llms_enabled','sitemap_enabled','sitemap_respect_noindex','sitemap_include_hidden',
			          'sitemap_include_unpublished','sitemap_include_images','sitemap_multilang_hreflang','sitemap_auto_regenerate',
			          'search_cleanup_enabled','remove_rsd','remove_wlw','remove_shortlink',
			          'remove_prev_next','remove_generator','auto_render_head'] as $key) {
				$save[$key] = $input->post($key) !== null ? 1 : 0;
			}
			$save['ai_enabled'] = $input->post('ai_enabled') !== null ? 1 : 0;
			if ($this->wire('input')->post('ichiban_generate_indexnow')) {
				$save['indexnow_key'] = $this->ichiban->generateIndexNowKey();
			}
			$this->wire('modules')->saveModuleConfigData('Ichiban', $save);
			$this->wire('session')->message(__('Settings saved.'));
			if (!empty($save['indexnow_key'])) {
				if ($this->ichiban->writeIndexNowKeyFile($save['indexnow_key'])) {
					$this->wire('session')->message(__('IndexNow key file written to the site root.'));
				} else {
					$this->wire('session')->warning(__('IndexNow key saved, but the key file could not be written. Create {key}.txt in the site root with the key as file content.'));
				}
			}
		}
		// Delegate to module config form after saving so the rendered values are current.
		$form = $this->wire('modules')->getModuleConfigInputfields('Ichiban');
		$form->prepend($this->wire('modules')->get('InputfieldHidden')->attr('name', '_ichiban_settings')->attr('value', '1'));
		return $this->renderAdminNav('settings') . "<form method='post'>" . $this->wire('session')->CSRF->renderInput() . $form->render()
			. "<p><button type='submit' class='uk-button uk-button-primary'>" . __('Save') . "</button> "
			. "<button type='submit' name='ichiban_generate_indexnow' value='1' class='uk-button uk-button-default'>" . __('Generate IndexNow key') . "</button></p></form>";
	}

	public function executeIdentity(): string {
		return $this->executeSettings();
	}

	// -------------------------------------------------------------------------
	// AJAX endpoints
	// -------------------------------------------------------------------------

	/** AJAX: rebuild index and return JSON stats */
	public function executeAjaxRebuildIndex(): void {
		ob_start();
		$this->wire('session')->CSRF->validate();
		$engine = new \IchibanAuditEngine($this->ichiban);
		$engine->rebuildIndex();
		$stats  = $engine->getQuickStats();
		ob_end_clean();
		header('Content-Type: application/json');
		echo json_encode(['ok' => true, 'stats' => $stats]);
		exit;
	}

	/** AJAX: restore a revision */
	public function executeAjaxRestoreRevision(): void {
		ob_start();
		$this->wire('session')->CSRF->validate();
		$revId  = (int)$this->wire('input')->post('rev_id');
		$result = $this->ichiban->getSeoRevisions()->restore($revId);
		ob_end_clean();
		header('Content-Type: application/json');
		echo json_encode(['ok' => $result]);
		exit;
	}

	/** AJAX: per-page Search Console metrics for InputfieldIchiban. */
	public function executeAjaxGscPage(): void {
		ob_start();
		$pageId = (int)$this->wire('input')->get('page_id');
		$page = $this->wire('pages')->get($pageId);
		$data = ['ok' => false];
		if ($page->id) {
			$gsc = new \IchibanSearchStatistics($this->ichiban);
			$data = ['ok' => true, 'metrics' => $gsc->getPageData($page->httpUrl(), 28)];
		}
		ob_end_clean();
		header('Content-Type: application/json');
		echo json_encode($data);
		exit;
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	protected function executeAuditCsv(): string {
		$db = $this->wire('database');
		try {
			$rows = $db->query("SELECT * FROM `ichiban_index` ORDER BY url")->fetchAll(\PDO::FETCH_ASSOC);
		} catch (\Throwable $e) {
			return "<div class='uk-alert uk-alert-warning'>" . __('Index not built.') . "</div>";
		}
		header('Content-Type: text/csv; charset=utf-8');
		header('Content-Disposition: attachment; filename="ichiban-audit-' . date('Y-m-d') . '.csv"');
		$csv = "url,canonical_url,meta_title,meta_description,meta_title_len,meta_desc_len,is_noindex,has_og_image,schema_type\n";
		foreach ($rows as $row) {
			$csv .= implode(',', array_map(fn($v) => '"' . str_replace('"', '""', (string)$v) . '"', [
				$row['url'], $row['canonical_url'] ?? '', $row['meta_title'], $row['meta_description'],
				$row['meta_title_len'], $row['meta_desc_len'],
				$row['is_noindex'], $row['has_og_image'], $row['schema_type'],
			])) . "\n";
		}
		echo $csv;
		exit;
	}

	protected function seoMaestroMigrationCandidates(): array {
		$out = [];
		$db = $this->wire('database');
		foreach ($this->wire('fields') as $field) {
			if (!$field->type || get_class($field->type) !== 'ProcessWire\\FieldtypeSeoMaestro') continue;
			$table = $field->getTable();
			$rows = 0;
			$dataRows = 0;
			try {
				$rows = (int)$db->query("SELECT COUNT(*) FROM `$table`")->fetchColumn();
				$dataRows = (int)$db->query("SELECT COUNT(*) FROM `$table` WHERE `data` IS NOT NULL AND `data` != '' AND `data` != '{}'")->fetchColumn();
			} catch (\Throwable $e) {
				// Keep the field visible even if the table is missing or unreadable.
			}
			$templates = [];
			foreach ($field->getFieldgroups() as $fieldgroup) {
				$template = $this->wire('templates')->get('fieldgroup=' . (int)$fieldgroup->id);
				if ($template && $template->id) $templates[] = $template->name;
			}
			$out[] = [
				'field' => $field->name,
				'table' => $table,
				'rows' => $rows,
				'data_rows' => $dataRows,
				'templates' => implode(', ', $templates),
			];
		}
		return $out;
	}

	protected function ichibanFields(): array {
		$out = [];
		foreach ($this->wire('fields') as $field) {
			if ($field->type instanceof FieldtypeIchiban) $out[$field->name] = $field;
		}
		uksort($out, static function(string $a, string $b): int {
			$rank = ['seo' => 0, 'ichiban' => 1];
			return ($rank[$a] ?? 10) <=> ($rank[$b] ?? 10) ?: strcmp($a, $b);
		});
		return $out;
	}

	protected function templateNamesForField(Field $field): array {
		$names = [];
		foreach ($this->wire('templates') as $template) {
			if (!$template->fieldgroup) continue;
			if ($template->fieldgroup->has($field)) $names[] = $template->name;
		}
		sort($names, SORT_NATURAL | SORT_FLAG_CASE);
		return $names;
	}

	protected function repairDuplicateIchibanFields(): array {
		$fields = $this->ichibanFields();
		if (count($fields) < 2) throw new WireException(__('No duplicate Ichiban fields were found.'));
		$names = array_keys($fields);
		$targetName = isset($fields['seo']) ? 'seo' : $names[0];
		$sourceName = isset($fields['ichiban']) && $targetName !== 'ichiban' ? 'ichiban' : ($names[1] ?? '');
		if ($sourceName === '' || !isset($fields[$targetName], $fields[$sourceName])) {
			throw new WireException(__('Could not determine which Ichiban fields to repair.'));
		}

		/** @var Field $target */
		$target = $fields[$targetName];
		/** @var Field $source */
		$source = $fields[$sourceName];
		$targetTable = $target->getTable();
		$sourceTable = $source->getTable();
		$this->ensureIchibanFieldColumns($targetTable);
		$this->ensureIchibanFieldColumns($sourceTable);

		$backups = [$this->backupFieldTable($targetTable), $this->backupFieldTable($sourceTable)];
		$copied = $this->copyMissingIchibanRows($sourceTable, $targetTable);
		$removedFromFieldgroups = 0;

		foreach ($source->getFieldgroups() as $fieldgroup) {
			if (!$fieldgroup->has($target)) {
				$fieldgroup->add($target);
			}
			if ($fieldgroup->has($source)) {
				$fieldgroup->remove($source);
				$removedFromFieldgroups++;
			}
			$fieldgroup->save();
		}

		try {
			$engine = new \IchibanAuditEngine($this->ichiban);
			$engine->rebuildIndex();
		} catch (\Throwable $e) {
			$this->wire('log')->save('ichiban', 'Duplicate field repair index rebuild failed: ' . $e->getMessage());
		}

		return [
			'target' => $targetName,
			'source' => $sourceName,
			'backups' => $backups,
			'rows' => $copied,
			'fieldgroups' => $removedFromFieldgroups,
		];
	}

	protected function copyMissingIchibanRows(string $sourceTable, string $targetTable): int {
		foreach ([$sourceTable, $targetTable] as $table) {
			if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) throw new WireException(__('Unsafe table name.'));
		}
		$db = $this->wire('database');
		$rows = $db->query("SELECT * FROM `$sourceTable`")->fetchAll(\PDO::FETCH_ASSOC);
		$stmt = $db->prepare("SELECT `data` FROM `$targetTable` WHERE pages_id=:pages_id LIMIT 1");
		$insert = $db->prepare("INSERT INTO `$targetTable`
			(`pages_id`, `data`, `meta_title`, `meta_noindex`, `og_image`, `sitemap_include`, `sitemap_priority`, `meta_inherit`, `og_inherit`, `schema_inherit`)
			VALUES (:pages_id, :data, :meta_title, :meta_noindex, :og_image, :sitemap_include, :sitemap_priority, :meta_inherit, :og_inherit, :schema_inherit)");
		$copied = 0;
		foreach ($rows as $row) {
			$stmt->execute([':pages_id' => (int)$row['pages_id']]);
			$existing = $stmt->fetchColumn();
			if ($existing !== false && !in_array(trim((string)$existing), ['', '{}', '[]'], true)) continue;
			if ($existing !== false) {
				$db->prepare("DELETE FROM `$targetTable` WHERE pages_id=:pages_id")->execute([':pages_id' => (int)$row['pages_id']]);
			}
			$insert->execute([
				':pages_id' => (int)$row['pages_id'],
				':data' => (string)($row['data'] ?? '{}'),
				':meta_title' => (string)($row['meta_title'] ?? ''),
				':meta_noindex' => (int)($row['meta_noindex'] ?? 0),
				':og_image' => (string)($row['og_image'] ?? ''),
				':sitemap_include' => array_key_exists('sitemap_include', $row) ? (int)$row['sitemap_include'] : 1,
				':sitemap_priority' => (float)($row['sitemap_priority'] ?? 0.5),
				':meta_inherit' => (int)($row['meta_inherit'] ?? 0),
				':og_inherit' => (int)($row['og_inherit'] ?? 0),
				':schema_inherit' => (int)($row['schema_inherit'] ?? 0),
			]);
			$copied++;
		}
		return $copied;
	}

	protected function migrateSeoMaestroField(string $fieldName): array {
		if ($fieldName === '') throw new WireException(__('Missing field name.'));
		$field = $this->wire('fields')->get($fieldName);
		if (!$field || !$field->id) throw new WireException(sprintf(__('Field "%s" was not found.'), $fieldName));
		if (!$field->type || get_class($field->type) !== 'ProcessWire\\FieldtypeSeoMaestro') {
			throw new WireException(sprintf(__('Field "%s" is not a SeoMaestro field.'), $fieldName));
		}
		/** @var FieldtypeIchiban $ichibanFieldtype */
		$ichibanFieldtype = $this->wire('modules')->get('FieldtypeIchiban');
		if (!$ichibanFieldtype) throw new WireException(__('FieldtypeIchiban is not installed.'));

		$db = $this->wire('database');
		$table = $field->getTable();
		$backup = $this->backupFieldTable($table);
		$rows = $db->query("SELECT `pages_id`, `data` FROM `$table`")->fetchAll(\PDO::FETCH_ASSOC);

		$this->ensureIchibanFieldColumns($table);

		$stmt = $db->prepare("UPDATE `$table` SET `data`=:data, `meta_title`=:meta_title, `meta_noindex`=:meta_noindex, `og_image`=:og_image, `sitemap_include`=:sitemap_include, `sitemap_priority`=:sitemap_priority, `meta_inherit`=0, `og_inherit`=0, `schema_inherit`=0 WHERE `pages_id`=:pages_id");
		$converted = 0;
		foreach ($rows as $row) {
			$oldData = json_decode((string)($row['data'] ?? '{}'), true) ?: [];
			$newData = $ichibanFieldtype->convertSeoMaestroData($oldData);
			$metaTitle = '';
			if (isset($newData['meta_title'])) {
				$mt = $newData['meta_title'];
				$metaTitle = is_array($mt) ? (string)($mt['value'] ?? '') : (string)$mt;
			}
			$stmt->execute([
				':data' => json_encode($newData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
				':meta_title' => $metaTitle,
				':meta_noindex' => !empty($newData['meta_noindex']) ? 1 : 0,
				':og_image' => (string)($newData['og_image'] ?? ''),
				':sitemap_include' => array_key_exists('sitemap_include', $newData) ? (int)(bool)$newData['sitemap_include'] : 1,
				':sitemap_priority' => (float)($newData['sitemap_priority'] ?? 0.5),
				':pages_id' => (int)$row['pages_id'],
			]);
			$converted++;
		}

		$field->type = $ichibanFieldtype;
		$this->wire('fields')->save($field);

		try {
			$engine = new \IchibanAuditEngine($this->ichiban);
			$engine->rebuildIndex();
		} catch (\Throwable $e) {
			$this->wire('log')->save('ichiban', 'Migration index rebuild failed: ' . $e->getMessage());
		}

		return ['field' => $field->name, 'backup' => $backup, 'rows' => $converted];
	}

	protected function backupFieldTable(string $table): string {
		if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) throw new WireException(__('Unsafe table name.'));
		$db = $this->wire('database');
		$backup = $table . '_ichiban_backup_' . date('Ymd_His');
		$db->exec("CREATE TABLE `$backup` LIKE `$table`");
		$db->exec("INSERT INTO `$backup` SELECT * FROM `$table`");
		return $backup;
	}

	protected function ensureIchibanFieldColumns(string $table): void {
		$db = $this->wire('database');
		$columns = [];
		foreach ($db->query("SHOW COLUMNS FROM `$table`")->fetchAll(\PDO::FETCH_ASSOC) as $row) {
			$columns[$row['Field']] = true;
		}
		$defs = [
			'meta_title' => 'VARCHAR(255) NOT NULL DEFAULT ""',
			'meta_noindex' => 'TINYINT(1) NOT NULL DEFAULT 0',
			'og_image' => 'VARCHAR(512) NOT NULL DEFAULT ""',
			'sitemap_include' => 'TINYINT(1) NOT NULL DEFAULT 1',
			'sitemap_priority' => 'DECIMAL(2,1) NOT NULL DEFAULT 0.5',
			'meta_inherit' => 'TINYINT(1) NOT NULL DEFAULT 0',
			'og_inherit' => 'TINYINT(1) NOT NULL DEFAULT 0',
			'schema_inherit' => 'TINYINT(1) NOT NULL DEFAULT 0',
		];
		foreach ($defs as $name => $definition) {
			if (!isset($columns[$name])) $db->exec("ALTER TABLE `$table` ADD `$name` $definition");
		}
	}

	protected function renderAdminNav(string $active): string {
		$items = [
			'dashboard' => [__('Dashboard'), ''],
			'bulk' => [__('Bulk Editor'), 'bulk/'],
			'audit' => [__('Audit'), 'audit/'],
			'redirects' => [__('Redirects'), 'redirects/'],
			'search-statistics' => [__('Insights'), 'search-statistics/'],
			'backlinks' => [__('Backlinks'), 'backlinks/'],
			'sitemap' => [__('Sitemap'), 'sitemap/'],
			'schemas' => [__('Schemas'), 'schemas/'],
			'revisions' => [__('Revisions'), 'revisions/'],
			'cleanup' => [__('Cleanup'), 'cleanup/'],
			'migration' => [__('Migration'), 'migration/'],
			'reports' => [__('Reports'), 'reports/'],
			'ai' => [__('AI'), 'ai/'],
		];
		$out = "<div class='ichiban-admin-nav uk-margin-medium-bottom'><ul class='uk-subnav uk-subnav-pill'>\n";
		foreach ($items as $key => $item) {
			$label = $item[0];
			$path = $item[1];
			$class = $key === $active ? " class='uk-active'" : '';
			$url = $this->adminUrl($path);
			$out .= "<li{$class}><a href='{$url}'>{$label}</a></li>\n";
		}
		$settingsLabel = __('Settings');
		$settingsClass = $active === 'settings' ? ' is-active' : '';
		$out .= "</ul><a class='ichiban-settings-link{$settingsClass}' href='" . $this->adminUrl('settings/') . "' title='{$settingsLabel}' aria-label='{$settingsLabel}'>" . $this->renderSettingsIcon() . "</a></div>\n";
		return $out;
	}

	protected function renderSettingsIcon(): string {
		return '<svg data-slot="icon" aria-hidden="true" fill="none" stroke-width="1.5" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">'
			. '<path d="M10.343 3.94c.09-.542.56-.94 1.11-.94h1.093c.55 0 1.02.398 1.11.94l.149.894c.07.424.384.764.78.93.398.164.855.142 1.205-.108l.737-.527a1.125 1.125 0 0 1 1.45.12l.773.774c.39.389.44 1.002.12 1.45l-.527.737c-.25.35-.272.806-.107 1.204.165.397.505.71.93.78l.893.15c.543.09.94.559.94 1.109v1.094c0 .55-.397 1.02-.94 1.11l-.894.149c-.424.07-.764.383-.929.78-.165.398-.143.854.107 1.204l.527.738c.32.447.269 1.06-.12 1.45l-.774.773a1.125 1.125 0 0 1-1.449.12l-.738-.527c-.35-.25-.806-.272-1.203-.107-.398.165-.71.505-.781.929l-.149.894c-.09.542-.56.94-1.11.94h-1.094c-.55 0-1.019-.398-1.11-.94l-.148-.894c-.071-.424-.384-.764-.781-.93-.398-.164-.854-.142-1.204.108l-.738.527c-.447.32-1.06.269-1.45-.12l-.773-.774a1.125 1.125 0 0 1-.12-1.45l.527-.737c.25-.35.272-.806.108-1.204-.165-.397-.506-.71-.93-.78l-.894-.15c-.542-.09-.94-.56-.94-1.109v-1.094c0-.55.398-1.02.94-1.11l.894-.149c.424-.07.765-.383.93-.78.165-.398.143-.854-.108-1.204l-.526-.738a1.125 1.125 0 0 1 .12-1.45l.773-.773a1.125 1.125 0 0 1 1.45-.12l.737.527c.35.25.807.272 1.204.107.397-.165.71-.505.78-.929l.15-.894Z" stroke-linecap="round" stroke-linejoin="round"></path>'
			. '<path d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" stroke-linecap="round" stroke-linejoin="round"></path>'
			. '</svg>';
	}

	protected function setIchibanBreadcrumb(string $label, string $path = ''): void {
		$breadcrumbs = $this->wire('breadcrumbs');
		$breadcrumbs->add(new Breadcrumb($this->adminUrl(), 'SEO (Ichiban)'));
		if ($path !== '') {
			$breadcrumbs->add(new Breadcrumb($this->adminUrl($path), $label));
		}
	}

	protected function renderBatteryScore(int $score, string $label = ''): string {
		$score = max(0, min(100, $score));
		$class = $score >= 75 ? 'good' : ($score >= 50 ? 'warning' : 'poor');
		$labelAttr = $this->wire('sanitizer')->entities($label ?: __('Score'));
		return "<div class='ichiban-battery-score ichiban-battery-{$class}' role='img' aria-label='{$labelAttr}: {$score}%'>"
			. "<div class='ichiban-battery-shell'><span style='width:{$score}%'></span><strong>{$score}%</strong></div>"
			. "</div>";
	}

	protected function getSchemaMappings(): array {
		try {
			$this->ensureSchemaTable();
			$rows = $this->wire('database')->query("SELECT * FROM `ichiban_schemas` ORDER BY sort ASC, id ASC")->fetchAll(\PDO::FETCH_ASSOC);
		} catch (\Throwable $e) {
			$rows = [];
		}
		if ($rows) {
			return array_map(static function(array $row): array {
				return [
					'name' => (string)$row['name'],
					'type' => (string)$row['schema_type'],
					'templates' => (string)$row['templates'],
					'fields' => json_decode((string)$row['fields_json'], true) ?: [],
					'enabled' => (int)($row['enabled'] ?? 1),
				];
			}, $rows);
		}
		$schemas = $this->ichiban->get('schema_mappings') ?: [];
		if (is_string($schemas)) $schemas = json_decode($schemas, true) ?: [];
		if (!is_array($schemas)) return [];
		foreach ($schemas as &$schema) {
			if (is_array($schema) && !array_key_exists('enabled', $schema)) $schema['enabled'] = 1;
		}
		unset($schema);
		return $schemas;
	}

	protected function adminUrl(string $path = ''): string {
		return rtrim($this->wire('config')->urls->admin, '/') . '/ichiban/' . ltrim($path, '/');
	}

	protected function auditRuleAffectedPages(string $ruleName, int $limit = 5): array {
		$whereMap = [
			'TitlePresent'       => "meta_title=''",
			'TitleLength'        => 'NOT (meta_title_len BETWEEN 30 AND 70)',
			'TitleUnique'        => "meta_title IN (SELECT meta_title FROM ichiban_index WHERE meta_title!='' GROUP BY meta_title HAVING COUNT(*) > 1)",
			'DescriptionPresent' => "meta_description=''",
			'DescriptionLength'  => 'NOT (meta_desc_len BETWEEN 50 AND 160)',
			'DescriptionUnique'  => "meta_description IN (SELECT meta_description FROM ichiban_index WHERE meta_description!='' GROUP BY meta_description HAVING COUNT(*) > 1)",
			'OgImagePresent'     => 'has_og_image=0',
			'CanonicalValid'     => 'canonical_url NOT LIKE "http%"',
			'NoindexOnPublic'    => 'is_noindex=1',
			'UrlNoUnderscores'   => 'url LIKE "%\\\\_%" ESCAPE "\\\\"',
			'SchemaPresent'      => "schema_type=''",
		];
		if (empty($whereMap[$ruleName])) return [];
		$db = $this->wire('database');
		try {
			$stmt = $db->prepare("SELECT page_id, url, template_name FROM ichiban_index WHERE {$whereMap[$ruleName]} ORDER BY url LIMIT :limit");
			$stmt->bindValue(':limit', max(1, $limit), \PDO::PARAM_INT);
			$stmt->execute();
			$rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
		} catch (\Throwable $e) {
			return [];
		}
		foreach ($rows as &$row) {
			$page = $this->wire('pages')->get((int)$row['page_id']);
			$row['edit_url'] = ($page && $page->id) ? $page->editUrl : (string)$row['url'];
		}
		unset($row);
		return $rows;
	}

	protected function shortValue(mixed $value, int $limit = 80): string {
		$text = trim((string)$value);
		if ($text === '') return 'empty';
		if (strlen($text) <= $limit) return $text;
		return substr($text, 0, $limit - 3) . '...';
	}

	protected function redirectOpenUrl(string $url): string {
		$url = trim($url);
		if ($url === '' || str_starts_with($url, '^')) return '';
		if (preg_match('!^https?://!i', $url)) return $url;
		if ($url[0] !== '/') $url = '/' . $url;
		return rtrim($this->wire('config')->urls->httpRoot, '/') . $url;
	}

	protected function rowScore(array $row): int {
		$score = 100;
		if (!$row['meta_title'])       $score -= 30;
		if (!$row['meta_description']) $score -= 20;
		if (!$row['has_og_image'])     $score -= 15;
		if ($row['meta_title_len'] > 70 || $row['meta_title_len'] < 30) $score -= 10;
		if ($row['meta_desc_len'] > 160 || $row['meta_desc_len'] < 50)  $score -= 10;
		if ($row['is_noindex'])        $score -= 15;
		return max(0, $score);
	}

	protected function rowScoreReasons(array $row): array {
		$reasons = [];
		if (!$row['meta_title']) $reasons[] = __('Missing title');
		if (!$row['meta_description']) $reasons[] = __('Missing description');
		if (!$row['has_og_image']) $reasons[] = __('Missing OG image');
		if ($row['meta_title_len'] > 70 || $row['meta_title_len'] < 30) $reasons[] = __('Title length outside target');
		if ($row['meta_desc_len'] > 160 || $row['meta_desc_len'] < 50) $reasons[] = __('Description length outside target');
		if ($row['is_noindex']) $reasons[] = __('Noindex');
		return $reasons ?: [__('All row checks pass')];
	}

	protected function renderGscTrendChart(array $rows): string {
		$san = $this->wire('sanitizer');
		$out = "<section class='ichiban-gsc-chart'><div class='ichiban-gsc-chart-head'><h3 class='uk-h4'>" . __('Daily Trend') . "</h3>"
			. "<div class='ichiban-gsc-chart-legend'><span class='clicks'>" . __('Clicks') . "</span><span class='impressions'>" . __('Impressions') . "</span></div></div>";
		if (!$rows) {
			return $out . "<div class='uk-alert uk-alert-primary'>" . __('No Search Console rows available yet.') . "</div></section>\n";
		}

		usort($rows, static fn(array $a, array $b): int => strcmp((string)($a['key'] ?? ''), (string)($b['key'] ?? '')));
		$chartRows = array_map(static fn(array $row): array => [
			'date' => (string)($row['key'] ?? ''),
			'clicks' => (int)($row['clicks'] ?? 0),
			'impressions' => (int)($row['impressions'] ?? 0),
		], $rows);
		$json = $san->entities(json_encode($chartRows, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
		return $out . "<div class='ichiban-gsc-chart-canvas-wrap'><canvas class='ichiban-gsc-chart-canvas' height='320' data-points='{$json}'></canvas><div class='ichiban-gsc-chart-tooltip'></div></div></section>\n";
	}

	protected function renderGscIndexingSection(\IchibanSearchStatistics $gsc, int $days, string $view): string {
		$san = $this->wire('sanitizer');
		$property = $gsc->getPropertyId();
		$url = 'https://search.google.com/search-console/index?resource_id=' . rawurlencode($property);
		$summary = $gsc->getIndexingIssues(8);
		$checked = (int)($summary['checked_at'] ?? 0);
		$checkedText = $checked ? $this->wire('datetime')->date('Y-m-d H:i', $checked) : __('Never checked');
		$issueCount = (int)($summary['issues'] ?? 0);
		$indexedCount = (int)($summary['indexed'] ?? 0);
		$totalCount = (int)($summary['total'] ?? 0);
		$out = "<section class='ichiban-gsc-indexing'>"
			. "<div class='ichiban-gsc-indexing-head'><div><h3 class='uk-h4'>" . __('Page Indexing') . "</h3>"
			. "<p>" . __('Checks a URL sample with Google URL Inspection API and groups non-indexed / problematic pages by coverage reason. Ichiban first uses Top Pages from Search Console for the last 28 days, then fills the sample with public ProcessWire pages sorted by path. This is a cached site scan, not Google’s full Page indexing aggregate report.') . "</p>"
			. "<code>" . $san->entities($property) . "</code></div>"
			. "<div class='ichiban-gsc-indexing-actions'>"
			. "<form method='post' action='" . $san->entities($this->gscUrl($view, $days)) . "' class='uk-margin-remove'>"
			. $this->wire('session')->CSRF->renderInput()
			. "<input type='hidden' name='refresh_indexing' value='1'>"
			. "<button class='uk-button uk-button-primary uk-button-small' type='submit'>" . __('Scan indexing issues') . "</button>"
			. "</form>"
			. "<a class='uk-button uk-button-default uk-button-small' target='_blank' rel='noopener' href='" . $san->entities($url) . "'>" . __('Open Page Indexing') . "</a>"
			. "</div></div>"
			. "<div class='ichiban-gsc-indexing-metrics'>"
			. "<div><strong>" . $issueCount . "</strong><span>" . __('Issues') . "</span></div>"
			. "<div><strong>" . $indexedCount . "</strong><span>" . __('Indexed') . "</span></div>"
			. "<div><strong>" . $totalCount . "</strong><span>" . __('Checked URLs') . "</span></div>"
			. "<div><strong>" . $san->entities($checkedText) . "</strong><span>" . __('Last scan') . "</span></div>"
			. "</div>";

		if (!$totalCount) {
			return $out . "<div class='uk-alert uk-alert-primary'>" . __('No indexing scan yet. Click Scan indexing issues to inspect Top Pages from Search Console first, then public ProcessWire pages if more URLs are needed.') . "</div></section>\n";
		}
		if (!$issueCount) {
			return $out . "<div class='uk-alert uk-alert-success'>" . __('No indexing issues found in the checked URL sample.') . "</div></section>\n";
		}

		$out .= "<div class='ichiban-gsc-indexing-grid'>";
		foreach (($summary['groups'] ?? []) as $group) {
			$out .= "<div class='ichiban-gsc-indexing-reason'><strong>" . $san->entities((string)$group['reason']) . "</strong><span>" . (int)$group['count'] . " " . __('URLs') . "</span>";
			foreach (($group['examples'] ?? []) as $example) {
				$out .= "<a href='" . $san->entities($example) . "' target='_blank' rel='noopener'>" . $san->entities($example) . "</a>";
			}
			$out .= "</div>";
		}
		return $out . "</div></section>\n";
	}

	protected function renderGscTable(string $title, array $rows, string $keyLabel = '', string $moreUrl = ''): string {
		$san = $this->wire('sanitizer');
		if ($keyLabel === '') $keyLabel = __('Page / query');
		$isPageTable = $keyLabel === __('Page');
		if ($isPageTable) $rows = $this->enrichGscPageRows($rows);
		$rowCount = count($rows);
		$out = "<section class='ichiban-gsc-table'><div class='ichiban-gsc-table-head'><h3 class='uk-h4'>" . $san->entities($title) . "</h3>";
		if ($moreUrl !== '') $out .= "<a href='" . $san->entities($moreUrl) . "'>" . __('View all') . "</a>";
		$out .= "</div>\n";
		if (!$rows) {
			return $out . "<div class='uk-alert uk-alert-primary'>" . __('No Search Console rows available yet.') . "</div></section>\n";
		}
		$out .= "<div class='uk-overflow-auto'><table class='uk-table uk-table-small uk-table-divider uk-table-hover ichiban-sortable-table'><thead><tr>"
			. "<th><button type='button' data-sort-type='text'>" . $san->entities($keyLabel) . "</button></th>";
		if ($isPageTable) {
			$out .= "<th><button type='button' data-sort-type='number'>" . __('Score') . "</button></th>";
		}
		$out .= "<th><button type='button' data-sort-type='number'>" . __('Clicks') . "</button></th>"
			. "<th><button type='button' data-sort-type='number'>" . __('Impressions') . "</button></th>"
			. "<th><button type='button' data-sort-type='number'>" . __('CTR') . "</button></th>"
			. "<th><button type='button' data-sort-type='number'>" . __('Position') . "</button></th>";
		if ($isPageTable) {
			$out .= "<th class='ichiban-gsc-edit-column'><span class='uk-hidden'>" . __('Edit') . "</span></th>";
		}
		$out .= "</tr></thead><tbody>\n";
		foreach ($rows as $row) {
			$keyRaw = (string)($row['key'] ?? '');
			$key = $this->formatGscKey((string)($row['key'] ?? ''), $keyLabel);
			$clicks = (int)($row['clicks'] ?? 0);
			$impressions = (int)($row['impressions'] ?? 0);
			$ctr = (string)($row['ctr'] ?? '0%');
			$position = (float)($row['position'] ?? 0);
			$out .= "<tr>"
				. "<td data-sort='" . $san->entities(strtolower($keyRaw)) . "'>{$key}</td>";
			if ($isPageTable) {
				$score = isset($row['audit_score']) ? (int)$row['audit_score'] : -1;
				$scoreClass = $score >= 80 ? 'ichiban-score-good' : ($score >= 60 ? 'ichiban-score-warning' : 'ichiban-score-poor');
				$scoreHtml = $score >= 0
					? "<span class='ichiban-gsc-audit-score {$scoreClass}'>{$score}</span>"
					: "<span class='ichiban-gsc-missing'>" . __('n/a') . "</span>";
				$out .= "<td data-sort='{$score}'>{$scoreHtml}</td>";
			}
			$out .= "<td data-sort='{$clicks}'>{$clicks}</td>"
				. "<td data-sort='{$impressions}'>{$impressions}</td>"
				. "<td data-sort='" . (float)str_replace('%', '', $ctr) . "'>" . $san->entities($ctr) . "</td>"
				. "<td data-sort='{$position}'>" . $san->entities((string)($row['position'] ?? 0)) . "</td>";
			if ($isPageTable) {
				$editUrl = (string)($row['edit_url'] ?? '');
				$editHtml = $editUrl !== ''
					? "<a class='ichiban-gsc-edit-link' href='" . $san->entities($editUrl) . "' title='" . $san->entities(__('Edit page')) . "' aria-label='" . $san->entities(__('Edit page')) . "'><span aria-hidden='true'>✎</span></a>"
					: "<span class='ichiban-gsc-missing'>—</span>";
				$out .= "<td class='ichiban-gsc-edit-column'>{$editHtml}</td>";
			}
			$out .= "</tr>\n";
		}
		return $out . "</tbody></table></div><div class='ichiban-gsc-table-foot'><span>" . sprintf(__('%s rows'), number_format($rowCount)) . "</span></div></section>\n";
	}

	protected function enrichGscPageRows(array $rows): array {
		if (!$rows) return $rows;
		try {
			$indexRows = $this->wire('database')->query("SELECT * FROM `ichiban_index`")->fetchAll(\PDO::FETCH_ASSOC);
		} catch (\Throwable $e) {
			return $rows;
		}
		$index = [];
		foreach ($indexRows as $row) {
			$url = rtrim((string)($row['url'] ?? ''), '/');
			if ($url === '') continue;
			$page = $this->wire('pages')->get((int)($row['page_id'] ?? 0));
			$row['audit_score'] = $this->rowScore($row);
			$row['edit_url'] = ($page && $page->id) ? $page->editUrl : '';
			$index[$url] = $row;
		}
		foreach ($rows as &$row) {
			$url = rtrim((string)($row['key'] ?? ''), '/');
			if ($url !== '' && isset($index[$url])) {
				$row['audit_score'] = $index[$url]['audit_score'];
				$row['edit_url'] = $index[$url]['edit_url'];
			}
		}
		unset($row);
		return $rows;
	}

	protected function renderGscFooter(\IchibanSearchStatistics $gsc, string $disconnectUrl): string {
		$lastUpdated = $gsc->getLastCacheTime();
		$updatedText = $lastUpdated
			? sprintf(__('Last updated %s'), $this->wire('datetime')->date('Y-m-d H:i', $lastUpdated))
			: __('Last updated unavailable');
		return "<div class='ichiban-gsc-footer'><span>{$updatedText}</span><form method='post' action='{$disconnectUrl}' class='uk-margin-remove'>"
			. $this->wire('session')->CSRF->renderInput()
			. "<input type='hidden' name='disconnect' value='1'>"
			. "<button class='uk-button uk-button-default uk-button-small' type='submit'>" . __('Disconnect GSC') . "</button>"
			. "</form></div>\n";
	}

	protected function formatGscKey(string $key, string $keyLabel): string {
		$san = $this->wire('sanitizer');
		if ($keyLabel === __('Page') && preg_match('!^https?://!i', $key)) {
			$href = $san->entities($key);
			$text = $san->entities($key);
			return "<a href='{$href}' target='_blank' rel='noopener'>{$text}</a>";
		}
		if ($keyLabel === __('Country')) {
			$name = $this->countryName($key);
			return $san->entities($name ?: strtoupper($key));
		}
		if ($keyLabel === __('Device') || $keyLabel === __('Appearance')) {
			return $san->entities(ucwords(strtolower(str_replace('_', ' ', $key))));
		}
		return $san->entities($key);
	}

	protected function countryName(string $code): string {
		$map = [
			'arg' => 'Argentina', 'aus' => 'Australia', 'aut' => 'Austria', 'bel' => 'Belgium', 'bmu' => 'Bermuda',
			'bra' => 'Brazil', 'can' => 'Canada', 'chl' => 'Chile', 'chn' => 'China', 'col' => 'Colombia',
			'cym' => 'Cayman Islands', 'cyp' => 'Cyprus', 'cze' => 'Czechia', 'deu' => 'Germany', 'dnk' => 'Denmark',
			'dom' => 'Dominican Republic', 'dza' => 'Algeria', 'esp' => 'Spain', 'fin' => 'Finland', 'fra' => 'France',
			'gbr' => 'United Kingdom', 'grc' => 'Greece', 'hkg' => 'Hong Kong', 'idn' => 'Indonesia', 'ind' => 'India',
			'irl' => 'Ireland', 'ita' => 'Italy', 'jpn' => 'Japan', 'kor' => 'South Korea', 'mex' => 'Mexico',
			'nld' => 'Netherlands', 'nor' => 'Norway', 'nzl' => 'New Zealand', 'pol' => 'Poland', 'prt' => 'Portugal',
			'rou' => 'Romania', 'sgp' => 'Singapore', 'swe' => 'Sweden', 'tha' => 'Thailand', 'tur' => 'Turkey',
			'ukr' => 'Ukraine', 'usa' => 'United States', 'vnm' => 'Vietnam', 'zaf' => 'South Africa',
		];
		return $map[strtolower($code)] ?? '';
	}
}
