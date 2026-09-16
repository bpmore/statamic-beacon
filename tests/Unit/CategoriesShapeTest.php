<?php

declare(strict_types=1);

use Bpmore\Beacon\Mapping\PathReader;
use Bpmore\Beacon\Source\JsonParser;
use Bpmore\Beacon\Source\ParserFactory;

/**
 * Brief test 5. The reference feed's `categories` is an object keyed by
 * category name. A mapper that assumes a list finds nothing, silently.
 */
it('extracts audiences from a categories object, a list, and an empty object without special casing', function (mixed $categories, array $expectedAudiences, ?string $expectedSeverity) {
    $parser = new JsonParser(factory(), ParserFactory::WORDPRESS_MAP, ParserFactory::WORDPRESS_SEVERITY_MAP, ParserFactory::WORDPRESS_EMPTY_WHEN);

    $post = wpPost([]);
    $post['categories'] = $categories;

    $alerts = $parser->parse(wpFeed([$post]));

    if ($expectedSeverity === null) {
        expect($alerts)->toBe([]);

        return;
    }

    expect($alerts)->toHaveCount(1)
        ->and($alerts[0]->audiences)->toBe($expectedAudiences)
        ->and(severityOf($alerts[0]))->toBe($expectedSeverity);
})->with([
    'object keyed by name' => [
        ['Urgent Clinic' => ['ID' => 1, 'slug' => 'urgent-clinic'], 'Fyi Campus' => ['ID' => 2, 'slug' => 'fyi-campus']],
        ['clinic', 'campus'],
        'emergency',
    ],
    'list' => [
        [['ID' => 1, 'slug' => 'alert-campus'], ['ID' => 2, 'slug' => 'alert-northwest']],
        ['campus', 'northwest'],
        'warning',
    ],
    'empty object' => [new stdClass, [], null],
    'empty list' => [[], [], null],
    'unmatched only' => [['General Information' => ['ID' => 3, 'slug' => 'general-information']], [], null],
]);

it('reads a wildcard path through an object and through a list alike', function () {
    expect(PathReader::all(['categories' => ['A' => ['slug' => 'x'], 'B' => ['slug' => 'y']]], 'categories.*.slug'))->toBe(['x', 'y'])
        ->and(PathReader::all(['categories' => [['slug' => 'x'], ['slug' => 'y']]], 'categories.*.slug'))->toBe(['x', 'y'])
        ->and(PathReader::all(['categories' => []], 'categories.*.slug'))->toBe([])
        ->and(PathReader::all(['categories' => 'nope'], 'categories.*.slug'))->toBe([])
        ->and(PathReader::all([], 'categories.*.slug'))->toBe([])
        ->and(PathReader::one(['a' => ['b' => 'c']], 'a.b'))->toBe('c')
        ->and(PathReader::one(['a' => ['b' => null]], 'a.b'))->toBeNull();
});

it('ignores fields the map does not name', function () {
    $parser = new JsonParser(factory(), ParserFactory::WORDPRESS_MAP, ParserFactory::WORDPRESS_SEVERITY_MAP, ParserFactory::WORDPRESS_EMPTY_WHEN);

    $post = wpPost(['urgent-campus'], ['excerpt' => '<script>alert(1)</script>', 'author' => ['name' => 'x']]);
    $alerts = $parser->parse(wpFeed([$post]));

    expect($alerts)->toHaveCount(1)
        ->and($alerts[0]->body)->not->toContain('script');
});

it('unwraps a JSONP callback as text, without executing anything', function () {
    $parser = new JsonParser(factory(), ParserFactory::WORDPRESS_MAP, ParserFactory::WORDPRESS_SEVERITY_MAP, ParserFactory::WORDPRESS_EMPTY_WHEN);

    $alerts = $parser->parse('displayAlert('.wpFeed([wpPost(['urgent-campus'])]).')');
    expect($alerts)->toHaveCount(1)->and(severityOf($alerts[0]))->toBe('emergency');

    $alerts = $parser->parse('/**/ jQuery123_cb('.wpFeed([wpPost(['fyi-campus'])]).');');
    expect($alerts)->toHaveCount(1);

    expect(JsonParser::unwrapJsonp('{"a":1}'))->toBe('{"a":1}')
        ->and(JsonParser::unwrapJsonp('alert(1); evil({"found":0})'))->toBe('alert(1); evil({"found":0})');
});
