<?php

declare(strict_types=1);

namespace ContextGate\Tests\Unit\Server;

use ContextGate\Contract\ContextContract;
use ContextGate\Contract\Field;
use ContextGate\Server\ContractGuard;
use ContextGate\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;

class ContractGuardTest extends TestCase
{
    public function test_changed_fields_trigger_revalidation(): void
    {
        config(['context-gate.classification' => ['guard_rows' => ['id' => 'public', 'email' => 'personal']]]);
        $contract = new GuardContract(['id' => Field::plain()]);

        $this->assertNull(ContractGuard::violation($contract));

        $contract->set(['id' => Field::plain(), 'email' => Field::plain()]);

        $this->assertNotNull(ContractGuard::violation($contract));
    }
}

class GuardContract extends ContextContract
{
    /** @param array<string, Field> $fields */
    public function __construct(private array $fields)
    {
    }

    /** @param array<string, Field> $fields */
    public function set(array $fields): void
    {
        $this->fields = $fields;
    }

    public function fields(): array
    {
        return $this->fields;
    }

    public function model(): string
    {
        return GuardRecord::class;
    }
}

class GuardRecord extends Model
{
    protected $table = 'guard_rows';
}
