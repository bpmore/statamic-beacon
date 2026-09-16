<?php

declare(strict_types=1);

namespace Bpmore\Beacon\Fetch;

final class MemoryStore implements Store
{
    /** @var array<string, array<string, mixed>> */
    private array $data = [];

    public function get(string $key): ?array
    {
        return $this->data[$key] ?? null;
    }

    public function put(string $key, array $data): void
    {
        $this->data[$key] = $data;
    }

    public function forget(string $key): void
    {
        unset($this->data[$key]);
    }
}
