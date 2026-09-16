<?php

declare(strict_types=1);

use Bpmore\Beacon\Source\JsonParser;
use Bpmore\Beacon\Source\ParserFactory;

/**
 * The two WordPress shapes the how-to guides document: WordPress.com's
 * public API (the same shape a JSONP proxy in front of it returns, without
 * the wrapper) and a self-hosted site's REST API v2 with `_embed`.
 */
it('reads the WordPress.com public API with the http driver defaults, no proxy needed', function () {
    $parser = new JsonParser(factory(), ParserFactory::WORDPRESS_MAP, ParserFactory::WORDPRESS_SEVERITY_MAP, ParserFactory::WORDPRESS_EMPTY_WHEN);

    // As public-api.wordpress.com/rest/v1.1/sites/{id}/posts/ returns it.
    $body = json_encode([
        'found' => 1,
        'posts' => [wpPost(['alert-clinic'], ['title' => 'Clinic hours &amp; parking'])],
        'meta' => ['links' => ['counts' => 'https://public-api.wordpress.com/...']],
    ]);

    $alerts = $parser->parse($body);

    expect($alerts)->toHaveCount(1)
        ->and($alerts[0]->title)->toBe('Clinic hours & parking')
        ->and(severityOf($alerts[0]))->toBe('warning')
        ->and($alerts[0]->audiences)->toBe(['clinic']);
});

/** The map the self-hosted guide gives, against the shape /wp-json/wp/v2/posts?_embed returns. */
const WP_V2_MAP = [
    'root' => '',
    'id' => 'id',
    'title' => 'title.rendered',
    'body' => 'content.rendered',
    'url' => 'link',
    'starts_at' => 'date_gmt',
    'audiences' => ['from' => '_embedded.wp:term.*.*.slug', 'pattern' => '^(?<severity>urgent|alert|fyi)-(?<audience>[a-z0-9]+)$'],
];

it('reads a self-hosted WordPress REST v2 response with embedded terms', function () {
    $parser = new JsonParser(factory(), WP_V2_MAP, ParserFactory::WORDPRESS_SEVERITY_MAP);

    $body = json_encode([
        [
            'id' => 9001,
            'date_gmt' => '2026-03-01T15:00:00',
            'link' => 'https://news.example.edu/2026/03/01/boil-water/',
            'title' => ['rendered' => 'Boil water notice &#8211; east campus'],
            'content' => ['rendered' => '<p>Boil it.</p><!--noteaser--><p>Details.</p>', 'protected' => false],
            '_embedded' => ['wp:term' => [
                [['taxonomy' => 'category', 'slug' => 'urgent-campus'], ['taxonomy' => 'category', 'slug' => 'news-release']],
                [['taxonomy' => 'post_tag', 'slug' => 'fyi-clinic']],
            ]],
        ],
        [
            'id' => 9002,
            'link' => 'https://news.example.edu/x/',
            'title' => ['rendered' => 'Ordinary news'],
            'content' => ['rendered' => '<p>x</p>'],
            '_embedded' => ['wp:term' => [[['taxonomy' => 'category', 'slug' => 'university']]]],
        ],
    ]);

    $alerts = $parser->parse($body);

    expect($alerts)->toHaveCount(1)
        ->and($alerts[0]->id)->toBe('9001')
        ->and($alerts[0]->title)->toBe("Boil water notice \u{2013} east campus")
        ->and(severityOf($alerts[0]))->toBe('emergency')
        ->and($alerts[0]->audiences)->toBe(['campus', 'clinic'])
        ->and($alerts[0]->teaser)->toBe('<p>Boil it.</p>')
        ->and($alerts[0]->url)->toBe('https://news.example.edu/2026/03/01/boil-water/');
});

it('treats an empty self-hosted list as no alerts', function () {
    expect((new JsonParser(factory(), WP_V2_MAP))->parse('[]'))->toBe([]);
});
