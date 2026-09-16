<?php

declare(strict_types=1);

use Bpmore\Beacon\Sanitize\Markdown;
use Bpmore\Beacon\Sanitize\SymfonySanitizer;
use Bpmore\Beacon\Source\GitHubFileParser;
use Bpmore\Beacon\Source\GitHubIssuesParser;
use Bpmore\Beacon\Source\ParserFactory;
use Bpmore\Beacon\Source\SourceDefinition;

function issuesParser(): GitHubIssuesParser
{
    return new GitHubIssuesParser(factory(), new Markdown, ParserFactory::LABEL_PATTERN, ParserFactory::WORDPRESS_SEVERITY_MAP);
}

/** Brief test 16. */
it('parses labels for severity and audiences with precedence, ignoring unrecognised labels', function () {
    $alerts = issuesParser()->parse(json_encode([[
        'number' => 12, 'state' => 'open', 'title' => 'Water main break', 'body' => 'Avoid the east lot.',
        'html_url' => 'https://github.com/o/r/issues/12',
        'labels' => [['name' => 'urgent-clinic'], ['name' => 'fyi-campus'], ['name' => 'bug'], ['name' => 'help wanted']],
    ]]));

    expect($alerts)->toHaveCount(1)
        ->and(severityOf($alerts[0]))->toBe('emergency')
        ->and($alerts[0]->audiences)->toBe(['clinic', 'campus'])
        ->and($alerts[0]->id)->toBe('issue-12')
        ->and($alerts[0]->url)->toBe('https://github.com/o/r/issues/12');
});

it('skips issues with no recognised label, closed issues, and pull requests', function () {
    $alerts = issuesParser()->parse(json_encode([
        ['number' => 1, 'state' => 'open', 'title' => 'No label', 'body' => '', 'labels' => [['name' => 'bug']]],
        ['number' => 2, 'state' => 'closed', 'title' => 'Closed', 'body' => '', 'labels' => [['name' => 'urgent-campus']]],
        ['number' => 3, 'state' => 'open', 'title' => 'PR', 'body' => '', 'labels' => [['name' => 'urgent-campus']], 'pull_request' => ['url' => 'x']],
        ['number' => 4, 'state' => 'open', 'title' => 'Real', 'body' => '', 'labels' => [['name' => 'urgent-campus']]],
    ]));

    expect(array_map(fn ($a) => $a->id, $alerts))->toBe(['issue-4']);
});

/** Brief test 18. */
it('renders Markdown with raw HTML stripped at the parser, then sanitizes', function () {
    $markdown = new Markdown;
    $html = $markdown->toHtml("Hello <script>alert(1)</script> [site](https://example.org) <b onclick=x>b</b>\n\n<iframe src=x></iframe>");

    // The parser itself removed the HTML; the sanitizer is not what saved us.
    expect($html)->not->toContain('<script')
        ->and($html)->not->toContain('<iframe')
        ->and($html)->not->toContain('<b')
        ->and($html)->toContain('<a href="https://example.org">site</a>');

    $alerts = issuesParser()->parse(json_encode([[
        'number' => 5, 'state' => 'open', 'title' => 'T', 'labels' => [['name' => 'fyi-campus']],
        'body' => "Hello <script>alert(1)</script> [site](https://example.org)",
    ]]));

    expect($alerts[0]->body)->toContain('<a href="https://example.org">site</a>')
        ->and($alerts[0]->body)->not->toContain('script');
});

it('refuses unsafe Markdown links at the parser', function () {
    expect((new Markdown)->toHtml('[x](javascript:alert(1))'))->not->toContain('javascript:');
});

it('treats a GitHub error object as malformed, not as an empty list', function () {
    issuesParser()->parse('{"message":"Not Found"}');
})->throws(\Bpmore\Beacon\Source\MalformedPayload::class);

it('reads a JSON alerts file in the documented shape', function () {
    $parser = new GitHubFileParser(factory(), new Markdown);

    $alerts = $parser->parse(json_encode(['alerts' => [
        ['id' => 'boil', 'title' => 'Boil water notice', 'body' => '<p>Boil <b>all</b> water.</p><script>x</script>', 'severity' => 'emergency', 'audiences' => ['clinic'], 'ends_at' => '2026-03-02T00:00:00Z', 'url' => 'https://example.org/boil'],
        ['title' => 'Parking', 'markdown' => 'Lot **B** closed.', 'severity' => 'info', 'teaser' => '<p>Lot B</p>', 'url' => 'https://example.org/p'],
        ['title' => 'No severity', 'body' => 'x'],
    ]]));

    expect($alerts)->toHaveCount(2)
        ->and($alerts[0]->id)->toBe('boil')
        ->and($alerts[0]->body)->toBe('<p>Boil <b>all</b> water.</p>')
        ->and($alerts[0]->dismissible)->toBeFalse()
        ->and($alerts[0]->endsAt?->format(DATE_ATOM))->toBe('2026-03-02T00:00:00+00:00')
        ->and($alerts[1]->body)->toContain('<strong>B</strong>')
        ->and($alerts[1]->teaser)->toBe('<p>Lot B</p>')
        ->and($alerts[1]->hasMoreLink())->toBeTrue();
});

it('reads a YAML alerts file', function () {
    $parser = new GitHubFileParser(factory(), new Markdown);

    $alerts = $parser->parse("alerts:\n  - title: Snow day\n    severity: warning\n    body: '<p>Closed.</p>'\n    audiences: [campus, north]\n");

    expect($alerts)->toHaveCount(1)
        ->and(severityOf($alerts[0]))->toBe('warning')
        ->and($alerts[0]->audiences)->toBe(['campus', 'north']);
});

it('builds the raw CDN URL for file mode and the API URL for issues mode', function () {
    $file = SourceDefinition::fromArray(['driver' => 'github', 'mode' => 'file', 'owner' => 'campus', 'repo' => 'alerts', 'ref' => 'main', 'path' => 'alerts.json']);
    $issues = SourceDefinition::fromArray(['driver' => 'github', 'mode' => 'issues', 'owner' => 'campus', 'repo' => 'alerts', 'label' => 'alert']);
    $withToken = SourceDefinition::fromArray(['driver' => 'github', 'mode' => 'issues', 'owner' => 'o', 'repo' => 'r', 'token' => 'ghp_x']);

    expect($file->url)->toBe('https://raw.githubusercontent.com/campus/alerts/main/alerts.json')
        ->and($file->countsAgainstGitHubApi())->toBeFalse()
        ->and($issues->url)->toBe('https://api.github.com/repos/campus/alerts/issues?state=open&per_page=50&labels=alert')
        ->and($issues->countsAgainstGitHubApi())->toBeTrue()
        ->and($issues->headers)->not->toHaveKey('Authorization')
        ->and($withToken->headers['Authorization'])->toBe('Bearer ghp_x');
});
