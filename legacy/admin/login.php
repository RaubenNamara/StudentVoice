<?php
declare(strict_types=1);

require_once __DIR__ . '/../auth_guard.php';
require_once __DIR__ . '/../db.php';

if (isset($_SESSION['sv_admin_id'])) {
    header("Location: " . SV_BASE . "/admin/dashboard.php");
    exit();
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = trim($_POST['password'] ?? '');

    if ($email === '' || $password === '') {
        $error = 'Please provide both email and password.';
    } else {
        $stmt = $pdo->prepare("SELECT id, name, email, password_hash, status FROM admins WHERE email = ?");
        $stmt->execute([$email]);
        $admin = $stmt->fetch();

        if ($admin && password_verify($password, $admin['password_hash'])) {
            if ($admin['status'] !== 'active') {
                $error = 'This admin account is disabled.';
            } else {
                session_regenerate_id(true);
                $_SESSION['sv_admin_id'] = (int) $admin['id'];
                $_SESSION['sv_admin_name'] = $admin['name'];
                header("Location: " . SV_BASE . "/admin/dashboard.php");
                exit();
            }
        } else {
            $error = 'Invalid email or password.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Admin Login | Students' Voice</title>
    <link rel="stylesheet" href="../assets/style.css">
</head>
<body>
    <div class="sv-center">
        <div class="sv-login-card">
            <div class="logo"><?= safe_text(SV_SCHOOL_NAME) ?></div>
            <div class="sub">Students' Voice &mdash; Admin</div>

            <?php if ($error): ?>
                <div class="error-box"><?= safe_text($error) ?></div>
            <?php endif; ?>

            <form method="POST" autocomplete="off">
                <label>Email</label>
                <input type="email" name="email" required class="form-control" placeholder="admin@stmarksnamagoma.local" autofocus>

                <label>Password</label>
                <input type="password" name="password" required class="form-control" placeholder="********">

                <button type="submit" class="btn block">Log In</button>
            </form>
        </div>
    </div>
</body>
</html>
