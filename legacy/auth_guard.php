<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| STUDENTS' VOICE -- SESSION ACCESS GUARD
|--------------------------------------------------------------------------
| Two independent guards sharing one session: require_student_login() for
| the feedback form, require_admin_login() for the /admin reports side.
| A student session never grants admin access and vice versa -- there is
| no role field to spoof, just two separate session keys.
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/config.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start([
        'cookie_httponly' => true,
        'cookie_samesite' => 'Strict',
    ]);
}

function require_student_login(): void
{
    if (!isset($_SESSION['sv_student_id'])) {
        header("Location: " . SV_BASE . "/login.php");
        exit();
    }
}

function current_student_id(): int
{
    return (int) ($_SESSION['sv_student_id'] ?? 0);
}

function require_admin_login(): void
{
    if (!isset($_SESSION['sv_admin_id'])) {
        header("Location: " . SV_BASE . "/admin/login.php");
        exit();
    }
}

function current_admin_name(): string
{
    return $_SESSION['sv_admin_name'] ?? '';
}
