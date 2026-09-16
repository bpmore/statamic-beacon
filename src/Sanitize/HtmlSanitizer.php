<?php

declare(strict_types=1);

namespace Bpmore\Beacon\Sanitize;

interface HtmlSanitizer
{
    /** Untrusted HTML in, HTML safe to print out. */
    public function sanitize(string $html): string;
}
