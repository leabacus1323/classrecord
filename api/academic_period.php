<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
$user = requireApiUser($pdo,'teacher');
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') requireCsrf();
function apRespond($d,$s=200){http_response_code($s);echo json_encode($d,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;}
try{
  $pdo->exec("CREATE TABLE IF NOT EXISTS academic_periods (id INT UNSIGNED NOT NULL AUTO_INCREMENT, school_year VARCHAR(20) NOT NULL, semester VARCHAR(30) NOT NULL, is_active TINYINT(1) NOT NULL DEFAULT 1, created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY(id), UNIQUE KEY uq_period(school_year,semester)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  $action=$_GET['action']??'list';
  if($action==='list'){
    $q=$pdo->query("SELECT id,school_year,semester,is_active,created_at FROM academic_periods ORDER BY school_year DESC, FIELD(semester,'1st Semester','2nd Semester','Summer'),semester");
    apRespond(['ok'=>true,'periods'=>$q->fetchAll(PDO::FETCH_ASSOC)]);
  }
  if($action==='create'){
    $d=json_decode(file_get_contents('php://input'),true)?:[];$sy=trim((string)($d['school_year']??''));$sem=trim((string)($d['semester']??''));
    if(!preg_match('/^\\d{4}-\\d{4}$/',$sy))apRespond(['ok'=>false,'message'=>'School Year must look like 2026-2027'],422);
    if(!in_array($sem,['1st Semester','2nd Semester','Summer'],true))apRespond(['ok'=>false,'message'=>'Invalid semester'],422);
    $q=$pdo->prepare("INSERT INTO academic_periods(school_year,semester,is_active) VALUES(?,?,1) ON DUPLICATE KEY UPDATE is_active=1");$q->execute([$sy,$sem]);
    apRespond(['ok'=>true,'message'=>'Academic period saved']);
  }
  if($action==='toggle'){
    $d=json_decode(file_get_contents('php://input'),true)?:[];$id=(int)($d['id']??0);$active=!empty($d['is_active'])?1:0;
    $q=$pdo->prepare("UPDATE academic_periods SET is_active=? WHERE id=?");$q->execute([$active,$id]);apRespond(['ok'=>true]);
  }
  apRespond(['ok'=>false,'message'=>'Unknown action'],404);
}catch(Throwable $e){apRespond(['ok'=>false,'message'=>$e->getMessage()],500);} 
