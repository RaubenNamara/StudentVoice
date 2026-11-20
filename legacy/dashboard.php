<?php
declare(strict_types=1);

require_once __DIR__ . '/auth_guard.php';
require_once __DIR__ . '/db.php';
require_student_login();

$studentId = current_student_id();
$class = $_SESSION['sv_student_class'] ?? '';
$stream = $_SESSION['sv_student_stream'] ?? '';

$activeTerm = $pdo->query("SELECT id, term, year FROM terms WHERE is_active = 1 LIMIT 1")->fetch();

$stmt = $pdo->prepare(
    "SELECT ta.teacher_id, ta.subject_id, t.name as teacher_name, s.name as subject_name
     FROM teacher_assignments ta
     JOIN teachers t ON t.id = ta.teacher_id AND t.status = 'active'
     JOIN subjects s ON s.id = ta.subject_id AND s.status = 'active'
     WHERE ta.class = ? AND ta.stream = ? AND ta.is_open = 1
     ORDER BY s.name, t.name"
);
$stmt->execute([$class, $stream]);
$assignments = $stmt->fetchAll();

$doneKeys = [];
if ($activeTerm && !empty($assignments)) {
    $doneStmt = $pdo->prepare(
        "SELECT teacher_id, subject_id FROM student_submissions WHERE student_id = ? AND term_id = ?"
    );
    $doneStmt->execute([$studentId, $activeTerm['id']]);
    foreach ($doneStmt->fetchAll() as $row) {
        $doneKeys[$row['teacher_id'] . ':' . $row['subject_id']] = true;
    }
}

$total = count($assignments);
$completed = 0;
foreach ($assignments as $a) {
    if (isset($doneKeys[$a['teacher_id'] . ':' . $a['subject_id']])) {
        $completed++;
    }
}
$pct = $total > 0 ? round(($completed / $total) * 100) : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>My Feedback | <?= safe_text(SV_SCHOOL_NAME) ?></title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
    <div class="sv-topbar">
        <div class="brand"><?= safe_text(SV_SCHOOL_NAME) ?> <span>| Students' Voice</span></div>
        <nav><a href="logout.php">Log Out</a></nav>
    </div>

    <div class="sv-wrap">
        <div class="sv-card">
            <h2 style="margin-top:0;color:var(--navy);">My Feedback</h2>
            <p style="color:var(--muted);font-size:0.85rem;">
                Class <?= safe_text($class) ?> &middot; Stream <?= safe_text($stream) ?>.
                Every answer is anonymous &mdash; nothing here is ever linked back to your name.
            </p>

            <?php if (!$activeTerm): ?>
                <div class="error-box">Feedback isn't open right now. Check back once the school opens a new term for submissions.</div>
            <?php elseif ($total === 0): ?>
                <div class="error-box">No teachers have been set up for your class/stream yet. Check back soon.</div>
            <?php else: ?>
                <div class="sv-progress-head">
                    <span><strong><?= $completed ?></strong> of <strong><?= $total ?></strong> completed</span>
                    <span><?= $pct ?>%</span>
                </div>
                <div class="sv-progress-track"><div class="sv-progress-fill" style="width:<?= $pct ?>%;"></div></div>

                <?php if ($completed === $total): ?>
                    <div class="success-box" style="margin-top:18px;">You've given feedback for every teacher this term. Thank you!</div>
                <?php endif; ?>

                <div class="sv-teacher-list">
                    <?php foreach ($assignments as $a):
                        $key = $a['teacher_id'] . ':' . $a['subject_id'];
                        $isDone = isset($doneKeys[$key]);
                    ?>
                        <div class="sv-teacher-card <?= $isDone ? 'done' : '' ?>">
                            <div class="info">
                                <div class="subject"><?= safe_text($a['subject_name']) ?></div>
                                <div class="teacher"><?= safe_text($a['teacher_name']) ?></div>
                            </div>
                            <?php if ($isDone): ?>
                                <span class="badge outstanding">&#10003; Done</span>
                            <?php else: ?>
                                <a class="btn" href="form.php?teacher_id=<?= (int) $a['teacher_id'] ?>&subject_id=<?= (int) $a['subject_id'] ?>">Give Feedback &rarr;</a>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
