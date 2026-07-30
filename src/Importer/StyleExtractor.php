<?php

declare(strict_types=1);

namespace Loom\Importer;

/**
 * Extract and consolidate CSS from WordPress themes.
 *
 * Supports three input modes:
 * 1. Theme data from XML export (wp_global_styles JSON)
 * 2. Local theme directory (UpDraftPlus backup)
 * 3. Live site URL (scrape for CSS)
 */
class StyleExtractor
{
	private string $outputDir;

	public function __construct(string $outputDir)
	{
		$this->outputDir = rtrim($outputDir, '/');
	}

	/**
	 * Extract styles from available sources and write to public/assets/css/style.css.
	 *
	 * @param string|null $themeDir Path to the WordPress theme directory (directory imports)
	 * @param string $siteUrl Site URL for downloading styles
	 * @param array $themeData Theme data from parser ['name' => string, 'global_styles' => array|null]
	 */
	public function extract(?string $themeDir, string $siteUrl = '', array $themeData = []): string
	{
		$css = '';
		$fonts = '';

		// Priority 1: Convert wp_global_styles from XML export
		if (!empty($themeData['global_styles'])) {
			$converter = new ThemeStyleConverter();
			$css = $converter->convert($themeData['global_styles']);
			$fonts = $this->buildFontImport($themeData['global_styles']);
		}

		// Priority 2: Extract from local theme directory
		if ($themeDir && is_dir($themeDir)) {
			$skipThemeJson = !empty($themeData['global_styles']);
			$themeCss = $this->extractFromTheme($themeDir, $skipThemeJson);
			if (!empty($themeCss)) {
				$css .= "\n\n/* Theme directory styles */\n" . $themeCss;
			}
		}

		// Priority 3: Scrape inline <style> blocks from live site
		if (!empty($siteUrl)) {
			$scrapedCss = $this->scrapeInlineStyles($siteUrl);
			if (!empty($scrapedCss)) {
				$css .= "\n\n/* Block library styles (scraped from live site) */\n" . $scrapedCss;
			}
		}

		// Priority 4: Download theme stylesheet from live site (only if we have nothing yet)
		if (empty($css) && !empty($siteUrl)) {
			$themeName = $themeData['name'] ?? '';
			$css = $this->downloadStylesheet($siteUrl, $themeName);
		}

		// Priority 5: Fallback to minimal stylesheet
		if (empty($css)) {
			$css = $this->generateMinimal();
		}

		// Merge duplicate :root blocks (last-wins semantics)
		$css = $this->mergeRootBlocks($css);

		// Clean up WordPress admin-only styles
		$css = $this->cleanCss($css);

		// Prepend font imports if we have them
		if (!empty($fonts)) {
			$css = $fonts . "\n\n" . $css;
		}

		// Write to output
		$destDir = $this->outputDir . '/public/assets/css';
		if (!is_dir($destDir)) {
			mkdir($destDir, 0755, true);
		}

		file_put_contents($destDir . '/style.css', $css);

		return $destDir . '/style.css';
	}

	/**
	 * Write pre-extracted CSS directly to the output stylesheet.
	 *
	 * Used when CSS has already been extracted from HTML (e.g., by HtmlCssExtractor).
	 * Skips all WordPress theme extraction logic (theme.json, theme directory, scraping).
	 */
	public function writeRaw(string $css): string
	{
		$destDir = $this->outputDir . '/public/assets/css';
		if (!is_dir($destDir)) {
			mkdir($destDir, 0755, true);
		}

		// Clean up the CSS
		$css = $this->cleanCss($css);

		// Merge duplicate :root blocks
		$css = $this->mergeRootBlocks($css);

		file_put_contents($destDir . '/style.css', $css);

		return $destDir . '/style.css';
	}

	/**
	 * Build a Google Fonts @import URL from theme font families.
	 */
	private function buildFontImport(array $globalStyles): string
	{
		$families = $globalStyles['settings']['typography']['fontFamilies']['theme'] ?? [];
		if (empty($families)) return '';

		$googleFamilies = [];

		foreach ($families as $family) {
			$fontFaces = $family['fontFace'] ?? [];

			// Check if any face has a Google Fonts source (not file:./...)
			$hasLocalSource = false;
			$hasRemoteSource = false;
			$weightRange = '';

			foreach ($fontFaces as $face) {
				$src = $face['src'] ?? '';
				if (is_array($src)) {
					$src = $src[0] ?? '';
				}
				if (str_starts_with($src, 'file:./')) {
					$hasLocalSource = true;
				} else {
					$hasRemoteSource = true;
				}

				// Build weight range from fontFace entries
				$weight = $face['fontWeight'] ?? '';
				if ($weight) {
					$weightRange = $weight; // "100 900" or "100,200,300"
				}
			}

			// If we only have local font files, generate a Google Fonts fallback
			if ($hasLocalSource && !$hasRemoteSource) {
				$fontName = $family['name'] ?? '';
				if (empty($fontName)) continue;

				// Google Fonts variable font syntax: FamilyName:wght@min..max
				$weightParam = $this->buildGoogleWeightParam($weightRange);
				$encodedName = str_replace(' ', '+', $fontName);
				$googleFamilies[] = $encodedName . ($weightParam ? ':' . $weightParam : '');
			}
		}

		if (empty($googleFamilies)) return '';

		$url = 'https://fonts.googleapis.com/css2?'
			. implode('&', array_map(fn($f) => 'family=' . $f, $googleFamilies))
			. '&display=swap';

		return "@import url('{$url}');";
	}

	/**
	 * Build Google Fonts weight parameter.
	 * Handles "100 900" (variable font range), "100,200,300" (specific weights), or "400" (single).
	 */
	private function buildGoogleWeightParam(string $weights): string
	{
		if (empty($weights)) return '';

		// Variable font range: "100 900" → wght@100..900
		if (preg_match('/^(\d+)\s+(\d+)$/', $weights, $m)) {
			return "wght@{$m[1]}..{$m[2]}";
		}

		// Specific weights: "100,200,300" → wght@100;200;300
		if (str_contains($weights, ',')) {
			return 'wght@' . str_replace(',', ';', $weights);
		}

		// Single weight
		return "wght@{$weights}";
	}

	/**
	 * Extract CSS from a local theme directory.
	 */
	private function extractFromTheme(string $themeDir, bool $skipThemeJson = false): string
	{
		$css = '';

		// Read main style.css
		$mainCss = $themeDir . '/style.css';
		if (file_exists($mainCss)) {
			$css .= file_get_contents($mainCss);
		}

		// Read theme.json for CSS custom properties (skip if global_styles already processed)
		$themeJsonPath = $themeDir . '/theme.json';
		if (!$skipThemeJson && file_exists($themeJsonPath)) {
			$json = json_decode(file_get_contents($themeJsonPath), true);
			if (is_array($json)) {
				$converter = new ThemeStyleConverter();
				$css .= "\n\n/* From theme.json */\n" . $converter->convert($json);
			}
		}

		// Read additional CSS files from common locations
		$cssDirs = ['assets/css', 'css', 'assets'];
		foreach ($cssDirs as $subdir) {
			$dir = $themeDir . '/' . $subdir;
			if (is_dir($dir)) {
				$files = glob($dir . '/*.css') ?: [];
				foreach ($files as $file) {
					$css .= "\n\n/* " . basename($file) . " */\n" . file_get_contents($file);
				}
			}
		}

		return $css;
	}

	/**
	 * Download the theme stylesheet from the live site.
	 */
	private function downloadStylesheet(string $siteUrl, string $themeName = ''): string
	{
		$urls = [];

		// If we know the theme name, try the correct path first
		if (!empty($themeName)) {
			$urls[] = rtrim($siteUrl, '/') . '/wp-content/themes/' . $themeName . '/style.css';
		}

		// Fallback: try the site root (some themes serve from root)
		$urls[] = rtrim($siteUrl, '/') . '/style.css';

		foreach ($urls as $url) {
			$css = $this->fetchUrl($url);
			if (!empty($css)) return $css;
		}

		return '';
	}

	/**
	 * Scrape inline <style> blocks from the live site HTML.
	 * WordPress generates ~50-60KB of block library CSS inline.
	 */
	private function scrapeInlineStyles(string $siteUrl): string
	{
		$html = $this->fetchUrl(rtrim($siteUrl, '/'));
		if (empty($html)) return '';

		// Extract all <style> blocks
		if (!preg_match_all('/<style[^>]*>(.*?)<\/style>/si', $html, $matches)) {
			return '';
		}

		$css = '';
		foreach ($matches[1] as $block) {
			$block = trim($block);
			if (empty($block)) continue;

			// Skip empty or comment-only blocks
			$stripped = preg_replace('/\/\*.*?\*\//s', '', $block);
			if (trim($stripped) === '') continue;

			$css .= $block . "\n\n";
		}

		return trim($css);
	}

	/**
	 * Fetch content from a URL using curl.
	 */
	private function fetchUrl(string $url): string
	{
		$ch = curl_init($url);
		curl_setopt_array($ch, [
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_FOLLOWLOCATION => true,
			CURLOPT_TIMEOUT => 15,
			CURLOPT_SSL_VERIFYPEER => false,
			CURLOPT_USERAGENT => 'Loom/1.0 WordPress Importer',
		]);
		$content = curl_exec($ch);
		$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);

		if ($httpCode !== 200 || $content === false) return '';

		return $content;
	}

	/**
	 * Generate a minimal, clean stylesheet.
	 */
	private function generateMinimal(): string
	{
		return <<<'CSS'
/* Loom — Base Styles (generated from WordPress migration) */
*,
*::before,
*::after {
	box-sizing: border-box;
	margin: 0;
	padding: 0;
}

body {
	font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
	line-height: 1.6;
	color: #1a1a1a;
	background: #fff;
	max-width: 48rem;
	margin: 0 auto;
	padding: 2rem 1rem;
}

h1, h2, h3, h4, h5, h6 {
	line-height: 1.2;
	margin-top: 1.5em;
	margin-bottom: 0.5em;
}

h1 { font-size: 2rem; }
h2 { font-size: 1.5rem; }
h3 { font-size: 1.25rem; }

p { margin-bottom: 1em; }

a {
	color: #2563eb;
	text-decoration: none;
}

a:hover {
	text-decoration: underline;
}

img {
	max-width: 100%;
	height: auto;
}

ul, ol {
	margin-bottom: 1em;
	padding-left: 1.5em;
}

li { margin-bottom: 0.25em; }

code {
	background: #f3f4f6;
	padding: 0.125em 0.375em;
	border-radius: 0.25rem;
	font-size: 0.875em;
}

pre {
	background: #f3f4f6;
	padding: 1rem;
	border-radius: 0.5rem;
	overflow-x: auto;
	margin-bottom: 1em;
}

pre code {
	background: none;
	padding: 0;
}

blockquote {
	border-left: 3px solid #d1d5db;
	padding-left: 1rem;
	margin-bottom: 1em;
	color: #6b7280;
}

table {
	width: 100%;
	border-collapse: collapse;
	margin-bottom: 1em;
}

th, td {
	border: 1px solid #e5e7eb;
	padding: 0.5rem;
	text-align: left;
}

th {
	background: #f9fafb;
	font-weight: 600;
}

header, footer {
	margin-top: 2rem;
	padding-top: 1rem;
	border-top: 1px solid #e5e7eb;
}

.hero {
	text-align: center;
	padding: 3rem 0;
}

.cta {
	text-align: center;
	padding: 2rem 0;
	background: #f9fafb;
	border-radius: 0.5rem;
	margin: 2rem 0;
}

.btn {
	display: inline-block;
	padding: 0.75rem 1.5rem;
	background: #2563eb;
	color: #fff;
	border-radius: 0.375rem;
	font-weight: 600;
	text-decoration: none;
}

.btn:hover {
	background: #1d4ed8;
	text-decoration: none;
}

.site-footer {
	margin-top: 2rem;
	padding-top: 1rem;
	border-top: 1px solid #e5e7eb;
	font-size: 0.875rem;
	color: #6b7280;
}
CSS;
	}

	/**
	 * Merge duplicate :root blocks into a single block with last-wins semantics.
	 */
	private function mergeRootBlocks(string $css): string
	{
		if (!str_contains($css, ':root')) return $css;

		// Extract all :root { ... } blocks
		$declarations = [];
		$css = preg_replace_callback(
			'/@media[^{]*\{[^}]*:root[^{]*\{([^}]*)\}[^}]*\}|:root\s*\{([^}]*)\}/s',
			function (array $m) use (&$declarations): string {
				$block = trim($m[1] ?? $m[2] ?? '');
				if (empty($block)) return '';
				// Parse individual declarations (last-wins by property name)
				foreach (explode("\n", $block) as $line) {
					$line = trim($line);
					if (empty($line) || str_starts_with($line, '/*')) continue;
					// Extract property name
					if (preg_match('/^([a-z-]+)\s*:/', $line, $propMatch)) {
						$declarations[$propMatch[1]] = $line;
					}
				}
				return ''; // Remove the original block
			},
			$css
		);

		if (empty($declarations)) return trim($css);

		// Build a single merged :root block
		$merged = ":root {\n\t" . implode("\n\t", $declarations) . "\n}\n\n";

		return trim($merged . "\n\n" . $css);
	}

	/**
	 * Clean up WordPress admin-only CSS. Preserves design-related block styles.
	 */
	private function cleanCss(string $css): string
	{
		// Remove @import statements for WordPress internal paths
		// (keep Google Fonts @import)
		$css = preg_replace('/@import\s+url\((?!["\']https?:\/\/fonts\.googleapis)[^)]+\)\s*;?\s*/i', '', $css);

		// Remove WordPress admin-only selectors
		$adminSelectors = [
			'\.admin-bar',
			'\.logged-in',
			'\.wp-admin',
			'\.screen-reader-text',
			'\.wp-embed-',
			'\.wp-caption-text',
			'\.gallery-caption',
		];

		foreach ($adminSelectors as $selector) {
			$css = preg_replace('/(' . $selector . '[^\{]*)\{[^}]*\}/s', '', $css);
		}

		// Remove emoji/smiley styles
		$css = preg_replace('/img\.wp-smiley[^\}]*\}/s', '', $css);
		// Remove WP admin sync variables
		$css = preg_replace('/:root\{--wp-block-synced-color[^\}]*\}/s', '', $css);
		// Remove lightbox animations
		$css = preg_replace('/\.wp-lightbox-[^\}]*\}/s', '', $css);
		// Remove utility color/background-color/border-color classes
		$css = preg_replace('/\.has-[a-z-]+-(color|background-color|border-color)\[class\]\{[^}]*\}/s', '', $css);
		// Remove gradient utility classes
		$css = preg_replace('/\.has-[a-z-]+-gradient-background\{[^}]*\}/s', '', $css);
		// Remove Beaver Builder animation placeholders
		$css = preg_replace('/\.fl-node-[a-f0-9]+\.fl-animation[^\}]*\}/s', '', $css);

		// Remove empty rules
		$css = preg_replace('/[^\{]+\{\s*\}/', '', $css);

		// Remove excessive whitespace
		$css = preg_replace('/\n{3,}/', "\n\n", $css);

		return trim($css);
	}
}
