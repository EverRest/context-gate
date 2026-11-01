<?php

declare(strict_types=1);

/**
 * The security scope of the mutation run (spec 011 FR-002): the paths whose mutants count toward the minimum score.
 * A directory ends with "/". scripts/mutation.php passes these to Infection; scripts/check-mutation-config.php
 * checks that every required security path is present and exists.
 */
return [
    'src/Pipeline/',
    'src/Privacy/',
    'src/Http/',
    'src/Source/Fetch/',
    'src/Source/DocsPathPolicy.php',
    'src/Source/ExamplePolicy.php',
    'src/Source/SensitiveNames.php',
    'src/Server/PolicyStep.php',
    'src/Server/Admission.php',
    'src/Server/ArgumentsStep.php',
    'src/Policy/',
];
