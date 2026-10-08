-- MyGradebook v1.18.2 Complete Database Installer
-- Includes subject management, subject-specific student assignment,
-- Includes Teacher/Student accounts, password reset, output activities/submissions, and Teacher submission viewer support.
-- assessments, bulk score storage, attendance, grading weights, Teacher/Student authentication, password resets, and student output submissions.
-- InfinityFree-ready: select your existing MyGradebook database in phpMyAdmin before importing.
-- WARNING: This is a FULL installer. Import it into the selected database in phpMyAdmin. It recreates MyGradebook tables.

SET FOREIGN_KEY_CHECKS=0;
DROP TABLE IF EXISTS output_submissions;
DROP TABLE IF EXISTS output_activities;
DROP TABLE IF EXISTS password_reset_requests;
DROP TABLE IF EXISTS attendance;
DROP TABLE IF EXISTS scores;
DROP TABLE IF EXISTS assessments;
DROP TABLE IF EXISTS term_weights;
DROP TABLE IF EXISTS class_course_students;
DROP TABLE IF EXISTS enrollments;
DROP TABLE IF EXISTS class_courses;
DROP TABLE IF EXISTS users;
DROP TABLE IF EXISTS students;
DROP TABLE IF EXISTS courses;
DROP TABLE IF EXISTS classes;
DROP TABLE IF EXISTS app_settings;
SET FOREIGN_KEY_CHECKS=1;

CREATE TABLE app_settings (
    setting_key VARCHAR(80) PRIMARY KEY,
    setting_value VARCHAR(255) NOT NULL
) ENGINE=InnoDB;

CREATE TABLE classes (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    class_code VARCHAR(40) NOT NULL UNIQUE,
    class_name VARCHAR(120) NOT NULL,
    program VARCHAR(120) NULL,
    year_level VARCHAR(30) NULL,
    section VARCHAR(30) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE courses (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    course_code VARCHAR(40) NOT NULL UNIQUE,
    course_description VARCHAR(180) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE students (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    student_no VARCHAR(50) NOT NULL UNIQUE,
    full_name VARCHAR(180) NOT NULL,
    gender ENUM('Male','Female') NULL,
    email VARCHAR(180) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_students_gender_name (gender, full_name)
) ENGINE=InnoDB;

CREATE TABLE users (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(80) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('teacher','student') NOT NULL,
    display_name VARCHAR(180) NOT NULL,
    student_id INT UNSIGNED NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    must_change_password TINYINT(1) NOT NULL DEFAULT 1,
    last_login DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_user_student (student_id),
    INDEX idx_users_role_active (role,is_active),
    CONSTRAINT fk_user_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE enrollments (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    class_id INT UNSIGNED NOT NULL,
    student_id INT UNSIGNED NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_enrollment (class_id, student_id),
    CONSTRAINT fk_enrollment_class FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE,
    CONSTRAINT fk_enrollment_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE class_courses (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    class_id INT UNSIGNED NOT NULL,
    course_id INT UNSIGNED NOT NULL,
    semester VARCHAR(30) NOT NULL DEFAULT '1st Semester',
    school_year VARCHAR(30) NOT NULL DEFAULT '2026-2027',
    teacher_name VARCHAR(180) NOT NULL DEFAULT 'CKC Faculty',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_class_course_period (class_id, course_id, semester, school_year),
    CONSTRAINT fk_cc_class FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE,
    CONSTRAINT fk_cc_course FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE class_course_students (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    class_course_id INT UNSIGNED NOT NULL,
    student_id INT UNSIGNED NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_class_course_student (class_course_id, student_id),
    INDEX idx_ccs_student (student_id),
    CONSTRAINT fk_ccs_class_course FOREIGN KEY (class_course_id) REFERENCES class_courses(id) ON DELETE CASCADE,
    CONSTRAINT fk_ccs_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE term_weights (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    class_course_id INT UNSIGNED NOT NULL,
    term ENUM('prelim','midterm','final') NOT NULL,
    category ENUM('quiz','exam','performance','output') NOT NULL,
    weight DECIMAL(6,5) NOT NULL,
    UNIQUE KEY uq_term_weight (class_course_id, term, category),
    CONSTRAINT fk_weight_cc FOREIGN KEY (class_course_id) REFERENCES class_courses(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE assessments (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    class_course_id INT UNSIGNED NOT NULL,
    term ENUM('prelim','midterm','final') NOT NULL,
    category ENUM('quiz','exam','performance','output') NOT NULL,
    title VARCHAR(160) NOT NULL,
    max_score DECIMAL(8,2) NOT NULL,
    assessment_date DATE NULL,
    sort_order INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_assessment_book (class_course_id, term, category, sort_order),
    CONSTRAINT fk_assessment_cc FOREIGN KEY (class_course_id) REFERENCES class_courses(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE scores (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    assessment_id INT UNSIGNED NOT NULL,
    student_id INT UNSIGNED NOT NULL,
    score DECIMAL(8,2) NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_score (assessment_id, student_id),
    CONSTRAINT fk_score_assessment FOREIGN KEY (assessment_id) REFERENCES assessments(id) ON DELETE CASCADE,
    CONSTRAINT fk_score_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE attendance (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    class_course_id INT UNSIGNED NOT NULL,
    student_id INT UNSIGNED NOT NULL,
    attendance_date DATE NOT NULL,
    status ENUM('Present','Absent','Late','Excused') NOT NULL DEFAULT 'Present',
    remarks VARCHAR(255) NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_attendance (class_course_id, student_id, attendance_date),
    CONSTRAINT fk_attendance_cc FOREIGN KEY (class_course_id) REFERENCES class_courses(id) ON DELETE CASCADE,
    CONSTRAINT fk_attendance_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE
) ENGINE=InnoDB;


CREATE TABLE password_reset_requests (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    status ENUM('pending','completed','cancelled') NOT NULL DEFAULT 'pending',
    requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    processed_by INT UNSIGNED NULL,
    processed_at DATETIME NULL,
    INDEX idx_reset_user_status (user_id,status,requested_at),
    INDEX idx_reset_status_date (status,requested_at),
    CONSTRAINT fk_reset_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_reset_processed_by FOREIGN KEY (processed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE output_activities (
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

CREATE TABLE output_submissions (
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

INSERT INTO app_settings(setting_key, setting_value) VALUES
('school_name','CHRIST THE KING COLLEGE'),
('school_address','9014 GINGOOG CITY'),
('default_teacher','CKC Faculty'),
('app_name','MyGradebook'),
('schema_version','1.18.2');

INSERT INTO classes(class_code,class_name,program,year_level,section) VALUES
('bsit3a','BSIT 3A','BS Information Technology','3','A'),
('bsba2a','BSBA 2A','BS Business Administration','2','A');

INSERT INTO courses(course_code,course_description) VALUES
('IT 301','Web Systems and Technologies'),
('IT 302','Advanced Database Systems'),
('IT 303','Human Computer Interaction');

INSERT INTO students(student_no,full_name,gender) VALUES
('2024-0182','Alyssa Marie Santos','Female'),
('2024-0191','Brian Lee Cruz','Male'),
('2024-0204','Carla Mae Flores','Female'),
('2024-0217','Daniel Reyes','Male'),
('2024-0223','Erika Joy Lim','Female'),
('2024-0230','Francis Gomez','Male'),
('2024-0238','Grace Mendoza','Female'),
('2024-0245','Henry Tan','Male'),
('2025-0101','Anna Mae Dizon','Female'),
('2025-0107','John Carlo Uy','Male'),
('2025-0115','Mika Villanueva','Female'),
('2025-0123','Paolo Ramos','Male'),
('2025-0132','Rica Bautista','Female'),
('2025-0140','Samuel Go','Male');

-- Initial login accounts. Both temporary passwords must be changed at first login.
-- Teacher: username teacher / password Teacher123!
INSERT INTO users(username,password_hash,role,display_name,student_id,is_active,must_change_password)
VALUES ('teacher','$2y$12$Xuo0xvqaaW/5ShLvPFToP.tlMvssgdWYj6BxOgSNWNA.p7Eqz.AGa','teacher','CKC Faculty',NULL,1,1);

-- Demo student accounts use Student ID as username / temporary password Student123!.
INSERT INTO users(username,password_hash,role,display_name,student_id,is_active,must_change_password)
SELECT s.student_no,'$2y$12$u/dw.r.qRM5SCDlCbCHar.LJezNkrAYeQGg2Jn1Y8OITYlJeBhG0u','student',s.full_name,s.id,1,1 FROM students s;

INSERT INTO enrollments(class_id,student_id)
SELECT c.id,s.id FROM classes c CROSS JOIN students s
WHERE (c.class_code='bsit3a' AND s.student_no LIKE '2024-%')
   OR (c.class_code='bsba2a' AND s.student_no LIKE '2025-%');

INSERT INTO class_courses(class_id,course_id,semester,school_year,teacher_name)
SELECT c.id,co.id,'1st Semester','2026-2027','CKC Faculty'
FROM classes c CROSS JOIN courses co;

-- Subject-specific student assignments. Existing demo class enrollments are initially
-- assigned to every seeded subject for that class so the original behavior is preserved.
INSERT INTO class_course_students(class_course_id,student_id)
SELECT cc.id,e.student_id
FROM class_courses cc
JOIN enrollments e ON e.class_id=cc.class_id;

INSERT INTO term_weights(class_course_id,term,category,weight)
SELECT cc.id,t.term,c.category,c.weight
FROM class_courses cc
CROSS JOIN (SELECT 'prelim' term UNION ALL SELECT 'midterm' UNION ALL SELECT 'final') t
CROSS JOIN (
  SELECT 'quiz' category,0.20 weight UNION ALL
  SELECT 'exam',0.30 UNION ALL
  SELECT 'performance',0.30 UNION ALL
  SELECT 'output',0.20
) c;

-- Create the seven starter assessments for every class/course/term.
INSERT INTO assessments(class_course_id,term,category,title,max_score,sort_order)
SELECT cc.id,t.term,a.category,a.title,a.max_score,a.sort_order
FROM class_courses cc
CROSS JOIN (SELECT 'prelim' term UNION ALL SELECT 'midterm' UNION ALL SELECT 'final') t
CROSS JOIN (
  SELECT 'quiz' category,'Quiz 1' title,20 max_score,1 sort_order UNION ALL
  SELECT 'quiz','Quiz 2',20,2 UNION ALL
  SELECT 'exam','Exam',100,1 UNION ALL
  SELECT 'performance','Performance 1',50,1 UNION ALL
  SELECT 'performance','Performance 2',50,2 UNION ALL
  SELECT 'output','Output 1',30,1 UNION ALL
  SELECT 'output','Output 2',30,2
) a;

-- Demo scores: deterministic values based on student and assessment IDs.
INSERT INTO scores(assessment_id,student_id,score)
SELECT a.id,ccs.student_id,
       ROUND(a.max_score * (0.70 + (MOD(ccs.student_id*3 + a.id*7, 25) / 100)),0)
FROM assessments a
JOIN class_course_students ccs ON ccs.class_course_id=a.class_course_id;
