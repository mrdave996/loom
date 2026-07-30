<?php

declare(strict_types=1);

namespace Loom\Importer;

/**
 * Extract metadata from HTML <head> elements.
 *
 * Parses title, description, Open Graph, Twitter Card, LD+JSON structured data,
 * favicon, and site name using DOMDocument for reliable parsing.
 */
class HtmlMetaExtractor
{
	/**
	 * Extract all metadata from an HTML string.
	 *
	 * @return array{
	 *     title: string,
	 *     description: string,
	 *     canonical: string,
	 *     og: array<string, string>,
	 *     twitter: array<string, string>,
	 *     ld_json: array,
	 *     favicon: string,
	 *     site_name: string,
	 * }
	 */
	public function extractAll(string $html): array
	{
		return [
			'title' => $this->extractTitle($html),
			'description' => $this->extractDescription($html),
			'canonical' => $this->extractCanonical($html),
			'og' => $this->extractOpenGraph($html),
			'twitter' => $this->extractTwitterCard($html),
			'ld_json' => $this->extractLdJson($html),
			'favicon' => $this->extractFavicon($html),
			'site_name' => $this->extractSiteName($html),
		];
	}

	/**
	 * Extract the <title> element content.
	 */
	public function extractTitle(string $html): string
	{
		if (preg_match('/<title[^>]*>(.*?)<\/title>/si', $html, $m)) {
			return trim(html_entity_decode($m[1], ENT_QUOTES, 'UTF-8'));
		}
		return '';
	}

	/**
	 * Extract the meta description content.
	 */
	public function extractDescription(string $html): string
	{
		if (preg_match('/<meta[^>]+name=["\']description["\'][^>]+content=["\']([^"\']*)["\'](?:[^>]*>)/si', $html, $m)) {
			return trim(html_entity_decode($m[1], ENT_QUOTES, 'UTF-8'));
		}
		// Try content before name
		if (preg_match('/<meta[^>]+content=["\']([^"\']*)["\'][^>]+name=["\']description["\'](?:[^>]*>)/si', $html, $m)) {
			return trim(html_entity_decode($m[1], ENT_QUOTES, 'UTF-8'));
		}
		return '';
	}

	/**
	 * Extract the canonical URL.
	 */
	public function extractCanonical(string $html): string
	{
		if (preg_match('/<link[^>]+rel=["\']canonical["\'][^>]+href=["\']([^"\']*)["\'](?:[^>]*>)/si', $html, $m)) {
			return trim($m[1]);
		}
		return '';
	}

	/**
	 * Extract all Open Graph meta tags.
	 *
	 * @return array<string, string> Key-value pairs with og: prefix stripped
	 */
	public function extractOpenGraph(string $html): array
	{
		$og = [];
		if (preg_match_all('/<meta[^>]+property=["\'](og:[^"\']+)["\'][^>]+content=["\']([^"\']*)["\'](?:[^>]*>)/si', $html, $matches, PREG_SET_ORDER)) {
			foreach ($matches as $m) {
				$key = str_replace('og:', '', $m[1]);
				$og[$key] = html_entity_decode($m[2], ENT_QUOTES, 'UTF-8');
			}
		}
		// Try content before property
		if (preg_match_all('/<meta[^>]+content=["\']([^"\']*)["\'][^>]+property=["\'](og:[^"\']+)["\'](?:[^>]*>)/si', $html, $matches, PREG_SET_ORDER)) {
			foreach ($matches as $m) {
				$key = str_replace('og:', '', $m[2]);
				if (!isset($og[$key])) {
					$og[$key] = html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
				}
			}
		}
		return $og;
	}

	/**
	 * Extract all Twitter Card meta tags.
	 *
	 * @return array<string, string> Key-value pairs with twitter: prefix stripped
	 */
	public function extractTwitterCard(string $html): array
	{
		$twitter = [];
		if (preg_match_all('/<meta[^>]+name=["\'](twitter:[^"\']+)["\'][^>]+content=["\']([^"\']*)["\'](?:[^>]*>)/si', $html, $matches, PREG_SET_ORDER)) {
			foreach ($matches as $m) {
				$key = str_replace('twitter:', '', $m[1]);
				$twitter[$key] = html_entity_decode($m[2], ENT_QUOTES, 'UTF-8');
			}
		}
		return $twitter;
	}

	/**
	 * Extract and decode all LD+JSON structured data blocks.
	 *
	 * @return array<int, array> Array of decoded JSON objects
	 */
	public function extractLdJson(string $html): array
	{
		$results = [];
		if (preg_match_all('/<script[^>]+type=["\']application\/ld\+json["\'][^>]*>(.*?)<\/script>/si', $html, $matches)) {
			foreach ($matches[1] as $block) {
				$decoded = json_decode(trim($block), true);
				if (is_array($decoded)) {
					$results[] = $decoded;
				}
			}
		}
		return $results;
	}

	/**
	 * Extract the favicon href.
	 */
	public function extractFavicon(string $html): string
	{
		// Try rel="icon" first
		if (preg_match('/<link[^>]+rel=["\'](?:shortcut )?icon["\'][^>]+href=["\']([^"\']+)["\'](?:[^>]*>)/si', $html, $m)) {
			return trim($m[1]);
		}
		// Try href before rel
		if (preg_match('/<link[^>]+href=["\']([^"\']+)["\'][^>]+rel=["\'](?:shortcut )?icon["\'](?:[^>]*>)/si', $html, $m)) {
			return trim($m[1]);
		}
		return '';
	}

	/**
	 * Extract the site name from og:site_name or fall back to title.
	 */
	public function extractSiteName(string $html): string
	{
		// Try og:site_name
		if (preg_match('/<meta[^>]+property=["\']og:site_name["\'][^>]+content=["\']([^"\']*)["\'](?:[^>]*>)/si', $html, $m)) {
			return trim(html_entity_decode($m[1], ENT_QUOTES, 'UTF-8'));
		}

		// Fall back to first part of <title>
		$title = $this->extractTitle($html);
		if (str_contains($title, ' – ') || str_contains($title, ' - ') || str_contains($title, ' | ')) {
			$parts = preg_split('/\s*[–\-|]\s*/', $title, 2);
			// Site name is usually the shorter part (or the second part)
			return trim($parts[1] ?? $parts[0]);
		}

		return $title;
	}

	/**
	 * Extract the date published from LD+JSON or meta tags.
	 */
	public function extractDatePublished(string $html): string
	{
		// Try LD+JSON first
		$ldJson = $this->extractLdJson($html);
		foreach ($ldJson as $item) {
			if (!empty($item['datePublished'])) {
				return substr($item['datePublished'], 0, 10);
			}
		}

		// Try article:published_time
		if (preg_match('/<meta[^>]+property=["\']article:published_time["\'][^>]+content=["\']([^"\']*)["\'](?:[^>]*>)/si', $html, $m)) {
			return substr($m[1], 0, 10);
		}

		return '';
	}
}
