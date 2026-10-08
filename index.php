<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/asset_loader.php';

$u = currentUser();
if ($u) {
    if (validateSingleLoginSession($pdo,$u,true)) redirectForUser($u);
    destroyLocalLoginSession();
    session_name('MYGRADEBOOK_SESSION'); session_start();
}

$error = $_SESSION['login_error'] ?? '';
unset($_SESSION['login_error']);

$notice = $_SESSION['login_notice'] ?? '';
unset($_SESSION['login_notice']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="theme-color" content="#172554">
  <title>MYGRADES · Login</title>
  <style><?= mygradebook_asset('auth.css') ?></style>
</head>
<body class="auth-page mygrades-login-page">

  <main class="auth-shell mygrades-shell">

    <div class="mygrades-brand">
      <strong>MYGRADES</strong>
      <small>Christ the King College · Gingoog City</small>
    </div>

    <section class="auth-card mygrades-login-card" id="login">

      <h1 class="mygrades-signin-title">Sign in</h1>

      <p class="mygrades-login-description">
        Use your Teacher or Student account to access the academic record system.
      </p>

      <?php if ($error): ?>
        <div class="auth-alert">
          <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
        </div>
      <?php endif; ?>

      <?php if ($notice): ?>
        <div class="auth-success">
          <?= htmlspecialchars($notice, ENT_QUOTES, 'UTF-8') ?>
        </div>
      <?php endif; ?>

      <form method="post" action="login.php" autocomplete="on">

        <input
          type="hidden"
          name="csrf_token"
          value="<?= htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8') ?>"
        >

        <label>
          Username
          <input
            type="text"
            name="username"
            autocomplete="username"
            required
            placeholder="Teacher username or Student ID"
          >
        </label>

        <label>
          Password
          <input
            type="password"
            name="password"
            autocomplete="current-password"
            required
            placeholder="Enter password"
          >
        </label>

        <button type="submit">Sign In</button>

      </form>

      <a class="text-link forgot-link" href="forgot_password.php">
        Forgot your password?
      </a>

    </section>

  </main>

</body>
</html>
