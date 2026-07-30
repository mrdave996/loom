<?php

declare(strict_types=1);

namespace Loom\Importer;

/**
 * Interface for WordPress content parsers.
 */
interface ContentParser
{
	/**
	 * Parse a WordPress export and return normalized data.
	 *
	 * @return array{
	 *     pages: array,
	 *     posts: array,
	 *     menus: array,
	 *     media: array,
	 *     options: array,
	 *     forms: array,
	 *     theme: array{name: string, global_styles: array|null},
	 * }
	 */
	public function parse(string $source): array;
}
