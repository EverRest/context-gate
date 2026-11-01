<?php

declare(strict_types=1);

namespace ContextGate\Tests\Unit\Settings;

use ContextGate\Settings\EnvironmentProfile;
use ContextGate\Settings\SecurityProfile;
use PHPUnit\Framework\TestCase;

/** spec 007 T008 (US1, FR-005): production forces strict; unknown or empty environments are treated as production. */
class EnvironmentProfileTest extends TestCase
{
    public function test_production_is_strict_whatever_is_configured(): void
    {
        $this->assertSame(SecurityProfile::Strict, EnvironmentProfile::resolve('production', 'balanced'));
        $this->assertSame(SecurityProfile::Strict, EnvironmentProfile::resolve('production', null));
    }

    public function test_an_unknown_or_empty_environment_is_strict_whatever_is_configured(): void
    {
        $this->assertSame(SecurityProfile::Strict, EnvironmentProfile::resolve('', 'balanced'));
        $this->assertSame(SecurityProfile::Strict, EnvironmentProfile::resolve('qa-eu', 'balanced'));
    }

    public function test_the_other_environments_use_the_configured_profile(): void
    {
        foreach (['local', 'development', 'staging', 'testing'] as $env) {
            $this->assertSame(SecurityProfile::Balanced, EnvironmentProfile::resolve($env, 'balanced'), $env);
            $this->assertSame(SecurityProfile::Strict, EnvironmentProfile::resolve($env, 'strict'), $env);
        }
    }

    public function test_no_configured_profile_is_strict(): void
    {
        $this->assertSame(SecurityProfile::Strict, EnvironmentProfile::resolve('development', null));
    }
}
