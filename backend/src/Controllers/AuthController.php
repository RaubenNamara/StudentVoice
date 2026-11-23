<?php

declare(strict_types=1);

namespace StudentVoice\Controllers;

use StudentVoice\Auth\Auth;
use StudentVoice\Auth\Throttle;
use StudentVoice\Config;
use StudentVoice\Database;
use StudentVoice\Http\Request;
use StudentVoice\Http\Response;
use StudentVoice\Support\VoterCode;

final class AuthController
{
    /** Who is signed in (admin and/or student), the school's name and the CSRF token */
    public function me(Request $r): never
    {
        Response::ok([
            'school' => (string) Config::get('SCHOOL_NAME', "St. Mark's College Namagoma"),
            // how students get in: voting numbers (if on) and/or from their Tusome dashboard
            'voting_numbers' => \StudentVoice\Support\Settings::votingNumbers(),
            'tusome_url' => \StudentVoice\Support\Settings::tusomeLinked() ? \StudentVoice\Support\Settings::tusomeUrl() : '',
            'csrf' => Auth::csrfToken(),
            'admin' => self::adminView(Auth::admin()),
            'student' => Auth::student(),
        ]);
    }

    public static function adminView(?array $a): ?array
    {
        if (!$a) {
            return null;
        }
        return [
            'id' => (int) $a['id'],
            'name' => $a['name'],
            'email' => $a['email'],
            'role' => $a['role'],
            'must_change_password' => (bool) $a['must_change_password'],
        ];
    }

    public function adminLogin(Request $r): never
    {
        Throttle::check('admin');
        $email = strtolower($r->string('email'));
        $password = (string) $r->input('password', '');
        if ($email === '' || $password === '') {
            Response::error('Enter your email and password.', 422);
        }
        $db = Database::get();
        $stmt = $db->prepare('SELECT * FROM admins WHERE email = ?');
        $stmt->execute([$email]);
        $admin = $stmt->fetch();

        if ($admin && $admin['locked_until'] && strtotime($admin['locked_until']) > time()) {
            Response::error('Too many wrong passwords. This account is locked for a few minutes.', 429);
        }
        if (!$admin || !password_verify($password, $admin['password_hash'])) {
            Throttle::fail('admin');
            if ($admin) {
                $fails = (int) $admin['failed_logins'] + 1;
                $db->prepare('UPDATE admins SET failed_logins = ?, locked_until = ? WHERE id = ?')
                    ->execute([$fails >= 5 ? 0 : $fails, $fails >= 5 ? date('Y-m-d H:i:s', time() + 900) : null, $admin['id']]);
            }
            Response::error('That email and password do not match.', 422);
        }
        if ($admin['status'] !== 'active') {
            Response::error('This account is disabled. Ask a super admin.', 403);
        }
        $db->prepare('UPDATE admins SET failed_logins = 0, locked_until = NULL, last_login_at = NOW() WHERE id = ?')->execute([$admin['id']]);
        Auth::loginAdmin($admin);
        Response::ok(['admin' => self::adminView(Auth::admin()), 'csrf' => Auth::csrfToken()]);
    }

    public function adminLogout(Request $r): never
    {
        Auth::logoutAdmin();
        Response::ok();
    }

    public function changePassword(Request $r): never
    {
        $admin = Auth::admin();
        $current = (string) $r->input('current_password', '');
        $new = (string) $r->input('new_password', '');
        if (!password_verify($current, $admin['password_hash'])) {
            Response::error('Your current password is not right.', 422, ['current_password' => 'Not right']);
        }
        if ($problem = self::passwordProblem($new, $admin['email'])) {
            Response::error($problem, 422, ['new_password' => $problem]);
        }
        if (password_verify($new, $admin['password_hash'])) {
            Response::error('Choose a password different from the one you have now.', 422, ['new_password' => 'Same as before']);
        }
        Database::get()->prepare('UPDATE admins SET password_hash = ?, must_change_password = 0, password_changed_at = NOW() WHERE id = ?')
            ->execute([password_hash($new, PASSWORD_DEFAULT), $admin['id']]);
        Auth::refreshAdmin();
        Response::ok(['admin' => self::adminView(Auth::admin())]);
    }

    public static function passwordProblem(string $password, string $email): ?string
    {
        if (strlen($password) < 8) {
            return 'Use at least 8 characters.';
        }
        if (!preg_match('/[A-Za-z]/', $password) || !preg_match('/\d/', $password)) {
            return 'Use letters and numbers.';
        }
        if (stripos($password, explode('@', $email)[0]) !== false) {
            return 'Do not use your email in your password.';
        }
        return null;
    }

    /** A student signs in with their registration number (or a slip's voting number) */
    public function studentLogin(Request $r): never
    {
        if (!\StudentVoice\Support\Settings::votingNumbers()) {
            Response::error('Signing in here is switched off. Open Student Voice from your Tusome dashboard.', 403, ['code' => 'use_tusome']);
        }
        Throttle::check('student');
        // the registration number (as in SMIS / Tusome), or the voting number from a slip
        $typed = trim($r->string('code'));
        if ($typed === '') {
            Response::error('Type your registration number.', 422);
        }
        $db = Database::get();
        $stmt = $db->prepare('SELECT id, status FROM students WHERE UPPER(TRIM(reg_no)) = ? ORDER BY status = \'active\' DESC LIMIT 1');
        $stmt->execute([\StudentVoice\Services\SmisSync::key($typed)]);
        $student = $stmt->fetch();
        if (!$student) {
            $code = VoterCode::normalise($typed);
            if (VoterCode::complete($code)) {
                $stmt = $db->prepare('SELECT id, status FROM students WHERE voter_code = ?');
                $stmt->execute([$code]);
                $student = $stmt->fetch();
            }
        }
        if (!$student) {
            Throttle::fail('student');
            Response::error('That registration number was not found. Check it and try again, like 22-09390.', 422);
        }
        if ($student['status'] !== 'active') {
            Response::error('This registration number is not active in Student Voice. See the person running the vote.', 403);
        }
        Auth::loginStudent((int) $student['id']);
        Response::ok(['student' => Auth::student(), 'csrf' => Auth::csrfToken()]);
    }

    public function studentLogout(Request $r): never
    {
        Auth::logoutStudent();
        Response::ok();
    }
}
