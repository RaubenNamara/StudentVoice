<?php
declare(strict_types=1);

require_once __DIR__ . '/../auth_guard.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/includes/name_import.php';
require_admin_login();

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $name = trim($_POST['name'] ?? '');
        if ($name === '') {
            $error = 'Teacher name is required.';
        } else {
            try {
                $pdo->prepare("INSERT INTO teachers (name) VALUES (?)")->execute([$name]);
                $success = 'Teacher added.';
            } catch (\PDOException $e) {
                $error = 'That teacher name already exists.';
            }
        }
    } elseif ($action === 'bulk_paste') {
        $lines = array_filter(preg_split('/\r\n|\r|\n/', trim($_POST['bulk'] ?? '')), fn($l) => trim($l) !== '');
        [$added, $skipped] = sv_import_names($pdo, 'teachers', array_values($lines));
        $success = "$added teacher(s) imported." . ($skipped > 0 ? " $skipped skipped (duplicate or blank)." : '');
    } elseif ($action === 'bulk_csv') {
        if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
            $error = 'Please choose a CSV file to upload.';
        } else {
            $rows = [];
            $handle = fopen($_FILES['csv_file']['tmp_name'], 'r');
            if ($handle) {
                while (($row = fgetcsv($handle)) !== false) { $rows[] = $row; }
                fclose($handle);
            }
            [$added, $skipped] = sv_import_names($pdo, 'teachers', $rows);
            $success = "$added teacher(s) imported from file." . ($skipped > 0 ? " $skipped skipped (duplicate or blank)." : '');
        }
    } elseif ($action === 'toggle') {
        $id = (int) ($_POST['id'] ?? 0);
        $pdo->prepare("UPDATE teachers SET status = IF(status='active','disabled','active') WHERE id = ?")->execute([$id]);
        $success = 'Status updated.';
    }
}

$teachers = $pdo->query(
    "SELECT t.id, t.name, t.status, COUNT(ta.id) as assignment_count
     FROM teachers t LEFT JOIN teacher_assignments ta ON ta.teacher_id = t.id
     GROUP BY t.id ORDER BY t.name"
)->fetchAll();

$pageTitle = 'Teachers';
$activeNav = 'teachers';
require __DIR__ . '/includes/header.php';
?>

<?php if ($error): ?><div class="error-box"><?= safe_text($error) ?></div><?php endif; ?>
<?php if ($success): ?><div class="success-box"><?= safe_text($success) ?></div><?php endif; ?>

<div class="sv-card">
    <h2 style="margin-top:0;color:var(--navy);">Add Teacher</h2>
    <form method="POST" style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;">
        <input type="hidden" name="action" value="add">
        <div style="flex:1 1 260px;">
            <label>Teacher's Name</label>
            <input type="text" name="name" required class="form-control" placeholder="e.g. Mr. John Ssemakula">
        </div>
        <div><button type="submit" class="btn">Add Teacher</button></div>
    </form>
</div>

<div class="sv-card">
    <h2 style="margin-top:0;color:var(--navy);">Import from File</h2>
    <p style="color:var(--muted);font-size:0.82rem;">A CSV with one teacher name per row (a header row is fine and skipped automatically).</p>
    <form method="POST" enctype="multipart/form-data" style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;">
        <input type="hidden" name="action" value="bulk_csv">
        <div style="flex:1 1 260px;"><label>CSV File</label><input type="file" name="csv_file" accept=".csv,text/csv" required class="form-control"></div>
        <div><button type="submit" class="btn">Upload &amp; Import</button></div>
    </form>
</div>

<div class="sv-card">
    <h2 style="margin-top:0;color:var(--navy);">Or Paste a List</h2>
    <p style="color:var(--muted);font-size:0.82rem;">One teacher name per line.</p>
    <form method="POST">
        <input type="hidden" name="action" value="bulk_paste">
        <textarea name="bulk" class="form-control" rows="6" placeholder="Mrs. Nakato&#10;Mr. Okello&#10;Ms. Auma"></textarea>
        <button type="submit" class="btn">Import</button>
    </form>
</div>

<div class="sv-card">
    <h3 style="margin-top:0;color:var(--navy);">All Teachers (<?= count($teachers) ?>)</h3>
    <table class="sv-data">
        <tr><th>Name</th><th>Assignments</th><th>Status</th><th></th></tr>
        <?php foreach ($teachers as $t): ?>
            <tr>
                <td><?= safe_text($t['name']) ?></td>
                <td><?= (int) $t['assignment_count'] ?> class/stream combo<?= (int) $t['assignment_count'] === 1 ? '' : 's' ?></td>
                <td><span class="badge <?= $t['status'] === 'active' ? 'outstanding' : 'below' ?>"><?= safe_text($t['status']) ?></span></td>
                <td style="text-align:right;white-space:nowrap;">
                    <form method="POST" style="display:inline;">
                        <input type="hidden" name="action" value="toggle">
                        <input type="hidden" name="id" value="<?= (int) $t['id'] ?>">
                        <button type="submit" class="btn secondary" style="padding:5px 10px;font-size:0.7rem;">Toggle Active/Disabled</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
    </table>
    <p style="margin-top:14px;"><a href="assignments.php" class="btn">Manage Assignments &rarr;</a></p>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
