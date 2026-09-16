<?php

declare(strict_types=1);

namespace Bpmore\Beacon\Http\Controllers;

use Bpmore\Beacon\Alert\Alert;
use Bpmore\Beacon\Alert\Severity;
use Bpmore\Beacon\Beacon;
use Bpmore\Beacon\Render\Banner;
use Bpmore\Beacon\Settings;
use Illuminate\Http\JsonResponse;

/**
 * The emergency fast path's endpoint.
 *
 * Answers with the active alerts for this site's audience, at the
 * configured severities (emergency only, by default), each already
 * rendered from Beacon's own store. Nothing is fetched from a remote
 * source here: the page script polls this origin, and this origin serves
 * what the scheduler last stored. That is the difference between this
 * and a script tag pointed at somebody else's server.
 *
 * Never cached: not by Statamic's static cache (the header) and not by
 * the browser (no-store). Off by default, and 404 when off.
 */
final class LiveController
{
    public function __invoke(Beacon $beacon, Settings $settings): JsonResponse
    {
        $config = (array) ($settings->effective()['fast_path'] ?? []);

        if (! (bool) ($config['enabled'] ?? false)) {
            return $this->respond(['enabled' => false], 404);
        }

        $severities = self::severities($config['severities'] ?? ['emergency']);
        $banner = new Banner($beacon->options());

        $alerts = array_values(array_filter(
            $beacon->active(),
            fn (Alert $a) => in_array($a->severity, $severities, true),
        ));

        return $this->respond([
            'enabled' => true,
            'checked_at' => gmdate(DATE_ATOM),
            // The severities this answer is authoritative for: a banner on
            // the page at one of these that is not listed here has ended.
            'severities' => array_map(fn (Severity $s) => $s->value, $severities),
            'alerts' => array_map(fn (Alert $a) => [
                'id' => $a->id,
                'key' => $a->dismissalKey(),
                'severity' => $a->severity->value,
                'dismissible' => $a->dismissible,
                'html' => $banner->renderOne($a),
            ], $alerts),
        ]);
    }

    /** @return list<Severity> */
    public static function severities(mixed $raw): array
    {
        $out = [];

        foreach ((array) $raw as $s) {
            $severity = is_string($s) ? Severity::tryFrom(strtolower($s)) : null;
            if ($severity !== null) {
                $out[] = $severity;
            }
        }

        return $out === [] ? [Severity::Emergency] : $out;
    }

    /** @param  array<string, mixed>  $data */
    private function respond(array $data, int $status = 200): JsonResponse
    {
        return response()->json($data, $status)
            ->header('Cache-Control', 'no-store, private, max-age=0')
            ->header('X-Statamic-Uncacheable', 'true');
    }
}
