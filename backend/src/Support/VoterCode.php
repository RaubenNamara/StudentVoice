<?php

declare(strict_types=1);

namespace StudentVoice\Support;

use PDO;

/**
 * Voting numbers: 6 characters shown as XXX-XXX (e.g. K7P-4QX), always mixing letters and digits,
 * from an alphabet with no look-alikes (no 0/O, 1/I/L), so a slip read aloud or copied by hand
 * still works. Random, not the RegNo, so classmates who know each other's RegNo cannot vote for
 * each other. (Older 8-character numbers, XXXX-XXXX, are still recognised when typed.)
 */
final class VoterCode
{
    private const LETTERS = 'ABCDEFGHJKMNPQRSTUVWXYZ';
    private const DIGITS = '23456789';
    private const LENGTH = 6;

    public static function generate(): string
    {
        $all = self::LETTERS . self::DIGITS;
        do {
            $out = '';
            for ($i = 0; $i < self::LENGTH; $i++) {
                $out .= $all[random_int(0, strlen($all) - 1)];
            }
            // letters and digits mixed: at least one of each
        } while (!preg_match('/[A-Z]/', $out) || !preg_match('/[0-9]/', $out));
        return substr($out, 0, 3) . '-' . substr($out, 3);
    }

    /** A new code no student has */
    public static function unique(PDO $db): string
    {
        $check = $db->prepare('SELECT 1 FROM students WHERE voter_code = ?');
        do {
            $code = self::generate();
            $check->execute([$code]);
        } while ($check->fetchColumn());
        return $code;
    }

    /** $n different new codes (for giving every student a new number at once) */
    public static function many(int $n): array
    {
        $codes = [];
        while (count($codes) < $n) {
            $codes[self::generate()] = true;
        }
        return array_keys($codes);
    }

    /** What a student typed, as stored: capitals, anything but letters and digits dropped, the dash put back */
    public static function normalise(string $typed): string
    {
        $c = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $typed));
        if (strlen($c) === 6) {
            return substr($c, 0, 3) . '-' . substr($c, 3);
        }
        if (strlen($c) === 8) {
            return substr($c, 0, 4) . '-' . substr($c, 4);
        }
        return $c;
    }

    /** Looks like a whole voting number (new or old length) */
    public static function complete(string $normalised): bool
    {
        return (bool) preg_match('/^([A-Z0-9]{3}-[A-Z0-9]{3}|[A-Z0-9]{4}-[A-Z0-9]{4})$/', $normalised);
    }
}
