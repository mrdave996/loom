<?php

declare(strict_types=1);

namespace Loom\Importer;

/**
 * Generate redirect rules for changed URLs.
 */
class RedirectMap
{
	private string $outputDir;

	public function __construct(string $outputDir)
	{
		$this->outputDir = rtrim($outputDir, '/');
	}

	/**
	 * Generate a redirect map from old WordPress URLs to new Loom paths.
	 *
	 * @param array $pages Parsed pages with original links
	 * @param array $posts Parsed posts with original links
	 * @param string $siteUrl WordPress site URL
	 * @param array $extra Additional redirects to merge (e.g., front page slug → /)
	 */
	public function generate(array $pages, array $posts, string $siteUrl = '', array $extra = []): string
	{
		$redirects = $extra;
		$siteUrl = rtrim($siteUrl, '/');

		foreach (array_merge($pages, $posts) as $item) {
			$oldLink = $item['link'] ?? '';
			$slug = $item['slug'] ?? '';

			if (empty($oldLink) || empty($slug)) continue;

			// Extract path from old link
			$oldPath = parse_url($oldLink, PHP_URL_PATH) ?? '/';
			$oldPath = rtrim($oldPath, '/');

			// New path
			$isPost = !empty($item['categories']) || !empty($item['taxonomies']);
			$newPath = $isPost ? '/blog/' . $slug : '/' . $slug;

			// Only add redirect if paths differ
			if ($oldPath !== $newPath && $oldPath !== '') {
				$redirects[$oldPath] = $newPath;
			}
		}

		// Sort by old path for readability
		ksort($redirects);

		// Generate PHP file
		$php = "<?php\n";
		$php .= "/**\n";
		$php .= " * Redirect map — auto-generated from WordPress migration.\n";
		$php .= " * Maps old WordPress URLs to new Loom paths.\n";
		$php .= " */\n\n";
		$php .= "return [\n";

		foreach ($redirects as $old => $new) {
			$php .= "\t'" . addslashes($old) . "' => '" . addslashes($new) . "',\n";
		}

		$php .= "];\n";

		$destPath = $this->outputDir . '/src/redirects.php';
		file_put_contents($destPath, $php);

		return $destPath;
	}
}
