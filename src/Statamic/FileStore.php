<?php

declare(strict_types=1);

namespace Bpmore\Beacon\Statamic;

use Bpmore\Beacon\Fetch\Store;
use Throwable;

/**
 * Snapshots and state as JSON files under storage.
 *
 * Written to a temporary name and renamed into place, so a page request
 * reading a snapshot while the scheduler writes one sees either the old
 * file or the new one and never half of each.
 */
final class FileStore implements Store
{
    public function __construct(private readonly string $directory) {}

    public function get(string $key): ?array
    {
        $path = $this->path($key);

        if (! is_file($path)) {
            return null;
        }

        try {
            $data = json_decode((string) file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }

        return is_array($data) ? $data : null;
    }

    public function put(string $key, array $data): void
    {
        if (! is_dir($this->directory)) {
            mkdir($this->directory, 0755, true);
        }

        $path = $this->path($key);
        $tmp = $path.'.'.bin2hex(random_bytes(4)).'.tmp';

        file_put_contents($tmp, json_encode($data, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), LOCK_EX);
        rename($tmp, $path);
    }

    public function forget(string $key): void
    {
        $path = $this->path($key);

        if (is_file($path)) {
            unlink($path);
        }
    }

    private function path(string $key): string
    {
        return $this->directory.'/'.preg_replace('/[^a-z0-9_.-]/i', '_', $key).'.json';
    }
}
