<?php

declare(strict_types=1);

namespace ContextGate\Tests\Unit\Classification;

use ContextGate\Classification\DraftWriter;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** T032, spec 004 US3 and research R6: name-based suggestions are marked; unknown columns need a decision. */
class DraftWriterTest extends TestCase
{
    #[Test]
    public function an_email_column_is_suggested_personal(): void
    {
        $draft = (new DraftWriter())->render(['users' => ['email']]);

        $this->assertStringContainsString("'email' => 'personal', // suggested", $draft);
    }

    #[Test]
    public function a_password_column_is_suggested_secret(): void
    {
        $draft = (new DraftWriter())->render(['users' => ['password']]);

        $this->assertStringContainsString("'password' => 'secret', // suggested", $draft);
    }

    #[Test]
    public function a_column_without_a_suggestion_needs_a_decision(): void
    {
        $draft = (new DraftWriter())->render(['orders' => ['total']]);

        $this->assertStringContainsString("'total' => 'secret', // needs decision", $draft);
    }
}
