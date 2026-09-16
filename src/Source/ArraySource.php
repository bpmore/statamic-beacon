<?php

declare(strict_types=1);

namespace Bpmore\Beacon\Source;

use Bpmore\Beacon\Alert\Alert;

/** Alerts handed in directly. For tests and for wiring alerts from code. */
final class ArraySource implements AlertSource
{
    /** @param  list<Alert>  $alerts */
    public function __construct(private readonly array $alerts) {}

    public function fetch(): array
    {
        return $this->alerts;
    }
}
