<?php
declare(strict_types=1);

require_once __DIR__ . '/auth_guard.php';
require_once __DIR__ . '/db.php';
require_student_login();

$studentId = current_student_id();
$class = $_SESSION['sv_student_class'] ?? '';
$stream = $_SESSION['sv_student_stream'] ?? '';

$activeTerm = $pdo->query("SELECT id, term, year FROM terms WHERE is_active = 1 LIMIT 1")->fetch();

// The teacher/subject choice always comes from the student's own checklist
// (dashboard.php), carried through as GET on first load and a hidden field
// on POST -- never a free-choice dropdown. Every load re-checks the
// assignment is still open and still matches this student's class/stream,
// so a paused assignment or a tampered id can't be submitted against.
function sv_valid_assignment(PDO $pdo, int $teacherId, int $subjectId, string $class, string $stream): ?array
{
    if ($teacherId <= 0 || $subjectId <= 0) {
        return null;
    }
    $stmt = $pdo->prepare(
        "SELECT t.name as teacher_name, s.name as subject_name
         FROM teacher_assignments ta
         JOIN teachers t ON t.id = ta.teacher_id AND t.status = 'active'
         JOIN subjects s ON s.id = ta.subject_id AND s.status = 'active'
         WHERE ta.teacher_id = ? AND ta.subject_id = ? AND ta.class = ? AND ta.stream = ? AND ta.is_open = 1"
    );
    $stmt->execute([$teacherId, $subjectId, $class, $stream]);
    $row = $stmt->fetch();
    return $row ?: null;
}

$teacherId = (int) ($_POST['teacher_id'] ?? $_GET['teacher_id'] ?? 0);
$subjectId = (int) ($_POST['subject_id'] ?? $_GET['subject_id'] ?? 0);
$assignment = sv_valid_assignment($pdo, $teacherId, $subjectId, $class, $stream);

$error = '';
$posted = $_POST;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $assignment) {
    if (!$activeTerm) {
        $error = 'No active term is configured. Please contact the school administrator.';
    } else {
        $answers = [];
        $allAnswered = true;
        for ($i = 1; $i <= 20; $i++) {
            $val = (int) ($_POST["q$i"] ?? 0);
            if ($val < 1 || $val > 5) {
                $allAnswered = false;
            }
            $answers[$i] = $val;
        }

        if (!$allAnswered) {
            $error = 'Please answer every statement (1-5) before submitting.';
        } else {
            $dupStmt = $pdo->prepare(
                "SELECT id FROM student_submissions WHERE student_id = ? AND teacher_id = ? AND subject_id = ? AND term_id = ?"
            );
            $dupStmt->execute([$studentId, $teacherId, $subjectId, $activeTerm['id']]);

            if ($dupStmt->fetch()) {
                $error = 'You have already submitted feedback for this teacher and subject this term.';
            } else {
                $commentGood = trim($_POST['comment_good'] ?? '');
                $commentImprove = trim($_POST['comment_improve'] ?? '');
                $commentSuggestions = trim($_POST['comment_suggestions'] ?? '');

                $pdo->beginTransaction();
                try {
                    $cols = implode(', ', array_map(fn($i) => "q$i", range(1, 20)));
                    $placeholders = implode(', ', array_fill(0, 20, '?'));
                    $insert = $pdo->prepare(
                        "INSERT INTO responses (teacher_id, subject_id, term_id, class, stream, $cols, comment_good, comment_improve, comment_suggestions)
                         VALUES (?, ?, ?, ?, ?, $placeholders, ?, ?, ?)"
                    );
                    $params = array_merge(
                        [$teacherId, $subjectId, $activeTerm['id'], $class, $stream],
                        array_values($answers),
                        [$commentGood ?: null, $commentImprove ?: null, $commentSuggestions ?: null]
                    );
                    $insert->execute($params);

                    $mark = $pdo->prepare(
                        "INSERT INTO student_submissions (student_id, teacher_id, subject_id, term_id) VALUES (?, ?, ?, ?)"
                    );
                    $mark->execute([$studentId, $teacherId, $subjectId, $activeTerm['id']]);

                    $pdo->commit();
                } catch (\PDOException $e) {
                    $pdo->rollBack();
                    $error = 'Could not save your feedback. Please try again.';
                }

                if ($error === '') {
                    header("Location: " . SV_BASE . "/thankyou.php");
                    exit();
                }
            }
        }
    }
}

$scale = [5 => 'Strongly Agree', 4 => 'Agree', 3 => 'Not Sure', 2 => 'Disagree', 1 => 'Strongly Disagree'];

function sv_selected_radio(array $posted, int $q, int $val): string
{
    return (int) ($posted["q$q"] ?? 0) === $val ? 'checked' : '';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Students' Voice Feedback | <?= safe_text(SV_SCHOOL_NAME) ?></title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
    <div class="sv-topbar">
        <div class="brand"><?= safe_text(SV_SCHOOL_NAME) ?> <span>| Students' Voice</span></div>
        <nav><a href="dashboard.php">My Feedback</a><a href="logout.php">Log Out</a></nav>
    </div>

    <div class="sv-wrap">
        <div class="sv-card">
            <?php if (!$assignment): ?>
                <h2 style="margin-top:0;color:var(--navy);">Not Available</h2>
                <div class="error-box">
                    This teacher/subject isn't open for feedback right now &mdash; it may have just been paused,
                    or doesn't match your class/stream.
                </div>
                <a href="dashboard.php" class="btn">&larr; Back to My Feedback</a>
            <?php else: ?>
                <h2 style="margin-top:0;color:var(--navy);"><?= safe_text($assignment['subject_name']) ?> &mdash; <?= safe_text($assignment['teacher_name']) ?></h2>
                <p style="color:var(--muted);font-size:0.85rem;">
                    Do <strong>not</strong> write your name anywhere on this form. Your responses are analyzed at
                    class/stream level only and cannot be traced back to you.
                </p>

                <?php if ($error): ?>
                    <div class="error-box"><?= safe_text($error) ?></div>
                <?php endif; ?>

                <?php if (!$activeTerm): ?>
                    <div class="error-box">No active term is configured yet. Please check back later.</div>
                <?php else: ?>
                <form method="POST">
                    <input type="hidden" name="teacher_id" value="<?= (int) $teacherId ?>">
                    <input type="hidden" name="subject_id" value="<?= (int) $subjectId ?>">

                    <div class="sv-section-title">Section A: Details</div>
                    <div style="display:flex;gap:14px;flex-wrap:wrap;">
                        <div style="flex:1 1 160px;">
                            <label>Class</label>
                            <input class="form-control" value="<?= safe_text($class) ?>" disabled>
                        </div>
                        <div style="flex:1 1 160px;">
                            <label>Stream</label>
                            <input class="form-control" value="<?= safe_text($stream) ?>" disabled>
                        </div>
                        <div style="flex:1 1 160px;">
                            <label>Term &amp; Year</label>
                            <input class="form-control" value="Term <?= (int) $activeTerm['term'] ?>, <?= (int) $activeTerm['year'] ?>" disabled>
                        </div>
                    </div>
                    <div style="display:flex;gap:14px;flex-wrap:wrap;">
                        <div style="flex:1 1 220px;">
                            <label>Subject</label>
                            <input class="form-control" value="<?= safe_text($assignment['subject_name']) ?>" disabled>
                        </div>
                        <div style="flex:1 1 220px;">
                            <label>Teacher's Name</label>
                            <input class="form-control" value="<?= safe_text($assignment['teacher_name']) ?>" disabled>
                        </div>
                    </div>

                    <div class="sv-section-title">Section B: Rating Scale</div>
                    <p class="sv-section-hint">Tick the option that best describes your experience: 5 = Strongly Agree &nbsp;&middot;&nbsp; 1 = Strongly Disagree</p>

                    <?php
                    $sections = sv_sections();
                    foreach ($sections as $sectionTitle => $statements):
                    ?>
                        <div class="sv-section-title" style="border-bottom-color:var(--border);font-size:0.85rem;"><?= safe_text($sectionTitle) ?></div>
                        <table class="sv-likert">
                            <tr>
                                <th style="text-align:left;">Statement</th>
                                <?php foreach ($scale as $val => $label): ?>
                                    <th><?= $val ?></th>
                                <?php endforeach; ?>
                            </tr>
                            <?php foreach ($statements as $qNum => $text): ?>
                            <tr>
                                <td class="statement"><?= $qNum ?>. <?= safe_text($text) ?></td>
                                <?php foreach ($scale as $val => $label): ?>
                                    <td><input type="radio" name="q<?= $qNum ?>" value="<?= $val ?>" <?= sv_selected_radio($posted, $qNum, $val) ?> required></td>
                                <?php endforeach; ?>
                            </tr>
                            <?php endforeach; ?>
                        </table>
                    <?php endforeach; ?>

                    <div class="sv-section-title">Section C: Open-Ended Feedback</div>
                    <label>What does this teacher do particularly well?</label>
                    <textarea name="comment_good" class="form-control" rows="3"><?= safe_text($posted['comment_good'] ?? '') ?></textarea>

                    <label>What areas could this teacher improve?</label>
                    <textarea name="comment_improve" class="form-control" rows="3"><?= safe_text($posted['comment_improve'] ?? '') ?></textarea>

                    <label>What suggestions do you have to improve learning in this subject?</label>
                    <textarea name="comment_suggestions" class="form-control" rows="3"><?= safe_text($posted['comment_suggestions'] ?? '') ?></textarea>

                    <button type="submit" class="btn block">Submit Feedback</button>
                </form>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
