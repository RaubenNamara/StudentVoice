<?php
declare(strict_types=1);

require_once __DIR__ . '/auth_guard.php';
require_once __DIR__ . '/db.php';

if (isset($_SESSION['sv_student_id'])) {
    header("Location: " . SV_BASE . "/dashboard.php");
    exit();
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $studentNo = trim($_POST['student_no'] ?? '');

    if ($studentNo === '') {
        $error = 'Please enter your student number.';
    } else {
        $stmt = $pdo->prepare("SELECT id, class, stream, status FROM students WHERE student_no = ?");
        $stmt->execute([$studentNo]);
        $student = $stmt->fetch();

        if (!$student) {
            $error = 'That student number was not found. Check with the school administrator if you believe this is an error.';
        } elseif ($student['status'] !== 'active') {
            $error = 'This student account is disabled. See the school administrator.';
        } else {
            session_regenerate_id(true);
            $_SESSION['sv_student_id'] = (int) $student['id'];
            $_SESSION['sv_student_class'] = $student['class'];
            $_SESSION['sv_student_stream'] = $student['stream'];
            header("Location: " . SV_BASE . "/dashboard.php");
            exit();
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
    <title>Students' Voice | <?= safe_text(SV_SCHOOL_NAME) ?></title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
    <div class="sv-center">
        <div class="sv-login-card">
            <div class="logo"><?= safe_text(SV_SCHOOL_NAME) ?></div>
            <div class="sub">Students' Voice Feedback</div>

            <?php if ($error): ?>
                <div class="error-box"><?= safe_text($error) ?></div>
            <?php endif; ?>

            <form method="POST" autocomplete="off">
                <label>Student Number</label>
                <input type="text" name="student_no" required class="form-control" placeholder="e.g. SMN-2026-014" autofocus>

                <button type="submit" class="btn block">Continue</button>
            </form>

            <div class="hint">
                Your login only confirms you are an enrolled student. Your answers are never linked back to your name --
                your feedback stays anonymous.
            </div>
        </div>
    </div>
</body>
</html>
