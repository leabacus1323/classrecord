<?php
/**
 * MyGradebook Activities schema bootstrap.
 * Uses information_schema (reliable on InfinityFree/MySQL) and can create
 * the two activity tables automatically if an older installation missed them.
 */
function mygradebookTableExists(PDO $pdo, string $table): bool {
    $q = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
    $q->execute([$table]);
    return (int)$q->fetchColumn() > 0;
}

function mygradebookColumnExists(PDO $pdo, string $table, string $column): bool {
    $q = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
    $q->execute([$table,$column]);
    return (int)$q->fetchColumn() > 0;
}

function ensureMyGradebookActivitySchema(PDO $pdo): array {
    $created = [];
    try {
        if (!mygradebookTableExists($pdo,'output_activities')) {
            // Deliberately omit foreign keys here for maximum compatibility with
            // older MyGradebook installations. Application logic maintains links.
            $pdo->exec("CREATE TABLE output_activities (
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
                INDEX idx_output_activity_creator (created_by)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            $created[] = 'output_activities';
        }

        if (!mygradebookTableExists($pdo,'output_submissions')) {
            $pdo->exec("CREATE TABLE output_submissions (
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
                INDEX idx_output_submission_activity (activity_id,submitted_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            $created[] = 'output_submissions';
        }

        // Verify required columns too, so a partially-created old table gives a useful error.
        $required = [
            'output_activities' => ['id','assessment_id','instructions','due_at','allow_late','is_published','created_by','created_at','updated_at'],
            'output_submissions' => ['id','activity_id','student_id','original_filename','stored_filename','mime_type','file_size','submission_note','submitted_at','teacher_feedback','graded_at','updated_at'],
        ];
        $missingColumns = [];
        foreach ($required as $table => $columns) {
            foreach ($columns as $column) {
                if (!mygradebookColumnExists($pdo,$table,$column)) $missingColumns[] = $table.'.'.$column;
            }
        }
        if ($missingColumns) {
            throw new RuntimeException('Activity tables exist but are incomplete. Missing columns: '.implode(', ',$missingColumns));
        }
        return ['ok'=>true,'created'=>$created];
    } catch (Throwable $e) {
        throw new RuntimeException('Activities database setup failed: '.$e->getMessage(),0,$e);
    }
}
