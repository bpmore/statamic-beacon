<?php

declare(strict_types=1);

namespace Bpmore\Beacon\Fetch;

use Bpmore\Beacon\Alert\Alert;
use Bpmore\Beacon\Alert\Selector;
use DateTimeImmutable;
use DateTimeInterface;

/**
 * What one source last said, and how the saying went.
 *
 * `alerts` and `fetchedAt` change only on a successful fetch. Everything
 * else is bookkeeping for the next poll and for the control panel: the last
 * attempt, the last error, the validator for a conditional request, the
 * rate-limit reading, and when polling may resume after backing off.
 */
final class Snapshot
{
    /** @param  list<Alert>  $alerts */
    public function __construct(
        public readonly array $alerts = [],
        public readonly ?DateTimeImmutable $fetchedAt = null,
        public readonly ?DateTimeImmutable $attemptedAt = null,
        public readonly ?string $lastError = null,
        public readonly ?DateTimeImmutable $lastErrorAt = null,
        public readonly ?string $etag = null,
        public readonly ?int $rateLimitRemaining = null,
        public readonly ?DateTimeImmutable $rateLimitReset = null,
        public readonly ?DateTimeImmutable $backoffUntil = null,
    ) {}

    public function fingerprint(): string
    {
        return Selector::fingerprint($this->alerts);
    }

    public function hasPayload(): bool
    {
        return $this->fetchedAt !== null;
    }

    /** Whether the payload is older than the ceiling. A snapshot with no payload is not stale, it is empty. */
    public function isStale(DateTimeInterface $now, int $maxAgeSeconds): bool
    {
        if ($this->fetchedAt === null) {
            return false;
        }

        return ($now->getTimestamp() - $this->fetchedAt->getTimestamp()) > $maxAgeSeconds;
    }

    /** The alerts, unless the payload is past the ceiling, in which case none. */
    public function servable(DateTimeInterface $now, int $maxAgeSeconds): array
    {
        return $this->isStale($now, $maxAgeSeconds) ? [] : $this->alerts;
    }

    public function isBackingOff(DateTimeInterface $now): bool
    {
        return $this->backoffUntil !== null && $this->backoffUntil > $now;
    }

    /** @param  list<Alert>  $alerts */
    public function withSuccess(array $alerts, DateTimeImmutable $at, ?string $etag): self
    {
        return new self($alerts, $at, $at, null, null, $etag, $this->rateLimitRemaining, $this->rateLimitReset, null);
    }

    /** Nothing changed since last time (304). The payload is as fresh as the confirmation. */
    public function withNotModified(DateTimeImmutable $at): self
    {
        return new self($this->alerts, $at, $at, null, null, $this->etag, $this->rateLimitRemaining, $this->rateLimitReset, null);
    }

    public function withFailure(string $error, DateTimeImmutable $at, ?DateTimeImmutable $backoffUntil = null): self
    {
        return new self($this->alerts, $this->fetchedAt, $at, $error, $at, $this->etag, $this->rateLimitRemaining, $this->rateLimitReset, $backoffUntil ?? $this->backoffUntil);
    }

    public function withRateLimit(?int $remaining, ?DateTimeImmutable $reset): self
    {
        return new self($this->alerts, $this->fetchedAt, $this->attemptedAt, $this->lastError, $this->lastErrorAt, $this->etag, $remaining, $reset, $this->backoffUntil);
    }

    public function withBackoff(?DateTimeImmutable $until, DateTimeImmutable $at, string $why): self
    {
        return new self($this->alerts, $this->fetchedAt, $at, $why, $at, $this->etag, $this->rateLimitRemaining, $this->rateLimitReset, $until);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'alerts' => array_map(fn (Alert $a) => $a->toArray(), $this->alerts),
            'fetched_at' => $this->fetchedAt?->format(DATE_ATOM),
            'attempted_at' => $this->attemptedAt?->format(DATE_ATOM),
            'last_error' => $this->lastError,
            'last_error_at' => $this->lastErrorAt?->format(DATE_ATOM),
            'etag' => $this->etag,
            'rate_limit_remaining' => $this->rateLimitRemaining,
            'rate_limit_reset' => $this->rateLimitReset?->format(DATE_ATOM),
            'backoff_until' => $this->backoffUntil?->format(DATE_ATOM),
            'fingerprint' => $this->fingerprint(),
        ];
    }

    /** @param  array<string, mixed>  $data */
    public static function fromArray(array $data): self
    {
        $date = fn ($v) => is_string($v) && $v !== '' ? new DateTimeImmutable($v) : null;

        $alerts = [];
        foreach ((array) ($data['alerts'] ?? []) as $a) {
            if (is_array($a)) {
                $alerts[] = Alert::fromArray($a);
            }
        }

        return new self(
            $alerts,
            $date($data['fetched_at'] ?? null),
            $date($data['attempted_at'] ?? null),
            isset($data['last_error']) ? (string) $data['last_error'] : null,
            $date($data['last_error_at'] ?? null),
            isset($data['etag']) ? (string) $data['etag'] : null,
            isset($data['rate_limit_remaining']) ? (int) $data['rate_limit_remaining'] : null,
            $date($data['rate_limit_reset'] ?? null),
            $date($data['backoff_until'] ?? null),
        );
    }
}
