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
    $html = $this->actingAs(userWith(Beacon::PERMISSION_PREVIEW))->get('/home?beacon-preview=emergency')->assertOk()->getContent();

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

it('marks a preview response as uncacheable, whether or not it was permitted', function () {
    $this->actingAs(superUser())->get('/home?beacon-preview=emergency')
        ->assertOk()
        ->assertHeader('X-Statamic-Uncacheable', 'true');

    $this->get('/home?beacon-preview=emergency')
        ->assertOk()
        ->assertHeader('X-Statamic-Uncacheable', 'true');

    $this->get('/home')->assertOk()->assertHeaderMissing('X-Statamic-Uncacheable');
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
