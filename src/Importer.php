<?php

declare(strict_types=1);

namespace Loom;

use Loom\Importer\ContentParser;
use Loom\Importer\WpXmlParser;
use Loom\Importer\WpSqlParser;
use Loom\Importer\HtmlParser;
use Loom\Importer\MediaMigrator;
use Loom\Importer\StyleExtractor;
use Loom\Importer\NavigationBuilder;
use Loom\Importer\FormConverter;
use Loom\Importer\ShortcodeHandler;
use Loom\Importer\HtmlToMarkdown;
use Loom\Importer\RedirectMap;
use Symfony\Component\Yaml\Yaml;

/**
 * WordPress to Loom migration orchestrator.
 */
class Importer
{
	private string $outputDir;
	private ShortcodeHandler $shortcodeHandler;
	private HtmlToMarkdown $htmlConverter;
	private MediaMigrator $mediaMigrator;
	private StyleExtractor $styleExtractor;
	private NavigationBuilder $navBuilder;
	private array $frontPageRedirects = [];
	private string $faviconPath = '';
	private string $siteTitle = '';
	private FormConverter $formConverter;
	private RedirectMap $redirectMap;

	public function __construct(string $outputDir)
	{
		$this->outputDir = rtrim($outputDir, '/');
		$this->shortcodeHandler = new ShortcodeHandler();
		$this->mediaMigrator = new MediaMigrator($this->outputDir);
		$this->styleExtractor = new StyleExtractor($this->outputDir);
		$this->navBuilder = new NavigationBuilder($this->outputDir);
		$this->formConverter = new FormConverter($this->outputDir);
		$this->redirectMap = new RedirectMap($this->outputDir);
	}

	/**
	 * Run the full migration.
	 *
	 * @param string $source Path to export file or directory
	 * @param string $format Force format: 'xml', 'sql', or 'auto'
	 */
	public function migrate(string $source, string $format = 'auto'): array
	{
		$this->cleanOutput();
		$this->ensureDirectories();

		echo "Loom Import\n";
		echo str_repeat('─', 50) . "\n\n";

		// Step 1: Detect format and parse
		echo "Step 1: Parsing export...\n";
		$parser = $this->detectParser($source, $format);
		$data = $parser->parse($source);
		$this->reportParsing($data);

		// Step 2: Migrate media
		echo "\nStep 2: Migrating media...\n";
		$this->siteTitle = $data['options']['site_title'] ?? '';
		$siteUrl = $data['options']['site_url'] ?? '';

		// Detect if media items are local files (HTML import)
		$hasLocalMedia = !empty($data['media']) && !empty($data['media'][0]['local_path'] ?? '');

		if ($hasLocalMedia) {
			$mediaResult = $this->mediaMigrator->migrateLocal($data['media'], $source);
			$urlMap = $mediaResult['url_map'];
			$altLookup = $mediaResult['alt_lookup'];
			echo "  " . count($urlMap) . " files copied, " . count($mediaResult['errors']) . " errors\n";
		} else {
			$mediaResult = $this->mediaMigrator->migrate($data['media'], $siteUrl);
			$urlMap = $mediaResult['url_map'];
			$altLookup = $mediaResult['alt_lookup'];
			echo "  " . count($urlMap) . " files migrated, " . count($mediaResult['errors']) . " errors\n";
		}

		// Detect and download favicons
		if ($hasLocalMedia) {
			$this->faviconPath = $this->extractLocalFavicon($data['media'], $urlMap, $source);
		} else {
			$this->faviconPath = $this->extractFavicons($data['media'], $urlMap, $siteUrl);
		}
		if ($this->faviconPath) {
			echo "  ✓ Favicon: {$this->faviconPath}\n";
		}

		// Initialize HTML converter with URL map and alt lookup
		$this->htmlConverter = new HtmlToMarkdown($urlMap, $altLookup);

		// Resolve featured image attachment IDs to URLs
		$this->resolveFeaturedImages($data['pages'], $data['media'], $urlMap);
		$this->resolveFeaturedImages($data['posts'], $data['media'], $urlMap);

		// Step 3: Extract styles
		echo "\nStep 3: Extracting styles...\n";
		$themeData = $data['theme'] ?? [];

		if (!empty($themeData['css'])) {
			// Pre-extracted CSS from HTML import
			$this->styleExtractor->writeRaw($themeData['css']);
			echo "  ✓ CSS extracted from HTML source\n";
		} else {
			// WordPress theme extraction
			$themeDir = $this->findThemeDir($source);
			$this->styleExtractor->extract($themeDir, $siteUrl, $themeData);
			echo "  ✓ CSS written to public/assets/css/style.css\n";
		}

		// Step 4: Convert pages
		echo "\nStep 4: Converting pages...\n";
		$pagesDir = $this->outputDir . '/content/pages';
		$pageCount = 0;
		$frontPageSlug = $this->detectFrontPage($data['pages'], $siteUrl);

		// Build navigation links for front matter
		// Use pre-resolved links from parser if available (HTML import), otherwise use NavigationBuilder
		$navLinks = $data['options']['nav_links'] ?? $this->navBuilder->build($data['menus'], $urlMap, $data['pages'], $frontPageSlug ?? '');
		$footerLinks = $data['options']['footer_links'] ?? $this->navBuilder->buildFooter($data['menus'], $urlMap, $data['pages'], $frontPageSlug ?? '');

		foreach ($data['pages'] as $page) {
			$isFrontPage = ($page['slug'] === $frontPageSlug);
			$this->convertItem($page, $pagesDir, 'page', $isFrontPage, $navLinks, $footerLinks);
			$pageCount++;
		}
		echo "  {$pageCount} pages converted\n";

		// Step 5: Convert posts
		echo "\nStep 5: Converting posts...\n";
		$postsDir = $this->outputDir . '/content/posts';
		if (!is_dir($postsDir)) {
			mkdir($postsDir, 0755, true);
		}
		$postCount = 0;
		foreach ($data['posts'] as $post) {
			$this->convertItem($post, $postsDir, 'post', false, $navLinks, $footerLinks);
			$postCount++;
		}
		echo "  {$postCount} posts converted\n";

		// Step 6: Create blog index
		if ($postCount > 0) {
			echo "\nStep 6: Creating blog index...\n";
			$this->createBlogIndex($data['options']['blogdescription'] ?? '');
			echo "  ✓ content/pages/blog.md\n";
		}

		// Step 7: Build navigation
		echo "\nStep 7: Building navigation...\n";
		echo "  " . count($navLinks) . " nav links, " . count($footerLinks) . " footer links\n";

		// Step 8: Convert forms
		echo "\nStep 8: Converting forms...\n";
		$this->formConverter->generateDefaultContactForm();
		echo "  ✓ templates/partials/form-contact.php\n";

		// Step 9: Generate redirect map
		echo "\nStep 9: Generating redirect map...\n";
		$this->redirectMap->generate($data['pages'], $data['posts'], $siteUrl, $this->frontPageRedirects);
		echo "  ✓ src/redirects.php\n";

		// Summary
		echo "\n" . str_repeat('─', 50) . "\n";
		echo "✓ Migration complete!\n";
		echo "  Pages: {$pageCount}\n";
		echo "  Posts: {$postCount}\n";
		echo "  Media: " . count($urlMap) . " files\n";
		echo "  Redirects: generated\n";
		echo "\nRun 'php loom verify' to check the output.\n";
		echo "Run 'php -S localhost:8080 -t public/' to preview.\n";

		return [
			'pages' => $pageCount,
			'posts' => $postCount,
			'media' => count($urlMap),
			'errors' => $mediaResult['errors'],
		];
	}

	/**
	 * Detect the export format and return the appropriate parser.
	 */
	private function detectParser(string $source, string $format): ContentParser
	{
		if ($format === 'xml' || ($format === 'auto' && str_ends_with(strtolower($source), '.xml'))) {
			return new WpXmlParser();
		}

		if ($format === 'sql' || ($format === 'auto' && str_ends_with(strtolower($source), '.sql'))) {
			return new WpSqlParser();
		}

		// HTML directory detection
		if ($format === 'html' || ($format === 'auto' && is_dir($source))) {
			if (file_exists($source . '/index.html') || !empty(glob($source . '/*.html'))) {
				return new HtmlParser();
			}
		}

		// Try to detect from file content (only for files, not directories)
		if (!is_dir($source)) {
			$firstBytes = file_get_contents($source, false, null, 0, 100);
			if (str_starts_with($firstBytes, '<?xml') || str_contains($firstBytes, '<rss')) {
				return new WpXmlParser();
			}

			if (str_contains($firstBytes, 'INSERT INTO') || str_contains($firstBytes, 'CREATE TABLE')) {
				return new WpSqlParser();
			}
		}

		throw new \RuntimeException("Cannot detect export format for: {$source}. Use --format=xml, --format=sql, or --format=html.");
	}

	/**
	 * Detect which page is the front page.
	 *
	 * WordPress: matches by siteUrl. Static HTML: matches by empty slug.
	 */
	private function detectFrontPage(array $pages, string $siteUrl): ?string
	{
		// First try matching by site URL (WordPress flow)
		if (!empty($siteUrl)) {
			$siteUrl = rtrim($siteUrl, '/');
			foreach ($pages as $page) {
				$link = rtrim($page['link'] ?? '', '/');
				if ($link === $siteUrl || $link === '') {
					return $page['slug'];
				}
			}
		}

		// Fallback: find the page with an empty slug (HTML import homepage)
		foreach ($pages as $page) {
			if (($page['slug'] ?? '') === '') {
				return '';
			}
		}

		return null;
	}

	/**
	 * Convert a single page or post to a Loom markdown file.
	 */
	private function convertItem(array $item, string $outputDir, string $type, bool $isFrontPage = false, array $navLinks = [], array $footerLinks = []): void
	{
		$slug = $item['slug'] ?? '';

		// Front page with empty slug is valid (HTML import homepage)
		if (empty($slug) && !$isFrontPage) return;

		// Process shortcodes
		$content = $this->shortcodeHandler->process($item['content'] ?? '');

		// Process form placeholders
		$content = $this->formConverter->convert($item['forms'] ?? [], $content);

		// Convert HTML to Markdown
		$markdown = $this->htmlConverter->convert($content);

		// Build front matter
		$frontMatter = [
			'title' => $item['title'] ?? '',
			'description' => $item['excerpt'] ?? '',
			'template' => $this->mapTemplate($item['template'] ?? 'default'),
			'date' => $item['date'] ?? date('Y-m-d'),
		];

		// Add navigation and footer links (front page only to avoid duplication)
		if ($isFrontPage) {
			if (!empty($navLinks)) {
				$frontMatter['nav_links'] = $navLinks;
			}
			if (!empty($footerLinks)) {
				$frontMatter['footer_links'] = $footerLinks;
			}
		}

		// Add site name for nav and footer partials
		if (!empty($this->siteTitle)) {
			$frontMatter['site_name'] = $this->siteTitle;
		}

		// Add featured image (resolved during media migration)
		if (!empty($item['featured_image']['url'])) {
			$frontMatter['image'] = $item['featured_image']['url'];
		}

		// Add favicon
		if (!empty($this->faviconPath)) {
			$frontMatter['favicon'] = $this->faviconPath;
		}

		// Add tags and categories
		if (!empty($item['tags'])) {
			$frontMatter['tags'] = $item['tags'];
		}
		if (!empty($item['categories'])) {
			$frontMatter['categories'] = $item['categories'];
		}

		// Write the file
		$yaml = Yaml::dump($frontMatter, 2, 4, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK);
		$fileContent = "---\n{$yaml}---\n\n{$markdown}";

		if ($isFrontPage) {
			// Write as homepage (index.md)
			$filePath = $outputDir . '/index.md';
			file_put_contents($filePath, $fileContent);
			echo "  ✓ {$slug}.md → index.md (front page)\n";

			// Also write original slug with redirect
			if ($slug !== 'index') {
				$this->frontPageRedirects[$slug] = '/';
			}
		} else {
			$filePath = $outputDir . '/' . $slug . '.md';

			// Create parent directories for nested slugs (e.g., resources/google-ads)
			$parentDir = dirname($filePath);
			if (!is_dir($parentDir)) {
				mkdir($parentDir, 0755, true);
			}

			file_put_contents($filePath, $fileContent);
		}
	}

	/**
	 * Map WordPress template names to Loom templates.
	 */
	private function mapTemplate(string $wpTemplate): string
	{
		$templateMap = [
			'' => 'default',
			'default' => 'default',
			'page.php' => 'default',
			'single.php' => 'default',
			'full-width.php' => 'pillar',
			'pillar.php' => 'pillar',
			'landing.php' => 'pillar',
			'blog.php' => 'blog',
		];

		// Strip WordPress template path
		$wpTemplate = basename($wpTemplate);

		return $templateMap[$wpTemplate] ?? 'default';
	}

	/**
	 * Create a blog index page.
	 */
	private function createBlogIndex(string $description): void
	{
		$yaml = Yaml::dump([
			'title' => 'Blog',
			'description' => !empty($description) ? $description : 'Latest news and updates.',
			'template' => 'blog',
		], 2, 4);

		$content = "---\n{$yaml}---\n\n## Latest Posts\n\n";

		$filePath = $this->outputDir . '/content/pages/blog.md';
		file_put_contents($filePath, $content);
	}

	/**
	 * Detect and download favicon images from media items.
	 * Returns the relative path to the favicon, or empty string if none found.
	 */
	private function extractFavicons(array $media, array $urlMap, string $siteUrl): string
	{
		$faviconPatterns = ['favicon', 'cropped-favicon', 'site-icon', 'android-chrome', 'apple-touch-icon'];
		$faviconUrl = '';

		// Find the best favicon (prefer cropped-favicon-32x32, then favicon-32x32, then any)
		foreach ($media as $item) {
			$url = $item['url'] ?? '';
			$filename = strtolower(basename(parse_url($url, PHP_URL_PATH) ?? ''));

			foreach ($faviconPatterns as $pattern) {
				if (str_contains($filename, $pattern) && str_contains($filename, '32x32')) {
					$faviconUrl = $url;
					break 2;
				}
			}
		}

		// Fallback: any favicon-like image
		if (empty($faviconUrl)) {
			foreach ($media as $item) {
				$url = $item['url'] ?? '';
				$filename = strtolower(basename(parse_url($url, PHP_URL_PATH) ?? ''));
				foreach ($faviconPatterns as $pattern) {
					if (str_contains($filename, $pattern)) {
						$faviconUrl = $url;
						break 2;
					}
				}
			}
		}

		if (empty($faviconUrl)) return '';

		// Check if we already have it in the URL map
		if (isset($urlMap[$faviconUrl])) {
			return $urlMap[$faviconUrl];
		}

		// Download the favicon
		$ext = pathinfo(parse_url($faviconUrl, PHP_URL_PATH) ?? 'favicon.png', PATHINFO_EXTENSION) ?: 'png';
		$destPath = $this->outputDir . '/public/assets/favicon.' . $ext;
		$destDir = dirname($destPath);
		if (!is_dir($destDir)) {
			mkdir($destDir, 0755, true);
		}

		$resolvedUrl = $siteUrl ? rtrim($siteUrl, '/') . '/' . ltrim($faviconUrl, '/') : $faviconUrl;
		if (str_starts_with($faviconUrl, 'http')) {
			$resolvedUrl = $faviconUrl;
		}

		$ch = curl_init($resolvedUrl);
		$fp = fopen($destPath, 'wb');
		curl_setopt_array($ch, [
			CURLOPT_FILE => $fp,
			CURLOPT_FOLLOWLOCATION => true,
			CURLOPT_TIMEOUT => 15,
			CURLOPT_SSL_VERIFYPEER => false,
		]);
		curl_exec($ch);
		$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);
		fclose($fp);

		if ($httpCode !== 200) {
			@unlink($destPath);
			return '';
		}

		return '/assets/favicon.' . $ext;
	}

	/**
	 * Extract favicon from local media assets.
	 * Looks for favicon-like files in the URL map (already copied by MediaMigrator).
	 */
	private function extractLocalFavicon(array $media, array $urlMap, string $sourceDir): string
	{
		$faviconPatterns = ['favicon', 'apple-touch-icon', 'android-chrome', 'site-icon'];

		// Search for favicon in the URL map (already migrated)
		foreach ($urlMap as $oldUrl => $newUrl) {
			$filename = strtolower(basename($oldUrl));
			foreach ($faviconPatterns as $pattern) {
				if (str_contains($filename, $pattern)) {
					return $newUrl;
				}
			}
		}

		// Search for favicon files in the source directory
		$candidates = [
			'/favicon.ico',
			'/favicon.svg',
			'/favicon.png',
			'/favicon-32x32.png',
			'/assets/favicon.ico',
			'/assets/img/favicon.svg',
			'/assets/img/favicon.webp',
		];

		foreach ($candidates as $candidate) {
			$path = $sourceDir . $candidate;
			if (file_exists($path)) {
				$destDir = $this->outputDir . '/public/assets';
				if (!is_dir($destDir)) {
					mkdir($destDir, 0755, true);
				}
				$ext = pathinfo($candidate, PATHINFO_EXTENSION);
				$destPath = $destDir . '/favicon.' . $ext;
				copy($path, $destPath);
				return '/assets/favicon.' . $ext;
			}
		}

		return '';
	}

	/**
	 * Find the WordPress theme directory in the export source.
	 */
	private function findThemeDir(string $source): ?string
	{
		if (is_dir($source)) {
			// UpDraftPlus backup — look for theme files
			$themesDir = $source . '/wp-content/themes';
			if (is_dir($themesDir)) {
				// Find the first non-default theme
				$themes = glob($themesDir . '/*', GLOB_ONLYDIR);
				foreach ($themes as $theme) {
					$name = basename($theme);
					if (!in_array($name, ['twentytwenty', 'twentytwentyone', 'twentytwentytwo', 'twentytwentythree', 'twentytwentyfour'])) {
						return $theme;
					}
				}
				// Fall back to first theme
				return $themes[0] ?? null;
			}
		}

		return null;
	}

	/**
	 * Ensure all output directories exist and copy core templates.
	 */
	private function ensureDirectories(): void
	{
		$dirs = [
			$this->outputDir . '/content/pages',
			$this->outputDir . '/content/posts',
			$this->outputDir . '/public/assets/css',
			$this->outputDir . '/public/assets/fonts',
			$this->outputDir . '/src',
			$this->outputDir . '/public/assets/js',
			$this->outputDir . '/public/assets/images',
			$this->outputDir . '/templates/layouts',
			$this->outputDir . '/templates/partials',
		];

		foreach ($dirs as $dir) {
			if (!is_dir($dir)) {
				mkdir($dir, 0755, true);
			}
		}

		// Copy core templates from Loom source if they don't exist in output
		$this->copyTemplates();
	}

	/**
	 * Copy layout and partial templates from the Loom source directory.
	 */
	private function copyTemplates(): void
	{
		// Loom source root is one level up from src/
		$loomDir = dirname(__DIR__);

		foreach (['layouts', 'partials'] as $type) {
			$srcDir = $loomDir . '/templates/' . $type;
			$destDir = $this->outputDir . '/templates/' . $type;

			if (!is_dir($srcDir)) continue;

			foreach (glob($srcDir . '/*.php') ?: [] as $file) {
				$dest = $destDir . '/' . basename($file);
				if (!file_exists($dest)) {
					copy($file, $dest);
				}
			}
		}

		// Copy core runtime files
		$srcFiles = ['Router.php', 'ContentLoader.php', 'Renderer.php', 'CacheManager.php', 'SeoGenerator.php', 'FormHandler.php'];
		foreach ($srcFiles as $file) {
			$src = $loomDir . '/src/' . $file;
			$dest = $this->outputDir . '/src/' . $file;
			if (file_exists($src) && !file_exists($dest)) {
				copy($src, $dest);
			}
		}

		// Copy public entry point and .htaccess
		foreach (['index.php', '.htaccess'] as $file) {
			$src = $loomDir . '/public/' . $file;
			$dest = $this->outputDir . '/public/' . $file;
			if (file_exists($src) && !file_exists($dest)) {
				copy($src, $dest);
			}
		}

		// Copy loom CLI
		$src = $loomDir . '/loom';
		$dest = $this->outputDir . '/loom';
		if (file_exists($src) && !file_exists($dest)) {
			copy($src, $dest);
		}
	}

	/**
	 * Resolve featured image attachment IDs to migrated local URLs.
	 */
	private function resolveFeaturedImages(array &$items, array $media, array $urlMap): void
	{
		// Build post_id → original URL lookup
		$mediaById = [];
		foreach ($media as $item) {
			if (!empty($item['post_id']) && !empty($item['url'])) {
				$mediaById[$item['post_id']] = $item['url'];
			}
		}

		foreach ($items as &$item) {
			$featured = $item['featured_image'] ?? null;
			if (empty($featured) || empty($featured['attachment_id'])) continue;

			$attachmentId = (int) $featured['attachment_id'];
			$originalUrl = $mediaById[$attachmentId] ?? '';

			if (!empty($originalUrl) && isset($urlMap[$originalUrl])) {
				$item['featured_image'] = ['url' => $urlMap[$originalUrl]];
			} elseif (!empty($originalUrl)) {
				$item['featured_image'] = ['url' => $originalUrl];
			}
		}
	}

	/**
	 * Remove stale output files from a previous import run.
	 */
	private function cleanOutput(): void
	{
		// Recursively remove .md files in content/ (handles nested slugs like resources/google-ads.md)
		foreach (['pages', 'posts'] as $type) {
			$dir = $this->outputDir . '/content/' . $type;
			if (is_dir($dir)) {
				$this->removeFilesRecursive($dir, '.md');
			}
		}

		// Remove cached HTML
		foreach (glob($this->outputDir . '/cache/*.html') ?: [] as $file) {
			@unlink($file);
		}

		// Remove migrated images
		$imagesDir = $this->outputDir . '/public/assets/images';
		if (is_dir($imagesDir)) {
			$this->removeDirectory($imagesDir);
		}
	}

	/**
	 * Recursively remove files matching an extension, then clean empty directories.
	 */
	private function removeFilesRecursive(string $dir, string $extension): void
	{
		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
			\RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ($iterator as $file) {
			if ($file->isFile() && $file->getExtension() === ltrim($extension, '.')) {
				@unlink($file->getPathname());
			}
		}

		// Remove empty subdirectories (but not the root dir)
		foreach ($iterator as $file) {
			if ($file->isDir() && $file->getPath() !== $dir) {
				@rmdir($file->getPathname());
			}
		}
	}

	/**
	 * Recursively remove a directory and its contents.
	 */
	private function removeDirectory(string $dir): void
	{
		if (!is_dir($dir)) return;

		$items = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
			\RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ($items as $item) {
			if ($item->isDir()) {
				rmdir($item->getRealPath());
			} else {
				unlink($item->getRealPath());
			}
		}

		rmdir($dir);
	}

	/**
	 * Report parsing results.
	 */
	private function reportParsing(array $data): void
	{
		echo "  Pages: " . count($data['pages']) . "\n";
		echo "  Posts: " . count($data['posts']) . "\n";
		echo "  Media: " . count($data['media']) . "\n";
		echo "  Menus: " . count($data['menus']) . "\n";
		echo "  Site: " . ($data['options']['site_title'] ?? 'Unknown') . "\n";
		if (!empty($data['theme']['name'])) {
			echo "  Theme: " . $data['theme']['name'] . "\n";
		}
	}
}
