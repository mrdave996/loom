<?php

declare(strict_types=1);

namespace Loom\Importer;

/**
 * Extract page-specific content from HTML.
 *
 * Isolates <main> content, strips shared nav/footer chrome,
 * detects forms, and strips scripts and presentation markup.
 */
class HtmlContentExtractor
{
	/**
	 * Extract the main content from a page's HTML.
	 *
	 * Prefers <main> element. Falls back to <body> content minus shared chrome.
	 */
	public function extractMainContent(string $html, string $sharedNav = '', string $sharedFooter = ''): string
	{
		// Try <main> first
		if (preg_match('/<main\b[^>]*>(.*?)<\/main>/si', $html, $m)) {
			return trim($m[1]);
		}

		// Fall back to <body> content
		$content = $html;
		if (preg_match('/<body\b[^>]*>(.*?)<\/body>/si', $html, $m)) {
			$content = $m[1];
		}

		// Strip shared nav and footer if provided
		if (!empty($sharedNav)) {
			$content = str_replace($sharedNav, '', $content);
		}
		if (!empty($sharedFooter)) {
			$content = str_replace($sharedFooter, '', $content);
		}

		// Strip <header> and <footer> tags (generic fallback)
		$content = preg_replace('/<header\b[^>]*>.*?<\/header>/si', '', $content);
		$content = preg_replace('/<footer\b[^>]*>.*?<\/footer>/si', '', $content);

		return trim($content);
	}

	/**
	 * Strip scripts, noscript, and presentation-only markup from content.
	 *
	 * More aggressive than HtmlToMarkdown's cleanGeneric — applied before
	 * the HTML-to-Markdown conversion.
	 */
	public function stripScripts(string $html): string
	{
		// Remove script tags
		$html = preg_replace('/<script\b[^>]*>.*?<\/script>/is', '', $html);

		// Remove noscript tags
		$html = preg_replace('/<noscript\b[^>]*>.*?<\/noscript>/is', '', $html);

		// Remove inline event handlers (onclick, onload, etc.)
		$html = preg_replace('/\s+on[a-z]+="[^"]*"/i', '', $html);
		$html = preg_replace("/\s+on[a-z]+='[^']*'/i", '', $html);

		return $html;
	}

	/**
	 * Detect <form> elements and extract field definitions.
	 *
	 * @return array<int, array{id: string, name: string, action: string, fields: array}>
	 */
	public function extractForms(string $html): array
	{
		$forms = [];

		if (preg_match_all('/<form\b([^>]*)>(.*?)<\/form>/si', $html, $matches, PREG_SET_ORDER)) {
			foreach ($matches as $i => $m) {
				$attrs = $m[1];
				$inner = $m[2];

				// Extract form attributes
				$id = $this->extractAttr($attrs, 'id') ?: 'form-' . $i;
				$name = $this->extractAttr($attrs, 'name') ?: $id;
				$action = $this->extractAttr($attrs, 'action') ?: '';

				// Extract fields
				$fields = $this->extractFormFields($inner);

				$forms[] = [
					'id' => $id,
					'name' => $name,
					'action' => $action,
					'fields' => $fields,
				];
			}
		}

		return $forms;
	}

	/**
	 * Replace <form> tags in content with Loom form placeholder comments.
	 */
	public function replaceFormsWithPlaceholders(string $content, array $forms): string
	{
		foreach ($forms as $form) {
			$pattern = '/<form\b[^>]*id=["\']' . preg_quote($form['id'], '/') . '["\'][^>]*>.*?<\/form>/si';
			$placeholder = "<!-- form:html:{$form['id']}:{$form['name']} -->";
			$content = preg_replace($pattern, $placeholder, $content);
		}

		return $content;
	}

	/**
	 * Extract an attribute value from an attribute string.
	 */
	private function extractAttr(string $attrs, string $name): string
	{
		if (preg_match('/' . $name . '=["\']([^"\']*)["\']/i', $attrs, $m)) {
			return $m[1];
		}
		return '';
	}

	/**
	 * Extract form field definitions from form inner HTML.
	 *
	 * @return array<int, array{type: string, name: string, label: string, required: bool}>
	 */
	private function extractFormFields(string $html): array
	{
		$fields = [];

		// Extract <input> elements
		if (preg_match_all('/<input\b([^>]*)>/si', $html, $matches, PREG_SET_ORDER)) {
			foreach ($matches as $m) {
				$attrs = $m[1];
				$type = $this->extractAttr($attrs, 'type') ?: 'text';
				$name = $this->extractAttr($attrs, 'name');
				if (empty($name) || in_array($type, ['hidden', 'submit', 'button'])) continue;

				$fields[] = [
					'type' => $type,
					'name' => $name,
					'label' => $this->guessLabel($html, $name),
					'required' => str_contains($attrs, 'required'),
				];
			}
		}

		// Extract <textarea> elements
		if (preg_match_all('/<textarea\b([^>]*)>/si', $html, $matches, PREG_SET_ORDER)) {
			foreach ($matches as $m) {
				$attrs = $m[1];
				$name = $this->extractAttr($attrs, 'name');
				if (empty($name)) continue;

				$fields[] = [
					'type' => 'textarea',
					'name' => $name,
					'label' => $this->guessLabel($html, $name),
					'required' => str_contains($attrs, 'required'),
				];
			}
		}

		// Extract <select> elements
		if (preg_match_all('/<select\b([^>]*)>/si', $html, $matches, PREG_SET_ORDER)) {
			foreach ($matches as $m) {
				$attrs = $m[1];
				$name = $this->extractAttr($attrs, 'name');
				if (empty($name)) continue;

				$fields[] = [
					'type' => 'select',
					'name' => $name,
					'label' => $this->guessLabel($html, $name),
					'required' => str_contains($attrs, 'required'),
				];
			}
		}

		return $fields;
	}

	/**
	 * Guess a field label from surrounding HTML.
	 */
	private function guessLabel(string $html, string $name): string
	{
		// Try <label for="name">
		if (preg_match('/<label[^>]+for=["\']' . preg_quote($name, '/') . '["\'][^>]*>(.*?)<\/label>/si', $html, $m)) {
			return trim(strip_tags($m[1]));
		}

		// Fall back to capitalized name
		return ucwords(str_replace(['_', '-'], ' ', $name));
	}
}
