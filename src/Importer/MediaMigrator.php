<?php

declare(strict_types=1);

namespace Loom\Importer;

use Intervention\Image\ImageManager;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\Encoders\WebpEncoder;

/**
 * Download media from WordPress, convert to WebP, and manage paths.
 */
class MediaMigrator
{
	private string $outputDir;
	private ImageManager $imageManager;
	private array $urlMap = [];      // old URL → new local path
	private array $altLookup = [];   // attachment_id → alt text
	private array $errors = [];

	public function __construct(string $outputDir)
	{
		$this->outputDir = rtrim($outputDir, '/');
		$this->imageManager = new ImageManager(new Driver());
	}

	/**
	 * Migrate all media items.
	 *
	 * @param array $media List of media items from ContentParser
	 * @param array $siteUrl The WordPress site URL for resolving relative paths
	 * @return array{url_map: array, errors: array}
	 */
	public function migrate(array $media, string $siteUrl = ''): array
	{
		// Build alt text lookup from media items
		foreach ($media as $item) {
			if (!empty($item['post_id']) && !empty($item['alt'])) {
				$this->altLookup[$item['post_id']] = $item['alt'];
			}
		}

		$total = count($media);
		$done = 0;

		foreach ($media as $item) {
			$done++;
			$url = $item['url'] ?? '';
			if (empty($url)) continue;

			// Skip non-image files for WebP conversion
			$mime = $item['mime_type'] ?? '';
			$isImage = str_starts_with($mime, 'image/') || preg_match('/\.(jpe?g|png|gif|webp|svg)$/i', $url);

			try {
				if ($isImage && !str_ends_with(strtolower($url), '.svg')) {
					$this->migrateImage($url, $item, $siteUrl);
				} else {
					$this->migrateFile($url, $item, $siteUrl);
				}
				echo "  [{$done}/{$total}] ✓ {$url}\n";
			} catch (\Throwable $e) {
				$this->errors[] = "{$url}: " . $e->getMessage();
				echo "  [{$done}/{$total}] ✗ {$url} — {$e->getMessage()}\n";
			}
		}

		return [
			'url_map' => $this->urlMap,
			'alt_lookup' => $this->altLookup,
			'errors' => $this->errors,
		];
	}

	/**
	 * Get the URL mapping (old → new).
	 */
	public function getUrlMap(): array
	{
		return $this->urlMap;
	}

	/**
	 * Get alt text for an attachment ID.
	 */
	public function getAlt(int $attachmentId): string
	{
		return $this->altLookup[$attachmentId] ?? '';
	}

	/**
	 * Download and convert an image to WebP.
	 */
	private function migrateImage(string $url, array $item, string $siteUrl): void
	{
		$sourcePath = $this->resolveUrl($url, $siteUrl);
		$yearMonth = $this->extractYearMonth($url);
		$originalName = basename(parse_url($url, PHP_URL_PATH) ?? 'image.jpg');
		$webpName = pathinfo($originalName, PATHINFO_FILENAME) . '.webp';

		$destDir = $this->outputDir . '/public/assets/images/' . $yearMonth;
		if (!is_dir($destDir)) {
			mkdir($destDir, 0755, true);
		}

		$destPath = $destDir . '/' . $webpName;

		// Download if remote
		$localSource = $this->downloadToTemp($sourcePath);

		try {
			$image = $this->imageManager->decodePath($localSource);
			$image->encode(new WebpEncoder(quality: 78))->save($destPath);
		} finally {
			if ($localSource !== $sourcePath && file_exists($localSource)) {
				unlink($localSource);
			}
		}

		$newUrl = '/assets/images/' . $yearMonth . '/' . $webpName;
		$this->urlMap[$url] = $newUrl;

		// Also map common WP size variants
		$this->mapSizeVariants($url, $newUrl);
	}

	/**
	 * Download a non-image file (PDF, etc).
	 */
	private function migrateFile(string $url, array $item, string $siteUrl): void
	{
		$sourcePath = $this->resolveUrl($url, $siteUrl);
		$yearMonth = $this->extractYearMonth($url);
		$filename = basename(parse_url($url, PHP_URL_PATH) ?? 'file');

		$destDir = $this->outputDir . '/public/assets/images/' . $yearMonth;
		if (!is_dir($destDir)) {
			mkdir($destDir, 0755, true);
		}

		$destPath = $destDir . '/' . $filename;
		$localSource = $this->downloadToTemp($sourcePath);

		rename($localSource, $destPath);

		$newUrl = '/assets/images/' . $yearMonth . '/' . $filename;
		$this->urlMap[$url] = $newUrl;
	}

	/**
	 * Map WordPress image size variants (thumbnail, medium, large) to the full-size WebP.
	 */
	private function mapSizeVariants(string $originalUrl, string $newUrl): void
	{
		// WordPress appends -{width}x{height} before the extension
		// e.g., image-150x150.jpg, image-300x200.jpg, image-1024x768.jpg
		$basePath = pathinfo($originalUrl, PATHINFO_DIRNAME);
		$filename = pathinfo($originalUrl, PATHINFO_FILENAME);
		$ext = pathinfo($originalUrl, PATHINFO_EXTENSION);

		// Map common sizes
		$sizes = ['thumbnail' => '150x150', 'medium' => '300x300', 'medium_large' => '768x768', 'large' => '1024x1024'];

		foreach ($sizes as $size) {
			$variantUrl = $basePath . '/' . $filename . '-' . $size . '.' . $ext;
			$this->urlMap[$variantUrl] = $newUrl;
		}
	}

	/**
	 * Resolve a URL to a local or remote path.
	 */
	private function resolveUrl(string $url, string $siteUrl): string
	{
		if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
			return $url;
		}

		// Relative URL — prepend site URL
		return rtrim($siteUrl, '/') . '/' . ltrim($url, '/');
	}

	/**
	 * Download a remote file to a temp location, or return local path.
	 */
	private function downloadToTemp(string $source): string
	{
		if (str_starts_with($source, 'http://') || str_starts_with($source, 'https://')) {
			$tmp = tempnam(sys_get_temp_dir(), 'loom_media_');
			$ch = curl_init($source);
			$fp = fopen($tmp, 'wb');
			curl_setopt_array($ch, [
				CURLOPT_FILE => $fp,
				CURLOPT_FOLLOWLOCATION => true,
				CURLOPT_TIMEOUT => 30,
				CURLOPT_SSL_VERIFYPEER => false,
			]);
			curl_exec($ch);
			$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
			curl_close($ch);
			fclose($fp);

			if ($httpCode !== 200) {
				unlink($tmp);
				throw new \RuntimeException("HTTP {$httpCode} downloading {$source}");
			}

			return $tmp;
		}

		if (!file_exists($source)) {
			throw new \RuntimeException("Local file not found: {$source}");
		}

		return $source;
	}

	/**
	 * Extract year/month from a WordPress media URL.
	 * WP URLs look like: /wp-content/uploads/2024/01/image.jpg
	 */
	private function extractYearMonth(string $url): string
	{
		if (preg_match('/(\d{4})\/(\d{2})/', $url, $m)) {
			return $m[1] . '/' . $m[2];
		}

		return date('Y') . '/' . date('m');
	}
}
