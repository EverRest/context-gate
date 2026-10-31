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
}
