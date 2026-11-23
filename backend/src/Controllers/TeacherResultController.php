<?php

declare(strict_types=1);

namespace StudentVoice\Controllers;

use PDO;
use StudentVoice\Database;
use StudentVoice\Http\Request;
use StudentVoice\Http\Response;
use StudentVoice\Services\TeachingList;
use StudentVoice\Support\Polls;

/**
 * Teacher poll results, grouped by teacher and subject - for admins only (teachers have no
 * account here and never see these).
 *
 * Scores are averages of the poll's agree/disagree (1-5) questions: overall, and per section.
 * A teacher with fewer than Polls::MIN_GROUP ratings shows counts only, never scores.
 */
final class TeacherResultController
{
    /** GET /admin/polls/{id}/teachers - the ranking table */
    public function ranking(Request $r): never
    {
        $db = Database::get();
        $poll = $this->teacherPoll((int) $r->param('id'));
        $questions = Polls::questions($db, $poll['id']);
        $scale = array_values(array_filter($questions, fn ($q) => $q['type'] === 'scale'));
        $sections = array_values(array_unique(array_map(fn ($q) => $q['section'] ?? '', $scale)));

        $sums = $this->scaleSums($db, $poll['id']);
        $ballots = $this->ballotCounts($db, $poll['id']);
        $skips = [];
        $stmt = $db->prepare('SELECT teacher_id, subject_id, COUNT(*) FROM vote_gates WHERE poll_id = ? AND skipped = 1 AND teacher_id <> 0 GROUP BY teacher_id, subject_id');
        $stmt->execute([$poll['id']]);
        foreach ($stmt->fetchAll(PDO::FETCH_NUM) as [$t, $s, $n]) {
            $skips["$t|$s"] = (int) $n;
        }
        $expected = $this->expected($db, $poll);
        $previous = $this->previousPoll($db, $poll);
        $prevSums = $previous ? $this->scaleSums($db, $previous['id']) : [];
        $prevBallots = $previous ? $this->ballotCounts($db, $previous['id']) : [];

        $names = $db->query('SELECT id, name FROM teachers')->fetchAll(PDO::FETCH_KEY_PAIR);
        $subjects = $db->query('SELECT id, name FROM subjects')->fetchAll(PDO::FETCH_KEY_PAIR);
        $sectionOf = [];
        foreach ($scale as $q) {
            $sectionOf[$q['id']] = $q['section'] ?? '';
        }

        $keys = array_unique(array_merge(array_keys($expected), array_keys($ballots), array_keys($skips)));
        $rows = [];
        $schoolSum = ['' => [0, 0]];
        $schoolSec = array_fill_keys($sections, [0, 0]);
        foreach ($keys as $k) {
            [$t, $s] = array_map('intval', explode('|', $k));
            $n = $ballots[$k] ?? 0;
            $row = [
                'teacher_id' => $t,
                'teacher' => $names[$t] ?? '(removed)',
                'subject_id' => $s,
                'subject' => $subjects[$s] ?? '',
                'ratings' => $n,
                'expected' => $expected[$k] ?? 0,
                'not_my_teacher' => $skips[$k] ?? 0,
                'enough' => $n >= Polls::MIN_GROUP,
                'overall' => null,
                'sections' => [],
                'change' => null,
            ];
            [$all, $bySec] = $this->scores($sums[$k] ?? [], $sectionOf);
            foreach ($sums[$k] ?? [] as $qid => [$sum, $cnt]) {
                $schoolSum[''][0] += $sum;
                $schoolSum[''][1] += $cnt;
                $sec = $sectionOf[$qid] ?? null;
                if ($sec !== null && isset($schoolSec[$sec])) {
                    $schoolSec[$sec][0] += $sum;
                    $schoolSec[$sec][1] += $cnt;
                }
            }
            if ($row['enough']) {
                $row['overall'] = $all;
                $row['sections'] = array_map(fn ($sec) => $bySec[$sec] ?? null, $sections);
                if (($prevBallots[$k] ?? 0) >= Polls::MIN_GROUP && $all !== null) {
                    [$prevAll] = $this->scores($prevSums[$k] ?? [], []);
                    $row['change'] = $prevAll !== null ? round($all - $prevAll, 2) : null;
                }
            }
            $rows[] = $row;
        }
        usort($rows, fn ($a, $b) => [$b['overall'] ?? -1, $a['teacher']] <=> [$a['overall'] ?? -1, $b['teacher']]);

        Response::ok([
            'poll' => $poll,
            'sections' => $sections,
            'school' => [
                'overall' => $schoolSum[''][1] ? round($schoolSum[''][0] / $schoolSum[''][1], 2) : null,
                'sections' => array_map(fn ($sec) => $schoolSec[$sec][1] ? round($schoolSec[$sec][0] / $schoolSec[$sec][1], 2) : null, $sections),
            ],
            'previous' => $previous ? ['id' => $previous['id'], 'title' => $previous['title']] : null,
            'min_group' => Polls::MIN_GROUP,
            'has_scale' => (bool) $scale,
            'rows' => $rows,
        ]);
    }

    /**
     * GET /admin/polls/{id}/teachers/leaders - for the best/lowest performers charts: each teacher's
     * spread of answers (SD..SA counts) per agree/disagree statement. Only teachers with enough
     * ratings overall; the page also skips a statement with fewer than MIN_GROUP answers.
     */
    public function leaders(Request $r): never
    {
        $db = Database::get();
        $poll = $this->teacherPoll((int) $r->param('id'));
        $questions = array_values(array_map(
            fn ($q) => ['id' => $q['id'], 'prompt' => $q['prompt'], 'section' => $q['section']],
            array_filter(Polls::questions($db, $poll['id']), fn ($q) => $q['type'] === 'scale')
        ));
        $ballots = $this->ballotCounts($db, $poll['id']);
        $stmt = $db->prepare(
            "SELECT b.teacher_id, b.subject_id, a.question_id, a.value_num, COUNT(*)
             FROM answers a JOIN ballots b ON b.id = a.ballot_id JOIN questions q ON q.id = a.question_id AND q.type = 'scale'
             WHERE b.poll_id = ? AND b.teacher_id IS NOT NULL GROUP BY b.teacher_id, b.subject_id, a.question_id, a.value_num"
        );
        $stmt->execute([$poll['id']]);
        $counts = [];
        foreach ($stmt->fetchAll(PDO::FETCH_NUM) as [$t, $s, $q, $v, $n]) {
            if ($v >= 1 && $v <= 5 && ($ballots["$t|$s"] ?? 0) >= Polls::MIN_GROUP) {
                $counts["$t|$s"][(int) $q] ??= [0, 0, 0, 0, 0];
                $counts["$t|$s"][(int) $q][(int) $v - 1] = (int) $n;
            }
        }
        $names = $db->query('SELECT id, name FROM teachers')->fetchAll(PDO::FETCH_KEY_PAIR);
        $subjects = $db->query('SELECT id, name FROM subjects')->fetchAll(PDO::FETCH_KEY_PAIR);
        $teachers = [];
        foreach ($counts as $k => $byQ) {
            [$t, $s] = array_map('intval', explode('|', $k));
            $teachers[] = ['teacher_id' => $t, 'teacher' => $names[$t] ?? '', 'subject_id' => $s, 'subject' => $subjects[$s] ?? '', 'ratings' => $ballots[$k], 'counts' => $byQ];
        }
        Response::ok(['questions' => $questions, 'teachers' => $teachers, 'min_group' => Polls::MIN_GROUP]);
    }

    /**
     * GET /admin/polls/{id}/teachers/meeting - the printed meeting report: every teacher (per subject)
     * with enough ratings, with their spread of answers per statement and the students' written
     * comments (per comment question). Teachers with fewer than MIN_GROUP ratings are left out.
     */
    public function meeting(Request $r): never
    {
        $db = Database::get();
        $poll = $this->teacherPoll((int) $r->param('id'));
        $questions = Polls::questions($db, $poll['id']);
        $scale = array_values(array_map(
            fn ($q) => ['id' => $q['id'], 'prompt' => $q['prompt'], 'section' => $q['section']],
            array_filter($questions, fn ($q) => $q['type'] === 'scale')
        ));
        $textQs = array_values(array_map(
            fn ($q) => ['id' => $q['id'], 'prompt' => $q['prompt']],
            array_filter($questions, fn ($q) => $q['type'] === 'text')
        ));
        $ballots = $this->ballotCounts($db, $poll['id']);
        $enough = array_filter($ballots, fn ($n) => $n >= Polls::MIN_GROUP);

        $counts = [];
        $school = [0, 0, 0, 0, 0];
        $stmt = $db->prepare(
            "SELECT b.teacher_id, b.subject_id, a.question_id, a.value_num, COUNT(*)
             FROM answers a JOIN ballots b ON b.id = a.ballot_id JOIN questions q ON q.id = a.question_id AND q.type = 'scale'
             WHERE b.poll_id = ? AND b.teacher_id IS NOT NULL GROUP BY b.teacher_id, b.subject_id, a.question_id, a.value_num"
        );
        $stmt->execute([$poll['id']]);
        foreach ($stmt->fetchAll(PDO::FETCH_NUM) as [$t, $s, $q, $v, $n]) {
            if ($v < 1 || $v > 5) {
                continue;
            }
            $school[(int) $v - 1] += (int) $n;
            if (isset($enough["$t|$s"])) {
                $counts["$t|$s"][(int) $q] ??= [0, 0, 0, 0, 0];
                $counts["$t|$s"][(int) $q][(int) $v - 1] = (int) $n;
            }
        }

        // written comments, shuffled so their order says nothing about who wrote them
        $comments = [];
        if ($textQs) {
            $stmt = $db->prepare(
                "SELECT b.teacher_id, b.subject_id, a.question_id, a.value_text
                 FROM answers a JOIN ballots b ON b.id = a.ballot_id JOIN questions q ON q.id = a.question_id AND q.type = 'text'
                 WHERE b.poll_id = ? AND b.teacher_id IS NOT NULL AND a.value_text IS NOT NULL AND a.value_text <> ''"
            );
            $stmt->execute([$poll['id']]);
            foreach ($stmt->fetchAll(PDO::FETCH_NUM) as [$t, $s, $q, $text]) {
                if (isset($enough["$t|$s"])) {
                    $comments["$t|$s"][(int) $q][] = trim((string) $text);
                }
            }
            foreach ($comments as &$byQ) {
                foreach ($byQ as &$list) {
                    shuffle($list);
                }
            }
            unset($byQ, $list);
        }

        $names = $db->query('SELECT id, name FROM teachers')->fetchAll(PDO::FETCH_KEY_PAIR);
        $subjects = $db->query('SELECT id, name FROM subjects')->fetchAll(PDO::FETCH_KEY_PAIR);
        $teachers = [];
        foreach ($enough as $k => $n) {
            [$t, $s] = array_map('intval', explode('|', $k));
            $teachers[] = [
                'teacher_id' => $t,
                'teacher' => $names[$t] ?? '(removed)',
                'subject_id' => $s,
                'subject' => $subjects[$s] ?? '',
                'ratings' => $n,
                'counts' => (object) ($counts[$k] ?? []),
                'comments' => (object) ($comments[$k] ?? []),
            ];
        }
        usort($teachers, fn ($a, $b) => [$a['subject'], $a['teacher']] <=> [$b['subject'], $b['teacher']]);

        Response::ok([
            'poll' => $poll,
            'questions' => $scale,
            'comment_questions' => $textQs,
            'school' => $school,
            'teachers' => $teachers,
            'hidden' => count($ballots) - count($enough),
            'min_group' => Polls::MIN_GROUP,
        ]);
    }

    /** GET /admin/polls/{id}/teachers/{teacher}/{subject} - one teacher's report */
    public function report(Request $r): never
    {
        $db = Database::get();
        $poll = $this->teacherPoll((int) $r->param('id'));
        $t = (int) $r->param('teacher');
        $s = (int) $r->param('subject');
        $k = "$t|$s";
        $teacher = $db->prepare('SELECT id, name, employee_number FROM teachers WHERE id = ?');
        $teacher->execute([$t]);
        $teacher = $teacher->fetch();
        $subject = $db->prepare('SELECT id, name FROM subjects WHERE id = ?');
        $subject->execute([$s]);
        $subject = $subject->fetch();
        if (!$teacher || !$subject) {
            Response::error('Teacher not found.', 404);
        }

        $ballots = $this->ballotCounts($db, $poll['id'])[$k] ?? 0;
        $skip = $db->prepare('SELECT COUNT(*) FROM vote_gates WHERE poll_id = ? AND teacher_id = ? AND subject_id = ? AND skipped = 1');
        $skip->execute([$poll['id'], $t, $s]);
        $base = [
            'poll' => $poll,
            'teacher' => $teacher,
            'subject' => $subject,
            'ratings' => $ballots,
            'expected' => $this->expected($db, $poll)[$k] ?? 0,
            'not_my_teacher' => (int) $skip->fetchColumn(),
            'min_group' => Polls::MIN_GROUP,
            'hidden' => $ballots < Polls::MIN_GROUP,
        ];
        if ($base['hidden']) {
            Response::ok($base);
        }

        $questions = Polls::questions($db, $poll['id']);
        $sectionOf = [];
        foreach ($questions as $q) {
            if ($q['type'] === 'scale') {
                $sectionOf[$q['id']] = $q['section'] ?? '';
            }
        }

        // this teacher's answers, and the whole poll's for comparison
        $stmt = $db->prepare('SELECT a.question_id, a.value_num, a.value_text, b.class, b.stream FROM answers a JOIN ballots b ON b.id = a.ballot_id WHERE b.poll_id = ? AND b.teacher_id = ? AND b.subject_id = ?');
        $stmt->execute([$poll['id'], $t, $s]);
        $mine = [];
        $byGroup = [];
        $groupBallots = $db->prepare('SELECT class, stream, COUNT(*) FROM ballots WHERE poll_id = ? AND teacher_id = ? AND subject_id = ? GROUP BY class, stream');
        $groupBallots->execute([$poll['id'], $t, $s]);
        $groupCounts = [];
        foreach ($groupBallots->fetchAll(PDO::FETCH_NUM) as [$c, $st, $n]) {
            $groupCounts["$c|$st"] = (int) $n;
        }
        foreach ($stmt->fetchAll() as $a) {
            $mine[(int) $a['question_id']][] = $a;
            if (isset($sectionOf[(int) $a['question_id']]) && $a['value_num'] !== null) {
                $g = "{$a['class']}|{$a['stream']}";
                $byGroup[$g][0] = ($byGroup[$g][0] ?? 0) + (int) $a['value_num'];
                $byGroup[$g][1] = ($byGroup[$g][1] ?? 0) + 1;
            }
        }
        $school = [];
        $stmt = $db->prepare("SELECT a.question_id, SUM(a.value_num), COUNT(*) FROM answers a JOIN ballots b ON b.id = a.ballot_id JOIN questions q ON q.id = a.question_id AND q.type = 'scale' WHERE b.poll_id = ? GROUP BY a.question_id");
        $stmt->execute([$poll['id']]);
        foreach ($stmt->fetchAll(PDO::FETCH_NUM) as [$qid, $sum, $cnt]) {
            $school[(int) $qid] = [(int) $sum, (int) $cnt];
        }

        // the whole poll's spread of answers per statement (for "this teacher vs the school")
        $schoolCounts = [];
        $stmt = $db->prepare("SELECT a.question_id, a.value_num, COUNT(*) FROM answers a JOIN ballots b ON b.id = a.ballot_id JOIN questions q ON q.id = a.question_id AND q.type = 'scale' WHERE b.poll_id = ? GROUP BY a.question_id, a.value_num");
        $stmt->execute([$poll['id']]);
        foreach ($stmt->fetchAll(PDO::FETCH_NUM) as [$qid, $v, $cnt]) {
            if ($v >= 1 && $v <= 5) {
                $schoolCounts[(int) $qid][(int) $v - 1] = (int) $cnt;
            }
        }

        $scaleRows = [];
        $others = [];
        $secMine = [];
        $secSchool = [];
        foreach ($questions as $q) {
            $answers = $mine[$q['id']] ?? [];
            if ($q['type'] === 'scale') {
                $sum = array_sum(array_map(fn ($a) => (int) $a['value_num'], $answers));
                $sec = $q['section'] ?? '';
                $secMine[$sec][0] = ($secMine[$sec][0] ?? 0) + $sum;
                $secMine[$sec][1] = ($secMine[$sec][1] ?? 0) + count($answers);
                $secSchool[$sec][0] = ($secSchool[$sec][0] ?? 0) + ($school[$q['id']][0] ?? 0);
                $secSchool[$sec][1] = ($secSchool[$sec][1] ?? 0) + ($school[$q['id']][1] ?? 0);
                $summary = ResultController::summarise($q, $answers, $ballots);
                $summary['school_average'] = ($school[$q['id']][1] ?? 0) ? round($school[$q['id']][0] / $school[$q['id']][1], 2) : null;
                $summary['school_counts'] = array_replace(array_fill(0, 5, 0), $schoolCounts[$q['id']] ?? []);
                $scaleRows[] = $summary;
            } else {
                $others[] = ResultController::summarise($q, $answers, $ballots);
            }
        }
        $sections = [];
        foreach ($secMine as $sec => [$sum, $cnt]) {
            $sections[] = [
                'section' => $sec,
                'average' => $cnt ? round($sum / $cnt, 2) : null,
                'school_average' => ($secSchool[$sec][1] ?? 0) ? round($secSchool[$sec][0] / $secSchool[$sec][1], 2) : null,
            ];
        }
        $allSum = array_sum(array_column($secMine, 0));
        $allCnt = array_sum(array_column($secMine, 1));
        $schoolAllSum = array_sum(array_column($secSchool, 0));
        $schoolAllCnt = array_sum(array_column($secSchool, 1));

        $groups = [];
        ksort($groupCounts);
        foreach ($groupCounts as $g => $n) {
            [$c, $st] = explode('|', $g);
            $enough = $n >= Polls::MIN_GROUP;
            $groups[] = [
                'class' => $c,
                'stream' => $st,
                'ratings' => $n,
                'average' => $enough && ($byGroup[$g][1] ?? 0) ? round($byGroup[$g][0] / $byGroup[$g][1], 2) : null,
                'enough' => $enough,
            ];
        }

        Response::ok($base + [
            'overall' => $allCnt ? round($allSum / $allCnt, 2) : null,
            'school_overall' => $schoolAllCnt ? round($schoolAllSum / $schoolAllCnt, 2) : null,
            'sections' => $sections,
            'scale' => $scaleRows,
            'others' => $others,
            'groups' => $groups,
            'trend' => $this->trend($db, $t, $s),
        ]);
    }

    /** This teacher's overall score in every teacher poll with enough ratings, oldest first */
    private function trend(PDO $db, int $t, int $s): array
    {
        $stmt = $db->prepare(
            "SELECT p.id, p.title, p.opened_at, COUNT(DISTINCT b.id) AS ratings, AVG(a.value_num) AS overall
             FROM polls p JOIN ballots b ON b.poll_id = p.id AND b.teacher_id = ? AND b.subject_id = ?
             JOIN answers a ON a.ballot_id = b.id JOIN questions q ON q.id = a.question_id AND q.type = 'scale'
             WHERE p.about = 'teachers' GROUP BY p.id, p.title, p.opened_at HAVING ratings >= ? ORDER BY p.opened_at, p.id"
        );
        $stmt->execute([$t, $s, Polls::MIN_GROUP]);
        return array_map(fn ($r) => ['poll_id' => (int) $r['id'], 'title' => $r['title'], 'opened_at' => $r['opened_at'], 'ratings' => (int) $r['ratings'], 'overall' => round((float) $r['overall'], 2)], $stmt->fetchAll());
    }

    /** "t|s" => [question id => [sum, count]] over the poll's 1-5 questions */
    private function scaleSums(PDO $db, int $pollId): array
    {
        $stmt = $db->prepare(
            "SELECT b.teacher_id, b.subject_id, a.question_id, SUM(a.value_num), COUNT(*)
             FROM answers a JOIN ballots b ON b.id = a.ballot_id JOIN questions q ON q.id = a.question_id AND q.type = 'scale'
             WHERE b.poll_id = ? AND b.teacher_id IS NOT NULL GROUP BY b.teacher_id, b.subject_id, a.question_id"
        );
        $stmt->execute([$pollId]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_NUM) as [$t, $s, $q, $sum, $cnt]) {
            $out["$t|$s"][(int) $q] = [(int) $sum, (int) $cnt];
        }
        return $out;
    }

    private function ballotCounts(PDO $db, int $pollId): array
    {
        $stmt = $db->prepare('SELECT teacher_id, subject_id, COUNT(*) FROM ballots WHERE poll_id = ? AND teacher_id IS NOT NULL GROUP BY teacher_id, subject_id');
        $stmt->execute([$pollId]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_NUM) as [$t, $s, $n]) {
            $out["$t|$s"] = (int) $n;
        }
        return $out;
    }

    /** [overall, [section => average]] from [question id => [sum, count]] */
    private function scores(array $byQuestion, array $sectionOf): array
    {
        $sum = 0;
        $cnt = 0;
        $sec = [];
        foreach ($byQuestion as $qid => [$s, $c]) {
            $sum += $s;
            $cnt += $c;
            $name = $sectionOf[$qid] ?? null;
            if ($name !== null) {
                $sec[$name][0] = ($sec[$name][0] ?? 0) + $s;
                $sec[$name][1] = ($sec[$name][1] ?? 0) + $c;
            }
        }
        return [
            $cnt ? round($sum / $cnt, 2) : null,
            array_map(fn ($x) => $x[1] ? round($x[0] / $x[1], 2) : null, $sec),
        ];
    }

    /** "t|s" => students who should rate that teacher (by today's Teaching list and the poll's classes) */
    private function expected(PDO $db, array $poll): array
    {
        $classOf = $db->query("SELECT id, class FROM students WHERE status = 'active'")->fetchAll(PDO::FETCH_KEY_PAIR);
        $out = [];
        foreach (TeachingList::allTargets($db) as $studentId => $targets) {
            if (!Polls::forClass($poll, (string) ($classOf[$studentId] ?? ''))) {
                continue;
            }
            foreach ($targets as [$t, $s]) {
                $out["$t|$s"] = ($out["$t|$s"] ?? 0) + 1;
            }
        }
        return $out;
    }

    /** The teacher poll before this one (for "change since last time") */
    private function previousPoll(PDO $db, array $poll): ?array
    {
        $stmt = $db->prepare(
            "SELECT id, title FROM polls WHERE about = 'teachers' AND id <> ? AND opened_at IS NOT NULL AND opened_at < COALESCE(?, NOW())
             ORDER BY opened_at DESC LIMIT 1"
        );
        $stmt->execute([$poll['id'], $poll['opened_at']]);
        $row = $stmt->fetch();
        return $row ? ['id' => (int) $row['id'], 'title' => $row['title']] : null;
    }

    private function teacherPoll(int $id): array
    {
        $poll = Polls::find(Database::get(), $id);
        if (!$poll) {
            Response::error('Poll not found.', 404);
        }
        if ($poll['about'] !== 'teachers') {
            Response::error('This poll is about the school, not teachers.', 422);
        }
        return $poll;
    }
}
