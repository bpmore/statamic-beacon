<?php

declare(strict_types=1);

namespace Bpmore\Beacon\Source;

use Bpmore\Beacon\Alert\Alert;
use Bpmore\Beacon\Alert\Severity;
use Bpmore\Beacon\Mapping\Dates;
use Bpmore\Beacon\Mapping\PatternMap;
use DOMElement;

/**
 * RSS 2.0 and Atom to alerts.
 *
 * Categories carry severity and audience the same way the JSON feed's
 * category slugs do: through one pattern with named groups. That is what
 * makes most publishing platforms usable without an adapter each.
 *
 * Read per item: title, link, the richest body available (content:encoded,
 * then content, then description or summary), category terms, the id or
 * guid, and the published date as `starts_at`.
 */
final class FeedParser implements Parser
{
    /** @param  array<string, string>  $severityMap */
    public function __construct(
        private readonly RemoteAlertFactory $factory,
        private readonly string $pattern,
        private readonly array $severityMap = [],
        private readonly ?Severity $defaultSeverity = null,
    ) {}

    public function parse(string $body, string $contentType = ''): array
    {
        $doc = Xml::load($body);
        $root = $doc->documentElement;

        if ($root === null) {
            throw new MalformedPayload('No root element.');
        }

        return match ($root->localName) {
            'feed' => $this->atom($root),
            'rss' => $this->rss($root),
            'RDF' => $this->rss($root),
            default => throw new MalformedPayload("Not a feed: root element is <{$root->localName}>."),
        };
    }

    /** @return list<Alert> */
    private function atom(DOMElement $feed): array
    {
        $alerts = [];

        foreach (Xml::children($feed, 'entry') as $entry) {
            $terms = [];
            foreach (Xml::children($entry, 'category') as $category) {
                $terms[] = $category->getAttribute('term') ?: trim($category->textContent);
            }

            $link = null;
            foreach (Xml::children($entry, 'link') as $l) {
                $rel = $l->getAttribute('rel') ?: 'alternate';
                if ($rel === 'alternate' && $l->getAttribute('href') !== '') {
                    $link = $l->getAttribute('href');
                    break;
                }
            }

            $alert = $this->build(
                Xml::text($entry, 'id'),
                Xml::text($entry, 'title'),
                $this->atomBody($entry),
                $link,
                $terms,
                Xml::text($entry, 'published') ?? Xml::text($entry, 'updated'),
            );

            if ($alert !== null) {
                $alerts[] = $alert;
            }
        }

        return $alerts;
    }

    private function atomBody(DOMElement $entry): string
    {
        $content = Xml::child($entry, 'content') ?? Xml::child($entry, 'summary');

        if ($content === null) {
            return '';
        }

        $type = $content->getAttribute('type');

        // `xhtml` content is real elements; serialise them. `html` and
        // `text` are text nodes holding escaped markup or plain words.
        if ($type === 'xhtml') {
            $html = '';
            foreach ($content->childNodes as $child) {
                $html .= $content->ownerDocument?->saveXML($child) ?? '';
            }

            return $html;
        }

        $text = $content->textContent;

        return $type === 'text' || $type === ''
            ? ($this->looksLikeHtml($text) ? $text : nl2br(htmlspecialchars($text, ENT_QUOTES | ENT_HTML5, 'UTF-8'), false))
            : $text;
    }

    /** @return list<Alert> */
    private function rss(DOMElement $rss): array
    {
        $alerts = [];
        $channel = Xml::child($rss, 'channel') ?? $rss;

        // RSS 1.0 (RDF) puts items beside the channel, RSS 2.0 inside it.
        $items = Xml::children($channel, 'item');
        if ($items === [] && $channel !== $rss) {
            $items = Xml::children($rss, 'item');
        }

        foreach ($items as $item) {
            $terms = [];
            foreach (Xml::children($item, 'category') as $category) {
                $terms[] = trim($category->textContent);
            }

            $alert = $this->build(
                Xml::text($item, 'guid') ?? Xml::text($item, 'link'),
                Xml::text($item, 'title'),
                Xml::text($item, 'encoded') ?? Xml::text($item, 'content') ?? Xml::text($item, 'description') ?? '',
                Xml::text($item, 'link'),
                $terms,
                Xml::text($item, 'pubDate') ?? Xml::text($item, 'date'),
            );

            if ($alert !== null) {
                $alerts[] = $alert;
            }
        }

        return $alerts;
    }

    /** @param  list<string>  $terms */
    private function build(?string $id, ?string $title, string $body, ?string $link, array $terms, ?string $published): ?Alert
    {
        if ($title === null) {
            return null;
        }

        $extracted = (new PatternMap($this->pattern, $this->severityMap))->extract($terms);
        $severity = $extracted['severity'] ?? $this->defaultSeverity;

        if ($severity === null) {
            return null;
        }

        return $this->factory->fromHtml(
            $id ?? hash('sha256', $title),
            $title,
            $body,
            $severity,
            $extracted['audiences'],
            Dates::parse($published),
            null,
            $link,
        );
    }

    private function looksLikeHtml(string $text): bool
    {
        return preg_match('/<[a-z][^>]*>/i', $text) === 1;
    }
}
