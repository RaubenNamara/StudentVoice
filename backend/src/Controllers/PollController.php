<?php

declare(strict_types=1);

namespace StudentVoice\Controllers;

use StudentVoice\Auth\Auth;
use StudentVoice\Database;
use StudentVoice\Http\Request;
use StudentVoice\Http\Response;
use StudentVoice\Support\Polls;

/** Admins write polls and their questions, choose who votes, and open and close them. */
final class PollController
{
    public function index(Request $r): never
    {
        $db = Database::get();
        $rows = $db->query(
            "SELECT p.*, (SELECT COUNT(*) FROM questions q WHERE q.poll_id = p.id) AS questions,
                    (SELECT COUNT(*) FROM voters v WHERE v.poll_id = p.id) AS votes
             FROM polls p ORDER BY FIELD(p.status, 'open', 'draft', 'closed'), p.updated_at DESC"
        )->fetchAll();
        $polls = [];
        foreach ($rows as $row) {
            $p = Polls::shape($row);
            $p['questions'] = (int) $row['questions'];
            $p['votes'] = (int) $row['votes'];
            $p['eligible'] = Polls::eligibleCount($db, $p);
            $polls[] = $p;
        }
        Response::ok($polls);
    }

    public function show(Request $r): never
    {
        $db = Database::get();
        $poll = $this->poll((int) $r->param('id'));
        $poll['questions'] = Polls::questions($db, $poll['id']);
        $poll['votes'] = Polls::voteCount($db, $poll['id']);
        $poll['eligible'] = Polls::eligibleCount($db, $poll);
        Response::ok($poll);
    }

    public function store(Request $r): never
    {
        [$title, $intro, $audience, $closesAt] = $this->details($r);
        $about = $r->string('about') === 'teachers' ? 'teachers' : 'school';
        $db = Database::get();
        $db->prepare('INSERT INTO polls (title, intro, about, sample_size, audience, closes_at, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)')
            ->execute([$title, $intro, $about, $about === 'teachers' ? 8 : null, $audience, $closesAt, Auth::adminId()]);
        Response::ok(['id' => (int) $db->lastInsertId()], 201);
    }

    public function update(Request $r): never
    {
        $poll = $this->poll((int) $r->param('id'));
        [$title, $intro, $audience, $closesAt] = $this->details($r);
        $about = $r->string('about') === 'teachers' ? 'teachers' : ($r->string('about') === 'school' ? 'school' : $poll['about']);
        if ($about !== $poll['about'] && Polls::voteCount(Database::get(), $poll['id']) > 0) {
            Response::error('Students have already voted, so the poll cannot switch between the school and teachers. Make a copy instead.', 422);
        }
        // statements per teacher (teacher polls): empty = all
        $sample = (int) $r->input('sample_size', 0);
        $sample = $about === 'teachers' && $sample > 0 ? min(50, $sample) : null;
        Database::get()->prepare('UPDATE polls SET title = ?, intro = ?, about = ?, sample_size = ?, audience = ?, closes_at = ? WHERE id = ?')
            ->execute([$title, $intro, $about, $sample, $audience, $closesAt, $poll['id']]);
        Response::ok();
    }

    /**
     * Saves the whole list of questions at once (in order). Once anyone has voted the questions
     * are fixed, so every ballot answered the same questions; a typo can still be fixed in a
     * question's wording, but nothing can be added, removed or have its type or options changed.
     */
    public function saveQuestions(Request $r): never
    {
        $poll = $this->poll((int) $r->param('id'));
        $items = $r->input('questions', []);
        if (!is_array($items) || !$items) {
            Response::error('Add at least one question.', 422);
        }
        if (count($items) > 150) {
            Response::error('A poll can have at most 150 questions.', 422);
        }
        $clean = [];
        $errors = [];
        foreach (array_values($items) as $i => $q) {
            $prompt = trim((string) ($q['prompt'] ?? ''));
            $type = (string) ($q['type'] ?? '');
            $section = trim((string) ($q['section'] ?? ''));
            $options = array_values(array_filter(array_map(fn ($o) => mb_substr(trim((string) $o), 0, 200), is_array($q['options'] ?? null) ? $q['options'] : []), fn ($o) => $o !== ''));
            if ($prompt === '') {
                $errors["q$i"] = 'Question ' . ($i + 1) . ' has no wording.';
            }
            if (!in_array($type, Polls::TYPES, true)) {
                $errors["q$i"] = 'Question ' . ($i + 1) . ' needs a type.';
            }
            if (in_array($type, ['choice', 'multi'], true) && (count($options) < 2 || count($options) > 20)) {
                $errors["q$i"] = 'Question ' . ($i + 1) . ' needs 2 to 20 options.';
            }
            if (count($options) !== count(array_unique(array_map('mb_strtolower', $options)))) {
                $errors["q$i"] = 'Question ' . ($i + 1) . ' has the same option twice.';
            }
            $clean[] = [
                'id' => isset($q['id']) ? (int) $q['id'] : 0,
                'prompt' => mb_substr($prompt, 0, 2000),
                'type' => $type,
                'section' => $section !== '' ? mb_substr($section, 0, 150) : null,
                'options' => in_array($type, ['choice', 'multi'], true) ? $options : null,
                'required' => !empty($q['required']),
            ];
        }
        if ($errors) {
            Response::error(reset($errors), 422, $errors);
        }

        $db = Database::get();
        $existing = [];
        foreach (Polls::questions($db, $poll['id']) as $q) {
            $existing[$q['id']] = $q;
        }

        if (Polls::voteCount($db, $poll['id']) > 0) {
            $ids = array_column($clean, 'id');
            sort($ids);
            $old = array_keys($existing);
            sort($old);
            if ($ids !== $old) {
                Response::error('Students have already voted, so questions cannot be added or removed. Make a copy of the poll to change it.', 422);
            }
            foreach ($clean as $q) {
                $e = $existing[$q['id']];
                if ($e['type'] !== $q['type'] || ($e['options'] ?: null) !== $q['options']) {
                    Response::error('Students have already voted, so a question\'s type and options cannot change. You can still fix the wording.', 422);
                }
            }
            Database::transaction(function ($db) use ($clean, $poll) {
                $stmt = $db->prepare('UPDATE questions SET prompt = ?, section = ? WHERE id = ? AND poll_id = ?');
                foreach ($clean as $q) {
                    $stmt->execute([$q['prompt'], $q['section'], $q['id'], $poll['id']]);
                }
                $db->prepare('UPDATE polls SET updated_at = NOW() WHERE id = ?')->execute([$poll['id']]);
            });
            Response::ok();
        }

        Database::transaction(function ($db) use ($clean, $poll, $existing) {
            $update = $db->prepare('UPDATE questions SET position = ?, section = ?, prompt = ?, type = ?, options = ?, required = ? WHERE id = ? AND poll_id = ?');
            $insert = $db->prepare('INSERT INTO questions (poll_id, position, section, prompt, type, options, required) VALUES (?, ?, ?, ?, ?, ?, ?)');
            $kept = [];
            foreach ($clean as $i => $q) {
                $opts = $q['options'] !== null ? json_encode($q['options'], JSON_UNESCAPED_UNICODE) : null;
                if ($q['id'] && isset($existing[$q['id']])) {
                    $update->execute([$i + 1, $q['section'], $q['prompt'], $q['type'], $opts, (int) $q['required'], $q['id'], $poll['id']]);
                    $kept[] = $q['id'];
                } else {
                    $insert->execute([$poll['id'], $i + 1, $q['section'], $q['prompt'], $q['type'], $opts, (int) $q['required']]);
                }
            }
            $gone = array_diff(array_keys($existing), $kept);
            if ($gone) {
                $db->prepare('DELETE FROM questions WHERE poll_id = ? AND id IN (' . implode(',', array_map('intval', $gone)) . ')')->execute([$poll['id']]);
            }
            $db->prepare('UPDATE polls SET updated_at = NOW() WHERE id = ?')->execute([$poll['id']]);
        });
        Response::ok();
    }

    /** open | closed | draft (back to draft only while nobody has voted) */
    public function setStatus(Request $r): never
    {
        $poll = $this->poll((int) $r->param('id'));
        $status = $r->string('status');
        $db = Database::get();
        if ($status === 'open') {
            if (!Polls::questions($db, $poll['id'])) {
                Response::error('Add questions before opening the poll.', 422);
            }
            if ($poll['closes_at'] && strtotime((string) $poll['closes_at']) <= time()) {
                Response::error('The closing time has passed. Change or clear it first.', 422);
            }
            $db->prepare("UPDATE polls SET status = 'open', opened_at = COALESCE(opened_at, NOW()), closed_at = NULL WHERE id = ?")->execute([$poll['id']]);
        } elseif ($status === 'closed') {
            $db->prepare("UPDATE polls SET status = 'closed', closed_at = NOW() WHERE id = ?")->execute([$poll['id']]);
        } elseif ($status === 'draft') {
            if (Polls::voteCount($db, $poll['id']) > 0) {
                Response::error('Students have already voted in this poll, so it cannot go back to draft. Close it instead.', 422);
            }
            $db->prepare("UPDATE polls SET status = 'draft', opened_at = NULL WHERE id = ?")->execute([$poll['id']]);
        } else {
            Response::error('Unknown status.', 422);
        }
        Response::ok();
    }

    /** A new draft with the same questions (to run again next term, or to change a poll with votes) */
    public function duplicate(Request $r): never
    {
        $poll = $this->poll((int) $r->param('id'));
        $id = Database::transaction(function ($db) use ($poll) {
            $db->prepare('INSERT INTO polls (title, intro, about, sample_size, audience, created_by) VALUES (?, ?, ?, ?, ?, ?)')
                ->execute([mb_substr($poll['title'] . ' (copy)', 0, 190), $poll['intro'], $poll['about'], $poll['sample_size'], $poll['audience'] ? json_encode($poll['audience']) : null, Auth::adminId()]);
            $id = (int) $db->lastInsertId();
            $db->prepare('INSERT INTO questions (poll_id, position, section, prompt, type, options, required)
                          SELECT ?, position, section, prompt, type, options, required FROM questions WHERE poll_id = ? ORDER BY position, id')
                ->execute([$id, $poll['id']]);
            return $id;
        });
        Response::ok(['id' => $id], 201);
    }

    /** Only polls nobody has voted in; a poll with votes is closed, never deleted */
    public function destroy(Request $r): never
    {
        $poll = $this->poll((int) $r->param('id'));
        if (Polls::voteCount(Database::get(), $poll['id']) > 0) {
            Response::error('Students have voted in this poll, so it cannot be deleted. Close it instead.', 422);
        }
        Database::get()->prepare('DELETE FROM polls WHERE id = ?')->execute([$poll['id']]);
        Response::ok();
    }

    private function poll(int $id): array
    {
        $poll = Polls::find(Database::get(), $id);
        if (!$poll) {
            Response::error('Poll not found.', 404);
        }
        return $poll;
    }

    private function details(Request $r): array
    {
        $title = mb_substr(trim($r->string('title')), 0, 190);
        if ($title === '') {
            Response::error('Give the poll a title.', 422, ['title' => 'Required']);
        }
        $intro = trim((string) $r->input('intro', ''));
        $audience = $r->input('audience');
        $audience = is_array($audience) ? array_values(array_intersect(Polls::CLASSES, $audience)) : [];
        // every class ticked is the same as "everyone" (and stays so when the school adds a stream)
        $audienceJson = $audience && count($audience) < count(Polls::CLASSES) ? json_encode($audience) : null;
        $closesAt = $r->string('closes_at');
        if ($closesAt !== '') {
            $t = strtotime($closesAt);
            if ($t === false) {
                Response::error('The closing time is not a date.', 422, ['closes_at' => 'Not a date']);
            }
            $closesAt = date('Y-m-d H:i:s', $t);
        }
        return [$title, $intro !== '' ? mb_substr($intro, 0, 5000) : null, $audienceJson, $closesAt ?: null];
    }
}
