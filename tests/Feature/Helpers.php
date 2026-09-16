<?php

declare(strict_types=1);

use Statamic\Facades\Blueprint;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\Role;
use Statamic\Facades\User;
use Statamic\Facades\YAML;

/** The alerts collection with the addon's own blueprint, as the install command makes it. */
function alertsCollection(string $handle = 'alerts'): void
{
    if (Collection::findByHandle($handle) === null) {
        Collection::make($handle)->title('Alerts')->save();
    }

    Blueprint::make('alert')
        ->setNamespace("collections.{$handle}")
        ->setContents(YAML::file(__DIR__.'/../../resources/blueprints/alert.yaml')->parse())
        ->save();
}

/** A local alert entry. Returns the entry id. */
function localAlert(array $data = [], bool $published = true, string $handle = 'alerts'): string
{
    $entry = Entry::make()
        ->collection($handle)
        ->slug($data['slug'] ?? 'alert-'.uniqid())
        ->published($published)
        ->data(array_merge([
            'title' => 'Inclement Weather Update',
            'severity' => 'warning',
            'message' => '<p>Campus is <strong>closed</strong> today.</p>',
        ], $data));

    $entry->saveQuietly();

    return (string) $entry->id();
}

function superUser(): \Statamic\Contracts\Auth\User
{
    $user = User::make()->email('super@example.test')->makeSuper();
    $user->save();

    return $user;
}

/** A signed-in user with exactly one permission, or none. */
function userWith(?string $permission): \Statamic\Contracts\Auth\User
{
    $role = Role::make('tester')->title('Tester')->permissions($permission ? [$permission] : []);
    $role->save();

    $user = User::make()->email('tester@example.test')->assignRole('tester');
    $user->save();

    return $user;
}

/** A page template that prints the banner, then a skip link, then a heading. */
function bannerPage(): void
{
    Collection::make('pages')->routes('/{slug}')->template('page')->save();
    Blueprint::make('page')->setNamespace('collections.pages')->setContents(['tabs' => ['main' => ['sections' => [['fields' => [
        ['handle' => 'title', 'field' => ['type' => 'text']],
    ]]]]]])->save();

    Entry::make()->collection('pages')->slug('home')->published(true)->data(['title' => 'Home'])->saveQuietly();

    test()->withFakeViews();
    test()->viewShouldReturnRaw('layout', '<html lang="en"><body>{{ beacon }}<a href="#main" class="skip">Skip to content</a><main id="main">{{ template_content }}</main></body></html>');
    test()->viewShouldReturnRaw('page', '<h1>{{ title }}</h1>');
}

/** The Beacon page under Tools: sources, the scheduler warning, the budget. */
function beaconUtility(): string
{
    $response = test()->actingAs(superUser())->get(cp_route('utilities.index').'/beacon')->assertOk()->getContent();

    preg_match('/data-page="([^"]+)"/', $response, $m);
    $page = json_decode(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'), true);

    return (string) $page['props']['html'];
}

/**
 * Whether markup Vue will compile is well formed. A utility view becomes a
 * Vue template in the browser, where an unclosed tag is a compile error
 * that shows as a blank page and never as a server exception.
 */
function assertVueTemplateIsWellFormed(string $html): void
{
    $xml = preg_replace('/<(input|br|hr|img)([^>]*?)\s*\/?>/', '<$1$2 />', $html);
    $xml = preg_replace_callback('/<[a-z][\w-]*(\s[^<>]*)?\/?>/i', function ($m) {
        return preg_replace_callback(
            '/(\s)([a-z][\w:@.-]*)(="[^"]*")?/i',
            fn ($a) => $a[1].$a[2].($a[3] ?? '="'.$a[2].'"'),
            $m[0],
        );
    }, (string) $xml);
    $xml = html_entity_decode((string) $xml, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $xml = str_replace('&', '&amp;', $xml);

    $previous = libxml_use_internal_errors(true);
    $ok = simplexml_load_string('<root>'.$xml.'</root>') !== false;
    $errors = array_map(fn ($e) => trim($e->message).' (line '.$e->line.')', libxml_get_errors());
    libxml_clear_errors();
    libxml_use_internal_errors($previous);

    expect($ok)->toBeTrue('the template is not well formed, so Vue would not compile it: '.implode('; ', $errors));
}
