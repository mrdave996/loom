<?php

declare(strict_types=1);

namespace Loom\Importer;

/**
 * Parse a static HTML site directory into Loom's standard import format.
 *
 * Implements the ContentParser interface so it plugs into the existing
 * Importer pipeline alongside WpXmlParser and WpSqlParser.
 *
 * Orchestrates focused helper classes for page discovery, metadata extraction,
 * navigation extraction, content extraction, asset handling, and CSS extraction.
 */
class HtmlParser implements ContentParser
{
	private HtmlPageDiscoverer $discoverer;
	private HtmlMetaExtractor $metaExtractor;
	private HtmlNavExtractor $navExtractor;
	private HtmlContentExtractor $contentExtractor;
	private HtmlAssetHandler $assetHandler;
	private HtmlCssExtractor $cssExtractor;

	public function __construct()
	{
		$this->discoverer = new HtmlPageDiscoverer();
		$this->metaExtractor = new HtmlMetaExtractor();
		$this->navExtractor = new HtmlNavExtractor();
		$this->contentExtractor = new HtmlContentExtractor();
		$this->assetHandler = new HtmlAssetHandler();
		$this->cssExtractor = new HtmlCssExtractor();
	}

	/**
	 * Parse a static HTML site directory and return normalized data.
	 *
	 * @param string $source Absolute path to the static HTML site root directory
	 * @return array{pages: array, posts: array, menus: array, media: array, options: array, forms: array, theme: array{name: string, global_styles: array|null, css: string}}
	 */
	public function parse(string $source): array
	{
		$source = rtrim($source, '/');

		echo "  Scanning HTML files...\n";

		// 1. Discover all HTML pages
		$discovered = $this->discoverer->discover($source);
		echo "  Found " . count($discovered) . " HTML pages\n";

		if (empty($discovered)) {
			return $this->emptyResult();
		}

		// 2. Read all HTML files and extract metadata
		$allHtml = [];
		$allHtmlForNav = [];
		$siteName = '';
		$favicon = '';

		foreach ($discovered as &$page) {
			$html = file_get_contents($page['path']);
			if ($html === false) continue;

			$meta = $this->metaExtractor->extractAll($html);
			$page['html'] = $html;
			$page['meta'] = $meta;

			$allHtml[] = $html;
			$allHtmlForNav[] = $html;

			// Use the first page's site name
			if (empty($siteName) && !empty($meta['site_name'])) {
				$siteName = $meta['site_name'];
			}

			// Use the first favicon found
			if (empty($favicon) && !empty($meta['favicon'])) {
				$favicon = $meta['favicon'];
			}
		}
		unset($page);

		// 3. Detect shared nav/footer chrome
		echo "  Detecting shared navigation...\n";
		$sharedNav = $this->navExtractor->detectSharedNav($allHtmlForNav);
		$sharedFooter = $this->navExtractor->detectSharedFooter($allHtmlForNav);

		// 4. Extract nav and footer from the first page (homepage)
		$homepageHtml = $allHtmlForNav[0] ?? '';
		$navLinks = $this->navExtractor->extractNav($homepageHtml);
		$footerLinks = $this->navExtractor->extractFooter($homepageHtml);

		echo "  " . count($navLinks) . " nav links, " . count($footerLinks) . " footer columns\n";

		// 5. Build the pages array
		$pages = [];
		$allForms = [];

		foreach ($discovered as $page) {
			if (!isset($page['html'])) continue;

			$html = $page['html'];
			$meta = $page['meta'];
			$slug = $page['slug'];

			// Extract main content
			$content = $this->contentExtractor->extractMainContent($html, $sharedNav, $sharedFooter);
			$content = $this->contentExtractor->stripScripts($content);

			// Extract and replace forms
			$forms = $this->contentExtractor->extractForms($content);
			if (!empty($forms)) {
				$content = $this->contentExtractor->replaceFormsWithPlaceholders($content, $forms);
				foreach ($forms as $form) {
					$allForms[$form['id']] = $form;
				}
			}

			// Determine template from LD+JSON or page structure
			$template = $this->detectTemplate($meta, $html);

			// Determine date
			$date = $this->metaExtractor->extractDatePublished($html);
			if (empty($date)) {
				$date = date('Y-m-d', filemtime($page['path']));
			}

			// Build the page entry
			$pageEntry = [
				'slug' => $slug,
				'title' => $meta['title'] ?: $this->slugToTitle($slug),
				'content' => $content,
				'excerpt' => $meta['description'] ?? '',
				'date' => $date,
				'template' => $template,
				'link' => $meta['canonical'] ?? '/' . $slug,
				'meta' => $meta,
				'forms' => $forms,
			];

			$pages[] = $pageEntry;
		}

		// 6. Discover assets
		echo "  Discovering assets...\n";
		$htmlPaths = array_column($discovered, 'path');
		$media = $this->assetHandler->discoverAssets($source, $htmlPaths);
		echo "  Found " . count($media) . " assets\n";

		// 7. Extract CSS
		echo "  Extracting CSS...\n";
		$css = $this->cssExtractor->extract($source, $htmlPaths);
		$cssLength = strlen($css);
		echo "  " . round($cssLength / 1024, 1) . " KB of CSS extracted\n";

		// 8. Build menus from nav links (format compatible with NavigationBuilder)
		$menus = [];
		if (!empty($navLinks)) {
			$menus[] = [
				'label' => 'Primary',
				'children' => $navLinks,
			];
		}
		if (!empty($footerLinks)) {
			// Flatten footer columns into a flat link list for NavigationBuilder
			$footerFlat = [];
			foreach ($footerLinks as $column) {
				foreach ($column['links'] ?? [] as $link) {
					$footerFlat[] = $link;
				}
			}
			if (!empty($footerFlat)) {
				$menus[] = [
					'label' => 'Footer',
					'children' => $footerFlat,
				];
			}
		}

		// 9. Build options
		$options = [
			'site_title' => $siteName,
			'site_url' => '',
			'blogdescription' => '',
		];

		// 10. Build theme data
		$theme = [
			'name' => 'static-html',
			'global_styles' => null,
			'css' => $css,
		];

		// 11. Pre-resolved nav and footer links (bypass NavigationBuilder)
		// Store in options so Importer can use them directly for HTML imports
		$options['nav_links'] = $navLinks;
		$options['footer_links'] = $this->buildFooterColumns($footerLinks);

		return [
			'pages' => $pages,
			'posts' => [],
			'menus' => $menus,
			'media' => $media,
			'options' => $options,
			'forms' => array_values($allForms),
			'theme' => $theme,
		];
	}

	/**
	 * Detect the best Loom template for a page based on its metadata and structure.
	 */
	private function detectTemplate(array $meta, string $html): string
	{
		// Check LD+JSON for page type hints
		$ldJson = $meta['ld_json'] ?? [];
		foreach ($ldJson as $item) {
			$type = $item['@type'] ?? '';
			if (is_array($type)) $type = $type[0] ?? '';

			// FAQ pages get pillar template
			if ($type === 'FAQPage') return 'pillar';

			// Articles and HowTo get pillar template
			if (in_array($type, ['Article', 'HowTo', 'WebPage'])) return 'pillar';
		}

		// Pages with FAQ sections get pillar
		if (str_contains($html, 'faq-list') || str_contains($html, 'faq-item')) {
			return 'pillar';
		}

		return 'default';
	}

	/**
	 * Convert a slug to a human-readable title.
	 */
	private function slugToTitle(string $slug): string
	{
		if (empty($slug)) return 'Home';

		// Take the last segment
		$parts = explode('/', $slug);
		$last = end($parts);

		// Convert dashes to spaces, capitalize
		return ucwords(str_replace('-', ' ', $last));
	}

	/**
	 * Convert footer column data to the format expected by Loom's footer partial.
	 *
	 * Input: [{label, links: [{label, url}]}]
	 * Output: same format (already compatible, but ensure structure)
	 */
	private function buildFooterColumns(array $footerLinks): array
	{
		$columns = [];
		foreach ($footerLinks as $column) {
			$label = $column['label'] ?? '';
			$links = $column['links'] ?? [];
			if (empty($label) || empty($links)) continue;

			$columns[] = [
				'label' => $label,
				'links' => $links,
			];
		}
		return $columns;
	}

	/**
	 * Return an empty result set.
	 */
	private function emptyResult(): array
	{
		return [
			'pages' => [],
			'posts' => [],
			'menus' => [],
			'media' => [],
			'options' => ['site_title' => '', 'site_url' => '', 'blogdescription' => ''],
			'forms' => [],
			'theme' => ['name' => 'static-html', 'global_styles' => null, 'css' => ''],
		];
	}
}
