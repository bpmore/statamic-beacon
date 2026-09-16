<?php

declare(strict_types=1);

use Bpmore\Beacon\Alert\Selector;

/**
 * Brief test 2, the core half. The invalidation firing at the boundary is
 * a feature test; here it is the selection and the boundary detection.
 */
it('does not render an alert whose end has passed', function () {
    $now = new DateTimeImmutable('2026-03-01T12:00:00Z');
    $alerts = [alert(['ends_at' => '2026-03-01T11:59:59Z'])];

    expect(Selector::active($alerts, $now, 'campus'))->toBe([]);
});

it('does not render an alert that starts in two hours, and does once it has started', function () {
    $alerts = [alert(['starts_at' => '2026-03-01T14:00:00Z'])];

    expect(Selector::active($alerts, new DateTimeImmutable('2026-03-01T12:00:00Z'), 'campus'))->toBe([])
        ->and(Selector::active($alerts, new DateTimeImmutable('2026-03-01T14:00:00Z'), 'campus'))->toHaveCount(1)
        ->and(Selector::active($alerts, new DateTimeImmutable('2026-03-01T16:00:00Z'), 'campus'))->toHaveCount(1);
});

it('treats the end instant itself as ended', function () {
    $alerts = [alert(['ends_at' => '2026-03-01T12:00:00Z'])];

    expect(Selector::active($alerts, new DateTimeImmutable('2026-03-01T12:00:00Z'), 'campus'))->toBe([]);
});

it('finds the alerts whose start or end fell inside a window', function () {
    $alerts = [
        alert(['id' => 'starts-inside', 'starts_at' => '2026-03-01T12:00:30Z']),
        alert(['id' => 'ends-inside', 'ends_at' => '2026-03-01T12:00:10Z']),
        alert(['id' => 'starts-later', 'starts_at' => '2026-03-01T12:05:00Z']),
        alert(['id' => 'ended-earlier', 'ends_at' => '2026-03-01T11:00:00Z']),
        alert(['id' => 'open']),
    ];

    $since = new DateTimeImmutable('2026-03-01T12:00:00Z');
    $now = new DateTimeImmutable('2026-03-01T12:01:00Z');

    expect(array_map(fn ($a) => $a->id, Selector::crossingBoundary($alerts, $since, $now)))
        ->toBe(['starts-inside', 'ends-inside']);
});

it('sorts the most severe first and keeps source order within a severity', function () {
    $now = new DateTimeImmutable('2026-03-01T12:00:00Z');
    $alerts = [
        alert(['id' => 'i1', 'severity' => 'info']),
        alert(['id' => 'w1', 'severity' => 'warning']),
        alert(['id' => 'e1', 'severity' => 'emergency']),
        alert(['id' => 'w2', 'severity' => 'warning']),
    ];

    expect(array_map(fn ($a) => $a->id, Selector::active($alerts, $now, 'campus')))->toBe(['e1', 'w1', 'w2', 'i1']);
});
