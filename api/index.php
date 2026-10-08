<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
date_default_timezone_set('Asia/Manila');
$authUser = requireApiUser($pdo,'teacher');
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') requireCsrf();

function respond($data, int $status=200): void {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
function body(): array {
    $raw = file_get_contents('php://input');
    if (!$raw) return $_POST ?: [];
    $data = json_decode($raw, true);
    return is_array($data) ? $data : $_POST;
}
function requireFields(array $data, array $fields): void {
    foreach ($fields as $f) {
        if (!isset($data[$f]) || $data[$f] === '') respond(['ok'=>false,'message'=>"Missing field: $f"],422);
    }
}
function normalizeGender($value): ?string {
    $g = strtolower(trim((string)$value));
    if ($g === 'm' || $g === 'male') return 'Male';
    if ($g === 'f' || $g === 'female') return 'Female';
    return null;
}

function submissionRoot(): string {
    return dirname(__DIR__) . '/uploads/submissions';
}
function safeDownloadName(string $name): string {
    $name = trim(str_replace(["\r","\n","\0"], '', $name));
    return $name !== '' ? $name : 'submission';
}
function streamSubmissionFile(array $row, string $disposition="attachment"): void {
    $stored = basename((string)($row['stored_filename'] ?? ''));
    $activityId = (int)($row['activity_id'] ?? 0);
    $path = submissionRoot() . '/' . $activityId . '/' . $stored;
    if (!$stored || !is_file($path)) respond(['ok'=>false,'message'=>'Submission file is no longer available.'],404);
    $name = safeDownloadName((string)($row['original_filename'] ?? 'submission'));
    header_remove('Content-Type');
    header('Content-Type: ' . ((string)($row['mime_type'] ?? '') ?: 'application/octet-stream'));
    header('Content-Length: ' . filesize($path));
    $disposition = $disposition === 'inline' ? 'inline' : 'attachment';
    header("Content-Disposition: {$disposition}; filename=\"" . str_replace('\"','', $name) . "\"; filename*=UTF-8''" . rawurlencode($name));
    header('X-Content-Type-Options: nosniff');
    readfile($path);
    exit;
}
function makeClassCode(PDO $pdo, string $level, string $section): string {
    $base = strtolower(trim($level . '-' . $section));
    $base = preg_replace('/[^a-z0-9]+/i', '-', $base);
    $base = trim($base, '-');
    if ($base === '') $base = 'class';
    $code = $base; $n = 2;
    $q = $pdo->prepare("SELECT COUNT(*) FROM classes WHERE class_code=?");
    while (true) {
        $q->execute([$code]);
        if ((int)$q->fetchColumn() === 0) return $code;
        $code = $base . '-' . $n++;
    }
}

function defaultWeights(): array {
    return ['quiz'=>0.20,'exam'=>0.30,'performance'=>0.30,'output'=>0.20];
}
function normalizeWeightValue($value): float {
    $weight = (float)$value;
    if ($weight > 1) $weight = $weight / 100;
    return $weight;
}
function loadTermWeights(PDO $pdo, int $ccId, string $term): array {
    $weights = defaultWeights();
    $stmt = $pdo->prepare("SELECT category,weight FROM term_weights WHERE class_course_id=? AND term=?");
    $stmt->execute([$ccId,$term]);
    foreach ($stmt as $row) {
        $category = (string)$row['category'];
        if (array_key_exists($category, $weights)) $weights[$category] = normalizeWeightValue($row['weight']);
    }
    return $weights;
}

function termGrade(PDO $pdo, int $ccId, string $term, int $studentId): float {
    $weights = loadTermWeights($pdo, $ccId, $term);
    $stmt=$pdo->prepare("SELECT a.category, SUM(COALESCE(s.score,0)) raw_total, SUM(a.max_score) max_total
                        FROM assessments a
                        LEFT JOIN scores s ON s.assessment_id=a.id AND s.student_id=?
                        WHERE a.class_course_id=? AND a.term=? GROUP BY a.category");
    $stmt->execute([$studentId,$ccId,$term]);
    $eq=[]; foreach($stmt as $r){
        $eq[$r['category']] = (float)$r['max_total'] > 0 ? ((float)$r['raw_total']/(float)$r['max_total']*50+50) : 0;
    }
    $current=0.0; foreach(['quiz','exam','performance','output'] as $c) $current += ($eq[$c]??0)*($weights[$c]??0);
    if($term==='prelim') return round($current,2);
    if($term==='midterm') return round(termGrade($pdo,$ccId,'prelim',$studentId)*0.30 + $current*0.70,2);
    return round(termGrade($pdo,$ccId,'midterm',$studentId)*0.30 + $current*0.70,2);
}

$action = $_GET['action'] ?? 'bootstrap';

try {
    switch ($action) {
        case 'bootstrap':
            $classes=$pdo->query("SELECT id,class_code,class_name,program,year_level,section FROM classes ORDER BY class_name")->fetchAll();
            foreach($classes as &$c){
                $q=$pdo->prepare("SELECT cc.id class_course_id, co.id course_id, co.course_code, co.course_description, cc.semester, cc.school_year, cc.teacher_name,
                                         (SELECT COUNT(*) FROM class_course_students ccs WHERE ccs.class_course_id=cc.id) student_count
                                  FROM class_courses cc JOIN courses co ON co.id=cc.course_id WHERE cc.class_id=? ORDER BY co.course_description");
                $q->execute([$c['id']]); $c['courses']=$q->fetchAll();
                $n=$pdo->prepare("SELECT COUNT(*) FROM enrollments WHERE class_id=?");$n->execute([$c['id']]);$c['student_count']=(int)$n->fetchColumn();
            }
            $settings=[]; foreach($pdo->query("SELECT setting_key,setting_value FROM app_settings") as $r)$settings[$r['setting_key']]=$r['setting_value'];
            respond(['ok'=>true,'classes'=>$classes,'settings'=>$settings,'user'=>currentUserPublic()]);

        case 'gradebook':
            $ccId=(int)($_GET['class_course_id']??0); $term=$_GET['term']??'prelim';
            if(!$ccId) respond(['ok'=>false,'message'=>'class_course_id is required'],422);
            $ctx=$pdo->prepare("SELECT cc.*,c.class_name,c.class_code,co.course_code,co.course_description
                                FROM class_courses cc JOIN classes c ON c.id=cc.class_id JOIN courses co ON co.id=cc.course_id WHERE cc.id=?");
            $ctx->execute([$ccId]); $context=$ctx->fetch(); if(!$context) respond(['ok'=>false,'message'=>'Class course not found'],404);
            $st=$pdo->prepare("SELECT s.id,s.student_no,s.full_name,s.gender,s.email FROM class_course_students ccs JOIN students s ON s.id=ccs.student_id WHERE ccs.class_course_id=? ORDER BY CASE s.gender WHEN 'Male' THEN 1 WHEN 'Female' THEN 2 ELSE 3 END, s.full_name");
            $st->execute([$ccId]); $students=$st->fetchAll();
            $as=$pdo->prepare("SELECT id,category,title,max_score,assessment_date,sort_order FROM assessments WHERE class_course_id=? AND term=? ORDER BY FIELD(category,'quiz','exam','performance','output'),sort_order,id");
            $as->execute([$ccId,$term]); $assessments=$as->fetchAll();
            $sc=$pdo->prepare("SELECT s.assessment_id,s.student_id,s.score FROM scores s JOIN assessments a ON a.id=s.assessment_id WHERE a.class_course_id=? AND a.term=?");
            $sc->execute([$ccId,$term]); $scores=[]; foreach($sc as $r)$scores[$r['student_id']][$r['assessment_id']]=$r['score']===null?'':(float)$r['score'];
            $weights = loadTermWeights($pdo, $ccId, $term);
            $previous=[]; if($term!=='prelim') foreach($students as $s)$previous[$s['id']]=termGrade($pdo,$ccId,$term==='midterm'?'prelim':'midterm',(int)$s['id']);
            respond(['ok'=>true,'context'=>$context,'students'=>$students,'assessments'=>$assessments,'scores'=>$scores,'weights'=>$weights,'previous'=>$previous]);

        case 'top_performers':
            $ccId=(int)($_GET['class_course_id']??0);
            if(!$ccId) respond(['ok'=>false,'message'=>'class_course_id is required'],422);
            $ctx=$pdo->prepare("SELECT cc.id,cc.semester,cc.school_year,cc.teacher_name,c.class_name,c.class_code,co.course_code,co.course_description
                                FROM class_courses cc JOIN classes c ON c.id=cc.class_id JOIN courses co ON co.id=cc.course_id WHERE cc.id=?");
            $ctx->execute([$ccId]); $context=$ctx->fetch();
            if(!$context) respond(['ok'=>false,'message'=>'Subject assignment not found'],404);

            $st=$pdo->prepare("SELECT s.id,s.student_no,s.full_name,s.gender,s.email
                               FROM class_course_students ccs JOIN students s ON s.id=ccs.student_id
                               WHERE ccs.class_course_id=? ORDER BY s.full_name");
            $st->execute([$ccId]); $students=$st->fetchAll();

            $out=[];
            foreach($students as $s){
                $sid=(int)$s['id'];
                $prelim=termGrade($pdo,$ccId,'prelim',$sid);
                $midterm=termGrade($pdo,$ccId,'midterm',$sid);
                $final=termGrade($pdo,$ccId,'final',$sid);
                $overall=round(($prelim+$midterm+$final)/3,2);
                $out[]=[
                    'id'=>$sid,
                    'student_no'=>$s['student_no'],
                    'full_name'=>$s['full_name'],
                    'gender'=>$s['gender'],
                    'email'=>$s['email'],
                    'prelim'=>$prelim,
                    'midterm'=>$midterm,
                    'final'=>$final,
                    'overall'=>$overall
                ];
            }
            respond(['ok'=>true,'context'=>$context,'students'=>$out]);

        case 'save_score':
            if($_SERVER['REQUEST_METHOD']!=='POST') respond(['ok'=>false,'message'=>'POST required'],405);
            $d=body(); requireFields($d,['assessment_id','student_id']);
            $a=$pdo->prepare("SELECT max_score FROM assessments WHERE id=?");$a->execute([(int)$d['assessment_id']]);$max=$a->fetchColumn();if($max===false)respond(['ok'=>false,'message'=>'Assessment not found'],404);
            $score=($d['score']===''||$d['score']===null)?null:(float)$d['score'];
            if($score!==null)$score=max(0,min((float)$max,$score));
            $q=$pdo->prepare("INSERT INTO scores(assessment_id,student_id,score) VALUES(?,?,?) ON DUPLICATE KEY UPDATE score=VALUES(score)");
            $q->execute([(int)$d['assessment_id'],(int)$d['student_id'],$score]); respond(['ok'=>true,'score'=>$score]);

        case 'assessment_bulk_scores':
            if($_SERVER['REQUEST_METHOD']!=='POST') respond(['ok'=>false,'message'=>'POST required'],405);
            $d=body(); requireFields($d,['class_course_id','term','rows']);
            $ccId=(int)$d['class_course_id']; $term=(string)$d['term']; $rows=$d['rows'];
            if(!in_array($term,['prelim','midterm','final'],true)) respond(['ok'=>false,'message'=>'Invalid grading term'],422);
            if(!is_array($rows) || count($rows)===0) respond(['ok'=>false,'message'=>'No assessment rows supplied for import'],422);
            if(count($rows)>2000) respond(['ok'=>false,'message'=>'Maximum bulk score upload is 2,000 students at a time'],422);

            $ctx=$pdo->prepare("SELECT cc.id,cc.class_id,co.course_code,co.course_description,c.class_name FROM class_courses cc JOIN courses co ON co.id=cc.course_id JOIN classes c ON c.id=cc.class_id WHERE cc.id=?");
            $ctx->execute([$ccId]); $context=$ctx->fetch();
            if(!$context) respond(['ok'=>false,'message'=>'Subject assignment not found'],404);

            $aq=$pdo->prepare("SELECT id,category,title,max_score FROM assessments WHERE class_course_id=? AND term=? ORDER BY FIELD(category,'quiz','exam','performance','output'),sort_order,id");
            $aq->execute([$ccId,$term]); $assessmentRows=$aq->fetchAll();
            if(!$assessmentRows) respond(['ok'=>false,'message'=>'This subject and term has no assessments yet. Add assessments first.'],422);
            $assessments=[]; foreach($assessmentRows as $a) $assessments[(int)$a['id']]=$a;

            $studentLookup=$pdo->prepare("SELECT s.id,s.full_name FROM class_course_students ccs JOIN students s ON s.id=ccs.student_id WHERE ccs.class_course_id=? AND s.student_no=? LIMIT 1");
            $upsert=$pdo->prepare("INSERT INTO scores(assessment_id,student_id,score) VALUES(?,?,?) ON DUPLICATE KEY UPDATE score=VALUES(score)");
            $saved=0; $studentsProcessed=0; $studentsSkipped=0; $cellsSkipped=0; $errors=[]; $seen=[];
            $pdo->beginTransaction();
            foreach($rows as $i=>$row){
                $line=(int)($row['row']??($i+2));
                if(!is_array($row)){ $studentsSkipped++; $errors[]=['row'=>$line,'message'=>'Invalid row']; continue; }
                $studentNo=trim((string)($row['student_no']??''));
                if($studentNo===''){ $studentsSkipped++; $errors[]=['row'=>$line,'message'=>'Student ID is required']; continue; }
                $key=strtolower($studentNo);
                if(isset($seen[$key])){ $studentsSkipped++; $errors[]=['row'=>$line,'message'=>'Duplicate Student ID in upload']; continue; }
                $seen[$key]=true;
                $studentLookup->execute([$ccId,$studentNo]); $student=$studentLookup->fetch();
                if(!$student){ $studentsSkipped++; $errors[]=['row'=>$line,'message'=>'Student is not assigned to the selected subject']; continue; }
                $scores=$row['scores']??[];
                if(!is_array($scores)){ $studentsSkipped++; $errors[]=['row'=>$line,'message'=>'Scores must be supplied as assessment columns']; continue; }
                $rowSaved=0;
                foreach($scores as $assessmentId=>$value){
                    $assessmentId=(int)$assessmentId;
                    if(!isset($assessments[$assessmentId])){ $cellsSkipped++; continue; }
                    if($value==='' || $value===null) continue; // Blank cells preserve existing scores.
                    if(!is_numeric($value)){ $cellsSkipped++; $errors[]=['row'=>$line,'assessment'=>$assessments[$assessmentId]['title'],'message'=>'Score is not numeric']; continue; }
                    $score=(float)$value; $max=(float)$assessments[$assessmentId]['max_score'];
                    if($score<0 || $score>$max){ $cellsSkipped++; $errors[]=['row'=>$line,'assessment'=>$assessments[$assessmentId]['title'],'message'=>"Score must be between 0 and $max"]; continue; }
                    $upsert->execute([$assessmentId,(int)$student['id'],$score]); $saved++; $rowSaved++;
                }
                if($rowSaved>0) $studentsProcessed++; else { $studentsSkipped++; if(empty($scores)) $errors[]=['row'=>$line,'message'=>'No recognized assessment scores in this row']; }
            }
            $pdo->commit();
            respond(['ok'=>true,'saved_scores'=>$saved,'students_processed'=>$studentsProcessed,'students_skipped'=>$studentsSkipped,'cells_skipped'=>$cellsSkipped,'errors'=>array_slice($errors,0,100),'subject'=>['course_code'=>$context['course_code'],'description'=>$context['course_description'],'class_name'=>$context['class_name']],'term'=>$term]);

        case 'fill_missing':
            if($_SERVER['REQUEST_METHOD']!=='POST') respond(['ok'=>false,'message'=>'POST required'],405);
            $d=body(); requireFields($d,['class_course_id','term']);
            $q=$pdo->prepare("INSERT INTO scores(assessment_id,student_id,score)
                SELECT a.id,ccs.student_id,0 FROM assessments a
                JOIN class_course_students ccs ON ccs.class_course_id=a.class_course_id
                LEFT JOIN scores s ON s.assessment_id=a.id AND s.student_id=ccs.student_id
                WHERE a.class_course_id=? AND a.term=? AND (s.id IS NULL OR s.score IS NULL)
                ON DUPLICATE KEY UPDATE score=0");
            $q->execute([(int)$d['class_course_id'],$d['term']]); respond(['ok'=>true]);

        case 'assessment_create':
            if($_SERVER['REQUEST_METHOD']!=='POST') respond(['ok'=>false,'message'=>'POST required'],405);
            $d=body(); requireFields($d,['class_course_id','term','category','title','max_score']);
            $sort=$pdo->prepare("SELECT COALESCE(MAX(sort_order),0)+1 FROM assessments WHERE class_course_id=? AND term=? AND category=?");
            $sort->execute([(int)$d['class_course_id'],$d['term'],$d['category']]);$order=(int)$sort->fetchColumn();
            $q=$pdo->prepare("INSERT INTO assessments(class_course_id,term,category,title,max_score,assessment_date,sort_order) VALUES(?,?,?,?,?,?,?)");
            $q->execute([(int)$d['class_course_id'],$d['term'],$d['category'],trim($d['title']),(float)$d['max_score'],($d['assessment_date'] ?? '') ?: null,$order]);
            respond(['ok'=>true,'id'=>(int)$pdo->lastInsertId()]);

        case 'assessment_delete':
            if($_SERVER['REQUEST_METHOD']!=='POST') respond(['ok'=>false,'message'=>'POST required'],405);
            $d=body();requireFields($d,['assessment_id']);$assessmentId=(int)$d['assessment_id'];
            $linked=$pdo->prepare("SELECT id FROM output_activities WHERE assessment_id=? LIMIT 1");$linked->execute([$assessmentId]);$activityId=(int)($linked->fetchColumn()?:0);
            if($activityId){$dir=submissionRoot().'/'.$activityId;if(is_dir($dir)){foreach(glob($dir.'/*')?:[] as $f)if(is_file($f))@unlink($f);@rmdir($dir);}}
            $q=$pdo->prepare("DELETE FROM assessments WHERE id=?");$q->execute([$assessmentId]);respond(['ok'=>true]);

        case 'activities':
            $ccId=(int)($_GET['class_course_id']??0); $term=(string)($_GET['term']??'prelim');
            if(!$ccId) respond(['ok'=>false,'message'=>'class_course_id is required'],422);
            if(!in_array($term,['prelim','midterm','final'],true)) respond(['ok'=>false,'message'=>'Invalid grading term'],422);
            $ctx=$pdo->prepare("SELECT cc.id,cc.semester,cc.school_year,cc.teacher_name,c.class_name,c.class_code,co.course_code,co.course_description FROM class_courses cc JOIN classes c ON c.id=cc.class_id JOIN courses co ON co.id=cc.course_id WHERE cc.id=?");
            $ctx->execute([$ccId]);$context=$ctx->fetch();if(!$context)respond(['ok'=>false,'message'=>'Subject assignment not found'],404);
            $q=$pdo->prepare("SELECT oa.id,oa.assessment_id,oa.instructions,oa.due_at,oa.allow_late,oa.is_published,oa.created_at,
                                    a.term,a.category,a.title,a.max_score,a.assessment_date,
                                    (SELECT COUNT(*) FROM class_course_students ccs WHERE ccs.class_course_id=a.class_course_id) assigned_count,
                                    (SELECT COUNT(*) FROM output_submissions os WHERE os.activity_id=oa.id) submitted_count,
                                    (SELECT COUNT(*) FROM output_submissions os2 JOIN scores sc2 ON sc2.assessment_id=a.id AND sc2.student_id=os2.student_id WHERE os2.activity_id=oa.id AND sc2.score IS NOT NULL) graded_count
                             FROM output_activities oa JOIN assessments a ON a.id=oa.assessment_id
                             WHERE a.class_course_id=? AND a.term=? ORDER BY COALESCE(oa.due_at,'9999-12-31 23:59:59'),oa.id DESC");
            $q->execute([$ccId,$term]);respond(['ok'=>true,'context'=>$context,'activities'=>$q->fetchAll()]);

        case 'activity_create':
            if($_SERVER['REQUEST_METHOD']!=='POST') respond(['ok'=>false,'message'=>'POST required'],405);
            $d=body();requireFields($d,['class_course_id','term','category','title','max_score']);
            $ccId=(int)$d['class_course_id'];$term=(string)$d['term'];$category=(string)$d['category'];$title=trim((string)$d['title']);$max=(float)$d['max_score'];
            if(!in_array($term,['prelim','midterm','final'],true)||!in_array($category,['quiz','exam','performance','output'],true))respond(['ok'=>false,'message'=>'Invalid term or grading category'],422);
            if($title===''||$max<=0)respond(['ok'=>false,'message'=>'Activity title and valid HPS are required'],422);
            $exists=$pdo->prepare("SELECT COUNT(*) FROM class_courses WHERE id=?");$exists->execute([$ccId]);if(!(int)$exists->fetchColumn())respond(['ok'=>false,'message'=>'Subject assignment not found'],404);
            $due=trim((string)($d['due_at']??''));$due=$due!==''?str_replace('T',' ',$due):null;if($due&&strlen($due)===16)$due.=':00';
            $pdo->beginTransaction();
            $sort=$pdo->prepare("SELECT COALESCE(MAX(sort_order),0)+1 FROM assessments WHERE class_course_id=? AND term=? AND category=?");$sort->execute([$ccId,$term,$category]);$order=(int)$sort->fetchColumn();
            $assessmentDate=$due?substr($due,0,10):date('Y-m-d');
            $a=$pdo->prepare("INSERT INTO assessments(class_course_id,term,category,title,max_score,assessment_date,sort_order) VALUES(?,?,?,?,?,?,?)");$a->execute([$ccId,$term,$category,$title,$max,$assessmentDate,$order]);$assessmentId=(int)$pdo->lastInsertId();
            $o=$pdo->prepare("INSERT INTO output_activities(assessment_id,instructions,due_at,allow_late,is_published,created_by) VALUES(?,?,?,?,?,?)");
            $o->execute([$assessmentId,trim((string)($d['instructions']??''))?:null,$due,!empty($d['allow_late'])?1:0,!empty($d['is_published'])?1:0,(int)$authUser['id']]);$activityId=(int)$pdo->lastInsertId();
            $pdo->commit();respond(['ok'=>true,'activity_id'=>$activityId,'assessment_id'=>$assessmentId]);

        case 'activity_toggle_publish':
            if($_SERVER['REQUEST_METHOD']!=='POST') respond(['ok'=>false,'message'=>'POST required'],405);
            $d=body();requireFields($d,['activity_id','is_published']);$q=$pdo->prepare("UPDATE output_activities SET is_published=? WHERE id=?");$q->execute([!empty($d['is_published'])?1:0,(int)$d['activity_id']]);respond(['ok'=>true]);

        case 'activity_delete':
            if($_SERVER['REQUEST_METHOD']!=='POST') respond(['ok'=>false,'message'=>'POST required'],405);
            $d=body();requireFields($d,['activity_id']);$q=$pdo->prepare("SELECT assessment_id FROM output_activities WHERE id=?");$q->execute([(int)$d['activity_id']]);$assessmentId=(int)($q->fetchColumn()?:0);if(!$assessmentId)respond(['ok'=>false,'message'=>'Activity not found'],404);
            $dir=submissionRoot().'/'.(int)$d['activity_id'];if(is_dir($dir)){foreach(glob($dir.'/*')?:[] as $f)if(is_file($f))@unlink($f);@rmdir($dir);} $del=$pdo->prepare("DELETE FROM assessments WHERE id=?");$del->execute([$assessmentId]);respond(['ok'=>true]);

        case 'activity_submissions':
            $activityId=(int)($_GET['activity_id']??0);if(!$activityId)respond(['ok'=>false,'message'=>'activity_id is required'],422);
            $a=$pdo->prepare("SELECT oa.id,oa.assessment_id,oa.instructions,oa.due_at,oa.allow_late,oa.is_published,a.class_course_id,a.term,a.category,a.title,a.max_score,c.class_name,co.course_code,co.course_description FROM output_activities oa JOIN assessments a ON a.id=oa.assessment_id JOIN class_courses cc ON cc.id=a.class_course_id JOIN classes c ON c.id=cc.class_id JOIN courses co ON co.id=cc.course_id WHERE oa.id=?");$a->execute([$activityId]);$activity=$a->fetch();if(!$activity)respond(['ok'=>false,'message'=>'Activity not found'],404);
            $q=$pdo->prepare("SELECT s.id student_id,s.student_no,s.full_name,s.gender,os.id submission_id,os.original_filename,os.mime_type,os.file_size,os.submission_note,os.submitted_at,os.teacher_feedback,os.graded_at,sc.score FROM class_course_students ccs JOIN students s ON s.id=ccs.student_id LEFT JOIN output_submissions os ON os.activity_id=? AND os.student_id=s.id LEFT JOIN scores sc ON sc.assessment_id=? AND sc.student_id=s.id WHERE ccs.class_course_id=? ORDER BY CASE s.gender WHEN 'Male' THEN 1 WHEN 'Female' THEN 2 ELSE 3 END,s.full_name");
            $q->execute([$activityId,(int)$activity['assessment_id'],(int)$activity['class_course_id']]);respond(['ok'=>true,'activity'=>$activity,'students'=>$q->fetchAll()]);

        case 'submission_grade':
            if($_SERVER['REQUEST_METHOD']!=='POST') respond(['ok'=>false,'message'=>'POST required'],405);
            $d=body();requireFields($d,['activity_id','student_id','score']);$activityId=(int)$d['activity_id'];$studentId=(int)$d['student_id'];$score=(float)$d['score'];
            $q=$pdo->prepare("SELECT oa.assessment_id,a.max_score FROM output_activities oa JOIN assessments a ON a.id=oa.assessment_id JOIN output_submissions os ON os.activity_id=oa.id AND os.student_id=? WHERE oa.id=?");$q->execute([$studentId,$activityId]);$row=$q->fetch();if(!$row)respond(['ok'=>false,'message'=>'Student submission not found'],404);$max=(float)$row['max_score'];if($score<0||$score>$max)respond(['ok'=>false,'message'=>"Score must be from 0 to $max"],422);
            $pdo->beginTransaction();$sc=$pdo->prepare("INSERT INTO scores(assessment_id,student_id,score) VALUES(?,?,?) ON DUPLICATE KEY UPDATE score=VALUES(score)");$sc->execute([(int)$row['assessment_id'],$studentId,$score]);$up=$pdo->prepare("UPDATE output_submissions SET teacher_feedback=?,graded_at=NOW() WHERE activity_id=? AND student_id=?");$up->execute([trim((string)($d['teacher_feedback']??''))?:null,$activityId,$studentId]);$pdo->commit();respond(['ok'=>true]);

        case 'submission_download':
            $submissionId=(int)($_GET['submission_id']??0);if(!$submissionId)respond(['ok'=>false,'message'=>'submission_id is required'],422);
            $q=$pdo->prepare("SELECT os.id,os.activity_id,os.original_filename,os.stored_filename,os.mime_type FROM output_submissions os WHERE os.id=?");$q->execute([$submissionId]);$row=$q->fetch();if(!$row)respond(['ok'=>false,'message'=>'Submission not found'],404);streamSubmissionFile($row,'attachment');

        case 'submission_inline':
            $submissionId=(int)($_GET['submission_id']??0);if(!$submissionId)respond(['ok'=>false,'message'=>'submission_id is required'],422);
            $q=$pdo->prepare("SELECT os.id,os.activity_id,os.original_filename,os.stored_filename,os.mime_type FROM output_submissions os WHERE os.id=?");$q->execute([$submissionId]);$row=$q->fetch();if(!$row)respond(['ok'=>false,'message'=>'Submission not found'],404);streamSubmissionFile($row,'inline');

        case 'subjects':
            $q=$pdo->query("SELECT cc.id subject_assignment_id,co.id course_id,co.course_code subject,co.course_description description,
                                   c.id class_id,c.class_name,c.year_level level,c.section,cc.semester,cc.school_year,cc.teacher_name,
                                   (SELECT COUNT(*) FROM class_course_students ccs WHERE ccs.class_course_id=cc.id) student_count
                            FROM class_courses cc
                            JOIN courses co ON co.id=cc.course_id
                            JOIN classes c ON c.id=cc.class_id
                            ORDER BY c.year_level,c.section,co.course_code");
            respond(['ok'=>true,'subjects'=>$q->fetchAll()]);

        case 'subject_create':
            if($_SERVER['REQUEST_METHOD']!=='POST') respond(['ok'=>false,'message'=>'POST required'],405);
            $d=body(); requireFields($d,['subject','description','level','section']);
            $subject=trim($d['subject']); $description=trim($d['description']); $level=trim($d['level']); $section=trim($d['section']);
            $semester=trim($d['semester']??'') ?: '1st Semester';
            $schoolYear=trim($d['school_year']??'') ?: '2026-2027';
            $teacher=trim($d['teacher_name']??'') ?: 'CKC Faculty';
            if(strlen($subject)>40) respond(['ok'=>false,'message'=>'Subject must be 40 characters or fewer'],422);
            $pdo->beginTransaction();
            $cq=$pdo->prepare("SELECT id FROM courses WHERE course_code=? LIMIT 1"); $cq->execute([$subject]); $courseId=(int)($cq->fetchColumn()?:0);
            if($courseId){
                $u=$pdo->prepare("UPDATE courses SET course_description=? WHERE id=?"); $u->execute([$description,$courseId]);
            } else {
                $i=$pdo->prepare("INSERT INTO courses(course_code,course_description) VALUES(?,?)"); $i->execute([$subject,$description]); $courseId=(int)$pdo->lastInsertId();
            }
            $cl=$pdo->prepare("SELECT id FROM classes WHERE year_level=? AND section=? LIMIT 1"); $cl->execute([$level,$section]); $classId=(int)($cl->fetchColumn()?:0);
            if(!$classId){
                $className=trim($level.' - '.$section); $classCode=makeClassCode($pdo,$level,$section);
                $i=$pdo->prepare("INSERT INTO classes(class_code,class_name,program,year_level,section) VALUES(?,?,?,?,?)");
                $i->execute([$classCode,$className,null,$level,$section]); $classId=(int)$pdo->lastInsertId();
            }
            $existing=$pdo->prepare("SELECT id FROM class_courses WHERE class_id=? AND course_id=? AND semester=? AND school_year=? LIMIT 1");
            $existing->execute([$classId,$courseId,$semester,$schoolYear]); $ccId=(int)($existing->fetchColumn()?:0);
            $alreadyExists=$ccId>0;
            if(!$ccId){
                $i=$pdo->prepare("INSERT INTO class_courses(class_id,course_id,semester,school_year,teacher_name) VALUES(?,?,?,?,?)");
                $i->execute([$classId,$courseId,$semester,$schoolYear,$teacher]); $ccId=(int)$pdo->lastInsertId();
                $w=$pdo->prepare("INSERT INTO term_weights(class_course_id,term,category,weight) VALUES(?,?,?,?)");
                $weights=['quiz'=>0.20,'exam'=>0.30,'performance'=>0.30,'output'=>0.20];
                foreach(['prelim','midterm','final'] as $term) foreach($weights as $category=>$weight) $w->execute([$ccId,$term,$category,$weight]);
            }
            $pdo->commit();
            respond(['ok'=>true,'class_id'=>$classId,'course_id'=>$courseId,'class_course_id'=>$ccId,'already_exists'=>$alreadyExists]);

        case 'subject_delete':
            if($_SERVER['REQUEST_METHOD']!=='POST') respond(['ok'=>false,'message'=>'POST required'],405);
            $d=body(); requireFields($d,['class_course_id']); $ccId=(int)$d['class_course_id'];
            $get=$pdo->prepare("SELECT class_id,course_id FROM class_courses WHERE id=?"); $get->execute([$ccId]); $row=$get->fetch();
            if(!$row) respond(['ok'=>false,'message'=>'Subject assignment not found'],404);
            $assigned=$pdo->prepare("SELECT student_id FROM class_course_students WHERE class_course_id=?"); $assigned->execute([$ccId]); $assignedStudentIds=$assigned->fetchAll(PDO::FETCH_COLUMN);
            $activityQ=$pdo->prepare("SELECT oa.id FROM output_activities oa JOIN assessments a ON a.id=oa.assessment_id WHERE a.class_course_id=?");$activityQ->execute([$ccId]);$activityIds=$activityQ->fetchAll(PDO::FETCH_COLUMN);
            $pdo->beginTransaction();
            $del=$pdo->prepare("DELETE FROM class_courses WHERE id=?"); $del->execute([$ccId]);
            $remaining=$pdo->prepare("SELECT COUNT(*) FROM class_course_students ccs JOIN class_courses cc ON cc.id=ccs.class_course_id WHERE cc.class_id=? AND ccs.student_id=?");
            $removeClassEnrollment=$pdo->prepare("DELETE FROM enrollments WHERE class_id=? AND student_id=?");
            foreach($assignedStudentIds as $studentId){ $remaining->execute([(int)$row['class_id'],(int)$studentId]); if((int)$remaining->fetchColumn()===0) $removeClassEnrollment->execute([(int)$row['class_id'],(int)$studentId]); }
            $q=$pdo->prepare("SELECT COUNT(*) FROM class_courses WHERE course_id=?"); $q->execute([(int)$row['course_id']]);
            if((int)$q->fetchColumn()===0){$x=$pdo->prepare("DELETE FROM courses WHERE id=?");$x->execute([(int)$row['course_id']]);}
            $q=$pdo->prepare("SELECT (SELECT COUNT(*) FROM class_courses WHERE class_id=?) + (SELECT COUNT(*) FROM enrollments WHERE class_id=?)");
            $q->execute([(int)$row['class_id'],(int)$row['class_id']]);
            if((int)$q->fetchColumn()===0){$x=$pdo->prepare("DELETE FROM classes WHERE id=?");$x->execute([(int)$row['class_id']]);}
            $pdo->commit();
            foreach($activityIds as $aid){$dir=submissionRoot().'/'.(int)$aid;if(is_dir($dir)){foreach(glob($dir.'/*')?:[] as $f)if(is_file($f))@unlink($f);@rmdir($dir);}}
            respond(['ok'=>true]);

        case 'students':
            $ccId=(int)($_GET['class_course_id']??0); if(!$ccId) respond(['ok'=>false,'message'=>'class_course_id required'],422);
            $q=$pdo->prepare("SELECT s.id,s.student_no,s.full_name,s.gender,s.email FROM class_course_students ccs JOIN students s ON s.id=ccs.student_id WHERE ccs.class_course_id=? ORDER BY CASE s.gender WHEN 'Male' THEN 1 WHEN 'Female' THEN 2 ELSE 3 END, s.full_name");
            $q->execute([$ccId]); respond(['ok'=>true,'students'=>$q->fetchAll()]);

        case 'student_create':
            if($_SERVER['REQUEST_METHOD']!=='POST') respond(['ok'=>false,'message'=>'POST required'],405);
            $d=body(); requireFields($d,['class_course_id','student_no','full_name']); $ccId=(int)$d['class_course_id'];
            $cc=$pdo->prepare("SELECT class_id FROM class_courses WHERE id=?"); $cc->execute([$ccId]); $classId=(int)($cc->fetchColumn()?:0);
            if(!$classId) respond(['ok'=>false,'message'=>'Subject assignment not found'],404);
            $studentNo=trim($d['student_no']); $fullName=trim($d['full_name']); $gender=normalizeGender($d['gender']??''); $email=trim($d['email']??'')?:null;
            if(!$gender) respond(['ok'=>false,'message'=>'Gender must be Male or Female'],422);
            $pdo->beginTransaction();
            $find=$pdo->prepare("SELECT id FROM students WHERE student_no=? LIMIT 1"); $find->execute([$studentNo]); $sid=(int)($find->fetchColumn()?:0);
            if($sid){
                $q=$pdo->prepare("UPDATE students SET full_name=?,gender=?,email=COALESCE(NULLIF(?,''),email) WHERE id=?"); $q->execute([$fullName,$gender,$email,$sid]);
            } else {
                $q=$pdo->prepare("INSERT INTO students(student_no,full_name,gender,email) VALUES(?,?,?,?)"); $q->execute([$studentNo,$fullName,$gender,$email]); $sid=(int)$pdo->lastInsertId();
            }
            $e=$pdo->prepare("INSERT IGNORE INTO enrollments(class_id,student_id) VALUES(?,?)"); $e->execute([$classId,$sid]);
            $se=$pdo->prepare("INSERT IGNORE INTO class_course_students(class_course_id,student_id) VALUES(?,?)"); $se->execute([$ccId,$sid]);
            $pdo->commit(); respond(['ok'=>true,'student_id'=>$sid]);

        case 'student_update':
            if($_SERVER['REQUEST_METHOD']!=='POST') respond(['ok'=>false,'message'=>'POST required'],405);
            $d=body(); requireFields($d,['student_id','student_no','full_name','gender']);
            $studentId=(int)$d['student_id']; $studentNo=trim((string)$d['student_no']); $fullName=trim((string)$d['full_name']);
            $gender=normalizeGender($d['gender']); $email=trim((string)($d['email']??''))?:null;
            if(!$studentId) respond(['ok'=>false,'message'=>'Student not found'],404);
            if(!$gender) respond(['ok'=>false,'message'=>'Gender must be Male or Female'],422);
            if($email!==null && !filter_var($email,FILTER_VALIDATE_EMAIL)) respond(['ok'=>false,'message'=>'Invalid email address'],422);
            $dup=$pdo->prepare("SELECT id FROM students WHERE student_no=? AND id<>? LIMIT 1");$dup->execute([$studentNo,$studentId]);
            if($dup->fetchColumn()) respond(['ok'=>false,'message'=>'Student ID is already used by another student'],409);
            $q=$pdo->prepare("UPDATE students SET student_no=?,full_name=?,gender=?,email=? WHERE id=?");
            $q->execute([$studentNo,$fullName,$gender,$email,$studentId]);
            if($q->rowCount()===0){
                $check=$pdo->prepare("SELECT id FROM students WHERE id=?");$check->execute([$studentId]);
                if(!$check->fetchColumn()) respond(['ok'=>false,'message'=>'Student not found'],404);
            }
            respond(['ok'=>true]);

        case 'student_bulk_create':
            if($_SERVER['REQUEST_METHOD']!=='POST') respond(['ok'=>false,'message'=>'POST required'],405);
            $d=body(); requireFields($d,['class_course_id','students']); $ccId=(int)$d['class_course_id']; $rows=$d['students'];
            if(!is_array($rows) || count($rows)===0) respond(['ok'=>false,'message'=>'No students supplied for import'],422);
            if(count($rows)>2000) respond(['ok'=>false,'message'=>'Maximum bulk upload is 2,000 students at a time'],422);
            $cc=$pdo->prepare("SELECT cc.class_id,co.course_code,co.course_description,c.class_name FROM class_courses cc JOIN courses co ON co.id=cc.course_id JOIN classes c ON c.id=cc.class_id WHERE cc.id=?");
            $cc->execute([$ccId]); $context=$cc->fetch(); if(!$context) respond(['ok'=>false,'message'=>'Subject assignment not found'],404);
            $created=0; $updated=0; $assigned=0; $alreadyAssigned=0; $skipped=0; $duplicatesSkipped=0; $errors=[]; $seenIds=[]; $seenPairs=[];
            $find=$pdo->prepare("SELECT id FROM students WHERE student_no=? LIMIT 1");
            $insertStudent=$pdo->prepare("INSERT INTO students(student_no,full_name,gender,email) VALUES(?,?,?,?)");
            $updateStudent=$pdo->prepare("UPDATE students SET full_name=?,gender=?,email=COALESCE(NULLIF(?,''),email) WHERE id=?");
            $classEnroll=$pdo->prepare("INSERT IGNORE INTO enrollments(class_id,student_id) VALUES(?,?)");
            $subjectEnroll=$pdo->prepare("INSERT IGNORE INTO class_course_students(class_course_id,student_id) VALUES(?,?)");
            $pdo->beginTransaction();
            foreach($rows as $i=>$row){
                $line=$i+2;
                if(!is_array($row)){ $skipped++; $errors[]=['row'=>$line,'message'=>'Invalid row']; continue; }
                $studentNo=trim((string)($row['student_no']??'')); $fullName=trim((string)($row['full_name']??'')); $gender=normalizeGender($row['gender']??''); $email=trim((string)($row['email']??''));
                if($studentNo==='' || $fullName==='' || !$gender){ $skipped++; $errors[]=['row'=>$line,'message'=>'Student ID, Full Name, and Gender (Male/Female) are required']; continue; }
                if(strlen($studentNo)>50 || strlen($fullName)>180 || strlen($email)>180){ $skipped++; $errors[]=['row'=>$line,'message'=>'A field exceeds the allowed length']; continue; }
                $idKey=strtolower($studentNo);
                $nameKey=strtolower(preg_replace('/\s+/', ' ', trim($fullName)));
                $pairKey=$idKey."\0".$nameKey;
                if(isset($seenPairs[$pairKey])){ $duplicatesSkipped++; $errors[]=['row'=>$line,'message'=>'Duplicate Student ID + Full Name in upload; first occurrence kept']; continue; }
                if(isset($seenIds[$idKey]) && $seenIds[$idKey]!==$nameKey){ $duplicatesSkipped++; $errors[]=['row'=>$line,'message'=>'Duplicate Student ID with a different name; first occurrence kept']; continue; }
                $seenPairs[$pairKey]=true; $seenIds[$idKey]=$nameKey;
                if($email!=='' && !filter_var($email,FILTER_VALIDATE_EMAIL)){ $skipped++; $errors[]=['row'=>$line,'message'=>'Invalid email address']; continue; }
                $find->execute([$studentNo]); $sid=(int)($find->fetchColumn()?:0);
                if($sid){ $updateStudent->execute([$fullName,$gender,$email,$sid]); $updated++; }
                else { $insertStudent->execute([$studentNo,$fullName,$gender,$email?:null]); $sid=(int)$pdo->lastInsertId(); $created++; }
                $classEnroll->execute([(int)$context['class_id'],$sid]);
                $subjectEnroll->execute([$ccId,$sid]);
                if($subjectEnroll->rowCount()>0) $assigned++; else $alreadyAssigned++;
            }
            $pdo->commit();
            respond(['ok'=>true,'created'=>$created,'updated'=>$updated,'assigned'=>$assigned,'already_assigned'=>$alreadyAssigned,'duplicates_skipped'=>$duplicatesSkipped,'skipped'=>$skipped,'processed'=>count($rows),'errors'=>$errors,'subject'=>['course_code'=>$context['course_code'],'description'=>$context['course_description'],'class_name'=>$context['class_name']]]);

        case 'student_remove':
            if($_SERVER['REQUEST_METHOD']!=='POST') respond(['ok'=>false,'message'=>'POST required'],405);
            $d=body(); requireFields($d,['class_course_id','student_id']); $ccId=(int)$d['class_course_id']; $studentId=(int)$d['student_id'];
            $cc=$pdo->prepare("SELECT class_id FROM class_courses WHERE id=?"); $cc->execute([$ccId]); $classId=(int)($cc->fetchColumn()?:0);
            if(!$classId) respond(['ok'=>false,'message'=>'Subject assignment not found'],404);
            $pdo->beginTransaction();
            $q=$pdo->prepare("DELETE FROM class_course_students WHERE class_course_id=? AND student_id=?"); $q->execute([$ccId,$studentId]);
            $remaining=$pdo->prepare("SELECT COUNT(*) FROM class_course_students ccs JOIN class_courses cc ON cc.id=ccs.class_course_id WHERE cc.class_id=? AND ccs.student_id=?");
            $remaining->execute([$classId,$studentId]);
            if((int)$remaining->fetchColumn()===0){ $x=$pdo->prepare("DELETE FROM enrollments WHERE class_id=? AND student_id=?"); $x->execute([$classId,$studentId]); }
            $pdo->commit(); respond(['ok'=>true]);

        case 'attendance':
            $ccId=(int)($_GET['class_course_id']??0);$date=$_GET['date']??date('Y-m-d');if(!$ccId)respond(['ok'=>false,'message'=>'class_course_id required'],422);
            $q=$pdo->prepare("SELECT s.id,s.student_no,s.full_name,s.gender,COALESCE(a.status,'Unmarked') status,COALESCE(a.remarks,'') remarks
                             FROM class_courses cc JOIN class_course_students ccs ON ccs.class_course_id=cc.id JOIN students s ON s.id=ccs.student_id
                             LEFT JOIN attendance a ON a.class_course_id=cc.id AND a.student_id=s.id AND a.attendance_date=?
                             WHERE cc.id=? ORDER BY CASE s.gender WHEN 'Male' THEN 1 WHEN 'Female' THEN 2 ELSE 3 END, s.full_name");$q->execute([$date,$ccId]);respond(['ok'=>true,'date'=>$date,'records'=>$q->fetchAll()]);

        case 'attendance_save':
            if($_SERVER['REQUEST_METHOD']!=='POST') respond(['ok'=>false,'message'=>'POST required'],405);
            $d=body();requireFields($d,['class_course_id','student_id','date','status']);
            $allowed=['Present','Late','Absent','Excused'];
            if(!in_array($d['status'],$allowed,true)) respond(['ok'=>false,'message'=>'Invalid attendance status'],422);
            $q=$pdo->prepare("INSERT INTO attendance(class_course_id,student_id,attendance_date,status,remarks) VALUES(?,?,?,?,?)
                              ON DUPLICATE KEY UPDATE status=VALUES(status),remarks=VALUES(remarks)");
            $q->execute([(int)$d['class_course_id'],(int)$d['student_id'],$d['date'],$d['status'],trim($d['remarks']??'')?:null]);respond(['ok'=>true]);


        case 'accounts':
            $users=$pdo->query("SELECT u.id,u.username,u.role,u.display_name,u.student_id,u.is_active,u.must_change_password,u.last_login,u.created_at,s.student_no,s.full_name student_name,
                                      pr.id reset_request_id,pr.requested_at reset_requested_at,aus.last_seen_at active_last_seen,aus.device_type active_device,aus.browser active_browser
                               FROM users u
                               LEFT JOIN students s ON s.id=u.student_id
                               LEFT JOIN active_user_sessions aus ON aus.user_id=u.id
                               LEFT JOIN password_reset_requests pr ON pr.id=(
                                  SELECT pr2.id FROM password_reset_requests pr2 WHERE pr2.user_id=u.id AND pr2.status='pending' ORDER BY pr2.requested_at DESC,pr2.id DESC LIMIT 1
                               )
                               ORDER BY (pr.id IS NULL),FIELD(u.role,'teacher','student'),u.display_name")->fetchAll();
            $students=$pdo->query("SELECT s.id,s.student_no,s.full_name,s.gender,s.email,u.id account_id,u.username account_username FROM students s LEFT JOIN users u ON u.student_id=s.id AND u.role='student' ORDER BY CASE s.gender WHEN 'Male' THEN 1 WHEN 'Female' THEN 2 ELSE 3 END,s.full_name")->fetchAll();
            $pending=(int)$pdo->query("SELECT COUNT(*) FROM password_reset_requests WHERE status='pending'")->fetchColumn();
            respond(['ok'=>true,'users'=>$users,'students'=>$students,'current_user_id'=>(int)$authUser['id'],'pending_reset_count'=>$pending]);

        case 'account_create':
            if($_SERVER['REQUEST_METHOD']!=='POST') respond(['ok'=>false,'message'=>'POST required'],405);
            $d=body(); requireFields($d,['role','username','display_name','password']);
            $role=(string)$d['role']; if(!in_array($role,['teacher','student'],true)) respond(['ok'=>false,'message'=>'Invalid account role'],422);
            $username=trim((string)$d['username']); $display=trim((string)$d['display_name']); $password=(string)$d['password'];
            if(strlen($username)<3) respond(['ok'=>false,'message'=>'Username must be at least 3 characters'],422);
            if(strlen($password)<8) respond(['ok'=>false,'message'=>'Temporary password must be at least 8 characters'],422);
            $studentId=null;
            if($role==='student'){
                $studentId=(int)($d['student_id']??0); if(!$studentId) respond(['ok'=>false,'message'=>'Select a student record for this account'],422);
                $q=$pdo->prepare("SELECT id,full_name FROM students WHERE id=?");$q->execute([$studentId]);$sr=$q->fetch();if(!$sr)respond(['ok'=>false,'message'=>'Student record not found'],404);
                $q=$pdo->prepare("SELECT id FROM users WHERE student_id=? LIMIT 1");$q->execute([$studentId]);if($q->fetchColumn())respond(['ok'=>false,'message'=>'This student already has a login account'],409);
            }
            $dup=$pdo->prepare("SELECT id FROM users WHERE username=? LIMIT 1");$dup->execute([$username]);if($dup->fetchColumn())respond(['ok'=>false,'message'=>'Username is already in use'],409);
            $hash=password_hash($password,PASSWORD_DEFAULT);
            $q=$pdo->prepare("INSERT INTO users(username,password_hash,role,display_name,student_id,is_active,must_change_password) VALUES(?,?,?,?,?,1,1)");
            $q->execute([$username,$hash,$role,$display,$studentId]);respond(['ok'=>true,'id'=>(int)$pdo->lastInsertId()]);

        case 'account_reset_password':
            if($_SERVER['REQUEST_METHOD']!=='POST') respond(['ok'=>false,'message'=>'POST required'],405);
            $d=body();requireFields($d,['user_id','password']);$userId=(int)$d['user_id'];$password=(string)$d['password'];
            if(strlen($password)<8)respond(['ok'=>false,'message'=>'Temporary password must be at least 8 characters'],422);
            $ucheck=$pdo->prepare("SELECT id,username,display_name FROM users WHERE id=? LIMIT 1");$ucheck->execute([$userId]);$target=$ucheck->fetch();
            if(!$target)respond(['ok'=>false,'message'=>'Account not found'],404);
            $hash=password_hash($password,PASSWORD_DEFAULT);
            $pdo->beginTransaction();
            $q=$pdo->prepare("UPDATE users SET password_hash=?,must_change_password=1,updated_at=NOW() WHERE id=?");$q->execute([$hash,$userId]);
            $pdo->prepare("DELETE FROM active_user_sessions WHERE user_id=?")->execute([$userId]);
            securityLog($pdo,$userId,'force_logout',['reason'=>'password_reset'],(int)$authUser['id']);
            $done=$pdo->prepare("UPDATE password_reset_requests SET status='completed',processed_by=?,processed_at=NOW() WHERE user_id=? AND status='pending'");
            $done->execute([(int)$authUser['id'],$userId]);
            $pdo->commit();
            respond(['ok'=>true,'username'=>$target['username'],'display_name'=>$target['display_name']]);

        case 'account_cancel_reset_request':
            if($_SERVER['REQUEST_METHOD']!=='POST') respond(['ok'=>false,'message'=>'POST required'],405);
            $d=body();requireFields($d,['user_id']);$userId=(int)$d['user_id'];
            $q=$pdo->prepare("UPDATE password_reset_requests SET status='cancelled',processed_by=?,processed_at=NOW() WHERE user_id=? AND status='pending'");
            $q->execute([(int)$authUser['id'],$userId]);
            respond(['ok'=>true]);

        case 'account_toggle':
            if($_SERVER['REQUEST_METHOD']!=='POST') respond(['ok'=>false,'message'=>'POST required'],405);
            $d=body();requireFields($d,['user_id','is_active']);$userId=(int)$d['user_id'];$active=(int)!empty($d['is_active']);
            if($userId===(int)$authUser['id'] && !$active)respond(['ok'=>false,'message'=>'You cannot disable your own account while logged in'],422);
            $q=$pdo->prepare("UPDATE users SET is_active=?,updated_at=NOW() WHERE id=?");$q->execute([$active,$userId]);if(!$q->rowCount())respond(['ok'=>false,'message'=>'Account not found or already unchanged'],404);
            if(!$active){$pdo->prepare("DELETE FROM active_user_sessions WHERE user_id=?")->execute([$userId]);securityLog($pdo,$userId,'force_logout',['reason'=>'account_disabled'],(int)$authUser['id']);}
            respond(['ok'=>true]);


        case 'security_logs':
            $limit=(int)($_GET['limit']??200);if($limit<20)$limit=20;if($limit>500)$limit=500;
            $event=trim((string)($_GET['event']??''));$search=trim((string)($_GET['search']??''));
            $where=["u.role='student'"];$params=[];
            if($event!==''){ $where[]='l.event_type=?';$params[]=$event; }
            if($search!==''){ $where[]='(u.display_name LIKE ? OR u.username LIKE ? OR s.student_no LIKE ? OR s.full_name LIKE ? OR l.ip_address LIKE ?)';$like='%'.$search.'%';for($i=0;$i<5;$i++)$params[]=$like; }
            $sql="SELECT l.id,l.user_id,l.actor_user_id,l.event_type,l.ip_address,l.device_type,l.browser,l.details,l.created_at,u.username,u.display_name,u.student_id,s.student_no,s.full_name student_name,au.display_name actor_name FROM security_activity_logs l JOIN users u ON u.id=l.user_id LEFT JOIN students s ON s.id=u.student_id LEFT JOIN users au ON au.id=l.actor_user_id WHERE ".implode(' AND ',$where)." ORDER BY l.created_at DESC,l.id DESC LIMIT ".$limit;
            $q=$pdo->prepare($sql);$q->execute($params);$logs=$q->fetchAll();
            foreach($logs as &$log){$decoded=$log['details']?json_decode((string)$log['details'],true):null;$log['details_data']=is_array($decoded)?$decoded:[];unset($log['details']);}
            $active=(int)$pdo->query("SELECT COUNT(*) FROM active_user_sessions aus JOIN users u ON u.id=aus.user_id WHERE u.role='student'")->fetchColumn();
            $blocked=(int)$pdo->query("SELECT COUNT(*) FROM security_activity_logs l JOIN users u ON u.id=l.user_id WHERE u.role='student' AND l.event_type='blocked_login' AND l.created_at>=DATE_SUB(NOW(),INTERVAL 24 HOUR)")->fetchColumn();
            $subs=(int)$pdo->query("SELECT COUNT(*) FROM security_activity_logs l JOIN users u ON u.id=l.user_id WHERE u.role='student' AND l.event_type='output_submitted' AND l.created_at>=DATE_SUB(NOW(),INTERVAL 24 HOUR)")->fetchColumn();
            $students=(int)$pdo->query("SELECT COUNT(*) FROM users WHERE role='student' AND is_active=1")->fetchColumn();
            $sessions=$pdo->query("SELECT aus.user_id,aus.ip_address,aus.device_type,aus.browser,aus.login_at,aus.last_seen_at,u.username,u.display_name,s.student_no,s.full_name student_name FROM active_user_sessions aus JOIN users u ON u.id=aus.user_id LEFT JOIN students s ON s.id=u.student_id WHERE u.role='student' ORDER BY aus.last_seen_at DESC")->fetchAll();
            respond(['ok'=>true,'summary'=>['active_students'=>$active,'blocked_24h'=>$blocked,'submissions_24h'=>$subs,'student_accounts'=>$students],'logs'=>$logs,'active_sessions'=>$sessions]);

        case 'security_force_logout':
            if($_SERVER['REQUEST_METHOD']!=='POST')respond(['ok'=>false,'message'=>'POST required'],405);
            $d=body();requireFields($d,['user_id']);$userId=(int)$d['user_id'];
            $q=$pdo->prepare("SELECT id,role,display_name FROM users WHERE id=? LIMIT 1");$q->execute([$userId]);$target=$q->fetch();
            if(!$target)respond(['ok'=>false,'message'=>'Account not found'],404);
            if($target['role']!=='student')respond(['ok'=>false,'message'=>'Only student sessions can be force-logged-out from Student Security Logs.'],422);
            $del=$pdo->prepare("DELETE FROM active_user_sessions WHERE user_id=?");$del->execute([$userId]);
            securityLog($pdo,$userId,'force_logout',['reason'=>'teacher_action'],(int)$authUser['id']);
            respond(['ok'=>true,'message'=>'Student session ended.','was_active'=>$del->rowCount()>0?1:0]);

        case 'dashboard':
            $classCount=(int)$pdo->query("SELECT COUNT(*) FROM classes")->fetchColumn();
            $studentCount=(int)$pdo->query("SELECT COUNT(*) FROM students")->fetchColumn();
            $subjectCount=(int)$pdo->query("SELECT COUNT(*) FROM class_courses")->fetchColumn();
            $assessmentCount=(int)$pdo->query("SELECT COUNT(*) FROM assessments")->fetchColumn();
            $scoreCount=(int)$pdo->query("SELECT COUNT(*) FROM scores WHERE score IS NOT NULL")->fetchColumn();
            $activeStudentSessions=(int)$pdo->query("SELECT COUNT(*) FROM active_user_sessions aus JOIN users u ON u.id=aus.user_id WHERE u.role='student'")->fetchColumn();
            $blocked24h=(int)$pdo->query("SELECT COUNT(*) FROM security_activity_logs l JOIN users u ON u.id=l.user_id WHERE u.role='student' AND l.event_type='blocked_login' AND l.created_at>=DATE_SUB(NOW(),INTERVAL 24 HOUR)")->fetchColumn();
            $submissions24h=(int)$pdo->query("SELECT COUNT(*) FROM output_submissions WHERE submitted_at>=DATE_SUB(NOW(),INTERVAL 24 HOUR)")->fetchColumn();
            $pendingOutputs=(int)$pdo->query("SELECT COUNT(*) FROM output_submissions WHERE graded_at IS NULL")->fetchColumn();

            $attendanceTotal=(int)$pdo->query("SELECT COUNT(*) FROM attendance")->fetchColumn();
            $attendancePresent=(int)$pdo->query("SELECT COUNT(*) FROM attendance WHERE status IN ('Present','Late')")->fetchColumn();
            $attendanceRate=$attendanceTotal?round(($attendancePresent/$attendanceTotal)*100,1):0;

            $grades=[];$recentPerformance=[];$subjectBuckets=[];
            $ccRows=$pdo->query("SELECT cc.id,cc.school_year,cc.semester,co.course_code,co.course_description FROM class_courses cc JOIN courses co ON co.id=cc.course_id ORDER BY cc.id DESC")->fetchAll();
            foreach($ccRows as $cc){
                $st=$pdo->prepare("SELECT s.id,s.student_no,s.full_name FROM class_course_students ccs JOIN students s ON s.id=ccs.student_id WHERE ccs.class_course_id=? ORDER BY s.full_name");
                $st->execute([(int)$cc['id']]);
                foreach($st->fetchAll() as $student){
                    $pre=termGrade($pdo,(int)$cc['id'],'prelim',(int)$student['id']);
                    $mid=termGrade($pdo,(int)$cc['id'],'midterm',(int)$student['id']);
                    $fin=termGrade($pdo,(int)$cc['id'],'final',(int)$student['id']);
                    $g=round(($pre+$mid+$fin)/3,2);
                    if($g>0){
                        $grades[]=$g;
                        $subjectBuckets[$cc['course_code']][]=$g;
                        if(count($recentPerformance)<8)$recentPerformance[]=['student_no'=>$student['student_no'],'full_name'=>$student['full_name'],'course_code'=>$cc['course_code'],'grade'=>$g];
                    }
                }
            }
            $overallAverage=$grades?round(array_sum($grades)/count($grades),2):0;
            $passedCount=count(array_filter($grades,fn($g)=>$g>=74.5));
            $passRate=$grades?round(($passedCount/count($grades))*100,1):0;
            $dist=['excellent'=>0,'very_good'=>0,'good'=>0,'satisfactory'=>0,'needs_improvement'=>0];
            foreach($grades as $g){if($g>=90)$dist['excellent']++;elseif($g>=85)$dist['very_good']++;elseif($g>=80)$dist['good']++;elseif($g>=74.5)$dist['satisfactory']++;else$dist['needs_improvement']++;}
            $topSubjects=[];foreach($subjectBuckets as $code=>$arr)$topSubjects[]=['course_code'=>$code,'average'=>round(array_sum($arr)/count($arr),2)];
            usort($topSubjects,fn($a,$b)=>$b['average']<=>$a['average']);$topSubjects=array_slice($topSubjects,0,5);

            $performanceTrend=[];
            if($grades){$chunks=array_chunk($grades,max(1,(int)ceil(count($grades)/6)));foreach(array_slice($chunks,0,6) as $chunk)$performanceTrend[]=round(array_sum($chunk)/count($chunk),2);}
            while(count($performanceTrend)<6)$performanceTrend[]=$overallAverage;

            $periodRow=$pdo->query("SELECT school_year,semester FROM class_courses ORDER BY id DESC LIMIT 1")->fetch();
            $currentPeriod=$periodRow?trim(($periodRow['semester']??'').' · '.($periodRow['school_year']??'')):'Current Academic Period';

            $recentSubmissions=$pdo->query("SELECT s.full_name student_name,a.title activity_title,os.submitted_at,os.graded_at FROM output_submissions os JOIN students s ON s.id=os.student_id JOIN output_activities oa ON oa.id=os.activity_id JOIN assessments a ON a.id=oa.assessment_id ORDER BY os.submitted_at DESC LIMIT 5")->fetchAll();
            $securityAlerts=[];
            if($blocked24h>0)$securityAlerts[]=['level'=>'danger','title'=>$blocked24h.' blocked login attempt'.($blocked24h===1?'':'s'),'detail'=>'Second-device login attempts in the last 24 hours.'];
            if($activeStudentSessions>0)$securityAlerts[]=['level'=>'info','title'=>$activeStudentSessions.' active student session'.($activeStudentSessions===1?'':'s'),'detail'=>'Students currently signed in to MYGRADES.'];
            if($pendingOutputs>0)$securityAlerts[]=['level'=>'warning','title'=>$pendingOutputs.' output'.($pendingOutputs===1?'':'s').' awaiting grading','detail'=>'Recent submissions still need teacher review.'];
            if(!$securityAlerts)$securityAlerts[]=['level'=>'info','title'=>'No urgent account alerts','detail'=>'Single-login monitoring is active.'];

            respond(['ok'=>true,'class_count'=>$classCount,'student_count'=>$studentCount,'subject_count'=>$subjectCount,'assessment_count'=>$assessmentCount,'score_count'=>$scoreCount,'active_student_sessions'=>$activeStudentSessions,'blocked_login_24h'=>$blocked24h,'submissions_24h'=>$submissions24h,'pending_outputs'=>$pendingOutputs,'attendance_total'=>$attendanceTotal,'attendance_rate'=>$attendanceRate,'overall_average'=>$overallAverage,'passed_count'=>$passedCount,'pass_rate'=>$passRate,'grade_distribution'=>$dist,'top_subjects'=>$topSubjects,'recent_performance'=>$recentPerformance,'recent_submissions'=>$recentSubmissions,'security_alerts'=>$securityAlerts,'performance_trend'=>$performanceTrend,'current_period'=>$currentPeriod]);

        default: respond(['ok'=>false,'message'=>'Unknown action'],404);
    }
} catch(Throwable $e){
    if($pdo->inTransaction())$pdo->rollBack();
    respond(['ok'=>false,'message'=>'Server error','detail'=>$e->getMessage()],500);
}
