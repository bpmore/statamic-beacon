<?php

declare(strict_types=1);

namespace Bpmore\Beacon\Mapping;

/**
 * A value out of decoded JSON by dot path, with `*` meaning "each".
 *
 * `categories.*.slug` reads the `slug` of every entry under `categories`,
 * whether `categories` is a list or an object keyed by name. The reference
 * feed keys it by name, and a reader that assumed a list would find nothing
 * and say nothing.
 */
final class PathReader
{
    /**
     * A single value, or null when the path leads nowhere.
     */
    public static function one(mixed $data, string $path): mixed
    {
        $all = self::all($data, $path);

        return $all[0] ?? null;
    }

    /**
     * Every value the path reaches. A scalar at the end is one value; a
     * wildcard fans out. Nulls are not values.
     *
     * @return list<mixed>
     */
    public static function all(mixed $data, string $path): array
    {
        if ($path === '') {
            return $data === null ? [] : [$data];
        }

        $segments = explode('.', $path);

        return self::walk($data, $segments);
    }

    /**
     * @param  list<string>  $segments
     * @return list<mixed>
     */
    private static function walk(mixed $node, array $segments): array
    {
        if ($segments === []) {
            return $node === null ? [] : [$node];
        }

        $segment = array_shift($segments);

        if ($segment === '*') {
            if (! is_array($node)) {
                return [];
            }

            $out = [];
            foreach ($node as $child) {
                foreach (self::walk($child, $segments) as $value) {
                    $out[] = $value;
                }
            }

            return $out;
        }

        if (! is_array($node) || ! array_key_exists($segment, $node)) {
            return [];
        }

        return self::walk($node[$segment], $segments);
    }
}
