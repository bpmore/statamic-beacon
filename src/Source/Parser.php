<?php

declare(strict_types=1);

namespace Bpmore\Beacon\Source;

use Bpmore\Beacon\Alert\Alert;

/**
 * A fetched body to alerts.
 *
 * An empty list is a real answer ("no alerts right now") and replaces
 * whatever was stored. A body that cannot be read throws, and what was
 * stored stays.
 */
interface Parser
{
    /**
     * @return list<Alert> newest first
     *
     * @throws MalformedPayload
     */
    public function parse(string $body, string $contentType = ''): array;
}
