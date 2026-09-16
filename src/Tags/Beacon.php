<?php

declare(strict_types=1);

namespace Bpmore\Beacon\Tags;

use Bpmore\Beacon\Alert\Alert;
use Bpmore\Beacon\Assets;
use Bpmore\Beacon\Beacon as Addon;
use Statamic\Tags\Tags;

/**
 * `{{ beacon }}` prints the banner, with its stylesheet and script as
 * configured. Put it first inside `<body>`, before the skip link, so a
 * keyboard user reaches an emergency before the skip target.
 *
 * `{{ beacon:alerts }}` gives the active alerts as data for a template
 * that draws its own banner. `{{ beacon:assets }}` prints only the assets.
 */
class Beacon extends Tags
{
    public function index(): string
    {
        $addon = app(Addon::class);
        $html = $addon->render(request());

        $fastPath = $addon->fastPath();
        if ($fastPath['enabled'] && $addon->previewSeverity(request()) === null) {
            $html .= (new \Bpmore\Beacon\Render\Banner($addon->options()))
                ->liveContainers(route('statamic.beacon.live', [], false), $fastPath['interval']);
        }

        if ($html === '') {
            return '';
        }

        return $html.app(Assets::class)->markup($addon->options());
    }

    public function assets(): string
    {
        return app(Assets::class)->markup(app(Addon::class)->options());
    }

    /** @return list<array<string, mixed>> */
    public function alerts(): array
    {
        return array_map(fn (Alert $a) => array_merge($a->toArray(), [
            'visible_body' => $a->visibleBody(),
            'has_more_link' => $a->hasMoreLink(),
            'dismissal_key' => $a->dismissalKey(),
        ]), app(Addon::class)->active());
    }
}
