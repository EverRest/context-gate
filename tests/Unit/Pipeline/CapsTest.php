<?php

declare(strict_types=1);

namespace ContextGate\Tests\Unit\Pipeline;

use ContextGate\Pipeline\Caps;
use PHPUnit\Framework\TestCase;

/** spec 007 T003: the connector caps hold the values the OpenAPI fetch used before they moved to Caps. */
class CapsTest extends TestCase
{
    public function test_the_openapi_caps_hold_the_fetch_values(): void
    {
        $this->assertSame(5.0, Caps::OPENAPI_TIMEOUT);
        $this->assertSame(2097152, Caps::OPENAPI_BYTES);
    }
}
