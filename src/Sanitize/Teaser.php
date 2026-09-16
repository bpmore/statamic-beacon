<?php

declare(strict_types=1);

namespace Bpmore\Beacon\Sanitize;

/**
 * Splitting a body at the teaser marker.
 *
 * The reference feed marks the fold with the HTML comment `<!--noteaser-->`.
 * Every sanitizer strips comments, so the split has to happen on the raw
 * string, before sanitizing. Order is: fetch, split, sanitize each part.
 */
final class Teaser
{
    public const DEFAULT_MARKER = '<!--noteaser-->';

    /**
     * @return array{teaser: string|null, body: string}  teaser is null when there is no marker
     */
    public static function split(string $html, string $marker = self::DEFAULT_MARKER): array
    {
        if ($marker === '') {
            return ['teaser' => null, 'body' => $html];
        }

        $at = strpos($html, $marker);

        if ($at === false) {
            return ['teaser' => null, 'body' => $html];
        }

        return [
            'teaser' => substr($html, 0, $at),
            'body' => substr($html, 0, $at).substr($html, $at + strlen($marker)),
        ];
    }
}
