<?php

declare(strict_types=1);

namespace StudentVoice\Controllers;

use StudentVoice\Auth\Auth;
use StudentVoice\Database;
use StudentVoice\Http\Request;
use StudentVoice\Http\Response;
use StudentVoice\Services\TeachingImport;
use StudentVoice\Services\TeachingList;
use StudentVoice\Services\TusomeSync;
use StudentVoice\Support\Polls;

/** Teachers from Tusome, and the Teaching list (who teaches each subject in each stream). */
final class TeacherController
{
    public function index(Request $r): never
    {
        $db = Database::get();
        // a teacher's subjects: from the Excel (Teaching list), or Tusome's departments
        $subjectsSql = \StudentVoice\Support\Settings::excelTeachersOnly()
            ? "(SELECT GROUP_CONCAT(DISTINCT s.name ORDER BY s.name SEPARATOR ', ') FROM teaching x2 JOIN subjects s ON s.id = x2.subject_id WHERE x2.teacher_id = t.id)"
            : "(SELECT GROUP_CONCAT(s.name ORDER BY s.name SEPARATOR ', ') FROM teacher_subjects ts JOIN subjects s ON s.id = ts.subject_id WHERE ts.teacher_id = t.id)";
        $teachers = $db->query(
            "SELECT t.id, t.name, t.employee_number, t.status,
                    $subjectsSql AS subjects,
                    (SELECT COUNT(DISTINCT CONCAT(x.class, '|', x.stream)) FROM teaching x WHERE x.teacher_id = t.id) AS streams
             FROM teachers t ORDER BY t.status = 'active' DESC, t.name"
        )->fetchAll();
        Response::ok([
            'teachers' => $teachers,
            'sync' => (new TusomeSync($db))->status(),
            'gaps' => TeachingList::gapCount($db),
            // teachers come only from the Excel upload (others are hidden)
            'excel_only' => \StudentVoice\Support\Settings::excelTeachersOnly(),
            'subjects' => (int) $db->query("SELECT COUNT(*) FROM subjects WHERE status = 'active'")->fetchColumn(),
        ]);
    }

    public function sync(Request $r): never
    {
        @set_time_limit(300);
        $result = (new TusomeSync(Database::get()))->run(Auth::adminId());
        if (!$result['ok']) {
            Response::error($result['message'], 422);
        }
        Response::ok($result);
    }

    public function grid(Request $r): never
    {
        $class = $r->string('class');
        if (!in_array($class, Polls::CLASSES, true)) {
            Response::error('Choose a class.', 422);
        }
        $db = Database::get();
        $grid = TeachingList::grid($db, $class);
        $grid['gaps'] = TeachingList::gapCount($db);
        Response::ok($grid);
    }

    /** One cell: the teacher(s) of a subject in a class and stream (empty = nobody) */
    public function setCell(Request $r): never
    {
        $class = $r->string('class');
        $stream = $r->string('stream');
        $subjectId = (int) $r->input('subject_id', 0);
        $ids = $r->input('teacher_ids', []);
        $ids = is_array($ids) ? array_values(array_unique(array_map('intval', $ids))) : [];
        if (!in_array($class, Polls::CLASSES, true) || $subjectId <= 0 || count($ids) > 5) {
            Response::error('That change is not valid.', 422);
        }
        $db = Database::get();
        if ($ids) {
            $in = implode(',', $ids);
            $found = (int) $db->query("SELECT COUNT(*) FROM teachers WHERE status = 'active' AND id IN ($in)")->fetchColumn();
            if ($found !== count($ids)) {
                Response::error('One of those teachers is not on the list.', 422);
            }
        }
        Database::transaction(function ($db) use ($class, $stream, $subjectId, $ids) {
            $db->prepare('DELETE FROM teaching WHERE class = ? AND stream = ? AND subject_id = ?')->execute([$class, $stream, $subjectId]);
            $insert = $db->prepare("INSERT INTO teaching (class, stream, subject_id, teacher_id, source) VALUES (?, ?, ?, ?, 'admin')");
            foreach ($ids as $id) {
                $insert->execute([$class, $stream, $subjectId, $id]);
            }
        });
        TeachingList::applyTeacherSource($db);
        Response::ok(['gaps' => TeachingList::gapCount($db)]);
    }

    /** Excel upload, step 1: what the file would do (nothing changes) */
    public function importPreview(Request $r): never
    {
        Response::ok((new TeachingImport(Database::get()))->preview($this->importRows($r)));
    }

    /** One teacher's results in every teacher poll (per subject), to open their reports */
    public function reports(Request $r): never
    {
        $db = Database::get();
        $teacher = $db->prepare('SELECT id, name, employee_number, status FROM teachers WHERE id = ?');
        $teacher->execute([(int) $r->param('id')]);
        $teacher = $teacher->fetch();
        if (!$teacher) {
            Response::error('Teacher not found.', 404);
        }
        $stmt = $db->prepare(
            "SELECT p.id AS poll_id, p.title, p.status, p.opened_at, b.subject_id, s.name AS subject,
                    COUNT(DISTINCT b.id) AS ratings,
                    AVG(CASE WHEN q.type = 'scale' THEN a.value_num END) AS overall
             FROM ballots b
             JOIN polls p ON p.id = b.poll_id
             JOIN subjects s ON s.id = b.subject_id
             LEFT JOIN answers a ON a.ballot_id = b.id
             LEFT JOIN questions q ON q.id = a.question_id
             WHERE b.teacher_id = ?
             GROUP BY p.id, p.title, p.status, p.opened_at, b.subject_id, s.name
             ORDER BY p.opened_at DESC, s.name"
        );
        $stmt->execute([$teacher['id']]);
        $rows = array_map(function ($row) {
            $enough = (int) $row['ratings'] >= Polls::MIN_GROUP;
            return [
                'poll_id' => (int) $row['poll_id'],
                'title' => $row['title'],
                'status' => $row['status'],
                'opened_at' => $row['opened_at'],
                'subject_id' => (int) $row['subject_id'],
                'subject' => $row['subject'],
                'ratings' => (int) $row['ratings'],
                'overall' => $enough && $row['overall'] !== null ? round((float) $row['overall'], 2) : null,
            ];
        }, $stmt->fetchAll());
        Response::ok(['teacher' => $teacher, 'reports' => $rows, 'min_group' => Polls::MIN_GROUP]);
    }

    /** Excel upload, step 2: write it. mode 'replace' (streams in the file) or 'fill' (empty cells only) */
    public function importApply(Request $r): never
    {
        $mode = $r->string('mode') === 'fill' ? 'fill' : 'replace';
        Response::ok((new TeachingImport(Database::get()))->apply($this->importRows($r), $mode));
    }

    private function importRows(Request $r): array
    {
        $rows = $r->input('rows', []);
        if (!is_array($rows) || !$rows) {
            Response::error('The file had no teachers in it.', 422);
        }
        if (count($rows) > 3000) {
            Response::error('That file is too big (more than 3000 rows).', 422);
        }
        return array_map(fn ($row) => [
            'name' => mb_substr((string) ($row['name'] ?? ''), 0, 190),
            'department' => mb_substr((string) ($row['department'] ?? ''), 0, 190),
            'subjects' => mb_substr((string) ($row['subjects'] ?? ''), 0, 1000),
            'streams' => array_slice(array_map('strval', (array) ($row['streams'] ?? [])), 0, 80),
        ], array_values(array_filter($rows, 'is_array')));
    }

    /** A teacher everywhere they are on the list: copy one stream's cell to the class's other streams */
    public function fillClass(Request $r): never
    {
        $class = $r->string('class');
        $subjectId = (int) $r->input('subject_id', 0);
        $ids = array_values(array_unique(array_map('intval', (array) $r->input('teacher_ids', []))));
        if (!in_array($class, Polls::CLASSES, true) || $subjectId <= 0 || !$ids || count($ids) > 5) {
            Response::error('That change is not valid.', 422);
        }
        Database::transaction(function ($db) use ($class, $subjectId, $ids) {
            $streams = $db->prepare("SELECT DISTINCT stream FROM students WHERE status = 'active' AND class = ?");
            $streams->execute([$class]);
            $del = $db->prepare('DELETE FROM teaching WHERE class = ? AND stream = ? AND subject_id = ?');
            $ins = $db->prepare("INSERT INTO teaching (class, stream, subject_id, teacher_id, source) VALUES (?, ?, ?, ?, 'admin')");
            foreach ($streams->fetchAll(\PDO::FETCH_COLUMN) as $stream) {
                $del->execute([$class, $stream, $subjectId]);
                foreach ($ids as $id) {
                    $ins->execute([$class, $stream, $subjectId, $id]);
                }
            }
        });
        TeachingList::applyTeacherSource(Database::get());
        Response::ok(['gaps' => TeachingList::gapCount(Database::get())]);
    }
}
