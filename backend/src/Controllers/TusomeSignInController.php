<?php

declare(strict_types=1);

namespace StudentVoice\Controllers;

use StudentVoice\Auth\Auth;
use StudentVoice\Config;
use StudentVoice\Database;
use StudentVoice\Http\Request;
use StudentVoice\Services\SmisSync;
use StudentVoice\Support\Polls;
use StudentVoice\Support\Settings;
use StudentVoice\Support\VoterCode;

/**
 * GET /api/vote/tusome?t=<ticket> - a learner arriving from their Tusome dashboard.
 *
 * The ticket is base64url(JSON) "." HMAC-SHA256 of it with the key both systems share
 * (TUSOME_API_KEY here, STUDENT_VOICE_API_KEY in Tusome). It names the learner (admission number =
 * the SMIS RegNo, name, class, stream), their Tusome school, when it expires (2 minutes) and a
 * one-use number. A good ticket signs them in and opens their polls; a learner not on the list yet
 * is added from the ticket. Anything wrong goes back to the start page with a reason.
 */
final class TusomeSignInController
{
    public function enter(Request $r): never
    {
        $problem = $this->signIn((string) $r->query('t', ''));
        $base = self::appBase();
        header('Cache-Control: no-store');
        header('Location: ' . $base . ($problem ? '?tusome=' . $problem : 'vote'), true, 302);
        exit;
    }

    /** @return string|null a short reason code, or null when signed in */
    private function signIn(string $ticket): ?string
    {
        $key = (string) Config::get('TUSOME_API_KEY', '');
        if (strlen($key) < 32) {
            return 'not-linked';
        }
        $parts = explode('.', $ticket);
        if (count($parts) !== 2 || !hash_equals(hash_hmac('sha256', $parts[0], $key), $parts[1])) {
            return 'invalid';
        }
        $data = json_decode((string) base64_decode(strtr($parts[0], '-_', '+/'), true), true);
        if (!is_array($data) || empty($data['adm']) || empty($data['n']) || !preg_match('/^[a-f0-9]{24}$/', (string) $data['n'])) {
            return 'invalid';
        }
        if ((int) ($data['exp'] ?? 0) < time()) {
            return 'expired';
        }
        // the school this Student Voice is for (TUSOME_SCHOOL: its Tusome code; empty = the home school)
        if ((string) ($data['school'] ?? '') !== (string) Config::get('TUSOME_SCHOOL', '')) {
            return 'other-school';
        }

        $db = Database::get();
        $db->exec('DELETE FROM tusome_tickets WHERE used_at < NOW() - INTERVAL 1 DAY');
        $use = $db->prepare('INSERT IGNORE INTO tusome_tickets (nonce) VALUES (?)');
        $use->execute([(string) $data['n']]);
        if ($use->rowCount() !== 1) {
            return 'used';
        }

        $find = $db->prepare('SELECT id, status FROM students WHERE UPPER(TRIM(reg_no)) = ?');
        $find->execute([SmisSync::key((string) $data['adm'])]);
        $student = $find->fetch();
        if (!$student) {
            // not on the list yet (e.g. new in SMIS since the last sync): add them from the ticket
            $class = self::className((string) ($data['class'] ?? ''));
            if (!$class) {
                return 'not-listed';
            }
            $db->prepare("INSERT INTO students (reg_no, name, class, stream, voter_code, source) VALUES (?, ?, ?, ?, ?, 'manual')")
                ->execute([
                    mb_substr(trim((string) $data['adm']), 0, 40),
                    mb_substr(trim((string) ($data['name'] ?? '')), 0, 190),
                    $class,
                    mb_substr(strtoupper(trim((string) ($data['stream'] ?? ''))), 0, 20),
                    VoterCode::unique($db),
                ]);
            $student = ['id' => (int) $db->lastInsertId(), 'status' => 'active'];
        }
        if ($student['status'] !== 'active') {
            return 'left';
        }
        Auth::loginStudent((int) $student['id'], 'tusome');
        return null;
    }

    /** "S.1", "S1", "S 1" -> "S.1"; anything else -> null */
    private static function className(string $c): ?string
    {
        if (!preg_match('/^\s*S\.?\s*([1-6])/i', $c, $m)) {
            return null;
        }
        $name = 'S.' . $m[1];
        return in_array($name, Polls::CLASSES, true) ? $name : null;
    }

    /** Where the app lives: the part of this request's path before /api/ ("/" live, "/studentvoice/" locally) */
    public static function appBase(): string
    {
        $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        $at = strpos($uri, '/api/');
        return ($at === false ? '' : substr($uri, 0, $at)) . '/';
    }

    /** Super admins: voting numbers on or off, and whether the Tusome link is set up */
    public function settings(Request $r): never
    {
        \StudentVoice\Http\Response::ok([
            'voting_numbers' => Settings::votingNumbers(),
            'tusome_linked' => Settings::tusomeLinked(),
            'tusome_url' => Settings::tusomeUrl(),
        ]);
    }

    public function saveSettings(Request $r): never
    {
        Settings::set('voting_numbers', $r->input('voting_numbers') ? '1' : '0');
        $this->settings($r);
    }
}
