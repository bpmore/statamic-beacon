<?php

declare(strict_types=1);

namespace Bpmore\Beacon\Sanitize;

/**
 * Whether a URL may become an `href`.
 *
 * `http`, `https`, protocol-relative, root-relative, relative and fragment
 * links pass. Every other scheme (`javascript:`, `data:`, `vbscript:`) is
 * replaced with `#`. The scheme is read the way a browser reads it, with
 * whitespace and control characters dropped first, so `java\tscript:` is
 * still `javascript:`.
 */
final class UrlScheme
{
    public const FALLBACK = '#';

    public static function isSafe(?string $url): bool
    {
        if ($url === null) {
            return false;
        }

        $raw = trim($url);

        if ($raw === '') {
            return false;
        }

        $beforePath = explode('/', $raw, 2)[0];
        $beforePath = preg_split('/[?#]/', $beforePath, 2)[0];
        $scheme = strtolower((string) preg_replace('/[^a-z0-9+.:\-]/i', '', $beforePath));

        if (preg_match('/^([a-z][a-z0-9+.\-]*):/', $scheme, $m) === 1) {
            return in_array($m[1], ['http', 'https'], true);
        }

        return true;
    }

    /** The URL if safe, `#` if not, null when there was nothing. */
    public static function safe(?string $url): ?string
    {
        if ($url === null || trim($url) === '') {
            return null;
        }

        return self::isSafe($url) ? trim($url) : self::FALLBACK;
    }
}
