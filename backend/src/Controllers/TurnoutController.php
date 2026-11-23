<?php

declare(strict_types=1);

namespace StudentVoice\Controllers;

use PDO;
use StudentVoice\Database;
use StudentVoice\Http\Request;
use StudentVoice\Http\Response;
use StudentVoice\Support\Polls;

/**
 * Turnout for the admins' dashboard: how many of the students who may vote in a poll have taken
 * part, by class and stream, and who they are (to chase the rest).
 *
 * Only WHO took part (the voters table: student + when), never what they answered or which teacher
 * they rated: ballots carry no student, and vote_gates only an anonymous fingerprint. In a teacher
 * poll a student counts as having taken part from their first rating.
 */
final class TurnoutController
{
    /** GET /admin/polls/{id}/turnout - totals, by class and by stream */
    public function show(Request $r): never
    {
        $db = Database::get();
        $poll = $this->poll($db, (int) $r->param('id'));
        [$where, $args] = $this->eligible($poll);
        $stmt = $db->prepare(
            "SELECT s.class, s.stream, COUNT(*) AS eligible, COUNT(v.student_id) AS voted
             FROM students s LEFT JOIN voters v ON v.poll_id = ? AND v.student_id = s.id
             WHERE $where GROUP BY s.class, s.stream ORDER BY s.class, s.stream"
        );
        $stmt->execute([$poll['id'], ...$args]);
        $streams = array_map(fn ($row) => [
            'class' => $row['class'],
            'stream' => $row['stream'],
            'eligible' => (int) $row['eligible'],
            'voted' => (int) $row['voted'],
        ], $stmt->fetchAll());

        $classes = [];
        foreach ($streams as $s) {
            $classes[$s['class']] ??= ['class' => $s['class'], 'eligible' => 0, 'voted' => 0];
            $classes[$s['class']]['eligible'] += $s['eligible'];
            $classes[$s['class']]['voted'] += $s['voted'];
        }
        Response::ok([
            'poll' => ['id' => $poll['id'], 'title' => $poll['title'], 'about' => $poll['about'], 'status' => $poll['status']],
            'eligible' => array_sum(array_column($streams, 'eligible')),
            'voted' => array_sum(array_column($streams, 'voted')),
            'classes' => array_values($classes),
            'streams' => $streams,
        ]);
    }

    /**
     * GET /admin/polls/{id}/voters?show=voted|not&class=&stream=&q=&page= - the students who have
     * (or have not yet) taken part, 50 a page, newest first
     */
    public function voters(Request $r): never
    {
        $db = Database::get();
        $poll = $this->poll($db, (int) $r->param('id'));
        [$where, $args] = $this->eligible($poll);
        $voted = $r->query('show', 'voted') !== 'not';
        $where .= $voted ? ' AND v.student_id IS NOT NULL' : ' AND v.student_id IS NULL';
        $class = (string) $r->query('class', '');
        if (in_array($class, Polls::CLASSES, true)) {
            $where .= ' AND s.class = ?';
            $args[] = $class;
        }
        $stream = strtoupper(trim((string) $r->query('stream', '')));
        if ($stream !== '') {
            $where .= ' AND s.stream = ?';
            $args[] = mb_substr($stream, 0, 20);
        }
        $q = trim((string) $r->query('q', ''));
        if ($q !== '') {
            $where .= ' AND (s.name LIKE ? OR s.reg_no LIKE ?)';
            $like = '%' . addcslashes(mb_substr($q, 0, 80), '%_\\') . '%';
            array_push($args, $like, $like);
        }
        $from = "FROM students s LEFT JOIN voters v ON v.poll_id = ? AND v.student_id = s.id WHERE $where";
        $count = $db->prepare("SELECT COUNT(*) $from");
        $count->execute([$poll['id'], ...$args]);
        $total = (int) $count->fetchColumn();

        $per = 50;
        $page = max(1, (int) $r->query('page', 1));
        $order = $voted ? 'v.voted_at DESC' : 's.class, s.stream, s.name';
        $stmt = $db->prepare("SELECT s.id, s.name, s.reg_no, s.class, s.stream, v.voted_at $from ORDER BY $order LIMIT $per OFFSET " . (($page - 1) * $per));
        $stmt->execute([$poll['id'], ...$args]);
        Response::ok([
            'total' => $total,
            'page' => $page,
            'pages' => max(1, (int) ceil($total / $per)),
            'students' => array_map(fn ($s) => [
                'id' => (int) $s['id'],
                'name' => $s['name'],
                'reg_no' => $s['reg_no'],
                'class' => $s['class'],
                'stream' => $s['stream'],
                'voted_at' => $s['voted_at'],
            ], $stmt->fetchAll()),
        ]);
    }

    private function poll(PDO $db, int $id): array
    {
        $poll = Polls::find($db, $id);
        if (!$poll || $poll['status'] === 'draft') {
            Response::error('Poll not found.', 404);
        }
        return $poll;
    }

    /** Active students in the poll's classes: [SQL condition on s, its arguments] */
    private function eligible(array $poll): array
    {
        if (!$poll['audience']) {
            return ["s.status = 'active'", []];
        }
        return ["s.status = 'active' AND s.class IN (" . implode(',', array_fill(0, count($poll['audience']), '?')) . ')', $poll['audience']];
    }
}
