<?php
session_name('MYGRADEBOOK_SESSION');
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
date_default_timezone_set('Asia/Manila');
$user=requireApiUser($pdo,'student');
$studentId=(int)$user['student_id'];
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') requireCsrf();

function respondStudent($data,int $status=200):void{http_response_code($status);echo json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;}
function studentDefaultWeights(): array { return ['quiz'=>0.20,'exam'=>0.30,'performance'=>0.30,'output'=>0.20]; }
function studentNormalizeWeight($value): float { $weight=(float)$value; return $weight>1 ? $weight/100 : $weight; }
function studentLoadWeights(PDO $pdo,int $ccId,string $term): array {
    $weights=studentDefaultWeights();
    $stmt=$pdo->prepare("SELECT category,weight FROM term_weights WHERE class_course_id=? AND term=?");
    $stmt->execute([$ccId,$term]);
    foreach($stmt as $row){$category=(string)$row['category'];if(array_key_exists($category,$weights))$weights[$category]=studentNormalizeWeight($row['weight']);}
    return $weights;
}
function studentTermGrade(PDO $pdo,int $ccId,string $term,int $studentId):float{
    $weights=studentLoadWeights($pdo,$ccId,$term);
    $stmt=$pdo->prepare("SELECT a.category,SUM(COALESCE(s.score,0)) raw_total,SUM(a.max_score) max_total FROM assessments a LEFT JOIN scores s ON s.assessment_id=a.id AND s.student_id=? WHERE a.class_course_id=? AND a.term=? GROUP BY a.category");$stmt->execute([$studentId,$ccId,$term]);
    $current=0.0;foreach($stmt as $r){$eq=(float)$r['max_total']>0?((float)$r['raw_total']/(float)$r['max_total']*50+50):0;$current+=$eq*(float)($weights[$r['category']]??0);}
    if($term==='prelim')return round($current,2);
    if($term==='midterm')return round(studentTermGrade($pdo,$ccId,'prelim',$studentId)*.30+$current*.70,2);
    return round(studentTermGrade($pdo,$ccId,'midterm',$studentId)*.30+$current*.70,2);
}
function assertStudentSubject(PDO $pdo,int $ccId,int $studentId):array{
    $q=$pdo->prepare("SELECT cc.id,cc.semester,cc.school_year,cc.teacher_name,c.class_name,c.class_code,c.year_level,c.section,co.course_code,co.course_description FROM class_course_students ccs JOIN class_courses cc ON cc.id=ccs.class_course_id JOIN classes c ON c.id=cc.class_id JOIN courses co ON co.id=cc.course_id WHERE ccs.class_course_id=? AND ccs.student_id=? LIMIT 1");
    $q->execute([$ccId,$studentId]);$row=$q->fetch();if(!$row)respondStudent(['ok'=>false,'message'=>'This subject is not assigned to your account.'],403);return $row;
}
function studentSubmissionRoot():string{return dirname(__DIR__).'/uploads/submissions';}
function studentSafeDownloadName(string $name):string{$name=trim(str_replace(["\r","\n","\0"],'',$name));return $name!==''?$name:'submission';}
function streamStudentSubmission(array $row):void{
    $stored=basename((string)($row['stored_filename']??''));$activityId=(int)($row['activity_id']??0);$path=studentSubmissionRoot().'/'.$activityId.'/'.$stored;
    if(!$stored||!is_file($path))respondStudent(['ok'=>false,'message'=>'Submission file is no longer available.'],404);
    $name=studentSafeDownloadName((string)($row['original_filename']??'submission'));
    header_remove('Content-Type');header('Content-Type: '.((string)($row['mime_type']??'')?:'application/octet-stream'));header('Content-Length: '.filesize($path));
    header("Content-Disposition: attachment; filename=\"".str_replace('"','',$name)."\"; filename*=UTF-8''".rawurlencode($name));header('X-Content-Type-Options: nosniff');readfile($path);exit;
}
function studentActivityRows(PDO $pdo,int $studentId):array{
    $q=$pdo->prepare("SELECT oa.id activity_id,oa.instructions,oa.due_at,oa.allow_late,oa.is_published,a.id assessment_id,a.class_course_id,a.term,a.category,a.title,a.max_score,
                            c.class_name,co.course_code,co.course_description,
                            os.id submission_id,os.original_filename,os.file_size,os.submission_note,os.submitted_at,os.teacher_feedback,os.graded_at,sc.score
                     FROM output_activities oa
                     JOIN assessments a ON a.id=oa.assessment_id
                     JOIN class_course_students ccs ON ccs.class_course_id=a.class_course_id AND ccs.student_id=?
                     JOIN class_courses cc ON cc.id=a.class_course_id JOIN classes c ON c.id=cc.class_id JOIN courses co ON co.id=cc.course_id
                     LEFT JOIN output_submissions os ON os.activity_id=oa.id AND os.student_id=?
                     LEFT JOIN scores sc ON sc.assessment_id=a.id AND sc.student_id=?
                     WHERE oa.is_published=1
                     ORDER BY CASE WHEN oa.due_at IS NULL THEN 1 ELSE 0 END,oa.due_at,a.id DESC");
    $q->execute([$studentId,$studentId,$studentId]);$rows=$q->fetchAll();$now=time();
    foreach($rows as &$r){$due=$r['due_at']?strtotime((string)$r['due_at']):null;$r['can_submit']=($due===null||$due>=$now||!empty($r['allow_late']))?1:0;$r['is_late']=($r['submitted_at']&&$due!==null&&strtotime((string)$r['submitted_at'])>$due)?1:0;}
    return $rows;
}

$action=$_GET['action']??'bootstrap';
try{
    switch($action){
        case 'bootstrap':
            $p=$pdo->prepare("SELECT s.id,s.student_no,s.full_name,s.gender,s.email FROM students s WHERE s.id=?");$p->execute([$studentId]);$profile=$p->fetch();if(!$profile)respondStudent(['ok'=>false,'message'=>'Student profile not found'],404);
            $q=$pdo->prepare("SELECT cc.id class_course_id,cc.semester,cc.school_year,cc.teacher_name,c.class_name,c.class_code,c.year_level,c.section,co.course_code,co.course_description FROM class_course_students ccs JOIN class_courses cc ON cc.id=ccs.class_course_id JOIN classes c ON c.id=cc.class_id JOIN courses co ON co.id=cc.course_id WHERE ccs.student_id=? ORDER BY co.course_code,c.class_name");$q->execute([$studentId]);$subjects=$q->fetchAll();
            foreach($subjects as &$s){$cc=(int)$s['class_course_id'];$s['prelim']=studentTermGrade($pdo,$cc,'prelim',$studentId);$s['midterm']=studentTermGrade($pdo,$cc,'midterm',$studentId);$s['final']=studentTermGrade($pdo,$cc,'final',$studentId);$s['overall']=round(($s['prelim']+$s['midterm']+$s['final'])/3,2);}
            $att=$pdo->prepare("SELECT COUNT(*) total,SUM(status='Present') present,SUM(status='Late') late,SUM(status='Absent') absent,SUM(status='Excused') excused FROM attendance WHERE student_id=?");$att->execute([$studentId]);$attendance=$att->fetch();
            respondStudent(['ok'=>true,'user'=>currentUserPublic(),'profile'=>$profile,'subjects'=>$subjects,'attendance_summary'=>$attendance,'activities'=>studentActivityRows($pdo,$studentId)]);

        case 'record':
            $ccId=(int)($_GET['class_course_id']??0);$term=(string)($_GET['term']??'prelim');if(!$ccId)respondStudent(['ok'=>false,'message'=>'Subject is required'],422);if(!in_array($term,['prelim','midterm','final'],true))respondStudent(['ok'=>false,'message'=>'Invalid term'],422);
            $context=assertStudentSubject($pdo,$ccId,$studentId);
            $weights=studentLoadWeights($pdo,$ccId,$term);
            $a=$pdo->prepare("SELECT a.id,a.category,a.title,a.max_score,a.assessment_date,s.score FROM assessments a LEFT JOIN scores s ON s.assessment_id=a.id AND s.student_id=? WHERE a.class_course_id=? AND a.term=? ORDER BY FIELD(a.category,'quiz','exam','performance','output'),a.sort_order,a.id");$a->execute([$studentId,$ccId,$term]);$assessments=$a->fetchAll();
            $category=[];foreach(['quiz','exam','performance','output'] as $c)$category[$c]=['raw'=>0.0,'max'=>0.0,'equivalent'=>0.0,'weight'=>(float)($weights[$c]??0),'weighted'=>0.0];
            foreach($assessments as $r){$c=$r['category'];$category[$c]['raw']+=(float)($r['score']??0);$category[$c]['max']+=(float)$r['max_score'];}
            $current=0.0;foreach($category as $c=>&$r){$r['equivalent']=$r['max']>0?round($r['raw']/$r['max']*50+50,2):0.0;$r['weighted']=round($r['equivalent']*$r['weight'],2);$current+=$r['weighted'];}
            $termGrade=studentTermGrade($pdo,$ccId,$term,$studentId);$previous=$term==='prelim'?null:studentTermGrade($pdo,$ccId,$term==='midterm'?'prelim':'midterm',$studentId);
            $at=$pdo->prepare("SELECT attendance_date,status,remarks FROM attendance WHERE class_course_id=? AND student_id=? ORDER BY attendance_date DESC LIMIT 200");$at->execute([$ccId,$studentId]);$attendance=$at->fetchAll();
            respondStudent(['ok'=>true,'context'=>$context,'term'=>$term,'assessments'=>$assessments,'weights'=>$weights,'categories'=>$category,'current_equivalent'=>round($current,2),'previous_grade'=>$previous,'term_grade'=>$termGrade,'attendance'=>$attendance]);

        case 'activity_submit':
            if(($_SERVER['REQUEST_METHOD']??'GET')!=='POST')respondStudent(['ok'=>false,'message'=>'POST required'],405);
            $activityId=(int)($_POST['activity_id']??0);if(!$activityId)respondStudent(['ok'=>false,'message'=>'Activity is required'],422);
            $q=$pdo->prepare("SELECT oa.id,oa.assessment_id,oa.due_at,oa.allow_late,oa.is_published,a.class_course_id,a.title FROM output_activities oa JOIN assessments a ON a.id=oa.assessment_id JOIN class_course_students ccs ON ccs.class_course_id=a.class_course_id AND ccs.student_id=? WHERE oa.id=? LIMIT 1");$q->execute([$studentId,$activityId]);$activity=$q->fetch();if(!$activity||!(int)$activity['is_published'])respondStudent(['ok'=>false,'message'=>'This activity is not available to your account.'],403);
            $due=$activity['due_at']?strtotime((string)$activity['due_at']):null;if($due!==null&&time()>$due&&!(int)$activity['allow_late'])respondStudent(['ok'=>false,'message'=>'The submission deadline has passed.'],422);
            $existingQ=$pdo->prepare("SELECT * FROM output_submissions WHERE activity_id=? AND student_id=? LIMIT 1");$existingQ->execute([$activityId,$studentId]);$existing=$existingQ->fetch();
            $note=trim((string)($_POST['submission_note']??''));$stored=$existing['stored_filename']??null;$original=$existing['original_filename']??null;$mime=$existing['mime_type']??null;$size=$existing['file_size']??null;$newPath=null;$oldPath=null;
            if(isset($_FILES['submission_file'])&&($_FILES['submission_file']['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_NO_FILE){
                $f=$_FILES['submission_file'];if(($f['error']??UPLOAD_ERR_OK)!==UPLOAD_ERR_OK)respondStudent(['ok'=>false,'message'=>'The file upload failed. Check the hosting upload limit and try again.'],422);
                $maxBytes=8*1024*1024;if((int)$f['size']>$maxBytes)respondStudent(['ok'=>false,'message'=>'File is larger than the 8 MB submission limit.'],422);
                $original=basename((string)$f['name']);$ext=strtolower(pathinfo($original,PATHINFO_EXTENSION));$allowed=['pdf','doc','docx','xls','xlsx','ppt','pptx','txt','csv','jpg','jpeg','png','zip'];if(!in_array($ext,$allowed,true))respondStudent(['ok'=>false,'message'=>'Unsupported file type. Use PDF, Office documents, text/CSV, images, or ZIP.'],422);
                $dir=studentSubmissionRoot().'/'.$activityId;if(!is_dir($dir)&&!mkdir($dir,0755,true))respondStudent(['ok'=>false,'message'=>'Unable to create the submission storage folder.'],500);
                $stored=bin2hex(random_bytes(18)).'.'.$ext;$newPath=$dir.'/'.$stored;if(!move_uploaded_file($f['tmp_name'],$newPath))respondStudent(['ok'=>false,'message'=>'Unable to save the uploaded file on the server.'],500);
                $mime=(string)($f['type']??'application/octet-stream');$size=(int)$f['size'];if($existing&&!empty($existing['stored_filename']))$oldPath=$dir.'/'.basename((string)$existing['stored_filename']);
            }
            if(!$stored&&$note==='')respondStudent(['ok'=>false,'message'=>'Attach a file or enter a submission note before submitting.'],422);
            $pdo->beginTransaction();
            $up=$pdo->prepare("INSERT INTO output_submissions(activity_id,student_id,original_filename,stored_filename,mime_type,file_size,submission_note,submitted_at,teacher_feedback,graded_at) VALUES(?,?,?,?,?,?,?,NOW(),NULL,NULL) ON DUPLICATE KEY UPDATE original_filename=VALUES(original_filename),stored_filename=VALUES(stored_filename),mime_type=VALUES(mime_type),file_size=VALUES(file_size),submission_note=VALUES(submission_note),submitted_at=NOW(),teacher_feedback=NULL,graded_at=NULL");
            $up->execute([$activityId,$studentId,$original,$stored,$mime,$size,$note?:null]);
            $clear=$pdo->prepare("INSERT INTO scores(assessment_id,student_id,score) VALUES(?,?,NULL) ON DUPLICATE KEY UPDATE score=NULL");$clear->execute([(int)$activity['assessment_id'],$studentId]);$pdo->commit();
            $sidQ=$pdo->prepare("SELECT id FROM output_submissions WHERE activity_id=? AND student_id=? LIMIT 1");$sidQ->execute([$activityId,$studentId]);$submissionId=(int)($sidQ->fetchColumn()?:0);
            securityLog($pdo,(int)$user['id'],'output_submitted',['activity_id'=>$activityId,'activity_title'=>$activity['title'],'class_course_id'=>(int)$activity['class_course_id'],'submission_id'=>$submissionId,'file_name'=>$original,'has_note'=>$note!==''?1:0]);
            if($oldPath&&$newPath&&$oldPath!==$newPath&&is_file($oldPath))@unlink($oldPath);
            respondStudent(['ok'=>true,'message'=>'Output submitted successfully.']);

        case 'submission_download':
            $submissionId=(int)($_GET['submission_id']??0);if(!$submissionId)respondStudent(['ok'=>false,'message'=>'submission_id is required'],422);
            $q=$pdo->prepare("SELECT os.activity_id,os.original_filename,os.stored_filename,os.mime_type FROM output_submissions os WHERE os.id=? AND os.student_id=?");$q->execute([$submissionId,$studentId]);$row=$q->fetch();if(!$row)respondStudent(['ok'=>false,'message'=>'Submission not found'],404);streamStudentSubmission($row);

        default: respondStudent(['ok'=>false,'message'=>'Unknown action'],404);
    }
}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();respondStudent(['ok'=>false,'message'=>$e->getMessage()],500);}
