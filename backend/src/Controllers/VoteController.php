<?php

declare(strict_types=1);

namespace StudentVoice\Controllers;

use StudentVoice\Auth\Auth;
use StudentVoice\Database;
use StudentVoice\Http\Request;
use StudentVoice\Http\Response;
use StudentVoice\Support\Polls;

/** The student's side: the polls open to their class, and casting a ballot - once. */
final class VoteController
{
    public function polls(Request $r): never
    {
        $student = Auth::student();
        $db = Database::get();
        $stmt = $db->prepare(
            "SELECT p.*, v.voted_at, (SELECT COUNT(*) FROM questions q WHERE q.poll_id = p.id) AS questions
             FROM polls p LEFT JOIN voters v ON v.poll_id = p.id AND v.student_id = ?
             WHERE p.status IN ('open', 'closed') ORDER BY p.status = 'open' DESC, p.opened_at DESC"
        );
        $stmt->execute([$student['id']]);
        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $p = Polls::shape($row);
            if (!Polls::forClass($p, $student['class'])) {
                continue;
            }
            // closed polls are listed only if this student voted in them (a record of taking part)
            if (!$p['accepting'] && !$row['voted_at']) {
                continue;
            }
            $out[] = [
                'id' => $p['id'],
                'title' => $p['title'],
                'intro' => $p['intro'],
                'closes_at' => $p['closes_at'],
                'accepting' => $p['accepting'],
                'questions' => (int) $row['questions'],
                'voted' => (bool) $row['voted_at'],
            ];
        }
        Response::ok($out);
    }

    public function poll(Request $r): never
    {
        [$poll] = $this->votable((int) $r->param('id'));
        Response::ok([
            'id' => $poll['id'],
            'title' => $poll['title'],
            'intro' => $poll['intro'],
            'closes_at' => $poll['closes_at'],
            'questions' => Polls::questions(Database::get(), $poll['id']),
        ]);
    }

    public function submit(Request $r): never
    {
        [$poll, $student] = $this->votable((int) $r->param('id'));
        $db = Database::get();
        $questions = Polls::questions($db, $poll['id']);
        $given = $r->input('answers', []);
        $given = is_array($given) ? $given : [];

        $rows = [];
        $errors = [];
        foreach ($questions as $n => $q) {
            $raw = $given[$q['id']] ?? $given[(string) $q['id']] ?? null;
            [$num, $text, $empty, $bad] = $this->read($q, $raw);
            if ($bad) {
                $errors[$q['id']] = 'This answer is not one of the choices.';
            } elseif ($empty && $q['required']) {
                $errors[$q['id']] = 'Please answer this question.';
            }
            if (!$empty && !$bad) {
                $rows[] = [$q['id'], $num, $text];
            }
        }
        if ($errors) {
            Response::error(count($errors) === 1 ? 'One question still needs an answer.' : count($errors) . ' questions still need an answer.', 422, $errors);
        }

        $ok = Database::transaction(function ($db) use ($poll, $student, $rows) {
            // The gate: this primary key (poll, student) can only exist once
            $gate = $db->prepare('INSERT IGNORE INTO voters (poll_id, student_id, class) VALUES (?, ?, ?)');
            $gate->execute([$poll['id'], $student['id'], $student['class']]);
            if ($gate->rowCount() !== 1) {
                return false;
            }
            $ballot = bin2hex(random_bytes(16));
            $db->prepare('INSERT INTO ballots (id, poll_id, class, stream, cast_on) VALUES (?, ?, ?, ?, CURDATE())')
                ->execute([$ballot, $poll['id'], $student['class'], $student['stream']]);
            $answer = $db->prepare('INSERT INTO answers (ballot_id, question_id, value_num, value_text) VALUES (?, ?, ?, ?)');
            foreach ($rows as [$qid, $num, $text]) {
                $answer->execute([$ballot, $qid, $num, $text]);
            }
            return true;
        });
        if (!$ok) {
            Response::error('You have already voted in this poll. Each student votes once.', 409, ['code' => 'already_voted']);
        }
        Response::ok(['voted' => true]);
    }

    /** @return array{0: ?int, 1: ?string, 2: bool, 3: bool} [number, text, empty, not allowed] */
    private function read(array $q, mixed $raw): array
    {
        switch ($q['type']) {
            case 'scale':
                $v = is_numeric($raw) ? (int) $raw : null;
                return $v === null ? [null, null, true, false] : [$v, null, false, $v < 1 || $v > 5];
            case 'yesno':
                if ($raw === null || $raw === '') {
                    return [null, null, true, false];
                }
                $v = is_bool($raw) ? (int) $raw : (is_numeric($raw) ? (int) $raw : -1);
                return [$v, null, false, $v !== 0 && $v !== 1];
            case 'choice':
                $v = is_numeric($raw) ? (int) $raw : null;
                return $v === null ? [null, null, true, false] : [$v, null, false, $v < 0 || $v >= count($q['options'])];
            case 'multi':
                $list = is_array($raw) ? array_values(array_unique(array_map('intval', array_filter($raw, 'is_numeric')))) : [];
                sort($list);
                if (!$list) {
                    return [null, null, true, false];
                }
                $bad = (bool) array_filter($list, fn ($i) => $i < 0 || $i >= count($q['options']));
                return [null, json_encode($list), false, $bad];
            default:
                $t = is_string($raw) ? trim($raw) : '';
                return $t === '' ? [null, null, true, false] : [null, mb_substr($t, 0, 3000), false, false];
        }
    }

    /** The poll, if this student may vote in it now */
    private function votable(int $id): array
    {
        $student = Auth::student();
        $db = Database::get();
        $poll = Polls::find($db, $id);
        if (!$poll || $poll['status'] === 'draft' || !Polls::forClass($poll, $student['class'])) {
            Response::error('This poll is not open to your class.', 404);
        }
        if (!$poll['accepting']) {
            Response::error('This poll has closed.', 410);
        }
        $voted = $db->prepare('SELECT 1 FROM voters WHERE poll_id = ? AND student_id = ?');
        $voted->execute([$poll['id'], $student['id']]);
        if ($voted->fetchColumn()) {
            Response::error('You have already voted in this poll. Each student votes once.', 409, ['code' => 'already_voted']);
        }
        return [$poll, $student];
    }
}
