<?php

declare(strict_types=1);

namespace Loom;

class CacheManager
{
	private const ARTIFACT_PREFIX = 'LOOM-CACHE-V1 ';

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
		$artifact = $this->readArtifact($path);

		return $artifact['html'] ?? ($this->readLegacy($path));
	}

	private function readLegacy(string $path): ?string
	{
		$cacheFile = $this->cachePath($path);
		if (!is_file($cacheFile)) return null;
		$raw = file_get_contents($cacheFile);
		return is_string($raw) ? $raw : null;
	}

	/**
	 * Check if the cache is still valid for the given path.
	 * Compares a stored content fingerprint across source, templates and dependencies.
	 */
	public function isValid(string $path, string $sourceFile, string $templatesDir, array $dependencies = []): bool
	{
		$artifact = $this->readArtifact($path);
		if ($artifact === null) {
			return false;
		}

		$currentFingerprint = $this->dependencyFingerprint($sourceFile, $templatesDir, $dependencies);

		return $currentFingerprint !== null
			&& hash_equals($artifact['fingerprint'], $currentFingerprint);
	}

	/**
	 * Capture the dependency state before rendering begins.
	 */
	public function snapshot(string $sourceFile, string $templatesDir, array $dependencies = []): ?string
	{
		return $this->dependencyFingerprint($sourceFile, $templatesDir, $dependencies);
	}

	/**
	 * Write rendered HTML to cache.
	 */
	public function set(
		string $path,
		string $html,
		string $sourceFile,
		string $templatesDir,
		array $dependencies,
		string $expectedFingerprint
	): bool
	{
		$cacheFile = $this->cachePath($path);
		$dir = dirname($cacheFile);

		if (!is_dir($dir)) {
			mkdir($dir, 0755, true);
		}

		$fingerprint = $sourceFile !== null && $templatesDir !== null
			? $this->dependencyFingerprint($sourceFile, $templatesDir, $dependencies)
			: null;
		if ($expectedFingerprint !== null && ($fingerprint === null || !hash_equals($expectedFingerprint, $fingerprint))) {
			return false;
		}

		$temporaryFile = tempnam($dir, 'loom-cache-');
		if ($temporaryFile === false) {
			return false;
		}

		$artifact = $fingerprint !== null ? self::ARTIFACT_PREFIX . $fingerprint . "\n" . $html : $html;
		if (file_put_contents($temporaryFile, $artifact, LOCK_EX) === false || !rename($temporaryFile, $cacheFile)) {
			if (is_file($temporaryFile)) {
				unlink($temporaryFile);
			}
			return false;
		}

		return true;
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
		foreach (glob($this->cacheDir . '/*.fingerprint') ?: [] as $legacyFingerprint) {
			if (is_file($legacyFingerprint)) {
				unlink($legacyFingerprint);
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

	/**
	 * @return array{fingerprint: string, html: string}|null
	 */
	private function readArtifact(string $path): ?array
	{
		$cacheFile = $this->cachePath($path);
		if (!is_file($cacheFile)) {
			return null;
		}

		$raw = file_get_contents($cacheFile);
		if (!is_string($raw) || !str_starts_with($raw, self::ARTIFACT_PREFIX)) {
			return null;
		}

		$separator = strpos($raw, "\n");
		if ($separator === false) {
			return null;
		}

		$fingerprint = substr($raw, strlen(self::ARTIFACT_PREFIX), $separator - strlen(self::ARTIFACT_PREFIX));
		if (preg_match('/^[a-f0-9]{64}$/', $fingerprint) !== 1) {
			return null;
		}

		return [
			'fingerprint' => $fingerprint,
			'html' => substr($raw, $separator + 1),
		];
	}

	private function dependencyFingerprint(string $sourceFile, string $templatesDir, array $dependencies): ?string
	{
		$records = [];
		try {
			foreach (array_merge([$sourceFile, $templatesDir], $dependencies) as $dependency) {
				if (is_string($dependency) && $dependency !== '') {
					$this->addFingerprintRecords($dependency, $records);
				}
			}
		} catch (\Throwable) {
			return null;
		}

		ksort($records);

		return hash('sha256', json_encode($records, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
	}

	private function addFingerprintRecords(string $path, array &$records): void
	{
		if (!file_exists($path)) {
			$records[$path] = 'missing';
			return;
		}

		if (is_file($path)) {
			$hash = hash_file('sha256', $path);
			if ($hash === false) {
				throw new \RuntimeException('Cannot fingerprint cache dependency: ' . $path);
			}
			$records[$path] = 'file:' . $hash;
			return;
		}

		$records[$path] = 'directory';
		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS),
			\RecursiveIteratorIterator::SELF_FIRST
		);
		foreach ($iterator as $item) {
			$itemPath = $item->getPathname();
			if ($item->isDir()) {
				$records[$itemPath] = 'directory';
				continue;
			}
			if ($item->isFile()) {
				$hash = hash_file('sha256', $itemPath);
				if ($hash === false) {
					throw new \RuntimeException('Cannot fingerprint cache dependency: ' . $itemPath);
				}
				$records[$itemPath] = 'file:' . $hash;
			}
		}
	}
}
