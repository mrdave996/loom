<?php

declare(strict_types=1);

namespace Loom\Importer;

/**
 * Process WordPress shortcodes, converting or stripping them.
 */
class ShortcodeHandler
{
	/**
	 * Process all shortcodes in content.
	 */
	public function process(?string $content): string
	{
		if ($content === null || $content === '') return '';

		// Process paired shortcodes first: [name attr]...[/name]
		$replaced = preg_replace_callback(
			'/\[([a-z_]+)([^\]]*)\](.*?)\[\/\1\]/si',
			fn(array $m) => $this->handlePaired($m[1], $m[2], $m[3]),
			$content
		);
		if ($replaced !== null) $content = $replaced;

		// Process self-closing shortcodes: [name attr] or [name attr /]
		$replaced = preg_replace_callback(
			'/\[([a-z_]+)([^\]]*?)\s*\/?\]/s',
			fn(array $m) => $this->handleSelfClosing($m[1], $m[2]),
			$content
		);
		if ($replaced !== null) $content = $replaced;

		return $content;
	}

	private function handlePaired(string $name, string $attrs, string $inner): string
	{
		return match ($name) {
			'caption' => $this->handleCaption($inner),
			'gallery' => $this->handleGallery($attrs),
			default => "<!-- shortcode removed: {$name} -->",
		};
	}

	private function handleSelfClosing(string $name, string $attrs): string
	{
		return match ($name) {
			'year' => (string) date('Y'),
			'hera_script', 'kronos_script' => '', // Deprecated — strip entirely
			'contact-form-7' => $this->handleContactForm7($attrs),
			'wpforms' => $this->handleWpForms($attrs),
			'button' => $this->handleButton($attrs),
			'embed' => '', // Handled by oEmbed
			default => "<!-- shortcode removed: {$name} -->",
		};
	}

	/**
	 * Convert [caption] to image + caption text.
	 */
	private function handleCaption(string $inner): string
	{
		// Extract image and caption text
		$inner = trim($inner);
		if (preg_match('/^(<img[^>]+>)(.*)$/si', $inner, $m)) {
			return $m[1] . "\n*" . trim(strip_tags($m[2])) . "*\n";
		}
		return $inner;
	}

	/**
	 * Convert [gallery] to a list of images.
	 */
	private function handleGallery(string $attrs): string
	{
		$ids = $this->extractAttr($attrs, 'ids');
		if (empty($ids)) {
			return '';
		}

		// Gallery will be resolved during media migration
		return "<!-- gallery ids=\"{$ids}\" -->";
	}

	/**
	 * Convert [contact-form-7] to a form placeholder.
	 */
	private function handleContactForm7(string $attrs): string
	{
		$id = $this->extractAttr($attrs, 'id');
		$title = $this->extractAttr($attrs, 'title');
		return "<!-- form:cf7:{$id}:{$title} -->";
	}

	/**
	 * Convert [wpforms] to a form placeholder.
	 */
	private function handleWpForms(string $attrs): string
	{
		$id = $this->extractAttr($attrs, 'id');
		return "<!-- form:wpforms:{$id} -->";
	}

	/**
	 * Convert [button] to a Markdown link.
	 */
	private function handleButton(string $attrs): string
	{
		$url = $this->extractAttr($attrs, 'url') ?: '#';
		$text = $this->extractAttr($attrs, 'text') ?: 'Click here';
		return "[**{$text}**]({$url})";
	}

	/**
	 * Extract an attribute value from a shortcode attribute string.
	 */
	private function extractAttr(string $attrs, string $name): string
	{
		if (preg_match('/' . $name . '=["\']([^"\']*)["\']/i', $attrs, $m)) {
			return $m[1];
		}
		return '';
	}
}
