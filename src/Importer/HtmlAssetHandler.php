<?php

declare(strict_types=1);

namespace Loom\Importer;

/**
 * Discover and manage local assets from a static HTML site.
 *
 * Scans the assets/ directory and HTML references to build a complete
 * media inventory. Copies files to the Loom output directory.
 */
class HtmlAssetHandler
{
	/**
	 * Discover all media assets in the source directory.
	 *
	 * Scans the assets/ directory and any images referenced in HTML files.
	 * Returns items compatible with ContentParser media format.
	 *
	 * @param string $sourceDir    Absolute path to the source site root
	 * @param array  $allHtmlFiles Array of HTML file paths
	 * @return array<int, array{url: string, filename: string, alt: string, mime_type: string, local_path: string}>
	 */
	public function discoverAssets(string $sourceDir, array $allHtmlFiles): array
	{
		$sourceDir = rtrim($sourceDir, '/');
		$assets = [];
		$seen = [];

		// Scan the assets/ directory
		$assetsDir = $sourceDir . '/assets';
		if (is_dir($assetsDir)) {
			$iterator = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator($assetsDir, \RecursiveDirectoryIterator::SKIP_DOTS)
			);

			foreach ($iterator as $file) {
				if (!$file->isFile()) continue;

				$relativePath = 'assets/' . substr($file->getPathname(), strlen($assetsDir) + 1);
				$absolutePath = $file->getPathname();

				if (isset($seen[$relativePath])) continue;
				$seen[$relativePath] = true;

				$mimeType = $this->guessMimeType($absolutePath);
				$assets[] = [
					'url' => '/' . $relativePath,
					'filename' => $file->getFilename(),
					'alt' => $this->guessAlt($file->getFilename()),
					'mime_type' => $mimeType,
					'local_path' => $absolutePath,
				];
			}
		}

		// Scan HTML files for referenced images not in assets/
		foreach ($allHtmlFiles as $htmlFile) {
			$html = file_get_contents($htmlFile);
			if ($html === false) continue;

			$imageUrls = $this->scanHtmlForImages($html);
			foreach ($imageUrls as $url) {
				// Skip external URLs
				if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) continue;
				if (str_starts_with($url, 'data:')) continue;

				// Normalize the URL
				$url = '/' . ltrim($url, '/');

				if (isset($seen[$url])) continue;

				// Try to resolve to a local file
				$localPath = $this->resolveLocalPath($url, $sourceDir);
				if ($localPath === null) continue;

				$seen[$url] = true;
				$assets[] = [
					'url' => $url,
					'filename' => basename($localPath),
					'alt' => $this->guessAlt(basename($localPath)),
					'mime_type' => $this->guessMimeType($localPath),
					'local_path' => $localPath,
				];
			}
		}

		return $assets;
	}

	/**
	 * Scan HTML content for referenced image URLs.
	 *
	 * @return array<int, string> Array of image URLs
	 */
	public function scanHtmlForImages(string $html): array
	{
		$urls = [];

		// <img src="...">
		if (preg_match_all('/<img\b[^>]+src=["\']([^"\']+)["\']/', $html, $matches)) {
			$urls = array_merge($urls, $matches[1]);
		}

		// <source src="...">
		if (preg_match_all('/<source\b[^>]+src=["\']([^"\']+)["\']/', $html, $matches)) {
			$urls = array_merge($urls, $matches[1]);
		}

		// <video poster="...">
		if (preg_match_all('/<video\b[^>]+poster=["\']([^"\']+)["\']/', $html, $matches)) {
			$urls = array_merge($urls, $matches[1]);
		}

		// background-image: url(...)
		if (preg_match_all('/url\(["\']?([^"\'()]+)["\']?\)/i', $html, $matches)) {
			foreach ($matches[1] as $url) {
				if (preg_match('/\.(jpe?g|png|gif|webp|svg|avif|mp4|webm)$/i', $url)) {
					$urls[] = $url;
				}
			}
		}

		return array_unique($urls);
	}

	/**
	 * Resolve a relative asset URL to an absolute local filesystem path.
	 */
	public function resolveLocalPath(string $url, string $sourceDir): ?string
	{
		$url = ltrim($url, '/');

		// Try relative to source root
		$path = $sourceDir . '/' . $url;
		if (file_exists($path)) return $path;

		// Try without query string
		$path = $sourceDir . '/' . preg_replace('/\?.*$/', '', $url);
		if (file_exists($path)) return $path;

		return null;
	}

	/**
	 * Copy all discovered assets to the output directory.
	 *
	 * @param array       $media     Media items from discoverAssets()
	 * @param string      $outputDir The Loom output directory
	 * @param string      $sourceDir The source site root
	 * @return array{url_map: array<string, string>, errors: array<string>}
	 */
	public function copyAssets(array $media, string $outputDir, string $sourceDir): array
	{
		$outputDir = rtrim($outputDir, '/');
		$urlMap = [];
		$errors = [];

		foreach ($media as $item) {
			$url = $item['url'] ?? '';
			$localPath = $item['local_path'] ?? '';
			$mime = $item['mime_type'] ?? '';

			if (empty($url) || empty($localPath) || !file_exists($localPath)) {
				if (!empty($url)) {
					$errors[] = "Asset not found: {$url}";
				}
				continue;
			}

			// Determine destination subdirectory by type
			$subDir = $this->getSubDirectory($mime, $url);
			$destDir = $outputDir . '/public/assets/' . $subDir;

			if (!is_dir($destDir)) {
				mkdir($destDir, 0755, true);
			}

			$filename = basename($localPath);
			$destPath = $destDir . '/' . $filename;

			// Skip if already exists
			if (file_exists($destPath)) {
				$newUrl = '/assets/' . $subDir . '/' . $filename;
				$urlMap[$url] = $newUrl;
				continue;
			}

			try {
				if (!copy($localPath, $destPath)) {
					$errors[] = "Failed to copy: {$url}";
					continue;
				}

				$newUrl = '/assets/' . $subDir . '/' . $filename;
				$urlMap[$url] = $newUrl;
			} catch (\Throwable $e) {
				$errors[] = "{$url}: " . $e->getMessage();
			}
		}

		return [
			'url_map' => $urlMap,
			'errors' => $errors,
		];
	}

	/**
	 * Guess MIME type from file extension.
	 */
	private function guessMimeType(string $path): string
	{
		$ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

		$map = [
			'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
			'png' => 'image/png', 'gif' => 'image/gif',
			'webp' => 'image/webp', 'svg' => 'image/svg+xml',
			'avif' => 'image/avif', 'ico' => 'image/x-icon',
			'mp4' => 'video/mp4', 'webm' => 'video/webm',
			'mp3' => 'audio/mpeg', 'wav' => 'audio/wav',
			'woff' => 'font/woff', 'woff2' => 'font/woff2',
			'ttf' => 'font/ttf', 'otf' => 'font/otf',
			'eot' => 'application/vnd.ms-fontobject',
			'css' => 'text/css', 'js' => 'application/javascript',
			'pdf' => 'application/pdf',
		];

		return $map[$ext] ?? 'application/octet-stream';
	}

	/**
	 * Guess alt text from filename.
	 */
	private function guessAlt(string $filename): string
	{
		$name = pathinfo($filename, PATHINFO_FILENAME);
		$name = str_replace(['-', '_'], ' ', $name);
		return ucwords($name);
	}

	/**
	 * Determine the output subdirectory based on MIME type and URL.
	 */
	private function getSubDirectory(string $mime, string $url): string
	{
		if (str_starts_with($mime, 'image/')) return 'images';
		if (str_starts_with($mime, 'video/')) return 'video';
		if (str_starts_with($mime, 'audio/')) return 'audio';
		if (str_starts_with($mime, 'font/') || str_contains($mime, 'font')) return 'fonts';

		// Check URL path for hints
		if (str_contains($url, '/img/') || str_contains($url, '/images/')) return 'images';
		if (str_contains($url, '/video/')) return 'video';
		if (str_contains($url, '/fonts/') || str_contains($url, '/font/')) return 'fonts';
		if (str_contains($url, '/js/')) return 'js';

		return 'images'; // default
	}
}
