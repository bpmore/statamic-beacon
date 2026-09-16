<?php

declare(strict_types=1);

namespace Bpmore\Beacon\Alert;

use DateTimeImmutable;

/** A clock that says what it is told. For tests and for the scheduled tick. */
final class FrozenClock implements Clock
{
    public function __construct(private DateTimeImmutable $now) {}

    public static function at(string $when): self
    {
        return new self(new DateTimeImmutable($when));
    }

    public function now(): DateTimeImmutable
    {
        return $this->now;
    }

    public function set(DateTimeImmutable $now): void
    {
        $this->now = $now;
    }
}
