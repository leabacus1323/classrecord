<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
$user = requireSecureLoginPage($pdo,'teacher');
date_default_timezone_set('Asia/Manila');

$submissionId = (int)($_GET['submission_id'] ?? 0);
if (!$submissionId) { http_response_code(400); exit('Invalid submission.'); }

$q = $pdo->prepare("SELECT os.id,os.activity_id,os.student_id,os.original_filename,os.stored_filename,os.mime_type,os.file_size,os.submission_note,os.submitted_at,os.teacher_feedback,os.graded_at,
                           s.student_no,s.full_name,s.gender,
                           a.title,a.term,a.category,a.max_score,
                           c.class_name,co.course_code,co.course_description
                    FROM output_submissions os
                    JOIN output_activities oa ON oa.id=os.activity_id
                    JOIN assessments a ON a.id=oa.assessment_id
                    JOIN class_courses cc ON cc.id=a.class_course_id
                    JOIN classes c ON c.id=cc.class_id
                    JOIN courses co ON co.id=cc.course_id
                    JOIN students s ON s.id=os.student_id
                    WHERE os.id=? LIMIT 1");
$q->execute([$submissionId]);
$row = $q->fetch(PDO::FETCH_ASSOC);
if (!$row) { http_response_code(404); exit('Submission not found.'); }

function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function fmtBytes($bytes): string {
    $bytes=(int)$bytes;
    if($bytes<=0)return '—';
    $units=['B','KB','MB','GB'];$i=0;$n=$bytes;
    while($n>=1024&&$i<count($units)-1){$n/=1024;$i++;}
    return number_format($n,$i?1:0).' '.$units[$i];
}
function submissionPath(array $row): string {
    return __DIR__ . '/uploads/submissions/' . (int)$row['activity_id'] . '/' . basename((string)$row['stored_filename']);
}
function officeZipEntry(string $path, string $entry): ?string {
    if(class_exists('ZipArchive')){
        $zip=new ZipArchive();
        if($zip->open($path)===true){$data=$zip->getFromName($entry);$zip->close();if($data!==false)return $data;}
    }
    if(class_exists('PharData')){
        try{$phar=new PharData($path,0,null,Phar::ZIP);if(isset($phar[$entry]))return file_get_contents($phar[$entry]->getPathname());}catch(Throwable $e){}
    }
    return null;
}
function officeZipNames(string $path): array {
    $names=[];
    if(class_exists('ZipArchive')){
        $zip=new ZipArchive();
        if($zip->open($path)===true){for($i=0;$i<$zip->numFiles;$i++)$names[]=$zip->getNameIndex($i);$zip->close();if($names)return $names;}
    }
    if(class_exists('PharData')){
        try{
            $phar=new PharData($path,0,null,Phar::ZIP);
            $prefix='phar://'.str_replace('\\','/',$path).'/';
            foreach(new RecursiveIteratorIterator($phar) as $f){$n=str_replace('\\','/',$f->getPathname());if(str_starts_with($n,$prefix))$n=substr($n,strlen($prefix));$names[]=$n;}
        }catch(Throwable $e){}
    }
    return $names;
}
function docxText(string $path): ?string {
    $xml=officeZipEntry($path,'word/document.xml'); if($xml===null)return null;
    $xml=preg_replace('/<w:tab[^>]*\/>/i', "\t", $xml);
    $xml=str_ireplace(['</w:p>','</w:tr>','<w:br/>','<w:br />'], ["\n","\n","\n","\n"], $xml);
    $text=html_entity_decode(strip_tags($xml),ENT_QUOTES|ENT_XML1,'UTF-8');
    $text=preg_replace("/\r\n?|\n{3,}/", "\n\n", $text);
    return trim($text);
}
function pptxSlides(string $path): ?array {
    $names=[];
    foreach(officeZipNames($path) as $name)if(preg_match('#^ppt/slides/slide(\d+)\.xml$#',$name,$m))$names[(int)$m[1]]=$name;
    if(!$names)return null;ksort($names,SORT_NUMERIC);$slides=[];
    foreach($names as $num=>$name){
        $xml=officeZipEntry($path,$name); if($xml===null)continue;
        preg_match_all('/<a:t[^>]*>(.*?)<\/a:t>/si',$xml,$m);
        $parts=[];foreach($m[1] as $t){$t=trim(html_entity_decode(strip_tags($t),ENT_QUOTES|ENT_XML1,'UTF-8'));if($t!=='')$parts[]=$t;}
        $slides[]=['number'=>$num,'text'=>implode("\n",$parts)];
    }
    return $slides;
}

$path=submissionPath($row);
$fileExists=is_file($path);
$ext=strtolower(pathinfo((string)$row['original_filename'],PATHINFO_EXTENSION));
$mime=strtolower((string)$row['mime_type']);
$inlineUrl='api/index.php?action=submission_inline&submission_id='.$submissionId;
$downloadUrl='api/index.php?action=submission_download&submission_id='.$submissionId;
$docxPreview=null;$pptxPreview=null;$textPreview=null;
if($fileExists && $ext==='docx')$docxPreview=docxText($path);
if($fileExists && $ext==='pptx')$pptxPreview=pptxSlides($path);
if($fileExists && in_array($ext,['txt','csv'],true)){
    $textPreview=file_get_contents($path, false, null, 0, min(filesize($path), 2*1024*1024));
}
$categoryLabels=['quiz'=>'Quiz','exam'=>'Exam','performance'=>'Performance Task','output'=>'Output'];
$cat=$categoryLabels[$row['category']]??ucfirst((string)$row['category']);
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?=h($row['full_name'])?> · Submission Preview · MyGradebook</title>
<style>
:root{--navy:#172554;--blue:#3157c8;--ink:#172033;--muted:#697386;--line:#e4e8ef;--bg:#f4f6fa;--white:#fff;--green:#168464;--soft:#eef3ff}
*{box-sizing:border-box}body{margin:0;font-family:Inter,Segoe UI,Arial,sans-serif;background:var(--bg);color:var(--ink)}a{text-decoration:none}.wrap{max-width:1280px;margin:auto;padding:22px}.top{display:flex;justify-content:space-between;gap:14px;align-items:center;margin-bottom:16px}.brand{display:flex;align-items:center;gap:10px}.logo{width:42px;height:42px;border-radius:12px;background:var(--navy);color:#fff;display:grid;place-items:center;font-weight:800}.title h1{margin:0;font-size:22px}.title p{margin:4px 0 0;color:var(--muted);font-size:13px}.actions{display:flex;gap:8px;flex-wrap:wrap}.btn{display:inline-flex;align-items:center;justify-content:center;border:1px solid var(--line);border-radius:10px;padding:9px 13px;font-weight:700;font-size:13px;background:#fff;color:var(--ink)}.btn.primary{background:var(--blue);border-color:var(--blue);color:#fff}.meta{background:#fff;border:1px solid var(--line);border-radius:14px;padding:14px 16px;display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin-bottom:16px}.meta div{min-width:0}.meta span{display:block;color:var(--muted);font-size:11px;text-transform:uppercase;letter-spacing:.05em;margin-bottom:4px}.meta strong{display:block;font-size:13px;overflow:hidden;text-overflow:ellipsis}.note{background:#fff;border:1px solid var(--line);border-radius:14px;padding:14px 16px;margin-bottom:16px}.note h3{font-size:13px;margin:0 0 7px}.note p{margin:0;white-space:pre-wrap;color:#3e4757}.viewer{background:#fff;border:1px solid var(--line);border-radius:14px;overflow:hidden;min-height:560px}.viewer-head{padding:11px 14px;border-bottom:1px solid var(--line);display:flex;justify-content:space-between;gap:10px;align-items:center;background:#fbfcfe}.viewer-head strong{font-size:13px}.viewer-head small{color:var(--muted)}iframe.pdf{width:100%;height:74vh;min-height:560px;border:0;background:#eee}.image-wrap{padding:18px;text-align:center}.image-wrap img{max-width:100%;max-height:78vh;object-fit:contain;border-radius:8px}.office-preview{padding:24px;max-width:900px;margin:auto}.office-note{background:var(--soft);border:1px solid #dce5ff;border-radius:10px;padding:10px 12px;color:#42506a;font-size:13px;margin-bottom:18px}.doc-page{white-space:pre-wrap;font-family:Georgia,'Times New Roman',serif;font-size:16px;line-height:1.65;background:#fff}.slide-grid{display:grid;gap:16px}.slide{border:1px solid var(--line);border-radius:12px;overflow:hidden}.slide-title{background:#f7f9fc;border-bottom:1px solid var(--line);padding:9px 12px;font-weight:800;font-size:12px}.slide-body{padding:22px;white-space:pre-wrap;line-height:1.55;min-height:150px}.raw-text{padding:18px;white-space:pre-wrap;font-family:ui-monospace,SFMono-Regular,Consolas,monospace;font-size:13px;overflow:auto}.unsupported{padding:48px 24px;text-align:center}.unsupported h2{margin:0 0 8px}.unsupported p{color:var(--muted);max-width:650px;margin:0 auto 18px}.file-badge{display:inline-flex;padding:4px 8px;border-radius:999px;background:var(--soft);color:var(--blue);font-size:11px;font-weight:800;text-transform:uppercase}
@media(max-width:850px){.wrap{padding:13px}.top{align-items:flex-start;flex-direction:column}.meta{grid-template-columns:1fr 1fr}.viewer{min-height:480px}iframe.pdf{height:70vh;min-height:480px}.office-preview{padding:15px}.slide-body{padding:16px}}
@media(max-width:520px){.meta{grid-template-columns:1fr}.actions{width:100%}.actions .btn{flex:1}.title h1{font-size:18px}}
</style>
</head>
<body>
<div class="wrap">
  <div class="top">
    <div class="brand"><div class="logo">MG</div><div class="title"><h1>Submission Preview</h1><p><?=h($row['full_name'])?> · <?=h($row['title'])?></p></div></div>
    <div class="actions"><a class="btn" href="teacher.php">Back to MyGradebook</a><?php if($fileExists): ?><a class="btn primary" href="<?=h($downloadUrl)?>">Download Original</a><?php endif; ?></div>
  </div>

  <section class="meta">
    <div><span>Student</span><strong><?=h($row['full_name'])?> (<?=h($row['student_no'])?>)</strong></div>
    <div><span>Subject</span><strong><?=h($row['course_code'])?> · <?=h($row['course_description'])?></strong></div>
    <div><span>Activity / Credit</span><strong><?=h($row['title'])?> · <?=h($cat)?> · HPS <?=h($row['max_score'])?></strong></div>
    <div><span>Submitted</span><strong><?=h(date('M j, Y g:i A',strtotime((string)$row['submitted_at'])))?></strong></div>
    <div><span>Class</span><strong><?=h($row['class_name'])?></strong></div>
    <div><span>Term</span><strong><?=h(ucfirst((string)$row['term']))?></strong></div>
    <div><span>File</span><strong><?=h($row['original_filename'] ?: 'No attachment')?></strong></div>
    <div><span>File Type / Size</span><strong><span class="file-badge"><?=h($ext ?: 'file')?></span> <?=h(fmtBytes($row['file_size']))?></strong></div>
  </section>

  <?php if(trim((string)$row['submission_note'])!==''): ?>
  <section class="note"><h3>Student Submission Note / Link</h3><p><?=h($row['submission_note'])?></p></section>
  <?php endif; ?>

  <section class="viewer">
    <div class="viewer-head"><strong><?=h($row['original_filename'] ?: 'Submission')?></strong><small>Protected teacher-only preview</small></div>
    <?php if(!$fileExists): ?>
      <div class="unsupported"><h2>File unavailable</h2><p>The uploaded attachment could not be found on the server. The submission note above may still contain the student's work or link.</p></div>
    <?php elseif($ext==='pdf' || $mime==='application/pdf'): ?>
      <iframe class="pdf" src="<?=h($inlineUrl)?>" title="PDF submission preview"></iframe>
    <?php elseif(in_array($ext,['jpg','jpeg','png','gif','webp'],true) || str_starts_with($mime,'image/')): ?>
      <div class="image-wrap"><img src="<?=h($inlineUrl)?>" alt="Student submission image"></div>
    <?php elseif($ext==='docx'): ?>
      <div class="office-preview"><div class="office-note"><b>Word preview:</b> MyGradebook extracts readable text from the DOCX file for secure browser review. Complex Word formatting, floating images, headers, and page layout may not look exactly like Microsoft Word. Use <b>Download Original</b> when exact formatting matters.</div>
        <?php if($docxPreview!==null && $docxPreview!==''): ?><div class="doc-page"><?=h($docxPreview)?></div><?php else: ?><div class="unsupported"><h2>DOCX preview unavailable</h2><p>This server could not extract the Word document. Download the original file to review it.</p><a class="btn primary" href="<?=h($downloadUrl)?>">Download Word File</a></div><?php endif; ?>
      </div>
    <?php elseif($ext==='pptx'): ?>
      <div class="office-preview"><div class="office-note"><b>PowerPoint preview:</b> MyGradebook extracts the text from each slide for secure browser review. Themes, animations, charts, embedded media, and exact slide positioning are not reproduced. Use <b>Download Original</b> for the full presentation.</div>
        <?php if(is_array($pptxPreview) && count($pptxPreview)): ?><div class="slide-grid"><?php foreach($pptxPreview as $slide): ?><article class="slide"><div class="slide-title">Slide <?=h($slide['number'])?></div><div class="slide-body"><?=h($slide['text'] ?: '[No extractable text on this slide]')?></div></article><?php endforeach; ?></div><?php else: ?><div class="unsupported"><h2>PPTX preview unavailable</h2><p>This server could not extract the presentation. Download the original file to review it.</p><a class="btn primary" href="<?=h($downloadUrl)?>">Download PowerPoint File</a></div><?php endif; ?>
      </div>
    <?php elseif(in_array($ext,['txt','csv'],true)): ?>
      <div class="raw-text"><?=h($textPreview ?? '')?></div>
    <?php elseif(in_array($ext,['doc','ppt'],true)): ?>
      <div class="unsupported"><h2>Legacy <?=strtoupper(h($ext))?> preview is not available</h2><p>Older .doc and .ppt files are binary Microsoft Office formats and cannot be safely converted on InfinityFree without additional server software. Download the original file to review it.</p><a class="btn primary" href="<?=h($downloadUrl)?>">Download <?=strtoupper(h($ext))?> File</a></div>
    <?php else: ?>
      <div class="unsupported"><h2>Browser preview is not available for this file type</h2><p>You can still download the original student submission.</p><a class="btn primary" href="<?=h($downloadUrl)?>">Download Original</a></div>
    <?php endif; ?>
  </section>
</div>
</body>
</html>
