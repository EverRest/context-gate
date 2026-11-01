<?php

declare(strict_types=1);

namespace ContextGate\Console;

use ContextGate\Server\GateServer;
use ContextGate\Server\Preview;
use Illuminate\Console\Command;
use Laravel\Mcp\Server\Transport\FakeTransporter;

/**
 * spec 004 US4 (FR-015, FR-016): runs one tool call through the same MCP path a caller uses, as the current caller, and
 * prints the text it would receive followed by the dropped fields. The reply is captured here, never sent to a client.
 */
class PreviewCommand extends Command
{
    protected $signature = 'gate:preview {tool : The tool to preview} {--args= : The arguments as a JSON object}';

    protected $description = 'Print the response the current caller would receive for a tool, and the fields dropped; sends nothing';

    public function handle(): int
    {
        $raw = $this->option('args');
        $arguments = json_decode(is_string($raw) ? $raw : '{}', true);

        if (! is_array($arguments)) {
            $this->error('--args must be a JSON object.');

            return self::FAILURE;
        }

        $preview = new Preview();
        app()->instance(Preview::class, $preview);

        try {
            $tool = $this->argument('tool');
            $reply = $this->reply(is_string($tool) ? $tool : '', $arguments);
        } finally {
            app()->forgetInstance(Preview::class);
        }

        if (isset($reply['error'])) {
            $message = $reply['error']['message'] ?? null;
            $this->error(is_string($message) ? $message : 'Request failed.');

            return self::FAILURE;
        }

        /** @var array{isError: bool, content: list<array{text: string}>, structuredContent?: array{truncated?: bool}} $result */
        $result = $reply['result'];

        if ($result['isError']) {
            $reason = $preview->reason === null ? 'unknown' : $preview->reason->value;
            $this->error("Refused: {$reason}.");

            return self::FAILURE;
        }

        $this->line($result['content'][0]['text']);

        if ($result['structuredContent']['truncated'] ?? false) {
            $this->line('Truncated: yes');
        }

        $dropped = $preview->payload === null ? [] : $preview->payload->dropped;

        if ($dropped === []) {
            $this->line('Dropped: none');

            return self::SUCCESS;
        }

        $this->line('Dropped:');

        foreach ($dropped as $field) {
            $this->line("  {$field['field']}  {$field['reason']}");
        }

        return self::SUCCESS;
    }

    /**
     * @param array<array-key, mixed> $arguments
     * @return array<string, mixed> the JSON-RPC reply the server built for the call
     */
    private function reply(string $tool, array $arguments): array
    {
        $transport = new class () extends FakeTransporter {
            public ?string $sent = null;

            public function send(string $message): void
            {
                $this->sent = $message;
            }
        };

        $server = new GateServer($transport);
        $server->start();
        $server->handle((string) json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => ['name' => $tool, 'arguments' => $arguments],
        ], JSON_THROW_ON_ERROR));

        /** @var array<string, mixed> $reply */
        $reply = json_decode((string) $transport->sent, true, 512, JSON_THROW_ON_ERROR);

        return $reply;
    }
}
