<?php

declare(strict_types=1);

namespace Bpmore\Beacon\Alert;

use DateTimeImmutable;

interface Clock
{
    public function now(): DateTimeImmutable;
}
