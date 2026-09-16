<?php

declare(strict_types=1);

use Bpmore\Beacon\Sanitize\SymfonySanitizer;
use Bpmore\Beacon\Sanitize\Teaser;

/**
 * Brief test 6. Sanitizers strip comments, and the marker is a comment.
 * Split first, or there is nothing to split on.
 */
it('splits at the marker before sanitizing, and loses the split if sanitized first', function () {
    $raw = '<p>Campus closed <b onclick="x()">today</b>.</p><!--noteaser--><p>Full details follow.</p>';
    $sanitizer = new SymfonySanitizer;

    $parts = Teaser::split($raw);
    expect($parts['teaser'])->toBe('<p>Campus closed <b onclick="x()">today</b>.</p>')
        ->and($parts['body'])->toBe('<p>Campus closed <b onclick="x()">today</b>.</p><p>Full details follow.</p>');

    $teaser = $sanitizer->sanitize((string) $parts['teaser']);
    $body = $sanitizer->sanitize($parts['body']);
    expect($teaser)->toBe('<p>Campus closed <b>today</b>.</p>')
        ->and($body)->toBe('<p>Campus closed <b>today</b>.</p><p>Full details follow.</p>');

    // Reversed order: the sanitizer removed the comment, so there is no marker.
    $sanitizedFirst = $sanitizer->sanitize($raw);
    expect($sanitizedFirst)->not->toContain('noteaser');
    expect(Teaser::split($sanitizedFirst)['teaser'])->toBeNull();
});

it('returns no teaser when there is no marker', function () {
    expect(Teaser::split('<p>Whole thing.</p>'))->toBe(['teaser' => null, 'body' => '<p>Whole thing.</p>']);
});

it('builds an alert with a teaser and a more link from the marker, and none without', function () {
    $with = factory()->fromHtml('1', 'T', '<p>Short.</p><!--noteaser--><p>Long.</p>', sev('info'), [], null, null, 'https://x.y/z');
    $without = factory()->fromHtml('2', 'T', '<p>Short.</p><p>Long.</p>', sev('info'), [], null, null, 'https://x.y/z');

    expect($with->teaser)->toBe('<p>Short.</p>')
        ->and($with->body)->toBe('<p>Short.</p><p>Long.</p>')
        ->and($with->hasMoreLink())->toBeTrue()
        ->and($with->visibleBody())->toBe('<p>Short.</p>')
        ->and($without->teaser)->toBeNull()
        ->and($without->hasMoreLink())->toBeFalse()
        ->and($without->visibleBody())->toBe('<p>Short.</p><p>Long.</p>');
});

it('uses a configurable marker', function () {
    $parts = Teaser::split('a<!--more-->b', '<!--more-->');
    expect($parts)->toBe(['teaser' => 'a', 'body' => 'ab']);
});
