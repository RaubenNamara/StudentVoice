<?php

declare(strict_types=1);

namespace StudentVoice\Controllers;

use StudentVoice\Auth\Auth;
use StudentVoice\Database;
use StudentVoice\Http\Request;
use StudentVoice\Http\Response;
use StudentVoice\Services\TeachingList;
use StudentVoice\Services\TusomeSync;
use StudentVoice\Support\Polls;

/** Teachers from Tusome, and the Teaching list (who teaches each subject in each stream). */
final class TeacherController
{
    public function index(Request $r): never
    {
        $db = Database::get();
        $teachers = $db->query(
            "SELECT t.id, t.name, t.employee_number, t.status,
                    (SELECT GROUP_CONCAT(s.name ORDER BY s.name SEPARATOR ', ') FROM teacher_subjects ts JOIN subjects s ON s.id = ts.subject_id WHERE ts.teacher_id = t.id) AS subjects,
                    (SELECT COUNT(DISTINCT CONCAT(x.class, '|', x.stream)) FROM teaching x WHERE x.teacher_id = t.id) AS streams
             FROM teachers t ORDER BY t.status = 'active' DESC, t.name"
        )->fetchAll();
        Response::ok([
            'teachers' => $teachers,
            'sync' => (new TusomeSync($db))->status(),
            'gaps' => TeachingList::gapCount($db),
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
        Response::ok(['gaps' => TeachingList::gapCount($db)]);
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
        Response::ok(['gaps' => TeachingList::gapCount(Database::get())]);
    }
}
