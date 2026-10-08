<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/asset_loader.php';
require_once __DIR__ . '/config/database.php';

$u = requireSecureLoginPage($pdo);
$forced = !empty($u['must_change_password']);
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    $current = (string)($_POST['current_password'] ?? '');
    $password = (string)($_POST['new_password'] ?? '');
    $confirm = (string)($_POST['confirm_password'] ?? '');

    if (!$token || !hash_equals(csrfToken(), (string)$token)) {
        $error = 'The form expired. Please try again.';
    } elseif (strlen($password) < 8) {
        $error = 'Use at least 8 characters for your new password.';
    } elseif ($password !== $confirm) {
        $error = 'The passwords do not match.';
    } else {
        $q = $pdo->prepare("SELECT password_hash FROM users WHERE id = ? AND is_active = 1 LIMIT 1");
        $q->execute([(int)$u['id']]);
        $oldHash = (string)($q->fetchColumn() ?: '');

        if (!$oldHash) {
            $error = 'Your account could not be verified.';
        } elseif (!$forced && ($current === '' || !password_verify($current, $oldHash))) {
            $error = 'Your current password is incorrect.';
        } elseif (password_verify($password, $oldHash)) {
            $error = 'Choose a new password that is different from your current password.';
        } else {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $pdo->prepare("UPDATE users SET password_hash = ?, must_change_password = 0, updated_at = NOW() WHERE id = ?")
                ->execute([$hash, (int)$u['id']]);

            try {
                $pdo->prepare("UPDATE password_reset_requests SET status='completed', processed_at=NOW(), processed_by=? WHERE user_id=? AND status='pending'")
                    ->execute([(int)$u['id'], (int)$u['id']]);
            } catch (Throwable $ignored) {
            }

            $_SESSION['user']['must_change_password'] = false;
            $_SESSION['login_notice'] = 'Password updated successfully.';
            redirectForUser($_SESSION['user']);
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <meta name="theme-color" content="#0b1739">
  <title><?= $forced ? 'Set New Password' : 'Change Password' ?> · MYGRADES</title>
  <style><?= mygradebook_asset('auth.css') ?></style>
</head>
<body class="auth-page mygrades-login-page">
  <main class="auth-shell mygrades-shell small">
    <div class="mygrades-brand">
      <strong>MYGRADES</strong>
      <small>Christ the King College · Gingoog City</small>
    </div>

    <section class="auth-card mygrades-login-card">
      <h1 class="mygrades-signin-title"><?= $forced ? 'Set a new password' : 'Change your password' ?></h1>
      <p class="mygrades-login-description">
        <?= $forced
            ? 'Create a secure password to continue to your account.'
            : 'Confirm your current password, then choose a new one.' ?>
      </p>

      <?php if ($error): ?>
        <div class="auth-alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
      <?php endif; ?>

      <form method="post" autocomplete="off">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8') ?>">

        <?php if (!$forced): ?>
          <label>
            Current password
            <input
              name="current_password"
              type="password"
              autocomplete="current-password"
              placeholder="Enter current password"
              required
            >
          </label>
        <?php endif; ?>

        <label>
          New password
          <input
            name="new_password"
            type="password"
            minlength="8"
            autocomplete="new-password"
            placeholder="At least 8 characters"
            required
          >
        </label>

        <label>
          Confirm password
          <input
            name="confirm_password"
            type="password"
            minlength="8"
            autocomplete="new-password"
            placeholder="Enter it again"
            required
          >
        </label>

        <button type="submit">Save Password</button>
      </form>

      <?php if ($forced): ?>
        <a class="text-link" href="logout.php">Sign out instead</a>
      <?php else: ?>
        <a class="text-link" href="<?= ($u['role'] ?? '') === 'student' ? 'student.php' : 'teacher.php' ?>">Back to dashboard</a>
      <?php endif; ?>
    </section>
  </main>
</body>
</html>
