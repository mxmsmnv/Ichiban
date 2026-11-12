<?php

require dirname(__DIR__) . '/src/Cli/Cli.php';
require dirname(__DIR__) . '/src/SearchStatistics/IndexingPublisher.php';

$module = new class {
	public function getSearchStatistics(): object {
		return new class {
			public function isConnected(): bool { return true; }
			public function inspectPublicPage(string $url): array {
				return str_contains($url, '/missing/') ? [] : ['verdict' => 'PASS'];
			}
			public function getLastError(): string { return ''; }
		};
	}
};

$cli = new class($module) extends IchibanCli {
	public function execute(array $options): array {
		return $this->run($options);
	}
};

$input = tempnam(sys_get_temp_dir(), 'ichiban-indexing-');
try {
	file_put_contents($input, "https://example.test/product/\nhttps://example.test/missing/\nhttps://example.test/jobs/role/\n");
	$options = ['ichiban-indexing' => 'inspect', 'ichiban-url-file' => $input, 'ichiban-limit' => '2'];
	$first = $cli->execute($options);
	$second = $cli->execute($options + ['ichiban-offset' => $first['next_offset']]);
	if (array_column($first['results'], 'status') !== ['inspected', 'error']
		|| $first['next_offset'] !== 2
		|| array_column($second['results'], 'url') !== ['https://example.test/jobs/role/']
		|| $second['next_offset'] !== null) {
		throw new RuntimeException('Indexing batch did not preserve errors or resume at the next input line.');
	}
} finally {
	unlink($input);
}

echo "Ichiban indexing CLI pagination passed.\n";
