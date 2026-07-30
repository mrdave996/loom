<?php

declare(strict_types=1);

namespace Loom;

use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\FrontMatter\FrontMatterExtension;
use League\CommonMark\Extension\FrontMatter\Output\RenderedContentWithFrontMatter;
use League\CommonMark\MarkdownConverter;
use Symfony\Component\Yaml\Yaml;

class ContentLoader
{
	private MarkdownConverter $converter;

	public function __construct()
	{
		$environment = new Environment([]);
		$environment->addExtension(new CommonMarkCoreExtension());
		$environment->addExtension(new FrontMatterExtension());

		$this->converter = new MarkdownConverter($environment);
	}

	/**
	 * Parse a markdown file into front matter + HTML body.
	 *
	 * @return array{front_matter: array, body: string}
	 */
	public function load(string $filePath): array
	{
		$raw = file_get_contents($filePath);

		if ($raw === false) {
			throw new \RuntimeException("Cannot read file: {$filePath}");
		}

		$result = $this->converter->convert($raw);

		$frontMatter = [];
		if ($result instanceof RenderedContentWithFrontMatter) {
			$frontMatter = $result->getFrontMatter() ?? [];
		}

		return [
			'front_matter' => $frontMatter,
			'body' => (string) $result,
		];
	}
}
