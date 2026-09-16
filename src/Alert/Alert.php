<?php

declare(strict_types=1);

namespace Bpmore\Beacon\Alert;

use DateTimeImmutable;
use DateTimeInterface;

/**
 * One alert, wherever it came from.
 *
 * Everything downstream of a source works only with this: the selector, the
 * renderer, the fingerprint, the snapshot store. No field says which source
 * produced it, so nothing downstream can behave differently by source.
 *
 * `body` and `teaser` are HTML that has already been made safe to print:
 * sanitized for a remote source, trusted as authored for the local one. The
 * title is plain text and is escaped when rendered.
 */
final class Alert
{
    /**
     * @param  list<string>  $audiences  empty means every audience
     */
    public function __construct(
        public readonly string $id,
        public readonly string $title,
        public readonly string $body,
        public readonly ?string $teaser,
        public readonly Severity $severity,
        public readonly array $audiences,
        public readonly ?DateTimeImmutable $startsAt,
        public readonly ?DateTimeImmutable $endsAt,
        public readonly ?string $url,
        public readonly bool $dismissible,
    ) {}

    /**
     * A copy with some fields changed. Only what the chain and preview need.
     *
     * @param  list<string>|null  $audiences
     */
    public function with(?Severity $severity = null, ?array $audiences = null, ?bool $dismissible = null): self
    {
        return new self(
            $this->id,
            $this->title,
            $this->body,
            $this->teaser,
            $severity ?? $this->severity,
            $audiences ?? $this->audiences,
            $this->startsAt,
            $this->endsAt,
            $this->url,
            $dismissible ?? $this->dismissible,
        );
    }

    /** What the banner shows: the teaser when there is one. */
    public function visibleBody(): string
    {
        return $this->teaser ?? $this->body;
    }

    /** Whether the banner links onward: a teaser and somewhere to go. */
    public function hasMoreLink(): bool
    {
        return $this->teaser !== null && $this->url !== null && $this->url !== '';
    }

    public function isActiveAt(DateTimeInterface $now): bool
    {
        if ($this->startsAt !== null && $this->startsAt > $now) {
            return false;
        }

        if ($this->endsAt !== null && $this->endsAt <= $now) {
            return false;
        }

        return true;
    }

    /**
     * A digest of what a visitor would see. Timestamps a feed adds about
     * itself (cache age, fetch time) are not part of an alert and never
     * reach here, so two fetches that differ only in those agree.
     */
    public function fingerprint(): string
    {
        $audiences = $this->audiences;
        sort($audiences);

        return hash('sha256', json_encode([
            $this->id,
            $this->title,
            $this->body,
            $this->teaser,
            $this->severity->value,
            $audiences,
            $this->startsAt?->format(DATE_ATOM),
            $this->endsAt?->format(DATE_ATOM),
            $this->url,
            $this->dismissible,
        ], JSON_THROW_ON_ERROR));
    }

    /** The key a browser remembers a dismissal under. Changes when the alert does. */
    public function dismissalKey(): string
    {
        return 'beacon:'.$this->id.':'.substr($this->fingerprint(), 0, 16);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'body' => $this->body,
            'teaser' => $this->teaser,
            'severity' => $this->severity->value,
            'audiences' => $this->audiences,
            'starts_at' => $this->startsAt?->format(DATE_ATOM),
            'ends_at' => $this->endsAt?->format(DATE_ATOM),
            'url' => $this->url,
            'dismissible' => $this->dismissible,
        ];
    }

    /** @param  array<string, mixed>  $data */
    public static function fromArray(array $data): self
    {
        return new self(
            (string) $data['id'],
            (string) $data['title'],
            (string) $data['body'],
            isset($data['teaser']) ? (string) $data['teaser'] : null,
            Severity::from((string) $data['severity']),
            array_values(array_map('strval', (array) ($data['audiences'] ?? []))),
            isset($data['starts_at']) ? new DateTimeImmutable((string) $data['starts_at']) : null,
            isset($data['ends_at']) ? new DateTimeImmutable((string) $data['ends_at']) : null,
            isset($data['url']) ? (string) $data['url'] : null,
            (bool) ($data['dismissible'] ?? true),
        );
    }
}
