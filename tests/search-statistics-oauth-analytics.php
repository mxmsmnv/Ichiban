<?php

namespace ProcessWire {
    class WireHttp {
        public static array $reply = [];
        public static int $calls = 0;
        public function post(string $url, array $body): string {
            self::$calls++;
            return json_encode(self::$reply, JSON_THROW_ON_ERROR);
        }
    }
}

namespace {
    require dirname(__DIR__) . '/src/SearchStatistics/SearchStatistics.php';

    function check(bool $condition, string $message): void {
        if (!$condition) throw new RuntimeException($message);
    }

    $session = new class {
        public array $data = [];
        public function set($key, $value): void { $this->data[$key] = $value; }
        public function get($key): mixed { return $this->data[$key] ?? null; }
        public function remove($key): void { unset($this->data[$key]); }
    };
    $modules = new class {
        public array $config = ['gsc_access_token' => 'old-access', 'gsc_refresh_token' => 'old-refresh'];
        public function getModuleConfigData($name): array { return $this->config; }
        public function saveModuleConfigData($name, $config): bool { $this->config = $config; return true; }
    };
    $insightsCache = new class {
        public array $data = [];
        public function getFor($namespace, $key): mixed { return $this->data[$key] ?? null; }
        public function saveFor($namespace, $key, $value, $ttl): void { $this->data[$key] = $value; }
    };
    $cacheDirectory = sys_get_temp_dir() . '/ichiban-insights-' . bin2hex(random_bytes(6)) . '/';
    mkdir($cacheDirectory, 0700);
    register_shutdown_function(static function() use ($cacheDirectory) {
        foreach (glob($cacheDirectory . '*.lock') as $file) unlink($file);
        rmdir($cacheDirectory);
    });
    $app = new class($session, $modules, $insightsCache, $cacheDirectory) {
        public function __construct(private object $session, private object $modules, private object $cache, private string $cacheDirectory) {}
        public function get($key): string { return 'configured'; }
        public function wire($key): object {
            return match ($key) {
                'session' => $this->session,
                'modules' => $this->modules,
                'cache' => $this->cache,
                'config' => (object)['paths' => (object)['cache' => $this->cacheDirectory]],
                default => $key,
            };
        }
    };
    $newStats = static fn() => new class($app) extends IchibanSearchStatistics {
        public array $cache = [];
        public array $requests = [];
        public string $response = '{"error":{"code":403,"message":"Denied"}}';
        protected function getRedirectUri(): string { return 'https://example.test/insights/'; }
        protected function getSiteUrl(): string { return 'sc-domain:example.test'; }
        protected function getAccessToken(): ?string { return 'token'; }
        protected function getCache(string $url, string $key): ?array { return $this->cache[$key] ?? null; }
        protected function setCache(string $url, string $key, array $data): void { $this->cache[$key] = $data; }
        protected function apiRequest(string $url, string $token, string $body = ''): string {
            $this->requests[] = json_decode($body, true);
            return $this->response;
        }
    };

    $stats = $newStats();
    check($stats->getDashboardData() === [] && !$stats->cache, 'Google 403 was cached as traffic data.');
    check($stats->getAnalyticsError() !== '' && $stats->getDailyRows() === [], 'Google failure generated empty traffic metrics.');

    $stats = $newStats();
    $stats->response = '{"rows":[]}';
    check($stats->getDashboardData()['clicks'] === 0 && $stats->cache !== [], 'Successful zero traffic was not accepted.');

    $insights = $newStats();
    check($insights->getInsightsData(28, 'pages')['auto'] && !$insights->requests, 'A cold page view contacted Google.');
    $insights->response = '{"rows":[{"keys":["https://example.test/test/"],"clicks":7}]}';
    $snapshot = $insights->getInsightsData(28, 'pages', 'auto');
    check(!$snapshot['stale'] && $snapshot['data']['pages'][0]['clicks'] === 7, 'Background refresh failed to populate the view.');
    check(count($insights->requests) === 3 && $insights->requests[2]['rowLimit'] === 250, 'Detail fetch was not bounded.');
    $insights = $newStats();
    check($insights->getInsightsData(28, 'pages')['data']['pages'][0]['clicks'] === 7 && !$insights->requests, 'Warm page did not use the cache.');
    foreach ($insightsCache->data as &$cached) {
        $cached['entries']['pages']['at'] -= IchibanSearchStatistics::CACHE_TTL;
        $cached['attempted'] -= IchibanSearchStatistics::CACHE_TTL;
    }
    unset($cached);
    $snapshot = $insights->getInsightsData(28, 'pages', 'auto');
    check($snapshot['stale'] && $snapshot['error'] !== '' && $snapshot['data']['pages'][0]['clicks'] === 7, 'Google failure discarded the last good data.');
    $insights = $newStats();
    check(!$insights->getInsightsData(28, 'pages', 'auto')['auto'] && !$insights->requests, 'Failed refresh retried during cooldown.');
    check(($insights->getInsightsData(90, 'pages')['data']['pages'] ?? null) === null, 'Period cache was reused for another period.');

    parse_str(parse_url($stats->getAuthUrl(), PHP_URL_QUERY), $query);
    $state = $query['state'];
    check(!$stats->handleCallback('code', 'other-state') && \ProcessWire\WireHttp::$calls === 0, 'Mismatched state reached Google.');
    \ProcessWire\WireHttp::$reply = ['access_token' => 'incomplete'];
    check(!$stats->handleCallback('code', $state) && $modules->config['gsc_refresh_token'] === 'old-refresh', 'Incomplete grant replaced the working credentials.');
    check(!$stats->handleCallback('code', $state) && \ProcessWire\WireHttp::$calls === 1, 'OAuth state was replayable.');
    parse_str(parse_url($stats->getAuthUrl(), PHP_URL_QUERY), $query);
    $session->data['ichiban_gsc_oauth']['expires'] = time() - 1;
    check(!$stats->handleCallback('code', $query['state']) && \ProcessWire\WireHttp::$calls === 1, 'Expired state reached Google.');
    parse_str(parse_url($stats->getAuthUrl(), PHP_URL_QUERY), $query);
    \ProcessWire\WireHttp::$reply = ['access_token' => 'new-access', 'refresh_token' => 'new-refresh'];
    check($stats->handleCallback('code', $query['state']) && $modules->config['gsc_refresh_token'] === 'new-refresh', 'Valid grant was not saved.');
    echo "Search Console failure and OAuth state checks passed.\n";
}
