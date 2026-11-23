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
 * subject in their class and stream on the Teaching list; with nobody listed, the student picks
 * their teacher from the subject's department.
 */
final class TeachingList
{
    /**
     * One student's subjects in order: subject id => the teacher ids listed for them (empty when
     * nobody is listed yet: the student picks their teacher from the department)
     */
    public static function subjectsFor(PDO $db, array $student): array
    {
        $all = self::resolve($db, [(int) $student['id'] => $student]);
        return $all[(int) $student['id']] ?? [];
    }

    /** [[teacher_id, subject_id], ...] listed for one student, in subject order */
    public static function targets(PDO $db, array $student): array
    {
        return self::flatten(self::subjectsFor($db, $student));
    }

    private static function flatten(array $subjects): array
    {
        $list = [];
        foreach ($subjects as $su => $teachers) {
            foreach ($teachers as $te) {
                $list[] = [$te, $su];
            }
        }
        return $list;
    }

    /** student id => [[teacher_id, subject_id], ...] for every active student */
    public static function allTargets(PDO $db): array
    {
        $students = [];
        foreach ($db->query("SELECT id, class, stream FROM students WHERE status = 'active'")->fetchAll() as $s) {
            $students[(int) $s['id']] = $s;
        }
        return array_map([self::class, 'flatten'], self::resolve($db, $students));
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
        // who has anything at all from Tusome (before the school's rules add to it)
        $recorded = array_map(fn () => true, $subjects);
        // the school's rules count as recorded, also for the stream, so a listed teacher for such a
        // subject is rated by these students rather than the whole stream
        foreach (self::ruleSubjects($db, $streamWhere) as [$st, $c, $sr, $su]) {
            $streamTakes["$c|$sr"][$su] = true;
            if (isset($students[$st])) {
                $subjects[$st][$su] = true;
            }
        }

        $order = $db->query('SELECT id FROM subjects ORDER BY name')->fetchAll(PDO::FETCH_COLUMN);
        $rank = array_flip(array_map('intval', $order));

        $out = [];
        foreach ($students as $id => $s) {
            $k = "{$s['class']}|{$s['stream']}";
            $cell = $teaching[$k] ?? [];
            // Tusome's subjects for this student, plus listed subjects Tusome knows nobody in the
            // stream takes (electives Tusome doesn't record: the whole stream rates them). With
            // nothing at all from Tusome: their stream's whole list.
            $takes = array_keys($subjects[$id] ?? []);
            foreach (array_keys($cell) as $su) {
                if (!isset($recorded[$id]) || !isset($streamTakes[$k][$su])) {
                    $takes[] = $su;
                }
            }
            $takes = array_values(array_unique($takes));
            usort($takes, fn ($a, $b) => ($rank[$a] ?? 0) <=> ($rank[$b] ?? 0));
            $list = [];
            foreach ($takes as $su) {
                $list[$su] = array_values(array_unique($own[$id][$su] ?? $cell[$su] ?? []));
            }
            $out[$id] = $list;
        }
        return $out;
    }

    /**
     * The subjects a student takes: Tusome's record plus the school's rules (empty = nothing
     * recorded, so nothing is optional for them)
     *
     * @return array<int, true> subject id => true
     */
    public static function takes(PDO $db, array $student): array
    {
        $stmt = $db->prepare('SELECT subject_id FROM student_subjects WHERE student_id = ?');
        $stmt->execute([(int) $student['id']]);
        $out = array_fill_keys(array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)), true);
        if (!$out) {
            return [];
        }
        $where = ' AND st.id = ' . (int) $student['id'];
        foreach (self::ruleSubjects($db, $where) as [, , , $su]) {
            $out[$su] = true;
        }
        return $out;
    }

    /**
     * St Mark's subject rules, for what Tusome's enrolments miss:
     * - O-level (S.1-S.4): everyone takes English
     * - A-level (S.5-S.6): Economics without Entrepreneurship means Sub-Maths
     * $where narrows the students (SQL on alias st, already escaped).
     *
     * @return list<array{0: int, 1: string, 2: string, 3: int}> [student id, class, stream, subject id]
     */
    private static function ruleSubjects(PDO $db, string $where): array
    {
        $ids = $db->query("SELECT UPPER(name), MIN(id) FROM subjects WHERE status = 'active' AND UPPER(name) IN ('ENG', 'ECON', 'ENT', 'SUB-MTC') GROUP BY UPPER(name)")->fetchAll(PDO::FETCH_KEY_PAIR);
        $out = [];
        $add = function (string $sql, array $args, int $subject) use ($db, &$out): void {
            $stmt = $db->prepare($sql);
            $stmt->execute($args);
            foreach ($stmt->fetchAll(PDO::FETCH_NUM) as [$st, $c, $sr]) {
                $out[] = [(int) $st, (string) $c, (string) $sr, $subject];
            }
        };
        if (isset($ids['ENG'])) {
            $add("SELECT st.id, st.class, st.stream FROM students st WHERE st.status = 'active' AND st.class IN ('S.1', 'S.2', 'S.3', 'S.4')$where", [], (int) $ids['ENG']);
        }
        if (isset($ids['ECON'], $ids['ENT'], $ids['SUB-MTC'])) {
            $add(
                "SELECT st.id, st.class, st.stream FROM students st
                 WHERE st.status = 'active' AND st.class IN ('S.5', 'S.6')
                   AND EXISTS (SELECT 1 FROM student_subjects x WHERE x.student_id = st.id AND x.subject_id = ?)
                   AND NOT EXISTS (SELECT 1 FROM student_subjects x WHERE x.student_id = st.id AND x.subject_id = ?)$where",
                [(int) $ids['ECON'], (int) $ids['ENT']],
                (int) $ids['SUB-MTC']
            );
        }
        return $out;
    }

    /**
     * With teachers from the Excel only: a teacher is shown when the Teaching list names them and
     * hidden ('gone') otherwise - never deleted, so their past ratings keep their names.
     */
    public static function applyTeacherSource(PDO $db): void
    {
        if (!\StudentVoice\Support\Settings::excelTeachersOnly()) {
            return;
        }
        $db->exec("UPDATE teachers t SET t.status = IF(EXISTS (SELECT 1 FROM teaching x WHERE x.teacher_id = t.id), 'active', 'gone')");
    }

    /**
     * subject id => teacher ids who may be chosen for it: with Excel teachers only, those the
     * Teaching list has for the subject anywhere; otherwise Tusome's department teachers
     */
    public static function subjectTeachers(PDO $db): array
    {
        $sql = \StudentVoice\Support\Settings::excelTeachersOnly()
            ? "SELECT DISTINCT x.subject_id, t.id, t.name FROM teaching x JOIN teachers t ON t.id = x.teacher_id AND t.status = 'active' ORDER BY t.name"
            : "SELECT ts.subject_id, t.id, t.name FROM teacher_subjects ts JOIN teachers t ON t.id = ts.teacher_id AND t.status = 'active' ORDER BY t.name";
        $out = [];
        foreach ($db->query($sql)->fetchAll() as $r) {
            $out[(int) $r['subject_id']][] = ['id' => (int) $r['id'], 'name' => $r['name']];
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

        // each subject's teachers: from the Excel (Teaching list) or Tusome's departments
        $teachersBySubject = self::subjectTeachers($db);

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
