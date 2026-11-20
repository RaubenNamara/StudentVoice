<?php

declare(strict_types=1);

/**
 * Sets an admin's password, or makes a new super admin with it if the email isn't an admin yet.
 * For getting back in when nobody can sign in. The password is used as given (no forced change)
 * and the account is switched on and unlocked.
 *
 *   php backend/bin/set-admin-password.php --email=you@example.com --password="..." [--name="Your Name"]
 */

if (PHP_SAPI !== 'cli') {
    exit("Run this from the command line.\n");
}

require __DIR__ . '/../bootstrap.php';

use StudentVoice\Controllers\AuthController;
use StudentVoice\Database;

$opts = getopt('', ['email:', 'password:', 'name:']);
$email = strtolower(trim((string) ($opts['email'] ?? '')));
$password = (string) ($opts['password'] ?? '');
$name = trim((string) ($opts['name'] ?? ''));
if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $password === '') {
    exit("Usage: php backend/bin/set-admin-password.php --email=you@example.com --password=\"...\" [--name=\"Your Name\"]\n");
}
if ($problem = AuthController::passwordProblem($password, $email)) {
    exit("That password won't do: $problem\n");
}

$db = Database::get();
$hash = password_hash($password, PASSWORD_DEFAULT);
$find = $db->prepare('SELECT id, name, role FROM admins WHERE email = ?');
$find->execute([$email]);
$admin = $find->fetch();

if ($admin) {
    $db->prepare("UPDATE admins SET password_hash = ?, must_change_password = 0, password_changed_at = NOW(), status = 'active', failed_logins = 0, locked_until = NULL WHERE id = ?")
        ->execute([$hash, $admin['id']]);
    echo "Password set for {$admin['name']} ($email, " . ($admin['role'] === 'super_admin' ? 'super admin' : 'admin') . ").\n";
} else {
    $db->prepare("INSERT INTO admins (name, email, password_hash, role, must_change_password, password_changed_at) VALUES (?, ?, ?, 'super_admin', 0, NOW())")
        ->execute([$name !== '' ? $name : explode('@', $email)[0], $email, $hash]);
    echo "Super admin created: $email\n";
}
echo "Sign in at /admin with that email and password.\n";
