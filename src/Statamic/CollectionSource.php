<?php

declare(strict_types=1);

namespace Bpmore\Beacon\Statamic;

use Bpmore\Beacon\Alert\Alert;
use Bpmore\Beacon\Alert\Severity;
use Bpmore\Beacon\Sanitize\UrlScheme;
use Bpmore\Beacon\Source\AlertSource;
use Carbon\CarbonInterface;
use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Support\Collection as LaravelCollection;
use Statamic\Contracts\Entries\Entry;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry as Entries;
use Statamic\Fields\Value;

/**
 * Alerts authored in this site's own collection.
 *
 * The bard fields are trusted as authored: this is the site's own content,
 * written by its own editors, and the blueprint restricts them to bold,
 * italic and links. The canonical URL still goes through the scheme check,
 * because a link fieldtype accepts anything typed into it.
 */
final class CollectionSource implements AlertSource
{
    public function __construct(private readonly string $handle) {}

    public function fetch(): array
    {
        return $this->alerts(published: true);
    }

    /** Every alert including drafts, newest first. For the preview. */
    public function fetchAll(): array
    {
        return $this->alerts(published: false);
    }

    public function exists(): bool
    {
        return Collection::findByHandle($this->handle) !== null;
    }

    /** @return list<Alert> */
    private function alerts(bool $published): array
    {
        if (! $this->exists()) {
            return [];
        }

        $query = Entries::query()->where('collection', $this->handle);

        if ($published) {
            $query->where('published', true);
        }

        /** @var LaravelCollection<int, Entry> $entries */
        $entries = $query->get();

        $alerts = [];
        foreach ($entries as $entry) {
            $alert = $this->alert($entry);
            if ($alert !== null) {
                $alerts[] = $alert;
            }
        }

        // Newest first: by start, else by last modification.
        usort($alerts, function (array $a, array $b) {
            return $b['sort'] <=> $a['sort'];
        });

        return array_map(fn (array $pair) => $pair['alert'], $alerts);
    }

    /** @return array{alert: Alert, sort: int}|null */
    private function alert(Entry $entry): ?array
    {
        $severity = Severity::tryFrom(strtolower((string) $this->raw($entry, 'severity', 'info')));
        $title = trim((string) ($entry->get('title') ?? ''));

        if ($severity === null || $title === '') {
            return null;
        }

        $startsAt = $this->date($entry->augmentedValue('starts_at'));
        $endsAt = $this->date($entry->augmentedValue('ends_at'));

        $dismissible = $entry->get('dismissible');
        if (! is_bool($dismissible)) {
            $dismissible = $severity !== Severity::Emergency;
        }

        $teaser = $this->html($entry->augmentedValue('teaser'));

        $audiences = [];
        foreach ((array) ($entry->get('audiences') ?? []) as $a) {
            if (is_scalar($a) && trim((string) $a) !== '') {
                $audiences[] = trim((string) $a);
            }
        }

        $alert = new Alert(
            (string) $entry->id(),
            $title,
            $this->html($entry->augmentedValue('message')) ?? '',
            $teaser,
            $severity,
            $audiences,
            $startsAt,
            $endsAt,
            // `link`, not `url`: an entry's augmented `url` is its own address,
            // which shadows any field of that name.
            UrlScheme::safe($this->url($entry->augmentedValue('link'))),
            $dismissible,
        );

        $modified = $entry->lastModified();

        return [
            'alert' => $alert,
            'sort' => $startsAt?->getTimestamp() ?? ($modified instanceof DateTimeInterface ? $modified->getTimestamp() : 0),
        ];
    }

    private function raw(Entry $entry, string $field, mixed $default): mixed
    {
        return $entry->get($field) ?? $default;
    }

    private function html(?Value $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $html = $value->value();

        if (is_object($html) && method_exists($html, '__toString')) {
            $html = (string) $html;
        }

        if (! is_string($html) || trim(strip_tags($html)) === '') {
            return null;
        }

        return trim($html);
    }

    private function date(?Value $value): ?DateTimeImmutable
    {
        $raw = $value?->value();

        if ($raw instanceof CarbonInterface) {
            return $raw->toDateTimeImmutable();
        }

        if ($raw instanceof DateTimeInterface) {
            return DateTimeImmutable::createFromInterface($raw);
        }

        return null;
    }

    private function url(?Value $value): ?string
    {
        $raw = $value?->value();

        if (is_object($raw) && method_exists($raw, 'url')) {
            $raw = $raw->url();
        }

        return is_string($raw) && trim($raw) !== '' ? trim($raw) : null;
    }
}
