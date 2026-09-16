<?php

declare(strict_types=1);

namespace Bpmore\Beacon\Fetch;

final class Response
{
    /** @var array<string, string> lower-cased header names */
    public readonly array $headers;

    /** @param  array<string, string|list<string>>  $headers */
    public function __construct(
        public readonly int $status,
        array $headers,
        public readonly string $body,
    ) {
        $flat = [];
        foreach ($headers as $name => $value) {
            $flat[strtolower((string) $name)] = is_array($value) ? (string) ($value[0] ?? '') : (string) $value;
        }
        $this->headers = $flat;
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    public function contentType(): string
    {
        return $this->header('content-type') ?? '';
    }
}
