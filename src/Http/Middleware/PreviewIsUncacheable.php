<?php

declare(strict_types=1);

namespace Bpmore\Beacon\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Statamic\StaticCaching\Cacher;
use Statamic\StaticCaching\Cachers\NullCacher;
use Symfony\Component\HttpFoundation\Response;

/**
 * A permitted preview request bypasses the static cache both ways: it is
 * never served a stored page and never stored itself.
 *
 * Statamic ignores query strings by default when it keys its cache, so
 * `/?beacon-preview=warning` would otherwise be answered with the cached
 * `/`, preview and all missing. And where query strings are part of the
 * key, a preview page written to the cache would be served, banner and
 * all, to the next anonymous visitor with the same link.
 *
 * Only a request that will actually render a preview gets the bypass: the
 * parameter present, its value in the enum, and a signed-in user with the
 * permission. Anyone else adding the parameter to a link gets the cached
 * page like everyone else, so the parameter is not a free way to make a
 * site render every page from scratch.
 *
 * This runs in the `web` group, ahead of Statamic's own cache middleware
 * in `statamic.web`, and that middleware is constructed only when the
 * pipeline reaches it. Swapping the cacher for the null one here, for this
 * request only, is what it sees. The header is belt to that brace: it is
 * the one Statamic's middleware honours on the way out.
 */
final class PreviewIsUncacheable
{
    public function handle(Request $request, Closure $next): Response
    {
        $parameter = (string) (app(\Bpmore\Beacon\Settings::class)->effective()['preview']['parameter'] ?? 'beacon-preview');

        // The cheap check first; the user lookup only when the parameter is there.
        $previewing = $request->query->has($parameter)
            && app(\Bpmore\Beacon\Beacon::class)->previewSeverity($request) !== null;

        if ($previewing) {
            app()->instance(Cacher::class, new NullCacher);
        }

        $response = $next($request);

        if ($previewing && $response instanceof Response) {
            $response->headers->set('X-Statamic-Uncacheable', 'true');
            $response->headers->set('Cache-Control', 'no-store, private');
        }

        return $response;
    }
}
