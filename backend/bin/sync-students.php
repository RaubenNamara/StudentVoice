<?php

declare(strict_types=1);

/**
 * Syncs the roster from SMIS (the same as the button on the Students page). Can run nightly
 * from cron:   php backend/bin/sync-students.php
 */

if (PHP_SAPI !== 'cli') {
    exit("Run this from the command line.\n");
}

require __DIR__ . '/../bootstrap.php';

use StudentVoice\Database;
use StudentVoice\Services\SmisSync;

$result = (new SmisSync(Database::get()))->run(null);
echo $result['message'] . PHP_EOL;
exit($result['ok'] ? 0 : 1);
