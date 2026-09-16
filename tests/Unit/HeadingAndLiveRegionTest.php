<?php

declare(strict_types=1);

use Bpmore\Beacon\Render\Banner;
use Bpmore\Beacon\Render\Options;

/**
 * Brief tests 1 (server half), 14 (server half), 15.
 */
it('server-rendered output is a landmark, never a live region', function () {
    $html = (new Banner)->render([alert(['severity' => 'emergency']), alert(['id' => 'b', 'severity' => 'info'])]);

    expect(str_contains($html, 'aria-live'))->toBeFalse('server output must not carry aria-live')
        ->and(str_contains($html, 'role="alert"'))->toBeFalse('server output must not carry role="alert"')
        ->and(str_contains($html, 'role="status"'))->toBeFalse('server output must not carry role="status"')
        ->and($html)->toContain('role="region"')
        ->and($html)->toContain('aria-labelledby="');
});

it('renders no h1 by default and the configured level otherwise', function () {
    $default = (new Banner)->render([alert()]);
    $h3 = (new Banner(new Options(headingLevel: 'h3')))->render([alert()]);

    expect(str_contains($default, '<h1'))->toBeFalse('default output must not contain an h1')
        ->and($default)->toContain('<h2 class="beacon__title"')
        ->and($h3)->toContain('<h3 class="beacon__title"')
        ->and($h3)->not->toContain('<h2');
});

it('refuses h1 as a heading level', function () {
    new Options(headingLevel: 'h1');
})->throws(InvalidArgumentException::class);

it('renders no dismiss control on the server', function () {
    $html = (new Banner)->render([alert(['dismissible' => true])]);

    expect(str_contains($html, '<button'))->toBeFalse('the dismiss control is added by script, so no-JS visitors get a banner they cannot hide')
        ->and($html)->toContain('data-beacon-dismissible="1"')
        ->and($html)->toContain('data-beacon-key="beacon:a1:');
});

it('conveys severity as a word, not only a class', function () {
    $html = (new Banner)->render([alert(['severity' => 'emergency'])]);

    expect($html)->toContain('<p class="beacon__severity">Emergency</p>');
});

it('gives the more link an accessible name that includes the title', function () {
    $html = (new Banner)->render([alert(['teaser' => '<p>Short</p>', 'title' => 'Inclement Weather Update'])]);

    expect($html)->toContain('Read more<span class="beacon__sr"> about Inclement Weather Update</span></a>')
        ->and($html)->not->toContain('title="');
});

it('renders nothing for no alerts', function () {
    expect((new Banner)->render([]))->toBe('');
});

it('renders several alerts as separate regions with distinct heading ids', function () {
    $html = (new Banner)->render([alert(['id' => 'x']), alert(['id' => 'y']), alert(['id' => 'z'])]);

    preg_match_all('/aria-labelledby="([^"]+)"/', $html, $m);

    expect($m[1])->toHaveCount(3)
        ->and(array_unique($m[1]))->toHaveCount(3)
        ->and(substr_count($html, 'role="region"'))->toBe(3);
});

it('marks a preview visibly and makes it non-dismissible', function () {
    $html = (new Banner)->render([alert(['dismissible' => true])], preview: true);

    expect($html)->toContain('beacon--preview')
        ->and($html)->toContain('Preview. This banner is visible only to you.')
        ->and($html)->not->toContain('data-beacon-key')
        ->and($html)->not->toContain('data-beacon-dismissible');
});

it('emits the legacy structure and prefixed class names in compatibility mode', function () {
    $html = (new Banner(new Options(compat: true, compatPrefix: 'oldbanner')))->render([alert(['severity' => 'emergency', 'teaser' => '<p>s</p>'])]);

    expect($html)->toContain('id="oldbanner-message"')
        ->and($html)->toContain('oldbanner-urgent')
        ->and($html)->toContain('oldbanner-module')
        ->and($html)->toContain('cta-bar cta-bar-sm')
        ->and($html)->toContain('class="text-container"')
        ->and($html)->toContain('class="btn-container"')
        ->and($html)->toContain('<h2 class="beacon__title"')
        ->and(str_contains($html, '<h1'))->toBeFalse()
        ->and($html)->toContain('role="region"');
});
