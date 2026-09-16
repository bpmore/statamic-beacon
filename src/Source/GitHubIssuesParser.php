<?php

declare(strict_types=1);

namespace Bpmore\Beacon\Source;

use Bpmore\Beacon\Alert\Severity;
use Bpmore\Beacon\Mapping\Dates;
use Bpmore\Beacon\Mapping\PatternMap;
use Bpmore\Beacon\Sanitize\Markdown;
use JsonException;

/**
 * Open GitHub issues to alerts.
 *
 * Labels carry severity and audience the way a WordPress category slug
 * does, through the same pattern: `urgent-clinic` as a label works like
 * `urgent-clinic` as a category. A label the pattern does not
 * match (`bug`) is ignored. The issue title is the alert title, the body is
 * Markdown rendered with raw HTML stripped at the parser, then sanitized.
 * Closing the issue ends the alert. Pull requests, which the issues API
 * also lists, are skipped.
 */
final class GitHubIssuesParser implements Parser
{
    /** @param  array<string, string>  $severityMap */
    public function __construct(
        private readonly RemoteAlertFactory $factory,
        private readonly Markdown $markdown,
        private readonly string $pattern,
        private readonly array $severityMap = [],
        private readonly ?Severity $defaultSeverity = null,
    ) {}

    public function parse(string $body, string $contentType = ''): array
    {
        try {
            $data = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new MalformedPayload('The response is not JSON: '.$e->getMessage(), 0, $e);
        }

        if (! is_array($data) || ! array_is_list($data)) {
            // GitHub returns an object, with `message`, for errors.
            $message = is_array($data) && isset($data['message']) ? (string) $data['message'] : 'not a list of issues';

            throw new MalformedPayload('GitHub did not return a list of issues: '.$message);
        }

        $alerts = [];

        foreach ($data as $issue) {
            if (! is_array($issue) || isset($issue['pull_request'])) {
                continue;
            }

            if (($issue['state'] ?? 'open') !== 'open') {
                continue;
            }

            $title = isset($issue['title']) ? (string) $issue['title'] : null;
            if ($title === null || trim($title) === '') {
                continue;
            }

            $labels = [];
            foreach ((array) ($issue['labels'] ?? []) as $label) {
                $labels[] = is_array($label) ? ($label['name'] ?? null) : $label;
            }

            $extracted = (new PatternMap($this->pattern, $this->severityMap))->extract($labels);
            $severity = $extracted['severity'] ?? $this->defaultSeverity;

            if ($severity === null) {
                continue;
            }

            $alerts[] = $this->factory->fromHtml(
                isset($issue['number']) ? 'issue-'.$issue['number'] : hash('sha256', $title),
                $title,
                $this->markdown->toHtml((string) ($issue['body'] ?? '')),
                $severity,
                $extracted['audiences'],
                Dates::parse($issue['created_at'] ?? null),
                null,
                isset($issue['html_url']) ? (string) $issue['html_url'] : null,
            );
        }

        return $alerts;
    }
}
