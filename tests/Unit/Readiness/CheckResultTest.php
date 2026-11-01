<?php

declare(strict_types=1);

namespace ContextGate\Tests\Unit\Readiness;

use ContextGate\Readiness\CheckResult;
use ContextGate\Readiness\CheckStatus;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class CheckResultTest extends TestCase
{
    public function test_fail_has_status_fail_and_keeps_subject_message_and_fix(): void
    {
        $result = CheckResult::fail('context-gate.limits.per_call_bytes', 'value exceeds the cap', 'php artisan gate:check');

        $this->assertSame(CheckStatus::Fail, $result->status);
        $this->assertSame('context-gate.limits.per_call_bytes', $result->subject);
        $this->assertSame('value exceeds the cap', $result->message);
        $this->assertSame('php artisan gate:check', $result->fix);
    }

    public function test_pass_and_warn_constructors_set_their_status(): void
    {
        $pass = CheckResult::pass('context-gate.http.path', 'set');
        $warn = CheckResult::warn('context-gate.http.origins', 'empty', 'set http.origins');

        $this->assertSame(CheckStatus::Pass, $pass->status);
        $this->assertSame(CheckStatus::Warn, $warn->status);
        $this->assertSame('set http.origins', $warn->fix);
    }

    public function test_message_is_stored_as_given_and_carries_no_appended_value(): void
    {
        $result = CheckResult::fail('context-gate.tokens', 'token table has rows without a hash', 'php artisan gate:token --list');

        $this->assertSame('token table has rows without a hash', $result->message);
    }

    public function test_result_exposes_only_name_free_string_fields(): void
    {
        $properties = array_map(
            static fn (\ReflectionProperty $p): string => $p->getName(),
            (new ReflectionClass(CheckResult::class))->getProperties(),
        );

        sort($properties);
        $this->assertSame(['fix', 'message', 'status', 'subject'], $properties);
    }
}
