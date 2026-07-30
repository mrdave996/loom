<?php
/**
 * Site config for the scraper.
 *
 * Copy this file and customise for your target site.
 *
 * Required keys:
 *   site_url        — full base URL (no trailing slash)
 *   site_name       — display name for navigation/footer
 *   scrape_dir      — absolute path to the wget mirror directory
 *   nav_selector    — CSS selector(s) for the main <nav> element
 *   footer_selector — CSS selector(s) for the footer element
 *   strip_selectors — additional selectors to strip from content
 *
 * Optional keys:
 *   url_to_slug     — explicit URL→slug map (auto-detected when absent)
 *   nav_links       — hardcoded nav array (extracted from HTML when absent)
 */
return [
	// ── Site identity ──────────────────────────────
	'site_url'  => 'https://www.example.com',
	'site_name' => 'Example',

	// ── Mirror directory (output of scrape-site.sh) ─
	'scrape_dir' => __DIR__ . '/../site/www.example.com',

	// ── Selectors ──────────────────────────────────
	'nav_selector'    => 'nav, .main-navigation, #site-navigation',
	'footer_selector' => 'footer, .site-footer, #colophon',
	'strip_selectors' => [
		'#wpadminbar',
		'.menu-skip-links',
		'.search-form',
	],

	// ── URL mapping (optional — leave empty for auto-detection) ──
	'url_to_slug' => [
		// 'https://www.example.com/about-us' => 'about',
	],

	// ── Hardcoded nav (optional — leave empty for auto-extraction) ──
	'nav_links' => [
		// ['label' => 'Home',  'url' => '/'],
		// ['label' => 'About', 'url' => '/about'],
	],
];