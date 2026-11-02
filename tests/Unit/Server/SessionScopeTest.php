<?php

declare(strict_types=1);

namespace ContextGate\Tests\Unit\Server;

use ContextGate\Contract\Field;
use ContextGate\Server\Caller;
use ContextGate\Server\FirewallRunner;
use PHPUnit\Framework\TestCase;

/** Spec 008 US3 #2 (T039, verification): a session pseudonym scope is bound to its caller. */
class SessionScopeTest extends TestCase
{
    public function test_a_session_scope_key_is_per_caller(): void
    {
        $field = Field::pseudonym('cus');

        $this->assertSame('session:alice', FirewallRunner::scopeKey($field, 'customer_id', new Caller('alice', [])));
        $this->assertSame('session:bob', FirewallRunner::scopeKey($field, 'customer_id', new Caller('bob', [])));
        $this->assertNotSame(
            FirewallRunner::scopeKey($field, 'customer_id', new Caller('alice', [])),
            FirewallRunner::scopeKey($field, 'customer_id', new Caller('bob', [])),
        );
    }
}
