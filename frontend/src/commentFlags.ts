/**
 * Sensitive student comments: harassment of any kind (sexual, physical, verbal) and serious academic
 * concerns (missing lessons, unfair marks, money for marks). Found by words and phrases, so it can
 * miss a comment written in other words (or in Luganda) and can now and then flag an innocent one:
 * admins still read the comments. Flagged comments come first in the meeting report.
 */
export interface Flag {
  key: 'sexual' | 'physical' | 'verbal' | 'academic'
  label: string
  /** Lower = more serious (listed first) */
  rank: number
  test: RegExp
}

export const FLAGS: Flag[] = [
  {
    key: 'sexual',
    label: 'Sexual harassment',
    rank: 0,
    test: /\b(sex|sexual(ly)?|harass\w*|touch(es|ed|ing)? (us|me|girls|boys|students|her|him)|touches|fondl\w*|kiss\w*|seduc\w*|love (letters?|affairs?|messages?)|girlfriends?|boyfriends?|relationships? with (a |the )?(student|girl|boy)s?|propos(es|ed|ing|al) to|indecent\w*|inappropriate(ly)?|private parts?|breasts?|buttocks|thighs?|(to|at|in) his (house|room|home)|(to|at|in) her (house|room|home)|at night|night calls?|(asks?|asked|asking) for (my|our|her|his|girls'?) (phone|number|contacts?|whatsapp)|whatsapps? (girls|boys|students|us)|favou?rs? in (exchange|return)|rap(e|ed|ing)|defil\w*|lust\w*)\b/i,
  },
  {
    key: 'physical',
    label: 'Physical harassment',
    rank: 1,
    test: /\b(beat(s|ing|en)?|cane[sd]?|caning|slap\w*|punch\w*|kick(s|ed|ing)?|hits?|hitting|pinch\w*|corporal|assault\w*|injur\w*|wound\w*|bleed\w*|whip\w*|throws? (things|chalk|books|dusters?) at|smack\w*|strangl\w*|violen\w*|twists? (our|my) (ears?|arms?)|pull(s|ed)? (our|my) (ears?|hair))\b/i,
  },
  {
    key: 'verbal',
    label: 'Verbal abuse',
    rank: 2,
    test: /\b(insult\w*|abus\w*|shout(s|ed|ing)? at|yell\w*|curs(e|es|ed|ing)|rude(ly|ness)?|harsh(ly|ness)?|mock(s|ed|ing)?|laughs? at (us|me|students)|humiliat\w*|embarrass\w*|threat\w*|bull(y|ies|ied|ying)|calls? (us|students|me|them|girls|boys) (names|stupid|fools?|idiots?|dull|animals?|pigs?|dogs?|monkeys?)|stupid|idiots?|fools|useless|discriminat\w*|tribal\w*|segregat\w*|favou?ritism|despis\w*|belittl\w*|vulgar|abusive|demean\w*|shames? (us|me|students))\b/i,
  },
  {
    key: 'academic',
    label: 'Academic concern',
    rank: 3,
    test: /\b(absent\w*|absenteeism|miss(es|ed)? (lessons?|classes|class|periods?)|dodg\w*|never (comes?|teach\w*|attends?|marks?)|rarely (comes?|teach\w*|attends?)|(does|did)(n'?t| not) (come|teach|mark|finish|attend)|not teaching|incomplete syllabus|syllabus (is )?(not|behind|incomplete)|behind (the )?syllabus|only dictat\w*|dictates? notes|unfair(ly)? (mark\w*|grad\w*)|marks? (unfairly|wrongly)|bribe\w*|money for (marks|grades|passing)|pay(s|ing)? (for|to get) (marks|grades)|sells? (marks|grades|exams?)|leak(s|ed|ing)? (exams?|papers?|tests?)|exam leaks?|cheat\w*)\b/i,
  },
]

/** The most serious flag a comment matches, if any */
export function flagOf(text: string): Flag | null {
  for (const f of FLAGS) if (f.test.test(text)) return f
  return null
}
