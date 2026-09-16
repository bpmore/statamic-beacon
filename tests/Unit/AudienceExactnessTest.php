<?php

declare(strict_types=1);

use Bpmore\Beacon\Alert\Audience;
use Bpmore\Beacon\Alert\Selector;

/**
 * Brief test 4. The reference client matched `inside` as a substring of the
 * body class, so `category-inside-scoop` was the intranet. Every near miss
 * in the reference vocabulary is a parameter here.
 */
it('matches an audience only by the exact string', function (string $site) {
    expect(Audience::matches(['inside'], $site))->toBeFalse();
})->with([
    'inside-scoop', 'category-inside', 'insidenew', 'inside ', ' inside', 'Inside', 'INSIDE',
    'campusinside', 'tag-inside-news', 'category-inside-scoop', 'tag-clinic-news',
]);

it('matches the exact audience', function () {
    expect(Audience::matches(['inside'], 'inside'))->toBeTrue()
        ->and(Audience::matches(['campus', 'clinic'], 'clinic'))->toBeTrue()
        ->and(Audience::matches(['clinic'], 'campus'))->toBeFalse();
});

it('treats an alert with no audiences as for everyone', function () {
    expect(Audience::matches([], 'anything'))->toBeTrue();
});

it('resolves a site handle to an audience by exact lookup, else the default', function () {
    $map = ['health' => 'clinic', 'health_nicu' => 'clinic', 'intranet' => 'inside'];

    expect(Audience::forSite('health', $map, 'campus'))->toBe('clinic')
        ->and(Audience::forSite('health_literacy', $map, 'campus'))->toBe('campus')
        ->and(Audience::forSite('healt', $map, 'campus'))->toBe('campus')
        ->and(Audience::forSite(null, $map, 'campus'))->toBe('campus');
});

it('selects only alerts for this audience', function () {
    $now = new DateTimeImmutable('2026-03-01T12:00:00Z');
    $alerts = [
        alert(['id' => 'all']),
        alert(['id' => 'campus', 'audiences' => ['campus']]),
        alert(['id' => 'health', 'audiences' => ['clinic']]),
        alert(['id' => 'both', 'audiences' => ['campus', 'clinic']]),
    ];

    $ids = fn (array $list) => array_map(fn ($a) => $a->id, $list);

    expect($ids(Selector::active($alerts, $now, 'campus')))->toBe(['all', 'campus', 'both'])
        ->and($ids(Selector::active($alerts, $now, 'clinic')))->toBe(['all', 'health', 'both'])
        ->and($ids(Selector::active($alerts, $now, 'inside')))->toBe(['all']);
});
