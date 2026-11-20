<?php

declare(strict_types=1);

namespace StudentVoice\Controllers;

use PDO;
use StudentVoice\Auth\Auth;
use StudentVoice\Database;
use StudentVoice\Http\Request;
use StudentVoice\Http\Response;
use StudentVoice\Services\TeachingList;
use StudentVoice\Support\Gate;
use StudentVoice\Support\Polls;

/**
 * The student's side: the polls open to their class, and casting a ballot.
 * - a school poll: once per student (voters' primary key)
 * - a teacher poll: once per student per teacher and subject (vote_gates fingerprint); a student
 *   may also say "not my teacher", which is counted per teacher without any answers
 */
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
        $targets = null;
        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $p = Polls::shape($row);
            if (!Polls::forClass($p, $student['class'])) {
                continue;
            }
            $item = [
                'id' => $p['id'],
                'title' => $p['title'],
                'intro' => $p['intro'],
                'about' => $p['about'],
                'closes_at' => $p['closes_at'],
                'accepting' => $p['accepting'],
                'questions' => (int) $row['questions'],
            ];
            if ($p['about'] === 'teachers') {
                $targets ??= TeachingList::targets($db, $student);
                $status = $this->statuses($db, $p['id'], (int) $student['id'], $targets);
                $item['teachers'] = count($targets);
                $item['handled'] = count(array_filter($status));
                $item['voted'] = $targets && $item['handled'] === count($targets);
                if (!$targets || (!$p['accepting'] && !$item['handled'])) {
                    continue;
                }
            } else {
                $item['voted'] = (bool) $row['voted_at'];
                if (!$p['accepting'] && !$item['voted']) {
                    continue;
                }
            }
            $out[] = $item;
        }
        Response::ok($out);
    }

    public function poll(Request $r): never
    {
        [$poll, $student, $targets] = $this->votable((int) $r->param('id'));
        $db = Database::get();
        $out = [
            'id' => $poll['id'],
            'title' => $poll['title'],
            'intro' => $poll['intro'],
            'about' => $poll['about'],
            'closes_at' => $poll['closes_at'],
            'questions' => Polls::questions($db, $poll['id']),
        ];
        if ($poll['about'] === 'teachers') {
            $out['teachers'] = $this->targetList($db, $poll['id'], (int) $student['id'], $targets);
        }
        Response::ok($out);
    }

    public function submit(Request $r): never
    {
        [$poll, $student, $targets] = $this->votable((int) $r->param('id'));
        $db = Database::get();
        [$teacherId, $subjectId] = $this->pickTarget($r, $poll, $targets);
        $questions = Polls::questions($db, $poll['id']);
        $given = $r->input('answers', []);
        $given = is_array($given) ? $given : [];

        $rows = [];
        $errors = [];
        foreach ($questions as $q) {
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

        $ok = Database::transaction(function (PDO $db) use ($poll, $student, $rows, $teacherId, $subjectId) {
            if ($teacherId) {
                // The gate: one fingerprint per student, teacher and subject - with no student id
                $gate = $db->prepare('INSERT IGNORE INTO vote_gates (poll_id, gate, teacher_id, subject_id) VALUES (?, ?, ?, ?)');
                $gate->execute([$poll['id'], Gate::for($poll['id'], (int) $student['id'], $teacherId, $subjectId), $teacherId, $subjectId]);
                if ($gate->rowCount() !== 1) {
                    return false;
                }
                // took part in the poll (turnout only; no teacher here)
                $db->prepare('INSERT IGNORE INTO voters (poll_id, student_id, class, stream) VALUES (?, ?, ?, ?)')
                    ->execute([$poll['id'], $student['id'], $student['class'], $student['stream']]);
            } else {
                // The gate: this primary key (poll, student) can only exist once
                $gate = $db->prepare('INSERT IGNORE INTO voters (poll_id, student_id, class, stream) VALUES (?, ?, ?, ?)');
                $gate->execute([$poll['id'], $student['id'], $student['class'], $student['stream']]);
                if ($gate->rowCount() !== 1) {
                    return false;
                }
            }
            $ballot = bin2hex(random_bytes(16));
            $db->prepare('INSERT INTO ballots (id, poll_id, teacher_id, subject_id, class, stream, cast_on) VALUES (?, ?, ?, ?, ?, ?, CURDATE())')
                ->execute([$ballot, $poll['id'], $teacherId ?: null, $subjectId ?: null, $student['class'], $student['stream']]);
            $answer = $db->prepare('INSERT INTO answers (ballot_id, question_id, value_num, value_text) VALUES (?, ?, ?, ?)');
            foreach ($rows as [$qid, $num, $text]) {
                $answer->execute([$ballot, $qid, $num, $text]);
            }
            return true;
        });
        if (!$ok) {
            Response::error($teacherId ? 'You have already rated this teacher in this poll.' : 'You have already voted in this poll. Each student votes once.', 409, ['code' => 'already_voted']);
        }
        Response::ok(['voted' => true]);
    }

    /** "This is not my teacher": counted for the admins, no answers saved */
    public function skip(Request $r): never
    {
        [$poll, $student, $targets] = $this->votable((int) $r->param('id'));
        if ($poll['about'] !== 'teachers') {
            Response::error('Only teacher polls have this.', 422);
        }
        [$teacherId, $subjectId] = $this->pickTarget($r, $poll, $targets);
        $gate = Database::get()->prepare('INSERT IGNORE INTO vote_gates (poll_id, gate, teacher_id, subject_id, skipped) VALUES (?, ?, ?, ?, 1)');
        $gate->execute([$poll['id'], Gate::for($poll['id'], (int) $student['id'], $teacherId, $subjectId), $teacherId, $subjectId]);
        if ($gate->rowCount() !== 1) {
            Response::error('You have already answered for this teacher.', 409, ['code' => 'already_voted']);
        }
        Response::ok();
    }

    /** gate key => true for each target this student has already rated or skipped */
    private function statuses(PDO $db, int $pollId, int $studentId, array $targets): array
    {
        if (!$targets) {
            return [];
        }
        $gates = [];
        foreach ($targets as [$t, $s]) {
            $gates[Gate::for($pollId, $studentId, $t, $s)] = "$t|$s";
        }
        $in = implode(',', array_fill(0, count($gates), '?'));
        $stmt = $db->prepare("SELECT gate, skipped FROM vote_gates WHERE poll_id = ? AND gate IN ($in)");
        $stmt->execute([$pollId, ...array_keys($gates)]);
        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $out[$gates[$row['gate']]] = (int) $row['skipped'] ? 'skipped' : 'done';
        }
        return $out;
    }

    private function targetList(PDO $db, int $pollId, int $studentId, array $targets): array
    {
        $status = $this->statuses($db, $pollId, $studentId, $targets);
        $names = $db->query('SELECT id, name FROM teachers')->fetchAll(PDO::FETCH_KEY_PAIR);
        $subjects = $db->query('SELECT id, name FROM subjects')->fetchAll(PDO::FETCH_KEY_PAIR);
        return array_map(fn ($x) => [
            'teacher_id' => $x[0],
            'teacher' => $names[$x[0]] ?? '',
            'subject_id' => $x[1],
            'subject' => $subjects[$x[1]] ?? '',
            'status' => $status["{$x[0]}|{$x[1]}"] ?? 'todo',
        ], $targets);
    }

    /** For a teacher poll: the teacher and subject asked for, which must be one of this student's */
    private function pickTarget(Request $r, array $poll, array $targets): array
    {
        if ($poll['about'] !== 'teachers') {
            return [0, 0];
        }
        $t = (int) $r->input('teacher_id', 0);
        $s = (int) $r->input('subject_id', 0);
        foreach ($targets as [$tt, $ss]) {
            if ($tt === $t && $ss === $s) {
                return [$t, $s];
            }
        }
        Response::error('That teacher is not on your list for this poll.', 422);
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

    /** The poll, if this student may vote in it now; for a teacher poll also their teachers */
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
        if ($poll['about'] === 'teachers') {
            $targets = TeachingList::targets($db, $student);
            if (!$targets) {
                Response::error('No teachers are listed for you yet. Tell the person running the vote.', 404);
            }
            $status = $this->statuses($db, $poll['id'], (int) $student['id'], $targets);
            if (count($status) === count($targets)) {
                Response::error('You have rated all your teachers in this poll. Thank you!', 409, ['code' => 'already_voted']);
            }
            return [$poll, $student, $targets];
        }
        $voted = $db->prepare('SELECT 1 FROM voters WHERE poll_id = ? AND student_id = ?');
        $voted->execute([$poll['id'], $student['id']]);
        if ($voted->fetchColumn()) {
            Response::error('You have already voted in this poll. Each student votes once.', 409, ['code' => 'already_voted']);
        }
        return [$poll, $student, []];
    }
}
