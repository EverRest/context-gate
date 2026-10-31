<?php

declare(strict_types=1);

namespace ContextGate\Source\Fetch;

use ContextGate\Server\ReasonCode;
use ContextGate\Source\SourceFailure;

/**
 * Production transport on native curl: the pin is set on the same handle that connects, and the
 * timeout is one total deadline for the transfer (research R2 steps 5 and 7).
 */
final class HttpTransport implements Transport
{
    /**
     * The options that keep the connection on the pinned address: no redirects, no environment proxy (a proxy would
     * resolve the name itself), https only.
     *
     * @return array<int, mixed>
     */
    public static function baseOptions(string $host, string $address, int $timeoutMs): array
    {
        return [
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROXY => '',
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_RESOLVE => ["{$host}:443:{$address}"],
            CURLOPT_CONNECTTIMEOUT_MS => $timeoutMs,
            CURLOPT_TIMEOUT_MS => $timeoutMs,
        ];
    }

    public function fetch(string $url, string $address, float $timeoutSeconds, int $maxBytes): HttpReply
    {
        $host = (string) parse_url($url, PHP_URL_HOST);
        $timeoutMs = (int) round($timeoutSeconds * 1000);
        $contentType = '';
        $body = '';

        $curl = curl_init($url);
        curl_setopt_array($curl, self::baseOptions($host, $address, $timeoutMs) + [
            CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$contentType): int {
                if (stripos($line, 'content-type:') === 0) {
                    $contentType = trim(substr($line, strlen('content-type:')));
                }

                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body, $maxBytes): int {
                $body .= $chunk;

                return strlen($body) > $maxBytes ? 0 : strlen($chunk);
            },
        ]);

        curl_exec($curl);
        $errno = curl_errno($curl);

        if ($errno === CURLE_OPERATION_TIMEDOUT) {
            throw new TransportTimeout();
        }

        // ponytail: CURLE_WRITE_ERROR is our own stop at the cap; PinnedClient turns the oversized body into source.too_large
        if ($errno !== 0 && $errno !== CURLE_WRITE_ERROR) {
            throw new SourceFailure(ReasonCode::SourceRefused);
        }

        return new HttpReply((int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE), $contentType, $body);
    }
}
