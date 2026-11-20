<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| STUDENTS' VOICE -- CONFIG
|--------------------------------------------------------------------------
| Same env-var-with-local-fallback pattern as devportal/config.php and
| bulksms/config.php -- nothing hardcoded, a real deploy only needs
| environment variables set, not this file edited.
|--------------------------------------------------------------------------
*/

if (!defined('SV_BASE')) {
    define('SV_BASE', getenv('STUDENTVOICE_BASE_PATH') ?: '/ABNsystems/studentvoice');
}

define('DB_HOST', getenv('STUDENTVOICE_DB_HOST') ?: 'localhost');
define('DB_NAME', getenv('STUDENTVOICE_DB_NAME') ?: 'stmarks_voice');
define('DB_USER', getenv('STUDENTVOICE_DB_USER') ?: 'root');
define('DB_PASS', getenv('STUDENTVOICE_DB_PASS') ?: '');
define('DB_CHARSET', 'utf8mb4');

if (!defined('SV_ENV')) {
    define('SV_ENV', getenv('STUDENTVOICE_ENV') ?: 'development');
}

if (SV_ENV === 'production') {
    error_reporting(E_ALL);
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
} else {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
}

define('SV_SCHOOL_NAME', getenv('STUDENTVOICE_SCHOOL_NAME') ?: "St. Mark's College Namagoma");

if (!function_exists('safe_text')) {
    function safe_text($value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}

// Section C-F statement text, in q1..q20 order -- shared by the student
// form (rendering the questions) and admin reports (labelling averages),
// so the two never drift out of sync with each other or the paper form.
function sv_sections(): array
{
    return [
    'Professionalism & Preparation' => [
        1 => 'The teacher starts and ends lessons on time.',
        2 => 'The teacher is well prepared for lessons.',
        3 => 'Lessons are well organized and easy to follow.',
        4 => 'The teacher is progressing well with syllabus coverage.',
        5 => 'The teacher marks work and returns it with helpful feedback.',
    ],
    'Teaching Effectiveness' => [
        6 => 'The teacher explains concepts clearly.',
        7 => 'Examples and illustrations improve my understanding.',
        8 => 'The teacher uses varied teaching methods (discussion, demonstration, group work, etc.).',
        9 => 'I am gaining confidence in this subject.',
        10 => 'The teacher prepares us well for internal and national examinations (e.g., UNEB).',
    ],
    'Classroom Environment & Discipline' => [
        11 => 'The teacher treats students with respect and fairness.',
        12 => "The teacher listens to students' questions and opinions.",
        13 => 'The classroom environment is safe and supportive.',
        14 => 'Discipline is managed fairly and consistently.',
        15 => 'The teacher encourages participation from all learners.',
    ],
    'Talent Nurturing & Skill Development' => [
        16 => 'The teacher encourages critical thinking and problem-solving.',
        17 => 'The teacher supports learners with different abilities.',
        18 => 'The teacher motivates us to aim for excellence.',
        19 => 'The teacher connects classroom learning to real-life situations.',
        20 => 'I feel inspired to improve in this subject.',
    ],
    ];
}
