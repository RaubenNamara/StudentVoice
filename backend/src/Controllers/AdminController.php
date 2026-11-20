<?php

declare(strict_types=1);

namespace StudentVoice\Controllers;

use StudentVoice\Auth\Auth;
use StudentVoice\Database;
use StudentVoice\Http\Request;
use StudentVoice\Http\Response;

/**
 * Super admins manage the admin accounts. New accounts and resets get a random temporary
 * password, shown once, which the person must replace when they sign in.
 */
final class AdminController
{
    public function index(Request $r): never
    {
        Response::ok(Database::get()->query('SELECT id, name, email, role, status, must_change_password, last_login_at, created_at FROM admins ORDER BY name')->fetchAll());
    }

    public function store(Request $r): never
    {
        $name = trim($r->string('name'));
        $email = strtolower($r->string('email'));
        $role = $r->string('role') === 'super_admin' ? 'super_admin' : 'admin';
        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            Response::error('Enter a name and a valid email.', 422);
        }
        $db = Database::get();
        $exists = $db->prepare('SELECT 1 FROM admins WHERE email = ?');
        $exists->execute([$email]);
        if ($exists->fetchColumn()) {
            Response::error('There is already an admin with that email.', 422, ['email' => 'Already used']);
        }
        $temp = self::tempPassword();
        $db->prepare('INSERT INTO admins (name, email, password_hash, role) VALUES (?, ?, ?, ?)')
            ->execute([mb_substr($name, 0, 150), $email, password_hash($temp, PASSWORD_DEFAULT), $role]);
        Response::ok(['id' => (int) $db->lastInsertId(), 'temporary_password' => $temp], 201);
    }

    public function update(Request $r): never
    {
        $id = (int) $r->param('id');
        if ($id === Auth::adminId()) {
            Response::error('You cannot change your own role or switch yourself off.', 422);
        }
        $role = $r->string('role') === 'super_admin' ? 'super_admin' : 'admin';
        $status = $r->string('status') === 'disabled' ? 'disabled' : 'active';
        Database::get()->prepare('UPDATE admins SET role = ?, status = ? WHERE id = ?')->execute([$role, $status, $id]);
        Response::ok();
    }

    public function resetPassword(Request $r): never
    {
        $id = (int) $r->param('id');
        if ($id === Auth::adminId()) {
            Response::error('Change your own password from your account menu.', 422);
        }
        $temp = self::tempPassword();
        Database::get()->prepare('UPDATE admins SET password_hash = ?, must_change_password = 1, password_changed_at = NOW(), failed_logins = 0, locked_until = NULL WHERE id = ?')
            ->execute([password_hash($temp, PASSWORD_DEFAULT), $id]);
        Response::ok(['temporary_password' => $temp]);
    }

    public static function tempPassword(): string
    {
        $chars = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKMNPQRSTUVWXYZ';
        $out = '';
        for ($i = 0; $i < 6; $i++) {
            $out .= $chars[random_int(0, strlen($chars) - 1)];
        }
        return $out . random_int(10, 99) . $chars[random_int(0, strlen($chars) - 1)] . random_int(1, 9);
    }
}
