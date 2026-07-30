<?php

declare(strict_types=1);

namespace Loom;

class Router
{
	private string $contentDir;
	private array $redirects = [];

	public function __construct(string $contentDir)
	{
		$this->contentDir = rtrim($contentDir, '/');
		$this->loadRedirects();
	}

	/**
	 * Resolve a URI to a markdown file path.
	 *
	 * @return string|null Resolved file path, or null if not found
	 */
	public function resolve(string $uri): ?string
	{
		$path = parse_url($uri, PHP_URL_PATH) ?? '/';
		$path = $this->cleanPath($path);

		// Try direct match: /about → content/pages/about.md
		$file = $this->contentDir . '/pages/' . $path . '.md';
		if (file_exists($file)) {
			return $file;
		}

		// Try index inside directory: /blog → content/pages/blog/index.md
		$file = $this->contentDir . '/pages/' . $path . '/index.md';
		if (file_exists($file)) {
			return $file;
		}

		// Try posts directory
		$file = $this->contentDir . '/posts/' . $path . '.md';
		if (file_exists($file)) {
			return $file;
		}

		return null;
	}

	/**
	 * Check if a URI has a redirect mapping.
	 *
	 * @return string|null Redirect target path, or null if no redirect
	 */
	public function getRedirect(string $uri): ?string
	{
		$path = parse_url($uri, PHP_URL_PATH) ?? '/';
		$path = rtrim($path, '/');

		return $this->redirects[$path] ?? null;
	}

	/**
	 * Load redirect map from src/redirects.php if it exists.
	 */
	private function loadRedirects(): void
	{
		$redirectFile = dirname($this->contentDir) . '/src/redirects.php';
		if (file_exists($redirectFile)) {
			$this->redirects = require $redirectFile;
		}
	}

	/**
	 * Get the clean URI path with no trailing slash (except root).
	 */
	private function cleanPath(string $path): string
	{
		$path = rawurldecode($path);
		$path = preg_replace('#\.{2,}#', '.', $path); // prevent directory traversal
		$path = rtrim($path, '/');

		if ($path === '' || $path === '/') {
			return 'index';
		}

		// Strip leading slash
		return ltrim($path, '/');
	}
}
