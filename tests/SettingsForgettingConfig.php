<?php

declare(strict_types=1);

namespace ContextGate\Tests;

use ContextGate\Settings\ActiveSettings;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;

/**
 * A config write in a test stands for the next process: the settings are validated again on the next read (spec 009
 * validates once per process; a test changes the document between processes, not inside one).
 */
final class SettingsForgettingConfig extends Repository
{
    /** @param array<array-key, mixed>|string $key */
    public function set($key, $value = null): void
    {
        parent::set($key, $value);

        Container::getInstance()->forgetInstance(ActiveSettings::class);
    }
}
