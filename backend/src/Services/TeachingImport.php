<?php

declare(strict_types=1);

namespace StudentVoice\Services;

use PDO;

/**
 * Fills the Teaching list from a spreadsheet of teachers and the streams they teach (read in the
 * browser; rows arrive as [name, department, subjects, streams[]]).
 *
 * Teachers are matched to Tusome's by name, whatever the word order ("MARVIN ESETE" is "Esete
 * Marvin") and allowing small spelling differences ("CHEPTOEK" / "Chepteok"); anyone not found
 * is added. Subjects come from the sheet's named subjects ("BIOLOGY (24)"); Tusome's record of a
 * teacher's subjects settles "CRE/IRE" and teachers who appear only in elective blocks
 * ("AGR/F.ART/LUG/TD"). A teacher with several subjects gets each one only in streams where
 * students take it (per Tusome), so English lands in O-Level and General Paper in A-Level.
 *
 * preview() changes nothing and reports what would happen; apply() writes it in one transaction.
 */
final class TeachingImport
{
    /** words in the sheet => subject names (as Tusome writes them, compared without dots/spaces) */
    private const WORDS = [
        'BIOLOGY' => ['BIO'], 'BIO' => ['BIO'],
        'CHEMISTRY' => ['CHEM'], 'CHEM' => ['CHEM'],
        'PHYSICS' => ['PHY'], 'PHY' => ['PHY'],
        'MATHEMATICS' => ['MTC'], 'MATHS' => ['MTC'], 'MAT' => ['MTC'], 'MTC' => ['MTC'],
        'SUBMAT' => ['SUBMTC'], 'SUBMATH' => ['SUBMTC'], 'SUBMATHEMATICS' => ['SUBMTC'], 'SUBMTC' => ['SUBMTC'],
        'ENGLISHLANGUAGE' => ['ENG'], 'ENGLISH' => ['ENG'], 'ENG' => ['ENG'],
        'LITERATURE' => ['LIT'], 'LITERATUREINENGLISH' => ['LIT'], 'LIT' => ['LIT'],
        'HISTORY' => ['HIST'], 'HIST' => ['HIST'],
        'GEOGRAPHY' => ['GEOG'], 'GEO' => ['GEOG'], 'GEOG' => ['GEOG'],
        'KISWAHILI' => ['KISW'], 'KIS' => ['KISW'], 'KISW' => ['KISW'],
        'LUGANDA' => ['LUG'], 'LUG' => ['LUG'],
        'ENTREPRENEURSHIPEDUCATION' => ['ENT'], 'ENTREPRENEURSHIP' => ['ENT'], 'ENT' => ['ENT'],
        'INFORMATIONCOMMUNICATIONTECHNOLOGY' => ['ICT'], 'ICT' => ['ICT'], 'SUBICT' => ['ICT'], 'COMPUTER' => ['ICT'],
        'ECONOMICS' => ['ECON'], 'ECO' => ['ECON'], 'ECON' => ['ECON'],
        'GENERALPAPER' => ['GP'], 'GP' => ['GP'],
        'PHYSICALEDUCATION' => ['PE'], 'PE' => ['PE'],
        'AGRICULTURE' => ['AGRIC'], 'AGR' => ['AGRIC'], 'AGRIC' => ['AGRIC'],
        'FINEART' => ['FART'], 'FART' => ['FART'], 'ART' => ['FART'],
        'TECHNICALDRAWING' => ['TD'], 'TD' => ['TD'],
        'CRE' => ['CRE'], 'IRE' => ['IRE'], 'CREIRE' => ['CRE', 'IRE'],
        'CHRISTIANRELIGIOUSEDUCATION' => ['CRE'], 'ISLAMICRELIGIOUSEDUCATION' => ['IRE'],
    ];

    private array $subjects = [];      // normalised name => subject id
    private array $subjectNames = [];  // id => name
    private array $teachers = [];      // id => [name, tokens]
    private array $tusomeSubjects = []; // teacher id => [subject ids]
    private array $streamTakes = [];   // "S.1|A" => [subject id => true]
    private array $streams = [];       // "S.1|A" => true (streams with students)

    public function __construct(private PDO $db)
    {
        foreach ($db->query("SELECT id, name FROM subjects WHERE status = 'active'")->fetchAll() as $s) {
            $this->subjects[self::norm($s['name'])] = (int) $s['id'];
            $this->subjectNames[(int) $s['id']] = $s['name'];
        }
        foreach ($db->query("SELECT id, name FROM teachers WHERE status = 'active'")->fetchAll() as $t) {
            $this->teachers[(int) $t['id']] = [$t['name'], self::tokens($t['name'])];
        }
        foreach ($db->query('SELECT teacher_id, subject_id FROM teacher_subjects')->fetchAll(PDO::FETCH_NUM) as [$t, $s]) {
            $this->tusomeSubjects[(int) $t][] = (int) $s;
        }
        foreach ($db->query("SELECT DISTINCT st.class, st.stream, x.subject_id FROM student_subjects x JOIN students st ON st.id = x.student_id AND st.status = 'active'")->fetchAll(PDO::FETCH_NUM) as [$c, $sr, $su]) {
            $this->streamTakes["$c|$sr"][(int) $su] = true;
        }
        foreach ($db->query("SELECT DISTINCT class, stream FROM students WHERE status = 'active'")->fetchAll(PDO::FETCH_NUM) as [$c, $sr]) {
            $this->streams["$c|$sr"] = true;
        }
    }

    /** What the file would do, without changing anything */
    public function preview(array $rows): array
    {
        $plan = $this->plan($rows);
        $current = [];
        foreach ($this->db->query('SELECT class, stream, subject_id, teacher_id FROM teaching')->fetchAll(PDO::FETCH_NUM) as [$c, $sr, $su, $te]) {
            $current["$c|$sr|$su"][] = (int) $te;
        }
        $changed = 0;
        foreach ($plan['cells'] as $k => $keys) {
            $ids = array_filter($keys, 'is_int');
            sort($ids);
            $now = $current[$k] ?? [];
            sort($now);
            if ($ids !== $now || count($ids) !== count($keys)) {
                $changed++;
            }
        }
        $byClass = [];
        foreach (array_keys($plan['cells']) as $k) {
            [$c] = explode('|', $k);
            $byClass[$c] = ($byClass[$c] ?? 0) + 1;
        }
        ksort($byClass);
        return [
            'teachers' => $plan['teachers'],
            'cells' => count($plan['cells']),
            'cells_by_class' => $byClass,
            'changed' => $changed,
            'streams' => count($plan['streams']),
            'unknown_streams' => array_values(array_unique($plan['unknownStreams'])),
            'shared' => $plan['shared'],
            'summary' => [
                'rows' => count($plan['teachers']),
                'matched' => count(array_filter($plan['teachers'], fn ($t) => $t['match'] !== null)),
                'new' => count(array_filter($plan['teachers'], fn ($t) => $t['match'] === null && !$t['skip'])),
                'unclear' => count(array_filter($plan['teachers'], fn ($t) => $t['skip'])),
            ],
        ];
    }

    /**
     * Writes the plan. $mode 'replace': every stream in the file gets exactly the file's teachers
     * (cells set by hand there are replaced); 'fill': only cells with nobody yet are filled.
     */
    public function apply(array $rows, string $mode): array
    {
        $plan = $this->plan($rows);
        return \StudentVoice\Database::transaction(function (PDO $db) use ($plan, $mode) {
            // new teachers first
            $ids = [];
            $insert = $db->prepare("INSERT INTO teachers (tusome_id, source, name) VALUES (NULL, 'upload', ?)");
            $link = $db->prepare('INSERT IGNORE INTO teacher_subjects (teacher_id, subject_id) VALUES (?, ?)');
            foreach ($plan['teachers'] as $i => $t) {
                if ($t['skip']) {
                    continue;
                }
                if ($t['match']) {
                    $ids["new:$i"] = $t['match']['id'];
                    continue;
                }
                $existing = $db->prepare("SELECT id FROM teachers WHERE source = 'upload' AND name = ?");
                $existing->execute([$t['name']]);
                $id = (int) $existing->fetchColumn();
                if (!$id) {
                    $insert->execute([$t['name']]);
                    $id = (int) $db->lastInsertId();
                }
                $db->prepare("UPDATE teachers SET status = 'active' WHERE id = ?")->execute([$id]);
                foreach ($t['subject_ids'] as $su) {
                    $link->execute([$id, $su]);
                }
                $ids["new:$i"] = $id;
            }

            $filled = [];
            foreach ($db->query('SELECT DISTINCT class, stream, subject_id FROM teaching')->fetchAll(PDO::FETCH_NUM) as [$c, $sr, $su]) {
                $filled["$c|$sr|$su"] = true;
            }
            if ($mode === 'replace') {
                $clear = $db->prepare('DELETE FROM teaching WHERE class = ? AND stream = ?');
                foreach (array_keys($plan['streams']) as $k) {
                    [$c, $sr] = explode('|', $k);
                    $clear->execute([$c, $sr]);
                }
                $filled = [];
            }
            $add = $db->prepare("INSERT IGNORE INTO teaching (class, stream, subject_id, teacher_id, source) VALUES (?, ?, ?, ?, 'upload')");
            $written = 0;
            foreach ($plan['cells'] as $k => $keys) {
                if (isset($filled[$k])) {
                    continue;
                }
                [$c, $sr, $su] = explode('|', $k);
                foreach ($keys as $key) {
                    $tid = is_int($key) ? $key : ($ids[$key] ?? null);
                    if ($tid) {
                        $add->execute([$c, $sr, (int) $su, $tid]);
                        $written += $add->rowCount();
                    }
                }
            }
            $new = count(array_filter($plan['teachers'], fn ($t) => !$t['match'] && !$t['skip']));
            TeachingList::applyTeacherSource($db);
            return ['written' => $written, 'new_teachers' => $new, 'gaps' => TeachingList::gapCount($db)];
        });
    }

    /**
     * teachers: one entry per row (match, subjects, why skipped); cells: "S.1|A|subject" =>
     * [teacher id or "new:row"]; shared: cells with more than one teacher
     */
    private function plan(array $rows): array
    {
        $teachers = [];
        $cells = [];
        $streamsSeen = [];
        $unknown = [];
        $keyNames = [];
        // read every row first
        $entries = [];
        foreach (array_values($rows) as $i => $row) {
            $name = trim((string) preg_replace('/\s+/', ' ', (string) ($row['name'] ?? '')));
            if ($name === '') {
                continue;
            }
            $match = $this->match($name);
            [$subjectIds, $note] = $this->subjectsFor((string) ($row['subjects'] ?? ''), (string) ($row['department'] ?? ''), $match['id'] ?? null);
            $streams = [];
            foreach ((array) ($row['streams'] ?? []) as $code) {
                $parsed = $this->findStream((string) $code);
                if (!$parsed) {
                    if (trim((string) $code) !== '') {
                        $unknown[] = trim((string) $code);
                    }
                    continue;
                }
                $streams[$parsed] = true;
            }
            $key = $match ? $match['id'] : "new:$i";
            $keyNames[$key] = $match ? $match['name'] : $name;
            $entries[] = compact('i', 'name', 'match', 'subjectIds', 'note', 'streams', 'key') + ['placed' => 0];
        }

        // pass 1: teachers with one subject; pass 2: teachers with several, who take the subjects
        // not already covered in each stream (so an English-and-Literature teacher in a stream
        // that already has an English teacher is put under Literature)
        foreach ([1, 2] as $pass) {
            foreach ($entries as &$e) {
                if (!$e['subjectIds'] || (count($e['subjectIds']) > 1) !== ($pass === 2)) {
                    continue;
                }
                foreach (array_keys($e['streams']) as $k) {
                    $here = $this->forLevel($e['subjectIds'], $k);
                    if (!$here) {
                        continue;
                    }
                    if ($pass === 2) {
                        $taken = isset($this->streamTakes[$k]) ? array_values(array_filter($here, fn ($s) => isset($this->streamTakes[$k][$s]))) : $here;
                        $open = array_values(array_filter($here, fn ($s) => !isset($cells["$k|$s"])));
                        $openTaken = array_values(array_intersect($taken, $open));
                        $here = $openTaken ?: ($open ?: ($taken ?: $here));
                    }
                    $streamsSeen[$k] = true;
                    foreach ($here as $su) {
                        $cells["$k|$su"][] = $e['key'];
                        $e['placed']++;
                    }
                }
            }
            unset($e);
        }
        foreach ($entries as $e) {
            $teachers[] = [
                'row' => $e['i'] + 1,
                'name' => $e['name'],
                'match' => $e['match'],
                'subjects' => array_map(fn ($s) => $this->subjectNames[$s], $e['subjectIds']),
                'subject_ids' => $e['subjectIds'],
                'streams' => count($e['streams']),
                'placed' => $e['placed'],
                'note' => $e['note'],
                'skip' => !$e['subjectIds'],
            ];
        }
        $shared = [];
        foreach ($cells as $k => $keys) {
            $cells[$k] = array_values(array_unique($keys, SORT_REGULAR));
            if (count($cells[$k]) > 1) {
                [$c, $sr, $su] = explode('|', $k);
                $shared[] = [
                    'class' => $c, 'stream' => $sr, 'subject' => $this->subjectNames[(int) $su],
                    'teachers' => array_map(fn ($key) => $keyNames[$key] ?? '?', $cells[$k]),
                ];
            }
        }
        usort($shared, fn ($a, $b) => [$a['class'], $a['stream'], $a['subject']] <=> [$b['class'], $b['stream'], $b['subject']]);
        return ['teachers' => $teachers, 'cells' => $cells, 'shared' => $shared, 'streams' => $streamsSeen, 'unknownStreams' => $unknown];
    }

    /** [subject ids, note for the preview] */
    private function subjectsFor(string $subjects, string $department, ?int $teacherId): array
    {
        $tusome = $this->tusomeSubjects[$teacherId ?? 0] ?? [];
        $main = [];
        $either = [];
        $blocks = [];
        $blockSets = [];
        // "BIOLOGY (24), AGR/F.ART/LUG/TD (4), CRE/IRE (26)"
        foreach (preg_split('/,(?![^()]*\))/', $subjects) as $part) {
            $label = trim((string) preg_replace('/\(\s*\d+\s*\)\s*$/', '', $part));
            if ($label === '') {
                continue;
            }
            $whole = self::norm($label);
            if (isset(self::WORDS[$whole]) && count(self::WORDS[$whole]) === 1) {
                $main[] = $this->id(self::WORDS[$whole][0]);
            } elseif ($whole === 'CREIRE') {
                $either[] = array_filter([$this->id('CRE'), $this->id('IRE')]);
            } elseif (str_contains($label, '/')) {
                $set = [];
                foreach (explode('/', $label) as $w) {
                    foreach (self::WORDS[self::norm($w)] ?? [] as $code) {
                        $set[] = $this->id($code);
                    }
                }
                $set = array_values(array_unique(array_filter($set)));
                $blocks = array_merge($blocks, $set);
                $set && $blockSets[] = $set;
            }
        }
        $main = array_values(array_unique(array_filter($main)));
        $blocks = array_values(array_unique(array_filter($blocks)));
        $note = null;

        // CRE/IRE: whichever of the two Tusome has for this teacher, else the department, else CRE
        foreach ($either as $pair) {
            $known = array_values(array_intersect($pair, $tusome));
            if ($known) {
                $main = array_merge($main, $known);
            } elseif (stripos($department, 'islam') !== false && $this->id('IRE')) {
                $main[] = $this->id('IRE');
            } elseif ($pair) {
                $main[] = reset($pair);
                $note = 'CRE or IRE not known - put under CRE; change on the Teaching list if needed';
            }
        }
        $main = array_values(array_unique($main));
        if ($main) {
            return [$main, $note];
        }
        // only elective blocks: the subjects Tusome has for them among the block's subjects, or the
        // one subject that appears in every block they teach (AGR/F.ART/LUG/TD + MAT/AGR = Agriculture)
        $known = array_values(array_intersect($blocks, $tusome));
        if ($known) {
            return [$known, 'Taken from Tusome (elective block in the file)'];
        }
        $common = $blockSets ? array_values(array_intersect(...$blockSets)) : [];
        if (count($common) === 1) {
            return [$common, 'The subject in all their elective blocks'];
        }
        // or the department's own subject, when it names just one of the block's subjects
        $dept = [];
        foreach (preg_split('/[\/(),&]+/', strtoupper($department)) as $w) {
            foreach (self::WORDS[self::norm($w)] ?? [] as $code) {
                $dept[] = $this->id($code);
            }
        }
        $dept = array_values(array_unique(array_filter(array_intersect($dept, $blocks ?: $dept))));
        if (count($dept) === 1) {
            return [$dept, 'Taken from the department (elective block in the file)'];
        }
        return [[], $blocks ? 'Only elective blocks (' . implode(', ', array_map(fn ($s) => $this->subjectNames[$s], $blocks)) . ') - set on the Teaching list' : 'No subject recognised'];
    }

    private function id(string $code): ?int
    {
        return $this->subjects[self::norm($code)] ?? null;
    }

    /** The Tusome teacher with this name: same words, any order, small spelling slips allowed */
    private function match(string $name): ?array
    {
        $tokens = self::tokens($name);
        if (!$tokens) {
            return null;
        }
        $sorted = $tokens;
        sort($sorted);
        $best = null;
        $bestScore = 0;
        $tie = false;
        foreach ($this->teachers as $id => [$tName, $tTokens]) {
            if ($tTokens === $tokens) {
                return ['id' => $id, 'name' => $tName, 'how' => 'exact'];
            }
            $s2 = $tTokens;
            sort($s2);
            if ($s2 === $sorted) {
                return ['id' => $id, 'name' => $tName, 'how' => 'reordered'];
            }
            // close: every word of the shorter name matches a word of the other (a slip allowed)
            $short = count($tokens) <= count($tTokens) ? $tokens : $tTokens;
            $long = count($tokens) <= count($tTokens) ? $tTokens : $tokens;
            if (count($short) < 2) {
                continue;
            }
            $score = 0;
            foreach ($short as $w) {
                foreach ($long as $v) {
                    $limit = strlen($w) >= 7 ? 2 : (strlen($w) >= 4 ? 1 : 0);
                    if ($w === $v || levenshtein($w, $v) <= $limit) {
                        $score += $w === $v ? 2 : 1;
                        continue 2;
                    }
                }
                continue 2; // a word with no partner: not this teacher
            }
            if ($score > $bestScore) {
                [$best, $bestScore, $tie] = [['id' => $id, 'name' => $tName, 'how' => 'close'], $score, false];
            } elseif ($score === $bestScore) {
                $tie = true;
            }
        }
        return $best && !$tie ? $best : null;
    }

    private static function tokens(string $name): array
    {
        return array_values(array_filter(preg_split('/[^a-z]+/', strtolower($name)), fn ($w) => $w !== '' && !in_array($w, ['mr', 'mrs', 'ms', 'miss', 'dr', 'tr', 'sir', 'madam'], true)));
    }

    /** Subjects taught only at one level: General Paper and Subsidiary Maths at A-Level, English at O-Level */
    private function forLevel(array $subjectIds, string $streamKey): array
    {
        $aLevel = in_array(substr($streamKey, 0, 3), ['S.5', 'S.6'], true);
        $aOnly = array_filter([$this->id('GP'), $this->id('SUBMTC')]);
        $oOnly = array_filter([$this->id('ENG')]);
        return array_values(array_filter($subjectIds, fn ($s) => $aLevel ? !in_array($s, $oOnly, true) : !in_array($s, $aOnly, true)));
    }

    /** "S1A" => "S.1|A"; a code SMIS writes longer ("S6B1" for stream SB1) is found by its ending */
    private function findStream(string $code): ?string
    {
        $parsed = self::stream($code);
        if (!$parsed) {
            return null;
        }
        if (isset($this->streams[$parsed])) {
            return $parsed;
        }
        [$class, $stream] = explode('|', $parsed);
        $found = array_values(array_filter(array_keys($this->streams), fn ($k) => str_starts_with($k, "$class|") && str_ends_with($k, $stream)));
        return count($found) === 1 ? $found[0] : null;
    }

    private static function norm(string $s): string
    {
        return strtoupper((string) preg_replace('/[^A-Za-z]/', '', $s));
    }

    /** "S1A" / "S.1 A" / "S5B1" => "S.1|A" */
    public static function stream(string $code): ?string
    {
        if (!preg_match('/^\s*S\.?\s*([1-6])\s*[-\s]?\s*([A-Z0-9]+)\s*$/i', $code, $m)) {
            return null;
        }
        return 'S.' . $m[1] . '|' . strtoupper($m[2]);
    }
}
