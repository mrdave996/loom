<?php

declare(strict_types=1);

namespace Loom\Importer;

/**
 * Parse WordPress XML (WXR) export files.
 */
class WpXmlParser implements ContentParser
{
	public function parse(string $source): array
	{
		$xml = simplexml_load_file($source);
		if ($xml === false) {
			throw new \RuntimeException("Cannot parse XML file: {$source}");
		}

		$ns = $xml->getNamespaces(true);

		$pages = [];
		$posts = [];
		$menus = [];
		$media = [];
		$options = [];
		$themeData = ['name' => '', 'global_styles' => null];

		// Parse channel metadata
		$options['site_title'] = (string) ($xml->channel->title ?? '');
		$options['blogdescription'] = (string) ($xml->channel->description ?? '');
		$options['site_url'] = (string) ($xml->channel->link ?? '');

		// Extract theme name from wp:term taxonomy
		$wpNs = $ns['wp'] ?? '';
		foreach ($xml->channel->children($wpNs)->term as $term) {
			if ((string) $term->children($wpNs)->term_taxonomy === 'wp_theme') {
				$themeData['name'] = (string) $term->children($wpNs)->term_slug;
				break;
			}
		}

		// Parse items
		foreach ($xml->channel->item as $item) {
			$itemNs = $item->getNamespaces(true);
			$wp = $itemNs['wp'] ?? $ns['wp'] ?? '';
			$content = $itemNs['content'] ?? $ns['content'] ?? '';
			$excerpt = $itemNs['excerpt'] ?? $ns['excerpt'] ?? '';

			$postType = (string) $item->children($wp)->post_type;
			$status = (string) $item->children($wp)->status;

			// Skip non-published items (drafts, trash, revisions)
			$alwaysInclude = ['attachment', 'nav_menu_item', 'wp_global_styles', 'wp_navigation'];
			if ($status !== 'publish' && !in_array($postType, $alwaysInclude)) {
				continue;
			}

			switch ($postType) {
				case 'page':
					$pages[] = $this->parseItem($item, $wp, $content, $excerpt);
					break;

				case 'post':
					$posts[] = $this->parseItem($item, $wp, $content, $excerpt);
					break;

				case 'attachment':
					$media[] = $this->parseAttachment($item, $wp, $content);
					break;

				case 'nav_menu_item':
					$menus[] = $this->parseMenuItem($item, $wp);
					break;

				case 'wp_global_styles':
					$json = (string) $item->children($content)->encoded;
					$decoded = json_decode($json, true);
					if (is_array($decoded) && json_last_error() === JSON_ERROR_NONE) {
						$themeData['global_styles'] = $decoded;
					}
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
			'forms' => [], // Forms detected during shortcode processing
			'theme' => $themeData,
		];
	}

	private function parseItem(\SimpleXMLElement $item, \SimpleXMLElement|string $wpNs, \SimpleXMLElement|string $contentNs, \SimpleXMLElement|string $excerptNs): array
	{
		$wp = $item->children($wpNs);
		$postMeta = [];

		// Parse postmeta
		foreach ($wp->postmeta as $meta) {
			$key = (string) $meta->meta_key;
			$value = (string) $meta->meta_value;
			$postMeta[$key] = $value;
		}

		// Extract categories and tags
		$tags = [];
		$categories = [];
		foreach ($item->category as $cat) {
			$domain = (string) $cat['domain'];
			$name = (string) $cat['nicename'];
			if ($domain === 'post_tag') {
				$tags[] = $name;
			} elseif ($domain === 'category') {
				$categories[] = $name;
			}
		}

		// Get featured image URL
		$featuredImage = null;
		if (!empty($postMeta['_thumbnail_id'])) {
			$featuredImage = ['attachment_id' => $postMeta['_thumbnail_id']];
		}

		return [
			'slug' => (string) $wp->post_name,
			'title' => (string) $item->title,
			'content' => (string) $item->children($contentNs)->encoded,
			'excerpt' => (string) $item->children($excerptNs)->encoded,
			'date' => (string) $wp->post_date,
			'template' => $postMeta['_wp_page_template'] ?? 'default',
			'meta' => $postMeta,
			'tags' => $tags,
			'categories' => $categories,
			'featured_image' => $featuredImage,
			'link' => (string) $item->link,
			'post_id' => (int) $wp->post_id,
			'parent_id' => (int) $wp->post_parent,
		];
	}

	private function parseAttachment(\SimpleXMLElement $item, \SimpleXMLElement|string $wpNs, \SimpleXMLElement|string $contentNs): array
	{
		$wp = $item->children($wpNs);
		$postMeta = [];

		foreach ($wp->postmeta as $meta) {
			$key = (string) $meta->meta_key;
			$value = (string) $meta->meta_value;
			$postMeta[$key] = $value;
		}

		// Use wp:attachment_url (actual file URL) instead of <link> (attachment page URL)
		$attachmentUrl = (string) $wp->attachment_url;
		if (empty($attachmentUrl)) {
			$attachmentUrl = (string) $item->link;
		}

		$result = [
			'url' => $attachmentUrl,
			'filename' => (string) $wp->post_name,
			'alt' => $postMeta['_wp_attachment_image_alt'] ?? '',
			'post_id' => (int) $wp->post_id,
			'mime_type' => (string) $wp->post_mime_type,
		];

		// Parse attachment metadata for dimensions and size variants
		if (!empty($postMeta['_wp_attachment_metadata'])) {
			$metadata = @unserialize($postMeta['_wp_attachment_metadata']);
			if (is_array($metadata)) {
				$result['width'] = $metadata['width'] ?? 0;
				$result['height'] = $metadata['height'] ?? 0;
				$result['file'] = $metadata['file'] ?? '';
				$result['sizes'] = $metadata['sizes'] ?? [];
			}
		}

		return $result;
	}

	private function parseMenuItem(\SimpleXMLElement $item, \SimpleXMLElement|string $wpNs): array
	{
		$wp = $item->children($wpNs);
		$postMeta = [];

		foreach ($wp->postmeta as $meta) {
			$key = (string) $meta->meta_key;
			$value = (string) $meta->meta_value;
			$postMeta[$key] = $value;
		}

		return [
			'label' => (string) $item->title,
			'url' => $postMeta['_menu_item_url'] ?? (string) $item->link,
			'object_id' => (int) ($postMeta['_menu_item_object_id'] ?? 0),
			'parent_id' => (int) ($postMeta['_menu_item_menu_item_parent'] ?? 0),
			'post_id' => (int) $wp->post_id,
			'type' => $postMeta['_menu_item_type'] ?? 'custom',
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
				// Keep the one with the more recent date
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
