<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
$user=requireApiUser($pdo,'teacher');
if(($_SERVER['REQUEST_METHOD']??'GET')==='POST')requireCsrf();
function sRespond($d,$s=200){http_response_code($s);echo json_encode($d,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;}
try{
  if(($_SERVER['REQUEST_METHOD']??'GET')==='GET'){
    $q=$pdo->query("SELECT cc.id subject_assignment_id,co.course_code subject,co.course_description description,c.class_name,c.year_level level,c.section,cc.semester,cc.school_year,cc.teacher_name,(SELECT COUNT(*) FROM class_course_students ccs WHERE ccs.class_course_id=cc.id) student_count FROM class_courses cc JOIN courses co ON co.id=cc.course_id JOIN classes c ON c.id=cc.class_id ORDER BY cc.school_year DESC,cc.semester,co.course_code,c.class_name");
    sRespond(['ok'=>true,'subjects'=>$q->fetchAll(PDO::FETCH_ASSOC)]);
  }
  $d=json_decode(file_get_contents('php://input'),true)?:[];
  foreach(['subject','description','level','section','semester','school_year'] as $f)if(trim((string)($d[$f]??''))==='')sRespond(['ok'=>false,'message'=>"$f is required"],422);
  $subject=trim($d['subject']);$description=trim($d['description']);$level=trim($d['level']);$section=trim($d['section']);$semester=trim($d['semester']);$sy=trim($d['school_year']);$teacher=trim($d['teacher_name']??($user['display_name']??'Teacher'));
  if(!in_array($semester,['1st Semester','2nd Semester','Summer'],true))sRespond(['ok'=>false,'message'=>'Invalid semester'],422);
  if(!preg_match('/^\\d{4}-\\d{4}$/',$sy))sRespond(['ok'=>false,'message'=>'School Year must look like 2026-2027'],422);
  $pdo->beginTransaction();
  $q=$pdo->prepare("SELECT id FROM courses WHERE course_code=? LIMIT 1");$q->execute([$subject]);$courseId=(int)($q->fetchColumn()?:0);
  if(!$courseId){$q=$pdo->prepare("INSERT INTO courses(course_code,course_description) VALUES(?,?)");$q->execute([$subject,$description]);$courseId=(int)$pdo->lastInsertId();}
  else{$pdo->prepare("UPDATE courses SET course_description=? WHERE id=?")->execute([$description,$courseId]);}
  $className=trim($level.' - '.$section);$q=$pdo->prepare("SELECT id FROM classes WHERE year_level=? AND section=? LIMIT 1");$q->execute([$level,$section]);$classId=(int)($q->fetchColumn()?:0);
  if(!$classId){$code=strtolower(preg_replace('/[^a-z0-9]+/i','-',trim($level.'-'.$section))).'-'.substr(md5((string)microtime(true)),0,5);$q=$pdo->prepare("INSERT INTO classes(class_code,class_name,program,year_level,section) VALUES(?,?,?,?,?)");$q->execute([$code,$className,'',$level,$section]);$classId=(int)$pdo->lastInsertId();}
  $q=$pdo->prepare("SELECT id FROM class_courses WHERE class_id=? AND course_id=? AND semester=? AND school_year=? LIMIT 1");$q->execute([$classId,$courseId,$semester,$sy]);$cc=(int)($q->fetchColumn()?:0);
  if(!$cc){$q=$pdo->prepare("INSERT INTO class_courses(class_id,course_id,semester,school_year,teacher_name) VALUES(?,?,?,?,?)");$q->execute([$classId,$courseId,$semester,$sy,$teacher]);$cc=(int)$pdo->lastInsertId();foreach(['prelim','midterm','final'] as $term)foreach(['quiz'=>.20,'exam'=>.30,'performance'=>.30,'output'=>.20] as $cat=>$w)$pdo->prepare("INSERT INTO term_weights(class_course_id,term,category,weight) VALUES(?,?,?,?)")->execute([$cc,$term,$cat,$w]);}
  $pdo->commit();sRespond(['ok'=>true,'class_course_id'=>$cc,'class_id'=>$classId]);
}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();sRespond(['ok'=>false,'message'=>$e->getMessage()],500);} 
