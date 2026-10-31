<?php

declare(strict_types=1);

namespace ContextGate\Tests\Contract;

use Closure;
use ContextGate\Pipeline\Normalizer;
use ContextGate\Pipeline\Payload;
use ContextGate\Pipeline\Projector;
use ContextGate\Pipeline\Quarantine;
use ContextGate\Pipeline\Redact;
use ContextGate\Pipeline\Stage;
use ContextGate\Privacy\Redactor;
use ContextGate\Tests\Fixtures\SeededPii;
use PHPUnit\Framework\TestCase;

/** SC-001 for text fields: seeded personal values inside text never reach the output of the text stages. */
class PrivacySeededTextTest extends TestCase
{
    public function test_seeded_values_inside_text_fields_never_appear_in_the_output(): void
    {
        $text = 'Contact ' . SeededPii::EMAIL . ', phone ' . SeededPii::PHONE . ', IBAN ' . SeededPii::IBAN
            . ', card ' . SeededPii::CARD . ', auth ' . SeededPii::BEARER_TOKEN . '.';
        $payload = new Payload([['note' => $text, 'status' => 'open']], ['note', 'status']);

        $result = $this->throughTextStages($payload);
        $encoded = json_encode($result->rows, JSON_THROW_ON_ERROR);

        foreach ([SeededPii::EMAIL, SeededPii::PHONE, SeededPii::IBAN, SeededPii::CARD, SeededPii::BEARER_TOKEN] as $value) {
            $this->assertStringNotContainsString($value, $encoded, "text leaks {$value}");
        }
    }

    private function throughTextStages(Payload $payload): Payload
    {
        /** @var list<Stage> $stages */
        $stages = [
            new Projector(),
            new Normalizer(['note']),
            new Redact(new Redactor(), ['note']),
            new Quarantine('docs:README.md', ['note']),
        ];

        /** @var Closure(Payload): Payload $next */
        $next = static fn (Payload $output): Payload => $output;

        foreach (array_reverse($stages) as $stage) {
            $next = static fn (Payload $input): Payload => $stage->handle($input, $next);
        }

        return $next($payload);
    }
}
