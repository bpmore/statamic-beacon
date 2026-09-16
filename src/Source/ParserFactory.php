<?php

declare(strict_types=1);

namespace Bpmore\Beacon\Source;

use Bpmore\Beacon\Alert\Severity;
use Bpmore\Beacon\Sanitize\HtmlSanitizer;
use Bpmore\Beacon\Sanitize\Markdown;
use Bpmore\Beacon\Sanitize\Teaser;
use InvalidArgumentException;

/**
 * The parser for a remote source definition.
 *
 * The WordPress.com posts API's map is the default for `http`, so pointing
 * at a WordPress.com alert site needs only a URL. Every default here is
 * overridable from the source's own config.
 */
final class ParserFactory
{
    public const WORDPRESS_MAP = [
        'root' => 'posts',
        'id' => 'ID',
        'title' => 'title',
        'body' => 'content',
        'url' => 'URL',
        'starts_at' => 'date',
        'audiences' => ['from' => 'categories.*.slug', 'pattern' => '^(?<severity>urgent|alert|fyi)-(?<audience>[a-z0-9]+)$'],
    ];

    public const WORDPRESS_SEVERITY_MAP = ['urgent' => 'emergency', 'alert' => 'warning', 'fyi' => 'info'];

    public const WORDPRESS_EMPTY_WHEN = ['found' => 0];

    public const LABEL_PATTERN = '^(?<severity>urgent|alert|fyi|emergency|warning|info)-(?<audience>[a-z0-9]+)$';

    public function __construct(private readonly HtmlSanitizer $sanitizer) {}

    public function for(SourceDefinition $source): Parser
    {
        $factory = new RemoteAlertFactory($this->sanitizer, (string) $source->option('teaser_marker', Teaser::DEFAULT_MARKER));
        $severityMap = $this->severityMap($source);
        $default = $this->defaultSeverity($source);

        return match ($source->driver) {
            'http' => new JsonParser(
                $factory,
                (array) $source->option('map', self::WORDPRESS_MAP),
                $severityMap,
                (array) $source->option('empty_when', self::WORDPRESS_EMPTY_WHEN),
                $default,
            ),
            'feed' => new FeedParser(
                $factory,
                (string) $source->option('pattern', self::LABEL_PATTERN),
                $severityMap,
                $default,
            ),
            'cap' => new CapParser(
                $factory,
                (array) $source->option('matrix', CapParser::DEFAULT_MATRIX),
                $source->audiences,
                (bool) $source->option('append_instruction', true),
            ),
            'github' => match ((string) $source->option('mode', 'file')) {
                'issues' => new GitHubIssuesParser(
                    $factory,
                    new Markdown,
                    (string) $source->option('pattern', self::LABEL_PATTERN),
                    $severityMap,
                    $default,
                ),
                default => new GitHubFileParser($factory, new Markdown, $severityMap),
            },
            default => throw new InvalidArgumentException("Source `{$source->driver}` is not a remote source."),
        };
    }

    /** @return array<string, string> */
    private function severityMap(SourceDefinition $source): array
    {
        $map = $source->option('severity_map');

        if (! is_array($map)) {
            $map = self::WORDPRESS_SEVERITY_MAP;
        }

        $out = [];
        foreach ($map as $from => $to) {
            $out[strtolower((string) $from)] = strtolower((string) $to);
        }

        return $out;
    }

    private function defaultSeverity(SourceDefinition $source): ?Severity
    {
        $value = $source->option('default_severity');

        return is_string($value) ? Severity::tryFrom(strtolower($value)) : null;
    }
}
