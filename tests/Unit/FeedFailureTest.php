<?php

declare(strict_types=1);

use Bpmore\Beacon\Alert\FrozenClock;
use Bpmore\Beacon\Fetch\MemoryStore;
use Bpmore\Beacon\Fetch\Poller;
use Bpmore\Beacon\Fetch\Response;
use Bpmore\Beacon\Fetch\TransportFailed;
use Bpmore\Beacon\Source\ParserFactory;
use Bpmore\Beacon\Source\RemoteSource;
use Bpmore\Beacon\Source\SourceDefinition;

/**
 * Brief tests 9, 10, 11 and 17. The remote path runs on every page of
 * every consuming site, so a broken feed must render nothing and throw
 * nothing, and a blip must not clear a live emergency.
 */
function httpSource(array $extra = []): SourceDefinition
{
    return SourceDefinition::fromArray(array_merge(['driver' => 'http', 'url' => 'https://feed.example/alerts', 'poll' => 300], $extra));
}

function livePayload(): string
{
    return wpFeed([wpPost(['urgent-campus'])]);
}

it('renders nothing and does not throw on timeout, 500, malformed JSON, or found: 0', function (mixed $answer) {
    $clock = FrozenClock::at('2026-03-01T12:00:00Z');
    $store = new MemoryStore;
    $source = httpSource();
    $poller = new Poller(transport([$answer]), $store, $clock);
    $parser = (new ParserFactory(new \Bpmore\Beacon\Sanitize\SymfonySanitizer))->for($source);

    $result = $poller->poll($source, $parser);
    $shown = (new RemoteSource($source, $poller, $clock))->fetch();

    expect($shown)->toBe([])
        ->and($result->changed)->toBeFalse();
})->with([
    'timeout' => [new TransportFailed('cURL error 28: timed out')],
    '500' => [new Response(500, [], 'Internal Server Error')],
    'malformed' => [ok('displayAlert({"found":1,')],
    'found 0' => [ok(wpFeed([], 0))],
]);

it('retains a live alert through a timeout, a 500 and malformed JSON, and clears it on found: 0', function (mixed $answer, bool $retained) {
    $clock = FrozenClock::at('2026-03-01T12:00:00Z');
    $store = new MemoryStore;
    $source = httpSource();
    $factory = new ParserFactory(new \Bpmore\Beacon\Sanitize\SymfonySanitizer);
    $parser = $factory->for($source);

    $first = (new Poller(transport([ok(livePayload())]), $store, $clock))->poll($source, $parser);
    expect($first->succeeded)->toBeTrue()->and($first->changed)->toBeTrue();

    $clock->set(new DateTimeImmutable('2026-03-01T12:05:00Z'));
    $poller = new Poller(transport([$answer]), $store, $clock);
    $second = $poller->poll($source, $parser);
    $shown = (new RemoteSource($source, $poller, $clock))->fetch();

    if ($retained) {
        expect($shown)->toHaveCount(1)
            ->and($second->succeeded)->toBeFalse()
            ->and($second->snapshot->lastError)->not->toBeNull()
            ->and($second->changed)->toBeFalse();
    } else {
        expect($shown)->toBe([])
            ->and($second->succeeded)->toBeTrue()
            ->and($second->changed)->toBeTrue();
    }
})->with([
    'timeout' => [new TransportFailed('timed out'), true],
    '500' => [new Response(500, [], 'boom'), true],
    'malformed' => [ok('{"found": 1, "posts": [{'), true],
    'found 0' => [ok(wpFeed([], 0)), false],
]);

it('treats found: 0 as empty even when posts is not empty', function () {
    $source = httpSource();
    $parser = (new ParserFactory(new \Bpmore\Beacon\Sanitize\SymfonySanitizer))->for($source);

    expect($parser->parse(wpFeed([wpPost(['urgent-campus'])], found: 0)))->toBe([]);
});

/** Brief test 10. */
it('produces the same fingerprint when only cache_age and cache_state differ, and a different one when content differs', function () {
    $clock = FrozenClock::at('2026-03-01T12:00:00Z');
    $store = new MemoryStore;
    $source = httpSource();
    $parser = (new ParserFactory(new \Bpmore\Beacon\Sanitize\SymfonySanitizer))->for($source);

    $a = wpFeed([wpPost(['urgent-campus'])], extra: ['cache_state' => 'fresh', 'cache_age' => '0 minute(s), 1 second(s) old']);
    $b = wpFeed([wpPost(['urgent-campus'])], extra: ['cache_state' => 'stale', 'cache_age' => '4 minute(s), 59 second(s) old']);
    $c = wpFeed([wpPost(['urgent-campus'], ['content' => '<p>Changed.</p>'])]);

    $poller = new Poller(transport([ok($a), ok($b), ok($c)]), $store, $clock);

    $first = $poller->poll($source, $parser);
    $clock->set(new DateTimeImmutable('2026-03-01T12:05:00Z'));
    $second = $poller->poll($source, $parser);
    $clock->set(new DateTimeImmutable('2026-03-01T12:10:00Z'));
    $third = $poller->poll($source, $parser);

    expect($first->changed)->toBeTrue()
        ->and($second->changed)->toBeFalse()
        ->and($second->snapshot->fingerprint())->toBe($first->snapshot->fingerprint())
        ->and($third->changed)->toBeTrue()
        ->and($third->snapshot->fingerprint())->not->toBe($first->snapshot->fingerprint());
});

it('reports no change across ten identical polls', function () {
    $clock = FrozenClock::at('2026-03-01T12:00:00Z');
    $store = new MemoryStore;
    $source = httpSource();
    $parser = (new ParserFactory(new \Bpmore\Beacon\Sanitize\SymfonySanitizer))->for($source);

    $script = [];
    for ($i = 0; $i < 11; $i++) {
        $script[] = ok(wpFeed([wpPost(['alert-clinic'])], extra: ['cache_age' => "{$i} minute(s) old"]));
    }
    $poller = new Poller(transport($script), $store, $clock);

    $changes = 0;
    for ($i = 0; $i < 11; $i++) {
        $clock->set((new DateTimeImmutable('2026-03-01T12:00:00Z'))->modify("+{$i} minutes"));
        $changes += $poller->poll($source, $parser)->changed ? 1 : 0;
    }

    expect($changes)->toBe(1);
});

/** Brief test 11. */
it('renders nothing once the retained payload is older than the ceiling, even with no ends_at', function () {
    $clock = FrozenClock::at('2026-03-01T12:00:00Z');
    $store = new MemoryStore;
    $source = httpSource(['max_age' => 3600]);
    $parser = (new ParserFactory(new \Bpmore\Beacon\Sanitize\SymfonySanitizer))->for($source);

    $poller = new Poller(transport([ok(livePayload())]), $store, $clock);
    $poller->poll($source, $parser);
    $remote = new RemoteSource($source, $poller, $clock);

    expect($remote->fetch())->toHaveCount(1)
        ->and($remote->fetch()[0]->endsAt)->toBeNull();

    $clock->set(new DateTimeImmutable('2026-03-01T13:00:01Z'));

    expect($remote->fetch())->toBe([]);
});

it('counts a successful fetch after a stale spell as a change', function () {
    $clock = FrozenClock::at('2026-03-01T12:00:00Z');
    $store = new MemoryStore;
    $source = httpSource(['max_age' => 3600]);
    $parser = (new ParserFactory(new \Bpmore\Beacon\Sanitize\SymfonySanitizer))->for($source);

    $poller = new Poller(transport([ok(livePayload()), ok(livePayload())]), $store, $clock);
    $poller->poll($source, $parser);

    $clock->set(new DateTimeImmutable('2026-03-01T14:00:00Z'));
    $again = $poller->poll($source, $parser);

    // The payload had aged out and was showing nothing; the same alerts
    // coming back means the page must change.
    expect($again->changed)->toBeTrue();
});

/** Brief test 17. */
it('skips the next poll when the GitHub rate limit is nearly gone, keeps the payload, and records why', function () {
    $clock = FrozenClock::at('2026-03-01T12:00:00Z');
    $store = new MemoryStore;
    $source = SourceDefinition::fromArray(['driver' => 'github', 'mode' => 'issues', 'owner' => 'o', 'repo' => 'r', 'poll' => 300]);
    $parser = (new ParserFactory(new \Bpmore\Beacon\Sanitize\SymfonySanitizer))->for($source);

    $issues = json_encode([[
        'number' => 7, 'state' => 'open', 'title' => 'Boil water notice', 'body' => 'Do not drink.',
        'html_url' => 'https://github.com/o/r/issues/7', 'created_at' => '2026-03-01T11:00:00Z',
        'labels' => [['name' => 'urgent-clinic']],
    ]]);

    $reset = (string) (new DateTimeImmutable('2026-03-01T12:40:00Z'))->getTimestamp();
    $transport = transport([
        ok($issues, ['X-RateLimit-Remaining' => '2', 'X-RateLimit-Reset' => $reset, 'ETag' => '"abc"']),
        ok($issues),
    ]);
    $poller = new Poller($transport, $store, $clock);

    $first = $poller->poll($source, $parser);
    expect($first->succeeded)->toBeTrue()
        ->and($first->snapshot->rateLimitRemaining)->toBe(2);

    $clock->set(new DateTimeImmutable('2026-03-01T12:05:00Z'));
    $second = $poller->poll($source, $parser);

    expect($second->skipped)->toBeTrue()
        ->and(count($transport->requests))->toBe(1)
        ->and($second->snapshot->alerts)->toHaveCount(1)
        ->and($second->snapshot->lastError)->toContain('rate limit')
        ->and($second->snapshot->backoffUntil?->format(DATE_ATOM))->toBe('2026-03-01T12:40:00+00:00')
        ->and((new RemoteSource($source, $poller, $clock))->fetch())->toHaveCount(1);

    // After the reset the poll goes ahead, with the ETag it kept.
    $clock->set(new DateTimeImmutable('2026-03-01T12:41:00Z'));
    $third = $poller->poll($source, $parser);
    expect($third->succeeded)->toBeTrue()
        ->and($transport->requests[1]->headers['If-None-Match'])->toBe('"abc"');
});

it('does not clear a live alert on a 403 rate-limit response', function () {
    $clock = FrozenClock::at('2026-03-01T12:00:00Z');
    $store = new MemoryStore;
    $source = SourceDefinition::fromArray(['driver' => 'github', 'mode' => 'issues', 'owner' => 'o', 'repo' => 'r']);
    $parser = (new ParserFactory(new \Bpmore\Beacon\Sanitize\SymfonySanitizer))->for($source);

    $issues = json_encode([['number' => 1, 'state' => 'open', 'title' => 'T', 'body' => 'b', 'labels' => [['name' => 'fyi-campus']]]]);
    $poller = new Poller(transport([
        ok($issues, ['X-RateLimit-Remaining' => '10']),
        new Response(403, ['X-RateLimit-Remaining' => '0', 'X-RateLimit-Reset' => (string) (new DateTimeImmutable('2026-03-01T12:30:00Z'))->getTimestamp()], '{"message":"API rate limit exceeded"}'),
    ]), $store, $clock);

    $poller->poll($source, $parser);
    $clock->set(new DateTimeImmutable('2026-03-01T12:05:00Z'));
    $result = $poller->poll($source, $parser);

    expect($result->succeeded)->toBeFalse()
        ->and($result->changed)->toBeFalse()
        ->and((new RemoteSource($source, $poller, $clock))->fetch())->toHaveCount(1)
        ->and($result->snapshot->backoffUntil?->format(DATE_ATOM))->toBe('2026-03-01T12:30:00+00:00');
});

it('treats 304 Not Modified as a fresh confirmation of the same payload', function () {
    $clock = FrozenClock::at('2026-03-01T12:00:00Z');
    $store = new MemoryStore;
    $source = httpSource(['max_age' => 3600]);
    $parser = (new ParserFactory(new \Bpmore\Beacon\Sanitize\SymfonySanitizer))->for($source);

    $poller = new Poller(transport([ok(livePayload(), ['ETag' => '"v1"']), new Response(304, [], '')]), $store, $clock);
    $poller->poll($source, $parser);

    $clock->set(new DateTimeImmutable('2026-03-01T12:55:00Z'));
    $result = $poller->poll($source, $parser);

    expect($result->succeeded)->toBeTrue()->and($result->changed)->toBeFalse()
        ->and($result->snapshot->fetchedAt?->format(DATE_ATOM))->toBe('2026-03-01T12:55:00+00:00');

    // Still served well past the original fetch, because the 304 renewed it.
    $clock->set(new DateTimeImmutable('2026-03-01T13:30:00Z'));
    expect((new RemoteSource($source, $poller, $clock))->fetch())->toHaveCount(1);
});

it('knows when a source is due', function () {
    $clock = FrozenClock::at('2026-03-01T12:00:00Z');
    $store = new MemoryStore;
    $source = httpSource(['poll' => 300]);
    $parser = (new ParserFactory(new \Bpmore\Beacon\Sanitize\SymfonySanitizer))->for($source);
    $poller = new Poller(transport([ok(livePayload())]), $store, $clock);

    expect($poller->isDue($source))->toBeTrue();
    $poller->poll($source, $parser);
    expect($poller->isDue($source))->toBeFalse();
    $clock->set(new DateTimeImmutable('2026-03-01T12:04:59Z'));
    expect($poller->isDue($source))->toBeFalse();
    $clock->set(new DateTimeImmutable('2026-03-01T12:05:00Z'));
    expect($poller->isDue($source))->toBeTrue();
});
