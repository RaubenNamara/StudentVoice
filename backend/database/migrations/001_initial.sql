-- Student Voice: students vote with a voting number, once per poll, and nobody can tell who
-- answered what.
--
-- How the "once, and anonymous" rule is kept:
--   voters   - WHO has voted in a poll (poll + student). Its primary key is what stops a second
--              vote. It holds no answers.
--   ballots  - WHAT was answered, with only the class and stream (for class-by-class results)
--              and the day. No student, no time of day, and a random id instead of a counter, so
--              a ballot cannot be lined up with the order or time students voted in.
--   answers  - one row per ballot and question, keyed by the ballot (no counter either).

CREATE TABLE admins (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(150) NOT NULL,
  email VARCHAR(190) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('super_admin','admin') NOT NULL DEFAULT 'admin',
  status ENUM('active','disabled') NOT NULL DEFAULT 'active',
  must_change_password TINYINT(1) NOT NULL DEFAULT 1,
  password_changed_at TIMESTAMP NULL DEFAULT NULL,
  failed_logins INT UNSIGNED NOT NULL DEFAULT 0,
  locked_until DATETIME NULL DEFAULT NULL,
  last_login_at DATETIME NULL DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_admin_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The roster, from SMIS (or added by hand). status 'left': no longer in SMIS, cannot vote.
CREATE TABLE students (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  reg_no VARCHAR(40) NOT NULL,
  name VARCHAR(190) NOT NULL DEFAULT '',
  class VARCHAR(10) NOT NULL,
  stream VARCHAR(20) NOT NULL DEFAULT '',
  voter_code CHAR(9) NOT NULL,
  status ENUM('active','left') NOT NULL DEFAULT 'active',
  source ENUM('smis','manual') NOT NULL DEFAULT 'smis',
  left_at DATETIME NULL DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_student_reg (reg_no),
  UNIQUE KEY uniq_student_code (voter_code),
  KEY idx_student_class (status, class, stream)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE sync_runs (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  started_by INT UNSIGNED NULL DEFAULT NULL,
  started_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  finished_at TIMESTAMP NULL DEFAULT NULL,
  status ENUM('running','success','failed') NOT NULL DEFAULT 'running',
  fetched INT UNSIGNED NOT NULL DEFAULT 0,
  created INT UNSIGNED NOT NULL DEFAULT 0,
  moved INT UNSIGNED NOT NULL DEFAULT 0,
  renamed INT UNSIGNED NOT NULL DEFAULT 0,
  left_school INT UNSIGNED NOT NULL DEFAULT 0,
  returned INT UNSIGNED NOT NULL DEFAULT 0,
  message VARCHAR(1000) NULL DEFAULT NULL,
  details LONGTEXT NULL DEFAULT NULL,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- audience: JSON list of classes ("S.1".."S.6") that may vote; NULL = every class.
-- closes_at: optional; after it the poll takes no more votes even if still 'open'.
CREATE TABLE polls (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  title VARCHAR(190) NOT NULL,
  intro TEXT NULL,
  status ENUM('draft','open','closed') NOT NULL DEFAULT 'draft',
  audience JSON NULL,
  closes_at DATETIME NULL DEFAULT NULL,
  opened_at DATETIME NULL DEFAULT NULL,
  closed_at DATETIME NULL DEFAULT NULL,
  created_by INT UNSIGNED NULL DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_poll_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- type: scale (1-5, Strongly disagree..Strongly agree), choice (one of options), multi (any of
-- options), yesno, text (written answer). section: optional heading shown above the question.
CREATE TABLE questions (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  poll_id INT UNSIGNED NOT NULL,
  position INT UNSIGNED NOT NULL DEFAULT 0,
  section VARCHAR(150) NULL DEFAULT NULL,
  prompt TEXT NOT NULL,
  type ENUM('scale','choice','multi','yesno','text') NOT NULL DEFAULT 'scale',
  options JSON NULL,
  required TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  KEY idx_question_poll (poll_id, position),
  CONSTRAINT fk_question_poll FOREIGN KEY (poll_id) REFERENCES polls (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE voters (
  poll_id INT UNSIGNED NOT NULL,
  student_id INT UNSIGNED NOT NULL,
  class VARCHAR(10) NOT NULL,
  voted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (poll_id, student_id),
  KEY idx_voter_student (student_id),
  CONSTRAINT fk_voter_poll FOREIGN KEY (poll_id) REFERENCES polls (id) ON DELETE CASCADE,
  CONSTRAINT fk_voter_student FOREIGN KEY (student_id) REFERENCES students (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE ballots (
  id CHAR(32) NOT NULL,
  poll_id INT UNSIGNED NOT NULL,
  class VARCHAR(10) NOT NULL,
  stream VARCHAR(20) NOT NULL DEFAULT '',
  cast_on DATE NOT NULL,
  PRIMARY KEY (id),
  KEY idx_ballot_poll (poll_id, class, stream),
  CONSTRAINT fk_ballot_poll FOREIGN KEY (poll_id) REFERENCES polls (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- value_num: scale 1-5, yesno 1/0, choice = the option's number (0-based)
-- value_text: a written answer, or for multi a JSON list of option numbers
CREATE TABLE answers (
  ballot_id CHAR(32) NOT NULL,
  question_id INT UNSIGNED NOT NULL,
  value_num INT NULL DEFAULT NULL,
  value_text TEXT NULL,
  PRIMARY KEY (ballot_id, question_id),
  KEY idx_answer_question (question_id),
  CONSTRAINT fk_answer_ballot FOREIGN KEY (ballot_id) REFERENCES ballots (id) ON DELETE CASCADE,
  CONSTRAINT fk_answer_question FOREIGN KEY (question_id) REFERENCES questions (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE login_throttle (
  ip VARCHAR(45) NOT NULL,
  kind VARCHAR(20) NOT NULL,
  failures INT UNSIGNED NOT NULL DEFAULT 0,
  window_start DATETIME NOT NULL,
  PRIMARY KEY (ip, kind)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
