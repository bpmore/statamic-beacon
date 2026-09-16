<?php

declare(strict_types=1);

namespace Bpmore\Beacon;

use Bpmore\Beacon\Source\ParserFactory;
use Illuminate\Support\Arr;
use Statamic\Contracts\Addons\SettingsRepository;

/**
 * The settings in force: the config file, with the control panel screen
 * on top once somebody has saved it.
 *
 * Three rules, taken from the sibling addons that learned them:
 *
 * - Whether the screen has been saved is the question, not whether it has
 *   values. An unsaved record comes back carrying the form's own defaults,
 *   and reading those would let a default beat a file a developer wrote.
 * - `raw()`, not `all()`. `all()` blends the form's defaults over what was
 *   saved, so a field left empty comes back as the default rather than as
 *   "the file answers".
 * - A text field left empty is the file's to answer. An empty sources list
 *   is too: a person who saved the screen without touching sources must
 *   not switch the banner off. The "Nothing" block is how you do that.
 *
 * Read on every resolve, so a save is seen by the next request.
 */
final class Settings
{
    public const PACKAGE = 'bpmore/statamic-beacon';

    /** Scalar form handle => config key. */
    public const MAP = [
        'audience' => 'audience',
        'heading_level' => 'render.heading_level',
        'compat' => 'render.compat',
        'compat_prefix' => 'render.compat_prefix',
        'label_info' => 'render.labels.info',
        'label_warning' => 'render.labels.warning',
        'label_emergency' => 'render.labels.emergency',
        'more_text' => 'render.more_text',
        'dismiss_text' => 'render.dismiss_text',
        'preview_text' => 'render.preview_text',
        'assets' => 'assets',
        'preview_enabled' => 'preview.enabled',
        'preview_parameter' => 'preview.parameter',
        'warn_after' => 'scheduler.warn_after',
        'fast_path_enabled' => 'fast_path.enabled',
        'fast_path_interval' => 'fast_path.interval',
    ];

    /** Fields the form collects in a different shape from the file. */
    public const SHAPED = ['site_audiences', 'sources'];

    /** Whether the screen has ever been saved. */
    public function saved(): bool
    {
        return $this->raw() !== null;
    }

    /** @return array<string, mixed> the whole config, as in force */
    public function effective(): array
    {
        $config = (array) config('statamic-beacon', []);
        $saved = $this->raw();

        if ($saved === null) {
            return $config;
        }

        foreach (self::MAP as $handle => $key) {
            if (! array_key_exists($handle, $saved) || $saved[$handle] === null || $saved[$handle] === '') {
                continue;
            }

            Arr::set($config, $key, $saved[$handle]);
        }

        if (is_array($saved['site_audiences'] ?? null)) {
            $map = self::siteAudiences($saved['site_audiences']);
            if ($map !== []) {
                $config['site_audiences'] = $map;
            }
        }

        if (is_array($saved['sources'] ?? null)) {
            $sources = self::sources($saved['sources']);
            if ($sources !== []) {
                $config['sources'] = $sources;
            }
        }

        return $config;
    }

    /** @return array<string, mixed>|null */
    private function raw(): ?array
    {
        try {
            return app(SettingsRepository::class)->find(self::PACKAGE)?->raw();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Grid rows naming a site, as the file keeps them: keyed by site.
     *
     * @return array<string, string>
     */
    public static function siteAudiences(mixed $rows): array
    {
        $map = [];

        foreach (is_array($rows) ? $rows : [] as $row) {
            if (! is_array($row)) {
                continue;
            }

            // The sites fieldtype hands back a list even when it takes one.
            $site = is_array($row['site'] ?? null) ? ($row['site'][0] ?? null) : ($row['site'] ?? null);
            $audience = $row['audience'] ?? null;

            if (is_string($site) && $site !== '' && is_string($audience) && trim($audience) !== '') {
                $map[$site] = trim($audience);
            }
        }

        return $map;
    }

    /**
     * Replicator blocks to the source list the file would hold.
     *
     * Each block type is a driver plus the shape the form asked for it in.
     * The WordPress.com block is the `http` driver with that API's field
     * map left to the parser's defaults, which is why it needs only an
     * address.
     *
     * @return list<array<string, mixed>>
     */
    public static function sources(mixed $blocks): array
    {
        $out = [];

        foreach (is_array($blocks) ? $blocks : [] as $block) {
            if (! is_array($block) || ! isset($block['type'])) {
                continue;
            }

            if (($block['enabled'] ?? true) === false) {
                continue;
            }

            $source = self::source($block);

            if ($source !== null) {
                $out[] = $source;
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $b
     * @return array<string, mixed>|null
     */
    private static function source(array $b): ?array
    {
        $common = array_filter([
            'key' => self::str($b, 'key'),
            'poll' => self::int($b, 'poll'),
            'timeout' => self::int($b, 'timeout'),
            'max_age' => self::int($b, 'max_age'),
            'max_severity' => self::str($b, 'max_severity'),
            'audiences' => self::list($b, 'audiences'),
        ], fn ($v) => $v !== null && $v !== []);

        return match ((string) $b['type']) {
            'collection' => ['driver' => 'collection', 'collection' => self::str($b, 'collection') ?? 'alerts'],
            'off' => ['driver' => 'null'],
            'wordpress' => self::str($b, 'url') === null ? null : $common + [
                'driver' => 'http',
                'url' => self::str($b, 'url'),
                'map' => ParserFactory::WORDPRESS_MAP,
                'severity_map' => ParserFactory::WORDPRESS_SEVERITY_MAP,
                'empty_when' => ParserFactory::WORDPRESS_EMPTY_WHEN,
            ],
            'http' => self::str($b, 'url') === null ? null : $common + array_filter([
                'driver' => 'http',
                'url' => self::str($b, 'url'),
                'map' => self::httpMap($b),
                'severity_map' => self::severityMap($b['severity_map'] ?? null),
                'default_severity' => self::str($b, 'default_severity'),
                'empty_when' => [],
            ], fn ($v) => $v !== null),
            'feed' => self::str($b, 'url') === null ? null : $common + array_filter([
                'driver' => 'feed',
                'url' => self::str($b, 'url'),
                'severity_map' => ParserFactory::WORDPRESS_SEVERITY_MAP,
                'default_severity' => self::str($b, 'default_severity'),
            ], fn ($v) => $v !== null),
            'cap' => self::str($b, 'url') === null ? null : $common + array_filter([
                'driver' => 'cap',
                'url' => self::str($b, 'url'),
                'geocodes' => ParserFactory::geocodes($b['geocodes'] ?? null) ?: null,
                'headers' => array_filter([
                    'User-Agent' => self::str($b, 'user_agent'),
                    'Accept' => 'application/geo+json, application/cap+xml, application/atom+xml, application/xml;q=0.9, */*;q=0.8',
                ]),
            ], fn ($v) => $v !== null),
            'github_file' => self::str($b, 'owner') === null || self::str($b, 'repo') === null ? null : $common + array_filter([
                'driver' => 'github',
                'mode' => 'file',
                'owner' => self::str($b, 'owner'),
                'repo' => self::str($b, 'repo'),
                'ref' => self::str($b, 'ref') ?? 'main',
                'path' => self::str($b, 'path') ?? 'alerts.json',
                'token' => self::str($b, 'token'),
            ], fn ($v) => $v !== null),
            'github_issues' => self::str($b, 'owner') === null || self::str($b, 'repo') === null ? null : $common + array_filter([
                'driver' => 'github',
                'mode' => 'issues',
                'owner' => self::str($b, 'owner'),
                'repo' => self::str($b, 'repo'),
                'label' => self::str($b, 'label') ?? 'alert',
                'token' => self::str($b, 'token'),
            ], fn ($v) => $v !== null),
            'gist' => self::str($b, 'url') === null ? null : $common + [
                'driver' => 'github',
                'mode' => 'gist',
                'gist' => self::str($b, 'url'),
            ],
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $b
     * @return array<string, mixed>
     */
    private static function httpMap(array $b): array
    {
        $map = array_filter([
            'root' => self::str($b, 'map_root') ?? '',
            'id' => self::str($b, 'map_id'),
            'title' => self::str($b, 'map_title'),
            'body' => self::str($b, 'map_body'),
            'url' => self::str($b, 'map_url'),
        ], fn ($v) => $v !== null);

        $pattern = self::str($b, 'pattern');
        $audiencesFrom = self::str($b, 'map_audiences');
        $severity = self::str($b, 'map_severity');

        if ($audiencesFrom !== null) {
            $map['audiences'] = $pattern !== null ? ['from' => $audiencesFrom, 'pattern' => $pattern] : $audiencesFrom;
        }

        if ($severity !== null) {
            $map['severity'] = $severity;
        }

        return $map;
    }

    /** @return array<string, string>|null */
    private static function severityMap(mixed $rows): ?array
    {
        $map = [];

        foreach (is_array($rows) ? $rows : [] as $row) {
            if (is_array($row) && is_string($row['from'] ?? null) && trim($row['from']) !== '' && is_string($row['to'] ?? null) && $row['to'] !== '') {
                $map[strtolower(trim($row['from']))] = $row['to'];
            }
        }

        return $map === [] ? null : $map;
    }

    /** @param  array<string, mixed>  $b */
    private static function str(array $b, string $key): ?string
    {
        $v = $b[$key] ?? null;

        if (is_array($v)) {
            $v = $v[0] ?? null;
        }

        return is_scalar($v) && trim((string) $v) !== '' ? trim((string) $v) : null;
    }

    /** @param  array<string, mixed>  $b */
    private static function int(array $b, string $key): ?int
    {
        $v = $b[$key] ?? null;

        return is_numeric($v) ? (int) $v : null;
    }

    /**
     * @param  array<string, mixed>  $b
     * @return list<string>
     */
    private static function list(array $b, string $key): array
    {
        $out = [];

        foreach ((array) ($b[$key] ?? []) as $v) {
            if (is_scalar($v) && trim((string) $v) !== '') {
                $out[] = trim((string) $v);
            }
        }

        return $out;
    }
}
