<?php
declare(strict_types=1);

require_once __DIR__ . '/auth_guard.php';
require_student_login();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Thank You | <?= safe_text(SV_SCHOOL_NAME) ?></title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
    <div class="sv-center">
        <div class="sv-login-card" style="text-align:center;">
            <div style="font-size:2.5rem;margin-bottom:10px;">&#10003;</div>
            <div class="logo">Thank You</div>
            <p style="color:var(--muted);font-size:0.9rem;margin:16px 0 24px;">
                Your feedback has been submitted anonymously and will help improve teaching and learning
                at <?= safe_text(SV_SCHOOL_NAME) ?>.
            </p>
            <a href="dashboard.php" class="btn block">Back to My Feedback List</a>
            <a href="logout.php" class="btn secondary block" style="margin-top:10px;">Log Out</a>
        </div>
    </div>
</body>
</html>
