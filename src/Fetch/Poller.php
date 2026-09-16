<?php

declare(strict_types=1);

namespace Bpmore\Beacon\Fetch;

use Bpmore\Beacon\Alert\Clock;
use Bpmore\Beacon\Source\MalformedPayload;
use Bpmore\Beacon\Source\Parser;
use Bpmore\Beacon\Source\SourceDefinition;
use Closure;
use DateTimeImmutable;
use Throwable;

/**
 * One scheduled fetch of one remote source.
 *
 * Nothing here throws to a caller. A source that is unreachable, slow,
 * malformed, or rate-limited leaves the last good payload where it was and
 * writes down what went wrong. A source that answers "nothing" replaces
 * the payload with nothing: an empty feed is a real answer.
 *
 * The stored fingerprint is compared after every success, and `changed` is
 * reported only when it differs. Polling every five minutes and clearing
 * the page cache every time would cost the cache for nothing.
 */
final class Poller
{
    /** Stop polling GitHub's API before it says no. */
    public const RATE_LIMIT_FLOOR = 3;

    /** How long to wait after a network or server failure before trying again. */
    public const FAILURE_BACKOFF = 60;

    /**
     * @param  (Closure(string, string): void)|null  $log  level, message
     */
    public function __construct(
        private readonly Transport $transport,
        private readonly Store $store,
        private readonly Clock $clock,
        private readonly ?Closure $log = null,
    ) {}

    public static function storeKey(SourceDefinition $source): string
    {
        return 'source:'.$source->key;
    }

    public function snapshot(SourceDefinition $source): Snapshot
    {
        $data = $this->store->get(self::storeKey($source));

        try {
            return $data === null ? new Snapshot : Snapshot::fromArray($data);
        } catch (Throwable $e) {
            $this->log('warning', "Beacon: stored snapshot for `{$source->key}` is unreadable and was discarded: ".$e->getMessage());

            return new Snapshot;
        }
    }

    /** Whether the poll interval has elapsed since the last attempt. */
    public function isDue(SourceDefinition $source, ?Snapshot $snapshot = null): bool
    {
        $snapshot ??= $this->snapshot($source);

        if ($snapshot->attemptedAt === null) {
            return true;
        }

        return ($this->clock->now()->getTimestamp() - $snapshot->attemptedAt->getTimestamp()) >= $source->poll;
    }

    public function poll(SourceDefinition $source, Parser $parser, bool $force = false): PollResult
    {
        $now = $this->clock->now();
        $before = $this->snapshot($source);
        $key = self::storeKey($source);

        if (! $force && $before->isBackingOff($now)) {
            return new PollResult($source->key, $before, false, false, true, 'backing off until '.$before->backoffUntil?->format(DATE_ATOM));
        }

        if ($source->countsAgainstGitHubApi() && $before->rateLimitRemaining !== null && $before->rateLimitRemaining <= self::RATE_LIMIT_FLOOR) {
            $resetAt = $before->rateLimitReset;
            if ($resetAt !== null && $resetAt > $now) {
                $after = $before->withBackoff($resetAt, $now, "GitHub rate limit nearly exhausted ({$before->rateLimitRemaining} left); waiting for the reset at ".$resetAt->format(DATE_ATOM));
                $this->store->put($key, $after->toArray());
                $this->log('warning', "Beacon: skipped polling `{$source->key}`: {$after->lastError}");

                return new PollResult($source->key, $after, false, false, true, $after->lastError);
            }
        }

        $request = new Request($source->url ?? '', $source->method, $source->headers, $source->timeout);

        if ($before->etag !== null) {
            $request = $request->withHeaders(['If-None-Match' => $before->etag]);
        }

        try {
            $response = $this->transport->send($request);
        } catch (TransportFailed $e) {
            return $this->failed($source, $before, $now, 'Could not reach the source: '.$e->getMessage(), $now->modify('+'.self::FAILURE_BACKOFF.' seconds'));
        } catch (Throwable $e) {
            return $this->failed($source, $before, $now, 'Fetch failed: '.$e->getMessage(), $now->modify('+'.self::FAILURE_BACKOFF.' seconds'));
        }

        $with = $before->withRateLimit(...$this->rateLimit($response));

        if ($response->status === 304) {
            $after = $with->withNotModified($now);
            $this->store->put($key, $after->toArray());

            return new PollResult($source->key, $after, false, true, false, 'not modified');
        }

        if ($response->status === 403 || $response->status === 429) {
            $reset = $with->rateLimitReset ?? $now->modify('+15 minutes');

            return $this->failed($source, $with, $now, "The source refused the request (HTTP {$response->status})".($with->rateLimitRemaining === 0 ? ', rate limit exhausted' : '').'.', $reset);
        }

        if ($response->status < 200 || $response->status >= 300) {
            return $this->failed($source, $with, $now, "The source answered HTTP {$response->status}.", $now->modify('+'.self::FAILURE_BACKOFF.' seconds'));
        }

        try {
            $alerts = $parser->parse($response->body, $response->contentType());
        } catch (MalformedPayload $e) {
            return $this->failed($source, $with, $now, $e->getMessage());
        } catch (Throwable $e) {
            return $this->failed($source, $with, $now, 'The response could not be read: '.$e->getMessage());
        }

        $after = $with->withSuccess($alerts, $now, $response->header('etag'));
        $this->store->put($key, $after->toArray());

        // What a visitor would see before and after, not what was stored.
        // A payload past its ceiling was showing nothing, and replacing it
        // with the same alerts is a change.
        $shownBefore = $before->servable($now, $source->maxAge);
        $changed = \Bpmore\Beacon\Alert\Selector::fingerprint($shownBefore) !== $after->fingerprint();

        return new PollResult($source->key, $after, $changed, true, false);
    }

    private function failed(SourceDefinition $source, Snapshot $before, DateTimeImmutable $now, string $error, ?DateTimeImmutable $backoffUntil = null): PollResult
    {
        $after = $before->withFailure($error, $now, $backoffUntil);
        $this->store->put(self::storeKey($source), $after->toArray());
        $this->log('warning', "Beacon: source `{$source->key}` failed: {$error}");

        return new PollResult($source->key, $after, false, false, false, $error);
    }

    /** @return array{0: int|null, 1: DateTimeImmutable|null} */
    private function rateLimit(Response $response): array
    {
        $remaining = $response->header('x-ratelimit-remaining');
        $reset = $response->header('x-ratelimit-reset');

        return [
            is_numeric($remaining) ? (int) $remaining : null,
            is_numeric($reset) ? new DateTimeImmutable('@'.(int) $reset) : null,
        ];
    }

    private function log(string $level, string $message): void
    {
        if ($this->log !== null) {
            ($this->log)($level, $message);
        }
    }
}
