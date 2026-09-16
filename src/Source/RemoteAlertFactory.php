<?php

declare(strict_types=1);

namespace Bpmore\Beacon\Source;

use Bpmore\Beacon\Alert\Alert;
use Bpmore\Beacon\Alert\Severity;
use Bpmore\Beacon\Sanitize\HtmlSanitizer;
use Bpmore\Beacon\Sanitize\Teaser;
use Bpmore\Beacon\Sanitize\TitleText;
use Bpmore\Beacon\Sanitize\UrlScheme;
use DateTimeImmutable;

/**
 * The one place a remote alert is assembled, so every remote parser does
 * the same things in the same order: decode the title to text, split the
 * body at the teaser marker, sanitize each part, validate the URL, and
 * default `dismissible` by severity.
 *
 * Split happens before sanitizing because the marker is an HTML comment
 * and sanitizers strip comments. Sanitizing first loses the fold.
 */
final class RemoteAlertFactory
{
    public function __construct(
        private readonly HtmlSanitizer $sanitizer,
        private readonly string $teaserMarker = Teaser::DEFAULT_MARKER,
    ) {}

    /** The marker this factory splits on. */
    public function marker(): string
    {
        return $this->teaserMarker;
    }

    /**
     * @param  list<string>  $audiences
     * @param  string  $bodyHtml  untrusted HTML
     */
    public function fromHtml(
        string $id,
        string $title,
        string $bodyHtml,
        Severity $severity,
        array $audiences = [],
        ?DateTimeImmutable $startsAt = null,
        ?DateTimeImmutable $endsAt = null,
        ?string $url = null,
        ?bool $dismissible = null,
    ): Alert {
        $parts = Teaser::split($bodyHtml, $this->teaserMarker);

        $body = $this->sanitizer->sanitize($parts['body']);
        $teaser = $parts['teaser'] === null ? null : $this->sanitizer->sanitize($parts['teaser']);

        return new Alert(
            $id,
            TitleText::decode($title),
            $body,
            $teaser,
            $severity,
            array_values($audiences),
            $startsAt,
            $endsAt,
            UrlScheme::safe($url),
            $dismissible ?? ($severity !== Severity::Emergency),
        );
    }

    /**
     * For a body that is already safe HTML (escaped plain text, for CAP).
     * No teaser split: plain text has no marker.
     *
     * @param  list<string>  $audiences
     */
    public function fromSafeHtml(
        string $id,
        string $title,
        string $safeBody,
        Severity $severity,
        array $audiences = [],
        ?DateTimeImmutable $startsAt = null,
        ?DateTimeImmutable $endsAt = null,
        ?string $url = null,
        ?bool $dismissible = null,
    ): Alert {
        return new Alert(
            $id,
            TitleText::decode($title),
            $safeBody,
            null,
            $severity,
            array_values($audiences),
            $startsAt,
            $endsAt,
            UrlScheme::safe($url),
            $dismissible ?? ($severity !== Severity::Emergency),
        );
    }
}
