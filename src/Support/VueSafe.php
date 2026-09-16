<?php

declare(strict_types=1);

namespace Bpmore\Beacon\Support;

/**
 * Text for a control panel view that Vue will compile.
 *
 * A utility view is not printed as HTML: Statamic hands the rendered markup
 * to Vue as a template. So Blade's escaping is half the job. A value with
 * `{{` in it, which an error message or a URL can carry, would be read by
 * Vue as an interpolation and the page would fail to compile, blank, with
 * no server error. Vue finds its delimiters before entities are decoded,
 * so a brace written as an entity is safe.
 */
final class VueSafe
{
    public static function text(mixed $value): string
    {
        return str_replace(['{', '}'], ['&#123;', '&#125;'], e((string) $value));
    }
}
