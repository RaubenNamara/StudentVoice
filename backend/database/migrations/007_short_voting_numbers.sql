-- Voting numbers are now 6 characters shown as XXX-XXX (older ones were XXXX-XXXX). The column
-- holds either. Existing numbers are not changed here: a super admin gives everyone new numbers
-- from Students > "New numbers for everyone" (or bin/new-voting-numbers.php --yes).

ALTER TABLE students MODIFY voter_code VARCHAR(12) NOT NULL;
