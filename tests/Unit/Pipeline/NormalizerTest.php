<?php

declare(strict_types=1);

namespace ContextGate\Tests\Unit\Pipeline;

use ContextGate\Pipeline\Normalizer;
use ContextGate\Pipeline\Payload;
use ContextGate\Tests\Fixtures\InjectionCorpus;
use PHPUnit\Framework\TestCase;

/** User Story 6, FR-013: NFKC, then invisible characters removed (research R9). */
class NormalizerTest extends TestCase
{
    public function test_fullwidth_letters_become_ascii(): void
    {
        $result = $this->normalize("\u{FF21}\u{FF22}\u{FF23}");

        $this->assertSame('ABC', $result);
    }

    public function test_invisible_characters_from_the_corpus_are_removed(): void
    {
        $invisible = ["\u{200B}", "\u{202E}", "\u{2060}", "\u{FEFF}", "\u{E0041}"];

        foreach (InjectionCorpus::HIDDEN as $hidden) {
            $result = $this->normalize($hidden);

            $this->assertSame(str_replace($invisible, '', $hidden), $result);
        }
    }

    public function test_the_whole_control_range_is_removed(): void
    {
        $edges = ["\u{200F}", "\u{202A}", "\u{2064}", "\u{E0000}", "\u{E007F}"];

        $this->assertSame('ab', $this->normalize('a' . implode('', $edges) . 'b'));
    }

    public function test_only_text_fields_are_touched(): void
    {
        $payload = new Payload([['title' => "\u{FF21}\u{200B}", 'status' => "\u{FF21}"]], ['title', 'status']);

        $result = (new Normalizer(['title']))->handle($payload, static fn (Payload $next): Payload => $next);

        $this->assertSame([['title' => 'A', 'status' => "\u{FF21}"]], $result->rows);
    }

    private function normalize(string $text): string
    {
        $payload = new Payload([['text' => $text]], ['text']);

        $result = (new Normalizer(['text']))->handle($payload, static fn (Payload $next): Payload => $next);

        $value = $result->rows[0]['text'];
        $this->assertIsString($value);

        return $value;
    }
}
