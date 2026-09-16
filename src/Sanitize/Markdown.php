<?php

declare(strict_types=1);

namespace Bpmore\Beacon\Sanitize;

use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\MarkdownConverter;

/**
 * Markdown to HTML with raw HTML refused at the parser.
 *
 * GitHub issue bodies are Markdown. Raw HTML inside them is stripped here,
 * before the sanitizer sees the result, so the sanitizer is the second line
 * and not the only one. GitHub's rendered-HTML endpoint is never used: it
 * would put remote HTML back into the pipeline.
 */
final class Markdown
{
    private MarkdownConverter $converter;

    public function __construct()
    {
        $environment = new Environment([
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
            'max_nesting_level' => 20,
        ]);
        $environment->addExtension(new CommonMarkCoreExtension);

        $this->converter = new MarkdownConverter($environment);
    }

    public function toHtml(string $markdown): string
    {
        return (string) $this->converter->convert($markdown);
    }
}
