<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/asset_loader.php';

$user = requireSecureLoginPage($pdo,'student');

/*
 * Resolve the student's real profile name from the students table.
 * This is more reliable than depending only on users.display_name.
 */
$studentName = trim((string)($user['display_name'] ?? ''));
$studentNo   = trim((string)($user['username'] ?? ''));

if (!empty($user['student_id'])) {
    try {
        $q = $pdo->prepare("
            SELECT full_name, student_no
            FROM students
            WHERE id = ?
            LIMIT 1
        ");
        $q->execute([(int)$user['student_id']]);
        $studentProfile = $q->fetch(PDO::FETCH_ASSOC);

        if ($studentProfile) {
            if (!empty($studentProfile['full_name'])) {
                $studentName = trim((string)$studentProfile['full_name']);
            }
            if (!empty($studentProfile['student_no'])) {
                $studentNo = trim((string)$studentProfile['student_no']);
            }
        }
    } catch (Throwable $e) {
        // Keep session values as fallback if profile lookup fails.
    }
}

if ($studentName === '') {
    $studentName = $studentNo !== '' ? $studentNo : 'Student';
}

/* Create cleaner avatar initials from the student's full name. */
$nameParts = preg_split('/\s+/', $studentName, -1, PREG_SPLIT_NO_EMPTY);
$avatarText = '';
foreach (array_slice($nameParts, 0, 2) as $part) {
    $avatarText .= mb_strtoupper(mb_substr($part, 0, 1));
}
if ($avatarText === '') {
    $avatarText = 'ST';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="theme-color" content="#172554">
    <title>My Records · MYGRADES</title>
    <meta name="csrf-token" content="<?= htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8') ?>">
    <style><?= mygradebook_asset('student.css') ?></style>
</head>

<body>

<header class="student-topbar">

    <a class="student-brand" href="student.php">
        <span>MG</span>
        <div>
            <strong>MYGRADES</strong>
            <small>Student Portal</small>
        </div>
    </a>

    <div class="student-user">

        <div class="student-avatar">
            <?= htmlspecialchars($avatarText, ENT_QUOTES, 'UTF-8') ?>
        </div>

        <div class="student-account-name">
            <strong>
                <?= htmlspecialchars($studentName, ENT_QUOTES, 'UTF-8') ?>
            </strong>

            <small>
                <?= htmlspecialchars($studentNo, ENT_QUOTES, 'UTF-8') ?>
            </small>
        </div>

        <a href="change_password.php">Change password</a>
        <a href="logout.php">Sign out</a>

    </div>

</header>


<main class="student-main">

    <section id="studentWelcome" class="student-hero">

        <div>
            <span class="eyebrow">My Academic Records</span>

            <h1>
                Welcome, <?= htmlspecialchars($studentName, ENT_QUOTES, 'UTF-8') ?>
            </h1>

            <p>
                View your subjects, grades, assessment scores, and attendance.
                This dashboard only displays records connected to your student account.
            </p>
        </div>

        <div class="privacy-chip">
            🔒 Private student view
        </div>

    </section>


    <div id="studentApp">

        <div class="student-panel">
            Loading your records…
        </div>

    </div>

</main>


<div class="student-modal"
     id="studentSubmissionModal"
     aria-hidden="true">

    <div class="student-modal-box">

        <div class="student-modal-head">

            <div>
                <span class="muted">Student Submission</span>
                <h2 id="studentSubmissionTitle">
                    Submit Output
                </h2>
            </div>

            <button type="button"
                    id="closeStudentSubmission"
                    aria-label="Close">
                ×
            </button>

        </div>


        <div id="studentSubmissionInfo"
             class="submission-info">
        </div>


        <label class="student-field">

            <span>Attachment</span>

            <input
                id="studentSubmissionFile"
                type="file"
                accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt,.csv,.jpg,.jpeg,.png,.zip"
            >

            <small>
                Maximum 8 MB. You may submit a file, a note, or both.
            </small>

        </label>


        <label class="student-field">

            <span>Submission Note</span>

            <textarea
                id="studentSubmissionNote"
                rows="5"
                placeholder="Optional message or link for your teacher"
            ></textarea>

        </label>


        <div class="student-modal-actions">

            <button type="button"
                    id="cancelStudentSubmission">
                Cancel
            </button>

            <button type="button"
                    class="primary"
                    id="submitStudentOutput">
                Submit Output
            </button>

        </div>

    </div>

</div>


<div class="student-toast"
     id="studentToast">
</div>


<script>
<?= mygradebook_asset('student.js') ?>
</script>

</body>
</html>
