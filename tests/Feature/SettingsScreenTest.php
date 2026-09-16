<?php

declare(strict_types=1);

use Bpmore\Beacon\Beacon;
use Bpmore\Beacon\Settings;
use Bpmore\Beacon\Source\ParserFactory;
use Illuminate\Support\Facades\Event;
use Statamic\Events\StaticCacheCleared;
use Statamic\Facades\Addon;

/**
 * The settings screen, for the person who runs communications and cannot
 * edit a PHP file. The form wins once saved; the file answers until then
 * and for whatever the form leaves blank.
 */

beforeEach(function () {
    \Illuminate\Support\Facades\File::delete(resource_path('addons/statamic-beacon.yaml'));
});

afterEach(function () {
    \Illuminate\Support\Facades\File::delete(resource_path('addons/statamic-beacon.yaml'));
});

it('offers a settings screen and links to it from the utility', function () {
    $addon = Addon::get(Settings::PACKAGE);

    expect($addon->hasSettingsBlueprint())->toBeTrue()
        ->and($addon->settingsUrl())->toContain('addons');

    $this->actingAs(superUser())->get($addon->settingsUrl())->assertOk();

    $html = beaconUtility();
    expect($html)->toContain('settings screen')
        ->and($html)->toContain('developer\'s config file');
});

it('shows the same defaults as the config file for every scalar it maps', function () {
    $fields = Addon::get(Settings::PACKAGE)->settingsBlueprint()->fields()->all();

    foreach (Settings::MAP as $handle => $key) {
        expect($fields->has($handle))->toBeTrue("the form has no field `{$handle}`");

        $inForce = config('statamic-beacon.'.$key);
        $default = $fields->get($handle)->defaultValue();

        expect($default)->toBe($inForce, "the form shows a different `{$handle}` from the one in force");
    }
});

it('uses the config file until the screen is saved', function () {
    expect(app(Settings::class)->saved())->toBeFalse()
        ->and(app(Settings::class)->effective())->toBe(config('statamic-beacon'));
});

it('adds a WordPress.com alert site as an http source with the API defaults from one address', function () {
    saveSettings(['sources' => [
        ['type' => 'collection', 'collection' => 'alerts'],
        ['type' => 'wordpress', 'url' => 'https://public-api.wordpress.com/rest/v1.1/sites/12345678/posts/?number=20', 'key' => 'wordpress', 'poll' => 120, 'audiences' => [], 'advanced' => false],
    ]]);

    $definitions = app(Beacon::class)->definitions();

    expect($definitions)->toHaveCount(2)
        ->and($definitions[0]->driver)->toBe('collection')
        ->and($definitions[1]->driver)->toBe('http')
        ->and($definitions[1]->key)->toBe('wordpress')
        ->and($definitions[1]->url)->toBe('https://public-api.wordpress.com/rest/v1.1/sites/12345678/posts/?number=20')
        ->and($definitions[1]->poll)->toBe(120)
        ->and($definitions[1]->option('map'))->toBe(ParserFactory::WORDPRESS_MAP)
        ->and($definitions[1]->option('severity_map'))->toBe(ParserFactory::WORDPRESS_SEVERITY_MAP)
        ->and($definitions[1]->option('empty_when'))->toBe(ParserFactory::WORDPRESS_EMPTY_WHEN);
});

it('pulls a published WordPress.com post in through the screen-configured source', function () {
    saveSettings([
        'audience' => 'campus',
        'sources' => [['type' => 'wordpress', 'url' => 'https://feed.example/alerts', 'key' => 'wordpress']],
    ]);
    alertsCollection();
    bannerPage();
    \Illuminate\Support\Facades\Http::fake(['feed.example/*' => \Illuminate\Support\Facades\Http::response('displayAlert('.wpFeed([wpPost(['urgent-campus'], ['title' => 'From the WordPress site'])]).')')]);

    app(Beacon::class)->tick();

    $html = $this->get('/home')->assertOk()->getContent();
    expect($html)->toContain('From the WordPress site')->and($html)->toContain('beacon--emergency');
});

it('keeps the file\'s sources when the screen saves an empty list', function () {
    config()->set('statamic-beacon.sources', [['driver' => 'http', 'url' => 'https://file.example/x', 'key' => 'from-file']]);

    saveSettings(['sources' => [], 'audience' => 'x']);

    $definitions = app(Beacon::class)->definitions();
    expect($definitions)->toHaveCount(1)->and($definitions[0]->key)->toBe('from-file');
});

it('switches everything off with a Nothing block', function () {
    saveSettings(['sources' => [['type' => 'off']]]);

    $definitions = app(Beacon::class)->definitions();
    expect($definitions)->toHaveCount(1)->and($definitions[0]->driver)->toBe('null');
});

it('maps every other block type to its driver', function () {
    saveSettings(['sources' => [
        ['type' => 'cap', 'url' => 'https://api.weather.gov/alerts/active?point=1,2', 'user_agent' => 'test (a@b.c)', 'max_severity' => 'warning', 'audiences' => ['campus'], 'geocodes' => [['code' => '005119', 'audiences' => 'campus, clinic']]],
        ['type' => 'github_file', 'owner' => 'o', 'repo' => 'r', 'ref' => 'main', 'path' => 'alerts.json'],
        ['type' => 'github_issues', 'owner' => 'o', 'repo' => 'r', 'label' => 'alert', 'token' => 'ghp_x'],
        ['type' => 'gist', 'url' => 'https://gist.githubusercontent.com/o/1/raw/alerts.json'],
        ['type' => 'feed', 'url' => 'https://example.org/feed.xml', 'default_severity' => 'info'],
        ['type' => 'http', 'url' => 'https://example.org/a.json', 'map_root' => 'items', 'map_title' => 'headline', 'map_body' => 'text', 'map_severity' => 'level', 'map_audiences' => 'tags.*.slug', 'pattern' => '^(?<severity>red|amber)-(?<audience>[a-z]+)$', 'severity_map' => [['from' => 'red', 'to' => 'emergency'], ['from' => 'amber', 'to' => 'warning']]],
    ]]);

    $d = app(Beacon::class)->definitions();

    expect(array_map(fn ($x) => $x->driver, $d))->toBe(['cap', 'github', 'github', 'github', 'feed', 'http'])
        ->and($d[0]->headers['User-Agent'])->toBe('test (a@b.c)')
        ->and($d[0]->maxSeverity?->value)->toBe('warning')
        ->and($d[0]->audiences)->toBe(['campus'])
        ->and($d[0]->option('geocodes'))->toBe(['005119' => ['campus', 'clinic']])
        ->and($d[1]->url)->toBe('https://raw.githubusercontent.com/o/r/main/alerts.json')
        ->and($d[2]->url)->toContain('api.github.com/repos/o/r/issues')
        ->and($d[2]->headers['Authorization'])->toBe('Bearer ghp_x')
        ->and($d[3]->url)->toBe('https://gist.githubusercontent.com/o/1/raw/alerts.json')
        ->and($d[4]->option('default_severity'))->toBe('info')
        ->and($d[5]->option('map')['root'])->toBe('items')
        ->and($d[5]->option('map')['audiences'])->toBe(['from' => 'tags.*.slug', 'pattern' => '^(?<severity>red|amber)-(?<audience>[a-z]+)$'])
        ->and($d[5]->option('severity_map'))->toBe(['red' => 'emergency', 'amber' => 'warning']);
});

it('skips a block missing what it needs rather than failing the whole list', function () {
    saveSettings(['sources' => [['type' => 'wordpress', 'url' => ''], ['type' => 'github_file', 'owner' => 'o'], ['type' => 'collection']]]);

    expect(array_map(fn ($x) => $x->driver, app(Beacon::class)->definitions()))->toBe(['collection']);
});

it('lets the screen set the audience and the site map, and lets a blank fall back to the file', function () {
    config()->set('statamic-beacon.audience', 'from-file');

    saveSettings(['audience' => 'clinic', 'site_audiences' => [['site' => ['default'], 'audience' => 'inside'], ['site' => [], 'audience' => 'x'], ['site' => ['other'], 'audience' => '']]]);
    $effective = app(Settings::class)->effective();
    expect($effective['audience'])->toBe('clinic')
        ->and($effective['site_audiences'])->toBe(['default' => 'inside'])
        ->and(app(Beacon::class)->audience())->toBe('inside');

    saveSettings(['audience' => '', 'site_audiences' => []]);
    expect(app(Settings::class)->effective()['audience'])->toBe('from-file');
});

it('lets the screen set the banner wording and heading level', function () {
    saveSettings(['heading_level' => 'h3', 'label_emergency' => 'Urgent', 'more_text' => 'Details', 'compat' => true, 'compat_prefix' => 'oldbanner', 'assets' => 'none']);
    alertsCollection();
    bannerPage();
    localAlert(['severity' => 'emergency', 'teaser' => '<p>t</p>', 'link' => 'https://x.y/']);

    $html = $this->get('/home')->assertOk()->getContent();

    expect($html)->toContain('<h3 class="beacon__title"')
        ->and($html)->toContain('Urgent')
        ->and($html)->toContain('Details<span class="beacon__sr">')
        ->and($html)->toContain('oldbanner-urgent')
        ->and($html)->not->toContain('<style>');
});

it('clears the static cache when the screen is saved', function () {
    Event::fake([StaticCacheCleared::class]);

    saveSettings(['audience' => 'campus']);

    Event::assertDispatchedTimes(StaticCacheCleared::class, 1);
});

it('says nothing a communications person would not understand', function () {
    $yaml = (string) file_get_contents(__DIR__.'/../../resources/blueprints/settings.yaml');

    foreach (['JSONP', 'regex', 'fingerprint', 'sanitiz', 'middleware', 'PSR', 'closure'] as $jargon) {
        expect(stripos($yaml, $jargon) !== false)->toBeFalse("the settings screen says `{$jargon}`");
    }
});
