<?php

declare(strict_types=1);

namespace Loom;

class Renderer
{
	private string $templatesDir;

	public function __construct(string $templatesDir)
	{
		$this->templatesDir = rtrim($templatesDir, '/');
	}

	/**
	 * Render a page through its layout template.
	 *
	 * @param array  $page    Parsed front matter metadata
	 * @param string $content HTML body content
	 * @return string Rendered HTML
	 */
	public function render(array $page, string $content): string
	{
		$template = $page['template'] ?? 'default';
		$layoutFile = $this->templatesDir . '/layouts/' . $template . '.php';

		if (!file_exists($layoutFile)) {
			throw new \RuntimeException("Template not found: {$layoutFile}");
		}

		// Capture output from the template file
		ob_start();
		extract(['page' => $page, 'content' => $content], EXTR_SKIP);
		require $layoutFile;
		return ob_get_clean();
	}
}
