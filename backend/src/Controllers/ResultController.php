<?php

declare(strict_types=1);

namespace StudentVoice\Controllers;

use StudentVoice\Database;
use StudentVoice\Http\Request;
use StudentVoice\Http\Response;
use StudentVoice\Support\Polls;

/**
 * Results: who has voted (turnout, by class) and what was answered (from the ballots, which
 * name no student). A class or stream view with fewer than Polls::MIN_GROUP ballots is not
 * shown, and written answers come back in random order, so neither can point at a student.
 */
final class ResultController
{
    public function show(Request $r): never
    {
        $db = Database::get();
        $poll = Polls::find($db, (int) $r->param('id'));
        if (!$poll) {
            Response::error('Poll not found.', 404);
        }
        $class = $r->string('class');
        $stream = $r->string('stream');

        $where = 'b.poll_id = ?';
        $args = [$poll['id']];
        if ($class !== '') {
            $where .= ' AND b.class = ?';
            $args[] = $class;
            if ($stream !== '') {
                $where .= ' AND b.stream = ?';
                $args[] = $stream;
            }
        }
        $count = $db->prepare("SELECT COUNT(*) FROM ballots b WHERE $where");
        $count->execute($args);
        $ballots = (int) $count->fetchColumn();
        $hidden = $class !== '' && $ballots > 0 && $ballots < Polls::MIN_GROUP;

        $questions = Polls::questions($db, $poll['id']);
        $results = [];
        if (!$hidden) {
            $stmt = $db->prepare("SELECT a.question_id, a.value_num, a.value_text FROM answers a JOIN ballots b ON b.id = a.ballot_id WHERE $where");
            $stmt->execute($args);
            $byQ = [];
            foreach ($stmt->fetchAll() as $a) {
                $byQ[(int) $a['question_id']][] = $a;
            }
            foreach ($questions as $q) {
                $results[] = $this->summarise($q, $byQ[$q['id']] ?? [], $ballots);
            }
        }

        Response::ok([
            'poll' => $poll,
            'filter' => ['class' => $class, 'stream' => $stream],
            'ballots' => $ballots,
            'hidden' => $hidden,
            'min_group' => Polls::MIN_GROUP,
            'turnout' => $this->turnout($db, $poll),
            'results' => $results,
        ]);
    }

    private function summarise(array $q, array $answers, int $ballots): array
    {
        $out = ['question' => $q, 'answered' => count($answers), 'skipped' => max(0, $ballots - count($answers))];
        switch ($q['type']) {
            case 'scale':
                $counts = array_fill(1, 5, 0);
                foreach ($answers as $a) {
                    $counts[(int) $a['value_num']] = ($counts[(int) $a['value_num']] ?? 0) + 1;
                }
                $sum = 0;
                foreach ($counts as $v => $n) {
                    $sum += $v * $n;
                }
                $out['counts'] = array_values($counts);
                $out['average'] = $answers ? round($sum / count($answers), 2) : null;
                // agree or strongly agree
                $out['agree_share'] = $answers ? round(($counts[4] + $counts[5]) / count($answers) * 100) : null;
                break;
            case 'yesno':
                $yes = count(array_filter($answers, fn ($a) => (int) $a['value_num'] === 1));
                $out['counts'] = [$yes, count($answers) - $yes];
                break;
            case 'choice':
            case 'multi':
                $counts = array_fill(0, count($q['options']), 0);
                foreach ($answers as $a) {
                    $picked = $q['type'] === 'choice' ? [(int) $a['value_num']] : (json_decode((string) $a['value_text'], true) ?: []);
                    foreach ($picked as $i) {
                        if (isset($counts[$i])) {
                            $counts[$i]++;
                        }
                    }
                }
                $out['counts'] = $counts;
                break;
            default:
                $texts = array_values(array_filter(array_map(fn ($a) => (string) $a['value_text'], $answers), fn ($t) => $t !== ''));
                shuffle($texts);
                $out['texts'] = $texts;
        }
        return $out;
    }

    /** Voted / could vote, per class and stream (voters know the class; streams come from ballots) */
    private function turnout(\PDO $db, array $poll): array
    {
        $eligible = [];
        foreach (StudentController::classList() as $c) {
            if (!Polls::forClass($poll, $c['class'])) {
                continue;
            }
            $eligible[$c['class']] = ['class' => $c['class'], 'students' => $c['students'], 'voted' => 0, 'streams' => []];
            foreach ($c['streams'] as $s) {
                $eligible[$c['class']]['streams'][$s['stream']] = ['stream' => $s['stream'], 'students' => $s['students'], 'voted' => 0];
            }
        }
        $stmt = $db->prepare('SELECT class, stream, COUNT(*) AS n FROM ballots WHERE poll_id = ? GROUP BY class, stream');
        $stmt->execute([$poll['id']]);
        foreach ($stmt->fetchAll() as $row) {
            $eligible[$row['class']] ??= ['class' => $row['class'], 'students' => 0, 'voted' => 0, 'streams' => []];
            $eligible[$row['class']]['voted'] += (int) $row['n'];
            $eligible[$row['class']]['streams'][$row['stream']] ??= ['stream' => $row['stream'], 'students' => 0, 'voted' => 0];
            $eligible[$row['class']]['streams'][$row['stream']]['voted'] = (int) $row['n'];
        }
        ksort($eligible);
        $classes = array_values(array_map(function ($c) {
            ksort($c['streams']);
            $c['streams'] = array_values($c['streams']);
            return $c;
        }, $eligible));
        return [
            'eligible' => array_sum(array_column($classes, 'students')),
            'voted' => array_sum(array_column($classes, 'voted')),
            'classes' => $classes,
        ];
    }

    /** The admin dashboard: roster size, polls, and votes over the last two weeks */
    public function overview(Request $r): never
    {
        $db = Database::get();
        $students = (int) $db->query("SELECT COUNT(*) FROM students WHERE status = 'active'")->fetchColumn();
        $polls = [];
        foreach ($db->query("SELECT * FROM polls WHERE status = 'open' ORDER BY opened_at DESC")->fetchAll() as $row) {
            $p = Polls::shape($row);
            $p['votes'] = Polls::voteCount($db, $p['id']);
            $p['eligible'] = Polls::eligibleCount($db, $p);
            $polls[] = $p;
        }
        $counts = $db->query("SELECT status, COUNT(*) AS n FROM polls GROUP BY status")->fetchAll(\PDO::FETCH_KEY_PAIR);
        $days = $db->query("SELECT DATE(voted_at) AS day, COUNT(*) AS n FROM voters WHERE voted_at >= CURDATE() - INTERVAL 13 DAY GROUP BY DATE(voted_at)")->fetchAll(\PDO::FETCH_KEY_PAIR);
        $series = [];
        for ($i = 13; $i >= 0; $i--) {
            $d = date('Y-m-d', strtotime("-$i day"));
            $series[] = ['day' => $d, 'votes' => (int) ($days[$d] ?? 0)];
        }
        $lastSync = $db->query("SELECT finished_at, status, message FROM sync_runs WHERE status <> 'running' ORDER BY id DESC LIMIT 1")->fetch() ?: null;
        Response::ok([
            'students' => $students,
            'polls' => ['open' => (int) ($counts['open'] ?? 0), 'draft' => (int) ($counts['draft'] ?? 0), 'closed' => (int) ($counts['closed'] ?? 0)],
            'open_polls' => $polls,
            'votes_by_day' => $series,
            'votes_today' => end($series)['votes'],
            'last_sync' => $lastSync,
        ]);
    }
}
