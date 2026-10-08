<?php
// MyGradebook authentication/session helpers.
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name('MYGRADEBOOK_SESSION');

    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => false,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    session_start();
}

function currentUser(): ?array {
    return isset($_SESSION['user']) && is_array($_SESSION['user']) ? $_SESSION['user'] : null;
}

function currentUserPublic(): ?array {
    $u = currentUser();
    if (!$u) return null;
    return [
        'id' => (int)$u['id'],
        'username' => (string)$u['username'],
        'role' => (string)$u['role'],
        'display_name' => (string)$u['display_name'],
        'student_id' => isset($u['student_id']) && $u['student_id'] !== null ? (int)$u['student_id'] : null,
        'must_change_password' => !empty($u['must_change_password']),
    ];
}

function csrfToken(): string {
    if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf_token'];
}

function requireCsrf(): void {
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['csrf_token'] ?? '');
    if (!$token || !hash_equals(csrfToken(), (string)$token)) {
        http_response_code(419);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok'=>false,'message'=>'Security token expired. Refresh the page and try again.']);
        exit;
    }
}

function redirectForUser(array $u): void {
    if (!empty($u['must_change_password'])) {
        header('Location: change_password.php');
    } elseif (($u['role'] ?? '') === 'student') {
        header('Location: student.php');
    } else {
        header('Location: teacher.php');
    }
    exit;
}

function requireLoginPage(?string $role=null): array {
    $u = currentUser();
    if (!$u) { header('Location: index.php'); exit; }
    if (!empty($u['must_change_password']) && basename($_SERVER['PHP_SELF'] ?? '') !== 'change_password.php') {
        header('Location: change_password.php'); exit;
    }
    if ($role !== null && ($u['role'] ?? '') !== $role) redirectForUser($u);
    return $u;
}

function requireApiRole(string $role): array {
    $u = currentUser();
    if (!$u) {
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok'=>false,'message'=>'Your login session has expired.']);
        exit;
    }
    if (!empty($u['must_change_password'])) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok'=>false,'message'=>'Password change required before continuing.']);
        exit;
    }
    if (($u['role'] ?? '') !== $role) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok'=>false,'message'=>'You do not have permission to access this resource.']);
        exit;
    }
    return $u;
}

function requireApiUser(PDO $pdo, string $role): array {
    $u = requireApiRole($role);
    if (!validateSingleLoginSession($pdo,$u,true)) {
        destroyLocalLoginSession();
        http_response_code(401); header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok'=>false,'message'=>'This login session is no longer active. Please sign in again.']); exit;
    }
    $q=$pdo->prepare("SELECT id,username,role,display_name,student_id,is_active,must_change_password FROM users WHERE id=? LIMIT 1");
    $q->execute([(int)$u['id']]); $fresh=$q->fetch(PDO::FETCH_ASSOC);
    if(!$fresh || !(int)$fresh['is_active']){
        $_SESSION=[];
        http_response_code(401); header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok'=>false,'message'=>'This account is no longer active.']); exit;
    }
    $_SESSION['user']=[
        'id'=>(int)$fresh['id'],'username'=>$fresh['username'],'role'=>$fresh['role'],'display_name'=>$fresh['display_name'],
        'student_id'=>$fresh['student_id']!==null?(int)$fresh['student_id']:null,'must_change_password'=>(bool)$fresh['must_change_password']
    ];
    if(!empty($fresh['must_change_password'])){
        http_response_code(403); header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok'=>false,'message'=>'Password change required before continuing.']); exit;
    }
    if($fresh['role']!==$role){
        http_response_code(403); header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok'=>false,'message'=>'You do not have permission to access this resource.']); exit;
    }
    return $_SESSION['user'];
}


// v1.27.3 single-login and security logging helpers.
function securityClientIp(): string {
    $candidates = [];
    if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) $candidates[] = trim((string)$_SERVER['HTTP_CF_CONNECTING_IP']);
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        foreach (explode(',', (string)$_SERVER['HTTP_X_FORWARDED_FOR']) as $ip) $candidates[] = trim($ip);
    }
    if (!empty($_SERVER['REMOTE_ADDR'])) $candidates[] = trim((string)$_SERVER['REMOTE_ADDR']);
    foreach ($candidates as $ip) if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
    return '';
}

function securityClientInfo(): array {
    $ua = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500);
    $lower = strtolower($ua);
    $device = 'Desktop';
    if (strpos($lower, 'ipad') !== false || strpos($lower, 'tablet') !== false) $device = 'Tablet';
    elseif (strpos($lower, 'iphone') !== false || strpos($lower, 'android') !== false || strpos($lower, 'mobile') !== false) $device = 'Mobile';
    $browser = 'Other';
    if (strpos($lower, 'edg/') !== false) $browser = 'Edge';
    elseif (strpos($lower, 'opr/') !== false || strpos($lower, 'opera') !== false) $browser = 'Opera';
    elseif (strpos($lower, 'chrome/') !== false) $browser = 'Chrome';
    elseif (strpos($lower, 'firefox/') !== false) $browser = 'Firefox';
    elseif (strpos($lower, 'safari/') !== false) $browser = 'Safari';
    return ['ip'=>securityClientIp(),'user_agent'=>$ua,'device_type'=>$device,'browser'=>$browser];
}

function securityLog(PDO $pdo, ?int $userId, string $eventType, array $details=[], ?int $actorUserId=null): void {
    try {
        $ci = securityClientInfo();
        $q = $pdo->prepare("INSERT INTO security_activity_logs(user_id,actor_user_id,event_type,ip_address,user_agent,device_type,browser,details,created_at) VALUES(?,?,?,?,?,?,?,?,NOW())");
        $json = $details ? json_encode($details, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) : null;
        $q->execute([$userId,$actorUserId,$eventType,$ci['ip'],$ci['user_agent'],$ci['device_type'],$ci['browser'],$json]);
    } catch (Throwable $e) {
        // Logging must never break gradebook operations after the security schema is installed.
    }
}

function beginSingleLoginSession(PDO $pdo, array $userRow): array {
    $userId = (int)$userRow['id'];
    $ci = securityClientInfo();
    $token = bin2hex(random_bytes(32));
    $pdo->beginTransaction();
    try {
        $q = $pdo->prepare("SELECT user_id,session_token,device_type,browser,ip_address,last_seen_at FROM active_user_sessions WHERE user_id=? FOR UPDATE");
        $q->execute([$userId]);
        $active = $q->fetch(PDO::FETCH_ASSOC);
        if ($active) {
            $last = strtotime((string)$active['last_seen_at']);
            $isFresh = $last !== false && $last >= (time() - 7200); // 2-hour inactivity safety release.
            if ($isFresh) {
                $pdo->rollBack();
                securityLog($pdo,$userId,'blocked_login',["reason"=>"another_active_session","active_device"=>$active['device_type'],"active_browser"=>$active['browser'],"active_ip"=>$active['ip_address']]);
                return ['ok'=>false,'active'=>$active];
            }
            $pdo->prepare("DELETE FROM active_user_sessions WHERE user_id=?")->execute([$userId]);
        }
        $ins=$pdo->prepare("INSERT INTO active_user_sessions(user_id,session_token,php_session_id,ip_address,user_agent,device_type,browser,login_at,last_seen_at) VALUES(?,?,?,?,?,?,?,NOW(),NOW())");
        $ins->execute([$userId,$token,session_id(),$ci['ip'],$ci['user_agent'],$ci['device_type'],$ci['browser']]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    $_SESSION['login_session_token']=$token;
    securityLog($pdo,$userId,'login_success');
    return ['ok'=>true,'token'=>$token];
}

function validateSingleLoginSession(PDO $pdo, array $u, bool $touch=true): bool {
    $token=(string)($_SESSION['login_session_token']??'');
    if ($token==='') return false;
    try {
        $q=$pdo->prepare("SELECT session_token,last_seen_at FROM active_user_sessions WHERE user_id=? LIMIT 1");
        $q->execute([(int)$u['id']]);$row=$q->fetch(PDO::FETCH_ASSOC);
        if(!$row || !hash_equals((string)$row['session_token'],$token)) return false;
        if($touch) $pdo->prepare("UPDATE active_user_sessions SET last_seen_at=NOW(),php_session_id=? WHERE user_id=? AND session_token=?")->execute([session_id(),(int)$u['id'],$token]);
        return true;
    } catch (Throwable $e) { return false; }
}

function endSingleLoginSession(PDO $pdo, ?array $u, string $eventType='logout'): void {
    if(!$u) return;
    $token=(string)($_SESSION['login_session_token']??'');
    try {
        if($token!==''){
            $q=$pdo->prepare("DELETE FROM active_user_sessions WHERE user_id=? AND session_token=?");
            $q->execute([(int)$u['id'],$token]);
        }
    } catch (Throwable $e) {}
    securityLog($pdo,(int)$u['id'],$eventType);
}

function destroyLocalLoginSession(): void {
    $_SESSION=[];
    if (ini_get('session.use_cookies')) {
        $p=session_get_cookie_params();
        setcookie(session_name(),'',time()-42000,$p['path'],$p['domain']??'',(bool)$p['secure'],(bool)$p['httponly']);
    }
    if(session_status()===PHP_SESSION_ACTIVE) session_destroy();
}

function requireSecureLoginPage(PDO $pdo, ?string $role=null): array {
    $u=requireLoginPage($role);
    if(!validateSingleLoginSession($pdo,$u,true)){
        destroyLocalLoginSession();
        session_name('MYGRADEBOOK_SESSION');
        session_start();
        $_SESSION['login_error']='Your session is no longer active. It may have been signed out by your teacher or replaced.';
        header('Location: index.php');exit;
    }
    return $u;
}
