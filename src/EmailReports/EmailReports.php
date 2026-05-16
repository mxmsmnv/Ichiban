<?php

/**
 * IchibanEmailReports — sends scheduled SEO summary emails via WireMail.
 *
 * Triggered by LazyCron. Frequency: weekly (Mon 8am) or monthly (1st of month).
 */
class IchibanEmailReports {

	protected object $ichiban;

	public function __construct(object $ichiban) {
		$this->ichiban = $ichiban;
	}

	public function init(): void {
		if (!$this->ichiban->get('email_reports_enabled')) return;
		$freq = $this->ichiban->get('email_reports_frequency') ?: 'weekly';
		// LazyCron method: every30Days for monthly, every7Days for weekly
		$method = $freq === 'monthly' ? 'every30Days' : 'every7Days';
		$this->ichiban->wire()->addHook("LazyCron::{$method}", $this, 'generateAndSend');
	}

	public function generateAndSend(?\ProcessWire\HookEvent $e = null): void {
		$report = $this->generateReport();
		$this->saveLastReport($report);
		$this->sendReport($report);
	}

	public function send(?\ProcessWire\HookEvent $e = null): void {
		$report = $this->getLastReport();
		if (!$report) {
			$report = $this->generateReport();
			$this->saveLastReport($report);
		}
		$this->sendReport($report);
	}

	public function sendTest(string $to = ''): bool {
		$report = $this->generateReport();
		$this->saveLastReport($report);
		$emails = $this->emails($to !== '' ? $to : (string)$this->ichiban->get('email_reports_recipients'));
		if (!$emails) return false;
		return $this->sendReport($report, $emails);
	}

	public function generateReport(): array {
		$audit = new \IchibanAuditEngine($this->ichiban);
		$report = $audit->getReport();
		$quick = $audit->getQuickStats();
		$san = $this->ichiban->wire('sanitizer');
		$db = $this->ichiban->wire('database');

		$critical = [];
		foreach (($report['rules'] ?? []) as $rule) {
			if ((int)($rule['issues'] ?? 0) <= 0) continue;
			if (!in_array((string)($rule['severity'] ?? ''), ['critical', 'error', 'warning'], true)) continue;
			$critical[] = [
				'name' => (string)($rule['name'] ?? ''),
				'severity' => (string)($rule['severity'] ?? ''),
				'issues' => (int)($rule['issues'] ?? 0),
			];
		}
		usort($critical, static fn(array $a, array $b): int => ($b['issues'] <=> $a['issues']));

		$gsc = null;
		if ($this->ichiban->get('email_reports_include_gsc') && $this->ichiban->get('gsc_access_token')) {
			try {
				$search = new \IchibanSearchStatistics($this->ichiban);
				$gsc = [
					'summary' => $search->getDashboardData(28),
					'top_pages' => $search->getTopPages(28, 10),
					'top_queries' => $search->getTopQueries(28, 10),
					'indexing' => $search->getIndexingIssues(8),
				];
			} catch (\Throwable $e) {
				$gsc = ['error' => $e->getMessage()];
			}
		}

		$countTable = static function ($table) use ($db): int {
			try {
				return (int)$db->query("SELECT COUNT(*) FROM {$table}")->fetchColumn();
			} catch (\Throwable $e) {
				return 0;
			}
		};

		return [
			'version' => 1,
			'generated_at' => date('c'),
			'frequency' => (string)($this->ichiban->get('email_reports_frequency') ?: 'weekly'),
			'site' => [
				'name' => (string)($this->ichiban->get('entity_name') ?: $this->ichiban->wire('config')->httpHost),
				'url' => rtrim((string)$this->ichiban->wire('config')->urls->httpRoot, '/') . '/',
			],
			'audit' => [
				'score' => (int)($report['score'] ?? ($quick['score'] ?? 0)),
				'total_pages' => (int)($report['total'] ?? 0),
				'quick_stats' => $quick,
				'priority_issues' => array_slice($critical, 0, 10),
			],
			'activity' => [
				'redirects' => $countTable('ichiban_redirects'),
				'cleanup_blocks' => $countTable('ichiban_cleanup_log'),
				'revisions' => $countTable('ichiban_revisions'),
			],
			'gsc' => $gsc,
			'admin_url' => rtrim((string)$this->ichiban->wire('config')->urls->httpAdmin, '/') . '/ichiban/reports/',
		];
	}

	public function saveLastReport(array $report): void {
		$modules = $this->ichiban->wire('modules');
		$config = $modules->getModuleConfigData('Ichiban');
		$config['email_reports_last_json'] = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		$config['email_reports_last_generated'] = time();
		$modules->saveModuleConfigData('Ichiban', $config);
	}

	public function getLastReport(): array {
		$json = (string)$this->ichiban->get('email_reports_last_json');
		$report = $json !== '' ? json_decode($json, true) : [];
		return is_array($report) ? $report : [];
	}

	public function lastReportJson(): string {
		return (string)$this->ichiban->get('email_reports_last_json');
	}

	protected function sendReport(array $report, array $emails = []): bool {
		$recipients = $this->ichiban->get('email_reports_recipients') ?: '';
		if (!$emails) $emails = $this->emails($recipients);
		if (!$emails) return false;

		$body = $this->buildHtml($report);
		if (!$body) return false;

		$from = trim((string)$this->ichiban->get('email_reports_from_email'));
		if ($from === '') $from = $this->ichiban->wire('config')->adminEmail ?: '';
		if (!$from) {
			$this->ichiban->wire('log')->save('ichiban', 'Email report skipped: no adminEmail configured');
			return false;
		}
		$fromName = trim((string)$this->ichiban->get('email_reports_from_name'));
		if ($fromName === '') $fromName = (string)($report['site']['name'] ?? $this->ichiban->wire('config')->httpHost);
		$subject = 'SEO Report - ' . date('Y-m-d');
		$docx = $this->buildDocx($report);
		$mailModule = trim((string)$this->ichiban->get('email_reports_mail_module'));
		$sent = false;
		foreach ($emails as $email) {
			try {
				/** @var \ProcessWire\WireMail $m */
				$m = $mailModule !== ''
					? $this->ichiban->wire('mail')->new($mailModule)
					: $this->ichiban->wire('mail')->new();
				$m->to($email)->from($from, $fromName)->subject($subject)->bodyHTML($body)->body(strip_tags($body));
				$tmp = '';
				if ($docx !== '' && method_exists($m, 'attachment')) {
					$tmp = tempnam(sys_get_temp_dir(), 'ichiban-report-');
					file_put_contents($tmp, $docx);
					$m->attachment($tmp, 'ichiban-seo-report-' . date('Y-m-d') . '.docx');
				}
				$result = $m->send();
				if ($tmp !== '') @unlink($tmp);
				$sent = $sent || $this->mailResultOk($result);
			} catch (\Throwable $ex) {
				$this->ichiban->wire('log')->save('ichiban', "Email report failed for {$email}: " . $ex->getMessage());
			}
		}
		return $sent;
	}

	public function buildHtml(array $data): string {
		$san     = $this->ichiban->wire('sanitizer');

		$siteName = $san->entities((string)($data['site']['name'] ?? $this->ichiban->wire('config')->httpHost));
		$score = (int)($data['audit']['score'] ?? 0);
		$html  = "<h2>{$siteName} — SEO Report</h2>\n";
		$html .= "<p><strong>Site Score: {$score}/100</strong></p>\n";

		$critical = $data['audit']['priority_issues'] ?? [];
		if ($critical) {
			$html .= "<h3>Issues Requiring Attention</h3><ul>\n";
			foreach (array_slice($critical, 0, 5) as $rule) {
				$name = $san->entities($rule['name']);
				$sev  = $san->entities($rule['severity']);
				$html .= "<li><strong>{$name}</strong> ({$sev}): {$rule['issues']} pages affected</li>\n";
			}
			$html .= "</ul>\n";
		}

		if (!empty($data['gsc']['summary'])) {
			$gsc = $data['gsc']['summary'];
			$html .= "<h3>Search Performance (last 28 days)</h3>\n";
			$html .= "<ul><li>Clicks: " . $san->entities((string)($gsc['clicks'] ?? 0)) . "</li><li>Impressions: " . $san->entities((string)($gsc['impressions'] ?? 0)) . "</li>"
				. "<li>CTR: " . $san->entities((string)($gsc['ctr'] ?? '0%')) . "</li><li>Avg Position: " . $san->entities((string)($gsc['position'] ?? 0)) . "</li></ul>\n";
		}

		$adminUrl = $san->entities((string)($data['admin_url'] ?? ($this->ichiban->wire('config')->urls->httpAdmin . 'ichiban/reports/')));
		$html .= "<p><a href='{$adminUrl}'>View full report in admin</a></p>\n";
		return $html;
	}

	public function buildDocx(array $report): string {
		if (!class_exists('\\ZipArchive')) return '';
		$tmp = tempnam(sys_get_temp_dir(), 'ichiban-docx-');
		$zip = new \ZipArchive();
		if ($zip->open($tmp, \ZipArchive::OVERWRITE) !== true) return '';
		$zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/></Types>');
		$zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/></Relationships>');
		$zip->addFromString('word/_rels/document.xml.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"/>');
		$zip->addFromString('word/document.xml', $this->docxDocumentXml($report));
		$zip->close();
		$bytes = (string)file_get_contents($tmp);
		@unlink($tmp);
		return $bytes;
	}

	protected function docxDocumentXml(array $report): string {
		$lines = [
			'SEO Report',
			(string)($report['site']['name'] ?? ''),
			'Generated: ' . (string)($report['generated_at'] ?? ''),
			'Score: ' . (int)($report['audit']['score'] ?? 0) . '/100',
			'Total pages: ' . (int)($report['audit']['total_pages'] ?? 0),
			'',
			'Priority issues',
		];
		foreach (($report['audit']['priority_issues'] ?? []) as $issue) {
			$lines[] = sprintf('%s (%s): %d pages', (string)($issue['name'] ?? ''), (string)($issue['severity'] ?? ''), (int)($issue['issues'] ?? 0));
		}
		if (!empty($report['gsc']['summary'])) {
			$gsc = $report['gsc']['summary'];
			$lines[] = '';
			$lines[] = 'Search Performance, last 28 days';
			$lines[] = 'Clicks: ' . (string)($gsc['clicks'] ?? 0);
			$lines[] = 'Impressions: ' . (string)($gsc['impressions'] ?? 0);
			$lines[] = 'CTR: ' . (string)($gsc['ctr'] ?? '0%');
			$lines[] = 'Avg position: ' . (string)($gsc['position'] ?? 0);
		}
		$body = '';
		foreach ($lines as $line) {
			$body .= '<w:p><w:r><w:t xml:space="preserve">' . htmlspecialchars($line, ENT_XML1 | ENT_COMPAT, 'UTF-8') . '</w:t></w:r></w:p>';
		}
		return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>' . $body . '<w:sectPr><w:pgSz w:w="12240" w:h="15840"/><w:pgMar w:top="1440" w:right="1440" w:bottom="1440" w:left="1440"/></w:sectPr></w:body></w:document>';
	}

	protected function emails(string $recipients): array {
		$san = $this->ichiban->wire('sanitizer');
		$emails = [];
		foreach (array_filter(array_map('trim', explode(',', $recipients))) as $email) {
			$email = $san->email($email);
			if ($email !== '') $emails[] = $email;
		}
		return array_values(array_unique($emails));
	}

	protected function mailResultOk(mixed $result): bool {
		if (is_bool($result)) return $result;
		if (is_int($result) || is_float($result) || is_numeric($result)) return ((int)$result) > 0;
		if (is_string($result)) return trim($result) !== '' && (bool)preg_match('/accepted|message\s*id/i', $result);
		return false;
	}
}
