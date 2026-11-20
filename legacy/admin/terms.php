<?php
declare(strict_types=1);

require_once __DIR__ . '/../auth_guard.php';
require_once __DIR__ . '/../db.php';
require_admin_login();

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $term = (int) ($_POST['term'] ?? 0);
        $year = (int) ($_POST['year'] ?? 0);
        if ($term < 1 || $term > 3 || $year < 2000) {
            $error = 'Please provide a valid term (1-3) and year.';
        } else {
            try {
                $pdo->prepare("INSERT INTO terms (term, year) VALUES (?, ?)")->execute([$term, $year]);
                $success = 'Term added. Activate it below when ready to open submissions.';
            } catch (\PDOException $e) {
                $error = 'That term/year already exists.';
            }
        }
    } elseif ($action === 'activate') {
        $id = (int) ($_POST['id'] ?? 0);
        $pdo->beginTransaction();
        $pdo->exec("UPDATE terms SET is_active = 0");
        $pdo->prepare("UPDATE terms SET is_active = 1 WHERE id = ?")->execute([$id]);
        $pdo->commit();
        $success = 'Active term updated. Students will now submit against this term.';
    }
}

$terms = $pdo->query("SELECT id, term, year, is_active FROM terms ORDER BY year DESC, term DESC")->fetchAll();

$pageTitle = 'Terms';
$activeNav = 'terms';
require __DIR__ . '/includes/header.php';
?>

<div class="sv-card">
    <h2 style="margin-top:0;color:var(--navy);">Add Term</h2>
    <p style="color:var(--muted);font-size:0.82rem;">Administer the form once per term, mid-term recommended. Only one term is "active" (open for submissions) at a time.</p>
    <?php if ($error): ?><div class="error-box"><?= safe_text($error) ?></div><?php endif; ?>
    <?php if ($success): ?><div class="success-box"><?= safe_text($success) ?></div><?php endif; ?>
    <form method="POST" style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;">
        <input type="hidden" name="action" value="add">
        <div style="flex:1 1 120px;">
            <label>Term</label>
            <select name="term" class="form-control">
                <option value="1">Term 1</option>
                <option value="2">Term 2</option>
                <option value="3">Term 3</option>
            </select>
        </div>
        <div style="flex:1 1 120px;">
            <label>Year</label>
            <input type="number" name="year" class="form-control" value="<?= (int) date('Y') ?>">
        </div>
        <div><button type="submit" class="btn">Add Term</button></div>
    </form>
</div>

<div class="sv-card">
    <h3 style="margin-top:0;color:var(--navy);">All Terms</h3>
    <table class="sv-data">
        <tr><th>Term</th><th>Year</th><th>Status</th><th></th></tr>
        <?php foreach ($terms as $t): ?>
            <tr>
                <td>Term <?= (int) $t['term'] ?></td>
                <td><?= (int) $t['year'] ?></td>
                <td><?= $t['is_active'] ? '<span class="badge outstanding">Active</span>' : '<span class="badge fair">Inactive</span>' ?></td>
                <td style="text-align:right;">
                    <?php if (!$t['is_active']): ?>
                        <form method="POST" style="display:inline;">
                            <input type="hidden" name="action" value="activate">
                            <input type="hidden" name="id" value="<?= (int) $t['id'] ?>">
                            <button type="submit" class="btn secondary" style="padding:5px 10px;font-size:0.7rem;">Set Active</button>
                        </form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
    </table>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
