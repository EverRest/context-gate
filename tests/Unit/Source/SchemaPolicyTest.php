<?php

declare(strict_types=1);

namespace ContextGate\Tests\Unit\Source;

require_once __DIR__ . '/../../Fixtures/app/app/Models/User.php';

require_once __DIR__ . '/../../Fixtures/app/app/Models/Order.php';

use App\Models\Order;
use App\Models\User;
use ContextGate\Classification\Catalog;
use ContextGate\Server\ReasonCode;
use ContextGate\Source\SchemaSource;
use ContextGate\Source\SourceRequest;
use PHPUnit\Framework\TestCase;

class SchemaPolicyTest extends TestCase
{
    public function test_a_model_outside_the_configured_list_is_denied(): void
    {
        $result = (new SchemaSource(new Catalog([]), [Order::class]))->read(new SourceRequest(['model' => User::class]));

        $this->assertFalse($result->ok);
        $this->assertSame(ReasonCode::PolicyDenied, $result->reason);
        $this->assertSame([], $result->data);
    }
}
