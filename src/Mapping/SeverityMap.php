<?php

declare(strict_types=1);

namespace Bpmore\Beacon\Mapping;

use Bpmore\Beacon\Alert\Severity;

/**
 * A feed's own severity word to one of Beacon's three.
 *
 * The map is config, never code: `urgent` means `emergency` on one feed and
 * nothing on another. A word that is already one of Beacon's own passes
 * through when the map does not mention it, and an unknown word is null.
 */
final class SeverityMap
{
    /** @param  array<string, string>  $map */
    public static function resolve(string $word, array $map): ?Severity
    {
        $word = strtolower(trim($word));

        if (array_key_exists($word, $map)) {
            return Severity::tryFrom(strtolower((string) $map[$word]));
        }

        return Severity::tryFrom($word);
    }
}
