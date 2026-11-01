<?php

declare(strict_types=1);

/**
 * Review findings that were fixed, each with the test that guards it (spec 011 FR-008, FR-009). A test carries the
 * group `finding-<key>`; tests/Unit/FindingRecordsTest.php fails when a record has no such test, or a test has no
 * record. `summary` names the rule the fix restored and the revert that was checked by hand.
 */
return [
    'refusal-lock-bump-takes-lock' => [
        'fixed' => '2026-11-01',
        'summary' => 'A refusal bump takes the credential key lock once per refusal. Reverted: bump() skips the lock; the lock-count test fails.',
    ],
    'refusal-lock-never-delays' => [
        'fixed' => '2026-11-01',
        'summary' => 'A held counter lock never delays a refusal. Reverted: block(0) becomes block(1); the timing test fails.',
    ],
    'refusal-lock-timeout-fallback' => [
        'fixed' => '2026-11-01',
        'summary' => 'A lock timeout falls back to an unlocked count and never fails the request. Reverted: the timeout is rethrown; the test errors.',
    ],
    'concrete-binding-keeps-interface' => [
        'fixed' => '2026-11-01',
        'summary' => 'Binding a concrete class does not replace the interface default. Reverted: RedactsText resolves the bound concrete class; the binding test fails.',
    ],
    'round2-bad-arguments-tool-error' => [
        'fixed' => '2026-11-01',
        'summary' => 'Bad arguments are a tool error the model can read, and the handler is not called. Reverted: the validation return is skipped; the test fails.',
    ],
    'round2-escaping-exception-reference' => [
        'fixed' => '2026-11-01',
        'summary' => 'An exception in a tool call is answered with a reference and never escapes, even under app.debug. Reverted: the catch rethrows; the test errors.',
    ],
];
