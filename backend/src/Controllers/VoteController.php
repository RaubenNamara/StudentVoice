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
use StudentVoice\Support\Sample;

/**
 * The student's side: the polls open to their class, and casting a ballot.
 *
 * - a school poll: once per student (voters' primary key)
 * - a teacher poll: by subject. When the Teaching list (or Tusome) names the student's teacher for
 *   a subject, they rate that teacher - no choice. With nobody listed, they pick their teacher from
 *   the subject's department. Each teacher once (vote_gates fingerprint per student, teacher and
 *   subject); a subject with two teachers listed for the stream asks for both. "I don't take this
 *   subject" closes a subject and counts as "not my teacher" for any listed teacher, so admins can
 *   fix the list.
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
                'questions' => $p['about'] === 'teachers' && $p['sample_size'] ? min((int) $p['sample_size'], (int) $row['questions']) : (int) $row['questions'],
            ];
            if ($p['about'] === 'teachers') {
                $subjects = $this->subjects($db, $p, $student);
                // progress counts subjects (a subject with several listed teachers is one step)
                $core = array_filter($subjects, fn ($x) => !$x['elective']) ?: $subjects;
                $item['teachers'] = count($core);
                $item['handled'] = count(array_filter($core, fn ($x) => $x['done'] >= $x['places']));
                $item['subjects'] = count($subjects);
                $item['voted'] = $subjects && $item['handled'] >= $item['teachers'];
                if (!$subjects || (!$p['accepting'] && !$item['handled'])) {
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
        [$poll, $student, $subjects] = $this->votable((int) $r->param('id'));
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
            $names = $db->query('SELECT id, name FROM teachers')->fetchAll(PDO::FETCH_KEY_PAIR);
            $people = fn (array $ids) => array_map(fn ($id) => ['id' => $id, 'name' => $names[$id] ?? ''], $ids);
            $out['subjects'] = array_map(fn ($s) => [
                'subject_id' => $s['subject_id'],
                'subject' => $s['subject'],
                'places' => $s['places'],
                'done' => $s['done'],
                'skipped' => $s['skipped'],
                'assigned' => $people($s['assigned']),
                'options' => $people($s['options']),
                'rated' => $s['rated'],
                'question_ids' => $s['question_ids'],
                'elective' => $s['elective'],
            ], $subjects);
        }
        Response::ok($out);
    }

    public function submit(Request $r): never
    {
        [$poll, $student, $subjects] = $this->votable((int) $r->param('id'));
        $db = Database::get();
        $questions = Polls::questions($db, $poll['id']);
        $teacherId = 0;
        $subjectId = 0;
        $markNotMine = [];
        if ($poll['about'] === 'teachers') {
            [$subject, $teacherId] = $this->pickSubject($r, $subjects, true);
            $subjectId = $subject['subject_id'];
            $asked = array_flip($subject['question_ids']);
            $questions = array_values(array_filter($questions, fn ($q) => isset($asked[$q['id']])));
        }
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

        $ok = Database::transaction(function (PDO $db) use ($poll, $student, $rows, $teacherId, $subjectId, $markNotMine) {
            if ($teacherId) {
                // The gate: one fingerprint per student, teacher and subject - with no student id
                $gate = $db->prepare('INSERT IGNORE INTO vote_gates (poll_id, gate, teacher_id, subject_id) VALUES (?, ?, ?, ?)');
                $gate->execute([$poll['id'], Gate::for($poll['id'], (int) $student['id'], $teacherId, $subjectId), $teacherId, $subjectId]);
                if ($gate->rowCount() !== 1) {
                    return false;
                }
                $this->markNotMine($db, $poll['id'], (int) $student['id'], $subjectId, $markNotMine);
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

    /**
     * Closes a subject: "I don't take this subject" (nothing rated), or "I'm done" after rating
     * the one(s) of several listed teachers who teach them. Listed teachers they didn't rate get a
     * "not my teacher", so admins can correct the list.
     */
    public function skip(Request $r): never
    {
        [$poll, $student, $subjects] = $this->votable((int) $r->param('id'));
        if ($poll['about'] !== 'teachers') {
            Response::error('Only teacher polls have this.', 422);
        }
        [$subject] = $this->pickSubject($r, $subjects, false);
        $unrated = array_values(array_diff($subject['assigned'], $subject['rated']));
        Database::transaction(function (PDO $db) use ($poll, $student, $subject, $unrated) {
            $db->prepare('INSERT IGNORE INTO vote_gates (poll_id, gate, teacher_id, subject_id, skipped) VALUES (?, ?, 0, ?, 1)')
                ->execute([$poll['id'], Gate::for($poll['id'], (int) $student['id'], 0, $subject['subject_id']), $subject['subject_id']]);
            $this->markNotMine($db, $poll['id'], (int) $student['id'], $subject['subject_id'], $unrated);
        });
        Response::ok();
    }

    private function markNotMine(PDO $db, int $pollId, int $studentId, int $subjectId, array $teacherIds): void
    {
        $stmt = $db->prepare('INSERT IGNORE INTO vote_gates (poll_id, gate, teacher_id, subject_id, skipped) VALUES (?, ?, ?, ?, 1)');
        foreach ($teacherIds as $t) {
            $stmt->execute([$pollId, Gate::for($pollId, $studentId, (int) $t, $subjectId), $t, $subjectId]);
        }
    }

    /**
     * The student's subjects for a teacher poll, each with: the listed teachers, the teachers they
     * may choose from, how many teachers they rate (places), which they have rated, and whether
     * they said they don't take it.
     */
    private function subjects(PDO $db, array $poll, array $student): array
    {
        $list = TeachingList::subjectsFor($db, $student);
        // subjects Tusome says this student takes; others on their stream's list are electives
        // (optional: only if they take them). With no Tusome subjects at all, none are electives.
        $mine = $db->prepare('SELECT subject_id FROM student_subjects WHERE student_id = ?');
        $mine->execute([$student['id']]);
        $mine = array_flip(array_map('intval', $mine->fetchAll(PDO::FETCH_COLUMN)));
        if (!$list) {
            return [];
        }
        $names = $db->query("SELECT id, name FROM subjects")->fetchAll(PDO::FETCH_KEY_PAIR);
        $dept = [];
        foreach ($db->query(
            "SELECT ts.subject_id, t.id FROM teacher_subjects ts JOIN teachers t ON t.id = ts.teacher_id AND t.status = 'active' ORDER BY t.name"
        )->fetchAll(PDO::FETCH_NUM) as [$s, $t]) {
            $dept[(int) $s][] = (int) $t;
        }
        $questions = Polls::questions($db, $poll['id']);
        $sid = (int) $student['id'];

        // every fingerprint this student could have in the poll, to look up in one query
        $gates = [];
        $out = [];
        foreach ($list as $su => $assigned) {
            // a listed teacher is the only choice; with nobody listed, the department's teachers
            $options = $assigned ?: array_values(array_unique($dept[$su] ?? []));
            if (!$options) {
                continue; // a subject with no teachers at all
            }
            foreach ([0, ...$options] as $t) {
                $gates[Gate::for($poll['id'], $sid, $t, $su)] = [$su, $t];
            }
            $out[$su] = [
                'subject_id' => $su,
                'subject' => $names[$su] ?? '',
                'assigned' => $assigned,
                'options' => $options,
                'places' => max(1, count($assigned)),
                'elective' => $mine && !isset($mine[$su]),
                'rated' => [],
                'skipped' => false,
                'done' => 0,
                'question_ids' => Sample::questionIds($questions, $poll['sample_size'] ? (int) $poll['sample_size'] : null, Gate::for($poll['id'], $sid, 0, $su)),
            ];
        }
        if ($gates) {
            foreach (array_chunk(array_keys($gates), 400) as $chunk) {
                $stmt = $db->prepare('SELECT gate, skipped FROM vote_gates WHERE poll_id = ? AND gate IN (' . implode(',', array_fill(0, count($chunk), '?')) . ')');
                $stmt->execute([$poll['id'], ...$chunk]);
                foreach ($stmt->fetchAll() as $row) {
                    [$su, $t] = $gates[$row['gate']];
                    if ($t === 0) {
                        $out[$su]['skipped'] = true;
                    } elseif (!(int) $row['skipped']) {
                        $out[$su]['rated'][] = $t;
                    }
                }
            }
        }
        foreach ($out as &$s) {
            $s['done'] = $s['skipped'] ? $s['places'] : min($s['places'], count($s['rated']));
        }
        return array_values($out);
    }

    /** The subject (and teacher) asked for, which must be open for this student */
    private function pickSubject(Request $r, array $subjects, bool $withTeacher): array
    {
        $s = (int) $r->input('subject_id', 0);
        foreach ($subjects as $subject) {
            if ($subject['subject_id'] !== $s) {
                continue;
            }
            if ($subject['done'] >= $subject['places']) {
                Response::error('You have finished this subject.', 409, ['code' => 'already_voted']);
            }
            if (!$withTeacher) {
                return [$subject, 0];
            }
            $t = (int) $r->input('teacher_id', 0);
            if (!in_array($t, $subject['options'], true)) {
                Response::error('Choose your teacher from the list.', 422);
            }
            if (in_array($t, $subject['rated'], true)) {
                Response::error('You have already rated this teacher.', 409);
            }
            return [$subject, $t];
        }
        Response::error('That subject is not on your list for this poll.', 422);
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

    /** The poll, if this student may vote in it now; for a teacher poll also their subjects */
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
            $subjects = $this->subjects($db, $poll, $student);
            if (!$subjects) {
                Response::error('No subjects are listed for you yet. Tell the person running the vote.', 404);
            }
            if (!array_filter($subjects, fn ($x) => $x['done'] < $x['places'])) {
                Response::error('You have rated all your teachers in this poll. Thank you!', 409, ['code' => 'already_voted']);
            }
            return [$poll, $student, $subjects];
        }
        $voted = $db->prepare('SELECT 1 FROM voters WHERE poll_id = ? AND student_id = ?');
        $voted->execute([$poll['id'], $student['id']]);
        if ($voted->fetchColumn()) {
            Response::error('You have already voted in this poll. Each student votes once.', 409, ['code' => 'already_voted']);
        }
        return [$poll, $student, []];
    }
}
