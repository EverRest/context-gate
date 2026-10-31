<?php

declare(strict_types=1);

namespace ContextGate\Tests\Unit\Fetch;

use ContextGate\Source\Fetch\HttpTransport;
use PHPUnit\Framework\TestCase;

/** FR-007: the pinned address is the only route; an environment proxy would resolve the name itself. */
class HttpTransportOptionsTest extends TestCase
{
    public function test_curl_ignores_proxy_variables_and_speaks_https_only(): void
    {
        $options = HttpTransport::baseOptions('api.example.test', '203.0.113.10', 5000);

        $this->assertSame('', $options[CURLOPT_PROXY]);
        $this->assertSame(CURLPROTO_HTTPS, $options[CURLOPT_PROTOCOLS]);
        $this->assertSame(['api.example.test:443:203.0.113.10'], $options[CURLOPT_RESOLVE]);
        $this->assertFalse($options[CURLOPT_FOLLOWLOCATION]);
    }
}
