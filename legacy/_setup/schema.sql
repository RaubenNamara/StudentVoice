-- ============================================================
-- ST. MARK'S COLLEGE NAMAGOMA -- STUDENTS' VOICE FEEDBACK SYSTEM
-- ============================================================
-- Digital version of "STUDENTS' VOICE - 360% TEACHING & LEARNING
-- FEEDBACK FORM". Students log in to prove they're enrolled, but the
-- login is only ever used as a submission gate (see student_submissions
-- below) -- the responses table itself has no student_id / name column
-- at all, so nobody, including a database admin, can trace a response
-- back to the student who filed it. That anonymity guarantee is the
-- whole point of the paper form ("Do NOT write your name" /
-- "Individual comments will remain confidential").
-- ============================================================

CREATE DATABASE IF NOT EXISTS stmarks_voice CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE stmarks_voice;

-- Neutral administrators who review reports (guideline: "a neutral
-- administrator, not the subject teacher, must supervise the process").
CREATE TABLE IF NOT EXISTS admins (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    email VARCHAR(190) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    status ENUM('active', 'disabled') NOT NULL DEFAULT 'active',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- name is UNIQUE so CSV/paste re-imports of the same staff list are
-- naturally idempotent (a repeat row just fails the insert and gets
-- counted as "skipped", not duplicated).
CREATE TABLE IF NOT EXISTS teachers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL UNIQUE,
    status ENUM('active', 'disabled') NOT NULL DEFAULT 'active',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS subjects (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL UNIQUE,
    status ENUM('active', 'disabled') NOT NULL DEFAULT 'active',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- One row per term the form has ever been administered in. Only one
-- row has is_active=1 at a time -- that's the term shown/recorded on
-- the student form right now. Keeping past terms around (rather than
-- overwriting a single "current term" row) is what makes the
-- guideline-8 "track improvement over consecutive terms" report possible.
CREATE TABLE IF NOT EXISTS terms (
    id INT AUTO_INCREMENT PRIMARY KEY,
    term TINYINT NOT NULL,
    year SMALLINT NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_term_year (term, year)
) ENGINE=InnoDB;

-- Roster gate only -- proves the person filling the form is an actual
-- enrolled student number on file. No password: the school pre-loads the
-- roster (import from a spreadsheet or Scholar export) before sharing the
-- link, and a student number that's on the list can log straight in.
-- Never referenced by the responses table.
CREATE TABLE IF NOT EXISTS students (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_no VARCHAR(40) NOT NULL UNIQUE,
    class VARCHAR(20) NOT NULL,
    stream VARCHAR(20) NOT NULL,
    status ENUM('active', 'disabled') NOT NULL DEFAULT 'active',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- A teacher can teach the same subject to several class/stream
-- combinations (and different subjects to different ones) -- one row
-- here per (teacher, subject, class, stream) they actually teach.
-- class/stream are free text matched against students.class/stream,
-- not a separate FK table -- the admin UI sources the checkbox list of
-- available class/stream pairs straight from the imported student
-- roster, so the two naturally stay in sync without a third table to
-- keep in step.
-- is_open is the "admin chooses who gets voiced" control: only open
-- assignments show up on a student's feedback checklist and can be
-- submitted against. Pausing one (e.g. a teacher on leave, or the
-- school wants to skip a subject this round) hides it without losing
-- the assignment or any past responses tied to it.
CREATE TABLE IF NOT EXISTS teacher_assignments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    teacher_id INT NOT NULL,
    subject_id INT NOT NULL,
    class VARCHAR(20) NOT NULL,
    stream VARCHAR(20) NOT NULL,
    is_open TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_assignment (teacher_id, subject_id, class, stream),
    FOREIGN KEY (teacher_id) REFERENCES teachers(id) ON DELETE RESTRICT,
    FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE RESTRICT,
    INDEX idx_class_stream (class, stream, is_open)
) ENGINE=InnoDB;

-- Submission gate ONLY: stops the same student voting twice for the
-- same teacher+subject+term. Deliberately holds no answer data, so
-- knowing a row exists here reveals nothing about what was answered --
-- it cannot be joined to `responses` (which has no student_id) to
-- de-anonymize anyone.
CREATE TABLE IF NOT EXISTS student_submissions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    teacher_id INT NOT NULL,
    subject_id INT NOT NULL,
    term_id INT NOT NULL,
    submitted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_vote (student_id, teacher_id, subject_id, term_id),
    FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
    FOREIGN KEY (teacher_id) REFERENCES teachers(id) ON DELETE CASCADE,
    FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE CASCADE,
    FOREIGN KEY (term_id) REFERENCES terms(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- The actual anonymous feedback. q1..q20 mirror the paper form's
-- Sections C-F (5 statements each, Likert 1-5):
--   q1-q5   Section C: Professionalism & Preparation
--   q6-q10  Section D: Teaching Effectiveness
--   q11-q15 Section E: Classroom Environment & Discipline
--   q16-q20 Section F: Talent Nurturing & Skill Development
-- class/stream are copied from the student's own record at submit time
-- (not looked up later) so a report is reproducible even if the
-- student's class/stream changes in a later term.
CREATE TABLE IF NOT EXISTS responses (
    id INT AUTO_INCREMENT PRIMARY KEY,
    teacher_id INT NOT NULL,
    subject_id INT NOT NULL,
    term_id INT NOT NULL,
    class VARCHAR(20) NOT NULL,
    stream VARCHAR(20) NOT NULL,
    q1 TINYINT NOT NULL, q2 TINYINT NOT NULL, q3 TINYINT NOT NULL, q4 TINYINT NOT NULL, q5 TINYINT NOT NULL,
    q6 TINYINT NOT NULL, q7 TINYINT NOT NULL, q8 TINYINT NOT NULL, q9 TINYINT NOT NULL, q10 TINYINT NOT NULL,
    q11 TINYINT NOT NULL, q12 TINYINT NOT NULL, q13 TINYINT NOT NULL, q14 TINYINT NOT NULL, q15 TINYINT NOT NULL,
    q16 TINYINT NOT NULL, q17 TINYINT NOT NULL, q18 TINYINT NOT NULL, q19 TINYINT NOT NULL, q20 TINYINT NOT NULL,
    comment_good TEXT NULL,
    comment_improve TEXT NULL,
    comment_suggestions TEXT NULL,
    submitted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    -- RESTRICT (not CASCADE, unlike student_submissions above): a teacher,
    -- subject, or term row must never be deletable out from under
    -- collected feedback -- that would silently destroy real data. The
    -- admin UI only ever disables teachers/subjects, never deletes them.
    FOREIGN KEY (teacher_id) REFERENCES teachers(id) ON DELETE RESTRICT,
    FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE RESTRICT,
    FOREIGN KEY (term_id) REFERENCES terms(id) ON DELETE RESTRICT,
    INDEX idx_report (teacher_id, subject_id, term_id),
    INDEX idx_stream (class, stream, term_id)
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- SEED: one admin account (change this password immediately after
-- first login -- same convention as every other seed account in this
-- codebase). password_hash('ChangeMe123!', PASSWORD_BCRYPT)
-- ------------------------------------------------------------
INSERT INTO admins (name, email, password_hash, status)
SELECT 'St. Mark''s Administrator', 'admin@stmarksnamagoma.local',
       '$2y$10$F1OHAGOY7XbHmbgGRpL36OTipUcdRZOdkpcHCCU3FaE7Ms/xL8ofe', 'active'
WHERE NOT EXISTS (SELECT 1 FROM admins WHERE email = 'admin@stmarksnamagoma.local');

-- Seed one active term so the student form has something to record
-- against out of the box. Edit via admin/terms.php as needed.
INSERT INTO terms (term, year, is_active)
SELECT 2, 2026, 1
WHERE NOT EXISTS (SELECT 1 FROM terms WHERE is_active = 1);
