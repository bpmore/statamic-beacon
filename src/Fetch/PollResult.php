<?php

declare(strict_types=1);

namespace Bpmore\Beacon\Fetch;

/**
 * What one poll did. `changed` is the only thing the page cache cares
 * about: it is true when what a visitor would see is different from what
 * the previous snapshot would have shown.
 */
final class PollResult
{
    public function __construct(
        public readonly string $source,
        public readonly Snapshot $snapshot,
        public readonly bool $changed,
        public readonly bool $succeeded,
        public readonly bool $skipped,
        public readonly ?string $note = null,
    ) {}
}
