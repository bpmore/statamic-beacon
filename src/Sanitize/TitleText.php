<?php

declare(strict_types=1);

namespace Bpmore\Beacon\Sanitize;

/**
 * A title as plain text.
 *
 * WordPress.com returns titles with entities in them (`&#8217;` for an
 * apostrophe). Decoded once here so the stored title is the real
 * character, then escaped by the renderer. Tags are not stripped: a title
 * that arrives as `<b>bold</b>` shows those characters literally, which is
 * the brief's rule, rather than either rendering or hiding them.
 */
final class TitleText
{
    public static function decode(string $title): string
    {
        $text = html_entity_decode($title, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }
}
