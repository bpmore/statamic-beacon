<?php

declare(strict_types=1);

namespace Bpmore\Beacon\Statamic;

use Bpmore\Beacon\Fetch\Request;
use Bpmore\Beacon\Fetch\Response;
use Bpmore\Beacon\Fetch\Transport;
use Bpmore\Beacon\Fetch\TransportFailed;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Throwable;

/**
 * The core's transport, on Laravel's HTTP client. Redirects are followed
 * (a feed URL moving is ordinary), a non-2xx status is a response and not
 * an exception (the poller decides what it means), and only a failure to
 * get any answer at all is thrown.
 */
final class LaravelTransport implements Transport
{
    public function __construct(private readonly Factory $http) {}

    public function send(Request $request): Response
    {
        try {
            $pending = $this->http
                ->withHeaders(array_merge(['User-Agent' => 'statamic-beacon (+https://hada.farm)'], $request->headers))
                ->timeout($request->timeout)
                ->connectTimeout(min($request->timeout, 5))
                ->withOptions(['allow_redirects' => ['max' => 5]]);

            $response = $request->method === 'POST'
                ? $pending->withBody($request->body ?? '', $request->headers['Content-Type'] ?? 'application/json')->post($request->url)
                : $pending->send($request->method, $request->url);
        } catch (ConnectionException $e) {
            throw new TransportFailed($e->getMessage(), 0, $e);
        } catch (Throwable $e) {
            throw new TransportFailed($e->getMessage(), 0, $e);
        }

        $headers = [];
        foreach ($response->headers() as $name => $values) {
            $headers[$name] = (string) ($values[0] ?? '');
        }

        return new Response($response->status(), $headers, $response->body());
    }
}
