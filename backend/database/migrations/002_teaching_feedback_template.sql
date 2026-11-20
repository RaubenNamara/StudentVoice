-- A ready-made draft poll from the paper "Students' Voice - 360 Teaching & Learning Feedback"
-- form (the first version of this system). Edit, add or remove questions, then open it.

INSERT INTO polls (title, intro, status) VALUES (
  'Teaching & learning feedback',
  'Tell us honestly how teaching and learning is going this term. Do not write your name anywhere. Your answers cannot be traced back to you.',
  'draft'
);

SET @poll = LAST_INSERT_ID();

INSERT INTO questions (poll_id, position, section, prompt, type, options, required) VALUES
(@poll, 1,  'Professionalism & preparation', 'Teachers start and end lessons on time.', 'scale', NULL, 1),
(@poll, 2,  'Professionalism & preparation', 'Teachers are well prepared for lessons.', 'scale', NULL, 1),
(@poll, 3,  'Professionalism & preparation', 'Lessons are well organised and easy to follow.', 'scale', NULL, 1),
(@poll, 4,  'Professionalism & preparation', 'Teachers are progressing well with syllabus coverage.', 'scale', NULL, 1),
(@poll, 5,  'Professionalism & preparation', 'Teachers mark work and return it with helpful feedback.', 'scale', NULL, 1),
(@poll, 6,  'Teaching effectiveness', 'Teachers explain concepts clearly.', 'scale', NULL, 1),
(@poll, 7,  'Teaching effectiveness', 'Examples and illustrations improve my understanding.', 'scale', NULL, 1),
(@poll, 8,  'Teaching effectiveness', 'Teachers use varied teaching methods (discussion, demonstration, group work, etc.).', 'scale', NULL, 1),
(@poll, 9,  'Teaching effectiveness', 'I am gaining confidence in my subjects.', 'scale', NULL, 1),
(@poll, 10, 'Teaching effectiveness', 'Teachers prepare us well for internal and national examinations (e.g. UNEB).', 'scale', NULL, 1),
(@poll, 11, 'Classroom environment & discipline', 'Teachers treat students with respect and fairness.', 'scale', NULL, 1),
(@poll, 12, 'Classroom environment & discipline', 'Teachers listen to students'' questions and opinions.', 'scale', NULL, 1),
(@poll, 13, 'Classroom environment & discipline', 'The classroom environment is safe and supportive.', 'scale', NULL, 1),
(@poll, 14, 'Classroom environment & discipline', 'Discipline is managed fairly and consistently.', 'scale', NULL, 1),
(@poll, 15, 'Classroom environment & discipline', 'Teachers encourage participation from all learners.', 'scale', NULL, 1),
(@poll, 16, 'Talent nurturing & skill development', 'Teachers encourage critical thinking and problem-solving.', 'scale', NULL, 1),
(@poll, 17, 'Talent nurturing & skill development', 'Teachers support learners with different abilities.', 'scale', NULL, 1),
(@poll, 18, 'Talent nurturing & skill development', 'Teachers motivate us to aim for excellence.', 'scale', NULL, 1),
(@poll, 19, 'Talent nurturing & skill development', 'Teachers connect classroom learning to real-life situations.', 'scale', NULL, 1),
(@poll, 20, 'Talent nurturing & skill development', 'I feel inspired to improve in my subjects.', 'scale', NULL, 1),
(@poll, 21, 'In your own words', 'What do teachers do particularly well?', 'text', NULL, 0),
(@poll, 22, 'In your own words', 'What could teachers improve?', 'text', NULL, 0),
(@poll, 23, 'In your own words', 'What suggestions do you have to improve learning at school?', 'text', NULL, 0);
