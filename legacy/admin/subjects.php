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
            $error = 'Subject name is required.';
        } else {
            try {
                $pdo->prepare("INSERT INTO subjects (name) VALUES (?)")->execute([$name]);
                $success = 'Subject added.';
            } catch (\PDOException $e) {
                $error = 'That subject already exists.';
            }
        }
    } elseif ($action === 'bulk_paste') {
        $lines = array_filter(preg_split('/\r\n|\r|\n/', trim($_POST['bulk'] ?? '')), fn($l) => trim($l) !== '');
        [$added, $skipped] = sv_import_names($pdo, 'subjects', array_values($lines));
        $success = "$added subject(s) imported." . ($skipped > 0 ? " $skipped skipped (duplicate or blank)." : '');
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
            [$added, $skipped] = sv_import_names($pdo, 'subjects', $rows);
            $success = "$added subject(s) imported from file." . ($skipped > 0 ? " $skipped skipped (duplicate or blank)." : '');
        }
    } elseif ($action === 'toggle') {
        $id = (int) ($_POST['id'] ?? 0);
        $pdo->prepare("UPDATE subjects SET status = IF(status='active','disabled','active') WHERE id = ?")->execute([$id]);
        $success = 'Status updated.';
    }
}

$subjects = $pdo->query("SELECT id, name, status FROM subjects ORDER BY name")->fetchAll();

$pageTitle = 'Subjects';
$activeNav = 'subjects';
require __DIR__ . '/includes/header.php';
?>

<?php if ($error): ?><div class="error-box"><?= safe_text($error) ?></div><?php endif; ?>
<?php if ($success): ?><div class="success-box"><?= safe_text($success) ?></div><?php endif; ?>

<div class="sv-card">
    <h2 style="margin-top:0;color:var(--navy);">Add Subject</h2>
    <form method="POST" style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;">
        <input type="hidden" name="action" value="add">
        <div style="flex:1 1 260px;">
            <label>Subject Name</label>
            <input type="text" name="name" required class="form-control" placeholder="e.g. Mathematics">
        </div>
        <div><button type="submit" class="btn">Add Subject</button></div>
    </form>
</div>

<div class="sv-card">
    <h2 style="margin-top:0;color:var(--navy);">Import from File</h2>
    <p style="color:var(--muted);font-size:0.82rem;">A CSV with one subject name per row (a header row is fine and skipped automatically).</p>
    <form method="POST" enctype="multipart/form-data" style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;">
        <input type="hidden" name="action" value="bulk_csv">
        <div style="flex:1 1 260px;"><label>CSV File</label><input type="file" name="csv_file" accept=".csv,text/csv" required class="form-control"></div>
        <div><button type="submit" class="btn">Upload &amp; Import</button></div>
    </form>
</div>

<div class="sv-card">
    <h2 style="margin-top:0;color:var(--navy);">Or Paste a List</h2>
    <p style="color:var(--muted);font-size:0.82rem;">One subject per line.</p>
    <form method="POST">
        <input type="hidden" name="action" value="bulk_paste">
        <textarea name="bulk" class="form-control" rows="6" placeholder="Mathematics&#10;English&#10;Physics"></textarea>
        <button type="submit" class="btn">Import</button>
    </form>
</div>

<div class="sv-card">
    <h3 style="margin-top:0;color:var(--navy);">All Subjects (<?= count($subjects) ?>)</h3>
    <table class="sv-data">
        <tr><th>Name</th><th>Status</th><th></th></tr>
        <?php foreach ($subjects as $s): ?>
            <tr>
                <td><?= safe_text($s['name']) ?></td>
                <td><span class="badge <?= $s['status'] === 'active' ? 'outstanding' : 'below' ?>"><?= safe_text($s['status']) ?></span></td>
                <td style="text-align:right;">
                    <form method="POST" style="display:inline;">
                        <input type="hidden" name="action" value="toggle">
                        <input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
                        <button type="submit" class="btn secondary" style="padding:5px 10px;font-size:0.7rem;">Toggle Active/Disabled</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
    </table>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
