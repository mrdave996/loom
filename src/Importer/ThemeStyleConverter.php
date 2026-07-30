<?php

declare(strict_types=1);

namespace Loom\Importer;

/**
 * Convert WordPress theme.json / wp_global_styles JSON to CSS.
 *
 * Produces CSS custom properties, @font-face declarations, block styles,
 * and element styles from the WordPress global styles data.
 */
class ThemeStyleConverter
{
	/**
	 * Convert a decoded global_styles JSON array to CSS.
	 */
	public function convert(array $globalStyles): string
	{
		$css = '';
		$settings = $globalStyles['settings'] ?? [];
		$styles = $globalStyles['styles'] ?? [];

		// 1. @font-face declarations
		$css .= $this->buildFontFaces($settings);

		// 2. :root custom properties (colors, fonts, sizes, spacing, layout)
		$css .= $this->buildRootVars($settings, $styles);

		// 3. Body styles (background, color, typography, spacing)
		$css .= $this->buildBodyStyles($styles, $settings);

		// 4. Element styles (button, heading, link)
		$css .= $this->buildElementStyles($styles);

		// 5. Block styles
		$css .= $this->buildBlockStyles($styles);

		// 6. Layout system (constrained, flow, flex, grid)
		$css .= $this->buildLayoutSystem($styles);

		return $css;
	}

	/**
	 * Build @font-face declarations from font family definitions.
	 *
	 * Only generates @font-face for remote URLs (e.g., Google Fonts).
	 * Local file:./ references are handled by StyleExtractor via @import fallback.
	 */
	private function buildFontFaces(array $settings): string
	{
		$faces = '';
		$families = $settings['typography']['fontFamilies']['theme'] ?? [];

		foreach ($families as $family) {
			$fontFaces = $family['fontFace'] ?? [];
			foreach ($fontFaces as $face) {
				$familyName = $face['fontFamily'] ?? $family['name'] ?? '';
				$weight = $face['fontWeight'] ?? 'normal';
				$style = $face['fontStyle'] ?? 'normal';
				// src can be a string or an array of strings
				$src = $face['src'] ?? '';
				if (is_array($src)) {
					$src = $src[0] ?? '';
				}

				if (empty($familyName) || empty($src)) continue;

				// Skip local file references — they need the actual font files on disk
				if (str_starts_with($src, 'file:./')) continue;

				// Skip HTTP URLs pointing to WordPress wp-content (will 404 after migration)
				// Google Fonts @import fallback handles these via StyleExtractor::buildFontImport()
				if (str_contains($src, 'wp-content/')) continue;

				$faces .= "@font-face {\n";
				$faces .= "\tfont-family: '{$familyName}';\n";
				$faces .= "\tfont-weight: {$weight};\n";
				$faces .= "\tfont-style: {$style};\n";
				$faces .= "\tsrc: url('{$src}') format('woff2');\n";
				$faces .= "\tfont-display: swap;\n";
				$faces .= "}\n\n";
			}
		}

		return $faces;
	}

	/**
	 * Build :root CSS custom properties from settings and styles.
	 */
	private function buildRootVars(array $settings, array $styles = []): string
	{
		$vars = [];

		// Color palette
		$palette = $settings['color']['palette']['theme'] ?? [];
		foreach ($palette as $color) {
			$slug = $this->sanitizeSlug($color['slug'] ?? '');
			$value = $color['color'] ?? '';
			if ($slug && $value) {
				$vars[] = "\t--wp--preset--color--{$slug}: {$value};";
			}
		}

		// Ensure essential color defaults exist
		$colorDefaults = [
			'base' => '#ffffff',
			'contrast' => '#1a1a1a',
			'white' => '#ffffff',
			'black' => '#000000',
		];
		$varText = implode("\n", $vars);
		foreach ($colorDefaults as $slug => $value) {
			$varName = "--wp--preset--color--{$slug}";
			if (!str_contains($varText, $varName)) {
				$vars[] = "\t{$varName}: {$value};";
			}
		}

		// Font families
		$families = $settings['typography']['fontFamilies']['theme'] ?? [];
		foreach ($families as $family) {
			$slug = $this->sanitizeSlug($family['slug'] ?? '');
			$value = $family['fontFamily'] ?? '';
			if ($slug && $value) {
				$vars[] = "\t--wp--preset--font-family--{$slug}: {$value};";
			}
		}

		// Fallback font family if none defined
		if (empty($families)) {
			$vars[] = "\t--wp--preset--font-family--manrope: system-ui, sans-serif;";
		}

		// Font sizes
		$sizes = $settings['typography']['fontSizes']['theme'] ?? [];
		foreach ($sizes as $size) {
			$slug = $this->sanitizeSlug($size['slug'] ?? '');
			$value = $this->resolveFontSize($size);
			if ($slug && $value) {
				$vars[] = "\t--wp--preset--font-size--{$slug}: {$value};";
			}
		}

		// Spacing presets (from theme or defaults)
		$spacing = $settings['spacing']['spacingSizes']['theme'] ?? [];
		if (empty($spacing)) {
			// WordPress Twenty Twenty-Five defaults
			$spacing = [
				['slug' => '10', 'size' => '10px'],
				['slug' => '20', 'size' => '20px'],
				['slug' => '30', 'size' => '30px'],
				['slug' => '40', 'size' => '30px'],
				['slug' => '50', 'size' => 'clamp(30px, 5vw, 50px)'],
				['slug' => '60', 'size' => 'clamp(30px, 7vw, 70px)'],
				['slug' => '70', 'size' => 'clamp(50px, 7vw, 90px)'],
				['slug' => '80', 'size' => 'clamp(70px, 10vw, 140px)'],
			];
		}
		foreach ($spacing as $space) {
			$slug = $this->sanitizeSlug($space['slug'] ?? '');
			$value = $space['size'] ?? '';
			if ($slug && $value) {
				$vars[] = "\t--wp--preset--spacing--{$slug}: {$value};";
			}
		}

		// Layout variables from styles
		$layoutVars = [];

		// Root padding (--wp--style--root--padding-*)
		// WordPress convention: top/bottom = 0, left/right = spacing preset
		$rootPadding = $styles['spacing']['padding'] ?? [];
		$defaultSidePadding = 'var(--wp--preset--spacing--50)';
		$layoutVars[] = "\t--wp--style--root--padding-top: " . ($rootPadding['top'] ?? '0px') . ";";
		$layoutVars[] = "\t--wp--style--root--padding-right: " . ($rootPadding['right'] ?? $defaultSidePadding) . ";";
		$layoutVars[] = "\t--wp--style--root--padding-bottom: " . ($rootPadding['bottom'] ?? '0px') . ";";
		$layoutVars[] = "\t--wp--style--root--padding-left: " . ($rootPadding['left'] ?? $defaultSidePadding) . ";";

		// Block gap
		$blockGap = $styles['spacing']['blockGap'] ?? '';
		if (is_string($blockGap) && $blockGap) {
			$layoutVars[] = "\t--wp--style--block-gap: {$blockGap};";
		}

		// Content size and wide size (defaults for block themes)
		$layout = $settings['layout'] ?? [];
		$contentSize = $layout['contentSize'] ?? '645px';
		$wideSize = $layout['wideSize'] ?? '1340px';
		$layoutVars[] = "\t--wp--style--global--content-size: {$contentSize};";
		$layoutVars[] = "\t--wp--style--global--wide-size: {$wideSize};";

		if (!empty($layoutVars)) {
			$vars[] = "\t" . implode("\n\t", array_map(fn($v) => trim($v), $layoutVars));
		}

		if (empty($vars)) return '';

		return ":root {\n" . implode("\n", $vars) . "\n}\n\n";
	}

	/**
	 * Build body styles from top-level styles (color, typography, spacing).
	 */
	private function buildBodyStyles(array $styles, array $settings): string
	{
		$css = '';
		$bodyCss = '';

		// Body color (background + text)
		// WordPress convention: base = background, contrast = text
		$bodyBg = $styles['color']['background'] ?? 'var(--wp--preset--color--base)';
		$bodyText = $styles['color']['text'] ?? 'var(--wp--preset--color--contrast)';
		$bodyCss .= "\tbackground-color: {$bodyBg};\n";
		$bodyCss .= "\tcolor: {$bodyText};\n";

		// Body typography
		// WordPress convention: first sans-serif family for body, large font size
		$bodyTypography = $styles['typography'] ?? [];
		if (empty($bodyTypography['fontFamily'])) {
			$bodyCss .= "\tfont-family: var(--wp--preset--font-family--manrope, system-ui, sans-serif);\n";
		}
		if (empty($bodyTypography['fontSize'])) {
			$bodyCss .= "\tfont-size: var(--wp--preset--font-size--large);\n";
		}
		if (empty($bodyTypography['fontWeight'])) {
			$bodyCss .= "\tfont-weight: 300;\n";
		}
		if (empty($bodyTypography['lineHeight'])) {
			$bodyCss .= "\tline-height: 1.4;\n";
		}
		$bodyCss .= $this->typographyToCss($bodyTypography);

		// Body margin (WordPress sets margin: 0 on body)
		$bodyCss .= "\tmargin: 0;\n";

		if ($bodyCss) {
			$css .= "body {\n{$bodyCss}}\n\n";
		}

		// Heading styles from elements
		$headingStyle = $styles['elements']['heading']['typography'] ?? [];
		if (!empty($headingStyle)) {
			$headingCss = $this->typographyToCss($headingStyle);
			if ($headingCss) {
				$css .= "h1, h2, h3, h4, h5, h6 {\n";
				$css .= $headingCss;
				$css .= "}\n\n";
			}
		}

		// Link styles
		$linkColor = $styles['elements']['link']['color']['text'] ?? '';
		if ($linkColor) {
			$css .= "a {\n\tcolor: {$linkColor};\n}\n\n";
		} else {
			// WordPress default: links inherit body color
			$css .= "a:where(:not(.wp-element-button)) {\n\tcolor: currentColor;\n}\n\n";
		}

		return $css;
	}

	/**
	 * Build WordPress layout system CSS (constrained, flow, flex, grid).
	 */
	private function buildLayoutSystem(array $styles): string
	{
		$gap = $styles['spacing']['blockGap'] ?? '1.2rem';
		if (!is_string($gap)) $gap = '1.2rem';

		return <<<CSS
		/* WordPress layout system */
		:where(body) { margin: 0; }
		.wp-site-blocks {
			padding-top: var(--wp--style--root--padding-top);
			padding-bottom: var(--wp--style--root--padding-bottom);
		}
		.has-global-padding {
			padding-right: var(--wp--style--root--padding-right);
			padding-left: var(--wp--style--root--padding-left);
		}
		:where(.wp-site-blocks) > * {
			margin-block-start: {$gap};
			margin-block-end: 0;
		}
		:where(.wp-site-blocks) > :first-child { margin-block-start: 0; }
		:where(.wp-site-blocks) > :last-child { margin-block-end: 0; }
		:where(.is-layout-flow) > :first-child { margin-block-start: 0; }
		:where(.is-layout-flow) > :last-child { margin-block-end: 0; }
		:where(.is-layout-flow) > * { margin-block-start: {$gap}; margin-block-end: 0; }
		:where(.is-layout-constrained) > :first-child { margin-block-start: 0; }
		:where(.is-layout-constrained) > :last-child { margin-block-end: 0; }
		:where(.is-layout-constrained) > * { margin-block-start: {$gap}; margin-block-end: 0; }
		:where(.is-layout-constrained) > :where(:not(.alignleft):not(.alignright):not(.alignfull)) {
			max-width: var(--wp--style--global--content-size);
			margin-left: auto !important;
			margin-right: auto !important;
		}
		:where(.is-layout-constrained) > .alignwide {
			max-width: var(--wp--style--global--wide-size);
		}
		body .is-layout-flex { display: flex; }
		.is-layout-flex { flex-wrap: wrap; align-items: center; }
		.is-layout-flex > :is(*, div) { margin: 0; }
		body .is-layout-grid { display: grid; }
		.is-layout-grid > :is(*, div) { margin: 0; }
		.wp-site-blocks > .alignleft { float: left; margin-right: 2em; }
		.wp-site-blocks > .alignright { float: right; margin-left: 2em; }
		.wp-site-blocks > .aligncenter { justify-content: center; margin-left: auto; margin-right: auto; }

		CSS;
	}

	/**
	 * Build element styles (button, heading, link).
	 */
	private function buildElementStyles(array $styles): string
	{
		$css = '';
		$elements = $styles['elements'] ?? [];

		// Button styles
		$buttonStyles = $elements['button'] ?? [];
		$buttonCss = $this->compileNode($buttonStyles);

		// WordPress convention: button bg = contrast, button text = base
		if (empty($buttonStyles['color']['background'] ?? '')) {
			$buttonCss .= "\tbackground-color: var(--wp--preset--color--contrast);\n";
		}
		if (empty($buttonStyles['color']['text'] ?? '')) {
			$buttonCss .= "\tcolor: var(--wp--preset--color--base);\n";
		}
		if ($buttonCss) {
			$css .= ":root :where(.wp-element-button, .wp-block-button__link) {\n";
			$css .= $buttonCss;
			$css .= "}\n\n";
		}

		// Link styles
		if (!empty($elements['link'])) {
			$linkCss = $this->compileNode($elements['link']);
			if ($linkCss) {
				$css .= "a {\n";
				$css .= $linkCss;
				$css .= "}\n\n";
			}
		}

		return $css;
	}

	/**
	 * Build block-specific styles.
	 */
	private function buildBlockStyles(array $styles): string
	{
		$css = '';
		$blocks = $styles['blocks'] ?? [];

		$blockSelectorMap = [
			'core/heading' => 'h1, h2, h3, h4, h5, h6',
			'core/site-title' => '.site-title',
			'core/site-tagline' => '.site-tagline',
			'core/navigation' => '.wp-block-navigation',
			'core/button' => '.wp-block-button__link',
			'core/pullquote' => '.wp-block-pullquote',
			'core/quote' => 'blockquote',
			'core/image' => '.wp-block-image',
			'core/gallery' => '.wp-block-gallery',
			'core/columns' => '.wp-block-columns',
			'core/column' => '.wp-block-column',
			'core/group' => '.wp-block-group',
			'core/paragraph' => '.wp-block-paragraph, p',
			'core/list' => '.wp-block-list, ul, ol',
			'core/search' => '.wp-block-search',
			'core/post-author' => '.wp-block-post-author',
			'core/post-author-name' => '.wp-block-post-author-name',
			'core/post-terms' => '.wp-block-post-terms',
			'core/separator' => '.wp-block-separator, hr',
		];

		foreach ($blocks as $blockName => $blockStyles) {
			// Get the CSS selector for this block
			$selector = $blockSelectorMap[$blockName] ?? '.wp-block-' . $this->blockNameToClass($blockName);

			// Compile the styles (skip nested variations for now)
			$blockCss = $this->compileNode($blockStyles);
			if ($blockCss) {
				$css .= "{$selector} {\n";
				$css .= $blockCss;
				$css .= "}\n\n";
			}

			// Handle variations (e.g., core/column section-2)
			foreach ($blockStyles as $key => $value) {
				if (is_array($value) && !empty($value)) {
					// Check if this looks like a variation (has CSS properties)
					if ($this->hasCssProperties($value)) {
						$variationSelector = $selector . '.' . $this->sanitizeSlug($key);
						$variationCss = $this->compileNode($value);
						if ($variationCss) {
							$css .= "{$variationSelector} {\n";
							$css .= $variationCss;
							$css .= "}\n\n";
						}
					}
				}
			}
		}

		return $css;
	}

	/**
	 * Compile a style node (typography, spacing, color, etc.) to CSS rules.
	 */
	private function compileNode(array $node): string
	{
		$css = '';

		// Typography
		if (!empty($node['typography'])) {
			$css .= $this->typographyToCss($node['typography']);
		}

		// Color
		if (!empty($node['color'])) {
			if (!empty($node['color']['text'])) {
				$css .= "\tcolor: {$node['color']['text']};\n";
			}
			if (!empty($node['color']['background'])) {
				$css .= "\tbackground-color: {$node['color']['background']};\n";
			}
			if (!empty($node['color']['gradient'])) {
				$css .= "\tbackground: {$node['color']['gradient']};\n";
			}
		}

		// Spacing
		if (!empty($node['spacing'])) {
			$spacing = $node['spacing'];
			if (!empty($spacing['padding'])) {
				$css .= $this->spacingToCss('padding', $spacing['padding']);
			}
			if (!empty($spacing['margin'])) {
				$css .= $this->spacingToCss('margin', $spacing['margin']);
			}
			if (!empty($spacing['blockGap'])) {
				$css .= "\tgap: {$spacing['blockGap']};\n";
			}
		}

		// Border
		if (!empty($node['border'])) {
			$border = $node['border'];
			if (!empty($border['color'])) {
				$css .= "\tborder-color: {$border['color']};\n";
			}
			if (!empty($border['width'])) {
				$css .= "\tborder-width: {$border['width']};\n";
			}
			if (!empty($border['radius'])) {
				$css .= "\tborder-radius: {$border['radius']};\n";
			}
			if (!empty($border['style'])) {
				$css .= "\tborder-style: {$border['style']};\n";
			}
		}

		// Inline CSS (some themes store raw CSS in variations)
		if (!empty($node['css']) && is_string($node['css'])) {
			$css .= "\t" . trim($node['css']) . "\n";
		}

		return $css;
	}

	/**
	 * Convert typography properties to CSS rules.
	 */
	private function typographyToCss(array $typography): string
	{
		$css = '';

		if (!empty($typography['fontFamily'])) {
			$css .= "\tfont-family: {$typography['fontFamily']};\n";
		}
		if (!empty($typography['fontSize'])) {
			$css .= "\tfont-size: {$typography['fontSize']};\n";
		}
		if (!empty($typography['fontWeight'])) {
			$css .= "\tfont-weight: {$typography['fontWeight']};\n";
		}
		if (!empty($typography['fontStyle'])) {
			$css .= "\tfont-style: {$typography['fontStyle']};\n";
		}
		if (!empty($typography['lineHeight'])) {
			$css .= "\tline-height: {$typography['lineHeight']};\n";
		}
		if (!empty($typography['letterSpacing'])) {
			$css .= "\tletter-spacing: {$typography['letterSpacing']};\n";
		}
		if (!empty($typography['textTransform'])) {
			$css .= "\ttext-transform: {$typography['textTransform']};\n";
		}
		if (!empty($typography['textDecoration'])) {
			$css .= "\ttext-decoration: {$typography['textDecoration']};\n";
		}

		return $css;
	}

	/**
	 * Convert spacing properties to CSS rules.
	 */
	private function spacingToCss(string $property, array $spacing): string
	{
		$css = '';

		if (isset($spacing['top']) || isset($spacing['right']) || isset($spacing['bottom']) || isset($spacing['left'])) {
			$top = $spacing['top'] ?? '0';
			$right = $spacing['right'] ?? '0';
			$bottom = $spacing['bottom'] ?? '0';
			$left = $spacing['left'] ?? '0';
			$css .= "\t{$property}: {$top} {$right} {$bottom} {$left};\n";
		} elseif (is_string($spacing)) {
			$css .= "\t{$property}: {$spacing};\n";
		}

		return $css;
	}

	/**
	 * Resolve a font src path. WordPress stores local fonts as file:./assets/fonts/...
	 * and external fonts as full URLs.
	 */
	private function resolveFontSrc(string $src): string
	{
		// Local file reference — convert to relative path for the static site
		if (str_starts_with($src, 'file:./')) {
			$path = substr($src, 6); // Remove 'file:.'
			return '/assets/fonts' . $path;
		}

		// Already a URL
		return $src;
	}

	/**
	 * Resolve a font size value. Fluid sizes become clamp(), static sizes pass through.
	 */
	private function resolveFontSize(array $size): string
	{
		$fluid = $size['fluid'] ?? false;

		// fluid can be false, or an array with min/max
		if (is_array($fluid) && isset($fluid['min'], $fluid['max'])) {
			$minRem = (float) str_replace('rem', '', $fluid['min']);
			$maxRem = (float) str_replace('rem', '', $fluid['max']);
			$preferred = $minRem + ($maxRem - $minRem) / 2;
			$preferredVw = round($preferred * 100 / 48, 4); // ~48rem = typical viewport
			return "clamp({$fluid['min']}, {$preferredVw}vw, {$fluid['max']})";
		}

		// Static size
		return $size['size'] ?? '';
	}

	/**
	 * Convert a WordPress block name to a CSS class name.
	 * e.g., "core/navigation" → "navigation"
	 */
	private function blockNameToClass(string $name): string
	{
		// Strip "core/" prefix if present
		if (str_starts_with($name, 'core/')) {
			$name = substr($name, 5);
		}

		return str_replace('/', '-', $name);
	}

	/**
	 * Sanitize a slug for use as a CSS identifier.
	 */
	private function sanitizeSlug(string $slug): string
	{
		return preg_replace('/[^a-z0-9-]/i', '-', $slug);
	}

	/**
	 * Check if an array contains CSS-like properties (not just nested arrays).
	 */
	private function hasCssProperties(array $node): bool
	{
		$cssKeys = ['typography', 'color', 'spacing', 'border', 'css', 'shadow', 'filter'];
		foreach ($cssKeys as $key) {
			if (isset($node[$key])) return true;
		}
		return false;
	}
}
