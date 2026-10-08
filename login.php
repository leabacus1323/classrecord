<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/config/database.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: index.php'); exit; }
$token = $_POST['csrf_token'] ?? '';
if (!$token || !hash_equals(csrfToken(), (string)$token)) {
    $_SESSION['login_error'] = 'Your login form expired. Please try again.';
    header('Location: index.php'); exit;
}
$username = trim((string)($_POST['username'] ?? ''));
$password = (string)($_POST['password'] ?? '');
if ($username === '' || $password === '') {
    $_SESSION['login_error'] = 'Enter your username and password.';
    header('Location: index.php'); exit;
}
$q=$pdo->prepare("SELECT id,username,password_hash,role,display_name,student_id,is_active,must_change_password FROM users WHERE username=? LIMIT 1");
$q->execute([$username]); $row=$q->fetch();
if (!$row || !(int)$row['is_active'] || !password_verify($password,$row['password_hash'])) {
    $_SESSION['login_error'] = 'Invalid username or password.';
    header('Location: index.php'); exit;
}
if ($row['role']==='student' && empty($row['student_id'])) {
    $_SESSION['login_error'] = 'This student account is not linked to a student record.';
    header('Location: index.php'); exit;
}
session_regenerate_id(true);
$single=beginSingleLoginSession($pdo,$row);
if(empty($single['ok'])){
    $active=$single['active']??[];
    $device=trim((string)($active['device_type']??''));$browser=trim((string)($active['browser']??''));
    $_SESSION['login_error']='This account is already active on another device'.(($device||$browser)?' ('.trim($device.' '.$browser).')':'').'. Log out there first or ask your teacher to force logout the account.';
    header('Location: index.php');exit;
}
$_SESSION['user']=[
    'id'=>(int)$row['id'],'username'=>$row['username'],'role'=>$row['role'],
    'display_name'=>$row['display_name'],'student_id'=>$row['student_id']!==null?(int)$row['student_id']:null,
    'must_change_password'=>(bool)$row['must_change_password']
];
$_SESSION['csrf_token']=bin2hex(random_bytes(32));
$pdo->prepare("UPDATE users SET last_login=NOW() WHERE id=?")->execute([(int)$row['id']]);
redirectForUser($_SESSION['user']);
