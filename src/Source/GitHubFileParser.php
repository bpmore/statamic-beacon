<?php

declare(strict_types=1);

namespace Bpmore\Beacon\Source;

use Bpmore\Beacon\Alert\Alert;
use Bpmore\Beacon\Alert\Severity;
use Bpmore\Beacon\Mapping\Dates;
use Bpmore\Beacon\Mapping\SeverityMap;
use Bpmore\Beacon\Sanitize\Markdown;
use JsonException;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;
use Throwable;

/**
 * A JSON or YAML file in Beacon's own alert shape, from a repository, a
 * Gist, or anywhere a URL serves it.
 *
 * The shape is the one the local collection produces, documented in
 * `docs/alerts-file.schema.json`: an object with an `alerts` list (or a bare
 * list), each with `title`, `severity`, and optionally `id`, `body` (HTML),
 * `markdown` (used when `body` is absent), `teaser`, `audiences`,
 * `starts_at`, `ends_at`, `url`, `dismissible`. The file is remote, so its
 * HTML is sanitized like any other.
 */
final class GitHubFileParser implements Parser
{
    /** @param  array<string, string>  $severityMap */
    public function __construct(
        private readonly RemoteAlertFactory $factory,
        private readonly Markdown $markdown,
        private readonly array $severityMap = [],
    ) {}

    public function parse(string $body, string $contentType = ''): array
    {
        $data = $this->decode($body, $contentType);

        $items = is_array($data['alerts'] ?? null) ? $data['alerts'] : (array_is_list($data) ? $data : [$data]);

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

    /** @return array<mixed> */
    private function decode(string $body, string $contentType): array
    {
        $trimmed = ltrim($body, "\xEF\xBB\xBF \t\r\n");

        if ($trimmed === '') {
            throw new MalformedPayload('The file is empty.');
        }

        $looksJson = $trimmed[0] === '{' || $trimmed[0] === '[';

        if ($looksJson || str_contains($contentType, 'json')) {
            try {
                $data = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
            } catch (JsonException $e) {
                throw new MalformedPayload('The file is not JSON: '.$e->getMessage(), 0, $e);
            }
        } else {
            try {
                $data = Yaml::parse($body);
            } catch (ParseException $e) {
                throw new MalformedPayload('The file is not YAML: '.$e->getMessage(), 0, $e);
            } catch (Throwable $e) {
                throw new MalformedPayload('The file could not be read: '.$e->getMessage(), 0, $e);
            }
        }

        if (! is_array($data)) {
            throw new MalformedPayload('The file does not contain an object or list.');
        }

        return $data;
    }

    /** @param  array<mixed>  $item */
    private function item(array $item): ?Alert
    {
        $title = isset($item['title']) && is_scalar($item['title']) ? (string) $item['title'] : null;
        $severity = isset($item['severity']) && is_string($item['severity']) ? SeverityMap::resolve($item['severity'], $this->severityMap) : null;

        if ($title === null || trim($title) === '' || $severity === null) {
            return null;
        }

        $html = isset($item['body']) && is_scalar($item['body']) ? (string) $item['body'] : null;
        if ($html === null && isset($item['markdown']) && is_scalar($item['markdown'])) {
            $html = $this->markdown->toHtml((string) $item['markdown']);
        }

        if (isset($item['teaser']) && is_scalar($item['teaser']) && (string) $item['teaser'] !== '') {
            // The file's own teaser field, expressed through the marker so
            // the factory's split-then-sanitize path handles it.
            $html = (string) $item['teaser'].$this->factory->marker().($html ?? '');
        }

        $audiences = [];
        foreach ((array) ($item['audiences'] ?? []) as $a) {
            if (is_scalar($a) && (string) $a !== '') {
                $audiences[] = (string) $a;
            }
        }

        return $this->factory->fromHtml(
            isset($item['id']) && is_scalar($item['id']) ? (string) $item['id'] : hash('sha256', $title),
            $title,
            $html ?? '',
            $severity,
            $audiences,
            Dates::parse($item['starts_at'] ?? null),
            Dates::parse($item['ends_at'] ?? null),
            isset($item['url']) && is_scalar($item['url']) ? (string) $item['url'] : null,
            isset($item['dismissible']) && is_bool($item['dismissible']) ? $item['dismissible'] : null,
        );
    }
}
