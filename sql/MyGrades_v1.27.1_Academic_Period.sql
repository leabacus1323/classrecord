-- MYGRADES v1.27.1 Academic Period Upgrade
-- SAFE UPGRADE for the current database. Existing class_courses already has semester and school_year.
-- Back up the database before importing.

SET FOREIGN_KEY_CHECKS=0;

CREATE TABLE IF NOT EXISTS `academic_periods` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `school_year` varchar(20) NOT NULL,
  `semester` varchar(30) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_academic_period` (`school_year`,`semester`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `academic_periods` (`school_year`,`semester`,`is_active`)
SELECT DISTINCT `school_year`,`semester`,1
FROM `class_courses`
WHERE `school_year` IS NOT NULL AND `school_year`<>'' AND `semester` IS NOT NULL AND `semester`<>'';

INSERT IGNORE INTO `academic_periods` (`school_year`,`semester`,`is_active`) VALUES
('2026-2027','1st Semester',1),
('2026-2027','2nd Semester',1),
('2026-2027','Summer',1);

INSERT INTO `app_settings` (`setting_key`,`setting_value`) VALUES ('schema_version','1.27.1')
ON DUPLICATE KEY UPDATE `setting_value`=VALUES(`setting_value`);

SET FOREIGN_KEY_CHECKS=1;
