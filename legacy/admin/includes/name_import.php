<?php
declare(strict_types=1);

/**
 * Shared by teachers.php and subjects.php -- both are just "import a
 * single-column list of names into a table with a UNIQUE(name)
 * constraint", so re-imports are naturally idempotent (a repeat name
 * fails the insert and counts as skipped rather than duplicating).
 */
function sv_looks_like_name_header(string $firstCell): bool
{
    $normalized = strtolower(trim($firstCell));
    return in_array($normalized, ['name', 'teacher', 'teacher_name', "teacher's name", 'subject', 'subject_name'], true);
}

function sv_import_names(PDO $pdo, string $table, array $rows): array
{
    if (!in_array($table, ['teachers', 'subjects'], true)) {
        throw new \InvalidArgumentException('Unsupported import target.');
    }

    $added = 0;
    $skipped = 0;
    $stmt = $pdo->prepare("INSERT INTO $table (name) VALUES (?)");

    foreach ($rows as $i => $row) {
        $name = trim(is_array($row) ? ($row[0] ?? '') : $row);
        if ($i === 0 && sv_looks_like_name_header($name)) {
            continue;
        }
        if ($name === '') {
            continue;
        }
        try {
            $stmt->execute([$name]);
            $added++;
        } catch (\PDOException $e) {
            $skipped++;
        }
    }

    return [$added, $skipped];
}
