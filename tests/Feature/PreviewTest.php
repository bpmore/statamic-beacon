<?php

declare(strict_types=1);

use Bpmore\Beacon\Beacon;

/**
 * Brief test 12. The reference test mode was open to anyone with the URL
 * and wrote the URL value into a class attribute unescaped.
 */
beforeEach(function () {
    alertsCollection();
    bannerPage();
    localAlert(['title' => 'Draft for review', 'severity' => 'info'], published: false);
});

it('renders nothing for an anonymous visitor asking for a preview', function () {
    $html = $this->get('/home?beacon-preview=emergency')->assertOk()->getContent();

    expect($html)->not->toContain('Draft for review')
        ->and($html)->not->toContain('beacon--preview');
});

it('renders nothing for a signed-in user without the permission', function () {
    $html = $this->actingAs(userWith(null))->get('/home?beacon-preview=emergency')->assertOk()->getContent();

    expect($html)->not->toContain('Draft for review');
});

it('renders the newest alert at the asked severity, marked as a preview, for a permitted user', function () {
    $html = markupOnly($this->actingAs(userWith(Beacon::PERMISSION_PREVIEW))->get('/home?beacon-preview=emergency')->assertOk()->getContent());

    expect($html)->toContain('Draft for review')
        ->and($html)->toContain('beacon--preview')
        ->and($html)->toContain('beacon--emergency')
        ->and($html)->toContain('Preview. This banner is visible only to you.')
        ->and($html)->not->toContain('data-beacon-key="');
});

it('renders a preview for a super user', function () {
    $html = $this->actingAs(superUser())->get('/home?beacon-preview=warning')->assertOk()->getContent();

    expect($html)->toContain('beacon--preview')->and($html)->toContain('beacon--warning');
});

it('ignores a value outside the severity allowlist, for everyone', function (string $value) {
    $anon = $this->get('/home?beacon-preview='.rawurlencode($value))->assertOk()->getContent();
    $user = $this->actingAs(superUser())->get('/home?beacon-preview='.rawurlencode($value))->assertOk()->getContent();

    foreach ([$anon, $user] as $html) {
        expect($html)->not->toContain('beacon--preview')
            ->and($html)->not->toContain('Draft for review')
            ->and($html)->not->toContain('<script>alert')
            ->and($html)->not->toContain('legacy-alert-');
    }
})->with(['<script>alert(1)</script>', 'legacy-alert-urgent', 'critical', 'emergency"><img src=x onerror=x>', '']);

it('marks a permitted preview response as uncacheable, and nobody else\'s', function () {
    // The parameter alone is not a cache bypass: an anonymous visitor, a
    // user without the permission, and a value outside the enum all get
    // the page as everyone else does. Anonymous goes first: actingAs()
    // keeps its user signed in for the rest of the test.
    $this->get('/home?beacon-preview=emergency')->assertOk()->assertHeaderMissing('X-Statamic-Uncacheable');
    $this->actingAs(userWith(null))->get('/home?beacon-preview=emergency')->assertOk()->assertHeaderMissing('X-Statamic-Uncacheable');
    $this->actingAs(superUser())->get('/home?beacon-preview=critical')->assertOk()->assertHeaderMissing('X-Statamic-Uncacheable');
    $this->get('/home')->assertOk()->assertHeaderMissing('X-Statamic-Uncacheable');

    $this->get('/home?beacon-preview=emergency')
        ->assertOk()
        ->assertHeader('X-Statamic-Uncacheable', 'true');
});

it('serves an anonymous visitor carrying the parameter from the static cache', function () {
    config()->set('statamic.static_caching.strategy', 'half');
    config()->set('statamic.static_caching.ignore_query_strings', true);

    $this->get('/home')->assertOk();
    $cacher = app(\Statamic\StaticCaching\Cacher::class);
    expect($cacher->hasCachedPage(\Illuminate\Http\Request::create('/home')))->toBeTrue('the plain page was cached');

    // Break the page so a fresh render would differ from the cached one.
    localAlert(['title' => 'Rendered fresh, not cached', 'severity' => 'warning']);

    $html = $this->get('/home?beacon-preview=emergency')->assertOk()->getContent();
    expect(str_contains($html, 'Rendered fresh, not cached'))->toBeFalse('the anonymous preview URL was rendered fresh instead of served from the cache');
});

it('can be switched off', function () {
    config()->set('statamic-beacon.preview.enabled', false);

    $html = $this->actingAs(superUser())->get('/home?beacon-preview=emergency')->assertOk()->getContent();

    expect($html)->not->toContain('beacon--preview');
});

it('is neither served from nor written to the static cache, with query strings ignored as Statamic defaults', function () {
    config()->set('statamic.static_caching.strategy', 'half');
    config()->set('statamic.static_caching.ignore_query_strings', true);

    // Warm the cache with the plain page.
    $plain = $this->get('/home')->assertOk()->getContent();
    expect($plain)->not->toContain('beacon--preview');

    // The preview is not answered with the cached plain page.
    $preview = $this->actingAs(superUser())->get('/home?beacon-preview=emergency')->assertOk()->getContent();
    expect($preview)->toContain('beacon--preview');

    // And the plain page is not now the preview.
    $again = $this->get('/home')->assertOk()->getContent();
    expect($again)->not->toContain('beacon--preview');

    // Nor is the preview URL itself served to the next anonymous visitor.
    auth()->logout();
    $this->app['auth']->forgetGuards();
    $this->flushSession();
    $anon = $this->get('/home?beacon-preview=emergency')->assertOk()->getContent();
    expect($anon)->not->toContain('beacon--preview');
});
