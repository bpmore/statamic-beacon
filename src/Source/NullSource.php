<?php

declare(strict_types=1);

namespace Bpmore\Beacon\Source;

/** No alerts, ever. For tests, and for switching the banner off without uninstalling. */
final class NullSource implements AlertSource
{
    public function fetch(): array
    {
        return [];
    }
}
