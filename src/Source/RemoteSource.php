<?php

declare(strict_types=1);

namespace Bpmore\Beacon\Source;

use Bpmore\Beacon\Alert\Clock;
use Bpmore\Beacon\Fetch\Poller;

/**
 * A remote source as the renderer sees it: the last good payload, unless
 * it is older than the ceiling, in which case nothing. Never fetches on a
 * page request; the poller does that on the schedule.
 */
final class RemoteSource implements AlertSource
{
    public function __construct(
        private readonly SourceDefinition $definition,
        private readonly Poller $poller,
        private readonly Clock $clock,
    ) {}

    public function fetch(): array
    {
        return $this->poller->snapshot($this->definition)->servable($this->clock->now(), $this->definition->maxAge);
    }
}
