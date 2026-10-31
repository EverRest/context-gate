<?php

declare(strict_types=1);

namespace ContextGate\Source\Fetch;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/** Production transport: connects to the pinned address with the original host for TLS (research R2 step 5). */
final class HttpTransport implements Transport
{
    public function fetch(string $url, string $address, float $timeoutSeconds): HttpReply
    {
        $host = (string) parse_url($url, PHP_URL_HOST);

        try {
            $response = Http::withOptions([
                'allow_redirects' => false,
                'connect_timeout' => $timeoutSeconds,
                'timeout' => $timeoutSeconds,
                'stream' => true,
                'curl' => [CURLOPT_RESOLVE => ["{$host}:443:{$address}"]],
            ])->get($url);
        } catch (ConnectionException) {
            // ponytail: every connection failure reads as a timeout, split out when a receipt needs the difference
            throw new TransportTimeout();
        }

        $body = $response->toPsrResponse()->getBody();

        $chunks = (function () use ($body): \Generator {
            while (! $body->eof()) {
                yield $body->read(8192);
            }
        })();

        return new HttpReply($response->status(), $response->header('Content-Type'), $chunks);
    }
}
