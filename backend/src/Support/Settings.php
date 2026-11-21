<?php

declare(strict_types=1);

namespace StudentVoice\Support;

use StudentVoice\Config;
use StudentVoice\Database;

/** Small switches kept in the settings table, and what the Tusome link needs. */
final class Settings
{
    public static function get(string $name, string $default = ''): string
    {
        try {
            $stmt = Database::get()->prepare('SELECT value FROM settings WHERE name = ?');
            $stmt->execute([$name]);
            $v = $stmt->fetchColumn();
            return $v === false ? $default : (string) $v;
        } catch (\PDOException) {
            return $default; // before migration 008
        }
    }

    public static function set(string $name, string $value): void
    {
        Database::get()->prepare('INSERT INTO settings (name, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)')
            ->execute([$name, $value]);
    }

    /** Students may sign in with a voting number (otherwise: from Tusome only) */
    public static function votingNumbers(): bool
    {
        return self::get('voting_numbers', '1') === '1';
    }

    /** Teachers come only from the Excel upload (the Teaching list), not every teacher Tusome has */
    public static function excelTeachersOnly(): bool
    {
        return self::get('teachers_source', 'excel') === 'excel';
    }

    /** The key shared with Tusome is set (it signs both the teaching feed and sign-in tickets) */
    public static function tusomeLinked(): bool
    {
        return strlen((string) Config::get('TUSOME_API_KEY', '')) >= 32;
    }

    /** Tusome's own address (for "open Tusome" links), from TUSOME_URL or the teaching feed's address */
    public static function tusomeUrl(): string
    {
        $url = (string) Config::get('TUSOME_URL', '');
        if ($url === '') {
            $feed = (string) Config::get('TUSOME_TEACHING_API_URL', '');
            $url = preg_replace('#/api/.*$#', '', $feed) ?? '';
            $url = preg_replace('#/backend/public$#', '', $url) ?? '';
        }
        return rtrim($url, '/');
    }
}
