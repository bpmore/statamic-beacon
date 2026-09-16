<?php

declare(strict_types=1);

namespace Bpmore\Beacon\Fetch;

/**
 * Where snapshots and scheduler state live between requests.
 *
 * Not the application cache: `cache:clear` must not take a live emergency
 * banner down with it. The addon layer keeps files under storage.
 */
interface Store
{
    /** @return array<string, mixed>|null */
    public function get(string $key): ?array;

    /** @param  array<string, mixed>  $data */
    public function put(string $key, array $data): void;

    public function forget(string $key): void;
}
