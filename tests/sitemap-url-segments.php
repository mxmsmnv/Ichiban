<?php

class TestSanitizer {
    public function name(string $value): string {
        return preg_replace('/[^a-zA-Z0-9_-]/', '', $value);
    }
}

class TestIchiban {
    public function canonicalUrl(string $url): string {
        return $url;
    }

    public function wire(string $name): mixed {
        return $name === 'sanitizer' ? new TestSanitizer() : null;
    }
}

require dirname(__DIR__) . '/src/Sitemap/Sitemap.php';

class TestIchibanSitemap extends IchibanSitemap {
    public function segment(string $base, array $entry, mixed $segment): ?array {
        return $this->buildUrlSegmentEntry($base, $entry, $segment);
    }
}

$serviceSource = (string)file_get_contents(dirname(__DIR__) . '/src/Sitemap/Sitemap.php');
$moduleSource = (string)file_get_contents(dirname(__DIR__) . '/Ichiban.module.php');
$entry = [
    'lastmod' => '2026-08-25',
    'changefreq' => 'weekly',
    'priority' => '0.5',
    'template' => 'blog-authors',
];
$sitemap = new TestIchibanSitemap(new TestIchiban());
$author = $sitemap->segment('https://example.com/blog/authors/', $entry, [
    'segment' => 'joe-bloggs/',
    'lastmod' => '2026-08-24T12:00:00-04:00',
]);
$pageTwo = $sitemap->segment('https://example.com/blog/', $entry, 'page2/');

$checks = [
    str_contains($serviceSource, '$page->template->urlSegments || $page->template->allowPageNum'),
    str_contains($moduleSource, '___collectSitemapUrlSegments(Page $page)'),
    ($author['loc'] ?? null) === 'https://example.com/blog/authors/joe-bloggs/',
    ($author['lastmod'] ?? null) === '2026-08-24T12:00:00-04:00',
    ($pageTwo['loc'] ?? null) === 'https://example.com/blog/page2/',
];

if (in_array(false, $checks, true)) {
    fwrite(STDERR, "Ichiban dynamic route contract failed.\n");
    exit(1);
}

echo "Ichiban dynamic route contract passed.\n";
