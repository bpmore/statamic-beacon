<?php

declare(strict_types=1);

namespace Bpmore\Beacon;

use Bpmore\Beacon\Alert\Alert;
use Bpmore\Beacon\Alert\Audience;
use Bpmore\Beacon\Alert\Clock;
use Bpmore\Beacon\Alert\Selector;
use Bpmore\Beacon\Alert\Severity;
use Bpmore\Beacon\Fetch\Poller;
use Bpmore\Beacon\Fetch\PollResult;
use Bpmore\Beacon\Fetch\Store;
use Bpmore\Beacon\Render\Banner;
use Bpmore\Beacon\Render\Options;
use Bpmore\Beacon\Source\NullSource;
use Bpmore\Beacon\Source\ParserFactory;
use Bpmore\Beacon\Source\RemoteSource;
use Bpmore\Beacon\Source\SourceChain;
use Bpmore\Beacon\Source\SourceDefinition;
use Bpmore\Beacon\Statamic\CollectionSource;
use DateTimeImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Statamic\Facades\Site;
use Statamic\Facades\StaticCache;
use Statamic\Facades\User;
use Throwable;

/**
 * The addon, wired: config to a source chain, the chain to the alerts a
 * visitor sees, the scheduler to the poller, a change to a cache flush.
 *
 * Everything that decides what an alert is or whether it shows lives in
 * the core namespaces and is called from here with values.
 */
final class Beacon
{
    public const PERMISSION_PREVIEW = 'view beacon previews';

    public const STATE_KEY = 'state:scheduler';

    /** @var array<string, mixed> */
    private array $config;

    private ?SourceChain $chain = null;

    public function __construct(
        array $config,
        private readonly Store $store,
        private readonly Poller $poller,
        private readonly ParserFactory $parsers,
        private readonly Clock $clock,
    ) {
        $this->config = $config;
    }

    // -- Reading -----------------------------------------------------------

    /** The audience of the current site, by exact lookup. */
    public function audience(): string
    {
        $handle = null;

        try {
            $handle = Site::current()->handle();
        } catch (Throwable) {
            // No site resolved (console with no request): the default audience.
        }

        return Audience::forSite($handle, (array) ($this->config['site_audiences'] ?? []), (string) ($this->config['audience'] ?? 'default'));
    }

    /** @return list<SourceDefinition> */
    public function definitions(): array
    {
        $out = [];

        foreach ((array) ($this->config['sources'] ?? [['driver' => 'collection']]) as $index => $source) {
            try {
                $out[] = SourceDefinition::fromArray((array) $source, (int) $index);
            } catch (Throwable $e) {
                Log::error('Beacon: source at position '.$index.' is misconfigured and was skipped: '.$e->getMessage());
            }
        }

        return $out;
    }

    public function chain(): SourceChain
    {
        if ($this->chain !== null) {
            return $this->chain;
        }

        $chain = new SourceChain(fn (string $level, string $message) => Log::log($level, $message));

        foreach ($this->definitions() as $definition) {
            $source = match ($definition->driver) {
                'collection' => new CollectionSource((string) ($definition->option('collection') ?? $this->config['collection'] ?? 'alerts'), $this->parsers->sanitizer()),
                'null' => new NullSource,
                default => new RemoteSource($definition, $this->poller, $this->clock),
            };

            $chain->add($source, $definition);
        }

        return $this->chain = $chain;
    }

    /** Every alert from every source, before time and audience filtering, newest first per source. @return list<Alert> */
    public function all(): array
    {
        return $this->chain()->fetch();
    }

    /** What a visitor to this site sees now. @return list<Alert> */
    public function active(): array
    {
        return Selector::active($this->all(), $this->clock->now(), $this->audience());
    }

    // -- Rendering ---------------------------------------------------------

    public function options(): Options
    {
        return Options::fromArray((array) ($this->config['render'] ?? []));
    }

    /**
     * The banner for this request: the live alerts, or a preview when a
     * permitted user asks for one with a valid severity.
     */
    public function render(?Request $request = null): string
    {
        $preview = $request !== null ? $this->previewSeverity($request) : null;

        if ($preview !== null) {
            $candidate = $this->previewCandidate();

            return $candidate === null ? '' : (new Banner($this->options()))->render([$candidate->with(severity: $preview)], preview: true);
        }

        try {
            return (new Banner($this->options()))->render($this->active());
        } catch (Throwable $e) {
            // The banner is on every page. Whatever went wrong, the page renders.
            Log::error('Beacon: rendering failed and the banner was left out: '.$e->getMessage());

            return '';
        }
    }

    /**
     * The severity a preview request asks for, or null when this request is
     * not a permitted preview. The value is checked against the enum
     * before anything else looks at it; the user is checked second.
     */
    public function previewSeverity(Request $request): ?Severity
    {
        $preview = (array) ($this->config['preview'] ?? []);

        if (! (bool) ($preview['enabled'] ?? true)) {
            return null;
        }

        $raw = $request->query((string) ($preview['parameter'] ?? 'beacon-preview'));

        if (! is_string($raw)) {
            return null;
        }

        $severity = Severity::tryFrom(strtolower($raw));

        if ($severity === null) {
            return null;
        }

        $user = User::current();

        if ($user === null || ! $user->can(self::PERMISSION_PREVIEW)) {
            return null;
        }

        return $severity;
    }

    /**
     * The newest alert from any source, whatever its schedule, audience or
     * published state: what the reference test mode showed, so comms
     * staff can see a banner before publishing one.
     */
    public function previewCandidate(): ?Alert
    {
        foreach ($this->chain()->definitions() as $definition) {
            if ($definition->driver !== 'collection') {
                continue;
            }

            $source = new CollectionSource((string) ($definition->option('collection') ?? $this->config['collection'] ?? 'alerts'), $this->parsers->sanitizer());
            $all = $source->fetchAll();

            if ($all !== []) {
                return SourceChain::constrain($all[0], $definition);
            }
        }

        return $this->all()[0] ?? null;
    }

    /**
     * The emergency fast path's settings: whether the page script polls
     * this origin between loads, and how often.
     *
     * @return array{enabled: bool, interval: int, severities: list<string>}
     */
    public function fastPath(): array
    {
        $config = (array) ($this->config['fast_path'] ?? []);

        return [
            'enabled' => (bool) ($config['enabled'] ?? false),
            'interval' => max(15, (int) ($config['interval'] ?? 60)),
            'severities' => array_map(fn ($s) => $s->value, \Bpmore\Beacon\Http\Controllers\LiveController::severities($config['severities'] ?? ['emergency'])),
        ];
    }

    // -- Scheduling --------------------------------------------------------

    /**
     * One minute's work: poll the remote sources that are due, notice
     * alerts whose start or end has just passed, and clear the page cache
     * when either changed what a visitor would see.
     *
     * @return array{polled: list<PollResult>, boundaries: list<string>, flushed: bool}
     */
    public function tick(bool $force = false): array
    {
        $now = $this->clock->now();
        $state = $this->store->get(self::STATE_KEY) ?? [];
        $since = isset($state['last_tick_at']) && is_string($state['last_tick_at']) ? new DateTimeImmutable($state['last_tick_at']) : $now->modify('-1 minute');

        $polled = $this->poll($force);
        $flush = array_reduce($polled, fn (bool $carry, PollResult $r) => $carry || $r->changed, false);

        $crossed = Selector::crossingBoundary($this->all(), $since, $now);
        $boundaries = array_map(fn (Alert $a) => $a->id, $crossed);
        $flush = $flush || $crossed !== [];

        if ($flush) {
            $this->flush();
        }

        $this->store->put(self::STATE_KEY, [
            'last_tick_at' => $now->format(DATE_ATOM),
            'last_flush_at' => $flush ? $now->format(DATE_ATOM) : ($state['last_flush_at'] ?? null),
        ]);

        return ['polled' => $polled, 'boundaries' => $boundaries, 'flushed' => $flush];
    }

    /**
     * Poll every remote source that is due (or all of them, forced).
     *
     * @return list<PollResult>
     */
    public function poll(bool $force = false): array
    {
        $results = [];

        foreach ($this->definitions() as $definition) {
            if (! $definition->isRemote()) {
                continue;
            }

            if (! $force && ! $this->poller->isDue($definition)) {
                continue;
            }

            try {
                $results[] = $this->poller->poll($definition, $this->parsers->for($definition), $force);
            } catch (Throwable $e) {
                // The poller does not throw. If it somehow did, the other
                // sources still get their turn.
                Log::error("Beacon: polling `{$definition->key}` threw: ".$e->getMessage());
            }
        }

        return $results;
    }

    /** Clear the static page cache. The banner is on every page, so it is all of it. */
    public function flush(): void
    {
        try {
            StaticCache::flush();
        } catch (Throwable $e) {
            Log::error('Beacon: static cache flush failed: '.$e->getMessage());
        }
    }

    // -- Status ------------------------------------------------------------

    /**
     * What the control panel and `beacon:status` show.
     *
     * @return array<string, mixed>
     */
    public function status(): array
    {
        $now = $this->clock->now();
        $state = $this->store->get(self::STATE_KEY) ?? [];
        $lastTick = isset($state['last_tick_at']) && is_string($state['last_tick_at']) ? new DateTimeImmutable($state['last_tick_at']) : null;
        $warnAfter = (int) (($this->config['scheduler']['warn_after'] ?? 300));

        $sources = [];
        $maxPoll = 0;
        $healthy = true;

        foreach ($this->definitions() as $definition) {
            if (! $definition->isRemote()) {
                $sources[] = [
                    'key' => $definition->key,
                    'driver' => $definition->driver,
                    'remote' => false,
                    'ok' => true,
                ];

                continue;
            }

            $snapshot = $this->poller->snapshot($definition);
            $stale = $snapshot->isStale($now, $definition->maxAge);
            $never = ! $snapshot->hasPayload();
            $ok = ! $stale && ! $never;
            $healthy = $healthy && $ok;
            $maxPoll = max($maxPoll, $definition->poll);

            $sources[] = [
                'key' => $definition->key,
                'driver' => $definition->driver,
                'remote' => true,
                'url' => $definition->url,
                'poll' => $definition->poll,
                'max_age' => $definition->maxAge,
                'max_severity' => $definition->maxSeverity?->value,
                'audiences' => $definition->audiences,
                'alerts' => count($snapshot->alerts),
                'fetched_at' => $snapshot->fetchedAt?->format(DATE_ATOM),
                'attempted_at' => $snapshot->attemptedAt?->format(DATE_ATOM),
                'last_error' => $snapshot->lastError,
                'last_error_at' => $snapshot->lastErrorAt?->format(DATE_ATOM),
                'backoff_until' => $snapshot->backoffUntil?->format(DATE_ATOM),
                'rate_limit_remaining' => $snapshot->rateLimitRemaining,
                'rate_limit_reset' => $snapshot->rateLimitReset?->format(DATE_ATOM),
                'stale' => $stale,
                'never_fetched' => $never,
                'ok' => $ok,
            ];
        }

        $hasRemote = $maxPoll > 0;
        $schedulerLate = $lastTick === null || ($now->getTimestamp() - $lastTick->getTimestamp()) > $warnAfter;

        return [
            'audience' => $this->audience(),
            'active' => count($this->active()),
            'sources' => $sources,
            'scheduler' => [
                'last_tick_at' => $lastTick?->format(DATE_ATOM),
                'last_flush_at' => $state['last_flush_at'] ?? null,
                'warn_after' => $warnAfter,
                'late' => $schedulerLate,
            ],
            // Worst case from a remote change to a visitor seeing it: the
            // longest poll interval, plus up to a minute for the scheduler
            // tick that runs it. The flush itself is immediate.
            'freshness_budget_seconds' => $hasRemote ? $maxPoll + 60 : null,
            'healthy' => $healthy && (! $hasRemote || ! $schedulerLate),
        ];
    }
}
