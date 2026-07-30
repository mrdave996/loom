# Loom WordPress Importer — Bugs & Improvements

Found during the Simple Telecom import (`loom-simpletelecom`). Each issue has the exact file, line numbers, root cause, and suggested fix.

---

## 1. `tel:` and `mailto:` URLs mangled by `mapUrl()`

**File:** `src/Importer/NavigationBuilder.php:83-102`
**Severity:** High — every import with phone/email nav links is broken

`mapUrl()` parses all URLs as HTTP paths. For `tel:1300858751`, `parse_url()` returns `PHP_URL_PATH` as `1300858751`, which becomes `/%201300858751` (the `%20` comes from spaces in the original WP menu item URL like `tel: 1300 858 751`). For `mailto:info@site.com`, it becomes `/info@site.com`.

```php
// Current (line 91):
$path = parse_url($url, PHP_URL_PATH) ?? '/';

// Fix: bail early for non-HTTP schemes
$parts = parse_url($url);
$scheme = strtolower($parts['scheme'] ?? '');
if (in_array($scheme, ['tel', 'mailto', 'javascript', 'data'])) {
    return $url;
}
```

---

## 2. Missing default CSS custom properties

**File:** `src/Importer/ThemeStyleConverter.php:89-178`
**Severity:** High — body text invisible or unstyled if theme doesn't define `base`/`contrast`

`buildBodyStyles()` (line 190) references `var(--wp--preset--color--base)` and `var(--wp--preset--color--contrast)` as fallbacks, but `buildRootVars()` only emits variables that exist in the theme's palette. If the theme doesn't define slugs `base` and `contrast`, these CSS vars are undefined and the body gets browser defaults (black text, white background — but also no font family).

Similarly, line 199 hardcodes `var(--wp--preset--font-family--manrope, ...)` which only exists if the theme defines a `manrope` font family slug.

**Fix:** After building vars from the theme palette, inject defaults for any missing essentials:

```php
// After the palette loop (line 101), add:
$defaults = [
    'base' => '#ffffff',
    'contrast' => '#1a1a1a',
    'white' => '#ffffff',
    'black' => '#000000',
];
foreach ($defaults as $slug => $value) {
    $varName = "--wp--preset--color--{$slug}";
    // Only add if not already defined by the theme
    if (!str_contains(implode("\n", $vars), $varName)) {
        $vars[] = "\t{$varName}: {$value};";
    }
}
```

And for font families (line 103-111), if no families are defined, emit a sensible default:

```php
if (empty($families)) {
    $vars[] = "\t--wp--preset--font-family--manrope: system-ui, sans-serif;";
}
```

---

## 3. Duplicate `:root` blocks from multiple conversion paths

**File:** `src/Importer/StyleExtractor.php:31-87`
**Severity:** Medium — confusing CSS cascade, later values silently override earlier ones

The `extract()` method processes theme data through multiple priority paths that each generate `:root` blocks:

- **Priority 1** (line 37): `ThemeStyleConverter::convert($themeData['global_styles'])` → `:root { ... }`
- **Priority 2** (line 44): `extractFromTheme()` reads `theme.json` from disk → calls `ThemeStyleConverter::convert()` again → another `:root { ... }`
- **Priority 3** (line 52): `scrapeInlineStyles()` captures inline `<style>` blocks from the live site → yet another `:root { ... }`

There's no deduplication. The CSS is concatenated as-is.

**Fix:** Two approaches (can combine):

**A)** In `extractFromTheme()` (line 171-204), skip `theme.json` conversion if `global_styles` was already processed:

```php
private function extractFromTheme(string $themeDir, bool $skipThemeJson = false): string
{
    // ...
    if (!$skipThemeJson && file_exists($themeJsonPath)) {
        // existing theme.json conversion
    }
}
```

**B)** After concatenation, merge duplicate `:root` blocks. Parse all `:root { ... }` blocks, collect declarations, and emit a single `:root` with last-wins semantics.

---

## 4. CSS bloat from scraping entire block library

**File:** `src/Importer/StyleExtractor.php:233-256`
**Severity:** Medium — generates ~50-60KB of unused CSS

`scrapeInlineStyles()` grabs every `<style>` block from the live site HTML. WordPress outputs inline styles for the **entire** block library on every page — lightbox, emoji, admin bar, all color/gradient utilities, writing-mode rotations, drop-caps, etc. Most are never used by the imported content.

**Fix options (in order of effort):**

**A) Quick win — strip known-unused patterns after scraping:**

Add to `cleanCss()`:

```php
// Remove emoji/smiley styles
$css = preg_replace('/img\.wp-smiley.*?\}/s', '', $css);
// Remove WP admin variables
$css = preg_replace('/:root\{--wp-block-synced-color.*?\}/s', '', $css);
// Remove lightbox animations
$css = preg_replace('/\.wp-lightbox-.*?\}/s', '', $css);
// Remove .has-*-color / .has-*-background-color / .has-*-border-color utility classes
$css = preg_replace('/\.has-[a-z-]+-(color|background-color|border-color)\[class\]\{[^}]*\}/s', '', $css);
// Remove gradient utility classes
$css = preg_replace('/\.has-[a-z-]+-gradient-background\{[^}]*\}/s', '', $css);
// Remove Beaver Builder animation placeholders
$css = preg_replace('/\.fl-node-[a-f0-9]+\.fl-animation[^}]*\}/s', '', $css);
```

**B) Better — tree-shake against imported content:**

After all pages are converted, scan the rendered HTML for used CSS classes, then strip rules whose selectors don't match. This is more complex but produces the cleanest output.

---

## 5. `@font-face` points to old WordPress site

**File:** `src/Importer/ThemeStyleConverter.php:51-84`
**Severity:** Medium — fonts 404 once old site goes down

`buildFontFaces()` preserves the original `src` URLs from `@font-face` declarations. For themes that store fonts in `wp-content/themes/...`, these point to the old WordPress site. The method skips `file:./` references (line 71) but passes through HTTP URLs unchanged.

**Fix:** For HTTP URLs pointing to the original site's `wp-content` path, either:

**A)** Download the font files locally during import and rewrite the URL to `/assets/fonts/`.
**B)** Replace with a Google Fonts `@import` (the `buildFontImport()` method already handles this for local-only fonts, but doesn't handle the case where the theme has HTTP URLs pointing to the old site).

---

## 6. Duplicate pages not deduplicated

**File:** `src/Importer/WpXmlParser.php:96-142` and `src/Importer/WpSqlParser.php`
**Severity:** Medium — pages like "1300 Numbers (2)" imported alongside "1300 Numbers"

WordPress can have multiple published pages with the same slug (e.g., `1300-numbers` and `1300-numbers-2`). Both pass the `$status !== 'publish'` filter. The parser reads the title verbatim from XML (line 129), so the duplicate keeps its `(2)` suffix.

In `Importer::convertItem()` (line 278), the second file overwrites the first by slug, but the `(2)` title survives in front matter.

**Fix:** Deduplicate by slug before returning from the parser. Keep the page with the most recent date, or the one without a numeric suffix:

```php
// In parse() method, before returning:
$seen = [];
$deduped = [];
foreach ($pages as $page) {
    $slug = $page['slug'];
    if (isset($seen[$slug])) {
        // Keep the one with the more recent date
        if (($page['date'] ?? '') > ($seen[$slug]['date'] ?? '')) {
            $deduped[$seen[$slug]['_index']] = $page;
        }
        continue;
    }
    $seen[$slug] = $page;
    $page['_index'] = count($deduped);
    $deduped[] = $page;
}
$pages = array_values($deduped);
```

Also strip numeric suffixes from titles: `preg_replace('/\s*\(\d+\)\s*$/', '', $title)`

---

## 7. No `footer_links` generated

**File:** `src/Importer.php:236-238`, `src/Importer/NavigationBuilder.php:32-78`
**Severity:** Low-Medium — footer is empty unless hardcoded fallback exists

The importer generates `nav_links` from the first WordPress menu (line 103: `$this->navBuilder->build($data['menus'], ...)`), but never generates `footer_links`. WordPress menus have distinct locations (primary, footer, social) but `NavigationBuilder::build()` always uses `$menus[0]`.

The `WpXmlParser::buildMenuTree()` merges all `nav_menu_item` posts into a single tree, losing location information.

**Fix:**

**A)** In `WpXmlParser`, parse menu location data from `theme_mods_nav_menu_locations` option or the `wp_navigation` post type. Build separate trees per location.

**B)** In `NavigationBuilder`, accept multiple menu trees and return both `nav_links` and `footer_links`.

**C)** In `Importer::convertItem()`, add `$frontMatter['footer_links'] = $footerLinks;` alongside the existing `nav_links`.

---

## 8. Image URL rewriting misses some patterns

**File:** `src/Importer/HtmlToMarkdown.php:122-167`
**Severity:** Medium — some images still point to CDN after import

Three sub-issues in `rewriteImageUrls()`:

**8a. Regex breaks on parenthesized URLs** (line 126)

Pattern `!\[([^\]]*)\]\(([^)]+)\)` stops at the first `)`. URLs like `image(1).jpg` produce truncated matches. Fix: use a balanced-parentheses pattern or `[^)]+(?:\([^)]*\)[^)]*)*`.

**8b. Exact-match lookup fails for CDN variants** (line 132)

`isset($this->urlMap[$url])` requires an exact match. If the content has a CDN URL (e.g., `https://i0.wp.com/site.com/wp-content/...`) but the export has the origin URL, the lookup fails silently. Fix: normalize URLs before lookup — strip CDN prefixes, resolve to origin URL.

**8c. Only rewrites `![]()` and `<img>`, not other references** (lines 125-164)

`<a href="...">` links to images, `srcset` attributes, and `background-image: url(...)` in inline styles are not rewritten. The `cleanWordPress()` method (line 70) strips all `style` attributes entirely, which removes `background-image` references before they can be rewritten. Fix: rewrite URLs in all contexts before stripping styles.

---

## 9. Beaver Builder content not cleaned

**File:** `src/Importer/HtmlToMarkdown.php:53-85`
**Severity:** Low-Medium — BB-built pages produce garbled markdown

`cleanWordPress()` handles `wp-block-*` and `elementor-*` wrappers but has zero awareness of Beaver Builder's `fl-*` class system. Beaver Builder content uses `fl-builder-content`, `fl-row`, `fl-col`, `fl-module`, `fl-module-content`, etc. These wrapper divs pass through to the HTML-to-markdown converter, producing nested raw HTML in the output.

**Fix:** Add Beaver Builder wrapper stripping, similar to the Elementor handling:

```php
// Strip Beaver Builder row/column/module wrappers
$html = preg_replace('/<div[^>]*class="[^"]*fl-(?:row|col|module|builder)[^"]*"[^>]*>/i', '', $html);
```

Or better — use the same recursive `stripWpBlockWrappers()` approach but extend the pattern to match `fl-*` classes.

---

## 10. `nav_links` duplicated in every page's front matter

**File:** `src/Importer.php:236-238`
**Severity:** Low — not a bug, but produces 30+ identical copies of the nav array

Every page gets the full `nav_links` array in its front matter. For a site with 30+ pages, that's 30+ copies of the same 10-15 nav link objects. Changing one link means editing every file.

**Fix:** Make `nav_links` optional in front matter. Add a default fallback in `nav.php`:

```php
$links = $page['nav_links'] ?? [
    ['label' => 'Home', 'url' => '/'],
    // ... sensible defaults
];
```

Then stop writing `nav_links` to every page's front matter during import (or only write it to the front page). The nav partial reads from front matter if present, otherwise uses its default.

---

## Priority Order

| # | Issue | Impact | Effort |
|---|-------|--------|--------|
| 1 | `tel:`/`mailto:` URLs mangled | Every import with phone links | 5 min |
| 2 | Missing CSS custom properties | Every import | 10 min |
| 6 | Duplicate pages with "(2)" | Common with WP revisions | 10 min |
| 3 | Duplicate `:root` blocks | Every import with theme data | 15 min |
| 8 | Image URL rewriting gaps | Some images per import | 30 min |
| 4 | CSS bloat from scraping | Every scrape-mode import | 30 min |
| 5 | `@font-face` pointing to old site | Themes with custom fonts | 30 min |
| 9 | Beaver Builder cleaning | Only BB-built sites | 30 min |
| 7 | No footer links generated | Every import | 1 hr |
| 10 | `nav_links` in every front matter | Every import (cosmetic) | 15 min |
