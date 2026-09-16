<?php

declare(strict_types=1);

use Bpmore\Beacon\Alert\Clock;
use Bpmore\Beacon\Alert\FrozenClock;
use Illuminate\Support\Facades\Http;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Collection;

it('creates the alerts collection and blueprint on install, and does not overwrite without --force', function () {
    expect(Collection::findByHandle('alerts'))->toBeNull();

    $this->artisan('beacon:install')->assertExitCode(0);

    expect(Collection::findByHandle('alerts'))->not->toBeNull()
        ->and(Blueprint::find('collections.alerts.alert'))->not->toBeNull()
        ->and(Blueprint::find('collections.alerts.alert')->hasField('severity'))->toBeTrue()
        ->and(Blueprint::find('collections.alerts.alert')->hasField('link'))->toBeTrue();

    $this->artisan('beacon:install')
        ->expectsOutputToContain('already exists')
        ->assertExitCode(0);
});

it('reports status as clean JSON with exit 0 when there is nothing remote', function () {
    $this->artisan('beacon:status --json')->assertExitCode(0);

    $out = json_decode(runCommand('beacon:status --json'), true, 512, JSON_THROW_ON_ERROR);

    expect($out['healthy'])->toBeTrue()
        ->and($out['freshness_budget_seconds'])->toBeNull()
        ->and($out['exit'])->toBe(0);
});

it('exits 1 when a remote source has never been fetched or the scheduler has not run', function () {
    config()->set('statamic-beacon.sources', [['driver' => 'http', 'key' => 'wordpress', 'url' => 'https://feed.example/x', 'poll' => 300]]);

    $out = json_decode(runCommand('beacon:status --json'), true, 512, JSON_THROW_ON_ERROR);

    expect($out['exit'])->toBe(1)
        ->and($out['sources'][0]['never_fetched'])->toBeTrue()
        ->and($out['scheduler']['late'])->toBeTrue()
        ->and($out['freshness_budget_seconds'])->toBe(360);
});

it('fetches now, then reports healthy, then stale after the ceiling', function () {
    config()->set('statamic-beacon.sources', [['driver' => 'http', 'key' => 'wordpress', 'url' => 'https://feed.example/x', 'poll' => 300, 'max_age' => 3600]]);
    $clock = FrozenClock::at('2026-03-01T09:00:00Z');
    app()->instance(Clock::class, $clock);
    Http::fake(['feed.example/*' => Http::response(wpFeed([wpPost(['alert-campus'])]))]);

    $fetch = json_decode(runCommand('beacon:fetch --json'), true, 512, JSON_THROW_ON_ERROR);
    expect($fetch['sources'][0]['succeeded'])->toBeTrue()
        ->and($fetch['sources'][0]['alerts'])->toBe(1)
        ->and($fetch['flushed'])->toBeTrue();

    $this->artisan('beacon:tick')->assertExitCode(0);

    $status = json_decode(runCommand('beacon:status --json'), true, 512, JSON_THROW_ON_ERROR);
    expect($status['exit'])->toBe(0)
        ->and($status['sources'][0]['ok'])->toBeTrue();

    $clock->set(new DateTimeImmutable('2026-03-01T10:00:01Z'));
    $status = json_decode(runCommand('beacon:status --json'), true, 512, JSON_THROW_ON_ERROR);
    expect($status['exit'])->toBe(1)
        ->and($status['sources'][0]['stale'])->toBeTrue();
});

it('exits 1 under --strict when the last attempt failed even though the payload is retained', function () {
    config()->set('statamic-beacon.sources', [['driver' => 'http', 'key' => 'wordpress', 'url' => 'https://feed.example/x', 'poll' => 300]]);
    $clock = FrozenClock::at('2026-03-01T09:00:00Z');
    app()->instance(Clock::class, $clock);
    Http::fakeSequence()->push(wpFeed([wpPost(['alert-campus'])]))->push('boom', 500);

    runCommand('beacon:fetch --json');
    $clock->set(new DateTimeImmutable('2026-03-01T09:06:00Z'));
    runCommand('beacon:tick');

    $lenient = json_decode(runCommand('beacon:status --json'), true, 512, JSON_THROW_ON_ERROR);
    $strict = json_decode(runCommand('beacon:status --json --strict'), true, 512, JSON_THROW_ON_ERROR);

    expect($lenient['exit'])->toBe(0)
        ->and($lenient['sources'][0]['last_error'])->toContain('500')
        ->and($lenient['sources'][0]['alerts'])->toBe(1)
        ->and($strict['exit'])->toBe(1);
});

it('registers the tick on the scheduler every minute', function () {
    $events = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())
        ->filter(fn ($e) => str_contains($e->command ?? '', 'beacon:tick'));

    expect($events)->toHaveCount(1)
        ->and($events->first()->expression)->toBe('* * * * *');
})->skip(fn () => ! app()->runningInConsole(), 'the schedule is registered in console only');

/** Run a command and return only what it wrote to stdout. */
function runCommand(string $command): string
{
    $output = new \Symfony\Component\Console\Output\BufferedOutput;
    \Illuminate\Support\Facades\Artisan::call($command, [], $output);

    return $output->fetch();
}
