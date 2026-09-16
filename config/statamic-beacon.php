<?php

return [

    /*
    |--------------------------------------------------------------------------
    | This site's audience
    |--------------------------------------------------------------------------
    |
    | An audience is a named group of consuming sites. An alert that names
    | audiences shows only on sites declared as one of them; an alert that
    | names none shows everywhere. Matching is an exact string comparison
    | against this value. Nothing is inferred from a hostname, a class name
    | or a URL, and nothing ever will be.
    |
    | `site_audiences` maps a Statamic site handle to its audience, for
    | multisite installs. A handle not listed here uses `audience`.
    |
    */
    'audience' => env('BEACON_AUDIENCE', 'default'),

    'site_audiences' => [
        // 'health' => 'clinic',
        // 'intranet' => 'inside',
    ],

    /*
    |--------------------------------------------------------------------------
    | Sources
    |--------------------------------------------------------------------------
    |
    | Where alerts come from, in order. Results are merged. Each source may
    | carry `max_severity` (the highest level it is allowed to raise) and
    | `audiences` (the only audiences its alerts may reach), so a public
    | feed cannot put up an emergency banner for everyone.
    |
    | Drivers:
    |
    |   collection  Alerts authored in this site's `alerts` collection.
    |   http        A JSON endpoint, mapped through `map`. The defaults
    |               match WordPress.com's posts API, so a WordPress.com
    |               alert site needs only `url`.
    |   feed        RSS 2.0 or Atom. Categories carry severity and audience
    |               through `pattern`.
    |   cap         Common Alerting Protocol 1.2, XML or the NWS JSON-LD.
    |   github      A file, a Gist, or labeled issues in a public repository.
    |   null        Nothing. For switching the banner off without uninstalling.
    |
    | Every remote driver takes: `url`, `method` (GET), `headers`, `timeout`
    | in seconds (5), `poll` in seconds between fetches (300), `max_age` in
    | seconds a stored payload may be shown after the last successful fetch
    | (86400), `teaser_marker` (<!--noteaser-->), `severity_map`,
    | `default_severity` (unset: an item with no severity is skipped).
    |
    | Remote sources are fetched by the scheduler, never during a page
    | request. Without `php please schedule:run` every minute they never
    | update. The README's first section has the cron line.
    |
    */
    'sources' => [
        ['driver' => 'collection'],

        // A WordPress.com alert site, read straight from WordPress.com's
        // public API so it does not depend on the institution's own web
        // server being up. The field map, severity map and emptiness rule
        // default to that API's shape, so a URL is enough. A JSONP proxy in
        // front of it, if you have one, works too.
        // ['driver' => 'http', 'key' => 'wordpress', 'url' => 'https://public-api.wordpress.com/rest/v1.1/sites/YOUR_SITE_ID/posts/?number=20', 'poll' => 300],

        // A public weather feed, capped so it can never raise an emergency
        // on its own, and limited to two audiences. NWS asks for a
        // User-Agent that identifies you.
        // ['driver' => 'cap', 'key' => 'nws',
        //  'url' => 'https://api.weather.gov/alerts/active?point=34.7465,-92.2896',
        //  'headers' => ['User-Agent' => 'beacon (you@example.org)', 'Accept' => 'application/geo+json'],
        //  'max_severity' => 'warning', 'audiences' => ['campus', 'clinic']],

        // A JSON or YAML file in a public repository: reviewed, versioned,
        // branch-protected, served from a CDN and not counted against the
        // API rate limit. The recommended remote source. A private
        // repository needs `token`, and a token expires; keep it public.
        // ['driver' => 'github', 'key' => 'repo', 'mode' => 'file',
        //  'owner' => 'your-org', 'repo' => 'alerts', 'ref' => 'main', 'path' => 'alerts.json'],

        // Open issues with a label. Labels such as `urgent-clinic`
        // carry severity and audience. 60 unauthenticated requests an hour
        // per IP address, shared by every site behind the same address.
        // `token` is optional and raises that to 5,000.
        // ['driver' => 'github', 'key' => 'issues', 'mode' => 'issues',
        //  'owner' => 'your-org', 'repo' => 'alerts', 'label' => 'alert', 'token' => env('BEACON_GITHUB_TOKEN')],
    ],

    /*
    |--------------------------------------------------------------------------
    | Local collection
    |--------------------------------------------------------------------------
    |
    | The handle of the collection the `collection` driver reads.
    | `php please beacon:install` creates it with the right blueprint.
    |
    */
    'collection' => 'alerts',

    /*
    |--------------------------------------------------------------------------
    | Rendering
    |--------------------------------------------------------------------------
    |
    | `heading_level` is h2 to h6; h1 is refused because the page has one.
    | `compat` emits the structure and class names of the legacy client's
    | markup, with `compat_prefix` in place of its name, so an existing
    | stylesheet keeps working during a migration. A migration aid, off by
    | default, and not a long-term mode.
    |
    */
    'render' => [
        'heading_level' => 'h2',
        'compat' => false,
        'compat_prefix' => 'legacy-alert',
        'labels' => [
            'info' => 'Information',
            'warning' => 'Warning',
            'emergency' => 'Emergency',
        ],
        'more_text' => 'Read more',
        'dismiss_text' => 'Dismiss',
        'preview_text' => 'Preview. This banner is visible only to you.',
    ],

    /*
    |--------------------------------------------------------------------------
    | Assets
    |--------------------------------------------------------------------------
    |
    | `inline` prints the stylesheet and the dismiss script with the banner,
    | so there is no second request and nothing to publish. `linked` emits
    | link and script tags to the published files under public/vendor.
    | `none` emits neither, for a theme that bundles its own.
    |
    */
    'assets' => 'inline',

    /*
    |--------------------------------------------------------------------------
    | Preview
    |--------------------------------------------------------------------------
    |
    | `?beacon-preview=emergency` shows the newest alert at that severity,
    | marked as a preview, to a signed-in control panel user with the
    | "view beacon previews" permission. Anyone else sees the page as it
    | is. The value must be one of info, warning, emergency; anything else
    | is ignored. Previews are never cached.
    |
    */
    'preview' => [
        'enabled' => true,
        'parameter' => 'beacon-preview',
    ],

    /*
    |--------------------------------------------------------------------------
    | Storage and scheduler
    |--------------------------------------------------------------------------
    |
    | Fetched payloads and scheduler state live as files here, not in the
    | application cache, so `cache:clear` does not take a live banner down.
    | The control panel warns when the scheduler has not run within
    | `warn_after` seconds.
    |
    */
    'storage' => storage_path('beacon'),

    'scheduler' => [
        'warn_after' => 300,
    ],

];
