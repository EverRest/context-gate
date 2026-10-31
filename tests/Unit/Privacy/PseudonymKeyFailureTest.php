<?php

declare(strict_types=1);

namespace ContextGate\Tests\Unit\Privacy;

use ContextGate\Privacy\Pseudonymizer;
use ContextGate\Tests\TestCase;
use RuntimeException;

class PseudonymKeyFailureTest extends TestCase
{
    public function test_a_failed_key_generation_issues_no_pseudonym(): void
    {
        $pseudonymizer = new class () extends Pseudonymizer {
            protected function newKey(): string
            {
                throw new RuntimeException('No entropy.');
            }
        };

        $this->expectException(RuntimeException::class);

        $pseudonymizer->pseudonym('cus', 'customers', '42', 'session:'.uniqid('', true));
    }
}
