<?php

declare(strict_types=1);

namespace ContextGate\Tests\Fixtures\Quickstart;

use ContextGate\Server\Tool;
use ContextGate\Tests\Fixtures\Quickstart\Models\Order;
use Laravel\Mcp\Response;

/** Quickstart fixture (spec 004 section 4): orders.status lists orders with one status. */
class OrdersStatusTool extends Tool
{
    public function name(): string
    {
        return 'orders.status';
    }

    public function description(): string
    {
        return 'Lists the orders with the given status: id, status, total and notes.';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => ['status' => ['type' => 'string']],
            'required' => ['status'],
        ];
    }

    public function handle(array $arguments): Response
    {
        $rows = Order::query()
            ->where('status', (string) $arguments['status'])
            ->get(['id', 'status', 'total', 'user_id', 'notes'])
            ->toArray();

        return Response::text((string) json_encode($rows, JSON_THROW_ON_ERROR));
    }
}
