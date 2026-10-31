<?php

declare(strict_types=1);

namespace ContextGate\Tests\Contract;

use ContextGate\Classification\Catalog;
use ContextGate\Server\ReasonCode;
use ContextGate\Source\SchemaSource;
use ContextGate\Source\SourceRequest;
use ContextGate\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;

/** A model whose table was never migrated. */
final class GhostModel extends Model
{
    protected $table = 'ghosts';
}

class SchemaMissingTableTest extends TestCase
{
    public function test_missing_table_returns_schema_missing_without_data(): void
    {
        $result = (new SchemaSource(new Catalog([]), [GhostModel::class]))
            ->read(new SourceRequest(['model' => GhostModel::class]));

        $this->assertFalse($result->ok);
        $this->assertSame(ReasonCode::SchemaMissing, $result->reason);
        $this->assertSame([], $result->data);
        $this->assertNull($result->refusalStep);
    }
}
