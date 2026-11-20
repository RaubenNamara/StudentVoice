-- Shorter teacher ratings.
-- sample_size: in a teacher poll, how many of the agree/disagree statements each student answers
-- per teacher (a different random mix per student and subject, spread across the sections);
-- NULL = all of them. Averages stay fair because every statement is still answered by many students.

ALTER TABLE polls ADD COLUMN sample_size TINYINT UNSIGNED NULL DEFAULT NULL AFTER about;

-- The starter teacher poll: 8 statements per teacher and two optional comment boxes
UPDATE polls SET sample_size = 8 WHERE title = 'Teacher feedback' AND about = 'teachers'
  AND NOT EXISTS (SELECT 1 FROM ballots b WHERE b.poll_id = polls.id);

DELETE q FROM questions q JOIN polls p ON p.id = q.poll_id
  WHERE p.title = 'Teacher feedback' AND p.about = 'teachers' AND q.position = 23
  AND NOT EXISTS (SELECT 1 FROM ballots b WHERE b.poll_id = p.id);

UPDATE questions q JOIN polls p ON p.id = q.poll_id
  SET q.prompt = 'What does this teacher do well?', q.section = 'Anything to add?', q.required = 0
  WHERE p.title = 'Teacher feedback' AND p.about = 'teachers' AND q.position = 21;

UPDATE questions q JOIN polls p ON p.id = q.poll_id
  SET q.prompt = 'What could this teacher do better?', q.section = 'Anything to add?', q.required = 0
  WHERE p.title = 'Teacher feedback' AND p.about = 'teachers' AND q.position = 22;
