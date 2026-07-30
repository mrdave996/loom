<?php

declare(strict_types=1);

namespace Loom\Importer;

use League\HTMLToMarkdown\HtmlConverter;

/**
 * Convert WordPress HTML to clean Markdown.
 */
class HtmlToMarkdown
{
	private HtmlConverter $converter;
	private array $urlMap;
	private array $altLookup;

	public function __construct(array $urlMap = [], array $altLookup = [])
	{
		$this->converter = new HtmlConverter([
			'header_style' => 'atx',
			'bold_style' => '**',
			'italic_style' => '*',
			'convert_quotes' => true,
			'remove_empty_nodes' => true,
		]);

		$this->urlMap = $urlMap;
		$this->altLookup = $altLookup;
	}

	/**
	 * Convert HTML content to Markdown.
	 */
	public function convert(string $html): string
	{
		// Pre-process: clean WordPress-specific markup
		$html = $this->cleanWordPress($html);

		// Convert to Markdown
		$markdown = $this->converter->convert($html);

		// Post-process: rewrite image URLs and clean up
		$markdown = $this->rewriteImageUrls($markdown);
		$markdown = $this->cleanMarkdown($markdown);

		return $markdown;
	}

	/**
	 * Strip WordPress and Elementor wrapper markup.
	 */
	private function cleanWordPress(string $html): string
	{
		// Rewrite URLs in inline styles BEFORE stripping style attributes
		$html = $this->rewriteInlineStyleUrls($html);

		// Strip wp-block wrapper divs (opening + closing), keep content
		$html = $this->stripWpBlockWrappers($html);

		// Remove Elementor section/column wrappers but keep inner content
		$html = preg_replace('/<div[^>]*class="[^"]*elementor-[^\"]*"[^>]*>/i', '', $html);
		$html = preg_replace('/<\/div>\s*(?=<div[^>]*class="[^"]*elementor-)/i', '', $html);

		// Strip Beaver Builder row/column/module wrappers
		$html = $this->stripBeaverBuilderWrappers($html);

		// Remove data- attributes (Elementor, data-settings, etc.)
		$html = preg_replace('/\s+data-[a-z-]+="[^"]*"/i', '', $html);

		// Remove empty divs and spans
		$html = preg_replace('/<div[^>]*>\s*<\/div>/i', '', $html);
		$html = preg_replace('/<span[^>]*>\s*<\/span>/i', '', $html);

		// Clean up style attributes — keep only essential ones
		$html = preg_replace('/\s+style="[^"]*"/i', '', $html);

		// Remove script tags (including hera/kronos)
		$html = preg_replace('/<script[^>]*>.*?<\/script>/is', '', $html);

		// Remove noscript tags
		$html = preg_replace('/<noscript[^>]*>.*?<\/noscript>/is', '', $html);

		// Remove form tags (handled separately by FormConverter)
		// Keep them for now — FormConverter will replace them

		// Remove HTML comments (except our form placeholders)
		$html = preg_replace('/<!--(?!\s*form:).*?-->/s', '', $html);

		return $html;
	}

	/**
	 * Recursively strip wp-block wrapper divs, keeping their content.
	 *
	 * The previous approach stripped opening <div> tags but left closing </div>,
	 * which destroyed column layouts and lost images. This matches full
	 * open→close pairs so the inner content survives intact.
	 */
	private function stripWpBlockWrappers(string $html): string
	{
		$pattern = '/<div\b[^>]*class="[^"]*wp-block-[^"]*"[^>]*>(.*?)<\/div>/is';
		$changed = true;
		while ($changed) {
			$changed = false;
			$newHtml = preg_replace_callback($pattern, function (array $m) use (&$changed): string {
				$changed = true;
				return $m[1];
			}, $html);
			if ($changed) {
				$html = $newHtml;
			}
		}

		// Also strip <figure class="wp-block-image..."> wrappers
		$html = preg_replace(
			'/<figure\b[^>]*class="[^"]*wp-block-image[^"]*"[^>]*>(.*?)<\/figure>/is',
			'$1',
			$html
		);

		return $html;
	}

	/**
	 * Strip Beaver Builder wrapper divs (fl-row, fl-col, fl-module, etc.).
	 */
	private function stripBeaverBuilderWrappers(string $html): string
	{
		$pattern = '/<div\b[^>]*class="[^"]*fl-(?:row|col|module|builder-content|module-content)[^"]*"[^>]*>(.*?)<\/div>/is';
		$changed = true;
		while ($changed) {
			$changed = false;
			$newHtml = preg_replace_callback($pattern, function (array $m) use (&$changed): string {
				$changed = true;
				return $m[1];
			}, $html);
			if ($changed) {
				$html = $newHtml;
			}
		}

		return $html;
	}

	/**
	 * Rewrite image URLs to local paths and ensure alt text.
	 */
	private function rewriteImageUrls(string $markdown): string
	{
		// Rewrite image URLs: ![alt](old-url) → ![alt](new-url)
		// Use balanced parentheses pattern to handle URLs like image(1).jpg
		$markdown = preg_replace_callback(
			'/!\[([^\]]*)\]\(((?:[^()]+|\([^()]*\))*)\)/',
			function (array $m) {
				$alt = $m[1];
				$url = trim($m[2]);

				// Look up new URL (with CDN normalization)
				$newUrl = $this->resolveUrl($url);
				if ($newUrl !== null) {
					$url = $newUrl;
				}

				// Ensure alt text
				if (empty($alt)) {
					$alt = $this->guessAlt($url);
				}

				return "![{$alt}]({$url})";
			},
			$markdown
		);

		// Also rewrite plain <img> tags that didn't convert
		$markdown = preg_replace_callback(
			'/<img[^>]+src=["\']([^"\']+)["\'][^>]*(?:alt=["\']([^"\']*)["\'])?[^>]*\/?>/i',
			function (array $m) {
				$url = $m[1];
				$alt = $m[2] ?? '';

				$newUrl = $this->resolveUrl($url);
				if ($newUrl !== null) {
					$url = $newUrl;
				}

				if (empty($alt)) {
					$alt = $this->guessAlt($url);
				}

				return "![{$alt}]({$url})";
			},
			$markdown
		);

		// Rewrite <a href="..."> links to images
		$markdown = preg_replace_callback(
			'/<a[^>]+href=["\']([^"\']+\.(?:jpg|jpeg|png|gif|webp|svg|avif)(?:\?[^"\']*)?)["\'][^>]*>/i',
			function (array $m) {
				$url = $m[1];
				$newUrl = $this->resolveUrl($url);
				if ($newUrl !== null) {
					return str_replace($url, $newUrl, $m[0]);
				}
				return $m[0];
			},
			$markdown
		);

		return $markdown;
	}

	/**
	 * Resolve a URL against the URL map, with CDN normalization.
	 */
	private function resolveUrl(string $url): ?string
	{
		// Direct match
		if (isset($this->urlMap[$url])) {
			return $this->urlMap[$url];
		}

		// Try stripping CDN prefixes (i0.wp.com, i1.wp.com, etc.)
		$normalized = $this->normalizeCdnUrl($url);
		if ($normalized !== $url && isset($this->urlMap[$normalized])) {
			return $this->urlMap[$normalized];
		}

		// Try without query string
		$withoutQuery = preg_replace('/\?.*$/', '', $url);
		if ($withoutQuery !== $url && isset($this->urlMap[$withoutQuery])) {
			return $this->urlMap[$withoutQuery];
		}

		return null;
	}

	/**
	 * Strip WordPress CDN prefixes to get the origin URL.
	 * e.g., https://i0.wp.com/site.com/wp-content/... → https://site.com/wp-content/...
	 */
	private function normalizeCdnUrl(string $url): string
	{
		if (preg_match('#^https?://i\d+\.wp\.com/(.+)$#', $url, $m)) {
			return 'https://' . $m[1];
		}
		return $url;
	}

	/**
	 * Rewrite image URLs inside inline style attributes (background-image, etc.)
	 * before style attributes are stripped by cleanWordPress().
	 */
	private function rewriteInlineStyleUrls(string $html): string
	{
		return preg_replace_callback(
			'/style="([^"]*)"/i',
			function (array $m): string {
				$style = $m[1];
				$rewritten = preg_replace_callback(
					'/url\(["\']?([^"\'()]+)["\']?\)/i',
					function (array $u): string {
						$url = $u[1];
						$newUrl = $this->resolveUrl($url);
						return "url('{$newUrl}')";
					},
					$style
				);
				return 'style="' . ($rewritten ?? $style) . '"';
			},
			$html
		);
	}

	/**
	 * Guess alt text from filename if not available.
	 */
	private function guessAlt(string $url): string
	{
		$filename = pathinfo($url, PATHINFO_FILENAME);
		// Convert dashes and underscores to spaces, capitalize
		$alt = str_replace(['-', '_'], ' ', $filename);
		return ucwords($alt);
	}

	/**
	 * Clean up the final Markdown output.
	 */
	private function cleanMarkdown(string $markdown): string
	{
		// Remove excessive blank lines (more than 2)
		$markdown = preg_replace('/\n{3,}/', "\n\n", $markdown);

		// Remove leading/trailing whitespace per line
		$lines = explode("\n", $markdown);
		$lines = array_map('rtrim', $lines);
		$markdown = implode("\n", $lines);

		// Remove trailing whitespace at end
		$markdown = rtrim($markdown) . "\n";

		return $markdown;
	}
}
