<?php

declare(strict_types=1);

namespace Bpmore\Beacon\Fetch;

/**
 * The one thing the core asks of the outside world. The addon layer
 * implements it with Laravel's HTTP client; tests implement it with an
 * array.
 */
interface Transport
{
    /** @throws TransportFailed */
    public function send(Request $request): Response;
}
