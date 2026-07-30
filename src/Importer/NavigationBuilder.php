<?php

declare(strict_types=1);

namespace Loom\Importer;

/**
 * Build navigation data from WordPress menu data.
 *
 * Returns nav_links arrays for page front matter rather than
 * generating a standalone nav.php file. The nav.php partial
 * reads from $page['nav_links'] at render time.
 */
class NavigationBuilder
{
	private string $outputDir;

	public function __construct(string $outputDir)
	{
		$this->outputDir = rtrim($outputDir, '/');
	}

	/**
	 * Build navigation links from menu data or page list.
	 *
	 * @param array $menus Menu tree from ContentParser
	 * @param array $urlMap URL mapping for rewriting old URLs to new paths
	 * @param array $pages Parsed pages (fallback when menus are empty)
	 * @param string $frontPageSlug Slug of the front page (mapped to '/')
	 * @return array Array of ['label' => string, 'url' => string] items
	 */
	public function build(array $menus, array $urlMap = [], array $pages = [], string $frontPageSlug = ''): array
	{
		// If no menus, fall back to building nav from pages
		if (empty($menus) && !empty($pages)) {
			$menuItems = [];
			foreach ($pages as $page) {
				$slug = $page['slug'] ?? '';
				$title = $page['title'] ?? '';
				if (empty($slug) || empty($title)) continue;

				// Front page maps to root URL
				$url = ($slug === $frontPageSlug) ? '/' : '/' . $slug;

				$menuItems[] = [
					'label' => $title,
					'url' => $url,
				];
			}
			return $menuItems;
		}

		// Use the first menu (usually "primary")
		$menuItems = $menus[0]['children'] ?? $menus[0] ?? [];
		if (isset($menus[0]) && !isset($menus[0]['children'])) {
			$menuItems = [$menus[0]];
		}

		// Flatten if needed
		if (isset($menus[0]) && is_array($menus[0])) {
			$menuItems = $menus;
		}

		// Map URLs, skip empty labels
		$result = [];
		foreach ($menuItems as $item) {
			$label = trim($item['label'] ?? '');
			if (empty($label)) continue;

			$url = $this->mapUrl($item['url'] ?? '/', $urlMap);
			$result[] = [
				'label' => $label,
				'url' => $url,
			];
		}

		return $result;
	}

	/**
	 * Build footer navigation links.
	 *
	 * Uses the second WordPress menu (usually "footer") if available,
	 * otherwise falls back to the first menu.
	 */
	public function buildFooter(array $menus, array $urlMap = [], array $pages = [], string $frontPageSlug = ''): array
	{
		// If multiple menus, use the second one for footer
		if (count($menus) > 1) {
			$menuItems = $menus[1]['children'] ?? $menus[1] ?? [];
			if (isset($menus[1]) && !isset($menus[1]['children'])) {
				$menuItems = [$menus[1]];
			}
			if (isset($menus[1]) && is_array($menus[1])) {
				$menuItems = $menus;
				// Use only the second menu's items
				$menuItems = isset($menus[1]) ? [$menus[1]] : [];
			}

			$result = [];
			foreach ($menuItems as $item) {
				$label = trim($item['label'] ?? '');
				if (empty($label)) continue;
				$url = $this->mapUrl($item['url'] ?? '/', $urlMap);
				$result[] = ['label' => $label, 'url' => $url];
			}

			if (!empty($result)) return $result;
		}

		// Fallback: use nav links for footer
		return $this->build($menus, $urlMap, $pages, $frontPageSlug);
	}

	/**
	 * Map an old WordPress URL to a new Loom path.
	 */
	private function mapUrl(string $url, array $urlMap): string
	{
		// Check if we have a mapping
		if (isset($urlMap[$url])) {
			return $urlMap[$url];
		}

		// Bail early for non-HTTP schemes — don't parse as paths
		$parts = parse_url($url);
		$scheme = strtolower($parts['scheme'] ?? '');
		if (in_array($scheme, ['tel', 'mailto', 'javascript', 'data'])) {
			// Clean up spaces that WordPress inserts (e.g., "tel: 1300 858 751")
			return preg_replace('/\s+/', '', $url);
		}

		// Parse the URL and try to find a matching slug
		$path = $parts['path'] ?? '/';
		$path = rtrim($path, '/');

		if (empty($path) || $path === '/') {
			return '/';
		}

		// Extract the slug from the path
		$slug = basename($path);

		return '/' . $slug;
	}
}
