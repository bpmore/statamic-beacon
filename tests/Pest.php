<?php

declare(strict_types=1);

use Bpmore\Beacon\Alert\Alert;
use Bpmore\Beacon\Alert\Severity;
use Bpmore\Beacon\Fetch\Request;
use Bpmore\Beacon\Fetch\Response;
use Bpmore\Beacon\Fetch\Transport;
use Bpmore\Beacon\Fetch\TransportFailed;
use Bpmore\Beacon\Sanitize\SymfonySanitizer;
use Bpmore\Beacon\Source\RemoteAlertFactory;
use Bpmore\Beacon\Tests\TestCase;

// Only the feature tests boot Statamic. The core takes values and returns
// values, so its tests need no framework, and a suite that boots nothing
// for them cannot grow a dependency on something booted.
uses(TestCase::class)->in('Feature');

require_once __DIR__.'/Feature/Helpers.php';

function alert(array $overrides = []): Alert
{
    $data = array_merge([
        'id' => 'a1',
        'title' => 'Inclement Weather Update',
        'body' => '<p>Campus is closed.</p>',
        'teaser' => null,
        'severity' => 'warning',
        'audiences' => [],
        'starts_at' => null,
        'ends_at' => null,
        'url' => 'https://example.org/alert',
        'dismissible' => true,
    ], $overrides);

    return Alert::fromArray($data);
}

function factory(): RemoteAlertFactory
{
    return new RemoteAlertFactory(new SymfonySanitizer);
}

/**
 * A transport that answers from a script: each call takes the next entry.
 * An entry is a Response, or a Throwable to throw, or a closure given the
 * request.
 */
function transport(array $script): Transport
{
    return new class($script) implements Transport
    {
        public array $requests = [];

        public function __construct(private array $script) {}

        public function send(Request $request): Response
        {
            $this->requests[] = $request;

            if ($this->script === []) {
                throw new TransportFailed('script exhausted');
            }

            $next = array_shift($this->script);

            if ($next instanceof Throwable) {
                throw $next;
            }

            if ($next instanceof Closure) {
                return $next($request);
            }

            return $next;
        }
    };
}

function ok(string $body, array $headers = []): Response
{
    return new Response(200, array_merge(['Content-Type' => 'application/json'], $headers), $body);
}

/** WordPress.com's posts API shape, with the categories object keyed by name. */
function wpFeed(array $posts, ?int $found = null, array $extra = []): string
{
    return json_encode(array_merge([
        'found' => $found ?? count($posts),
        'posts' => $posts,
        'cache_state' => 'stale',
        'cache_age' => '0 minute(s), 1 second(s) old',
    ], $extra), JSON_THROW_ON_ERROR);
}

function wpPost(array $categorySlugs, array $overrides = []): array
{
    $categories = [];
    foreach ($categorySlugs as $slug) {
        $categories[ucwords(str_replace('-', ' ', $slug))] = ['ID' => crc32($slug), 'name' => $slug, 'slug' => $slug];
    }

    return array_merge([
        'ID' => 400,
        'date' => '2026-01-25T15:00:00-06:00',
        'title' => 'Inclement Weather Update',
        'URL' => 'https://alerts.example.wordpress.com/2026/01/25/inclement-weather-update/',
        'content' => "\n<p class=\"wp-block-paragraph\"><a href=\"https://example.edu/updates/\" target=\"_blank\" rel=\"noreferrer noopener\">Click Here for Updates</a></p>\n",
        'excerpt' => '<p>Click Here for Updates</p>',
        'categories' => $categories,
        'tags' => [],
    ], $overrides);
}

function severityOf(Alert $a): string
{
    return $a->severity->value;
}

function sev(string $s): Severity
{
    return Severity::from($s);
}
