<?php

declare(strict_types=1);

namespace StudentVoice\Services;

use PDO;
use StudentVoice\Config;

/**
 * Brings in who teaches whom from Tusome (GET .../api/integrations/student-voice/teaching with the
 * shared key). One run:
 *
 *  - teachers and subjects: added or renamed to match Tusome; ones Tusome no longer has are
 *    marked "gone" (never deleted - past results keep their names)
 *  - teacher_subjects, student_subjects, student_teachers: replaced with Tusome's
 *  - the Teaching list: a subject with exactly one teacher is filled in for every stream where
 *    students take it ("auto"); cells an admin set are never touched
 *
 * All in one transaction. Refused when the answer looks wrong (no teachers, or far fewer than
 * last time), so a Tusome problem can't empty the list.
 */
final class TusomeSync
{
    private const MIN_TEACHER_SHARE = 0.5;

    public function __construct(private PDO $db)
    {
    }

    public static function configured(): bool
    {
        return (string) Config::get('TUSOME_TEACHING_API_URL', '') !== '' && strlen((string) Config::get('TUSOME_API_KEY', '')) >= 32;
    }

    public function status(): array
    {
        $this->db->exec("UPDATE sync_runs SET status = 'failed', finished_at = NOW(), message = 'Stopped before it finished' WHERE status = 'running' AND started_at < NOW() - INTERVAL 15 MINUTE");
        $last = $this->db->query(
            "SELECT r.started_at, r.finished_at, r.status, r.message, COALESCE(a.name, '') AS started_by_name
             FROM sync_runs r LEFT JOIN admins a ON a.id = r.started_by WHERE r.kind = 'tusome' ORDER BY r.id DESC LIMIT 1"
        )->fetch() ?: null;
        return ['configured' => self::configured(), 'last' => $last];
    }

    /** @return array{ok: bool, message: string} */
    public function run(?int $startedBy): array
    {
        if (!self::configured()) {
            return ['ok' => false, 'message' => 'The link to Tusome is not set up. Put TUSOME_TEACHING_API_URL and TUSOME_API_KEY in backend/.env.'];
        }
        $lock = (int) $this->db->query("SELECT GET_LOCK('studentvoice_tusome_sync', 0)")->fetchColumn();
        if ($lock !== 1) {
            return ['ok' => false, 'message' => 'A sync is already running. Wait for it to finish.'];
        }
        $this->db->prepare("INSERT INTO sync_runs (kind, started_by) VALUES ('tusome', ?)")->execute([$startedBy]);
        $runId = (int) $this->db->lastInsertId();
        try {
            [$message, $details] = $this->sync($this->fetch());
            $this->finish($runId, 'success', $message, $details);
            return ['ok' => true, 'message' => $message];
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            error_log('[studentvoice] Tusome sync failed: ' . $e->getMessage());
            $message = $e instanceof \RuntimeException ? $e->getMessage() : 'The sync failed and nothing was changed.';
            $this->finish($runId, 'failed', $message, null);
            return ['ok' => false, 'message' => $message];
        } finally {
            $this->db->query("SELECT RELEASE_LOCK('studentvoice_tusome_sync')");
        }
    }

    private function fetch(): array
    {
        $ch = curl_init((string) Config::get('TUSOME_TEACHING_API_URL'));
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 90,
            CURLOPT_CONNECTTIMEOUT => 20,
            CURLOPT_HTTPHEADER => ['X-Student-Voice-Key: ' . Config::get('TUSOME_API_KEY'), 'Accept: application/json'],
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($body === false || $code !== 200) {
            $hint = $code === 404 ? ' Check the address, and that STUDENT_VOICE_API_KEY in Tusome matches TUSOME_API_KEY here.' : '';
            throw new \RuntimeException('Could not read from Tusome' . ($err ? " ({$err})" : " (HTTP {$code})") . '.' . $hint . ' Nothing was changed.');
        }
        $data = json_decode((string) $body, true)['data'] ?? null;
        foreach (['teachers', 'subjects', 'teacher_subjects', 'student_subjects', 'student_teachers'] as $k) {
            if (!is_array($data[$k] ?? null)) {
                throw new \RuntimeException('Tusome sent an answer this version does not understand. Nothing was changed.');
            }
        }
        if (!$data['teachers'] || !$data['subjects']) {
            throw new \RuntimeException('Tusome sent no teachers or no subjects. Nothing was changed.');
        }
        return $data;
    }

    private function sync(array $d): array
    {
        $db = $this->db;
        $active = (int) $db->query("SELECT COUNT(*) FROM teachers WHERE status = 'active'")->fetchColumn();
        if (count($d['teachers']) < self::MIN_TEACHER_SHARE * $active) {
            throw new \RuntimeException(sprintf('Tusome sent only %d teachers but %d are active here. That looks like a Tusome problem, so nothing was changed.', count($d['teachers']), $active));
        }

        $db->beginTransaction();

        // subjects and teachers, matched on Tusome's ids
        $subjectIds = $this->upsert('subjects', $d['subjects'], fn ($s) => ['name' => mb_substr(trim((string) $s['name']), 0, 150)]);
        $before = $db->query("SELECT tusome_id FROM teachers WHERE status = 'active'")->fetchAll(PDO::FETCH_COLUMN);
        $teacherIds = $this->upsert('teachers', $d['teachers'], fn ($t) => [
            'name' => mb_substr(trim((string) preg_replace('/\s+/', ' ', (string) $t['name'])), 0, 190) ?: 'Teacher ' . $t['id'],
            'employee_number' => isset($t['employee_number']) && $t['employee_number'] !== '' ? mb_substr((string) $t['employee_number'], 0, 50) : null,
        ]);
        $newTeachers = count(array_diff(array_keys($teacherIds), array_map('intval', $before)));
        $goneTeachers = count(array_diff(array_map('intval', $before), array_keys($teacherIds)));

        // students by registration number
        $studentIds = [];
        foreach ($db->query("SELECT id, reg_no FROM students")->fetchAll() as $s) {
            $studentIds[SmisSync::key($s['reg_no'])] = (int) $s['id'];
        }

        $db->exec('DELETE FROM teacher_subjects');
        $rows = [];
        foreach ($d['teacher_subjects'] as [$t, $s]) {
            if (isset($teacherIds[(int) $t], $subjectIds[(int) $s])) {
                $rows[$teacherIds[(int) $t] . ',' . $subjectIds[(int) $s]] = [$teacherIds[(int) $t], $subjectIds[(int) $s]];
            }
        }
        $this->insertMany('teacher_subjects', ['teacher_id', 'subject_id'], array_values($rows));

        $db->exec('DELETE FROM student_subjects');
        $rows = [];
        foreach ($d['student_subjects'] as [$reg, $s]) {
            $sid = $studentIds[SmisSync::key((string) $reg)] ?? null;
            if ($sid && isset($subjectIds[(int) $s])) {
                $rows["$sid," . $subjectIds[(int) $s]] = [$sid, $subjectIds[(int) $s]];
            }
        }
        $this->insertMany('student_subjects', ['student_id', 'subject_id'], array_values($rows));
        $studentSubjects = count($rows);

        $db->exec('DELETE FROM student_teachers');
        $rows = [];
        foreach ($d['student_teachers'] as [$reg, $t, $s]) {
            $sid = $studentIds[SmisSync::key((string) $reg)] ?? null;
            if ($sid && isset($teacherIds[(int) $t], $subjectIds[(int) $s])) {
                $rows["$sid," . $subjectIds[(int) $s] . ',' . $teacherIds[(int) $t]] = [$sid, $subjectIds[(int) $s], $teacherIds[(int) $t]];
            }
        }
        $this->insertMany('student_teachers', ['student_id', 'subject_id', 'teacher_id'], array_values($rows));
        $studentTeachers = count($rows);

        $auto = $this->autoFill();
        $db->commit();

        $gaps = TeachingList::gapCount($db);
        $parts = [count($teacherIds) . ' teachers', count($subjectIds) . ' subjects'];
        $newTeachers && $parts[] = "$newTeachers new teachers";
        $goneTeachers && $parts[] = "$goneTeachers no longer in Tusome";
        $message = 'Sync complete: ' . implode(', ', $parts) . ". {$auto} subjects filled in where only one teacher teaches them."
            . ($gaps ? " {$gaps} subjects still need a teacher on the Teaching list." : ' The Teaching list is complete.');
        return [$message, ['student_subjects' => $studentSubjects, 'student_teachers' => $studentTeachers, 'auto' => $auto, 'gaps' => $gaps]];
    }

    /** Insert or update rows matched on tusome_id; the rest become 'gone'. @return array tusome id => local id */
    private function upsert(string $table, array $items, callable $fields): array
    {
        $map = [];
        foreach ($this->db->query("SELECT id, tusome_id FROM $table")->fetchAll() as $r) {
            $map[(int) $r['tusome_id']] = (int) $r['id'];
        }
        $seen = [];
        foreach ($items as $item) {
            $tid = (int) ($item['id'] ?? 0);
            if ($tid <= 0) {
                continue;
            }
            $f = $fields($item);
            $cols = array_keys($f);
            if (isset($map[$tid])) {
                $set = implode(', ', array_map(fn ($c) => "$c = ?", $cols));
                $this->db->prepare("UPDATE $table SET $set, status = 'active' WHERE id = ?")->execute([...array_values($f), $map[$tid]]);
            } else {
                $this->db->prepare("INSERT INTO $table (tusome_id, " . implode(', ', $cols) . ') VALUES (?' . str_repeat(', ?', count($cols)) . ')')
                    ->execute([$tid, ...array_values($f)]);
                $map[$tid] = (int) $this->db->lastInsertId();
            }
            $seen[$tid] = $map[$tid];
        }
        $gone = array_diff_key($map, $seen);
        if ($gone) {
            $this->db->exec("UPDATE $table SET status = 'gone' WHERE id IN (" . implode(',', array_map('intval', $gone)) . ')');
        }
        return $seen;
    }

    private function insertMany(string $table, array $cols, array $rows): void
    {
        foreach (array_chunk($rows, 500) as $chunk) {
            $one = '(' . implode(',', array_fill(0, count($cols), '?')) . ')';
            $this->db->prepare("INSERT INTO $table (" . implode(',', $cols) . ') VALUES ' . implode(',', array_fill(0, count($chunk), $one)))
                ->execute(array_merge(...$chunk));
        }
    }

    /**
     * Subjects with exactly one active teacher: that teacher in every stream where a student takes
     * the subject (or every stream with no subject data at all), unless an admin set the cell.
     * @return int cells filled
     */
    private function autoFill(): int
    {
        $db = $this->db;
        $db->exec("DELETE FROM teaching WHERE source = 'auto'");
        $single = $db->query(
            "SELECT ts.subject_id, MIN(ts.teacher_id) AS teacher_id FROM teacher_subjects ts
             JOIN teachers t ON t.id = ts.teacher_id AND t.status = 'active'
             JOIN subjects s ON s.id = ts.subject_id AND s.status = 'active'
             GROUP BY ts.subject_id HAVING COUNT(*) = 1"
        )->fetchAll(PDO::FETCH_KEY_PAIR);
        if (!$single) {
            return 0;
        }
        $taken = [];
        foreach ($db->query(
            "SELECT DISTINCT s.class, s.stream, ss.subject_id FROM student_subjects ss JOIN students s ON s.id = ss.student_id AND s.status = 'active'"
        )->fetchAll() as $r) {
            $taken["{$r['class']}|{$r['stream']}"][(int) $r['subject_id']] = true;
        }
        $admin = [];
        foreach ($db->query("SELECT DISTINCT class, stream, subject_id FROM teaching WHERE source = 'admin'")->fetchAll() as $r) {
            $admin["{$r['class']}|{$r['stream']}|{$r['subject_id']}"] = true;
        }
        $insert = $db->prepare("INSERT IGNORE INTO teaching (class, stream, subject_id, teacher_id, source) VALUES (?, ?, ?, ?, 'auto')");
        $n = 0;
        foreach ($db->query("SELECT DISTINCT class, stream FROM students WHERE status = 'active'")->fetchAll() as $c) {
            $k = "{$c['class']}|{$c['stream']}";
            foreach ($single as $subjectId => $teacherId) {
                if ((isset($taken[$k]) && !isset($taken[$k][$subjectId])) || isset($admin["$k|$subjectId"])) {
                    continue;
                }
                if (!isset($taken[$k])) {
                    continue; // no subject data for this stream: leave it to the admins
                }
                $insert->execute([$c['class'], $c['stream'], $subjectId, $teacherId]);
                $n += $insert->rowCount();
            }
        }
        return $n;
    }

    private function finish(int $runId, string $status, string $message, ?array $details): void
    {
        $this->db->prepare('UPDATE sync_runs SET status = ?, finished_at = NOW(), message = ?, details = ? WHERE id = ?')
            ->execute([$status, mb_substr($message, 0, 1000), $details !== null ? json_encode($details) : null, $runId]);
    }
}
