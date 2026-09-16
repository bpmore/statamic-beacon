<?php

declare(strict_types=1);

use Bpmore\Beacon\Source\JsonParser;

/**
 * The worked examples in docs/how-to/json.md, run. A guide whose example
 * does not parse is worse than no guide.
 */
it('parses the status page example from the JSON guide', function () {
    $parser = new JsonParser(factory(),
        ['root' => 'incidents', 'id' => 'id', 'title' => 'name', 'body' => 'incident_updates.0.body', 'url' => 'shortlink', 'severity' => 'impact'],
        ['critical' => 'emergency', 'major' => 'warning', 'minor' => 'info', 'none' => 'info'],
    );

    $alerts = $parser->parse('{"incidents": [{"id": "abc", "name": "Patient portal degraded", "impact": "major", "shortlink": "https://status.example.org/i/abc", "incident_updates": [{"body": "We are investigating."}]}]}');

    expect($alerts)->toHaveCount(1)
        ->and($alerts[0]->id)->toBe('abc')
        ->and($alerts[0]->title)->toBe('Patient portal degraded')
        ->and($alerts[0]->body)->toBe('We are investigating.')
        ->and(severityOf($alerts[0]))->toBe('warning')
        ->and($alerts[0]->url)->toBe('https://status.example.org/i/abc')
        ->and($alerts[0]->audiences)->toBe([]);
});

it('parses the tagged list example from the JSON guide', function () {
    $parser = new JsonParser(factory(),
        ['root' => '', 'id' => 'key', 'title' => 'headline', 'body' => 'html', 'url' => 'more',
            'audiences' => ['from' => 'tags.*', 'pattern' => '^(?<severity>urgent|alert|fyi)-(?<audience>[a-z0-9]+)$']],
        ['urgent' => 'emergency', 'alert' => 'warning', 'fyi' => 'info'],
    );

    $alerts = $parser->parse('[{"key": 12, "headline": "Lot B closed", "html": "<p>Use lot C.</p>", "tags": ["parking", "fyi-campus"], "more": "https://example.edu/parking"}, {"key": 13, "headline": "Not an alert", "html": "", "tags": ["parking"]}]');

    expect($alerts)->toHaveCount(1)
        ->and($alerts[0]->id)->toBe('12')
        ->and(severityOf($alerts[0]))->toBe('info')
        ->and($alerts[0]->audiences)->toBe(['campus']);
});

it('links only to guide files that exist', function () {
    $index = (string) file_get_contents(__DIR__.'/../../docs/how-to/README.md');
    preg_match_all('/\]\(([^)]+\.md)\)/', $index, $m);

    expect($m[1])->not->toBe([]);

    foreach ($m[1] as $link) {
        expect(is_file(__DIR__.'/../../docs/how-to/'.$link))->toBeTrue("the how-to index links to `{$link}`, which does not exist");
    }

    foreach (glob(__DIR__.'/../../docs/how-to/*.md') ?: [] as $guide) {
        preg_match_all('/\]\((\.\.\/[^)]+)\)/', (string) file_get_contents($guide), $rel);
        foreach ($rel[1] as $link) {
            expect(is_file(__DIR__.'/../../docs/how-to/'.$link))->toBeTrue(basename($guide)." links to `{$link}`, which does not exist");
        }
    }
});
