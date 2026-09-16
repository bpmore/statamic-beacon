<?php

declare(strict_types=1);

use Bpmore\Beacon\Alert\Clock;
use Bpmore\Beacon\Alert\FrozenClock;
use Bpmore\Beacon\Beacon;
use Statamic\Events\StaticCacheCleared;
use Statamic\Facades\Entry;
use Illuminate\Support\Facades\Event;

/**
 * Brief test 2, the invalidation half, and section 7: the page cache is
 * cleared on every local change, at every scheduled start and end, and on
 * a remote change but not on a remote poll that changed nothing.
 */
beforeEach(function () {
    alertsCollection();
    config()->set('statamic.static_caching.strategy', 'half');
    Event::fake([StaticCacheCleared::class]);
});

it('clears the static cache when an alert is created, edited, unpublished, and deleted', function () {
    $entry = Entry::make()->collection('alerts')->slug('one')->published(true)->data(['title' => 'One', 'severity' => 'info', 'message' => '<p>x</p>']);
    $entry->save();
    Event::assertDispatchedTimes(StaticCacheCleared::class, 1);

    $entry->set('title', 'Edited')->save();
    Event::assertDispatchedTimes(StaticCacheCleared::class, 2);

    $entry->published(false)->save();
    Event::assertDispatchedTimes(StaticCacheCleared::class, 3);

    $entry->delete();
    Event::assertDispatchedTimes(StaticCacheCleared::class, 4);
});

it('does not clear the static cache for entries in other collections', function () {
    \Statamic\Facades\Collection::make('pages')->save();
    Entry::make()->collection('pages')->slug('x')->data(['title' => 'x'])->save();

    Event::assertNotDispatched(StaticCacheCleared::class);
});

it('clears the static cache at an alert\'s start and again at its end, and not between', function () {
    $clock = FrozenClock::at('2026-03-01T09:00:00Z');
    app()->instance(Clock::class, $clock);

    localAlert(['starts_at' => '2026-03-01 12:00', 'ends_at' => '2026-03-01 15:00']);

    $tick = fn (string $at) => tap(app(Beacon::class), fn () => $clock->set(new DateTimeImmutable($at)))->tick();

    expect($tick('2026-03-01T09:01:00Z')['flushed'])->toBeFalse()
        ->and($tick('2026-03-01T11:59:00Z')['flushed'])->toBeFalse()
        ->and($tick('2026-03-01T12:00:00Z')['flushed'])->toBeTrue()
        ->and($tick('2026-03-01T12:01:00Z')['flushed'])->toBeFalse()
        ->and($tick('2026-03-01T14:59:00Z')['flushed'])->toBeFalse()
        ->and($tick('2026-03-01T15:00:30Z')['flushed'])->toBeTrue()
        ->and($tick('2026-03-01T15:01:30Z')['flushed'])->toBeFalse();

    Event::assertDispatchedTimes(StaticCacheCleared::class, 2);
});

it('clears the static cache when a remote poll changes the banner and not when it does not', function () {
    config()->set('statamic-beacon.sources', [
        ['driver' => 'http', 'key' => 'wordpress', 'url' => 'https://feed.example/alerts', 'poll' => 300],
    ]);
    $clock = FrozenClock::at('2026-03-01T09:00:00Z');
    app()->instance(Clock::class, $clock);

    \Illuminate\Support\Facades\Http::fakeSequence()
        ->push(wpFeed([wpPost(['urgent-campus'])], extra: ['cache_age' => '1']))
        ->push(wpFeed([wpPost(['urgent-campus'])], extra: ['cache_age' => '2']))
        ->push(wpFeed([wpPost(['urgent-campus'], ['title' => 'Changed'])]))
        ->push(wpFeed([], 0));

    $tick = fn (string $at) => tap(app(Beacon::class), fn () => $clock->set(new DateTimeImmutable($at)))->tick();

    expect($tick('2026-03-01T09:00:00Z')['flushed'])->toBeTrue();
    Event::assertDispatchedTimes(StaticCacheCleared::class, 1);

    // Not due yet: no request, no flush.
    expect($tick('2026-03-01T09:02:00Z')['polled'])->toBe([]);

    expect($tick('2026-03-01T09:05:00Z')['flushed'])->toBeFalse();
    Event::assertDispatchedTimes(StaticCacheCleared::class, 1);

    expect($tick('2026-03-01T09:10:00Z')['flushed'])->toBeTrue();
    Event::assertDispatchedTimes(StaticCacheCleared::class, 2);

    expect($tick('2026-03-01T09:15:00Z')['flushed'])->toBeTrue();
    Event::assertDispatchedTimes(StaticCacheCleared::class, 3);

    \Illuminate\Support\Facades\Http::assertSentCount(4);
});

it('serves the remote alert from the stored snapshot on a page request without fetching', function () {
    config()->set('statamic-beacon.sources', [
        ['driver' => 'http', 'key' => 'wordpress', 'url' => 'https://feed.example/alerts', 'poll' => 300],
    ]);
    config()->set('statamic-beacon.audience', 'campus');
    bannerPage();

    \Illuminate\Support\Facades\Http::fake(['feed.example/*' => \Illuminate\Support\Facades\Http::response(wpFeed([wpPost(['urgent-campus'], ['title' => 'From the feed'])]))]);

    // Before any poll: nothing, and no request made by the page.
    expect($this->get('/home')->getContent())->not->toContain('From the feed');
    \Illuminate\Support\Facades\Http::assertNothingSent();

    app(Beacon::class)->tick();
    \Illuminate\Support\Facades\Http::assertSentCount(1);

    $html = $this->get('/home')->getContent();
    expect($html)->toContain('From the feed')
        ->and($html)->toContain('beacon--emergency')
        ->and($html)->toContain('<a href="https://example.edu/updates/" target="_blank" rel="noreferrer noopener">Click Here for Updates</a>');
    \Illuminate\Support\Facades\Http::assertSentCount(1);
});
