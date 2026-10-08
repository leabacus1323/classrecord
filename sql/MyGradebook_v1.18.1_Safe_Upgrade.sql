-- MyGradebook v1.18 Safe Upgrade from v1.17
-- v1.18 Teacher Submission Viewer introduces no new database tables or columns.
-- This script only updates the stored schema version marker.
-- Safe for an existing v1.17 database; it does not delete or alter grades, students, subjects, attendance, accounts, activities, or submissions.

INSERT INTO app_settings (setting_key, setting_value)
VALUES ('schema_version','1.18.1')
ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value);
