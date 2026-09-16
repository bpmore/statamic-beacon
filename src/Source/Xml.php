<?php

declare(strict_types=1);

namespace Bpmore\Beacon\Source;

use DOMDocument;
use DOMElement;
use DOMNode;

/**
 * XML from a remote source, read defensively.
 *
 * PHP's own DOM extension, no library. No network access while parsing
 * (`LIBXML_NONET`), entities never substituted (`LIBXML_NOENT` is never
 * set), and a document with a DOCTYPE at all is refused outright, since a
 * feed has no honest reason to carry one and every entity-expansion attack
 * needs one. PHP 8 disables external entity loading for good; the DOCTYPE
 * refusal is the belt to that brace.
 *
 * Elements are matched by local name. The `cap:` prefix is optional and
 * inconsistent across publishers, and a reader that wanted the prefix
 * would parse a third of real feeds to nothing.
 */
final class Xml
{
    public static function load(string $body): DOMDocument
    {
        $body = ltrim($body, "\xEF\xBB\xBF \t\r\n");

        if ($body === '') {
            throw new MalformedPayload('The response is empty.');
        }

        if (preg_match('/<!DOCTYPE/i', $body) === 1) {
            throw new MalformedPayload('The XML carries a DOCTYPE, which a feed never needs. Refused.');
        }

        $previous = libxml_use_internal_errors(true);

        $doc = new DOMDocument;
        $doc->recover = false;
        $ok = $doc->loadXML($body, LIBXML_NONET | LIBXML_NOCDATA | LIBXML_NOBLANKS);

        $errors = libxml_get_errors();
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if ($ok === false || $doc->documentElement === null) {
            $first = $errors[0] ?? null;

            throw new MalformedPayload('The response is not well-formed XML'.($first ? ': '.trim($first->message) : '.'));
        }

        return $doc;
    }

    /** The direct children of a node with a given local name. @return list<DOMElement> */
    public static function children(DOMNode $node, string $localName): array
    {
        $out = [];

        foreach ($node->childNodes as $child) {
            if ($child instanceof DOMElement && $child->localName === $localName) {
                $out[] = $child;
            }
        }

        return $out;
    }

    /** The first direct child by local name. */
    public static function child(DOMNode $node, string $localName): ?DOMElement
    {
        return self::children($node, $localName)[0] ?? null;
    }

    /** The text of the first direct child by local name, or null. */
    public static function text(DOMNode $node, string $localName): ?string
    {
        $child = self::child($node, $localName);

        if ($child === null) {
            return null;
        }

        $text = trim($child->textContent);

        return $text === '' ? null : $text;
    }

    /** Every descendant by local name, in document order. @return list<DOMElement> */
    public static function descendants(DOMNode $node, string $localName): array
    {
        $out = [];

        foreach ($node->childNodes as $child) {
            if (! $child instanceof DOMElement) {
                continue;
            }

            if ($child->localName === $localName) {
                $out[] = $child;
            }

            foreach (self::descendants($child, $localName) as $deeper) {
                $out[] = $deeper;
            }
        }

        return $out;
    }
}
