<?php

declare(strict_types=1);

use Bpmore\Beacon\Alert\Clock;
use Bpmore\Beacon\Alert\FrozenClock;
use Illuminate\Support\Facades\Http;

it('shows the audience, the sources, and no scheduler warning when everything is local', function () {
    config()->set('statamic-beacon.audience', 'clinic');

    $html = beaconUtility();

    expect($html)->toContain('clinic')
        ->and($html)->toContain('collection-0')
        ->and($html)->not->toContain('scheduler has never run');

    assertVueTemplateIsWellFormed($html);
});

it('warns when a remote source is configured and the scheduler has never run, and stops once it has', function () {
    config()->set('statamic-beacon.sources', [['driver' => 'http', 'key' => 'wordpress', 'url' => 'https://feed.example/x', 'poll' => 300]]);
    app()->instance(Clock::class, FrozenClock::at('2026-03-01T09:00:00Z'));
    Http::fake(['feed.example/*' => Http::response(wpFeed([wpPost(['urgent-campus'])]))]);

    $before = beaconUtility();
    expect($before)->toContain('The scheduler has never run')
        ->and($before)->toContain('Never fetched')
        ->and($before)->toContain('Up to 360 seconds');

    app(\Bpmore\Beacon\Beacon::class)->tick();

    $after = beaconUtility();
    expect($after)->not->toContain('The scheduler has never run')
        ->and($after)->toContain('OK')
        ->and($after)->toContain('2026-03-01T09:00:00+00:00');

    assertVueTemplateIsWellFormed($after);
});

it('shows a source\'s last error without breaking the page on a brace', function () {
    config()->set('statamic-beacon.sources', [['driver' => 'http', 'key' => 'wordpress', 'url' => 'https://feed.example/x', 'poll' => 300]]);
    Http::fake(['feed.example/*' => Http::response('{{ not json', 200)]);

    app(\Bpmore\Beacon\Beacon::class)->tick();

    $html = beaconUtility();

    expect($html)->toContain('not JSON')
        ->and($html)->not->toContain('{{ not json');

    assertVueTemplateIsWellFormed($html);
});
