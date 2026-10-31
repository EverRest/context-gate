<?php

declare(strict_types=1);

namespace ContextGate\Tests\Fixtures;

use ContextGate\Contract\ContextContract;
use ContextGate\Contract\Field;

/** Test-only: relates to a contract that has its own relation, which a contract may not do (FR-011). */
class DepthRelationProbeContract extends ContextContract
{
    public function fields(): array
    {
        return ['customer_id' => Field::relation(RelationProbeContract::class)];
    }

    public function model(): string
    {
        return ProbeOrderModel::class;
    }
}
