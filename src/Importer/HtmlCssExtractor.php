<?php

declare(strict_types=1);

namespace Loom\Importer;

/**
 * Extract and merge CSS from static HTML sites.
 *
 * Reads shared stylesheets and inline <style> blocks, deduplicates,
 * and produces a single merged stylesheet for Loom.
 */
class HtmlCssExtractor
{
	/**
	 * Extract and merge all CSS from the source site.
	 *
	 * @param string $sourceDir Absolute path to the source site root
	 * @param array  $htmlFiles Array of HTML file paths
	 */
	public function extract(string $sourceDir, array $htmlFiles): string
	{
		$sourceDir = rtrim($sourceDir, '/');

		// 1. Read the shared stylesheet
		$sharedCss = $this->extractSharedStylesheet($sourceDir);

		// 2. Extract inline <style> blocks from all HTML files
		$inlineCss = $this->extractInlineStyles($htmlFiles);

		// 3. Combine
		$combined = '';
		if (!empty($sharedCss)) {
			$combined .= "/* Shared stylesheet */\n" . $sharedCss;
		}
		if (!empty($inlineCss)) {
			$combined .= "\n\n/* Inline styles */\n" . $inlineCss;
		}

		if (empty($combined)) return '';

		// 4. Extract @import and @font-face to top
		$parts = $this->extractImportsAndFonts($combined);
		$combined = '';
		if (!empty($parts['imports'])) {
			$combined .= $parts['imports'] . "\n\n";
		}
		if (!empty($parts['font_faces'])) {
			$combined .= $parts['font_faces'] . "\n\n";
		}
		$combined .= $parts['remaining'];

		// 5. Deduplicate
		$combined = $this->deduplicate($combined);

		// 6. Rewrite relative URLs in CSS
		$combined = $this->rewriteCssUrls($combined, $sourceDir);

		// 7. Clean up
		$combined = $this->cleanCss($combined);

		return $combined;
	}

	/**
	 * Read the main shared stylesheet from the source directory.
	 *
	 * Tries common locations: assets/css/styles.css, assets/css/style.css, css/styles.css, style.css
	 */
	public function extractSharedStylesheet(string $sourceDir): string
	{
		$candidates = [
			'/assets/css/styles.css',
			'/assets/css/style.css',
			'/css/styles.css',
			'/css/style.css',
			'/style.css',
		];

		foreach ($candidates as $candidate) {
			$path = $sourceDir . $candidate;
			if (file_exists($path)) {
				$css = file_get_contents($path);
				if ($css !== false && !empty(trim($css))) {
					return $css;
				}
			}
		}

		return '';
	}

	/**
	 * Extract all inline <style> blocks from HTML files.
	 * Deduplicates identical blocks by content hash.
	 */
	public function extractInlineStyles(array $htmlFiles): string
	{
		$blocks = [];
		$seen = [];

		foreach ($htmlFiles as $file) {
			$html = file_get_contents($file);
			if ($html === false) continue;

			if (preg_match_all('/<style\b[^>]*>(.*?)<\/style>/si', $html, $matches)) {
				foreach ($matches[1] as $block) {
					$block = trim($block);
					if (empty($block)) continue;

					// Skip empty or comment-only blocks
					$stripped = preg_replace('/\/\*.*?\*\//s', '', $block);
					if (trim($stripped) === '') continue;

					$hash = md5($block);
					if (isset($seen[$hash])) continue;
					$seen[$hash] = true;

					$blocks[] = $block;
				}
			}
		}

		return implode("\n\n", $blocks);
	}

	/**
	 * Extract @import and @font-face declarations for separate handling.
	 *
	 * @return array{imports: string, font_faces: string, remaining: string}
	 */
	public function extractImportsAndFonts(string $css): array
	{
		$imports = [];
		$fontFaces = [];
		$remaining = [];

		// Split into top-level blocks
		$blocks = $this->splitCssBlocks($css);

		foreach ($blocks as $block) {
			$trimmed = trim($block);
			if (empty($trimmed)) continue;

			if (str_starts_with($trimmed, '@import')) {
				$imports[] = $trimmed;
			} elseif (str_starts_with($trimmed, '@font-face')) {
				$fontFaces[] = $trimmed;
			} else {
				$remaining[] = $trimmed;
			}
		}

		return [
			'imports' => implode("\n", $imports),
			'font_faces' => implode("\n\n", $fontFaces),
			'remaining' => implode("\n\n", $remaining),
		];
	}

	/**
	 * Deduplicate CSS rules.
	 *
	 * If the same selector+declaration block appears multiple times,
	 * only the last occurrence is kept.
	 */
	public function deduplicate(string $css): string
	{
		// Split into rules (simple approach: split on } boundaries)
		$rules = [];
		$current = '';
		$depth = 0;

		for ($i = 0; $i < strlen($css); $i++) {
			$char = $css[$i];
			$current .= $char;

			if ($char === '{') $depth++;
			if ($char === '}') {
				$depth--;
				if ($depth === 0) {
					$rules[] = trim($current);
					$current = '';
				}
			}
		}

		if (!empty(trim($current))) {
			$rules[] = trim($current);
		}

		// Deduplicate by selector (last wins)
		$bySelector = [];
		foreach ($rules as $rule) {
			if (preg_match('/^([^{]+)\{/', $rule, $m)) {
				$selector = trim($m[1]);
				$bySelector[$selector] = $rule;
			} else {
				// Non-rule blocks (@media, etc.) — keep all
				$bySelector[] = $rule;
			}
		}

		return implode("\n\n", array_values($bySelector));
	}

	/**
	 * Rewrite relative URLs in CSS to absolute paths.
	 */
	public function rewriteCssUrls(string $css, string $sourceDir): string
	{
		// Find the CSS file's location to resolve relative paths
		$cssDir = $this->findCssDirectory($sourceDir);

		return preg_replace_callback(
			'/url\(["\']?([^"\'()]+)["\']?\)/i',
			function (array $m) use ($cssDir, $sourceDir): string {
				$url = $m[1];

				// Skip absolute URLs, data URIs
				if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')
					|| str_starts_with($url, 'data:')) {
					return $m[0];
				}

				// Resolve relative path
				if (str_starts_with($url, '../') || str_starts_with($url, './')) {
					$resolved = realpath($cssDir . '/' . $url);
					if ($resolved && file_exists($resolved)) {
						$relative = substr($resolved, strlen($sourceDir));
						$url = '/' . ltrim($relative, '/');
					}
				} elseif (!str_starts_with($url, '/')) {
					// Relative to CSS directory
					$resolved = realpath($cssDir . '/' . $url);
					if ($resolved && file_exists($resolved)) {
						$relative = substr($resolved, strlen($sourceDir));
						$url = '/' . ltrim($relative, '/');
					}
				}

				return "url('{$url}')";
			},
			$css
		);
	}

	/**
	 * Find the directory containing the main CSS file.
	 */
	private function findCssDirectory(string $sourceDir): string
	{
		$candidates = [
			'/assets/css',
			'/css',
			'/',
		];

		foreach ($candidates as $candidate) {
			$dir = $sourceDir . $candidate;
			if (is_dir($dir) && (file_exists($dir . '/styles.css') || file_exists($dir . '/style.css'))) {
				return $dir;
			}
		}

		return $sourceDir;
	}

	/**
	 * Split CSS into top-level blocks (rules, @media, @font-face, etc.)
	 */
	private function splitCssBlocks(string $css): array
	{
		$blocks = [];
		$current = '';
		$depth = 0;

		for ($i = 0; $i < strlen($css); $i++) {
			$char = $css[$i];
			$current .= $char;

			if ($char === '{') $depth++;
			if ($char === '}') {
				$depth--;
				if ($depth === 0) {
					$blocks[] = $current;
					$current = '';
				}
			}
		}

		if (!empty(trim($current))) {
			$blocks[] = $current;
		}

		return $blocks;
	}

	/**
	 * Clean up CSS — remove empty rules, excessive whitespace, WordPress admin styles.
	 */
	private function cleanCss(string $css): string
	{
		// Remove empty rules
		$css = preg_replace('/[^\{]+\{\s*\}/', '', $css);

		// Remove excessive whitespace
		$css = preg_replace('/\n{3,}/', "\n\n", $css);

		return trim($css);
	}
}
