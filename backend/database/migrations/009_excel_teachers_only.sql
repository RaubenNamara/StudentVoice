-- Teachers come only from the Excel upload (the Teaching list). Teachers synced from Tusome that
-- the Excel doesn't name are hidden (status 'gone') - never deleted, so a later upload that names
-- them brings them back. The Tusome sync still brings in which subjects each student takes.
-- teachers_source: 'excel' (only teachers on the Teaching list) or 'tusome' (every Tusome teacher).

INSERT INTO settings (name, value) VALUES ('teachers_source', 'excel')
  ON DUPLICATE KEY UPDATE value = value;

UPDATE teachers t SET t.status = 'gone'
  WHERE NOT EXISTS (SELECT 1 FROM teaching x WHERE x.teacher_id = t.id);
