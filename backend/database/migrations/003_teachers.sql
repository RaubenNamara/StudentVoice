-- Teacher feedback: students rate each of their own teachers, once each, anonymously.
--
-- Who teaches whom comes from Tusome (TusomeSync):
--   teachers, subjects, teacher_subjects  - Tusome's teachers and the subjects (departments) they teach
--   student_subjects                      - which subjects each student takes
--   student_teachers                      - a student's own teacher for a subject, where Tusome has it
-- and from the school's Teaching list (admins):
--   teaching                              - the teacher of each subject in each class and stream
--
-- The "once per teacher" gate for a teacher poll is vote_gates: a keyed fingerprint (HMAC with
-- APP_KEY) of poll + student + teacher + subject, with no student id beside it. So the database
-- shows how many students rated a teacher, never which ones. voters still records that a student
-- took part in the poll at all (for turnout), without any teacher.

ALTER TABLE polls ADD COLUMN about ENUM('school','teachers') NOT NULL DEFAULT 'school' AFTER intro;

ALTER TABLE voters ADD COLUMN stream VARCHAR(20) NOT NULL DEFAULT '' AFTER class;

ALTER TABLE ballots
  ADD COLUMN teacher_id INT UNSIGNED NULL DEFAULT NULL AFTER poll_id,
  ADD COLUMN subject_id INT UNSIGNED NULL DEFAULT NULL AFTER teacher_id,
  ADD KEY idx_ballot_teacher (poll_id, teacher_id, subject_id);

ALTER TABLE sync_runs ADD COLUMN kind ENUM('smis','tusome') NOT NULL DEFAULT 'smis' AFTER id;

CREATE TABLE teachers (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  tusome_id INT UNSIGNED NOT NULL,
  name VARCHAR(190) NOT NULL,
  employee_number VARCHAR(50) NULL DEFAULT NULL,
  status ENUM('active','gone') NOT NULL DEFAULT 'active',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_teacher_tusome (tusome_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE subjects (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  tusome_id INT UNSIGNED NOT NULL,
  name VARCHAR(150) NOT NULL,
  status ENUM('active','gone') NOT NULL DEFAULT 'active',
  PRIMARY KEY (id),
  UNIQUE KEY uniq_subject_tusome (tusome_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE teacher_subjects (
  teacher_id INT UNSIGNED NOT NULL,
  subject_id INT UNSIGNED NOT NULL,
  PRIMARY KEY (teacher_id, subject_id),
  KEY idx_ts_subject (subject_id),
  CONSTRAINT fk_ts_teacher FOREIGN KEY (teacher_id) REFERENCES teachers (id) ON DELETE CASCADE,
  CONSTRAINT fk_ts_subject FOREIGN KEY (subject_id) REFERENCES subjects (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE student_subjects (
  student_id INT UNSIGNED NOT NULL,
  subject_id INT UNSIGNED NOT NULL,
  PRIMARY KEY (student_id, subject_id),
  KEY idx_ss_subject (subject_id),
  CONSTRAINT fk_ss_student FOREIGN KEY (student_id) REFERENCES students (id) ON DELETE CASCADE,
  CONSTRAINT fk_ss_subject FOREIGN KEY (subject_id) REFERENCES subjects (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE student_teachers (
  student_id INT UNSIGNED NOT NULL,
  subject_id INT UNSIGNED NOT NULL,
  teacher_id INT UNSIGNED NOT NULL,
  PRIMARY KEY (student_id, subject_id, teacher_id),
  CONSTRAINT fk_st_student FOREIGN KEY (student_id) REFERENCES students (id) ON DELETE CASCADE,
  CONSTRAINT fk_st_subject FOREIGN KEY (subject_id) REFERENCES subjects (id) ON DELETE CASCADE,
  CONSTRAINT fk_st_teacher FOREIGN KEY (teacher_id) REFERENCES teachers (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- source 'auto': filled in by the sync because the subject has only one teacher (the sync may
-- change or clear it); 'admin': set on the Teaching list (the sync never touches it).
CREATE TABLE teaching (
  class VARCHAR(10) NOT NULL,
  stream VARCHAR(20) NOT NULL,
  subject_id INT UNSIGNED NOT NULL,
  teacher_id INT UNSIGNED NOT NULL,
  source ENUM('auto','admin') NOT NULL DEFAULT 'admin',
  PRIMARY KEY (class, stream, subject_id, teacher_id),
  KEY idx_teaching_teacher (teacher_id),
  CONSTRAINT fk_teaching_subject FOREIGN KEY (subject_id) REFERENCES subjects (id) ON DELETE CASCADE,
  CONSTRAINT fk_teaching_teacher FOREIGN KEY (teacher_id) REFERENCES teachers (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- skipped = 1: the student said this is not their teacher (counted per teacher, to fix the list)
CREATE TABLE vote_gates (
  poll_id INT UNSIGNED NOT NULL,
  gate CHAR(64) NOT NULL,
  teacher_id INT UNSIGNED NOT NULL,
  subject_id INT UNSIGNED NOT NULL,
  skipped TINYINT(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (poll_id, gate),
  KEY idx_gate_teacher (poll_id, teacher_id, subject_id),
  CONSTRAINT fk_gate_poll FOREIGN KEY (poll_id) REFERENCES polls (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
