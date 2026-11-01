<?php

declare(strict_types=1);

// Quickstart fixture config (batch QS-357): the operator's config/context-gate.php for specs 003, 005 and 006.
// Test data only. The OS user is the stdio caller; the environment names are fake.
$osUser = posix_getpwuid(posix_geteuid())['name'] ?? 'unknown';

return [
    'enabled' => true,
    'profile' => 'strict',
    'callers' => [
        $osUser => ['tools' => ['routes.list', 'schema.describe', 'config.keys', 'docs.read']],
        // Spec 005 section 2: the HTTP caller of the token issued for the quickstart (gate:token prints its key).
        'token:2' => ['tools' => ['routes.list', 'schema.describe', 'config.keys', 'docs.read']],
    ],
    'log_level' => 'info',
    'tools' => [
        'routes.list' => ['enabled' => true, 'class' => \ContextGate\Server\Sources\RoutesListTool::class, 'gate' => 'routes.list.allow', 'fingerprint' => '594d034ad359c458650a46961fed9db03418f278eec27d5e3660787af7cd75af', 'contract' => \ContextGate\Source\Pseudo\RoutesContract::class],
        'schema.describe' => ['enabled' => true, 'class' => \ContextGate\Server\Sources\SchemaDescribeTool::class, 'gate' => 'schema.describe.allow', 'fingerprint' => '29d5b92df5b228a979722a4cf65d48e48040d5e658697aff9b589e00dc558603', 'contract' => \ContextGate\Source\Pseudo\SchemaItemsContract::class],
        'config.keys' => ['enabled' => true, 'class' => \ContextGate\Server\Sources\ConfigKeysTool::class, 'gate' => 'config.keys.allow', 'fingerprint' => 'ff989f32345974f7928b6b496a046f8e44a303ece50fa465feb917a0c22458b7', 'contract' => \ContextGate\Source\Pseudo\ConfigKeysContract::class],
        'docs.read' => ['enabled' => true, 'class' => \ContextGate\Server\Sources\DocsReadTool::class, 'gate' => 'docs.read.allow', 'fingerprint' => '2d816fae3662d99cca18df7938c7aa0aef29403f991c4835a7e23d38da9abdc3', 'contract' => \ContextGate\Source\Pseudo\DocsContract::class],
        // Spec 006 section 1: a disabled tool without a contract, so gate:audit lists it by name and gate:check skips it.
        'orders.status' => ['enabled' => false, 'class' => \ContextGate\Tests\Fixtures\Quickstart\OrdersStatusTool::class, 'gate' => 'orders.status.allow', 'fingerprint' => '0334c6a0b45b849e08311e383db56148d8dd2160d1fc97c9b7b72ea225ea5b78', ],
    ],
    'canaries' => ['values' => []],
    'firewall' => [
        'ceiling' => 'internal',
        'limits' => ['per_call_bytes' => null, 'per_session_bytes' => null, 'per_day_bytes' => null, 'calls_per_minute' => null],
    ],
    'classification' => [
        'users' => ['email' => 'personal', 'password' => 'secret', 'name' => 'personal'],
        'source_routes' => [
            'method' => 'internal', 'uri' => 'internal', 'name' => 'internal',
            'controller' => 'internal', 'rule_names' => 'internal', 'middleware' => 'internal',
        ],
        'source_config_keys' => ['key' => 'internal', 'type' => 'internal', 'env' => 'internal'],
        'source_schema_items' => [
            'model' => 'internal', 'table' => 'internal', 'kind' => 'internal', 'name' => 'internal',
            'type' => 'internal', 'class' => 'internal', 'detail' => 'internal',
        ],
        'source_docs' => ['source' => 'internal', 'text' => 'internal', 'truncated' => 'internal'],
        'source_openapi_endpoints' => [
            'version' => 'internal', 'method' => 'internal', 'path' => 'internal', 'parameters' => 'internal',
            'request_schema' => 'internal', 'examples' => 'internal', 'response_schemas' => 'internal', 'security' => 'internal',
        ],
    ],
    'http' => ['path' => 'mcp/context-gate', 'origins' => [], 'refusals_per_minute' => null],
    'channel' => 'context-gate',
    'cache_store' => null,
    'sources' => [
        'routes' => ['exclude' => []],
        'eloquent' => ['models' => [\ContextGate\Tests\Fixtures\Quickstart\Models\User::class]],
        'config' => ['env_map' => ['database.host' => 'DB_HOST', 'services.stripe.secret' => 'STRIPE_SECRET']],
        'docs' => ['paths' => ['docs/']],
        'openapi' => [
            'paths' => ['api/'], 'allowlist' => [], 'examples' => 'drop',
            'access' => 'read', 'timeout_seconds' => null, 'max_bytes' => null,
        ],
    ],
];
