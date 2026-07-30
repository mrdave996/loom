<?php

declare(strict_types=1);

namespace Loom;

use Symfony\Component\DomCrawler\Crawler;
use League\HTMLToMarkdown\HtmlConverter;

/**
 * Generic site scraper for Loom.
 *
 * Takes a directory of HTML files (from wget/mirror) and converts them
 * into Loom markdown content with proper front matter.
 *
 * Site config keys:
 *   site_url        — base URL of the site (e.g. https://example.com)
 *   site_name       — display name
 *   scrape_dir      — absolute path to the wget mirror directory
 *   nav_selector    — CSS selector for the main navigation element
 *   footer_selector — CSS selector for the footer element
 *   strip_selectors — array of CSS selectors to remove from content
 *   url_to_slug     — map of full URLs to output slugs (optional, auto-detected if missing)
 *   nav_links       — hardcoded nav structure (optional, extracted from HTML if missing)
 */
class Scraper
{
	private string $rootDir;
	private array $config;
	private HtmlConverter $htmlConverter;
	private array $urlToSlug = [];
	private array $extractedNavLinks = [];
	private array $extractedFooterLinks = [];
	private int $pageCount = 0;
	private int $imageCount = 0;
	private array $errors = [];

	public function __construct(string $rootDir, array $config)
	{
		$this->rootDir = rtrim($rootDir, '/');
		$this->config = $config;
		$this->htmlConverter = new HtmlConverter([
			'header_style'    => 'atx',
			'bold_style'      => '**',
			'italic_style'    => '*',
			'list_item_style' => '-',
			'strip_tags'      => 'script,style,noscript',
			'remove_nodes'    => 'script,style,noscript',
		]);

		// Merge hardcoded url_to_slug map if provided
		if (!empty($config['url_to_slug'])) {
			$this->urlToSlug = $config['url_to_slug'];
		}
	}

	/**
	 * Run the full scrape pipeline.
	 */
	public function scrape(): array
	{
		$scrapeDir = $this->config['scrape_dir'] ?? '';
		if (empty($scrapeDir) || !is_dir($scrapeDir)) {
			throw new \RuntimeException("Scrape directory not found: {$scrapeDir}");
		}

		// Resolve symlinks (macOS /tmp → /private/tmp)
		$scrapeDir = realpath($scrapeDir) ?: $scrapeDir;

		echo "Loom Scrape\n";
		echo str_repeat('─', 50) . "\n\n";

		// Step 1: Discover HTML files
		echo "Step 1: Discovering pages...\n";
		$htmlFiles = $this->findHtmlFiles($scrapeDir);
		echo "  Found " . count($htmlFiles) . " HTML files\n";

		if (empty($htmlFiles)) {
			echo "  No HTML files found. Did you run scrape-site.sh first?\n";
			return ['pages' => 0, 'images' => 0, 'errors' => ['No HTML files found']];
		}

		// Step 2: Process pages
		echo "\nStep 2: Processing pages...\n";
		$this->ensureDirectories();

		foreach ($htmlFiles as $filePath) {
			$this->processPage($filePath, $scrapeDir);
		}

		// Step 3: Use hardcoded nav_links if provided, otherwise use extracted
		echo "\nStep 3: Building navigation...\n";
		$navLinks = !empty($this->config['nav_links']) ? $this->config['nav_links'] : $this->extractedNavLinks;
		$footerLinks = $this->extractedFooterLinks;
		echo "  " . count($navLinks) . " nav links, " . count($footerLinks) . " footer links\n";

		// Step 4: Copy CSS assets
		echo "\nStep 4: Copying assets...\n";
		$this->copyCssAssets($scrapeDir);

		// Step 5: Copy images
		echo "\nStep 5: Migrating images...\n";
		$this->copyImageAssets($scrapeDir);

		// Step 6: Inject nav/footer into front page
		echo "\nStep 6: Injecting navigation into pages...\n";
		$this->injectNavigation($navLinks, $footerLinks);

		// Summary
		echo "\n" . str_repeat('─', 50) . "\n";
		echo "✓ Scrape complete!\n";
		echo "  Pages: {$this->pageCount}\n";
		echo "  Images: {$this->imageCount}\n";
		if (!empty($this->errors)) {
			echo "  Errors: " . count($this->errors) . "\n";
			foreach ($this->errors as $err) {
				echo "    ✗ {$err}\n";
			}
		}
		echo "\nRun 'php loom verify' to check the output.\n";
		echo "Run 'php -S localhost:8080 -t public/' to preview.\n";

		return [
			'pages'  => $this->pageCount,
			'images' => $this->imageCount,
			'errors' => $this->errors,
		];
	}

	/**
	 * Find all HTML files in the scrape directory.
	 */
	private function findHtmlFiles(string $dir): array
	{
		$files = [];
		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
			\RecursiveIteratorIterator::SELF_FIRST
		);

		foreach ($iterator as $file) {
			if ($file->isFile() && in_array(strtolower($file->getExtension()), ['html', 'htm'])) {
				$files[] = $file->getRealPath();
			}
		}

		sort($files);
		return $files;
	}

	/**
	 * Process a single HTML file into a Loom markdown page.
	 */
	private function processPage(string $filePath, string $scrapeDir): void
	{
		$realPath = realpath($filePath) ?: $filePath;
		$relativePath = str_replace($scrapeDir . '/', '', $realPath);
		$html = file_get_contents($filePath);

		if ($html === false || empty(trim($html))) {
			$this->errors[] = "{$relativePath}: Empty or unreadable";
			return;
		}

		// Determine the URL from file path
		$url = $this->filePathToUrl($filePath, $scrapeDir);
		$slug = $this->urlToSlug($url);

		if (empty($slug)) {
			echo "  ⚠ {$relativePath}: Could not determine slug, skipping\n";
			return;
		}

		// Parse HTML
		$crawler = new Crawler($html, $url);

		// Extract page title
		$title = $this->extractTitle($crawler);

		// Extract meta description
		$description = $this->extractDescription($crawler);

		// Extract nav links (first page only, then reuse)
		if (empty($this->extractedNavLinks)) {
			$this->extractedNavLinks = $this->extractNavLinks($crawler);
		}

		// Extract footer links (first page only)
		if (empty($this->extractedFooterLinks)) {
			$this->extractedFooterLinks = $this->extractFooterLinks($crawler);
		}

		// Strip boilerplate and extract main content
		$contentHtml = $this->extractContent($crawler);

		if (empty(trim($contentHtml))) {
			echo "  ⚠ {$relativePath}: No content extracted\n";
			return;
		}

		// Convert HTML to markdown
		$markdown = $this->htmlConverter->convert($contentHtml);

		// Clean up excessive whitespace
		$markdown = preg_replace('/\n{3,}/', "\n\n", $markdown);
		$markdown = trim($markdown);

		// Build front matter
		$frontMatter = [
			'title'       => $title,
			'description' => $description,
			'template'    => 'default',
			'date'        => date('Y-m-d'),
		];

		// Write the file
		$outputDir = $this->rootDir . '/content/pages';
		$outputFile = $outputDir . '/' . $slug . '.md';

		// Ensure parent directory exists for nested slugs
		$outputParent = dirname($outputFile);
		if (!is_dir($outputParent)) {
			mkdir($outputParent, 0755, true);
		}

		$yaml = \Symfony\Component\Yaml\Yaml::dump($frontMatter, 2, 4, \Symfony\Component\Yaml\Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK);
		$fileContent = "---\n{$yaml}---\n\n{$markdown}";

		file_put_contents($outputFile, $fileContent);
		$this->pageCount++;

		echo "  ✓ {$relativePath} → content/pages/{$slug}.md\n";
	}

	/**
	 * Convert a file path to a site URL.
	 */
	private function filePathToUrl(string $filePath, string $scrapeDir): string
	{
		$realPath = realpath($filePath) ?: $filePath;
		$relativePath = str_replace($scrapeDir . '/', '', $realPath);
		$siteUrl = rtrim($this->config['site_url'] ?? '', '/');

		// Strip index.html / index.htm
		$relativePath = preg_replace('#/index\.html?$#', '/', $relativePath);
		$relativePath = preg_replace('#^index\.html?$#', '', $relativePath);

		return $siteUrl . '/' . $relativePath;
	}

	/**
	 * Map a URL to a output slug.
	 */
	private function urlToSlug(string $url): string
	{
		// Check explicit map first
		if (!empty($this->urlToSlug[$url])) {
			return $this->urlToSlug[$url];
		}

		// Strip trailing slash and query string
		$path = parse_url($url, PHP_URL_PATH) ?? '/';
		$path = rawurldecode($path);
		$path = rtrim($path, '/');

		$siteUrl = rtrim($this->config['site_url'] ?? '', '/');
		$basePath = parse_url($siteUrl, PHP_URL_PATH) ?? '';
		$basePath = rtrim($basePath, '/');

		// Strip the site's base path
		if (!empty($basePath) && str_starts_with($path, $basePath)) {
			$path = substr($path, strlen($basePath));
		}

		$path = ltrim($path, '/');

		// Strip .html extension
		$path = preg_replace('/\.html?$/', '', $path);

		// Map root to index
		if (empty($path) || $path === '/') {
			return 'index';
		}

		// Sanitize: remove dots, collapse slashes
		$path = preg_replace('#\.{2,}#', '.', $path);
		$path = preg_replace('#/+#', '/', $path);
		$path = trim($path, '/');

		return $path;
	}

	/**
	 * Extract the page title.
	 */
	private function extractTitle(Crawler $crawler): string
	{
		// Try <title> tag
		try {
			$title = $crawler->filter('title')->text('');
			if (!empty(trim($title))) {
				// Strip site name suffix (e.g. "Page Name – Site Name")
				$separator = ' – ';
				if (str_contains($title, $separator)) {
					$title = explode($separator, $title)[0];
				} elseif (str_contains($title, ' | ')) {
					$title = explode(' | ', $title)[0];
				} elseif (str_contains($title, ' - ')) {
					$title = explode(' - ', $title)[0];
				}
				return trim($title);
			}
		} catch (\Exception $e) {}

		// Try first h1
		try {
			return trim($crawler->filter('h1')->first()->text(''));
		} catch (\Exception $e) {}

		return 'Untitled';
	}

	/**
	 * Extract meta description.
	 */
	private function extractDescription(Crawler $crawler): string
	{
		try {
			return trim($crawler->filter('meta[name="description"]')->attr('content') ?? '');
		} catch (\Exception $e) {}

		try {
			return trim($crawler->filter('meta[property="og:description"]')->attr('content') ?? '');
		} catch (\Exception $e) {}

		return '';
	}

	/**
	 * Extract navigation links from the page using the configured selector.
	 */
	private function extractNavLinks(Crawler $crawler): array
	{
		$selector = $this->config['nav_selector'] ?? '';
		if (empty($selector)) return [];

		$links = [];
		try {
			// Try each selector (comma-separated)
			$selectors = array_map('trim', explode(',', $selector));
			foreach ($selectors as $sel) {
				$nav = $crawler->filter($sel);
				if ($nav->count() > 0) {
					$nav->filter('a')->each(function (Crawler $a) use (&$links) {
						$href = $a->attr('href') ?? '';
						$label = trim($a->text(''));
						if (!empty($href) && !empty($label) && !str_starts_with($href, '#') && !str_starts_with($href, 'javascript:')) {
							// Make relative URLs absolute
							if (!str_starts_with($href, 'http') && !str_starts_with($href, '//')) {
								$siteUrl = rtrim($this->config['site_url'] ?? '', '/');
								$href = $siteUrl . '/' . ltrim($href, '/');
							}
							// Convert to local slug
							$slug = $this->urlToSlug($href);
							$linkUrl = ($slug === 'index') ? '/' : '/' . $slug;
							$links[] = [
								'label' => $label,
								'url'   => $linkUrl,
							];
						}
					});
					break; // Use first matching selector
				}
			}
		} catch (\Exception $e) {}

		// Deduplicate by URL
		$seen = [];
		$unique = [];
		foreach ($links as $link) {
			if (!isset($seen[$link['url']])) {
				$seen[$link['url']] = true;
				$unique[] = $link;
			}
		}

		return $unique;
	}

	/**
	 * Extract footer links from the page.
	 */
	private function extractFooterLinks(Crawler $crawler): array
	{
		$selector = $this->config['footer_selector'] ?? '';
		if (empty($selector)) return [];

		$columns = [];
		try {
			$selectors = array_map('trim', explode(',', $selector));
			foreach ($selectors as $sel) {
				$footer = $crawler->filter($sel);
				if ($footer->count() > 0) {
					// Try to find nav groups within footer
					$footer->filter('nav, .footer-col, .widget, ul')->each(function (Crawler $group) use (&$columns) {
						$heading = '';
						try {
							$heading = trim($group->filter('h2, h3, h4, .widget-title')->first()->text(''));
						} catch (\Exception $e) {}

						$links = [];
						$group->filter('a')->each(function (Crawler $a) use (&$links) {
							$href = $a->attr('href') ?? '';
							$label = trim($a->text(''));
							if (!empty($href) && !empty($label) && !str_starts_with($href, '#') && !str_starts_with($href, 'javascript:')) {
								if (!str_starts_with($href, 'http') && !str_starts_with($href, '//')) {
									$siteUrl = rtrim($this->config['site_url'] ?? '', '/');
									$href = $siteUrl . '/' . ltrim($href, '/');
								}
								$slug = $this->urlToSlug($href);
								$linkUrl = ($slug === 'index') ? '/' : '/' . $slug;
								$links[] = [
									'label' => $label,
									'url'   => $linkUrl,
								];
							}
						});

						if (!empty($links)) {
							$columns[] = [
								'label' => $heading ?: 'Links',
								'links' => $links,
							];
						}
					});
					break;
				}
			}
		} catch (\Exception $e) {}

		return $columns;
	}

	/**
	 * Strip boilerplate elements and extract main content HTML.
	 */
	private function extractContent(Crawler $crawler): string
	{
		// Clone the crawler so we don't mutate the original
		$contentCrawler = clone $crawler;

		// Remove configured strip_selectors
		$stripSelectors = $this->config['strip_selectors'] ?? [];
		foreach ($stripSelectors as $selector) {
			try {
				$contentCrawler->filter($selector)->each(function (Crawler $node) {
					$node->getNode(0)->parentNode->removeChild($node->getNode(0));
				});
			} catch (\Exception $e) {}
		}

		// Remove nav and footer (they're extracted separately)
		$navSelector = $this->config['nav_selector'] ?? '';
		$footerSelector = $this->config['footer_selector'] ?? '';

		if (!empty($navSelector)) {
			foreach (explode(',', $navSelector) as $sel) {
				try {
					$contentCrawler->filter(trim($sel))->each(function (Crawler $node) {
						$node->getNode(0)->parentNode->removeChild($node->getNode(0));
					});
				} catch (\Exception $e) {}
			}
		}

		if (!empty($footerSelector)) {
			foreach (explode(',', $footerSelector) as $sel) {
				try {
					$contentCrawler->filter(trim($sel))->each(function (Crawler $node) {
						$node->getNode(0)->parentNode->removeChild($node->getNode(0));
					});
				} catch (\Exception $e) {}
			}
		}

		// Also remove common boilerplate
		$commonStrips = [
			'header', 'nav', 'footer',
			'.site-header', '.site-footer', '.site-navigation',
			'#wpadminbar', '.admin-bar',
			'script', 'style', 'noscript',
			'[role="navigation"]',
		];
		foreach ($commonStrips as $sel) {
			try {
				$contentCrawler->filter($sel)->each(function (Crawler $node) {
					$node->getNode(0)->parentNode->removeChild($node->getNode(0));
				});
			} catch (\Exception $e) {}
		}

		// Try to find main content area
		$contentSelectors = [
			'main',
			'[role="main"]',
			'.site-content',
			'.content-area',
			'.page-content',
			'article',
			'#content',
			'.entry-content',
			'.wp-block-post-content',
		];

		foreach ($contentSelectors as $sel) {
			try {
				$content = $contentCrawler->filter($sel);
				if ($content->count() > 0) {
					return $content->first()->html();
				}
			} catch (\Exception $e) {}
		}

		// Fallback: use the body content
		try {
			$body = $contentCrawler->filter('body');
			if ($body->count() > 0) {
				return $body->first()->html();
			}
		} catch (\Exception $e) {}

		// Last resort: raw HTML
		return $contentCrawler->html();
	}

	/**
	 * Copy CSS files from the scraped site.
	 */
	private function copyCssAssets(string $scrapeDir): void
	{
		$cssDir = $this->rootDir . '/public/assets/css';
		if (!is_dir($cssDir)) {
			mkdir($cssDir, 0755, true);
		}

		$cssFiles = [];
		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($scrapeDir, \RecursiveDirectoryIterator::SKIP_DOTS)
		);

		foreach ($iterator as $file) {
			if ($file->isFile() && strtolower($file->getExtension()) === 'css') {
				$cssFiles[] = $file->getRealPath();
			}
		}

		foreach ($cssFiles as $cssFile) {
			$realCssPath = realpath($cssFile) ?: $cssFile;
			$relativePath = str_replace($scrapeDir . '/', '', $realCssPath);
			$destFile = $cssDir . '/' . basename($cssFile);

			if (!file_exists($destFile)) {
				copy($cssFile, $destFile);
				echo "  ✓ CSS: {$relativePath}\n";
			}
		}
	}

	/**
	 * Copy image files from the scraped site.
	 */
	private function copyImageAssets(string $scrapeDir): void
	{
		$imagesDir = $this->rootDir . '/public/assets/images';
		if (!is_dir($imagesDir)) {
			mkdir($imagesDir, 0755, true);
		}

		$imageExts = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'ico', 'avif'];
		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($scrapeDir, \RecursiveDirectoryIterator::SKIP_DOTS)
		);

		foreach ($iterator as $file) {
			if ($file->isFile() && in_array(strtolower($file->getExtension()), $imageExts)) {
				$realImgPath = $file->getRealPath() ?: $file->getPathname();
				$relativePath = str_replace($scrapeDir . '/', '', $realImgPath);
				$destFile = $imagesDir . '/' . basename($file->getFilename());

				if (!file_exists($destFile)) {
					copy($file->getRealPath(), $destFile);
					$this->imageCount++;
				}
			}
		}

		echo "  ✓ {$this->imageCount} images copied\n";
	}

	/**
	 * Inject nav_links and footer_links into the front page's front matter.
	 */
	private function injectNavigation(array $navLinks, array $footerLinks): void
	{
		if (empty($navLinks) && empty($footerLinks)) return;

		// Find the index/home page
		$indexFile = $this->rootDir . '/content/pages/index.md';
		if (!file_exists($indexFile)) {
			// Try to find any file that could be the home page
			$homeCandidates = ['home', 'index', ''];
			foreach ($homeCandidates as $candidate) {
				$testFile = $this->rootDir . '/content/pages/' . $candidate . '.md';
				if (file_exists($testFile)) {
					$indexFile = $testFile;
					break;
				}
			}
		}

		if (!file_exists($indexFile)) {
			echo "  ⚠ No index page found for nav injection\n";
			return;
		}

		$raw = file_get_contents($indexFile);
		if (!str_starts_with($raw, '---')) return;

		$end = strpos($raw, '---', 3);
		if ($end === false) return;

		$yamlBlock = substr($raw, 3, $end - 3);
		$frontMatter = \Symfony\Component\Yaml\Yaml::parse($yamlBlock) ?? [];

		if (!empty($navLinks)) {
			$frontMatter['nav_links'] = $navLinks;
		}
		if (!empty($footerLinks)) {
			$frontMatter['footer_links'] = $footerLinks;
		}
		$frontMatter['site_name'] = $this->config['site_name'] ?? 'My Site';

		$body = substr($raw, $end + 3);
		$yaml = \Symfony\Component\Yaml\Yaml::dump($frontMatter, 2, 4, \Symfony\Component\Yaml\Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK);
		file_put_contents($indexFile, "---\n{$yaml}---\n{$body}");

		echo "  ✓ Navigation injected into index page\n";
	}

	/**
	 * Ensure output directories exist.
	 */
	private function ensureDirectories(): void
	{
		$dirs = [
			$this->rootDir . '/content/pages',
			$this->rootDir . '/content/posts',
			$this->rootDir . '/public/assets/css',
			$this->rootDir . '/public/assets/images',
		];

		foreach ($dirs as $dir) {
			if (!is_dir($dir)) {
				mkdir($dir, 0755, true);
			}
		}
	}
}
