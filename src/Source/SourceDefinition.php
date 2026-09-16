<?php

declare(strict_types=1);

namespace Bpmore\Beacon\Source;

use Bpmore\Beacon\Alert\Severity;
use InvalidArgumentException;

/**
 * One entry in the `sources` list, read once and checked.
 *
 * Every remote driver shares: a URL, how to reach it, how often, how long
 * a stored payload may be served, a severity ceiling, and an audience
 * restriction. The driver-specific parts sit in `options`.
 */
final class SourceDefinition
{
    public const DRIVERS = ['collection', 'http', 'feed', 'cap', 'github', 'null'];

    /**
     * @param  array<string, string>  $headers
     * @param  list<string>  $audiences  empty means no restriction
     * @param  array<string, mixed>  $options
     */
    public function __construct(
        public readonly string $key,
        public readonly string $driver,
        public readonly ?string $url,
        public readonly string $method,
        public readonly array $headers,
        public readonly int $timeout,
        public readonly int $poll,
        public readonly int $maxAge,
        public readonly ?Severity $maxSeverity,
        public readonly array $audiences,
        public readonly array $options,
    ) {}

    /** @param  array<string, mixed>  $config */
    public static function fromArray(array $config, int $index = 0): self
    {
        $driver = strtolower((string) ($config['driver'] ?? 'collection'));

        if (! in_array($driver, self::DRIVERS, true)) {
            throw new InvalidArgumentException("Unknown source driver `{$driver}`. One of: ".implode(', ', self::DRIVERS).'.');
        }

        $url = isset($config['url']) ? trim((string) $config['url']) : null;

        if ($driver === 'github') {
            $url = self::githubUrl($config) ?? $url;
        }

        if (in_array($driver, ['http', 'feed', 'cap', 'github'], true) && ($url === null || $url === '')) {
            throw new InvalidArgumentException("Source `{$driver}` at position {$index} has no url.");
        }

        $maxSeverity = null;
        if (isset($config['max_severity'])) {
            $maxSeverity = Severity::tryFrom(strtolower((string) $config['max_severity']));
            if ($maxSeverity === null) {
                throw new InvalidArgumentException("Source at position {$index}: max_severity must be one of ".implode(', ', Severity::values()).'.');
            }
        }

        $headers = [];
        foreach ((array) ($config['headers'] ?? []) as $name => $value) {
            $headers[(string) $name] = (string) $value;
        }

        $token = isset($config['token']) && is_string($config['token']) && trim($config['token']) !== '' ? trim($config['token']) : null;
        if ($driver === 'github' && $token !== null) {
            $headers['Authorization'] = 'Bearer '.$token;
        }

        $audiences = [];
        foreach ((array) ($config['audiences'] ?? []) as $a) {
            if (is_scalar($a) && (string) $a !== '') {
                $audiences[] = (string) $a;
            }
        }

        $key = isset($config['key']) && is_string($config['key']) && $config['key'] !== ''
            ? preg_replace('/[^a-z0-9_-]/i', '-', $config['key'])
            : $driver.'-'.$index;

        return new self(
            (string) $key,
            $driver,
            $url === '' ? null : $url,
            strtoupper((string) ($config['method'] ?? 'GET')),
            $headers,
            max(1, (int) ($config['timeout'] ?? 5)),
            max(30, (int) ($config['poll'] ?? 300)),
            max(60, (int) ($config['max_age'] ?? 86400)),
            $maxSeverity,
            $audiences,
            $config,
        );
    }

    public function isRemote(): bool
    {
        return in_array($this->driver, ['http', 'feed', 'cap', 'github'], true);
    }

    public function option(string $name, mixed $default = null): mixed
    {
        return $this->options[$name] ?? $default;
    }

    /** Whether this source's traffic counts against GitHub's REST limit. */
    public function countsAgainstGitHubApi(): bool
    {
        return $this->driver === 'github' && ($this->option('mode') ?? 'file') === 'issues';
    }

    /**
     * The URL a GitHub source reads, from its mode and parts. `file` reads
     * the raw CDN, which the REST rate limit does not count. `issues` reads
     * the API, which it does.
     *
     * @param  array<string, mixed>  $config
     */
    private static function githubUrl(array $config): ?string
    {
        $mode = (string) ($config['mode'] ?? 'file');
        $owner = isset($config['owner']) ? trim((string) $config['owner']) : '';
        $repo = isset($config['repo']) ? trim((string) $config['repo']) : '';

        return match ($mode) {
            'file' => $owner !== '' && $repo !== '' && isset($config['path'])
                ? sprintf('https://raw.githubusercontent.com/%s/%s/%s/%s', rawurlencode($owner), rawurlencode($repo), rawurlencode((string) ($config['ref'] ?? 'main')), ltrim((string) $config['path'], '/'))
                : null,
            'issues' => $owner !== '' && $repo !== ''
                ? sprintf('https://api.github.com/repos/%s/%s/issues?state=open&per_page=50&labels=%s', rawurlencode($owner), rawurlencode($repo), rawurlencode((string) ($config['label'] ?? 'alert')))
                : null,
            'gist' => isset($config['gist']) && is_string($config['gist']) && str_starts_with($config['gist'], 'http')
                ? $config['gist']
                : null,
            default => throw new InvalidArgumentException("GitHub mode must be file, issues or gist, not `{$mode}`."),
        };
    }
}
