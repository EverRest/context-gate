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
    | Log channel that receives one receipt line per call. Writes to stderr only.
    */
    'channel' => 'context-gate',

];
