<?php

declare(strict_types=1);

namespace ContextGate\Tests\Unit\Console;

use ContextGate\Tests\TestCase;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\Test;

/** gate:fingerprint names everything the pin hashes, so an operator knows what a change re-pins. */
class FingerprintCommandDescriptionTest extends TestCase
{
    #[Test]
    public function the_description_names_every_field_the_fingerprint_hashes(): void
    {
        $description = Artisan::all()['gate:fingerprint']->getDescription();

        $this->assertStringContainsString('name, description, schema, annotations', $description);
    }
}
