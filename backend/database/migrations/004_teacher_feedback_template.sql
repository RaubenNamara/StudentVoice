-- A ready-made draft teacher poll, worded as on the paper "Students' Voice - 360 Teaching &
-- Learning Feedback" form: each student answers it once for each of their teachers.

INSERT INTO polls (title, intro, about, status) VALUES (
  'Teacher feedback',
  'Answer these questions once for each of your teachers. Do not write your name anywhere. Your answers cannot be traced back to you, and teachers never see who said what.',
  'teachers',
  'draft'
);

SET @poll = LAST_INSERT_ID();

INSERT INTO questions (poll_id, position, section, prompt, type, options, required) VALUES
(@poll, 1,  'Professionalism & preparation', 'The teacher starts and ends lessons on time.', 'scale', NULL, 1),
(@poll, 2,  'Professionalism & preparation', 'The teacher is well prepared for lessons.', 'scale', NULL, 1),
(@poll, 3,  'Professionalism & preparation', 'Lessons are well organised and easy to follow.', 'scale', NULL, 1),
(@poll, 4,  'Professionalism & preparation', 'The teacher is progressing well with syllabus coverage.', 'scale', NULL, 1),
(@poll, 5,  'Professionalism & preparation', 'The teacher marks work and returns it with helpful feedback.', 'scale', NULL, 1),
(@poll, 6,  'Teaching effectiveness', 'The teacher explains concepts clearly.', 'scale', NULL, 1),
(@poll, 7,  'Teaching effectiveness', 'Examples and illustrations improve my understanding.', 'scale', NULL, 1),
(@poll, 8,  'Teaching effectiveness', 'The teacher uses varied teaching methods (discussion, demonstration, group work, etc.).', 'scale', NULL, 1),
(@poll, 9,  'Teaching effectiveness', 'I am gaining confidence in this subject.', 'scale', NULL, 1),
(@poll, 10, 'Teaching effectiveness', 'The teacher prepares us well for internal and national examinations (e.g. UNEB).', 'scale', NULL, 1),
(@poll, 11, 'Classroom environment & discipline', 'The teacher treats students with respect and fairness.', 'scale', NULL, 1),
(@poll, 12, 'Classroom environment & discipline', 'The teacher listens to students'' questions and opinions.', 'scale', NULL, 1),
(@poll, 13, 'Classroom environment & discipline', 'The classroom environment is safe and supportive.', 'scale', NULL, 1),
(@poll, 14, 'Classroom environment & discipline', 'Discipline is managed fairly and consistently.', 'scale', NULL, 1),
(@poll, 15, 'Classroom environment & discipline', 'The teacher encourages participation from all learners.', 'scale', NULL, 1),
(@poll, 16, 'Talent nurturing & skill development', 'The teacher encourages critical thinking and problem-solving.', 'scale', NULL, 1),
(@poll, 17, 'Talent nurturing & skill development', 'The teacher supports learners with different abilities.', 'scale', NULL, 1),
(@poll, 18, 'Talent nurturing & skill development', 'The teacher motivates us to aim for excellence.', 'scale', NULL, 1),
(@poll, 19, 'Talent nurturing & skill development', 'The teacher connects classroom learning to real-life situations.', 'scale', NULL, 1),
(@poll, 20, 'Talent nurturing & skill development', 'I feel inspired to improve in this subject.', 'scale', NULL, 1),
(@poll, 21, 'In your own words', 'What does this teacher do particularly well?', 'text', NULL, 0),
(@poll, 22, 'In your own words', 'What areas could this teacher improve?', 'text', NULL, 0),
(@poll, 23, 'In your own words', 'What suggestions do you have to improve learning in this subject?', 'text', NULL, 0);
