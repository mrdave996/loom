<?php

declare(strict_types=1);

namespace Loom;

use Symfony\Component\Yaml\Yaml;

class SeoGenerator
{
	private string $contentDir;

	public function __construct(string $contentDir)
	{
		$this->contentDir = rtrim($contentDir, '/');
	}

	/**
	 * Handle SEO-related requests (sitemap.xml, robots.txt).
	 *
	 * @return array{header: string, body: string}|null
	 */
	public function handle(string $path): ?array
	{
		return match ($path) {
			'/sitemap.xml' => $this->sitemap(),
			'/robots.txt' => $this->robots(),
			default => null,
		};
	}

	/**
	 * Generate sitemap.xml from all content pages.
	 */
	private function sitemap(): array
	{
		$urls = $this->collectPageUrls();
		$xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
		$xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

		foreach ($urls as $url => $meta) {
			$xml .= "  <url>\n";
			$xml .= "    <loc>{$url}</loc>\n";
			if (!empty($meta['lastmod'])) {
				$xml .= "    <lastmod>{$meta['lastmod']}</lastmod>\n";
			}
			$xml .= "  </url>\n";
		}

		$xml .= '</urlset>';

		return [
			'header' => 'Content-Type: application/xml; charset=utf-8',
			'body' => $xml,
		];
	}

	/**
	 * Generate robots.txt.
	 */
	private function robots(): array
	{
		$body = "User-agent: *\n";
		$body .= "Allow: /\n";
		$body .= "Disallow: /cache/\n";
		$body .= "\n";
		$body .= "Sitemap: /sitemap.xml\n";

		return [
			'header' => 'Content-Type: text/plain; charset=utf-8',
			'body' => $body,
		];
	}

	/**
	 * Collect all page URLs and metadata from content files.
	 *
	 * @return array<string, array{lastmod: string}>
	 */
	private function collectPageUrls(): array
	{
		$urls = [];
		$files = $this->findMarkdownFiles($this->contentDir . '/pages');

		foreach ($files as $file) {
			$relativePath = str_replace($this->contentDir . '/pages/', '', $file);
			$url = $this->pathToUrl($relativePath);
			$lastmod = date('Y-m-d', filemtime($file));

			$urls[$url] = ['lastmod' => $lastmod];
		}

		// Sort by URL
		ksort($urls);

		return $urls;
	}

	/**
	 * Convert a file path to a URL.
	 */
	private function pathToUrl(string $path): string
	{
		// index.md → /, about.md → /about, blog/post.md → /blog/post
		$path = preg_replace('/\.md$/', '', $path);

		if ($path === 'index') {
			return '/';
		}

		return '/' . $path;
	}

	/**
	 * Recursively find all .md files in a directory.
	 *
	 * @return string[]
	 */
	private function findMarkdownFiles(string $dir): array
	{
		if (!is_dir($dir)) {
			return [];
		}

		$files = [];
		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS)
		);

		foreach ($iterator as $file) {
			if ($file->isFile() && $file->getExtension() === 'md') {
				$files[] = $file->getRealPath();
			}
		}

		return $files;
	}
}
