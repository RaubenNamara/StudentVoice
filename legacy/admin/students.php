<?php
declare(strict_types=1);

require_once __DIR__ . '/../auth_guard.php';
require_once __DIR__ . '/../db.php';
require_admin_login();

// A row's first cell is treated as a header, not data, if it looks like a
// column label rather than an actual student number (e.g. a roster
// exported with "Student No, Class, Stream" as row 1).
function sv_looks_like_header(string $firstCell): bool
{
    $normalized = strtolower(trim($firstCell));
    return in_array($normalized, ['student_no', 'student no', 'student number', 'admission no', 'admission number', 'reg no', 'regno'], true);
}

function sv_import_rows(PDO $pdo, array $rows): array
{
    $added = 0;
    $skipped = 0;
    foreach ($rows as $i => $parts) {
        $parts = array_map('trim', $parts);
        if ($i === 0 && isset($parts[0]) && sv_looks_like_header($parts[0])) {
            continue;
        }
        if (count($parts) < 3 || $parts[0] === '' || $parts[1] === '' || $parts[2] === '') {
            if (count($parts) >= 1 && $parts[0] !== '') { $skipped++; }
            continue;
        }
        [$studentNo, $class, $stream] = $parts;
        try {
            $pdo->prepare("INSERT INTO students (student_no, class, stream) VALUES (?, ?, ?)")
                ->execute([$studentNo, $class, $stream]);
            $added++;
        } catch (\PDOException $e) {
            $skipped++;
        }
    }
    return [$added, $skipped];
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $studentNo = trim($_POST['student_no'] ?? '');
        $class = trim($_POST['class'] ?? '');
        $stream = trim($_POST['stream'] ?? '');

        if ($studentNo === '' || $class === '' || $stream === '') {
            $error = 'Student number, class, and stream are all required.';
        } else {
            try {
                $pdo->prepare("INSERT INTO students (student_no, class, stream) VALUES (?, ?, ?)")
                    ->execute([$studentNo, $class, $stream]);
                $success = 'Student added.';
            } catch (\PDOException $e) {
                $error = 'That student number already exists.';
            }
        }
    } elseif ($action === 'bulk_paste') {
        $lines = preg_split('/\r\n|\r|\n/', trim($_POST['bulk'] ?? ''));
        $rows = array_map(fn($line) => array_map('trim', explode(',', $line)), array_filter($lines, fn($l) => trim($l) !== ''));
        [$added, $skipped] = sv_import_rows($pdo, $rows);
        $success = "$added student(s) imported." . ($skipped > 0 ? " $skipped row(s) skipped (duplicate or incomplete)." : '');
    } elseif ($action === 'bulk_csv') {
        if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
            $error = 'Please choose a CSV file to upload.';
        } else {
            $rows = [];
            $handle = fopen($_FILES['csv_file']['tmp_name'], 'r');
            if ($handle) {
                while (($row = fgetcsv($handle)) !== false) {
                    $rows[] = $row;
                }
                fclose($handle);
            }
            [$added, $skipped] = sv_import_rows($pdo, $rows);
            $success = "$added student(s) imported from file." . ($skipped > 0 ? " $skipped row(s) skipped (duplicate or incomplete)." : '');
        }
    } elseif ($action === 'toggle') {
        $id = (int) ($_POST['id'] ?? 0);
        $pdo->prepare("UPDATE students SET status = IF(status='active','disabled','active') WHERE id = ?")->execute([$id]);
        $success = 'Status updated.';
    }
}

$students = $pdo->query("SELECT id, student_no, class, stream, status FROM students ORDER BY class, stream, student_no")->fetchAll();

$pageTitle = 'Students';
$activeNav = 'students';
require __DIR__ . '/includes/header.php';
?>

<?php if ($error): ?><div class="error-box"><?= safe_text($error) ?></div><?php endif; ?>
<?php if ($success): ?><div class="success-box"><?= safe_text($success) ?></div><?php endif; ?>

<div class="sv-card">
    <h2 style="margin-top:0;color:var(--navy);">Add One Student</h2>
    <form method="POST" style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;">
        <input type="hidden" name="action" value="add">
        <div style="flex:1 1 160px;"><label>Student No.</label><input type="text" name="student_no" required class="form-control" placeholder="SMN-2026-014"></div>
        <div style="flex:1 1 120px;"><label>Class</label><input type="text" name="class" required class="form-control" placeholder="S4"></div>
        <div style="flex:1 1 120px;"><label>Stream</label><input type="text" name="stream" required class="form-control" placeholder="East"></div>
        <div><button type="submit" class="btn">Add Student</button></div>
    </form>
</div>

<div class="sv-card">
    <h2 style="margin-top:0;color:var(--navy);">Import Roster from File</h2>
    <p style="color:var(--muted);font-size:0.82rem;">
        Upload the school's student list as a CSV (Save As &rarr; CSV from Excel/Sheets works). Three columns per row:
        <code>student_no, class, stream</code>. A header row is fine and will be skipped automatically. No passwords needed --
        any student number on the list can log in directly.
    </p>
    <form method="POST" enctype="multipart/form-data" style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;">
        <input type="hidden" name="action" value="bulk_csv">
        <div style="flex:1 1 260px;">
            <label>CSV File</label>
            <input type="file" name="csv_file" accept=".csv,text/csv" required class="form-control">
        </div>
        <div><button type="submit" class="btn">Upload &amp; Import</button></div>
    </form>
</div>

<div class="sv-card">
    <h2 style="margin-top:0;color:var(--navy);">Or Paste a List</h2>
    <p style="color:var(--muted);font-size:0.82rem;">One student per line: <code>student_no, class, stream</code></p>
    <form method="POST">
        <input type="hidden" name="action" value="bulk_paste">
        <textarea name="bulk" class="form-control" rows="6" placeholder="SMN-2026-014, S4, East&#10;SMN-2026-015, S4, East"></textarea>
        <button type="submit" class="btn">Import</button>
    </form>
</div>

<div class="sv-card">
    <h3 style="margin-top:0;color:var(--navy);">All Students (<?= count($students) ?>)</h3>
    <table class="sv-data">
        <tr><th>Student No.</th><th>Class</th><th>Stream</th><th>Status</th><th></th></tr>
        <?php foreach ($students as $s): ?>
            <tr>
                <td><?= safe_text($s['student_no']) ?></td>
                <td><?= safe_text($s['class']) ?></td>
                <td><?= safe_text($s['stream']) ?></td>
                <td><span class="badge <?= $s['status'] === 'active' ? 'outstanding' : 'below' ?>"><?= safe_text($s['status']) ?></span></td>
                <td style="text-align:right;">
                    <form method="POST" style="display:inline;">
                        <input type="hidden" name="action" value="toggle">
                        <input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
                        <button type="submit" class="btn secondary" style="padding:5px 10px;font-size:0.7rem;">Toggle</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
    </table>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
