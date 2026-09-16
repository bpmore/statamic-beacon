<?php

declare(strict_types=1);

namespace Bpmore\Beacon\Fetch;

final class Request
{
    /** @param  array<string, string>  $headers */
    public function __construct(
        public readonly string $url,
        public readonly string $method = 'GET',
        public readonly array $headers = [],
        public readonly int $timeout = 5,
        public readonly ?string $body = null,
    ) {}

    /** @param  array<string, string>  $headers */
    public function withHeaders(array $headers): self
    {
        return new self($this->url, $this->method, array_merge($this->headers, $headers), $this->timeout, $this->body);
    }
}
