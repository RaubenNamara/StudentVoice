<?php

declare(strict_types=1);

namespace StudentVoice\Controllers;

use StudentVoice\Auth\Auth;
use StudentVoice\Database;
use StudentVoice\Http\Request;
use StudentVoice\Http\Response;
use StudentVoice\Services\SmisSync;
use StudentVoice\Support\VoterCode;

/** The roster: SMIS sync, students added by hand, voting numbers and printable slips. */
final class StudentController
{
    private const CLASSES = ['S.1', 'S.2', 'S.3', 'S.4', 'S.5', 'S.6'];

    public function index(Request $r): never
    {
        $where = ['1 = 1'];
        $args = [];
        $status = $r->string('status') ?: 'active';
        if ($status !== 'all') {
            $where[] = 's.status = ?';
            $args[] = $status === 'left' ? 'left' : 'active';
        }
        if ($class = $r->string('class')) {
            $where[] = 's.class = ?';
            $args[] = $class;
        }
        if (($stream = $r->string('stream')) !== '') {
            $where[] = 's.stream = ?';
            $args[] = $stream;
        }
        if ($q = $r->string('q')) {
            $where[] = '(s.name LIKE ? OR s.reg_no LIKE ? OR s.voter_code = ?)';
            array_push($args, "%$q%", "%$q%", VoterCode::normalise($q));
        }
        $page = max(1, (int) $r->query('page', 1));
        $per = 50;
        $sqlWhere = implode(' AND ', $where);
        $db = Database::get();
        $count = $db->prepare("SELECT COUNT(*) FROM students s WHERE $sqlWhere");
        $count->execute($args);
        $total = (int) $count->fetchColumn();
        $stmt = $db->prepare(
            "SELECT s.id, s.reg_no, s.name, s.class, s.stream, s.voter_code, s.status, s.source, s.left_at,
                    (SELECT COUNT(*) FROM voters v WHERE v.student_id = s.id) AS votes
             FROM students s WHERE $sqlWhere ORDER BY s.class, s.stream, s.name LIMIT $per OFFSET " . (($page - 1) * $per)
        );
        $stmt->execute($args);
        Response::ok(['students' => $stmt->fetchAll(), 'total' => $total, 'page' => $page, 'per_page' => $per]);
    }

    /** Active students per class and stream (for filters, poll audiences and slips) */
    public function classes(Request $r): never
    {
        Response::ok(self::classList());
    }

    public static function classList(): array
    {
        $rows = Database::get()->query(
            "SELECT class, stream, COUNT(*) AS students FROM students WHERE status = 'active' GROUP BY class, stream ORDER BY class, stream"
        )->fetchAll();
        $out = [];
        foreach ($rows as $row) {
            $out[$row['class']] ??= ['class' => $row['class'], 'students' => 0, 'streams' => []];
            $out[$row['class']]['students'] += (int) $row['students'];
            $out[$row['class']]['streams'][] = ['stream' => $row['stream'], 'students' => (int) $row['students']];
        }
        return array_values($out);
    }

    public function syncStatus(Request $r): never
    {
        Response::ok((new SmisSync(Database::get()))->status());
    }

    public function sync(Request $r): never
    {
        @set_time_limit(300);
        $result = (new SmisSync(Database::get()))->run(Auth::adminId());
        if (!$result['ok']) {
            Response::error($result['message'], 422);
        }
        Response::ok($result);
    }

    public function store(Request $r): never
    {
        [$regNo, $name, $class, $stream] = $this->fields($r);
        $db = Database::get();
        $exists = $db->prepare('SELECT 1 FROM students WHERE reg_no = ?');
        $exists->execute([$regNo]);
        if ($exists->fetchColumn()) {
            Response::error('A student with that registration number is already here.', 422, ['reg_no' => 'Already used']);
        }
        $code = VoterCode::unique($db);
        $db->prepare("INSERT INTO students (reg_no, name, class, stream, voter_code, source) VALUES (?, ?, ?, ?, ?, 'manual')")
            ->execute([$regNo, $name, $class, $stream, $code]);
        Response::ok(['id' => (int) $db->lastInsertId(), 'voter_code' => $code], 201);
    }

    public function update(Request $r): never
    {
        $id = (int) $r->param('id');
        [, $name, $class, $stream] = $this->fields($r, false);
        Database::get()->prepare('UPDATE students SET name = ?, class = ?, stream = ? WHERE id = ?')->execute([$name, $class, $stream, $id]);
        Response::ok();
    }

    /** A lost or seen slip: the old number stops working. Votes already cast still count. */
    public function newCode(Request $r): never
    {
        $db = Database::get();
        $code = VoterCode::unique($db);
        $db->prepare('UPDATE students SET voter_code = ? WHERE id = ?')->execute([$code, (int) $r->param('id')]);
        Response::ok(['voter_code' => $code]);
    }

    /** Only for students added by hand; SMIS students follow SMIS */
    public function setStatus(Request $r): never
    {
        $status = $r->string('status') === 'left' ? 'left' : 'active';
        Database::get()->prepare("UPDATE students SET status = ?, left_at = IF(? = 'left', NOW(), NULL) WHERE id = ? AND source = 'manual'")
            ->execute([$status, $status, (int) $r->param('id')]);
        Response::ok();
    }

    /**
     * Everything for printing voting slips and stream lists: one class (and stream), or the whole
     * school (class "all"), by class, stream and name
     */
    public function slips(Request $r): never
    {
        $class = $r->string('class');
        $all = $class === 'all';
        if (!$all && !in_array($class, self::CLASSES, true)) {
            Response::error('Choose a class.', 422);
        }
        $args = [];
        $sql = "SELECT reg_no, name, class, stream, voter_code FROM students WHERE status = 'active'";
        if (!$all) {
            $sql .= ' AND class = ?';
            $args[] = $class;
            if (($stream = $r->string('stream')) !== '') {
                $sql .= ' AND stream = ?';
                $args[] = $stream;
            }
        }
        $stmt = Database::get()->prepare($sql . ' ORDER BY class, stream, name');
        $stmt->execute($args);
        Response::ok(['students' => $stmt->fetchAll()]);
    }

    private function fields(Request $r, bool $withRegNo = true): array
    {
        $regNo = $r->string('reg_no');
        $name = trim((string) preg_replace('/\s+/', ' ', $r->string('name')));
        $class = $r->string('class');
        $stream = strtoupper($r->string('stream'));
        $errors = [];
        if ($withRegNo && $regNo === '') {
            $errors['reg_no'] = 'Enter the registration number';
        }
        if ($name === '') {
            $errors['name'] = 'Enter the name';
        }
        if (!in_array($class, self::CLASSES, true)) {
            $errors['class'] = 'Choose a class';
        }
        if (mb_strlen($stream) > 20) {
            $errors['stream'] = 'Too long';
        }
        if ($errors) {
            Response::error('Please fix the marked fields.', 422, $errors);
        }
        return [mb_substr($regNo, 0, 40), mb_substr($name, 0, 190), $class, $stream];
    }
}
