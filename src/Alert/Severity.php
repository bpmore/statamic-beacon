<?php

declare(strict_types=1);

namespace Bpmore\Beacon\Alert;

/**
 * The three levels an alert can carry, in a defined order.
 *
 * The reference client had no order: whichever category iterated last won,
 * so an alert tagged both `fyi` and `urgent` could render as an FYI. Here
 * `emergency` beats `warning` beats `info`, whatever order the input came in.
 */
enum Severity: string
{
    case Info = 'info';
    case Warning = 'warning';
    case Emergency = 'emergency';

    public function rank(): int
    {
        return match ($this) {
            self::Info => 1,
            self::Warning => 2,
            self::Emergency => 3,
        };
    }

    public function outranks(Severity $other): bool
    {
        return $this->rank() > $other->rank();
    }

    /** The highest of several, or null when there are none. */
    public static function highest(iterable $severities): ?Severity
    {
        $top = null;

        foreach ($severities as $severity) {
            if ($top === null || $severity->outranks($top)) {
                $top = $severity;
            }
        }

        return $top;
    }

    /** The lower of this and a ceiling. */
    public function cappedAt(Severity $ceiling): Severity
    {
        return $this->outranks($ceiling) ? $ceiling : $this;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $s) => $s->value, self::cases());
    }
}
