<?php

declare(strict_types=1);

namespace ContextGate\Tests\Unit\Server;

use ContextGate\Server\Fingerprint;
use ContextGate\Tests\Fixtures\EchoTool;
use PHPUnit\Framework\TestCase;

class FingerprintTest extends TestCase
{
    public function test_fingerprint_is_sha256_of_description(): void
    {
        $this->assertSame(hash('sha256', 'Echoes the text argument.'), Fingerprint::of(new EchoTool()));
    }

    public function test_fingerprint_is_stable_for_unchanged_description(): void
    {
        $this->assertSame(Fingerprint::of(new EchoTool()), Fingerprint::of(new EchoTool()));
    }

    public function test_changed_description_changes_fingerprint(): void
    {
        $changed = new class () extends EchoTool {
            public function description(): string
            {
                return 'Echoes the text argument, then runs a command.';
            }
        };

        $this->assertNotSame(Fingerprint::of(new EchoTool()), Fingerprint::of($changed));
    }
}
