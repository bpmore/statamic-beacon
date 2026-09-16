<?php

declare(strict_types=1);

use Statamic\Facades\Entry;

beforeEach(function () {
    alertsCollection();
    bannerPage();
});

it('renders a published local alert first in the body, before the skip link, with no dismiss control', function () {
    localAlert(['title' => 'Boil water notice', 'severity' => 'emergency']);

    $page = $this->get('/home')->assertOk()->getContent();
    $html = markupOnly($page);

    $banner = strpos($html, 'role="region"');
    $skip = strpos($html, 'class="skip"');

    expect($banner)->not->toBeFalse()
        ->and($skip)->not->toBeFalse()
        ->and($banner < $skip)->toBeTrue('the banner must come before the skip link so a keyboard user reaches it first')
        ->and($html)->toContain('Boil water notice')
        ->and($html)->toContain('<p>Campus is <strong>closed</strong> today.</p>')
        ->and($html)->toContain('data-beacon-dismissible="0"')
        ->and(str_contains($html, '<button'))->toBeFalse('no dismiss control in server output')
        ->and(str_contains($html, 'aria-live'))->toBeFalse()
        ->and(str_contains($html, 'role="alert"'))->toBeFalse()
        ->and(substr_count($html, '<h1'))->toBe(1)
        ->and($html)->toContain('<h2 class="beacon__title"')
        ->and($page)->toContain('<style>')
        ->and($page)->toContain('<script data-beacon-dismiss="Dismiss">');
});

it('renders nothing when there are no alerts', function () {
    $html = $this->get('/home')->assertOk()->getContent();

    expect($html)->not->toContain('beacon')
        ->and($html)->toContain('class="skip"');
});

it('does not render a draft, an expired alert, or one that has not started', function () {
    localAlert(['title' => 'Draft'], published: false);
    localAlert(['title' => 'Expired', 'ends_at' => now()->subHour()->format('Y-m-d H:i')]);
    localAlert(['title' => 'Future', 'starts_at' => now()->addHours(2)->format('Y-m-d H:i')]);
    localAlert(['title' => 'Live']);

    $html = $this->get('/home')->assertOk()->getContent();

    expect($html)->toContain('Live')
        ->and($html)->not->toContain('Draft')
        ->and($html)->not->toContain('Expired')
        ->and($html)->not->toContain('Future');
});

it('shows only alerts for this site\'s configured audience', function () {
    config()->set('statamic-beacon.audience', 'clinic');

    localAlert(['title' => 'Everyone']);
    localAlert(['title' => 'Health only', 'audiences' => ['clinic']]);
    localAlert(['title' => 'Campus only', 'audiences' => ['campus']]);
    localAlert(['title' => 'Near miss', 'audiences' => ['clinic-news']]);

    $html = $this->get('/home')->assertOk()->getContent();

    expect($html)->toContain('Everyone')
        ->and($html)->toContain('Health only')
        ->and($html)->not->toContain('Campus only')
        ->and($html)->not->toContain('Near miss');
});

it('renders three simultaneous alerts across two audiences, most severe first', function () {
    config()->set('statamic-beacon.audience', 'campus');

    localAlert(['title' => 'Info one', 'severity' => 'info', 'audiences' => ['campus']]);
    localAlert(['title' => 'Emergency one', 'severity' => 'emergency']);
    localAlert(['title' => 'Warning one', 'severity' => 'warning', 'audiences' => ['campus', 'clinic']]);
    localAlert(['title' => 'Elsewhere', 'severity' => 'emergency', 'audiences' => ['clinic']]);

    $html = $this->get('/home')->assertOk()->getContent();

    expect(substr_count($html, 'role="region"'))->toBe(3)
        ->and($html)->not->toContain('Elsewhere')
        ->and(strpos($html, 'Emergency one') < strpos($html, 'Warning one'))->toBeTrue()
        ->and(strpos($html, 'Warning one') < strpos($html, 'Info one'))->toBeTrue();
});

it('renders a teaser with a more link whose accessible name includes the title', function () {
    localAlert([
        'title' => 'Parking closure',
        'teaser' => '<p>Lot B is closed.</p>',
        'link' => 'https://example.org/parking',
    ]);

    $html = $this->get('/home')->assertOk()->getContent();

    expect($html)->toContain('<div class="beacon__body"><p>Lot B is closed.</p></div>')
        ->and($html)->toContain('href="https://example.org/parking">Read more<span class="beacon__sr"> about Parking closure</span></a>')
        ->and($html)->not->toContain('Campus is');
});

it('uses the configured heading level and compatibility class names', function () {
    config()->set('statamic-beacon.render.heading_level', 'h3');
    config()->set('statamic-beacon.render.compat', true);
    localAlert();

    $html = $this->get('/home')->assertOk()->getContent();

    expect($html)->toContain('<h3 class="beacon__title"')
        ->and($html)->toContain('id="legacy-alert-message"')
        ->and($html)->toContain('legacy-alert-alert');
});

it('maps a multisite handle to an audience by exact lookup', function () {
    config()->set('statamic-beacon.audience', 'campus');
    config()->set('statamic-beacon.site_audiences', ['default' => 'clinic']);

    localAlert(['title' => 'Health only', 'audiences' => ['clinic']]);
    localAlert(['title' => 'Campus only', 'audiences' => ['campus']]);

    $html = $this->get('/home')->assertOk()->getContent();

    expect($html)->toContain('Health only')->and($html)->not->toContain('Campus only');
});

it('offers the active alerts as data', function () {
    localAlert(['title' => 'Data']);

    $this->viewShouldReturnRaw('page', '{{ beacon:alerts }}[{{ title }}|{{ severity }}]{{ /beacon:alerts }}');

    expect($this->get('/home')->assertOk()->getContent())->toContain('[Data|warning]');
});

it('emits linked assets or none when configured', function () {
    localAlert();

    config()->set('statamic-beacon.assets', 'linked');
    $linked = $this->get('/home')->getContent();
    expect($linked)->toContain('<link rel="stylesheet" href="/vendor/statamic-beacon/dist/beacon.css">')
        ->and($linked)->toContain('src="/vendor/statamic-beacon/dist/beacon.js"');

    config()->set('statamic-beacon.assets', 'none');
    $none = $this->get('/home')->getContent();
    expect($none)->not->toContain('<style>')->and($none)->not->toContain('<script');
});
