<?php

declare(strict_types=1);

/**
 * Brief test 1, the injected half, and section 7's optional fast path:
 * off by default, an endpoint on this origin that serves Beacon's own
 * store, live regions present and empty at load, and nothing cached.
 */
beforeEach(function () {
    alertsCollection();
    bannerPage();
});

it('is off by default: no live regions on the page and a 404 from the endpoint', function () {
    localAlert(['title' => 'E', 'severity' => 'emergency']);

    $html = markupOnly($this->get('/home')->assertOk()->getContent());

    expect($html)->not->toContain('data-beacon-live')
        ->and(str_contains($html, 'role="alert"'))->toBeFalse()
        ->and(str_contains($html, 'role="status"'))->toBeFalse();

    $this->get('/!/statamic-beacon/live')->assertStatus(404)->assertHeader('X-Statamic-Uncacheable', 'true');
});

it('renders both live regions empty at load when on, even with no alerts, and never as part of the landmark', function () {
    config()->set('statamic-beacon.fast_path.enabled', true);
    config()->set('statamic-beacon.fast_path.interval', 30);

    $html = markupOnly($this->get('/home')->assertOk()->getContent());

    expect($html)->toContain('data-beacon-live="/!/statamic-beacon/live"')
        ->and($html)->toContain('data-beacon-interval="30"')
        ->and($html)->toContain('<div class="beacon-live__status beacon-stack" role="status"></div>')
        ->and($html)->toContain('<div class="beacon-live__alert beacon-stack" role="alert"></div>')
        ->and(str_contains($html, 'aria-live'))->toBeFalse()
        ->and(substr_count($html, 'role="alert"'))->toBe(1)
        ->and(substr_count($html, 'role="status"'))->toBe(1);

    localAlert(['title' => 'Landmark', 'severity' => 'emergency']);
    $html = markupOnly($this->get('/home')->assertOk()->getContent());

    // The server-rendered alert is a region, before the live containers, not inside them.
    expect(strpos($html, 'role="region"'))->toBeLessThan(strpos($html, 'data-beacon-live'))
        ->and($html)->toContain('role="alert"></div>');
});

it('serves the active emergency alerts for this audience from the store, rendered, uncached', function () {
    config()->set('statamic-beacon.fast_path.enabled', true);
    config()->set('statamic-beacon.audience', 'campus');

    localAlert(['title' => 'Emergency here', 'severity' => 'emergency']);
    localAlert(['title' => 'Warning here', 'severity' => 'warning']);
    localAlert(['title' => 'Elsewhere', 'severity' => 'emergency', 'audiences' => ['clinic']]);
    localAlert(['title' => 'Draft', 'severity' => 'emergency'], published: false);
    localAlert(['title' => 'Expired', 'severity' => 'emergency', 'ends_at' => now()->subHour()->format('Y-m-d H:i')]);

    $response = $this->get('/!/statamic-beacon/live')
        ->assertOk()
        ->assertHeader('X-Statamic-Uncacheable', 'true')
        ->assertHeader('Cache-Control', 'max-age=0, no-store, private');

    $data = $response->json();

    expect($data['enabled'])->toBeTrue()
        ->and($data['severities'])->toBe(['emergency'])
        ->and($data['alerts'])->toHaveCount(1)
        ->and($data['alerts'][0]['severity'])->toBe('emergency')
        ->and($data['alerts'][0]['key'])->toStartWith('beacon:')
        ->and($data['alerts'][0]['dismissible'])->toBeFalse()
        ->and($data['alerts'][0]['html'])->toContain('<section class="beacon beacon--emergency" role="region"')
        ->and($data['alerts'][0]['html'])->toContain('Emergency here')
        ->and($data['alerts'][0]['html'])->not->toContain('beacon-stack');
});

it('carries warnings too when configured, and the script knows which region takes each', function () {
    config()->set('statamic-beacon.fast_path.enabled', true);
    config()->set('statamic-beacon.fast_path.severities', ['emergency', 'warning']);

    localAlert(['title' => 'W', 'severity' => 'warning']);
    localAlert(['title' => 'I', 'severity' => 'info']);

    $data = $this->get('/!/statamic-beacon/live')->assertOk()->json();

    expect(array_map(fn ($a) => $a['severity'], $data['alerts']))->toBe(['warning'])
        ->and($data['severities'])->toBe(['emergency', 'warning']);
});

it('serves sanitized remote content and never fetches the remote source on the request', function () {
    config()->set('statamic-beacon.fast_path.enabled', true);
    config()->set('statamic-beacon.audience', 'campus');
    config()->set('statamic-beacon.sources', [['driver' => 'http', 'key' => 'wordpress', 'url' => 'https://feed.example/alerts', 'poll' => 300]]);
    \Illuminate\Support\Facades\Http::fake(['feed.example/*' => \Illuminate\Support\Facades\Http::response(wpFeed([wpPost(['urgent-campus'], ['content' => '<p>Go <b onclick="x()">now</b><script>1</script></p>'])]))]);

    // Before any scheduled fetch: nothing, and nothing requested.
    expect($this->get('/!/statamic-beacon/live')->assertOk()->json('alerts'))->toBe([]);
    \Illuminate\Support\Facades\Http::assertNothingSent();

    app(\Bpmore\Beacon\Beacon::class)->tick();

    $data = $this->get('/!/statamic-beacon/live')->assertOk()->json();
    expect($data['alerts'])->toHaveCount(1)
        ->and($data['alerts'][0]['html'])->toContain('<p>Go <b>now</b></p>')
        ->and($data['alerts'][0]['html'])->not->toContain('script');
    \Illuminate\Support\Facades\Http::assertSentCount(1);
});

it('is not written to the static cache in half mode', function () {
    config()->set('statamic-beacon.fast_path.enabled', true);
    config()->set('statamic.static_caching.strategy', 'half');

    $first = $this->get('/!/statamic-beacon/live')->assertOk()->json('checked_at');
    localAlert(['title' => 'New', 'severity' => 'emergency']);
    $second = $this->get('/!/statamic-beacon/live')->assertOk()->json();

    expect($second['alerts'])->toHaveCount(1);
});

it('is settable from the screen', function () {
    saveSettings(['fast_path_enabled' => true, 'fast_path_interval' => 45]);

    $fast = app(\Bpmore\Beacon\Beacon::class)->fastPath();
    expect($fast['enabled'])->toBeTrue()->and($fast['interval'])->toBe(45);

    saveSettings(['fast_path_enabled' => true, 'fast_path_interval' => 5]);
    expect(app(\Bpmore\Beacon\Beacon::class)->fastPath()['interval'])->toBe(15);
});

it('does not emit live regions on a preview page', function () {
    config()->set('statamic-beacon.fast_path.enabled', true);
    localAlert(['title' => 'Draft'], published: false);

    $html = markupOnly($this->actingAs(superUser())->get('/home?beacon-preview=emergency')->assertOk()->getContent());

    expect($html)->toContain('beacon--preview')->and($html)->not->toContain('data-beacon-live');
});
