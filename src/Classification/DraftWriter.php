<?php

declare(strict_types=1);

namespace ContextGate\Classification;

/**
 * Renders the gate:classify draft (spec 004 US3, research R6). A column whose name holds a fragment below gets a
 * suggestion; secret fragments are checked first, so a name that fits both stays secret. Any other column is
 * `secret // needs decision`. The draft enables nothing: CatalogCheck (spec 006) rejects it until the markers go.
 */
final class DraftWriter
{
    private const SUGGESTIONS = [
        'secret' => ['password', 'token', 'secret'],
        'personal' => ['email', 'phone', 'mobile', 'iban', 'card', 'address', 'birth', 'ssn'],
    ];

    /** @param array<string, list<string>> $tables table => column names */
    public function render(array $tables): string
    {
        $header = "<?php\n\n// Draft from gate:classify. It enables nothing: remove every suggestion and decision marker, then save.\n";

        if ($tables === []) {
            return $header."// No tables were found, so the draft is empty. List the models in sources.eloquent.models, migrate, then run gate:classify again.\nreturn [];\n";
        }

        $body = '';

        foreach ($tables as $table => $columns) {
            $body .= '    '.var_export($table, true)." => [\n";

            foreach ($columns as $column) {
                [$class, $marker] = $this->classify($column);

                $body .= '        '.var_export($column, true).' => '.var_export($class, true).", // {$marker}\n";
            }

            $body .= "    ],\n";
        }

        return $header."\nreturn [\n{$body}];\n";
    }

    /** @return array{string, string} the class and the marker comment */
    private function classify(string $column): array
    {
        $name = strtolower($column);

        foreach (self::SUGGESTIONS as $class => $fragments) {
            foreach ($fragments as $fragment) {
                if (str_contains($name, $fragment)) {
                    return [$class, 'suggested'];
                }
            }
        }

        return ['secret', 'needs decision'];
    }
}
