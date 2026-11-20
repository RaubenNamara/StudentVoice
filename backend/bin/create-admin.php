<?php

declare(strict_types=1);

/**
 * Makes a super admin (the first account, or one more), with a temporary password printed once.
 *
 *   php backend/bin/create-admin.php --name="Your Name" --email=you@example.com
 */

if (PHP_SAPI !== 'cli') {
    exit("Run this from the command line.\n");
}

require __DIR__ . '/../bootstrap.php';

use StudentVoice\Controllers\AdminController;
use StudentVoice\Database;

$opts = getopt('', ['name:', 'email:']);
$name = trim((string) ($opts['name'] ?? ''));
$email = strtolower(trim((string) ($opts['email'] ?? '')));
if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    exit("Usage: php backend/bin/create-admin.php --name=\"Your Name\" --email=you@example.com\n");
}

$db = Database::get();
$exists = $db->prepare('SELECT 1 FROM admins WHERE email = ?');
$exists->execute([$email]);
if ($exists->fetchColumn()) {
    exit("There is already an admin with that email.\n");
}
$temp = AdminController::tempPassword();
$db->prepare("INSERT INTO admins (name, email, password_hash, role) VALUES (?, ?, ?, 'super_admin')")
    ->execute([$name, $email, password_hash($temp, PASSWORD_DEFAULT)]);

echo "Super admin created: $email\nTemporary password: $temp\nSign in with it; you will be asked to choose your own.\n";
