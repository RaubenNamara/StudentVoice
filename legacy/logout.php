<?php
declare(strict_types=1);

require_once __DIR__ . '/auth_guard.php';

unset($_SESSION['sv_student_id'], $_SESSION['sv_student_class'], $_SESSION['sv_student_stream']);
session_regenerate_id(true);

header("Location: " . SV_BASE . "/login.php");
exit();
