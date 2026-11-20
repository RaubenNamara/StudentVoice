<?php

declare(strict_types=1);

namespace StudentVoice\Services;

use PDO;

/**
 * Who teaches whom, and so which teachers a student rates in a teacher poll.
 *
 * A student's subjects: those Tusome says they take, plus any subject on their stream's Teaching
 * list that Tusome has nobody in the stream taking (an elective Tusome doesn't record: the whole
 * stream rates it, and "Not my teacher" covers anyone who doesn't take it).
 * For each subject, their own teacher where Tusome records one, else the teacher(s) of that
 * subject in their class and stream on the Teaching list.
 */
final class TeachingList
{
    /** [[teacher_id, subject_id], ...] for one student, in subject order */
    public static function targets(PDO $db, array $student): array
    {
        $all = self::resolve($db, [(int) $student['id'] => $student]);
        return $all[(int) $student['id']] ?? [];
    }

    /** student id => [[teacher_id, subject_id], ...] for every active student */
    public static function allTargets(PDO $db): array
    {
        $students = [];
        foreach ($db->query("SELECT id, class, stream FROM students WHERE status = 'active'")->fetchAll() as $s) {
            $students[(int) $s['id']] = $s;
        }
        return self::resolve($db, $students);
    }

    private static function resolve(PDO $db, array $students): array
    {
        if (!$students) {
            return [];
        }
        $one = count($students) === 1 ? (int) array_key_first($students) : null;
        $where = $one ? " AND x.student_id = $one" : '';

        $subjects = [];
        foreach ($db->query("SELECT x.student_id, x.subject_id FROM student_subjects x JOIN subjects s ON s.id = x.subject_id AND s.status = 'active' WHERE 1 $where")->fetchAll(PDO::FETCH_NUM) as [$st, $su]) {
            $subjects[(int) $st][(int) $su] = true;
        }
        $own = [];
        foreach ($db->query("SELECT x.student_id, x.subject_id, x.teacher_id FROM student_teachers x JOIN teachers t ON t.id = x.teacher_id AND t.status = 'active' WHERE 1 $where")->fetchAll(PDO::FETCH_NUM) as [$st, $su, $te]) {
            $own[(int) $st][(int) $su][] = (int) $te;
        }
        $teaching = [];
        foreach ($db->query(
            "SELECT x.class, x.stream, x.subject_id, x.teacher_id FROM teaching x
             JOIN teachers t ON t.id = x.teacher_id AND t.status = 'active' JOIN subjects s ON s.id = x.subject_id AND s.status = 'active'"
        )->fetchAll(PDO::FETCH_NUM) as [$c, $sr, $su, $te]) {
            $teaching["$c|$sr"][(int) $su][] = (int) $te;
        }
        // subjects Tusome has anyone taking, per stream
        $streamTakes = [];
        $streamWhere = $one ? ' AND st.class = ' . $db->quote((string) $students[$one]['class']) . ' AND st.stream = ' . $db->quote((string) $students[$one]['stream']) : '';
        foreach ($db->query(
            "SELECT DISTINCT st.class, st.stream, x.subject_id FROM student_subjects x JOIN students st ON st.id = x.student_id AND st.status = 'active' WHERE 1 $streamWhere"
        )->fetchAll(PDO::FETCH_NUM) as [$c, $sr, $su]) {
            $streamTakes["$c|$sr"][(int) $su] = true;
        }
        $order = $db->query('SELECT id FROM subjects ORDER BY name')->fetchAll(PDO::FETCH_COLUMN);
        $rank = array_flip(array_map('intval', $order));

        $out = [];
        foreach ($students as $id => $s) {
            $k = "{$s['class']}|{$s['stream']}";
            $cell = $teaching[$k] ?? [];
            // Tusome's subjects for this student, plus listed subjects Tusome knows nobody in the
            // stream takes (electives Tusome doesn't record: the whole stream rates them)
            $takes = array_keys($subjects[$id] ?? []);
            foreach (array_keys($cell) as $su) {
                if (!isset($streamTakes[$k][$su])) {
                    $takes[] = $su;
                }
            }
            $takes = array_values(array_unique($takes));
            usort($takes, fn ($a, $b) => ($rank[$a] ?? 0) <=> ($rank[$b] ?? 0));
            $list = [];
            foreach ($takes as $su) {
                foreach (array_unique($own[$id][$su] ?? $cell[$su] ?? []) as $te) {
                    $list[] = [$te, $su];
                }
            }
            $out[$id] = $list;
        }
        return $out;
    }

    /** Subjects students take in a stream with no teacher on the list */
    public static function gapCount(PDO $db): int
    {
        return (int) $db->query(
            "SELECT COUNT(*) FROM (
               SELECT DISTINCT s.class, s.stream, ss.subject_id FROM student_subjects ss
               JOIN students s ON s.id = ss.student_id AND s.status = 'active'
               JOIN subjects su ON su.id = ss.subject_id AND su.status = 'active'
             ) need
             WHERE NOT EXISTS (
               SELECT 1 FROM teaching t JOIN teachers te ON te.id = t.teacher_id AND te.status = 'active'
               WHERE t.class = need.class AND t.stream = need.stream AND t.subject_id = need.subject_id
             )"
        )->fetchColumn();
    }

    /** The Teaching list for one class: subjects down, streams across */
    public static function grid(PDO $db, string $class): array
    {
        $streams = $db->prepare("SELECT stream, COUNT(*) AS students FROM students WHERE status = 'active' AND class = ? GROUP BY stream ORDER BY stream");
        $streams->execute([$class]);
        $streams = $streams->fetchAll();

        $takers = [];
        $stmt = $db->prepare(
            "SELECT s.stream, ss.subject_id, COUNT(*) AS n FROM student_subjects ss JOIN students s ON s.id = ss.student_id AND s.status = 'active'
             WHERE s.class = ? GROUP BY s.stream, ss.subject_id"
        );
        $stmt->execute([$class]);
        foreach ($stmt->fetchAll() as $r) {
            $takers[(int) $r['subject_id']][$r['stream']] = (int) $r['n'];
        }

        $cells = [];
        $stmt = $db->prepare("SELECT t.stream, t.subject_id, t.teacher_id, t.source FROM teaching t JOIN teachers te ON te.id = t.teacher_id AND te.status = 'active' WHERE t.class = ?");
        $stmt->execute([$class]);
        foreach ($stmt->fetchAll() as $r) {
            $cells[(int) $r['subject_id']][$r['stream']][] = ['teacher_id' => (int) $r['teacher_id'], 'source' => $r['source']];
        }

        $teachersBySubject = [];
        foreach ($db->query(
            "SELECT ts.subject_id, t.id, t.name FROM teacher_subjects ts JOIN teachers t ON t.id = ts.teacher_id AND t.status = 'active' ORDER BY t.name"
        )->fetchAll() as $r) {
            $teachersBySubject[(int) $r['subject_id']][] = ['id' => (int) $r['id'], 'name' => $r['name']];
        }

        $subjects = [];
        $allSubjects = [];
        foreach ($db->query("SELECT id, name FROM subjects WHERE status = 'active' ORDER BY name")->fetchAll() as $s) {
            $sid = (int) $s['id'];
            $allSubjects[] = ['id' => $sid, 'name' => $s['name'], 'teachers' => $teachersBySubject[$sid] ?? []];
            // subjects this class takes (per Tusome) or already on its list; with no subject data
            // for the class, every subject that has teachers
            $relevant = $takers ? isset($takers[$sid]) : isset($teachersBySubject[$sid]);
            if (!$relevant && !isset($cells[$sid])) {
                continue;
            }
            $row = ['id' => $sid, 'name' => $s['name'], 'teachers' => $teachersBySubject[$sid] ?? [], 'cells' => []];
            foreach ($streams as $st) {
                $row['cells'][$st['stream']] = [
                    'takers' => $takers[$sid][$st['stream']] ?? 0,
                    'teachers' => $cells[$sid][$st['stream']] ?? [],
                ];
            }
            $subjects[] = $row;
        }

        // every teacher, for a subject whose Tusome list is missing someone
        $allTeachers = $db->query("SELECT id, name FROM teachers WHERE status = 'active' ORDER BY name")->fetchAll();
        return [
            'class' => $class,
            'streams' => array_map(fn ($s) => ['stream' => $s['stream'], 'students' => (int) $s['students']], $streams),
            'subjects' => $subjects,
            'all_teachers' => array_map(fn ($t) => ['id' => (int) $t['id'], 'name' => $t['name']], $allTeachers),
            'all_subjects' => $allSubjects,
            'has_subject_data' => (bool) $takers,
        ];
    }
}
