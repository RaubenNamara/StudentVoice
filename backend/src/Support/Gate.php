<?php

declare(strict_types=1);

namespace StudentVoice\Support;

use StudentVoice\Config;

/**
 * The "once per teacher" mark for teacher polls: HMAC-SHA256 of poll + student + teacher +
 * subject with APP_KEY. The database keeps only this fingerprint, so it shows that someone
 * rated a teacher but not who; only the app (holding APP_KEY) can check a given student.
 *
 * APP_KEY must never change while a teacher poll is open: with a new key, every student could
 * rate their teachers again.
 */
final class Gate
{
    public static function for(int $pollId, int $studentId, int $teacherId, int $subjectId): string
    {
        $key = (string) Config::get('APP_KEY', '');
        if (strlen($key) < 32) {
            throw new \RuntimeException('APP_KEY is missing from backend/.env. Run php backend/bin/migrate.php to create it.');
        }
        return hash_hmac('sha256', "$pollId|$studentId|$teacherId|$subjectId", $key);
    }
}
