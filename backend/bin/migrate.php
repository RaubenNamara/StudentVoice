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

// The secret behind teacher-poll vote fingerprints (Support\Gate): made once, never changed
$envFile = __DIR__ . '/../.env';
if (is_file($envFile) && strlen((string) \StudentVoice\Config::get('APP_KEY', '')) < 32) {
    file_put_contents($envFile, "\n# Secret for anonymous teacher-poll vote marks. Never change or lose it.\nAPP_KEY=" . bin2hex(random_bytes(32)) . "\n", FILE_APPEND);
    $say('Created APP_KEY in backend/.env (keep it; never change it).');
}

$name = Database::name();
try {
    Migrator::ensureDatabase($name);
} catch (\PDOException $e) {
    $say("Could not create database $name (create it in cPanel first): " . $e->getMessage());
}
$say("Database ($name)");
Migrator::run($say) ?: $say('  up to date');
