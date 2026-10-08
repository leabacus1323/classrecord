<?php
$base=dirname(__DIR__);
$files=[
 'app'=>file_get_contents($base.'/assets/app.js'),
 'css'=>file_get_contents($base.'/assets/styles.css'),
 'api'=>file_get_contents($base.'/api/index.php'),
];
$checks=[
 'analytics dashboard renderer'=>str_contains($files['app'],'analytics-dashboard'),
 'grade distribution panel'=>str_contains($files['app'],'Grade Distribution'),
 'attendance overview panel'=>str_contains($files['app'],'Attendance Overview'),
 'security alerts panel'=>str_contains($files['app'],'Security & Account Alerts'),
 'responsive analytics css'=>str_contains($files['css'],'.analytics-dashboard'),
 'dashboard api attendance'=>str_contains($files['api'],"'attendance_rate'"),
 'dashboard api submissions'=>str_contains($files['api'],"'pending_outputs'"),
];
$failed=[];
foreach($checks as $name=>$ok){echo ($ok?'PASS':'FAIL')." - $name\n"; if(!$ok)$failed[]=$name;}
exit($failed?1:0);
