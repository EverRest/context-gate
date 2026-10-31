<?php

declare(strict_types=1);

namespace ContextGate\Tests\Contract;

require_once dirname(__DIR__) . '/Fixtures/app/app/Http/Requests/StoreOrderRequest.php';

use App\Http\Requests\StoreOrderRequest;
use ContextGate\Source\RoutesSource;
use ContextGate\Source\SourceRequest;
use ContextGate\Tests\TestCase;
use Illuminate\Support\Facades\Route;

class RoutesListTest extends TestCase
{
    public function test_a_post_route_with_a_form_request_lists_method_uri_name_controller_rules_and_middleware(): void
    {
        Route::post('api/orders', [StubOrderController::class, 'store'])
            ->middleware(['api', 'throttle:60,1'])
            ->name('orders.store');

        $result = (new RoutesSource())->read(new SourceRequest([]));

        $this->assertTrue($result->ok);
        /** @var list<array<string, mixed>> $routes */
        $routes = $result->data['routes'];
        $orders = array_values(array_filter($routes, static fn (array $r): bool => $r['uri'] === 'api/orders'));
        $this->assertCount(1, $orders);
        $this->assertSame([
            'method' => 'POST',
            'uri' => 'api/orders',
            'name' => 'orders.store',
            'controller' => StubOrderController::class . '@store',
            'rule_names' => ['customer_id', 'total'],
            'middleware' => ['api', 'throttle'],
        ], $orders[0]);
    }

    public function test_a_closure_route_has_no_controller_and_no_rule_names(): void
    {
        Route::post('api/ping', fn (): string => 'pong');

        $result = (new RoutesSource())->read(new SourceRequest([]));

        /** @var list<array<string, mixed>> $routes */
        $routes = $result->data['routes'];
        $ping = array_values(array_filter($routes, static fn (array $r): bool => $r['uri'] === 'api/ping'));
        $this->assertCount(1, $ping);
        $this->assertNull($ping[0]['controller']);
        $this->assertSame([], $ping[0]['rule_names']);
    }
}

final class StubOrderController
{
    public function store(StoreOrderRequest $request): void
    {
    }
}
