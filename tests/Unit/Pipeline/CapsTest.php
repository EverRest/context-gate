<?php

declare(strict_types=1);

namespace ContextGate\Tests\Unit\Pipeline;

use ContextGate\Pipeline\Caps;
use PHPUnit\Framework\TestCase;

class CapsTest extends TestCase
{
    public function test_hard_caps_match_the_data_model(): void
    {
        $this->assertSame(65536, Caps::PER_CALL);
        $this->assertSame(2097152, Caps::PER_SESSION);
        $this->assertSame(20971520, Caps::PER_DAY);
        $this->assertSame(300, Caps::PER_MINUTE);
    }

    /** spec 007 T003: the connector caps hold the values the OpenAPI fetch used before they moved to Caps. */
    public function test_the_openapi_caps_hold_the_fetch_values(): void
    {
        $this->assertSame(5.0, Caps::OPENAPI_TIMEOUT);
        $this->assertSame(2097152, Caps::OPENAPI_BYTES);
    }
}
