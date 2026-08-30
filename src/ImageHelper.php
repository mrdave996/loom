<?php

declare(strict_types=1);

namespace Loom;

/**
 * Post-render performance helpers for content images (Phase 11).
 *
 * Injects width/height (to prevent CLS) and loading="lazy" into content <img>
 * tags that lack them, resolving dimensions from the filesystem for the
 * self-hosted /assets/images/* set. Shared nav/brand images that already carry
 * explicit width/height are left untouched.
 */
class ImageHelper
{
    /**
     * Resolve an absolute URL path to a file under the public dir, or null.
     * Query strings and directory-traversal are neutralised.
     */
    public static function resolvePath(string $urlPath, string $publicDir): ?string
    {
        $parts = parse_url($urlPath);
        $path  = $parts['path'] ?? $urlPath;
        $path  = preg_replace('#\.{2,}#', '.', $path);
        $path  = ltrim(trim(rawurldecode($path)), '/');

        if ($path === '' || str_contains($path, '..')) {
            return null;
        }

        $file = rtrim($publicDir, '/') . '/' . $path;
        return is_file($file) ? $file : null;
    }

    /**
     * Read image dimensions as [width, height] using GD, or null on failure.
     */
    public static function dims(string $absPath): ?array
    {
        if (!function_exists('getimagesize')) {
            return null;
        }
        $info = @getimagesize($absPath);
        if ($info === false || !isset($info[0], $info[1])) {
            return null;
        }
        return [(int) $info[0], (int) $info[1]];
    }

    /**
     * Post-process rendered HTML: add width/height + loading="lazy" to content
     * <img> tags that need them.
     *
     * @param string $html      Full rendered page
     * @param string $publicDir Filesystem root that serves the asset URLs
     */
    public static function optimize(string $html, string $publicDir): string
    {
        return preg_replace_callback(
            '/<img\b([^>]*)>/i',
            static function (array $m) use ($publicDir): string {
                $tag = $m[1];
                // Drop a stray self-closing slash (markdown emits `<img ... />`).
                $tag = rtrim($tag);
                if (substr($tag, -1) === '/') {
                    $tag = rtrim(substr($tag, 0, -1));
                }

                // Pull src out of whatever attribute order it appears in.
                if (!preg_match('/\bsrc\s*=\s*["\']([^"\']+)["\']/i', $tag, $sm)) {
                    return $m[0];
                }
                $src = $sm[1];
                $hasW = (bool) preg_match('/\bwidth\s*=/i', $tag);
                $hasH = (bool) preg_match('/\bheight\s*=/i', $tag);

                // Inject dimensions from the filesystem when both are missing.
                if ((!$hasW || !$hasH)) {
                    $local = self::resolvePath($src, $publicDir);
                    if ($local !== null) {
                        $dims = self::dims($local);
                        if ($dims !== null) {
                            if (!$hasW) {
                                $tag .= sprintf(' width="%d"', $dims[0]);
                            }
                            if (!$hasH) {
                                $tag .= sprintf(' height="%d"', $dims[1]);
                            }
                        }
                    }
                }

                // Add lazy loading unless already controlled (loading or fetchpriority).
                if (!preg_match('/\b(?:loading|fetchpriority)\s*=/i', $tag)) {
                    $tag .= ' loading="lazy"';
                }

                return '<img' . $tag . '>';
            },
            $html
        ) ?? $html;
    }
}
