<?php

declare(strict_types=1);

use Bpmore\Beacon\Source\CapParser;
use Bpmore\Beacon\Source\MalformedPayload;
use Bpmore\Beacon\Source\Xml;

function capXml(string $status = 'Actual', string $severity = 'Severe', string $urgency = 'Immediate', string $prefix = '', string $extra = ''): string
{
    $p = $prefix;
    $ns = $prefix === '' ? 'xmlns="urn:oasis:names:tc:emergency:cap:1.2"' : 'xmlns:cap="urn:oasis:names:tc:emergency:cap:1.2"';

    return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<{$p}alert {$ns}>
  <{$p}identifier>NWS-1</{$p}identifier>
  <{$p}sender>w-nws.webmaster@noaa.gov</{$p}sender>
  <{$p}sent>2026-03-01T11:00:00-06:00</{$p}sent>
  <{$p}status>{$status}</{$p}status>
  <{$p}msgType>Alert</{$p}msgType>
  <{$p}scope>Public</{$p}scope>
  {$extra}
  <{$p}info>
    <{$p}category>Met</{$p}category>
    <{$p}event>Tornado Warning</{$p}event>
    <{$p}urgency>{$urgency}</{$p}urgency>
    <{$p}severity>{$severity}</{$p}severity>
    <{$p}certainty>Observed</{$p}certainty>
    <{$p}effective>2026-03-01T11:00:00-06:00</{$p}effective>
    <{$p}expires>2026-03-01T11:45:00-06:00</{$p}expires>
    <{$p}headline>Tornado Warning issued March 1 at 11:00AM CST</{$p}headline>
    <{$p}description>A tornado was observed 5 miles &lt; west of town.

Take cover now.</{$p}description>
    <{$p}instruction>Move to an interior room.</{$p}instruction>
    <{$p}web>http://www.weather.gov</{$p}web>
  </{$p}info>
</{$p}alert>
XML;
}

it('never renders an alert whose status is not Actual', function (string $status) {
    $alerts = (new CapParser(factory()))->parse(capXml(status: $status));

    expect($alerts)->toBe([]);
})->with(['Test', 'Exercise', 'System', 'Draft', 'test', '']);

it('renders an Actual alert', function () {
    $alerts = (new CapParser(factory()))->parse(capXml());

    expect($alerts)->toHaveCount(1)
        ->and($alerts[0]->id)->toBe('NWS-1')
        ->and($alerts[0]->title)->toBe('Tornado Warning issued March 1 at 11:00AM CST')
        ->and(severityOf($alerts[0]))->toBe('emergency')
        ->and($alerts[0]->startsAt?->format(DATE_ATOM))->toBe('2026-03-01T11:00:00-06:00')
        ->and($alerts[0]->endsAt?->format(DATE_ATOM))->toBe('2026-03-01T11:45:00-06:00')
        ->and($alerts[0]->url)->toBe('http://www.weather.gov')
        ->and($alerts[0]->dismissible)->toBeFalse();
});

it('matches elements by local name so the cap: prefix is optional', function () {
    $prefixed = (new CapParser(factory()))->parse(capXml(prefix: 'cap:'));
    $bare = (new CapParser(factory()))->parse(capXml());

    expect($prefixed)->toHaveCount(1)
        ->and($prefixed[0]->fingerprint())->toBe($bare[0]->fingerprint());
});

it('escapes the plain-text body and turns newlines into paragraphs and breaks', function () {
    $alerts = (new CapParser(factory()))->parse(capXml());

    expect($alerts[0]->body)->toContain('5 miles &lt; west of town.</p>')
        ->and($alerts[0]->body)->toContain('<p>Take cover now.</p>')
        ->and($alerts[0]->body)->toContain('<p>Move to an interior room.</p>');
});

it('derives severity from CAP severity and urgency through the default matrix', function (string $severity, string $urgency, string $expected) {
    $alerts = (new CapParser(factory()))->parse(capXml(severity: $severity, urgency: $urgency));

    expect(severityOf($alerts[0]))->toBe($expected);
})->with([
    ['Extreme', 'Future', 'emergency'],
    ['Severe', 'Immediate', 'emergency'],
    ['Severe', 'Expected', 'warning'],
    ['Moderate', 'Immediate', 'warning'],
    ['Minor', 'Immediate', 'info'],
    ['Unknown', 'Unknown', 'info'],
]);

it('accepts a configured matrix', function () {
    $matrix = [['severity' => ['Minor'], 'to' => 'emergency'], ['to' => 'info']];
    $alerts = (new CapParser(factory(), $matrix))->parse(capXml(severity: 'Minor'));

    expect(severityOf($alerts[0]))->toBe('emergency');
});

it('assigns every alert from the source to the configured audiences', function () {
    $alerts = (new CapParser(factory(), audiences: ['campus', 'clinic']))->parse(capXml());

    expect($alerts[0]->audiences)->toBe(['campus', 'clinic']);
});

it('reads the NWS JSON-LD FeatureCollection', function () {
    $json = json_encode(['type' => 'FeatureCollection', 'features' => [
        ['id' => 'x', 'type' => 'Feature', 'properties' => [
            'id' => 'urn:oid:1', 'status' => 'Actual', 'severity' => 'Moderate', 'urgency' => 'Expected',
            'headline' => 'Heat Advisory', 'description' => "* WHAT...Heat index 109.\n\n* WHERE...Central Arkansas.",
            'instruction' => 'Drink fluids.', 'effective' => '2026-09-15T11:16:00-05:00', 'expires' => '2026-09-16T04:00:00-05:00',
            'web' => 'http://www.weather.gov', 'references' => [],
        ]],
        ['id' => 'y', 'type' => 'Feature', 'properties' => [
            'id' => 'urn:oid:2', 'status' => 'Test', 'severity' => 'Extreme', 'urgency' => 'Immediate', 'headline' => 'TEST',
        ]],
    ]]);

    $alerts = (new CapParser(factory()))->parse($json);

    expect($alerts)->toHaveCount(1)
        ->and($alerts[0]->id)->toBe('urn:oid:1')
        ->and(severityOf($alerts[0]))->toBe('warning')
        ->and($alerts[0]->body)->toContain('<p>* WHAT...Heat index 109.</p>')
        ->and($alerts[0]->endsAt?->format(DATE_ATOM))->toBe('2026-09-16T04:00:00-05:00');
});

it('replaces referenced alerts with the update rather than stacking them', function () {
    $json = json_encode(['features' => [
        ['properties' => ['id' => 'urn:oid:2', 'status' => 'Actual', 'severity' => 'Severe', 'urgency' => 'Immediate', 'headline' => 'Update', 'description' => 'u',
            'references' => [['@id' => 'https://x', 'identifier' => 'urn:oid:1', 'sent' => '2026-03-01T00:00:00Z']]]],
        ['properties' => ['id' => 'urn:oid:1', 'status' => 'Actual', 'severity' => 'Severe', 'urgency' => 'Immediate', 'headline' => 'Original', 'description' => 'o']],
    ]]);

    $alerts = (new CapParser(factory()))->parse($json);

    expect(array_map(fn ($a) => $a->id, $alerts))->toBe(['urn:oid:2']);
});

it('replaces referenced alerts in the XML triple form too', function () {
    $update = capXml(extra: '<references>w-nws.webmaster@noaa.gov,NWS-0,2026-03-01T10:00:00-06:00</references>');
    $original = str_replace('NWS-1', 'NWS-0', capXml());
    $feed = '<feed xmlns="http://www.w3.org/2005/Atom"><entry>'.preg_replace('/<\?xml[^>]*\?>/', '', $update).'</entry><entry>'.preg_replace('/<\?xml[^>]*\?>/', '', $original).'</entry></feed>';

    $alerts = (new CapParser(factory()))->parse($feed);

    expect(array_map(fn ($a) => $a->id, $alerts))->toBe(['NWS-1']);
});

it('refuses XML with a DOCTYPE and never expands entities', function () {
    $xxe = '<?xml version="1.0"?><!DOCTYPE alert [<!ENTITY xxe SYSTEM "file:///etc/passwd">]><alert><status>Actual</status><info><headline>&xxe;</headline></info></alert>';

    expect(fn () => Xml::load($xxe))->toThrow(MalformedPayload::class);
    expect(fn () => (new CapParser(factory()))->parse($xxe))->toThrow(MalformedPayload::class);
});

it('treats malformed XML as malformed, not as empty', function () {
    (new CapParser(factory()))->parse('<alert><status>Actual</status>');
})->throws(MalformedPayload::class);

/** Geocode filtering: SAME county codes and UGC zones mapped to audiences. */
function capWithGeocodes(string $identifier, array $codes, string $status = 'Actual'): string
{
    $geo = implode('', array_map(fn ($c) => "<geocode><valueName>".(preg_match('/^\d+$/', $c) ? 'SAME' : 'UGC')."</valueName><value>{$c}</value></geocode>", $codes));

    return str_replace(['NWS-1', '<web>'], [$identifier, $geo.'<web>'], capXml(status: $status));
}

it('aims a CAP alert at the audiences of the geocodes it carries, and drops one that matches none', function () {
    $parser = new CapParser(factory(), geocodes: [
        '005119' => ['campus', 'clinic'],   // Pulaski County, SAME
        'ARZ044' => ['campus'],             // a UGC zone
        '005143' => ['north'],              // Washington County
    ]);

    $feed = '<feed xmlns="http://www.w3.org/2005/Atom">'
        .'<entry>'.preg_replace('/<\?xml[^>]*\?>/', '', capWithGeocodes('A', ['005119'])).'</entry>'
        .'<entry>'.preg_replace('/<\?xml[^>]*\?>/', '', capWithGeocodes('B', ['005143', 'ARZ044'])).'</entry>'
        .'<entry>'.preg_replace('/<\?xml[^>]*\?>/', '', capWithGeocodes('C', ['005001'])).'</entry>'
        .'<entry>'.preg_replace('/<\?xml[^>]*\?>/', '', capWithGeocodes('D', [])).'</entry>'
        .'</feed>';

    $alerts = $parser->parse($feed);
    $by = [];
    foreach ($alerts as $a) {
        $by[$a->id] = $a->audiences;
    }

    expect(array_keys($by))->toBe(['A', 'B'])
        ->and($by['A'])->toBe(['campus', 'clinic'])
        ->and($by['B'])->toBe(['north', 'campus']);
});

it('reads geocodes from the NWS JSON shape and matches them case-insensitively', function () {
    $parser = new CapParser(factory(), geocodes: ['arz044' => ['campus'], '005119' => ['clinic']]);

    $json = json_encode(['features' => [
        ['properties' => ['id' => 'x', 'status' => 'Actual', 'severity' => 'Moderate', 'urgency' => 'Expected', 'headline' => 'Heat', 'description' => 'd',
            'geocode' => ['SAME' => ['005119', '005045'], 'UGC' => ['ARZ044']]]],
        ['properties' => ['id' => 'y', 'status' => 'Actual', 'severity' => 'Moderate', 'urgency' => 'Expected', 'headline' => 'Elsewhere', 'description' => 'd',
            'geocode' => ['SAME' => ['005001']]]],
    ]]);

    $alerts = $parser->parse($json);

    expect($alerts)->toHaveCount(1)
        ->and($alerts[0]->id)->toBe('x')
        ->and($alerts[0]->audiences)->toBe(['clinic', 'campus']);
});

it('falls back to the source-wide audiences when no geocode map is given', function () {
    $alerts = (new CapParser(factory(), audiences: ['campus']))->parse(capWithGeocodes('A', ['005001']));

    expect($alerts)->toHaveCount(1)->and($alerts[0]->audiences)->toBe(['campus']);
});

it('accepts the geocode map in either config shape', function () {
    expect(\Bpmore\Beacon\Source\ParserFactory::geocodes(['005119' => ['campus', 'clinic'], 'ARZ044' => 'campus, north']))
        ->toBe(['005119' => ['campus', 'clinic'], 'ARZ044' => ['campus', 'north']])
        ->and(\Bpmore\Beacon\Source\ParserFactory::geocodes([['code' => '005119', 'audiences' => 'campus clinic'], ['code' => '', 'audiences' => 'x'], ['code' => 'ARZ044', 'audiences' => '']]))
        ->toBe(['005119' => ['campus', 'clinic']])
        ->and(\Bpmore\Beacon\Source\ParserFactory::geocodes(null))->toBe([]);
});
