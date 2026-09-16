<?php

declare(strict_types=1);

namespace Bpmore\Beacon\Sanitize;

use Closure;
use Symfony\Component\HtmlSanitizer\HtmlSanitizer as Symfony;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

/**
 * The allowlist, on top of symfony/html-sanitizer.
 *
 * Permitted: paragraphs, line breaks, inline formatting, links, and lists.
 * Everything else is dropped, including the `style` attribute, every event
 * handler, comments, and every element the brief names: script, style,
 * iframe, object, embed, form, input, button, textarea, select, option,
 * label. Those are not listed here because nothing is allowed unless it is
 * listed; a test names them so a wider allowlist cannot quietly let one in.
 *
 * A link whose scheme is not http or https loses its `href` in the
 * sanitizer. The brief wants `#` in its place and a log line, so anchors
 * left without an `href` get one afterwards and the count is reported.
 */
final class SymfonySanitizer implements HtmlSanitizer
{
    private const ELEMENTS = [
        'p' => [],
        'br' => [],
        'strong' => [],
        'b' => [],
        'em' => [],
        'i' => [],
        'u' => [],
        's' => [],
        'sub' => [],
        'sup' => [],
        'code' => [],
        'span' => [],
        'ul' => [],
        'ol' => ['start'],
        'li' => [],
        'a' => ['href', 'rel', 'target'],
    ];

    /**
     * Stripped of their tag, keeping their children, so a WordPress `<div>`
     * wrapper or an `<h3>` still shows its words. Anything not in this list
     * or the one above is removed with everything inside it, which is what
     * happens to script, style, iframe, object, embed, form, input, button,
     * textarea, select, option and label. They are not listed anywhere on
     * purpose: unknown means gone, and a test names each of them.
     */
    public const UNWRAPPED = [
        'div', 'section', 'article', 'aside', 'header', 'footer', 'main', 'nav',
        'figure', 'figcaption', 'blockquote', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
        'table', 'thead', 'tbody', 'tfoot', 'tr', 'td', 'th', 'pre', 'small',
        'mark', 'del', 'ins', 'abbr', 'cite', 'q', 'dl', 'dt', 'dd', 'font', 'center',
    ];

    private Symfony $sanitizer;

    /**
     * @param  (Closure(int): void)|null  $onUnsafeLink  called with how many links were neutralised
     */
    public function __construct(private readonly ?Closure $onUnsafeLink = null)
    {
        // Symfony's default for an element nobody mentioned is to drop it
        // with its children. That default is kept: it is what makes an
        // element missing from the lists above disappear rather than leak.
        $config = (new HtmlSanitizerConfig)
            ->allowLinkSchemes(['http', 'https'])
            ->allowRelativeLinks()
            ->forceHttpsUrls(false)
            ->withMaxInputLength(200_000);

        foreach (self::UNWRAPPED as $element) {
            $config = $config->blockElement($element);
        }
        foreach (self::ELEMENTS as $element => $attributes) {
            $config = $config->allowElement($element, $attributes);
        }

        $this->sanitizer = new Symfony($config);
    }

    public function sanitize(string $html): string
    {
        if (trim($html) === '') {
            return '';
        }

        $clean = $this->sanitizer->sanitize($html);

        $clean = (string) preg_replace_callback(
            '/<a(?![^>]*\shref=)([^>]*)>/i',
            function (array $m) use (&$dropped) {
                $dropped++;

                return '<a href="'.UrlScheme::FALLBACK.'"'.$m[1].'>';
            },
            $clean,
        );

        if (($dropped ?? 0) > 0 && $this->onUnsafeLink !== null) {
            ($this->onUnsafeLink)($dropped);
        }

        return trim($clean);
    }
}
