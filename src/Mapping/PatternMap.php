<?php

declare(strict_types=1);

namespace Bpmore\Beacon\Mapping;

use Bpmore\Beacon\Alert\Severity;

/**
 * Severity and audiences out of a set of tags, labels, or category slugs.
 *
 * A WordPress alert site encodes both in one slug: `urgent-clinic`. A
 * GitHub label does the same. The pattern names its groups `severity` and
 * `audience`; either may be absent. A term the pattern does not match is
 * ignored, so a `bug` label or a `general-information` category is not an
 * error. When several terms name a severity, the highest wins, whatever
 * order they came in.
 */
final class PatternMap
{
    /**
     * @param  array<string, string>  $severityMap  feed vocabulary => beacon severity
     */
    public function __construct(
        private readonly string $pattern,
        private readonly array $severityMap = [],
    ) {}

    /**
     * @param  iterable<mixed>  $terms
     * @return array{severity: Severity|null, audiences: list<string>}
     */
    public function extract(iterable $terms): array
    {
        $severities = [];
        $audiences = [];

        foreach ($terms as $term) {
            if (! is_string($term) || $term === '') {
                continue;
            }

            if (@preg_match($this->regex(), $term, $m) !== 1) {
                continue;
            }

            if (isset($m['severity']) && $m['severity'] !== '') {
                $severity = SeverityMap::resolve($m['severity'], $this->severityMap);
                if ($severity !== null) {
                    $severities[] = $severity;
                }
            }

            if (isset($m['audience']) && $m['audience'] !== '') {
                $audiences[] = $m['audience'];
            }
        }

        return [
            'severity' => Severity::highest($severities),
            'audiences' => array_values(array_unique($audiences)),
        ];
    }

    private function regex(): string
    {
        $p = $this->pattern;

        // A bare pattern from config, without delimiters, is the common case.
        if ($p !== '' && ! in_array($p[0], ['/', '#', '~', '%'], true)) {
            return '/'.str_replace('/', '\/', $p).'/';
        }

        return $p;
    }
}
