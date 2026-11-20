<?php

declare(strict_types=1);

namespace StudentVoice\Support;

use PDO;

/**
 * Voting numbers: 8 random characters shown as XXXX-XXXX, from an alphabet with no look-alikes
 * (no 0/O, 1/I/L), so a slip read aloud or copied by hand still works. Random, not the RegNo,
 * so classmates who know each other's RegNo cannot vote for each other.
 */
final class VoterCode
{
    private const ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    public static function generate(): string
    {
        $out = '';
        for ($i = 0; $i < 8; $i++) {
            $out .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }
        return substr($out, 0, 4) . '-' . substr($out, 4);
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

    /** What a student typed, as stored: upper case, spaces and dashes ignored, dash in the middle */
    public static function normalise(string $typed): string
    {
        $c = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $typed));
        return strlen($c) === 8 ? substr($c, 0, 4) . '-' . substr($c, 4) : $c;
    }
}
