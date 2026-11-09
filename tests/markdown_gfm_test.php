<?php

declare(strict_types=1);

// GFM regression test: pipe tables must render as <table>, not literal pipe text.
// Run with composer deps installed:
//   php tests/markdown_gfm_test.php [path/to/vendor/autoload.php]

use Loom\ContentLoader;

require_once $argv[1] ?? __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/ContentLoader.php';

$loader = new ContentLoader();

$table = "| Endpoint | Method |\n|---|---|\n| /api/services | GET |\n";
$html = $loader->convertToHtml($table);
foreach (['<table>', '<th>Endpoint</th>', '<td>/api/services</td>'] as $needle) {
	if (!str_contains($html, $needle)) {
		throw new RuntimeException("Expected pipe table to render {$needle}, got: {$html}");
	}
}

$inline = $loader->convertToHtml('~~struck~~ and https://example.test');
if (!str_contains($inline, '<del>struck</del>')) {
	throw new RuntimeException("Expected GFM strikethrough, got: {$inline}");
}
if (!preg_match('/<a href="https:\/\/example\.test">/', $inline)) {
	throw new RuntimeException("Expected GFM autolink, got: {$inline}");
}

// Raw HTML must still pass through unescaped: consumers embed iframes etc.
// (GithubFlavoredMarkdownExtension's bundled DisallowedRawHtml would break them).
$raw = $loader->convertToHtml('<iframe src="https://maps.example.test/embed"></iframe>');
if (!str_contains($raw, '<iframe src="https://maps.example.test/embed">')) {
	throw new RuntimeException("Expected raw HTML pass-through, got: {$raw}");
}

echo "markdown_gfm_test: PASS\n";
