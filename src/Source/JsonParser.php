<?php

declare(strict_types=1);

namespace Bpmore\Beacon\Source;

use Bpmore\Beacon\Alert\Alert;
use Bpmore\Beacon\Alert\Severity;
use Bpmore\Beacon\Mapping\Dates;
use Bpmore\Beacon\Mapping\PathReader;
use Bpmore\Beacon\Mapping\PatternMap;
use Bpmore\Beacon\Mapping\SeverityMap;
use JsonException;

/**
 * A JSON document to alerts, through a field map from config.
 *
 * The map names where each field lives. `audiences` and `severity` may be
 * a plain path or `['from' => path, 'pattern' => regex]`, where the pattern's
 * named groups pull severity and audience out of one term. Fields the map
 * does not mention are never read. An item with no resolvable severity is
 * not an alert and is skipped, which is also what the reference client did.
 *
 * `empty_when` is a set of path => value pairs; when every pair matches the
 * document is empty, whatever else it contains. The reference feed's `found`
 * is authoritative that way.
 */
final class JsonParser implements Parser
{
    /**
     * @param  array<string, mixed>  $map
     * @param  array<string, string>  $severityMap
     * @param  array<string, mixed>  $emptyWhen
     */
    public function __construct(
        private readonly RemoteAlertFactory $factory,
        private readonly array $map,
        private readonly array $severityMap = [],
        private readonly array $emptyWhen = [],
        private readonly ?Severity $defaultSeverity = null,
    ) {}

    public function parse(string $body, string $contentType = ''): array
    {
        try {
            $data = json_decode(self::unwrapJsonp($body), true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new MalformedPayload('The response is not JSON: '.$e->getMessage(), 0, $e);
        }

        if (! is_array($data)) {
            throw new MalformedPayload('The response is JSON but not an object or list.');
        }

        return $this->fromDecoded($data);
    }

    /**
     * The JSON inside a JSONP wrapper, or the body as it was.
     *
     * The reference proxy answers `displayAlert({...})` whatever it is
     * asked. Read here as text and unwrapped: the callback name is
     * discarded and nothing is ever executed, which is the whole
     * difference between this and a script tag.
     */
    public static function unwrapJsonp(string $body): string
    {
        // Some JSONP responses lead with an empty comment against a
        // content-sniffing attack. It is not part of the payload.
        $trimmed = trim((string) preg_replace('/^\s*\/\*.*?\*\//s', '', $body));

        if ($trimmed === '' || $trimmed[0] === '{' || $trimmed[0] === '[') {
            return $body;
        }

        if (preg_match('/^[A-Za-z_$][\w$.]*\s*\(\s*([\[{].*[\]}])\s*\)\s*;?$/s', $trimmed, $m) === 1) {
            return $m[1];
        }

        return $body;
    }

    /**
     * @param  array<mixed>  $data
     * @return list<Alert>
     */
    public function fromDecoded(array $data): array
    {
        if ($this->isEmpty($data)) {
            return [];
        }

        $root = (string) ($this->map['root'] ?? '');
        $items = $root === '' ? $data : (PathReader::one($data, $root) ?? []);

        if (! is_array($items)) {
            throw new MalformedPayload("Nothing iterable at `{$root}`.");
        }

        // A single object where a list was expected is one item.
        if ($items !== [] && ! array_is_list($items) && ! $this->looksLikeKeyedItems($items)) {
            $items = [$items];
        }

        $alerts = [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $alert = $this->item($item);

            if ($alert !== null) {
                $alerts[] = $alert;
            }
        }

        return $alerts;
    }

    /** @param  array<mixed>  $data */
    private function isEmpty(array $data): bool
    {
        if ($this->emptyWhen === []) {
            return false;
        }

        foreach ($this->emptyWhen as $path => $expected) {
            $actual = PathReader::one($data, (string) $path);

            // Loose on purpose: a feed says `"found": 0` or `"found": "0"`.
            if ($actual === null || (string) $actual !== (string) $expected) {
                return false;
            }
        }

        return true;
    }

    /** @param  array<mixed>  $item */
    private function item(array $item): ?Alert
    {
        $id = $this->string($item, 'id');
        $title = $this->string($item, 'title');

        if ($title === null) {
            return null;
        }

        $terms = $this->terms($item, 'audiences');
        $severity = $this->severity($item, $terms);

        if ($severity === null) {
            return null;
        }

        $audiences = $this->audiences($item, $terms);

        $dismissible = PathReader::one($item, (string) ($this->map['dismissible'] ?? ''));

        return $this->factory->fromHtml(
            $id ?? hash('sha256', $title),
            $title,
            $this->string($item, 'body') ?? '',
            $severity,
            $audiences,
            Dates::parse(PathReader::one($item, (string) ($this->map['starts_at'] ?? ''))),
            Dates::parse(PathReader::one($item, (string) ($this->map['ends_at'] ?? ''))),
            $this->string($item, 'url'),
            is_bool($dismissible) ? $dismissible : null,
        );
    }

    /**
     * The raw terms a patterned field reads, or the plain values of a path.
     *
     * @param  array<mixed>  $item
     * @return list<mixed>
     */
    private function terms(array $item, string $field): array
    {
        $spec = $this->map[$field] ?? null;

        if (is_string($spec)) {
            return $spec === '' ? [] : PathReader::all($item, $spec);
        }

        if (is_array($spec) && isset($spec['from'])) {
            return PathReader::all($item, (string) $spec['from']);
        }

        return [];
    }

    /**
     * @param  array<mixed>  $item
     * @param  list<mixed>  $audienceTerms
     */
    private function severity(array $item, array $audienceTerms): ?Severity
    {
        $found = [];

        // A pattern on `audiences` may name a severity group too.
        $spec = $this->map['audiences'] ?? null;
        if (is_array($spec) && isset($spec['pattern'])) {
            $found[] = (new PatternMap((string) $spec['pattern'], $this->severityMap))->extract($audienceTerms)['severity'];
        }

        $spec = $this->map['severity'] ?? null;
        if (is_string($spec) && $spec !== '') {
            foreach (PathReader::all($item, $spec) as $word) {
                if (is_string($word)) {
                    $found[] = SeverityMap::resolve($word, $this->severityMap);
                }
            }
        } elseif (is_array($spec) && isset($spec['from'], $spec['pattern'])) {
            $found[] = (new PatternMap((string) $spec['pattern'], $this->severityMap))
                ->extract(PathReader::all($item, (string) $spec['from']))['severity'];
        }

        return Severity::highest(array_filter($found)) ?? $this->defaultSeverity;
    }

    /**
     * @param  array<mixed>  $item
     * @param  list<mixed>  $terms
     * @return list<string>
     */
    private function audiences(array $item, array $terms): array
    {
        $spec = $this->map['audiences'] ?? null;

        if (is_array($spec) && isset($spec['pattern'])) {
            return (new PatternMap((string) $spec['pattern'], $this->severityMap))->extract($terms)['audiences'];
        }

        return array_values(array_unique(array_filter(array_map(
            fn ($t) => is_string($t) ? $t : (is_scalar($t) ? (string) $t : null),
            $terms,
        ))));
    }

    /** @param  array<mixed>  $item */
    private function string(array $item, string $field): ?string
    {
        $path = $this->map[$field] ?? null;

        if (! is_string($path) || $path === '') {
            return null;
        }

        $value = PathReader::one($item, $path);

        if ($value === null || is_array($value)) {
            return null;
        }

        $value = is_bool($value) ? ($value ? '1' : '0') : (string) $value;

        return trim($value) === '' ? null : $value;
    }

    /**
     * Whether an associative array is a set of items keyed by id rather
     * than one item. Items are arrays; fields are not.
     *
     * @param  array<mixed>  $items
     */
    private function looksLikeKeyedItems(array $items): bool
    {
        foreach ($items as $value) {
            if (! is_array($value)) {
                return false;
            }
        }

        return true;
    }
}
