<?php
declare(strict_types=1);

require_once __DIR__ . '/../auth_guard.php';

unset($_SESSION['sv_admin_id'], $_SESSION['sv_admin_name']);
session_regenerate_id(true);

header("Location: " . SV_BASE . "/admin/login.php");
exit();
