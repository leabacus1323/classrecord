-- MyGradebook v1.17 - Student Output Submission Module
-- Safe upgrade for an existing v1.16 database.
-- Existing students, subjects, assessments, scores, attendance, and user accounts are preserved.

CREATE TABLE IF NOT EXISTS output_activities (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    assessment_id INT UNSIGNED NOT NULL,
    instructions TEXT NULL,
    due_at DATETIME NULL,
    allow_late TINYINT(1) NOT NULL DEFAULT 0,
    is_published TINYINT(1) NOT NULL DEFAULT 1,
    created_by INT UNSIGNED NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_output_activity_assessment (assessment_id),
    INDEX idx_output_activity_due (due_at,is_published),
    CONSTRAINT fk_output_activity_assessment FOREIGN KEY (assessment_id) REFERENCES assessments(id) ON DELETE CASCADE,
    CONSTRAINT fk_output_activity_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS output_submissions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    activity_id BIGINT UNSIGNED NOT NULL,
    student_id INT UNSIGNED NOT NULL,
    original_filename VARCHAR(255) NULL,
    stored_filename VARCHAR(255) NULL,
    mime_type VARCHAR(160) NULL,
    file_size BIGINT UNSIGNED NULL,
    submission_note TEXT NULL,
    submitted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    teacher_feedback TEXT NULL,
    graded_at DATETIME NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_output_submission_student (activity_id,student_id),
    INDEX idx_output_submission_student (student_id,submitted_at),
    INDEX idx_output_submission_activity (activity_id,submitted_at),
    CONSTRAINT fk_output_submission_activity FOREIGN KEY (activity_id) REFERENCES output_activities(id) ON DELETE CASCADE,
    CONSTRAINT fk_output_submission_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO app_settings(setting_key,setting_value)
VALUES ('schema_version','1.17')
ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value);
