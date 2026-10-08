<?php
$root = dirname(__DIR__);
$checks = [
  'SQL migration creates active_user_sessions' => ["sql/MyGrades_v1.27.3_Single_Login_Security_Logs.sql", 'CREATE TABLE IF NOT EXISTS active_user_sessions'],
  'SQL migration creates security_activity_logs' => ["sql/MyGrades_v1.27.3_Single_Login_Security_Logs.sql", 'CREATE TABLE IF NOT EXISTS security_activity_logs'],
  'auth helper supports single-session validation' => ['includes/auth.php', 'function validateSingleLoginSession'],
  'login blocks another active device' => ['login.php', 'beginSingleLoginSession'],
  'logout clears active session' => ['logout.php', 'endSingleLoginSession'],
  'teacher has security navigation' => ['teacher.php', 'data-view="security"'],
  'teacher API exposes security logs' => ['api/index.php', "case 'security_logs'"],
  'teacher API can force logout' => ['api/index.php', "case 'security_force_logout'"],
  'student submission writes security log' => ['api/student.php', "'output_submitted'"],
  'teacher JS renders security logs' => ['assets/app.js', 'async function renderSecurityLogs'],
  'teacher JS includes security view metadata' => ['assets/app.js', "security:['Student Security Logs'"],
];
$failed = 0;
foreach ($checks as $name => [$file, $needle]) {
  $path = $root . '/' . $file;
  $ok = is_file($path) && strpos((string)file_get_contents($path), $needle) !== false;
  echo ($ok ? "PASS" : "FAIL") . " - $name\n";
  if (!$ok) $failed++;
}
exit($failed ? 1 : 0);
