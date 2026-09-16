<?php

declare(strict_types=1);

use Bpmore\Beacon\Alert\Severity;
use Bpmore\Beacon\Mapping\PatternMap;
use Bpmore\Beacon\Source\JsonParser;
use Bpmore\Beacon\Source\ParserFactory;

/**
 * Brief test 3. The reference client let the last category iterated win,
 * which is insertion order, so `fyi-campus` after `urgent-campus` rendered as
 * an FYI. Both orderings are asserted because the bug is order dependent.
 */
it('renders an alert tagged both fyi and urgent as emergency, in either order', function (array $slugs) {
    $parser = new JsonParser(factory(), ParserFactory::WORDPRESS_MAP, ParserFactory::WORDPRESS_SEVERITY_MAP, ParserFactory::WORDPRESS_EMPTY_WHEN);

    $alerts = $parser->parse(wpFeed([wpPost($slugs)]));

    expect($alerts)->toHaveCount(1)
        ->and(severityOf($alerts[0]))->toBe('emergency')
        ->and($alerts[0]->audiences)->toBe(['campus']);
})->with([
    'fyi first' => [['fyi-campus', 'urgent-campus']],
    'urgent first' => [['urgent-campus', 'fyi-campus']],
    'warning between' => [['fyi-campus', 'alert-campus', 'urgent-campus']],
    'warning last' => [['urgent-campus', 'fyi-campus', 'alert-campus']],
]);

it('orders emergency above warning above info', function () {
    expect(Severity::Emergency->outranks(Severity::Warning))->toBeTrue()
        ->and(Severity::Warning->outranks(Severity::Info))->toBeTrue()
        ->and(Severity::Info->outranks(Severity::Emergency))->toBeFalse()
        ->and(Severity::highest([Severity::Info, Severity::Emergency, Severity::Warning]))->toBe(Severity::Emergency)
        ->and(Severity::highest([]))->toBeNull();
});

it('takes the highest severity out of a set of pattern terms regardless of order', function () {
    $map = new PatternMap('^(?<severity>urgent|alert|fyi)-(?<audience>[a-z]+)$', ParserFactory::WORDPRESS_SEVERITY_MAP);

    expect($map->extract(['fyi-campus', 'urgent-clinic'])['severity'])->toBe(Severity::Emergency)
        ->and($map->extract(['urgent-clinic', 'fyi-campus'])['severity'])->toBe(Severity::Emergency)
        ->and($map->extract(['bug', 'general-information'])['severity'])->toBeNull();
});

it('caps a severity at a ceiling', function () {
    expect(Severity::Emergency->cappedAt(Severity::Warning))->toBe(Severity::Warning)
        ->and(Severity::Info->cappedAt(Severity::Warning))->toBe(Severity::Info);
});
