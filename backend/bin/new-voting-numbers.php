<?php

declare(strict_types=1);

/**
 * Gives every student a new voting number (the same as Students > "New numbers for everyone").
 * Old numbers and printed slips stop working; votes already cast still count.
 *
 *   php backend/bin/new-voting-numbers.php --yes
 */

if (PHP_SAPI !== 'cli') {
    exit("Run this from the command line.\n");
}

require __DIR__ . '/../bootstrap.php';

use StudentVoice\Controllers\StudentController;
use StudentVoice\Database;

if (!in_array('--yes', $argv, true)) {
    exit("This gives EVERY student a new voting number; old slips stop working.\nRun again with --yes to do it.\n");
}
$n = StudentController::resetAllCodes(Database::get());
echo "$n students have new voting numbers. Print new slips from Students > Print voting slips.\n";
