<?php

declare(strict_types=1);

namespace ContextGate\Tests\Unit\Source;

use ContextGate\Contract\Field;
use ContextGate\Source\Pseudo\OpenApiEndpointsContract;
use ContextGate\Tests\TestCase;

/** Document-supplied strings (path, security names, version) are untrusted text, not plain metadata. */
class OpenApiEndpointsContractTest extends TestCase
{
    public function test_path_security_and_version_are_text_fields(): void
    {
        $fields = (new OpenApiEndpointsContract())->fields();

        foreach (['version', 'path', 'security'] as $name) {
            $this->assertSame(Field::TEXT, $fields[$name]->form, $name);
        }
    }
}
