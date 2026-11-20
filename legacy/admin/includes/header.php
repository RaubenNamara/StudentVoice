<?php
/** Expects $pageTitle and $activeNav to be set by the including page. */
$activeNav = $activeNav ?? '';

function sv_nav_icon(string $name): string
{
    $paths = [
        'dashboard' => '<rect x="3" y="3" width="7" height="9" rx="1.5"/><rect x="14" y="3" width="7" height="5" rx="1.5"/><rect x="14" y="12" width="7" height="9" rx="1.5"/><rect x="3" y="16" width="7" height="5" rx="1.5"/>',
        'reports'   => '<path d="M4 20V10"/><path d="M11 20V4"/><path d="M18 20v-7"/>',
        'teachers'  => '<circle cx="12" cy="8" r="3.4"/><path d="M5 20c0-3.6 3-6 7-6s7 2.4 7 6"/>',
        'assignments' => '<path d="M9 11.5 11 13.5 15.5 9"/><rect x="3.5" y="4" width="17" height="16" rx="2"/>',
        'subjects'  => '<path d="M4 5.5C4 4.7 4.7 4 5.5 4H12v16H5.5C4.7 20 4 19.3 4 18.5z"/><path d="M20 5.5c0-.8-.7-1.5-1.5-1.5H12v16h6.5c.8 0 1.5-.7 1.5-1.5z"/>',
        'students'  => '<circle cx="12" cy="7.5" r="3.2"/><path d="M6 20.5c0-3.3 2.7-5.8 6-5.8s6 2.5 6 5.8"/>',
        'terms'     => '<rect x="3.5" y="4.5" width="17" height="16" rx="1.8"/><path d="M3.5 9.5h17"/><path d="M8 2.5v4"/><path d="M16 2.5v4"/>',
        'logout'    => '<path d="M9 21H5.5A1.5 1.5 0 0 1 4 19.5v-15A1.5 1.5 0 0 1 5.5 3H9"/><path d="M15.5 16.5 20 12l-4.5-4.5"/><path d="M20 12H9"/>',
    ];
    $d = $paths[$name] ?? '';
    return '<svg class="sv-nav-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">' . $d . '</svg>';
}

function sv_nav_link(string $key, string $href, string $label, string $active): string
{
    $cls = $key === $active ? 'sv-nav-link active' : 'sv-nav-link';
    return '<a href="' . $href . '" class="' . $cls . '">' . sv_nav_icon($key) . '<span>' . $label . '</span></a>';
}

$adminName = current_admin_name();
$adminInitial = $adminName !== '' ? strtoupper(substr($adminName, 0, 1)) : 'A';
$pageTitles = [
    'dashboard' => 'Dashboard', 'reports' => 'Reports', 'teachers' => 'Teachers',
    'assignments' => 'Assignments', 'subjects' => 'Subjects', 'students' => 'Students', 'terms' => 'Terms',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title><?= safe_text($pageTitle ?? 'Admin') ?> | Students' Voice</title>
    <link rel="stylesheet" href="../assets/style.css">
</head>
<body class="sv-admin-body">
    <div class="sv-admin-shell">
        <aside class="sv-sidebar">
            <div class="sv-sidebar-brand">
                <div class="mark">SV</div>
                <div>
                    <div class="name"><?= safe_text(SV_SCHOOL_NAME) ?></div>
                    <div class="tag">Students' Voice Admin</div>
                </div>
            </div>
            <nav class="sv-sidebar-nav">
                <?= sv_nav_link('dashboard', 'dashboard.php', 'Dashboard', $activeNav) ?>
                <?= sv_nav_link('reports', 'reports.php', 'Reports', $activeNav) ?>
                <?= sv_nav_link('teachers', 'teachers.php', 'Teachers', $activeNav) ?>
                <?= sv_nav_link('assignments', 'assignments.php', 'Assignments', $activeNav) ?>
                <?= sv_nav_link('subjects', 'subjects.php', 'Subjects', $activeNav) ?>
                <?= sv_nav_link('students', 'students.php', 'Students', $activeNav) ?>
                <?= sv_nav_link('terms', 'terms.php', 'Terms', $activeNav) ?>
            </nav>
            <div class="sv-sidebar-footer">
                <a href="logout.php" class="sv-nav-link">
                    <?= sv_nav_icon('logout') ?><span>Log Out</span>
                </a>
            </div>
        </aside>
        <main class="sv-admin-main">
            <div class="sv-admin-topbar">
                <h1><?= safe_text($pageTitles[$activeNav] ?? ($pageTitle ?? 'Admin')) ?></h1>
                <div class="sv-admin-user">
                    <div class="avatar"><?= safe_text($adminInitial) ?></div>
                    <span><?= safe_text($adminName ?: 'Administrator') ?></span>
                </div>
            </div>
            <div class="sv-admin-content">
