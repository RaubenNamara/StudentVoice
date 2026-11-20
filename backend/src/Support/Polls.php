<?php

declare(strict_types=1);

namespace StudentVoice\Support;

use PDO;

/** Rules shared by the admin side and the voting side. */
final class Polls
{
    public const TYPES = ['scale', 'choice', 'multi', 'yesno', 'text'];
    public const CLASSES = ['S.1', 'S.2', 'S.3', 'S.4', 'S.5', 'S.6'];
    /** results for a class or stream with fewer ballots than this stay hidden (they could point at someone) */
    public const MIN_GROUP = 5;

    public static function find(PDO $db, int $id): ?array
    {
        $stmt = $db->prepare('SELECT * FROM polls WHERE id = ?');
        $stmt->execute([$id]);
        $poll = $stmt->fetch();
        return $poll ? self::shape($poll) : null;
    }

    public static function shape(array $p): array
    {
        $p['id'] = (int) $p['id'];
        $p['audience'] = $p['audience'] !== null ? (json_decode((string) $p['audience'], true) ?: null) : null;
        $p['accepting'] = self::accepting($p);
        return $p;
    }

    /** Open, and not past its closing time */
    public static function accepting(array $p): bool
    {
        return $p['status'] === 'open' && (!$p['closes_at'] || strtotime((string) $p['closes_at']) > time());
    }

    public static function forClass(array $p, string $class): bool
    {
        return !$p['audience'] || in_array($class, $p['audience'], true);
    }

    public static function questions(PDO $db, int $pollId): array
    {
        $stmt = $db->prepare('SELECT id, position, section, prompt, type, options, required FROM questions WHERE poll_id = ? ORDER BY position, id');
        $stmt->execute([$pollId]);
        return array_map(fn ($q) => [
            'id' => (int) $q['id'],
            'position' => (int) $q['position'],
            'section' => $q['section'],
            'prompt' => $q['prompt'],
            'type' => $q['type'],
            'options' => $q['options'] !== null ? (json_decode((string) $q['options'], true) ?: []) : [],
            'required' => (bool) $q['required'],
        ], $stmt->fetchAll());
    }

    public static function voteCount(PDO $db, int $pollId): int
    {
        $stmt = $db->prepare('SELECT COUNT(*) FROM voters WHERE poll_id = ?');
        $stmt->execute([$pollId]);
        return (int) $stmt->fetchColumn();
    }

    /** Active students who may vote in this poll */
    public static function eligibleCount(PDO $db, array $poll): int
    {
        if (!$poll['audience']) {
            return (int) $db->query("SELECT COUNT(*) FROM students WHERE status = 'active'")->fetchColumn();
        }
        $in = implode(',', array_fill(0, count($poll['audience']), '?'));
        $stmt = $db->prepare("SELECT COUNT(*) FROM students WHERE status = 'active' AND class IN ($in)");
        $stmt->execute($poll['audience']);
        return (int) $stmt->fetchColumn();
    }
}
