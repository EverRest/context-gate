<?php

/**
 * Holds the configuration lock (the `.lock` file ConfigVersionStore flocks) for a number of seconds, so a save in
 * another process must wait. Prints "locked" once the lock is held and "released" after it is let go. Usage:
 *
 *   php config-lock-holder.php <lock-file> <seconds>
 */

declare(strict_types=1);

$handle = fopen($_SERVER['argv'][1], 'c');

if ($handle === false) {
    exit(1);
}

flock($handle, LOCK_EX);
fwrite(STDOUT, "locked\n");
usleep((int) ((float) $_SERVER['argv'][2] * 1_000_000));
flock($handle, LOCK_UN);
fclose($handle);
fwrite(STDOUT, "released\n");
