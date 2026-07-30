<?php

declare(strict_types=1);

namespace Loom\Importer;

/**
 * Extract navigation links and footer columns from HTML markup.
 *
 * Identifies shared nav/footer chrome by comparing across multiple pages.
 * Returns data in the format expected by NavigationBuilder and Loom templates.
 */
class HtmlNavExtractor
{
	/**
	 * Extract navigation links from a page's HTML.
	 *
	 * Looks for <header> or <nav> elements and extracts <a> tags.
	 *
	 * @return array<int, array{label: string, url: string}>
	 */
	public function extractNav(string $html): array
	{
		// Try to find <header> first, then standalone <nav>
		$navHtml = $this->extractElement($html, 'header');
		if (empty($navHtml)) {
			$navHtml = $this->extractElement($html, 'nav');
		}
		if (empty($navHtml)) return [];

		return $this->extractLinks($navHtml);
	}

	/**
	 * Extract footer links from a page's HTML.
	 *
	 * Looks for column-based footer layouts with headings and link lists.
	 *
	 * @return array<int, array{label: string, links: array<int, array{label: string, url: string}>}>
	 */
	public function extractFooter(string $html): array
	{
		$footerHtml = $this->extractElement($html, 'footer');
		if (empty($footerHtml)) return [];

		// Try to find column groups (h2/h3/h4 + sibling <ul>)
		$columns = $this->extractFooterColumns($footerHtml);
		if (!empty($columns)) return $columns;

		// Fallback: flat list of links
		$links = $this->extractLinks($footerHtml);
		if (!empty($links)) {
			return [['label' => 'Links', 'links' => $links]];
		}

		return [];
	}

	/**
	 * Detect shared nav HTML by comparing <header> blocks across pages.
	 *
	 * Returns the common header HTML so it can be stripped from content extraction.
	 *
	 * @param array<int, string> $allHtml Array of full HTML strings
	 */
	public function detectSharedNav(array $allHtml): string
	{
		if (count($allHtml) < 2) return '';

		$headers = [];
		foreach ($allHtml as $html) {
			$header = $this->extractElement($html, 'header');
			$headers[] = $header ?: '';
		}

		// Find the longest common prefix/suffix
		$common = $headers[0];
		for ($i = 1; $i < count($headers); $i++) {
			$common = $this->longestCommonSubstring($common, $headers[$i]);
			if (empty($common)) return '';
		}

		return $common;
	}

	/**
	 * Detect shared footer HTML by comparing <footer> blocks across pages.
	 *
	 * @param array<int, string> $allHtml Array of full HTML strings
	 */
	public function detectSharedFooter(array $allHtml): string
	{
		if (count($allHtml) < 2) return '';

		$footers = [];
		foreach ($allHtml as $html) {
			$footer = $this->extractElement($html, 'footer');
			$footers[] = $footer ?: '';
		}

		$common = $footers[0];
		for ($i = 1; $i < count($footers); $i++) {
			$common = $this->longestCommonSubstring($common, $footers[$i]);
			if (empty($common)) return '';
		}

		return $common;
	}

	/**
	 * Normalize a URL extracted from an <a> tag.
	 */
	public function normalizeUrl(string $url): string
	{
		$url = trim($url);

		// Skip non-HTTP schemes
		if (preg_match('/^(tel|mailto|javascript|data):/i', $url)) {
			return preg_replace('/\s+/', '', $url);
		}

		// Strip protocol and host if present
		if (preg_match('#^https?://[^/]+(/.*)?$#', $url, $m)) {
			$url = $m[1] ?? '/';
		}

		// Normalize
		$url = rtrim($url, '/');
		if (empty($url)) $url = '/';

		// Ensure leading slash
		if (!str_starts_with($url, '/') && !str_starts_with($url, '#')) {
			$url = '/' . $url;
		}

		return $url;
	}

	/**
	 * Extract the inner HTML of an element by tag name.
	 */
	private function extractElement(string $html, string $tag): string
	{
		// Match the element and its content
		if (preg_match('/<' . $tag . '\b[^>]*>(.*?)<\/' . $tag . '>/si', $html, $m)) {
			return $m[1];
		}
		return '';
	}

	/**
	 * Extract all <a> links from an HTML fragment.
	 *
	 * @return array<int, array{label: string, url: string}>
	 */
	private function extractLinks(string $html): array
	{
		$links = [];

		if (preg_match_all('/<a\b[^>]+href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/si', $html, $matches, PREG_SET_ORDER)) {
			foreach ($matches as $m) {
				$url = $this->normalizeUrl($m[1]);
				$label = trim(strip_tags($m[2]));

				// Skip empty labels, anchor-only links, and hidden elements
				if (empty($label) || $url === '#' || str_starts_with($url, '#')) continue;

				$links[] = [
					'label' => $label,
					'url' => $url,
				];
			}
		}

		return $links;
	}

	/**
	 * Extract footer columns from a footer HTML fragment.
	 *
	 * Looks for heading + list pairs that represent column groups.
	 *
	 * @return array<int, array{label: string, links: array<int, array{label: string, url: string}>}>
	 */
	private function extractFooterColumns(string $html): array
	{
		$columns = [];

		// Pattern 1: <div> with heading + <ul> inside
		if (preg_match_all(
			'/<(?:div|section)\b[^>]*>\s*<(?:h[2-4]|p|strong)\b[^>]*>(.*?)<\/(?:h[2-4]|p|strong)>\s*<ul\b[^>]*>(.*?)<\/ul>/si',
			$html,
			$matches,
			PREG_SET_ORDER
		)) {
			foreach ($matches as $m) {
				$label = trim(strip_tags($m[1]));
				if (empty($label)) continue;

				$links = [];
				if (preg_match_all('/<a\b[^>]+href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/si', $m[2], $linkMatches, PREG_SET_ORDER)) {
					foreach ($linkMatches as $lm) {
						$url = $this->normalizeUrl($lm[1]);
						$linkLabel = trim(strip_tags($lm[2]));
						if (!empty($linkLabel) && $url !== '#') {
							$links[] = ['label' => $linkLabel, 'url' => $url];
						}
					}
				}

				if (!empty($links)) {
					$columns[] = ['label' => $label, 'links' => $links];
				}
			}
		}

		return $columns;
	}

	/**
	 * Find the longest common substring between two strings.
	 *
	 * Used to extract shared nav/footer chrome across pages.
	 */
	private function longestCommonSubstring(string $a, string $b): string
	{
		if (empty($a) || empty($b)) return '';

		$aLen = strlen($a);
		$bLen = strlen($b);

		// For very long strings, use a simpler approach: common prefix + common suffix
		$prefix = '';
		$minLen = min($aLen, $bLen);
		for ($i = 0; $i < $minLen; $i++) {
			if ($a[$i] === $b[$i]) {
				$prefix .= $a[$i];
			} else {
				break;
			}
		}

		// If the common prefix is substantial (>100 chars), use it
		if (strlen($prefix) > 100) {
			return $prefix;
		}

		// Otherwise, try common suffix
		$suffix = '';
		for ($i = 1; $i <= $minLen; $i++) {
			if ($a[$aLen - $i] === $b[$bLen - $i]) {
				$suffix = $a[$aLen - $i] . $suffix;
			} else {
				break;
			}
		}

		if (strlen($suffix) > 100) {
			return $suffix;
		}

		// Return whichever is longer
		return strlen($prefix) >= strlen($suffix) ? $prefix : $suffix;
	}
}
