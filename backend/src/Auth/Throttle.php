<?php

declare(strict_types=1);

namespace StudentVoice\Auth;

use StudentVoice\Database;
use StudentVoice\Http\Response;

/**
 * Slows guessing of passwords and voting numbers from one connection. If the table is missing it
 * steps aside.
 *
 * Students: very generous, because the whole school (5,000 learners) can reach the site through
 * one internet address, and on a voting day dozens of mistyped slips in ten minutes are normal; a
 * lower limit would lock every learner out at once. A voting number has 32^8 (about 10^12)
 * possibilities, so even 400 wrong tries every ten minutes, all day, has about a 1 in 4,000 chance
 * of ever hitting one of the school's numbers.
 * Admins: strict, since a password is far easier to guess than a voting number.
 */
final class Throttle
{
    private const MAX_FAILURES = ['student' => 400, 'admin' => 40];
    private const DEFAULT_MAX = 40;
    private const WINDOW_MINUTES = 10;

    public static function check(string $kind): void
    {
        try {
            $stmt = Database::get()->prepare('SELECT failures, window_start > DATE_SUB(NOW(), INTERVAL ' . self::WINDOW_MINUTES . ' MINUTE) AS recent FROM login_throttle WHERE ip = ? AND kind = ?');
            $stmt->execute([self::ip(), $kind]);
            $row = $stmt->fetch();
        } catch (\PDOException $e) {
            error_log('[studentvoice] login throttle unavailable: ' . $e->getMessage());
            return;
        }
        if ($row && $row['recent'] && (int) $row['failures'] >= (self::MAX_FAILURES[$kind] ?? self::DEFAULT_MAX)) {
            Response::error('Too many wrong tries from this connection. Please wait ' . self::WINDOW_MINUTES . ' minutes and try again.', 429);
        }
    }

    public static function fail(string $kind): void
    {
        try {
            Database::get()->prepare(
                'INSERT INTO login_throttle (ip, kind, failures, window_start) VALUES (?, ?, 1, NOW())
                 ON DUPLICATE KEY UPDATE
                    failures = IF(window_start > DATE_SUB(NOW(), INTERVAL ' . self::WINDOW_MINUTES . ' MINUTE), failures + 1, 1),
                    window_start = IF(window_start > DATE_SUB(NOW(), INTERVAL ' . self::WINDOW_MINUTES . ' MINUTE), window_start, NOW())'
            )->execute([self::ip(), $kind]);
        } catch (\PDOException $e) {
            error_log('[studentvoice] login throttle unavailable: ' . $e->getMessage());
        }
    }

    private static function ip(): string
    {
        return mb_substr((string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'), 0, 45);
    }
}
