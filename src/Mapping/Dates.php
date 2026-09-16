<?php

declare(strict_types=1);

namespace Bpmore\Beacon\Mapping;

use DateTimeImmutable;
use Throwable;

final class Dates
{
    /** A timestamp from whatever a feed put there, or null when it is not one. */
    public static function parse(mixed $value): ?DateTimeImmutable
    {
        if ($value instanceof DateTimeImmutable) {
            return $value;
        }

        if (is_int($value)) {
            return (new DateTimeImmutable('@'.$value));
        }

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return new DateTimeImmutable(trim($value));
        } catch (Throwable) {
            return null;
        }
    }
}
