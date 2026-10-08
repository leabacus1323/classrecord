<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/asset_loader.php';
require_once __DIR__ . '/config/database.php';

if (currentUser()) redirectForUser(currentUser());
$error=''; $success='';
if($_SERVER['REQUEST_METHOD']==='POST'){
    $token=$_POST['csrf_token']??'';
    $username=trim((string)($_POST['username']??''));
    if(!$token || !hash_equals(csrfToken(),(string)$token)){
        $error='The form expired. Please try again.';
    } elseif($username===''){
        $error='Enter your username or Student ID.';
    } else {
        try{
            $q=$pdo->prepare("SELECT id FROM users WHERE username=? AND is_active=1 LIMIT 1");
            $q->execute([$username]);
            $userId=(int)($q->fetchColumn()?:0);
            if($userId){
                $pending=$pdo->prepare("SELECT id FROM password_reset_requests WHERE user_id=? AND status='pending' LIMIT 1");
                $pending->execute([$userId]);
                if(!$pending->fetchColumn()){
                    $ins=$pdo->prepare("INSERT INTO password_reset_requests(user_id,status,requested_at) VALUES(?,'pending',NOW())");
                    $ins->execute([$userId]);
                }
            }
            // Always return the same message so usernames cannot be discovered from this page.
            $success='If that account exists and is active, a password reset request has been sent to the teacher administrator.';
        } catch(Throwable $e){
            $error='Password reset requests are not enabled yet. Ask the administrator to run the v1.16 database upgrade.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <meta name="theme-color" content="#172554">
  <title>Forgot Password · MYGRADES</title>
  <style><?=mygradebook_asset('auth.css')?></style>
</head>
<body class="auth-page">
  <main class="auth-shell small">
    <a class="auth-brand" href="index.php"><span>MG</span><strong>MYGRADES</strong></a>
    <section class="auth-card">
      <div class="login-icon">?</div>
      <h1>Forgot password</h1>
      <p class="muted">Enter your Teacher username or Student ID. A teacher administrator can then reset your account to a temporary password.</p>
      <?php if($error):?><div class="auth-alert"><?=htmlspecialchars($error)?></div><?php endif;?>
      <?php if($success):?><div class="auth-success"><?=htmlspecialchars($success)?></div><?php endif;?>
      <?php if(!$success):?>
      <form method="post">
        <input type="hidden" name="csrf_token" value="<?=htmlspecialchars(csrfToken())?>">
        <label>Username / Student ID<input name="username" type="text" autocomplete="username" placeholder="Enter your account username" required></label>
        <button type="submit">Request Password Reset</button>
      </form>
      <?php endif;?>
      <a class="text-link" href="index.php">Back to sign in</a>
    </section>
  </main>
</body>
</html>
