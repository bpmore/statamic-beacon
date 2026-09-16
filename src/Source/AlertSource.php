<?php

declare(strict_types=1);

namespace Bpmore\Beacon\Source;

use Bpmore\Beacon\Alert\Alert;

interface AlertSource
{
    /** @return list<Alert> newest first */
    public function fetch(): array;
}
