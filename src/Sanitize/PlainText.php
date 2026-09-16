<?php

declare(strict_types=1);

namespace Bpmore\Beacon\Sanitize;

/**
 * Plain text with newlines, as HTML.
 *
 * CAP bodies are text, not markup. Running text through an HTML sanitizer
 * would treat a `<` in a forecast as a tag. So: escape everything, then turn
 * blank lines into paragraphs. A single newline inside a paragraph is the
 * publisher's hard wrap (the NWS wraps at 65 columns) and becomes a space,
 * so the words flow to the reader's width instead of the teletype's.
 */
final class PlainText
{
    public static function toHtml(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", trim($text));

        if ($text === '') {
            return '';
        }

        $paragraphs = preg_split('/\n{2,}/', $text) ?: [];

        return implode('', array_map(
            fn (string $p) => '<p>'.htmlspecialchars(trim((string) preg_replace('/\s*\n\s*/', ' ', $p)), ENT_QUOTES | ENT_HTML5, 'UTF-8').'</p>',
            $paragraphs,
        ));
    }
}
