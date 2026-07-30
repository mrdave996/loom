<?php

declare(strict_types=1);

namespace Loom;

class CacheManager
{
	private string $cacheDir;

	public function __construct(string $cacheDir)
	{
		$this->cacheDir = rtrim($cacheDir, '/');
		if (!is_dir($this->cacheDir)) {
			mkdir($this->cacheDir, 0755, true);
		}
	}

	/**
	 * Get cached HTML for a request path, or null if stale/missing.
	 */
	public function get(string $path): ?string
	{
		$cacheFile = $this->cachePath($path);

		if (!file_exists($cacheFile)) {
			return null;
		}

		return file_get_contents($cacheFile);
	}

	/**
	 * Check if the cache is still valid for the given path.
	 * Compares cache file mtime against source file and template mtimes.
	 */
	public function isValid(string $path, string $sourceFile, string $templatesDir): bool
	{
		$cacheFile = $this->cachePath($path);

		if (!file_exists($cacheFile)) {
			return false;
		}

		$cacheMtime = filemtime($cacheFile);

		// Source file must be older than cache
		if (file_exists($sourceFile) && filemtime($sourceFile) > $cacheMtime) {
			return false;
		}

		// Check all template files (layouts + partials)
		$templateFiles = array_merge(
			glob($templatesDir . '/layouts/*.php') ?: [],
			glob($templatesDir . '/partials/*.php') ?: []
		);

		foreach ($templateFiles as $templateFile) {
			if (file_exists($templateFile) && filemtime($templateFile) > $cacheMtime) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Write rendered HTML to cache.
	 */
	public function set(string $path, string $html): void
	{
		$cacheFile = $this->cachePath($path);
		$dir = dirname($cacheFile);

		if (!is_dir($dir)) {
			mkdir($dir, 0755, true);
		}

		file_put_contents($cacheFile, $html);
	}

	/**
	 * Clear all cached files.
	 */
	public function clear(): int
	{
		$files = glob($this->cacheDir . '/*.html') ?: [];
		$count = 0;

		foreach ($files as $file) {
			if (is_file($file)) {
				unlink($file);
				$count++;
			}
		}

		return $count;
	}

	/**
	 * Build the cache file path for a request URI.
	 */
	private function cachePath(string $path): string
	{
		// Normalize: /about/ → about, / → index
		$path = trim($path, '/');
		if ($path === '') {
			$path = 'index';
		}

		// Sanitize: replace slashes with dashes for flat cache files
		$path = str_replace('/', '-', $path);

		return $this->cacheDir . '/' . $path . '.html';
	}
}
