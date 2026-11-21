<?php

declare(strict_types=1);

namespace StudentVoice\Auth;

use StudentVoice\Config;
use StudentVoice\Database;

/**
 * Two kinds of sign-in share one session cookie but never each other's rights:
 * - an admin (email + password): runs polls, students and results
 * - a student (their voting number): sees and answers the polls open to their class
 *
 * The session holds only ids; the row is re-read every request, so a disabled admin or a student
 * who has left the school is out at once.
 */
final class Auth
{
    private static array|false|null $admin = null;
    private static array|false|null $student = null;

    public static function startSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        $dir = __DIR__ . '/../../storage/sessions';
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        if (is_dir($dir) && is_writable($dir)) {
            session_save_path($dir);
            // Our own folder, so the host's session cleanup never reaches it (cPanel ships with
            // gc_probability = 0). Clear files idle longer than any sign-in can last on about 1 in
            // 200 requests: thousands of students signing in would otherwise leave a file each, forever.
            $idle = max((int) Config::get('SESSION_IDLE_MINUTES', 30), (int) Config::get('STUDENT_IDLE_MINUTES', 15));
            ini_set('session.gc_maxlifetime', (string) max(3600, ($idle + 30) * 60));
            ini_set('session.gc_probability', '1');
            ini_set('session.gc_divisor', '200');
        }
        session_name('studentvoice_session');
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => Config::isProduction(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();

        $now = time();
        $adminIdle = (int) Config::get('SESSION_IDLE_MINUTES', 30) * 60;
        $studentIdle = (int) Config::get('STUDENT_IDLE_MINUTES', 15) * 60;
        if (isset($_SESSION['admin_id']) && $now - (int) ($_SESSION['admin_seen'] ?? 0) > $adminIdle) {
            unset($_SESSION['admin_id'], $_SESSION['admin_at']);
        }
        if (isset($_SESSION['student_id']) && $now - (int) ($_SESSION['student_seen'] ?? 0) > $studentIdle) {
            unset($_SESSION['student_id']);
        }
        if (isset($_SESSION['admin_id'])) {
            $_SESSION['admin_seen'] = $now;
        }
        if (isset($_SESSION['student_id'])) {
            $_SESSION['student_seen'] = $now;
        }
    }

    public static function admin(): ?array
    {
        if (self::$admin === null) {
            self::$admin = false;
            if (isset($_SESSION['admin_id'])) {
                $stmt = Database::get()->prepare('SELECT * FROM admins WHERE id = ?');
                $stmt->execute([$_SESSION['admin_id']]);
                $row = $stmt->fetch();
                $stale = $row && $row['password_changed_at'] && strtotime($row['password_changed_at']) > (int) ($_SESSION['admin_at'] ?? 0);
                if ($row && $row['status'] === 'active' && !$stale) {
                    self::$admin = $row;
                } else {
                    unset($_SESSION['admin_id']);
                }
            }
        }
        return self::$admin ?: null;
    }

    public static function adminId(): ?int
    {
        return self::admin() ? (int) self::admin()['id'] : null;
    }

    public static function student(): ?array
    {
        if (self::$student === null) {
            self::$student = false;
            if (isset($_SESSION['student_id'])) {
                $stmt = Database::get()->prepare("SELECT id, reg_no, name, class, stream FROM students WHERE id = ? AND status = 'active'");
                $stmt->execute([$_SESSION['student_id']]);
                $row = $stmt->fetch();
                if ($row) {
                    // came in from Tusome (signed in there): when they finish, they go back to Tusome
                    $row['via'] = ($_SESSION['student_via'] ?? '') === 'tusome' ? 'tusome' : 'code';
                    self::$student = $row;
                } else {
                    unset($_SESSION['student_id']);
                }
            }
        }
        return self::$student ?: null;
    }

    public static function loginAdmin(array $admin): void
    {
        session_regenerate_id(true);
        $_SESSION['admin_id'] = (int) $admin['id'];
        $_SESSION['admin_at'] = time();
        $_SESSION['admin_seen'] = time();
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
        self::$admin = null;
    }

    public static function loginStudent(int $studentId, string $via = 'code'): void
    {
        session_regenerate_id(true);
        $_SESSION['student_id'] = $studentId;
        $_SESSION['student_via'] = $via;
        $_SESSION['student_seen'] = time();
        $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
        self::$student = null;
    }

    public static function logoutAdmin(): void
    {
        unset($_SESSION['admin_id'], $_SESSION['admin_at'], $_SESSION['admin_seen']);
        self::$admin = false;
    }

    public static function logoutStudent(): void
    {
        unset($_SESSION['student_id'], $_SESSION['student_seen'], $_SESSION['student_via']);
        self::$student = false;
    }

    public static function csrfToken(): string
    {
        return $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
    }

    /** After an admin changes their own password: this session stays valid, others end */
    public static function refreshAdmin(): void
    {
        self::$admin = null;
        $_SESSION['admin_at'] = time() + 1;
    }
}
