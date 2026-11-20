<?php
declare(strict_types=1);

require_once __DIR__ . '/../auth_guard.php';
require_once __DIR__ . '/../db.php';
require_admin_login();

function sv_band(float $avg): array
{
    if ($avg >= 4.4) return ['Outstanding', 'outstanding'];
    if ($avg >= 3.7) return ['Very Good', 'verygood'];
    if ($avg >= 3.1) return ['Good', 'good'];
    if ($avg >= 2.5) return ['Fair', 'fair'];
    return ['Below Expectation', 'below'];
}

$teachers = $pdo->query("SELECT id, name FROM teachers ORDER BY name")->fetchAll();
$subjects = $pdo->query("SELECT id, name FROM subjects ORDER BY name")->fetchAll();
$terms = $pdo->query("SELECT id, term, year FROM terms ORDER BY year DESC, term DESC")->fetchAll();
$classes = $pdo->query("SELECT DISTINCT class FROM responses ORDER BY class")->fetchAll(PDO::FETCH_COLUMN);
$streams = $pdo->query("SELECT DISTINCT stream FROM responses ORDER BY stream")->fetchAll(PDO::FETCH_COLUMN);

$fTeacher = (int) ($_GET['teacher_id'] ?? 0);
$fSubject = (int) ($_GET['subject_id'] ?? 0);
$fTerm = (int) ($_GET['term_id'] ?? 0);
$fClass = trim($_GET['class'] ?? '');
$fStream = trim($_GET['stream'] ?? '');

$where = [];
$params = [];
if ($fTeacher > 0) { $where[] = 'teacher_id = ?'; $params[] = $fTeacher; }
if ($fSubject > 0) { $where[] = 'subject_id = ?'; $params[] = $fSubject; }
if ($fTerm > 0) { $where[] = 'term_id = ?'; $params[] = $fTerm; }
if ($fClass !== '') { $where[] = 'class = ?'; $params[] = $fClass; }
if ($fStream !== '') { $where[] = 'stream = ?'; $params[] = $fStream; }
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$qCols = implode(', ', array_map(fn($i) => "AVG(q$i) as q$i", range(1, 20)));
$stmt = $pdo->prepare("SELECT COUNT(*) as n, $qCols FROM responses $whereSql");
$stmt->execute($params);
$agg = $stmt->fetch();

$n = (int) ($agg['n'] ?? 0);
$sections = sv_sections();
$statementAverages = [];
$sectionAverages = [];
$overallAvg = 0.0;

if ($n > 0) {
    foreach ($sections as $sectionTitle => $statements) {
        $sum = 0.0;
        $count = 0;
        foreach ($statements as $qNum => $text) {
            $avg = (float) $agg["q$qNum"];
            $statementAverages[$qNum] = ['text' => $text, 'avg' => $avg];
            $sum += $avg;
            $count++;
        }
        $sectionAverages[$sectionTitle] = $sum / $count;
    }
    $overallAvg = array_sum(array_column($statementAverages, 'avg')) / count($statementAverages);
}

$strengths = $statementAverages;
uasort($strengths, fn($a, $b) => $b['avg'] <=> $a['avg']);
$strengths = array_slice($strengths, 0, 3, true);

$improvements = $statementAverages;
uasort($improvements, fn($a, $b) => $a['avg'] <=> $b['avg']);
$improvements = array_slice($improvements, 0, 3, true);

$commentStmt = $pdo->prepare(
    "SELECT class, stream, comment_good, comment_improve, comment_suggestions, submitted_at FROM responses $whereSql
     " . ($where ? 'AND' : 'WHERE') . " (comment_good IS NOT NULL OR comment_improve IS NOT NULL OR comment_suggestions IS NOT NULL)
     ORDER BY submitted_at DESC LIMIT 100"
);
$commentStmt->execute($params);
$comments = $commentStmt->fetchAll();

// Trend across terms -- only meaningful once a specific teacher+subject is chosen.
$trend = [];
if ($fTeacher > 0 && $fSubject > 0) {
    $tStmt = $pdo->prepare(
        "SELECT t.term, t.year, COUNT(*) as n, " . implode(' + ', array_map(fn($i) => "AVG(r.q$i)", range(1, 20))) . " / 20 as overall_avg
         FROM responses r JOIN terms t ON r.term_id = t.id
         WHERE r.teacher_id = ? AND r.subject_id = ?
         GROUP BY t.id ORDER BY t.year, t.term"
    );
    $tStmt->execute([$fTeacher, $fSubject]);
    $trend = $tStmt->fetchAll();
}

$pageTitle = 'Reports';
$activeNav = 'reports';
require __DIR__ . '/includes/header.php';
?>

<div class="sv-card">
    <h2 style="margin-top:0;color:var(--navy);">Feedback Report</h2>
    <form method="GET" class="filters">
        <div class="field">
            <label>Teacher</label>
            <select name="teacher_id" class="form-control">
                <option value="0">All Teachers</option>
                <?php foreach ($teachers as $t): ?>
                    <option value="<?= (int) $t['id'] ?>" <?= $fTeacher === (int) $t['id'] ? 'selected' : '' ?>><?= safe_text($t['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <label>Subject</label>
            <select name="subject_id" class="form-control">
                <option value="0">All Subjects</option>
                <?php foreach ($subjects as $s): ?>
                    <option value="<?= (int) $s['id'] ?>" <?= $fSubject === (int) $s['id'] ? 'selected' : '' ?>><?= safe_text($s['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <label>Term</label>
            <select name="term_id" class="form-control">
                <option value="0">All Terms</option>
                <?php foreach ($terms as $t): ?>
                    <option value="<?= (int) $t['id'] ?>" <?= $fTerm === (int) $t['id'] ? 'selected' : '' ?>>Term <?= (int) $t['term'] ?>, <?= (int) $t['year'] ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <label>Class</label>
            <select name="class" class="form-control">
                <option value="">All Classes</option>
                <?php foreach ($classes as $c): ?>
                    <option value="<?= safe_text($c) ?>" <?= $fClass === $c ? 'selected' : '' ?>><?= safe_text($c) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <label>Stream</label>
            <select name="stream" class="form-control">
                <option value="">All Streams</option>
                <?php foreach ($streams as $s): ?>
                    <option value="<?= safe_text($s) ?>" <?= $fStream === $s ? 'selected' : '' ?>><?= safe_text($s) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field"><button type="submit" class="btn">Filter</button></div>
    </form>
</div>

<?php if ($n === 0): ?>
    <div class="sv-card"><p style="color:var(--muted);">No responses match this filter yet.</p></div>
<?php else: ?>

    <div class="sv-card">
        <div class="stat-row">
            <div class="stat-tile"><div class="num"><?= $n ?></div><div class="lbl">Responses</div></div>
            <div class="stat-tile"><div class="num"><?= number_format($overallAvg, 2) ?> / 5</div><div class="lbl">Average Rating</div></div>
            <div class="stat-tile"><div class="num"><?= number_format($overallAvg * 20, 1) ?> / 100</div><div class="lbl">Total Score</div></div>
            <div class="stat-tile">
                <?php [$bandLabel, $bandClass] = sv_band($overallAvg); ?>
                <div class="badge <?= $bandClass ?>" style="font-size:0.85rem;"><?= $bandLabel ?></div>
                <div class="lbl" style="margin-top:6px;">Performance Band</div>
            </div>
        </div>
    </div>

    <div class="sv-card">
        <h3 style="margin-top:0;color:var(--navy);">Section Averages</h3>
        <table class="sv-data">
            <tr><th>Section</th><th>Average</th><th>Band</th></tr>
            <?php foreach ($sectionAverages as $title => $avg): [$l, $c] = sv_band($avg); ?>
                <tr>
                    <td><?= safe_text($title) ?></td>
                    <td><?= number_format($avg, 2) ?></td>
                    <td><span class="badge <?= $c ?>"><?= $l ?></span></td>
                </tr>
            <?php endforeach; ?>
        </table>
    </div>

    <div class="sv-card">
        <h3 style="margin-top:0;color:var(--success);">Strength Areas Identified</h3>
        <table class="sv-data">
            <?php foreach ($strengths as $qNum => $s): ?>
                <tr><td><?= $qNum ?>. <?= safe_text($s['text']) ?></td><td style="width:80px;text-align:right;"><strong><?= number_format($s['avg'], 2) ?></strong></td></tr>
            <?php endforeach; ?>
        </table>
    </div>

    <div class="sv-card">
        <h3 style="margin-top:0;color:var(--danger);">Improvement Areas Identified</h3>
        <table class="sv-data">
            <?php foreach ($improvements as $qNum => $s): ?>
                <tr><td><?= $qNum ?>. <?= safe_text($s['text']) ?></td><td style="width:80px;text-align:right;"><strong><?= number_format($s['avg'], 2) ?></strong></td></tr>
            <?php endforeach; ?>
        </table>
        <?php if ($overallAvg < 3.1): ?>
            <p style="margin-top:10px;font-size:0.85rem;color:var(--danger);"><strong>Action Plan Recommended</strong> &mdash; overall rating falls below "Good". Consider mentoring, peer observation, or coaching.</p>
        <?php endif; ?>
    </div>

    <div class="sv-card">
        <h3 style="margin-top:0;color:var(--navy);">All 20 Statements</h3>
        <?php foreach ($sections as $sectionTitle => $statements): ?>
            <div class="sv-section-title" style="border-bottom-color:var(--border);font-size:0.85rem;"><?= safe_text($sectionTitle) ?></div>
            <table class="sv-data">
                <?php foreach ($statements as $qNum => $text): $avg = $statementAverages[$qNum]['avg']; [$l, $c] = sv_band($avg); ?>
                    <tr>
                        <td><?= $qNum ?>. <?= safe_text($text) ?></td>
                        <td style="width:70px;"><?= number_format($avg, 2) ?></td>
                        <td style="width:130px;"><span class="badge <?= $c ?>"><?= $l ?></span></td>
                    </tr>
                <?php endforeach; ?>
            </table>
        <?php endforeach; ?>
    </div>

    <?php if (!empty($trend)): ?>
    <div class="sv-card">
        <h3 style="margin-top:0;color:var(--navy);">Trend Across Terms (this teacher + subject)</h3>
        <table class="sv-data">
            <tr><th>Term</th><th>Responses</th><th>Average Rating</th><th>Band</th></tr>
            <?php foreach ($trend as $row): [$l, $c] = sv_band((float) $row['overall_avg']); ?>
                <tr>
                    <td>Term <?= (int) $row['term'] ?>, <?= (int) $row['year'] ?></td>
                    <td><?= (int) $row['n'] ?></td>
                    <td><?= number_format((float) $row['overall_avg'], 2) ?></td>
                    <td><span class="badge <?= $c ?>"><?= $l ?></span></td>
                </tr>
            <?php endforeach; ?>
        </table>
    </div>
    <?php endif; ?>

    <div class="sv-card">
        <h3 style="margin-top:0;color:var(--navy);">Open-Ended Comments <span style="font-weight:400;color:var(--muted);font-size:0.75rem;">(anonymous)</span></h3>
        <?php if (empty($comments)): ?>
            <p style="color:var(--muted);font-size:0.85rem;">No written comments in this filter.</p>
        <?php else: ?>
            <?php foreach ($comments as $c): ?>
                <div class="comment-box">
                    <div style="color:var(--muted);font-size:0.7rem;text-transform:uppercase;margin-bottom:6px;"><?= safe_text($c['class']) ?> &middot; <?= safe_text($c['stream']) ?></div>
                    <?php if ($c['comment_good']): ?><p style="margin:4px 0;"><strong>Does well:</strong> <?= nl2br(safe_text($c['comment_good'])) ?></p><?php endif; ?>
                    <?php if ($c['comment_improve']): ?><p style="margin:4px 0;"><strong>Could improve:</strong> <?= nl2br(safe_text($c['comment_improve'])) ?></p><?php endif; ?>
                    <?php if ($c['comment_suggestions']): ?><p style="margin:4px 0;"><strong>Suggestion:</strong> <?= nl2br(safe_text($c['comment_suggestions'])) ?></p><?php endif; ?>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
