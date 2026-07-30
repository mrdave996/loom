<?php

declare(strict_types=1);

namespace Loom\Importer;

/**
 * Discover HTML pages in a static site directory.
 *
 * Recursively scans for .html files, converts folder-based URLs to slugs,
 * and optionally uses sitemap.xml for page ordering.
 */
class HtmlPageDiscoverer
{
	/**
	 * Discover all HTML files in the source directory.
	 *
	 * @param string $sourceDir Absolute path to the static HTML site root
	 * @return array<int, array{path: string, slug: string, depth: int}>
	 */
	public function discover(string $sourceDir): array
	{
		$sourceDir = rtrim($sourceDir, '/');
		$pages = [];

		// Walk the directory tree for .html files
		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($sourceDir, \RecursiveDirectoryIterator::SKIP_DOTS),
			\RecursiveIteratorIterator::SELF_FIRST
		);

		foreach ($iterator as $file) {
			if (!$file->isFile()) continue;
			if ($file->getExtension() !== 'html') continue;

			$relativePath = substr($file->getPathname(), strlen($sourceDir) + 1);

			// Skip underscore-prefixed directories (template/partial dirs)
			$segments = explode('/', $relativePath);
			$skip = false;
			foreach ($segments as $seg) {
				if (str_starts_with($seg, '_')) {
					$skip = true;
					break;
				}
			}
			if ($skip) continue;

			// Convert file path to URL slug
			$slug = $this->pathToSlug($relativePath);
			if ($slug === null) continue;

			$depth = substr_count($slug, '/');

			$pages[] = [
				'path' => $file->getPathname(),
				'slug' => $slug,
				'depth' => $depth,
			];
		}

		// Sort by depth (shallowest first), then by slug
		usort($pages, function (array $a, array $b): int {
			if ($a['depth'] !== $b['depth']) {
				return $a['depth'] - $b['depth'];
			}
			return strcmp($a['slug'], $b['slug']);
		});

		// If sitemap.xml exists, use it for ordering
		$sitemapPath = $sourceDir . '/sitemap.xml';
		if (file_exists($sitemapPath)) {
			$pages = $this->orderBySitemap($pages, $sitemapPath);
		}

		return $pages;
	}

	/**
	 * Convert a file path to a URL slug.
	 *
	 * Rules:
	 * - index.html at root → '' (homepage)
	 * - about/index.html → 'about'
	 * - resources/google-ads/index.html → 'resources/google-ads'
	 * - standalone.html → 'standalone'
	 *
	 * Returns null for files that should be skipped.
	 */
	private function pathToSlug(string $relativePath): ?string
	{
		// Normalize separators
		$relativePath = str_replace('\\', '/', $relativePath);

		// Remove .html extension
		$withoutExt = preg_replace('/\.html$/', '', $relativePath);

		// Handle index.html → directory slug
		if (str_ends_with($withoutExt, '/index')) {
			$slug = substr($withoutExt, 0, -6); // remove '/index'
		} elseif ($withoutExt === 'index') {
			// Root index.html = homepage
			$slug = '';
		} else {
			$slug = $withoutExt;
		}

		// Clean up
		$slug = trim($slug, '/');

		return $slug;
	}

	/**
	 * Reorder pages to match sitemap.xml ordering.
	 * Pages not in the sitemap are appended at the end.
	 */
	private function orderBySitemap(array $pages, string $sitemapPath): array
	{
		$xml = @simplexml_load_file($sitemapPath);
		if ($xml === false) return $pages;

		// Extract URLs from sitemap
		$sitemapUrls = [];
		$ns = $xml->getNamespaces(true);

		foreach ($xml->url as $urlNode) {
			$loc = (string) $urlNode->loc;
			// Extract path from URL
			$path = parse_url($loc, PHP_URL_PATH) ?? '/';
			$path = trim($path, '/');
			if (empty($path)) $path = '';
			$sitemapUrls[] = $path;
		}

		if (empty($sitemapUrls)) return $pages;

		// Build slug → page lookup
		$pageBySlug = [];
		foreach ($pages as $page) {
			$pageBySlug[$page['slug']] = $page;
		}

		// Reorder: sitemap pages first (in sitemap order), then remaining
		$ordered = [];
		$seen = [];

		foreach ($sitemapUrls as $url) {
			if (isset($pageBySlug[$url])) {
				$ordered[] = $pageBySlug[$url];
				$seen[$url] = true;
			}
		}

		// Append any pages not in the sitemap
		foreach ($pages as $page) {
			if (!isset($seen[$page['slug']])) {
				$ordered[] = $page;
			}
		}

		return $ordered;
	}
}
