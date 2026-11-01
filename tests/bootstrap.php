<?php

declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';

// The fixture application keeps its bootstrap/cache directory out of git (its files are generated), so a fresh
// checkout has none; the Testbench boot needs it to exist and be writable.
@mkdir(__DIR__.'/Fixtures/app/bootstrap/cache', 0775, true);
