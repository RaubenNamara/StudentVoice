<?php
declare(strict_types=1);

require_once __DIR__ . '/../auth_guard.php';
require_once __DIR__ . '/../db.php';
require_admin_login();

$activeTerm = $pdo->query("SELECT id, term, year FROM terms WHERE is_active = 1 LIMIT 1")->fetch();

$totalResponses = (int) $pdo->query("SELECT COUNT(*) FROM responses")->fetchColumn();
$termResponses = 0;
if ($activeTerm) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM responses WHERE term_id = ?");
    $stmt->execute([$activeTerm['id']]);
    $termResponses = (int) $stmt->fetchColumn();
}
$totalStudents = (int) $pdo->query("SELECT COUNT(*) FROM students WHERE status = 'active'")->fetchColumn();
$totalTeachers = (int) $pdo->query("SELECT COUNT(*) FROM teachers WHERE status = 'active'")->fetchColumn();
$totalSubjects = (int) $pdo->query("SELECT COUNT(*) FROM subjects WHERE status = 'active'")->fetchColumn();

$byStream = $pdo->prepare(
    "SELECT class, stream, COUNT(*) as n FROM responses WHERE term_id = ? GROUP BY class, stream ORDER BY class, stream"
);
$streamRows = [];
if ($activeTerm) {
    $byStream->execute([$activeTerm['id']]);
    $streamRows = $byStream->fetchAll();
}

$pageTitle = 'Dashboard';
$activeNav = 'dashboard';
require __DIR__ . '/includes/header.php';
?>

<div class="sv-card">
    <h2 style="margin-top:0;color:var(--navy);">Overview</h2>
    <?php if ($activeTerm): ?>
        <p style="color:var(--muted);font-size:0.85rem;">Current active term: <strong>Term <?= (int) $activeTerm['term'] ?>, <?= (int) $activeTerm['year'] ?></strong></p>
    <?php else: ?>
        <div class="error-box">No active term set. <a href="terms.php">Set one now</a> so students can submit feedback.</div>
    <?php endif; ?>

    <div class="stat-row">
        <div class="stat-tile"><div class="num"><?= $termResponses ?></div><div class="lbl">Responses This Term</div></div>
        <div class="stat-tile"><div class="num"><?= $totalResponses ?></div><div class="lbl">Responses All-Time</div></div>
        <div class="stat-tile"><div class="num"><?= $totalStudents ?></div><div class="lbl">Active Students</div></div>
        <div class="stat-tile"><div class="num"><?= $totalTeachers ?></div><div class="lbl">Teachers</div></div>
        <div class="stat-tile"><div class="num"><?= $totalSubjects ?></div><div class="lbl">Subjects</div></div>
    </div>
</div>

<div class="sv-card">
    <h3 style="margin-top:0;color:var(--navy);">Responses by Class / Stream (this term)</h3>
    <?php if (empty($streamRows)): ?>
        <p style="color:var(--muted);font-size:0.85rem;">No responses recorded yet this term.</p>
    <?php else: ?>
        <table class="sv-data">
            <tr><th>Class</th><th>Stream</th><th>Responses</th></tr>
            <?php foreach ($streamRows as $r): ?>
                <tr>
                    <td><?= safe_text($r['class']) ?></td>
                    <td><?= safe_text($r['stream']) ?></td>
                    <td><?= (int) $r['n'] ?></td>
                </tr>
            <?php endforeach; ?>
        </table>
    <?php endif; ?>
    <p style="margin-top:14px;"><a href="reports.php" class="btn">View Full Reports &rarr;</a></p>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
