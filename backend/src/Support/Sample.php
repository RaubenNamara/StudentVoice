<?php

declare(strict_types=1);

namespace StudentVoice\Support;

/**
 * Which statements a student answers about a teacher when a poll asks only some of them
 * (polls.sample_size). The mix is spread across the sections (one from each in turn) and is fixed
 * for a student and subject (it comes from their vote fingerprint), so reopening the page shows
 * the same statements and the server can check the answer against the same list.
 * Questions that are not 1-5 statements (comments, choices) are always included.
 */
final class Sample
{
    /** @return int[] the question ids to ask, in the poll's order */
    public static function questionIds(array $questions, ?int $size, string $seed): array
    {
        $scale = array_values(array_filter($questions, fn ($q) => $q['type'] === 'scale'));
        if (!$size || $size >= count($scale)) {
            return array_map(fn ($q) => (int) $q['id'], $questions);
        }
        $rank = fn (int $id) => hash('sha256', "$seed|$id");

        $bySection = [];
        foreach ($scale as $q) {
            $bySection[$q['section'] ?? ''][] = (int) $q['id'];
        }
        foreach ($bySection as &$ids) {
            usort($ids, fn ($a, $b) => strcmp($rank($a), $rank($b)));
        }
        unset($ids);
        $sections = array_keys($bySection);
        usort($sections, fn ($a, $b) => strcmp(hash('sha256', "$seed|s|$a"), hash('sha256', "$seed|s|$b")));

        $picked = [];
        while (count($picked) < $size) {
            foreach ($sections as $s) {
                if ($bySection[$s] && count($picked) < $size) {
                    $picked[array_shift($bySection[$s])] = true;
                }
            }
        }
        return array_values(array_map(
            fn ($q) => (int) $q['id'],
            array_filter($questions, fn ($q) => $q['type'] !== 'scale' || isset($picked[(int) $q['id']]))
        ));
    }
}
