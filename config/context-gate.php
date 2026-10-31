<?php

declare(strict_types=1);

return [

    /*
    | Off until `gate:install` is run and tools are listed here. Nothing is visible by default.
    */
    'enabled' => false,

    /*
    | Tools a caller may see and call, keyed by tool name. Empty by default.
    */
    'tools' => [],

    /*
    | Canary values: a response or serialized output containing one of them is blocked (FR-017). Set by the
    | operator from the environment; empty by default.
    */
    'canaries' => ['values' => []],

    /*
    | The most sensitive class the stdio caller may receive (stdio only; tokens supply it in spec 005).
    */
    'firewall' => [
        'ceiling' => 'internal',

        /*
        | Null keeps the code default. A value lowers that limit; a value above its code cap is ignored and
        | gate:check reports it.
        */
        'limits' => [
            'per_call_bytes' => null,
            'per_session_bytes' => null,
            'per_day_bytes' => null,
            'calls_per_minute' => null,
        ],
    ],

    /*
    | Classification catalog: column => class. Empty by default, so every column is unclassified and dropped.
    */
    'classification' => [],

    /*
    | Log channel that receives one receipt line per call. Writes to stderr only.
    */
    'channel' => 'context-gate',

];
