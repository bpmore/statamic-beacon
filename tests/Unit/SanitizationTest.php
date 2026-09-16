<?php

declare(strict_types=1);

use Bpmore\Beacon\Render\Banner;
use Bpmore\Beacon\Sanitize\SymfonySanitizer;
use Bpmore\Beacon\Sanitize\TitleText;
use Bpmore\Beacon\Sanitize\UrlScheme;

/**
 * Brief tests 7 and 8. Body, title and URL each rendered inert; entities in
 * a title decoded to the character.
 */
it('renders an image with an onerror handler, a tag in the title, and a javascript URL inert', function () {
    $alert = factory()->fromHtml(
        '1',
        '<b>bold</b>',
        '<p>Hi <img src=x onerror=alert(1)> there</p>',
        sev('warning'),
        [],
        null,
        null,
        'javascript:alert(1)',
    );

    expect($alert->body)->toBe('<p>Hi  there</p>')
        ->and($alert->title)->toBe('<b>bold</b>')
        ->and($alert->url)->toBe('#');

    $html = (new Banner)->render([$alert->with()]);

    expect($html)->not->toContain('onerror')
        ->and($html)->not->toContain('<img')
        ->and($html)->not->toContain('javascript:')
        ->and($html)->toContain('&lt;b&gt;bold&lt;/b&gt;')
        ->and(str_contains($html, '<b>bold</b>'))->toBeFalse('the title must show its tags literally, never render them');
});

it('decodes entities in a title to the character, not the entity and not markup', function () {
    expect(TitleText::decode('Campus&#8217;s Winter Weather Update'))->toBe("Campus\u{2019}s Winter Weather Update")
        ->and(TitleText::decode('Fish &amp; Chips'))->toBe('Fish & Chips');

    $alert = factory()->fromHtml('1', 'Campus&#8217;s Update', '', sev('info'));
    $html = (new Banner)->render([$alert]);

    expect($html)->toContain("Campus\u{2019}s Update")
        ->and($html)->not->toContain('&#8217;');
});

it('escapes a decoded title that became markup', function () {
    // `&lt;script&gt;` decodes to `<script>`; it must then be escaped on output.
    $alert = factory()->fromHtml('1', '&lt;script&gt;alert(1)&lt;/script&gt;', '', sev('info'));
    $html = (new Banner)->render([$alert]);

    expect($html)->not->toContain('<script>')
        ->and($html)->toContain('&lt;script&gt;');
});

it('drops every element the brief forbids, with its contents', function (string $element) {
    $html = (new SymfonySanitizer)->sanitize("<p>before</p><{$element} onload=\"x()\">payload</{$element}><p>after</p>");

    expect(str_contains($html, '<'.$element))->toBeFalse("<{$element}> survived the sanitizer")
        ->and(str_contains($html, 'payload'))->toBeFalse("the contents of <{$element}> survived the sanitizer")
        ->and($html)->toContain('<p>before</p>')
        ->and($html)->toContain('<p>after</p>');
})->with(['script', 'style', 'iframe', 'object', 'form', 'button', 'textarea', 'select', 'option', 'label']);

it('drops the void elements the brief forbids', function (string $element) {
    // `<input>` and `<embed>` cannot hold content, so the text after them is
    // sibling text and rightly survives; the element and its attributes do not.
    $html = (new SymfonySanitizer)->sanitize("<p>before</p><{$element} type=\"password\" src=\"x\" onload=\"x()\"><p>after</p>");

    expect(str_contains($html, '<'.$element))->toBeFalse("<{$element}> survived the sanitizer")
        ->and($html)->not->toContain('onload')
        ->and($html)->not->toContain('password')
        ->and($html)->toContain('<p>before</p>')
        ->and($html)->toContain('<p>after</p>');
})->with(['input', 'embed']);

it('drops event handler attributes and the style attribute', function () {
    $html = (new SymfonySanitizer)->sanitize('<p onclick="x()" style="position:fixed" onmouseover="y()" class="c" id="i">t</p><a href="https://a.b" onfocus="z()" style="x">l</a>');

    expect($html)->not->toContain('onclick')
        ->and($html)->not->toContain('onmouseover')
        ->and($html)->not->toContain('onfocus')
        ->and($html)->not->toContain('style=')
        ->and($html)->toContain('<a href="https://a.b">l</a>');
});

it('keeps the formatting the brief allows', function () {
    $in = '<p>A <strong>b</strong> <em>c</em> <a href="https://x.y/">d</a> <a href="/rel">e</a> <a href="#f">f</a><br>g</p><ul><li>h</li></ul><ol start="3"><li>i</li></ol>';
    $out = (new SymfonySanitizer)->sanitize($in);

    expect($out)->toContain('<strong>b</strong>')
        ->and($out)->toContain('<em>c</em>')
        ->and($out)->toContain('<a href="https://x.y/">d</a>')
        ->and($out)->toContain('<a href="/rel">e</a>')
        ->and($out)->toContain('<a href="#f">f</a>')
        ->and($out)->toContain('<ul><li>h</li></ul>')
        ->and($out)->toContain('<ol start="3"><li>i</li></ol>');
});

it('keeps the words inside a wrapper it does not allow', function () {
    $out = (new SymfonySanitizer)->sanitize('<div class="wp-block-group"><h3>Heading</h3><p>Words</p></div>');

    expect($out)->toBe('Heading<p>Words</p>');
});

it('replaces an unsafe link scheme in the body with # and reports it', function () {
    $reported = 0;
    $s = new SymfonySanitizer(function (int $n) use (&$reported) {
        $reported += $n;
    });

    $out = $s->sanitize('<a href="javascript:alert(1)">x</a> <a href="data:text/html,hi">y</a> <a href="vbscript:z">z</a> <a href="https://ok">ok</a>');

    expect($out)->toBe('<a href="#">x</a> <a href="#">y</a> <a href="#">z</a> <a href="https://ok">ok</a>')
        ->and($reported)->toBe(3);
});

it('validates the canonical URL scheme the way a browser reads it', function (?string $url, ?string $expected) {
    expect(UrlScheme::safe($url))->toBe($expected);
})->with([
    ['https://example.org/a', 'https://example.org/a'],
    ['http://example.org/a', 'http://example.org/a'],
    ['/relative/path', '/relative/path'],
    ['relative.html', 'relative.html'],
    ['#fragment', '#fragment'],
    ['//cdn.example.org/x', '//cdn.example.org/x'],
    ['?q=1', '?q=1'],
    ['javascript:alert(1)', '#'],
    ["java\tscript:alert(1)", '#'],
    ['JAVASCRIPT:alert(1)', '#'],
    [' javascript:alert(1)', '#'],
    ['data:text/html,x', '#'],
    ['vbscript:x', '#'],
    ['ftp://example.org/x', '#'],
    ['', null],
    [null, null],
]);

it('returns nothing for invalid UTF-8 rather than passing it through', function () {
    expect((new SymfonySanitizer)->sanitize("<p>\xff\xfe</p>"))->toBe('');
});
