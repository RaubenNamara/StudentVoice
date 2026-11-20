<?php
declare(strict_types=1);

require_once __DIR__ . '/../auth_guard.php';
require_once __DIR__ . '/../db.php';
require_admin_login();

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'assign') {
        $teacherId = (int) ($_POST['teacher_id'] ?? 0);
        $subjectId = (int) ($_POST['subject_id'] ?? 0);
        $combos = $_POST['combos'] ?? [];

        if ($teacherId <= 0 || $subjectId <= 0) {
            $error = 'Please choose a teacher and a subject.';
        } elseif (empty($combos)) {
            $error = 'Tick at least one class/stream this teacher teaches this subject in.';
        } else {
            $added = 0;
            $skipped = 0;
            $stmt = $pdo->prepare("INSERT INTO teacher_assignments (teacher_id, subject_id, class, stream) VALUES (?, ?, ?, ?)");
            foreach ($combos as $combo) {
                $parts = explode('|', (string) $combo, 2);
                if (count($parts) !== 2) { $skipped++; continue; }
                [$class, $stream] = $parts;
                try {
                    $stmt->execute([$teacherId, $subjectId, $class, $stream]);
                    $added++;
                } catch (\PDOException $e) {
                    $skipped++;
                }
            }
            $success = "$added assignment(s) created." . ($skipped > 0 ? " $skipped already existed." : '');
        }
    } elseif ($action === 'toggle_open') {
        $id = (int) ($_POST['id'] ?? 0);
        $pdo->prepare("UPDATE teacher_assignments SET is_open = IF(is_open=1,0,1) WHERE id = ?")->execute([$id]);
        $success = 'Assignment updated.';
    }
}

$teachers = $pdo->query("SELECT id, name FROM teachers WHERE status = 'active' ORDER BY name")->fetchAll();
$subjects = $pdo->query("SELECT id, name FROM subjects WHERE status = 'active' ORDER BY name")->fetchAll();
$classStreams = $pdo->query("SELECT DISTINCT class, stream FROM students ORDER BY class, stream")->fetchAll();

$assignments = $pdo->query(
    "SELECT ta.id, t.name as teacher_name, s.name as subject_name, ta.class, ta.stream, ta.is_open
     FROM teacher_assignments ta
     JOIN teachers t ON t.id = ta.teacher_id
     JOIN subjects s ON s.id = ta.subject_id
     ORDER BY t.name, s.name, ta.class, ta.stream"
)->fetchAll();

$pageTitle = 'Assignments';
$activeNav = 'assignments';
require __DIR__ . '/includes/header.php';
?>

<?php if ($error): ?><div class="error-box"><?= safe_text($error) ?></div><?php endif; ?>
<?php if ($success): ?><div class="success-box"><?= safe_text($success) ?></div><?php endif; ?>

<div class="sv-card">
    <h2 style="margin-top:0;color:var(--navy);">Assign a Teacher</h2>
    <p style="color:var(--muted);font-size:0.82rem;">
        Pick a teacher and subject, then tick every class + stream they teach it in. A teacher can be assigned to
        several subjects and several class/stream combinations &mdash; just repeat this for each subject they teach.
    </p>

    <?php if (empty($teachers) || empty($subjects)): ?>
        <div class="error-box">Add at least one <a href="teachers.php">teacher</a> and one <a href="subjects.php">subject</a> first.</div>
    <?php elseif (empty($classStreams)): ?>
        <div class="error-box">No class/stream combinations found yet &mdash; <a href="students.php">import the student roster</a> first, since that's where the class/stream list comes from.</div>
    <?php else: ?>
        <form method="POST">
            <input type="hidden" name="action" value="assign">
            <div style="display:flex;gap:14px;flex-wrap:wrap;">
                <div style="flex:1 1 240px;">
                    <label>Teacher</label>
                    <select name="teacher_id" class="form-control" required>
                        <option value="">-- Select teacher --</option>
                        <?php foreach ($teachers as $t): ?>
                            <option value="<?= (int) $t['id'] ?>"><?= safe_text($t['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div style="flex:1 1 240px;">
                    <label>Subject</label>
                    <select name="subject_id" class="form-control" required>
                        <option value="">-- Select subject --</option>
                        <?php foreach ($subjects as $s): ?>
                            <option value="<?= (int) $s['id'] ?>"><?= safe_text($s['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <label style="margin-top:6px;">Classes &amp; Streams Taught</label>
            <div class="sv-checkbox-grid">
                <?php foreach ($classStreams as $cs): ?>
                    <label class="sv-checkbox-pill">
                        <input type="checkbox" name="combos[]" value="<?= safe_text($cs['class'] . '|' . $cs['stream']) ?>">
                        <?= safe_text($cs['class']) ?> <?= safe_text($cs['stream']) ?>
                    </label>
                <?php endforeach; ?>
            </div>

            <button type="submit" class="btn" style="margin-top:16px;">Save Assignment</button>
        </form>
    <?php endif; ?>
</div>

<div class="sv-card">
    <h3 style="margin-top:0;color:var(--navy);">All Assignments (<?= count($assignments) ?>)</h3>
    <p style="color:var(--muted);font-size:0.8rem;">
        "Open" assignments are the ones students actually see on their feedback checklist. Pause any you don't want voiced right now.
    </p>
    <?php if (empty($assignments)): ?>
        <p style="color:var(--muted);font-size:0.85rem;">No assignments yet.</p>
    <?php else: ?>
        <table class="sv-data">
            <tr><th>Teacher</th><th>Subject</th><th>Class</th><th>Stream</th><th>Status</th><th></th></tr>
            <?php foreach ($assignments as $a): ?>
                <tr>
                    <td><?= safe_text($a['teacher_name']) ?></td>
                    <td><?= safe_text($a['subject_name']) ?></td>
                    <td><?= safe_text($a['class']) ?></td>
                    <td><?= safe_text($a['stream']) ?></td>
                    <td><span class="badge <?= $a['is_open'] ? 'outstanding' : 'fair' ?>"><?= $a['is_open'] ? 'Open' : 'Paused' ?></span></td>
                    <td style="text-align:right;">
                        <form method="POST" style="display:inline;">
                            <input type="hidden" name="action" value="toggle_open">
                            <input type="hidden" name="id" value="<?= (int) $a['id'] ?>">
                            <button type="submit" class="btn secondary" style="padding:5px 10px;font-size:0.7rem;"><?= $a['is_open'] ? 'Pause' : 'Open' ?></button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
        </table>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
