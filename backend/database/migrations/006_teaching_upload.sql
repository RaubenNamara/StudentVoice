-- Teaching list from an Excel upload (Teachers > Upload from Excel).
-- Teachers named in the file but not found in Tusome are added here (tusome_id NULL,
-- source 'upload'); a Tusome sync never marks them gone. Cells filled from a file are
-- source 'upload' (a sync leaves them alone, like 'admin').

ALTER TABLE teachers MODIFY tusome_id INT UNSIGNED NULL DEFAULT NULL;
ALTER TABLE teachers ADD COLUMN source ENUM('tusome','upload') NOT NULL DEFAULT 'tusome' AFTER tusome_id;
ALTER TABLE teaching MODIFY source ENUM('auto','admin','upload') NOT NULL DEFAULT 'admin';
