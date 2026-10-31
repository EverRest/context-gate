<?php

declare(strict_types=1);

namespace ContextGate\Tests\Unit\Contract;

use ContextGate\Contract\ContextContract;
use ContextGate\Contract\Field;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use stdClass;

class ContextContractTest extends TestCase
{
    public function test_fields_are_returned_in_declared_order(): void
    {
        $contract = new ContextContractTestOrderContract();

        $this->assertSame(['status', 'total', 'email'], array_keys($contract->fields()));
        $this->assertSame(stdClass::class, $contract->model());
    }

    public function test_no_factory_or_contract_method_takes_a_class_parameter(): void
    {
        $methods = [
            ...(new ReflectionClass(Field::class))->getMethods(ReflectionMethod::IS_STATIC),
            ...(new ReflectionClass(ContextContract::class))->getMethods(),
        ];

        foreach ($methods as $method) {
            foreach ($method->getParameters() as $parameter) {
                $this->assertNotSame('class', $parameter->getName(), $method->class . '::' . $method->getName());
            }
        }
    }
}

final class ContextContractTestOrderContract extends ContextContract
{
    public function model(): string
    {
        return stdClass::class;
    }

    public function fields(): array
    {
        return [
            'status' => Field::plain(),
            'total' => Field::bucket([0, 100, 1000]),
            'email' => Field::pseudonym('USR'),
        ];
    }
}
