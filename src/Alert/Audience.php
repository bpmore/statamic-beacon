<?php

declare(strict_types=1);

namespace Bpmore\Beacon\Alert;

/**
 * Whether an alert is for this site.
 *
 * Exact string match against a declared value, and nothing else. The
 * reference client sniffed `document.body.className` for a substring, so a
 * page with body class `category-inside-scoop` resolved to the intranet
 * bucket. There is no substring, prefix, or pattern matching here, and
 * there must never be.
 */
final class Audience
{
    /**
     * @param  list<string>  $targets  the alert's audiences; empty means everyone
     */
    public static function matches(array $targets, string $site): bool
    {
        if ($targets === []) {
            return true;
        }

        return in_array($site, $targets, true);
    }

    /**
     * The audience for a site handle: an exact lookup in the configured map,
     * else the default.
     *
     * @param  array<string, string>  $map  site handle => audience
     */
    public static function forSite(?string $siteHandle, array $map, string $default): string
    {
        if ($siteHandle !== null && array_key_exists($siteHandle, $map) && is_string($map[$siteHandle]) && $map[$siteHandle] !== '') {
            return $map[$siteHandle];
        }

        return $default;
    }
}
