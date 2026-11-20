<?php

declare(strict_types=1);

namespace StudentVoice\Services;

use PDO;
use StudentVoice\Config;
use StudentVoice\Support\VoterCode;

/**
 * Brings the roster in line with the school's SMIS student list - the same API Tusome syncs from
 * (RegNo, Name, Class "S1".."S6", Stream). One run:
 *
 *  - New in SMIS: added, with a new random voting number.
 *  - In both: name, class and stream follow SMIS. The voting number never changes.
 *  - Here but no longer in SMIS: marked "left" (cannot vote). Students added by hand are left
 *    alone. A student who comes back to SMIS is active again, with the same voting number.
 *
 * All in one transaction: a failed run changes nothing. A run is refused when the answer looks
 * wrong (empty, unreadable, or far smaller than the school), so an SMIS outage can never mark the
 * whole school as left.
 */
final class SmisSync
{
    public const DEFAULT_URL = 'https://smisug.com//STMARKLIBRARYAPI/SLISLIBRARYAPI/api/student';
    /** a list smaller than this share of the active SMIS students is treated as a broken answer */
    private const MIN_ROSTER_SHARE = 0.6;

    public function __construct(private PDO $db)
    {
    }

    /** The running run (if any) and the last few */
    public function status(): array
    {
        $this->db->exec("UPDATE sync_runs SET status = 'failed', finished_at = NOW(), message = 'Stopped before it finished' WHERE status = 'running' AND started_at < NOW() - INTERVAL 15 MINUTE");
        $runs = $this->db->query(
            "SELECT r.id, r.started_at, r.finished_at, r.status, r.fetched, r.created, r.moved, r.renamed, r.left_school, r.returned, r.message,
                    COALESCE(a.name, '') AS started_by_name
             FROM sync_runs r LEFT JOIN admins a ON a.id = r.started_by
             ORDER BY r.id DESC LIMIT 5"
        )->fetchAll();
        return ['running' => isset($runs[0]) && $runs[0]['status'] === 'running', 'runs' => $runs];
    }

    /** @return array{ok: bool, message: string} */
    public function run(?int $startedBy): array
    {
        $lock = (int) $this->db->query("SELECT GET_LOCK('studentvoice_smis_sync', 0)")->fetchColumn();
        if ($lock !== 1) {
            return ['ok' => false, 'message' => 'A sync is already running. Wait for it to finish.'];
        }
        $this->db->prepare('INSERT INTO sync_runs (started_by) VALUES (?)')->execute([$startedBy]);
        $runId = (int) $this->db->lastInsertId();
        try {
            $result = $this->sync();
            $this->finish($runId, 'success', $result['counts'], $result['message'], $result['details']);
            return ['ok' => true, 'message' => $result['message']];
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            error_log('[studentvoice] SMIS sync failed: ' . $e->getMessage());
            $message = $e instanceof \RuntimeException ? $e->getMessage() : 'The sync failed and nothing was changed.';
            $this->finish($runId, 'failed', [], $message, null);
            return ['ok' => false, 'message' => $message];
        } finally {
            $this->db->query("SELECT RELEASE_LOCK('studentvoice_smis_sync')");
        }
    }

    private function sync(): array
    {
        $roster = $this->fetchRoster();

        $students = [];
        foreach ($this->db->query("SELECT id, reg_no, name, class, stream, status FROM students WHERE source = 'smis'")->fetchAll() as $s) {
            $students[self::key($s['reg_no'])] = $s;
        }
        $active = count(array_filter($students, fn ($s) => $s['status'] === 'active'));
        if (count($roster) < self::MIN_ROSTER_SHARE * $active) {
            throw new \RuntimeException(sprintf(
                'SMIS returned only %d students but %d are active here. That looks like an SMIS problem, so nothing was changed.',
                count($roster), $active
            ));
        }

        $counts = ['fetched' => count($roster), 'created' => 0, 'moved' => 0, 'renamed' => 0, 'left_school' => 0, 'returned' => 0];
        $details = ['created' => [], 'moved' => [], 'left_school' => [], 'returned' => []];

        // students added by hand who are now in SMIS too: SMIS takes them over (same voting number)
        $manual = [];
        foreach ($this->db->query("SELECT id, reg_no FROM students WHERE source = 'manual'")->fetchAll() as $m) {
            $manual[self::key($m['reg_no'])] = (int) $m['id'];
        }

        $this->db->beginTransaction();
        $insert = $this->db->prepare("INSERT INTO students (reg_no, name, class, stream, voter_code, source) VALUES (?, ?, ?, ?, ?, 'smis')");
        $update = $this->db->prepare("UPDATE students SET name = ?, class = ?, stream = ?, status = 'active', left_at = NULL, source = 'smis' WHERE id = ?");

        foreach ($roster as $key => $r) {
            $label = "{$r['regNo']} {$r['name']} ({$r['class']} {$r['stream']})";
            $existing = $students[$key] ?? null;
            if ($existing === null && isset($manual[$key])) {
                $update->execute([$r['name'], $r['class'], $r['stream'], $manual[$key]]);
                continue;
            }
            if ($existing === null) {
                $insert->execute([$r['regNo'], $r['name'], $r['class'], $r['stream'], VoterCode::unique($this->db)]);
                $counts['created']++;
                $details['created'][] = $label;
                continue;
            }
            $moved = $existing['class'] !== $r['class'] || $existing['stream'] !== $r['stream'];
            $renamed = $r['name'] !== '' && $existing['name'] !== $r['name'];
            $returned = $existing['status'] !== 'active';
            if (!$moved && !$renamed && !$returned) {
                continue;
            }
            $update->execute([$r['name'] !== '' ? $r['name'] : $existing['name'], $r['class'], $r['stream'], (int) $existing['id']]);
            if ($moved) {
                $counts['moved']++;
                $details['moved'][] = "{$r['regNo']} {$r['name']}: {$existing['class']} {$existing['stream']} -> {$r['class']} {$r['stream']}";
            }
            $renamed && $counts['renamed']++;
            if ($returned) {
                $counts['returned']++;
                $details['returned'][] = $label;
            }
        }

        $leave = $this->db->prepare("UPDATE students SET status = 'left', left_at = NOW() WHERE id = ?");
        foreach ($students as $key => $s) {
            if (isset($roster[$key]) || $s['status'] !== 'active') {
                continue;
            }
            $leave->execute([(int) $s['id']]);
            $counts['left_school']++;
            $details['left_school'][] = "{$s['reg_no']} {$s['name']}";
        }
        $this->db->commit();

        $parts = [];
        foreach (['created' => 'new students added', 'moved' => 'moved class or stream', 'left_school' => 'no longer in SMIS (cannot vote)', 'returned' => 'back in SMIS'] as $k => $text) {
            if ($counts[$k]) {
                $parts[] = "{$counts[$k]} {$text}";
            }
        }
        $message = 'Sync complete: ' . ($parts ? implode(', ', $parts) : 'everything already matched SMIS') . ". {$counts['fetched']} students in SMIS.";
        return ['counts' => $counts, 'message' => $message, 'details' => $details];
    }

    /** RegNo key => [regNo, name, class "S.1".."S.6", stream] */
    private function fetchRoster(): array
    {
        $url = (string) (Config::get('SMIS_STUDENTS_API_URL') ?: self::DEFAULT_URL);
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 90, CURLOPT_CONNECTTIMEOUT => 20, CURLOPT_FOLLOWLOCATION => true]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($body === false || $code !== 200) {
            throw new \RuntimeException('Could not reach SMIS' . ($err ? " ({$err})" : " (HTTP {$code})") . '. Nothing was changed.');
        }
        $rows = json_decode((string) $body, true);
        if (!is_array($rows) || !$rows) {
            throw new \RuntimeException('SMIS sent an empty or unreadable list. Nothing was changed.');
        }
        $roster = [];
        foreach ($rows as $row) {
            $regNo = trim((string) ($row['RegNo'] ?? ''));
            $class = strtoupper(trim((string) ($row['Class'] ?? '')));
            if ($regNo === '' || !preg_match('/^S\.?\s*([1-6])$/', $class, $m)) {
                continue;
            }
            $roster[self::key($regNo)] = [
                'regNo' => $regNo,
                'name' => mb_substr(trim((string) preg_replace('/\s+/', ' ', (string) ($row['Name'] ?? ''))), 0, 190),
                'class' => 'S.' . $m[1],
                'stream' => mb_substr(strtoupper(trim((string) ($row['Stream'] ?? ''))), 0, 20),
            ];
        }
        if (!$roster) {
            throw new \RuntimeException('SMIS sent no usable students. Nothing was changed.');
        }
        return $roster;
    }

    public static function key(string $regNo): string
    {
        return strtoupper(trim($regNo));
    }

    private function finish(int $runId, string $status, array $counts, string $message, ?array $details): void
    {
        $this->db->prepare(
            'UPDATE sync_runs SET status = ?, finished_at = NOW(), fetched = ?, created = ?, moved = ?, renamed = ?, left_school = ?, returned = ?, message = ?, details = ? WHERE id = ?'
        )->execute([
            $status, $counts['fetched'] ?? 0, $counts['created'] ?? 0, $counts['moved'] ?? 0, $counts['renamed'] ?? 0,
            $counts['left_school'] ?? 0, $counts['returned'] ?? 0, mb_substr($message, 0, 1000),
            $details !== null ? json_encode($details) : null, $runId,
        ]);
    }
}
