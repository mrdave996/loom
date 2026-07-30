<?php

declare(strict_types=1);

namespace Loom\Importer;

/**
 * Parse UpDraftPlus SQL dump files.
 */
class WpSqlParser implements ContentParser
{
	private string $tablePrefix = 'wp_';

	public function parse(string $source): array
	{
		$sql = file_get_contents($source);
		if ($sql === false) {
			throw new \RuntimeException("Cannot read SQL file: {$source}");
		}

		// Detect table prefix from SQL
		if (preg_match('/INSERT INTO `?(\w+)posts`?/i', $sql, $m)) {
			$this->tablePrefix = preg_replace('/posts$/i', '', $m[1]);
		}

		$tables = $this->parseInsertStatements($sql);

		$postsTable = $this->tablePrefix . 'posts';
		$metaTable = $this->tablePrefix . 'postmeta';
		$optionsTable = $this->tablePrefix . 'options';
		$termsTable = $this->tablePrefix . 'terms';
		$termRelTable = $this->tablePrefix . 'term_relationships';
		$termTaxTable = $this->tablePrefix . 'term_taxonomy';

		// Parse options
		$options = $this->parseOptions($tables[$optionsTable] ?? []);

		// Parse all posts
		$allPosts = $tables[$postsTable] ?? [];
		$allMeta = $tables[$metaTable] ?? [];
		$allTerms = $tables[$termsTable] ?? [];
		$allTermRel = $tables[$termRelTable] ?? [];
		$allTermTax = $tables[$termTaxTable] ?? [];

		// Index postmeta by post_id
		$metaByPost = [];
		foreach ($allMeta as $row) {
			$postId = (int) ($row['post_id'] ?? 0);
			$key = $row['meta_key'] ?? '';
			$value = $row['meta_value'] ?? '';
			if ($postId > 0 && $key !== '') {
				$metaByPost[$postId][$key] = $value;
			}
		}

		// Index taxonomies
		$termNames = [];
		foreach ($allTerms as $row) {
			$termNames[(int) ($row['term_id'] ?? 0)] = $row['name'] ?? '';
		}

		$termTaxonomies = [];
		foreach ($allTermTax as $row) {
			$termTaxonomies[(int) ($row['term_taxonomy_id'] ?? 0)] = [
				'term_id' => (int) ($row['term_id'] ?? 0),
				'taxonomy' => $row['taxonomy'] ?? '',
			];
		}

		// Index term relationships by object_id
		$termsByPost = [];
		foreach ($allTermRel as $row) {
			$objectId = (int) ($row['object_id'] ?? 0);
			$termTaxId = (int) ($row['term_taxonomy_id'] ?? 0);
			if ($objectId > 0 && isset($termTaxonomies[$termTaxId])) {
				$tax = $termTaxonomies[$termTaxId];
				$termName = $termNames[$tax['term_id']] ?? '';
				if ($termName !== '') {
					$termsByPost[$objectId][$tax['taxonomy']][] = $termName;
				}
			}
		}

		$pages = [];
		$posts = [];
		$media = [];
		$menus = [];

		foreach ($allPosts as $row) {
			$postType = $row['post_type'] ?? '';
			$status = $row['post_status'] ?? '';

			if ($status !== 'publish') {
				continue;
			}

			$postId = (int) ($row['ID'] ?? 0);
			$meta = $metaByPost[$postId] ?? [];
			$terms = $termsByPost[$postId] ?? [];

			$entry = [
				'slug' => $row['post_name'] ?? '',
				'title' => $row['post_title'] ?? '',
				'content' => $row['post_content'] ?? '',
				'excerpt' => $row['post_excerpt'] ?? '',
				'date' => $row['post_date'] ?? '',
				'template' => $meta['_wp_page_template'] ?? 'default',
				'meta' => $meta,
				'tags' => $terms['post_tag'] ?? [],
				'categories' => $terms['category'] ?? [],
				'featured_image' => !empty($meta['_thumbnail_id']) ? ['attachment_id' => $meta['_thumbnail_id']] : null,
				'link' => '',
				'post_id' => $postId,
				'parent_id' => (int) ($row['post_parent'] ?? 0),
			];

			switch ($postType) {
				case 'page':
					$pages[] = $entry;
					break;
				case 'post':
					$posts[] = $entry;
					break;
				case 'attachment':
					$media[] = [
						'url' => $row['guid'] ?? '',
						'filename' => basename($row['guid'] ?? ''),
						'alt' => $meta['_wp_attachment_image_alt'] ?? '',
						'post_id' => $postId,
						'mime_type' => $row['post_mime_type'] ?? '',
					];
					break;
				case 'nav_menu_item':
					$menus[] = [
						'label' => $row['post_title'] ?? '',
						'url' => $meta['_menu_item_url'] ?? '',
						'object_id' => (int) ($meta['_menu_item_object_id'] ?? 0),
						'parent_id' => (int) ($meta['_menu_item_menu_item_parent'] ?? 0),
						'post_id' => $postId,
						'type' => $meta['_menu_item_type'] ?? 'custom',
					];
					break;
			}
		}

		// Deduplicate pages and posts by slug
		$pages = $this->deduplicateBySlug($pages);
		$posts = $this->deduplicateBySlug($posts);

		return [
			'pages' => $pages,
			'posts' => $posts,
			'menus' => $this->buildMenuTree($menus),
			'media' => $media,
			'options' => $options,
			'forms' => [],
			'theme' => ['name' => '', 'global_styles' => null],
		];
	}

	/**
	 * Deduplicate items by slug, keeping the most recent.
	 * Also strips numeric suffixes from titles (e.g., "Page (2)" → "Page").
	 */
	private function deduplicateBySlug(array $items): array
	{
		$seen = [];
		$deduped = [];

		foreach ($items as $item) {
			$slug = $item['slug'] ?? '';
			if (empty($slug)) continue;

			// Strip numeric suffix from title (e.g., "1300 Numbers (2)" → "1300 Numbers")
			$item['title'] = preg_replace('/\s*\(\d+\)\s*$/', '', $item['title'] ?? '');

			if (isset($seen[$slug])) {
				if (($item['date'] ?? '') > ($seen[$slug]['date'] ?? '')) {
					$index = $seen[$slug]['_index'];
					$deduped[$index] = $item;
					$seen[$slug] = array_merge($item, ['_index' => $index]);
				}
				continue;
			}

			$seen[$slug] = array_merge($item, ['_index' => count($deduped)]);
			$deduped[] = $item;
		}

		return array_values($deduped);
	}

	/**
	 * Parse INSERT INTO statements from SQL dump into arrays.
	 */
	private function parseInsertStatements(string $sql): array
	{
		$tables = [];
		$pattern = '/INSERT INTO `?(\w+)`?\s*(?:\([^)]*\)\s*)?VALUES\s*(.*?);/is';

		if (preg_match_all($pattern, $sql, $matches, PREG_SET_ORDER)) {
			foreach ($matches as $match) {
				$table = $match[1];
				$valuesBlock = $match[2];

				if (!isset($tables[$table])) {
					$tables[$table] = [];
				}

				// Parse individual value groups
				$rows = $this->parseValuesBlock($valuesBlock);
				$tables[$table] = array_merge($tables[$table], $rows);
			}
		}

		return $tables;
	}

	/**
	 * Parse a VALUES block into individual row arrays.
	 */
	private function parseValuesBlock(string $block): array
	{
		$rows = [];
		// Match each (row) of values
		$pattern = '/\(([^)]+(?:\([^)]*\)[^)]*)*)\)/';

		if (preg_match_all($pattern, $block, $matches)) {
			foreach ($matches[1] as $rowStr) {
				$values = $this->parseRowValues($rowStr);
				if (!empty($values)) {
					$rows[] = $values;
				}
			}
		}

		return $rows;
	}

	/**
	 * Parse a single row of comma-separated SQL values.
	 */
	private function parseRowValues(string $row): array
	{
		$values = [];
		$len = strlen($row);
		$i = 0;

		while ($i < $len) {
			// Skip whitespace
			while ($i < $len && $row[$i] === ' ') {
				$i++;
			}

			if ($i >= $len) break;

			if ($row[$i] === "'") {
				// Quoted string
				$i++;
				$buffer = '';
				while ($i < $len) {
					if ($row[$i] === '\\' && $i + 1 < $len) {
						$buffer .= $row[$i + 1];
						$i += 2;
					} elseif ($row[$i] === "'") {
						if ($i + 1 < $len && $row[$i + 1] === "'") {
							$buffer .= "'";
							$i += 2;
						} else {
							$i++;
							break;
						}
					} else {
						$buffer .= $row[$i];
						$i++;
					}
				}
				$values[] = $buffer;
			} elseif (substr($row, $i, 4) === 'NULL' && ($i + 4 >= $len || $row[$i + 4] === ',' || $row[$i + 4] === ' ')) {
				$values[] = null;
				$i += 4;
			} else {
				// Numeric or unquoted
				$end = strpos($row, ',', $i);
				if ($end === false) $end = $len;
				$values[] = trim(substr($row, $i, $end - $i));
				$i = $end;
			}

			// Skip comma
			if ($i < $len && $row[$i] === ',') {
				$i++;
			}
		}

		return $values;
	}

	/**
	 * Parse options table into key-value pairs.
	 */
	private function parseOptions(array $rows): array
	{
		$options = [];

		foreach ($rows as $row) {
			if (!is_array($row)) continue;

			// Options table: option_id, option_name, option_value, autoload
			if (count($row) >= 3) {
				$name = $row[1] ?? '';
				$value = $row[2] ?? '';
				$options[$name] = $value;
			}
		}

		// Extract key options
		return [
			'site_title' => $options['blogname'] ?? '',
			'blogdescription' => $options['blogdescription'] ?? '',
			'site_url' => $options['siteurl'] ?? '',
			'page_on_front' => (int) ($options['page_on_front'] ?? 0),
		];
	}

	/**
	 * Build a hierarchical menu tree from flat menu items.
	 */
	private function buildMenuTree(array $items): array
	{
		$indexed = [];
		foreach ($items as $item) {
			$indexed[$item['post_id']] = $item;
			$indexed[$item['post_id']]['children'] = [];
		}

		$tree = [];
		foreach ($indexed as $id => $item) {
			if ($item['parent_id'] > 0 && isset($indexed[$item['parent_id']])) {
				$indexed[$item['parent_id']]['children'][] = &$indexed[$id];
			} else {
				$tree[] = &$indexed[$id];
			}
		}

		return $tree;
	}
}
