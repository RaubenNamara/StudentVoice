<?php

declare(strict_types=1);

/**
 * Brings the database up to date (creates it first if this MySQL user may).
 *
 *   php backend/bin/migrate.php
 *
 * Run it after every pull - a coworker's new tables only exist once this has run.
 */

if (PHP_SAPI !== 'cli') {
    exit("Run this from the command line.\n");
}

require __DIR__ . '/../bootstrap.php';

use StudentVoice\Database;
use StudentVoice\Support\Migrator;

$say = fn (string $line) => print($line . PHP_EOL);

$name = Database::name();
try {
    Migrator::ensureDatabase($name);
} catch (\PDOException $e) {
    $say("Could not create database $name (create it in cPanel first): " . $e->getMessage());
}
$say("Database ($name)");
Migrator::run($say) ?: $say('  up to date');
