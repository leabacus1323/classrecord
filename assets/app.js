const API='api/index.php';
const CSRF=document.querySelector('meta[name="csrf-token"]')?.content||'';
const labels={quiz:'Quiz',exam:'Exam',performance:'Performance Task',output:'Output'};
const categories=['quiz','exam','performance','output'];
const DEFAULT_WEIGHTS={quiz:0.20,exam:0.30,performance:0.30,output:0.20};
const PASSING_GRADE=74.5;
let state={bootstrap:null,activeClassId:null,activeClassCode:null,activeCCId:null,activeTerm:'prelim',view:'grade',book:null,search:'',filter:'all',bulkRows:[],bulkAssessmentRows:[],bulkAssessmentBook:null,bulkAssessmentColumns:[],editingStudentId:null,performerBasis:'prelim',performerLimit:10,performerData:null,activityData:null,activeActivityId:null};

async function api(action,opts={}){
  const qs=opts.query?'&'+new URLSearchParams(opts.query):'';
  const headers={'Content-Type':'application/json'}; if((opts.method||'GET')!=='GET'&&CSRF)headers['X-CSRF-Token']=CSRF;
  const res=await fetch(`${API}?action=${encodeURIComponent(action)}${qs}`,{
    method:opts.method||'GET',headers,body:opts.body?JSON.stringify(opts.body):undefined
  });
  const data=await res.json().catch(()=>({ok:false,message:'Invalid server response'}));
  if(res.status===401){window.location='index.php';throw new Error('Login required')}
  if(res.status===403&&String(data.message||'').toLowerCase().includes('password')){window.location='change_password.php';throw new Error(data.message)}
  if(!res.ok||data.ok===false) throw new Error(data.message||'Request failed');
  return data;
}
function el(id){return document.getElementById(id)}
function initials(name){return name.split(/\s+/).filter(Boolean).map(x=>x[0]).slice(0,2).join('').toUpperCase()}
function showToast(msg){const t=el('toast');t.textContent=msg;t.classList.add('show');setTimeout(()=>t.classList.remove('show'),2200)}
function openModal(id){el(id).classList.add('show')}
function closeModal(id){el(id).classList.remove('show')}
function activeClass(){return state.bootstrap?.classes?.find(c=>Number(c.id)===Number(state.activeClassId))}
function activeCourse(){return activeClass()?.courses?.find(c=>Number(c.class_course_id)===Number(state.activeCCId))}
function escapeHtml(s){return String(s??'').replace(/[&<>"']/g,m=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[m]))}
function normalizedWeight(v){v=Number(v);if(!Number.isFinite(v))return 0;return v>1?v/100:v}
function resolvedWeights(src){const out={...DEFAULT_WEIGHTS};Object.entries(src||{}).forEach(([k,v])=>{if(Object.prototype.hasOwnProperty.call(out,k))out[k]=normalizedWeight(v)});return out}
function passingStatus(grade){return Number(grade)>=PASSING_GRADE}
function remarkLabel(grade){return passingStatus(grade)?'Pass':'Fail'}

// Custom CKC display rounding:
// decimal part 0.49 or higher rounds up (e.g. 89.49 -> 90).
// Pass/Fail still uses the unrounded grade through passingStatus().
function roundedGrade(value){
  const n=Number(value);
  if(!Number.isFinite(n))return 0;
  const whole=Math.floor(n);
  const decimal=n-whole;
  return decimal>=0.489999999 ? whole+1 : whole;
}
function openSidebar(){const side=el('sidebar'),back=el('sidebarBackdrop'),btn=el('mobileMenuToggle');if(side)side.classList.add('open');if(back)back.classList.add('show');document.body.classList.add('nav-open');if(btn)btn.setAttribute('aria-expanded','true')}
function closeSidebar(){const side=el('sidebar'),back=el('sidebarBackdrop'),btn=el('mobileMenuToggle');if(side)side.classList.remove('open');if(back)back.classList.remove('show');document.body.classList.remove('nav-open');if(btn)btn.setAttribute('aria-expanded','false')}

async function init(){
  try{
    state.bootstrap=await api('bootstrap');
    const first=state.bootstrap.classes.find(c=>c.courses?.length)||state.bootstrap.classes[0];
    state.activeClassId=Number(first?.id||0); state.activeClassCode=first?.class_code||'';
    state.activeCCId=Number(first?.courses?.[0]?.class_course_id||0);
    el('teacherName').textContent=state.bootstrap.user?.display_name||state.bootstrap.settings.default_teacher||'CKC Faculty';
    bindStatic(); populateClasses(); syncCourseOptions(); await setView('grade');
  }catch(err){el('viewContainer').innerHTML=`<div class="panel"><h3>Setup required</h3><p>${escapeHtml(err.message)}</p><p>Import <b>sql/classtrack.sql</b> into MySQL, then verify <b>config/database.php</b>.</p></div>`;}
}
function bindStatic(){
  document.querySelectorAll('.nav-btn').forEach(b=>b.addEventListener('click',async()=>{closeSidebar();await setView(b.dataset.view)}));
  el('mobileMenuToggle')?.addEventListener('click',openSidebar);
  el('sidebarClose')?.addEventListener('click',closeSidebar);
  el('sidebarBackdrop')?.addEventListener('click',closeSidebar);
  window.addEventListener('keydown',e=>{if(e.key==='Escape')closeSidebar()});
  window.addEventListener('resize',()=>{if(window.innerWidth>900)closeSidebar()});
  document.querySelectorAll('[data-close]').forEach(b=>b.addEventListener('click',()=>closeModal(b.dataset.close)));
  el('classSelect').addEventListener('change',async e=>{state.activeClassId=Number(e.target.value);state.activeClassCode=activeClass()?.class_code||'';syncCourseOptions();await refreshView()});
  el('subjectSelect').addEventListener('change',async e=>{state.activeCCId=Number(e.target.value);syncContext();await refreshView()});
  el('termSelect').addEventListener('change',async e=>{state.activeTerm=e.target.value;if(state.view==='performers')state.performerBasis=e.target.value;await refreshView()});
  el('addAssessmentBtn').addEventListener('click',createAssessment);
  el('createActivityBtn')?.addEventListener('click',createActivity);
  el('addStudentBtn').addEventListener('click',createStudent);
  el('previewBulkStudentsBtn').addEventListener('click',previewBulkStudents);
  el('importBulkStudentsBtn').addEventListener('click',importBulkStudents);
  el('downloadCurrentSubjectStudentsBtn').addEventListener('click',()=>downloadStudentsForSubject(Number(el('bulkSubjectSelect').value)));
  el('downloadStudentTemplateBtn').addEventListener('click',downloadStudentTemplate);
  el('bulkStudentFile').addEventListener('change',async e=>{if(e.target.files?.[0]){el('bulkStudentPaste').value=await e.target.files[0].text();await previewBulkStudents();}});
  el('bulkAssessmentSubjectSelect').addEventListener('change',resetBulkAssessmentDestination);
  el('bulkAssessmentTermSelect').addEventListener('change',resetBulkAssessmentDestination);
  el('downloadAssessmentTemplateBtn').addEventListener('click',downloadAssessmentTemplate);
  el('previewBulkAssessmentsBtn').addEventListener('click',previewBulkAssessments);
  el('importBulkAssessmentsBtn').addEventListener('click',importBulkAssessments);
  el('bulkAssessmentFile').addEventListener('change',async e=>{if(e.target.files?.[0]){el('bulkAssessmentPaste').value=await e.target.files[0].text();await previewBulkAssessments();}});
  [el('assessmentModal'),el('studentModal'),el('bulkStudentModal'),el('bulkAssessmentModal'),el('activityModal'),el('activitySubmissionsModal')].forEach(m=>m.addEventListener('click',e=>{if(e.target===m)closeModal(m.id)}));
}
function populateClasses(){
  const classes=state.bootstrap?.classes||[];
  el('classSelect').innerHTML=classes.map(c=>`<option value="${c.id}">${escapeHtml(c.class_name)}</option>`).join('');
  el('classSelect').value=state.activeClassId||'';

  const subjects=allSubjectOptions();
  const mini=el('subjectMini');
  if(!mini)return;
  mini.innerHTML=subjects.length?subjects.map(s=>`<button type="button" class="${Number(s.class_course_id)===Number(state.activeCCId)?'active':''}" data-class="${s.class_id}" data-cc="${s.class_course_id}" title="${escapeHtml(s.course_code)} · ${escapeHtml(s.course_description)} — ${escapeHtml(s.class_name)}"><span class="subject-main">${escapeHtml(s.course_description)}</span><span class="subject-code">${escapeHtml(s.course_code)}</span><span class="subject-meta">${escapeHtml(s.class_name)} · ${Number(s.student_count||0)} students · ${escapeHtml(s.semester)} · SY ${escapeHtml(s.school_year)}</span></button>`).join(''):'<div class="empty" style="padding:12px 8px;color:#93a3bf;font-size:11px">No subjects yet</div>';
  mini.querySelectorAll('button').forEach(b=>b.addEventListener('click',async()=>{
    state.activeClassId=Number(b.dataset.class);
    state.activeCCId=Number(b.dataset.cc);
    state.activeClassCode=activeClass()?.class_code||'';
    el('classSelect').value=state.activeClassId;
    syncCourseOptions();
    const targetView=['performers','activities','attendance','students'].includes(state.view)?state.view:'grade';
    closeSidebar();
    await setView(targetView);
    populateClasses();
  }));
}
function syncCourseOptions(){
  const c=activeClass(); const courses=c?.courses||[];
  if(!courses.some(x=>Number(x.class_course_id)===Number(state.activeCCId))) state.activeCCId=Number(courses[0]?.class_course_id||0);
  el('subjectSelect').innerHTML=courses.length?courses.map(x=>`<option value="${x.class_course_id}">${escapeHtml(x.course_description)}</option>`).join(''):'<option value="">No subject assigned</option>';
  el('subjectSelect').value=state.activeCCId||''; syncContext(); populateClasses();
}
function syncContext(){
  const c=activeCourse();
  el('courseCode').value=c?.course_code||'';
  el('semesterSY').value=c?`${c.semester} / SY ${c.school_year}`:'';
  el('teacherName').textContent=state.bootstrap?.user?.display_name||c?.teacher_name||state.bootstrap?.settings?.default_teacher||'CKC Faculty';
}
async function setView(view){
  state.view=view; document.querySelectorAll('.nav-btn').forEach(b=>b.classList.toggle('active',b.dataset.view===view)); await refreshView();
}
async function refreshView(){
  syncContext(); const meta={
    dashboard:['Dashboard','Overview of classes, students, subjects, assessments, and recorded scores.'],
    students:['Students','Manage students assigned to the selected subject. Use bulk upload for large class lists.'],
    subjects:['Subject Management','Add subjects and assign them to a level and section.'],
    grade:['Class Grade Entry','Enter and compute scores for every student in the selected class.'],
    activities:['Activities & Submissions','Create student outputs, credit them to a grading category, review submissions, and grade them.'],
    performers:['Top Performing Students','Generate and rank the top-performing students for the selected subject.'],
    attendance:['Attendance','Record daily attendance for the selected class and course.'],
    accounts:['Accounts & Passwords','Create accounts, process reset requests, and manage login access.'],
    security:['Student Security Logs','Monitor student sessions, blocked login attempts, devices, and output submission events.']
  }[state.view]; el('viewTitle').textContent=meta[0];el('viewSubtitle').textContent=meta[1];
  el('contextCard').style.display=(state.view==='dashboard'||state.view==='subjects'||state.view==='accounts'||state.view==='security')?'none':'grid';
  if(state.view==='dashboard') return renderDashboard();
  if(state.view==='subjects') return renderSubjects();
  if(state.view==='students') return renderStudents();
  if(state.view==='accounts') return renderAccounts();
  if(state.view==='security') return renderSecurityLogs();
  if(!state.activeCCId){el('topActions').innerHTML='';el('viewContainer').innerHTML='<div class="panel"><h3>No subject assigned</h3><p>Add a subject in <b>Subjects</b> first, then return here.</p></div>';return;}
  if(state.view==='activities') return renderActivities();
  if(state.view==='attendance') return renderAttendance();
  if(state.view==='performers') return renderTopPerformers();
  state.book=await api('gradebook',{query:{class_course_id:state.activeCCId,term:state.activeTerm}});
  return renderGrade();
}

async function renderDashboard(){
  el('topActions').innerHTML='<button class="btn btn-light" id="refreshDashboardBtn">Refresh</button>';
  const d=await api('dashboard');
  const pct=n=>Math.max(0,Math.min(100,Number(n||0)));
  const dist=d.grade_distribution||{};
  const distTotal=Object.values(dist).reduce((a,b)=>a+Number(b||0),0)||1;
  const donutParts=[['Excellent',dist.excellent||0,'#2d8cff'],['Very Good',dist.very_good||0,'#2dd4bf'],['Good',dist.good||0,'#facc15'],['Satisfactory',dist.satisfactory||0,'#fb923c'],['Needs Improvement',dist.needs_improvement||0,'#8b5cf6']];
  let cursor=0; const stops=[]; donutParts.forEach(x=>{const start=cursor,end=cursor+(Number(x[1])/distTotal*100);stops.push(`${x[2]} ${start}% ${end}%`);cursor=end});
  const recent=(d.recent_performance||[]).map((r,i)=>`<tr><td>${i+1}</td><td><strong>${escapeHtml(r.student_no||'')}</strong></td><td>${escapeHtml(r.full_name||'')}</td><td>${escapeHtml(r.course_code||'')}</td><td><strong>${Number(r.grade||0).toFixed(2)}</strong></td><td><span class="status ${Number(r.grade)>=75?'pass':'review'}">${Number(r.grade)>=75?'Passed':'Review'}</span></td></tr>`).join('')||'<tr><td colspan="6" class="muted">No recent grade records yet.</td></tr>';
  const topSubjects=(d.top_subjects||[]).map(r=>`<div class="metric-row"><span>${escapeHtml(r.course_code||r.course_description||'Subject')}</span><div class="metric-bar"><i style="width:${pct(r.average)}%"></i></div><b>${Number(r.average||0).toFixed(1)}</b></div>`).join('')||'<div class="empty compact-empty">No subject averages yet.</div>';
  const security=(d.security_alerts||[]).map(a=>`<div class="alert-row ${escapeHtml(a.level||'info')}"><span class="alert-dot"></span><div><strong>${escapeHtml(a.title||'Alert')}</strong><small>${escapeHtml(a.detail||'')}</small></div><button class="link-btn" data-security-jump>View</button></div>`).join('')||'<div class="empty compact-empty">No security alerts.</div>';
  const submissions=(d.recent_submissions||[]).map(r=>`<div class="submission-row"><div><strong>${escapeHtml(r.student_name||'Student')}</strong><small>${escapeHtml(r.activity_title||'Output')} · ${escapeHtml(r.submitted_at||'')}</small></div><span class="status ${r.graded_at?'pass':'review'}">${r.graded_at?'Graded':'Pending'}</span></div>`).join('')||'<div class="empty compact-empty">No recent submissions.</div>';
  el('viewContainer').innerHTML=`
  <div class="analytics-dashboard">
    <section class="analytics-welcome"><div><p class="eyebrow">MYGRADES ANALYTICS</p><h2>Welcome back, ${escapeHtml(state.bootstrap.user?.display_name||'Teacher')}! 👋</h2><p>Here is an overview of academic performance, attendance, activities, and account security.</p></div><div class="period-badge">${escapeHtml(d.current_period||'Current Academic Period')}</div></section>
    <section class="analytics-stats">
      <div class="analytics-stat blue"><span>Total Students</span><strong>${Number(d.student_count||0).toLocaleString()}</strong><small>${Number(d.active_student_sessions||0)} active sessions</small></div>
      <div class="analytics-stat violet"><span>Overall Average</span><strong>${Number(d.overall_average||0).toFixed(2)}</strong><small>Across recorded final grades</small></div>
      <div class="analytics-stat green"><span>Pass Rate</span><strong>${pct(d.pass_rate).toFixed(1)}%</strong><small>${Number(d.passed_count||0)} passing records</small></div>
      <div class="analytics-stat amber"><span>Attendance Rate</span><strong>${pct(d.attendance_rate).toFixed(1)}%</strong><small>${Number(d.attendance_total||0)} attendance entries</small></div>
      <div class="analytics-stat cyan"><span>Pending Outputs</span><strong>${Number(d.pending_outputs||0)}</strong><small>${Number(d.submissions_24h||0)} submissions in 24h</small></div>
    </section>
    <section class="analytics-grid analytics-grid-top">
      <article class="analytics-panel performance-panel"><div class="panel-title"><div><h3>Student Performance Overview</h3><small>Current grade movement</small></div><span class="mini-badge">Average ${Number(d.overall_average||0).toFixed(1)}</span></div><div class="spark-chart">${(d.performance_trend||[0,0,0,0,0,0]).map((v,i)=>`<div class="spark-col"><span style="height:${Math.max(8,pct(v))}%"></span><small>${['P1','P2','P3','P4','P5','Now'][i]||''}</small></div>`).join('')}</div></article>
      <article class="analytics-panel"><div class="panel-title"><div><h3>Grade Distribution</h3><small>Recorded student grades</small></div></div><div class="donut-wrap"><div class="donut" style="background:conic-gradient(${stops.join(',')})"><div><strong>${distTotal===1&&Object.values(dist).every(v=>!v)?0:distTotal}</strong><span>Grades</span></div></div><div class="legend">${donutParts.map(x=>`<div><i style="background:${x[2]}"></i><span>${x[0]}</span><b>${Number(x[1]||0)}</b></div>`).join('')}</div></div></article>
      <article class="analytics-panel"><div class="panel-title"><div><h3>Attendance Overview</h3><small>Present and late count as attended</small></div></div><div class="gauge" style="--gauge:${pct(d.attendance_rate)}"><div><strong>${pct(d.attendance_rate).toFixed(1)}%</strong><span>Attendance Rate</span></div></div><div class="gauge-foot"><span>0%</span><span>100%</span></div></article>
    </section>
    <section class="analytics-grid analytics-grid-mid">
      <article class="analytics-panel"><div class="panel-title"><div><h3>Top Performing Subjects</h3><small>Average grade by subject</small></div></div><div class="metric-list">${topSubjects}</div></article>
      <article class="analytics-panel"><div class="panel-title"><div><h3>Academic Snapshot</h3><small>Current workload</small></div></div><div class="snapshot-grid"><div><strong>${d.class_count}</strong><span>Classes</span></div><div><strong>${d.subject_count}</strong><span>Subjects</span></div><div><strong>${d.assessment_count}</strong><span>Assessments</span></div><div><strong>${d.score_count}</strong><span>Scores</span></div></div></article>
      <article class="analytics-panel"><div class="panel-title"><div><h3>Recent Output Submissions</h3><small>Latest student activity</small></div><button class="link-btn" id="goActivities">Open</button></div><div class="submission-list">${submissions}</div></article>
    </section>
    <section class="analytics-grid analytics-grid-bottom">
      <article class="analytics-panel wide"><div class="panel-title"><div><h3>Recent Student Performance</h3><small>Latest computed grades</small></div></div><div class="table-wrap analytics-table-wrap"><table class="data-table analytics-table"><thead><tr><th>#</th><th>Student ID</th><th>Name</th><th>Subject</th><th>Grade</th><th>Status</th></tr></thead><tbody>${recent}</tbody></table></div></article>
      <article class="analytics-panel"><div class="panel-title"><div><h3>Security & Account Alerts</h3><small>Single-login monitoring</small></div><button class="link-btn" id="goSecurity">Logs</button></div><div class="alert-list">${security}</div></article>
      <article class="analytics-panel"><div class="panel-title"><div><h3>Quick Actions</h3><small>Common teacher tasks</small></div></div><div class="quick-action-grid"><button data-quick="students">＋ Add / Manage Students</button><button data-quick="grade">⇧ Upload / Enter Grades</button><button data-quick="activities">▤ Manage Outputs</button><button data-quick="attendance">✓ Record Attendance</button></div></article>
    </section>
  </div>`;
  el('refreshDashboardBtn').onclick=renderDashboard;
  el('goActivities')?.addEventListener('click',()=>setView('activities'));
  el('goSecurity')?.addEventListener('click',()=>setView('security'));
  document.querySelectorAll('[data-security-jump]').forEach(b=>b.onclick=()=>setView('security'));
  document.querySelectorAll('[data-quick]').forEach(b=>b.onclick=()=>setView(b.dataset.quick));
}

async function renderAccounts(){
  el('topActions').innerHTML='<a class="btn btn-light" href="change_password.php">Change My Password</a>';
  const d=await api('accounts');
  const unlinked=d.students.filter(s=>!s.account_id);
  const pending=Number(d.pending_reset_count||0);
  el('viewContainer').innerHTML=`
  <section class="summary account-summary">
    <div class="stat"><span>Total Accounts</span><strong>${d.users.length}</strong></div>
    <div class="stat"><span>Teachers</span><strong>${d.users.filter(u=>u.role==='teacher').length}</strong></div>
    <div class="stat"><span>Students</span><strong>${d.users.filter(u=>u.role==='student').length}</strong></div>
    <div class="stat ${pending?'reset-alert-stat':''}"><span>Reset Requests</span><strong>${pending}</strong></div>
  </section>
  ${pending?`<section class="panel reset-request-panel"><div class="panel-heading"><div><h3>Password Reset Requests</h3><p>${pending} account${pending===1?' has':'s have'} requested password assistance. Set a temporary password, then give it directly to the user. They will be required to change it after signing in.</p></div></div></section>`:''}
  <section class="panel account-create-panel">
    <div class="panel-heading"><div><h3>Create Login Account</h3><p>Student accounts are linked to an existing student record. New accounts must change their temporary password on first login.</p></div></div>
    <div class="account-form-grid">
      <div class="form-field"><label>Account Type</label><select id="accountRole"><option value="student">Student</option><option value="teacher">Teacher</option></select></div>
      <div class="form-field account-student-field"><label>Student</label><select id="accountStudent"><option value="">Select student</option>${unlinked.map(s=>`<option value="${s.id}" data-no="${escapeHtml(s.student_no)}" data-name="${escapeHtml(s.full_name)}">${escapeHtml(s.student_no)} · ${escapeHtml(s.full_name)}</option>`).join('')}</select></div>
      <div class="form-field"><label>Username</label><input id="accountUsername" placeholder="Student ID or teacher username"></div>
      <div class="form-field"><label>Display Name</label><input id="accountDisplayName" placeholder="Full name"></div>
      <div class="form-field"><label>Temporary Password</label><input id="accountPassword" type="password" minlength="8" placeholder="At least 8 characters"></div>
      <div class="account-form-action"><button class="btn btn-primary" id="createAccountBtn">Create Account</button></div>
    </div>
  </section>
  <section class="panel" style="padding:0"><div class="table-wrap"><table class="data-table account-table"><thead><tr><th>#</th><th style="text-align:left">Account</th><th>Role</th><th style="text-align:left">Linked Student</th><th>Status</th><th>Last Login</th><th>Action</th></tr></thead><tbody>${d.users.map((u,i)=>`<tr class="${u.reset_request_id?'reset-request-row':''}"><td>${i+1}</td><td style="text-align:left"><strong>${escapeHtml(u.display_name)}</strong><br><span class="muted">${escapeHtml(u.username)}</span>${u.reset_request_id?`<br><span class="reset-request-badge">Reset requested ${escapeHtml(u.reset_requested_at||'')}</span>`:''}</td><td><span class="status ${u.role==='teacher'?'pass':''}">${escapeHtml(u.role)}</span></td><td style="text-align:left">${u.student_no?`${escapeHtml(u.student_no)} · ${escapeHtml(u.student_name)}`:'—'}</td><td><span class="status ${Number(u.is_active)?'pass':'review'}">${Number(u.is_active)?'Active':'Disabled'}</span>${Number(u.must_change_password)?'<br><span class="muted">Password change required</span>':''}</td><td>${u.last_login?escapeHtml(u.last_login):'Never'}</td><td class="subject-actions">${Number(u.id)===Number(d.current_user_id)?'<a class="btn btn-light btn-sm" href="change_password.php">Change My Password</a>':`<button class="btn ${u.reset_request_id?'btn-primary':'btn-light'} btn-sm reset-account" data-id="${u.id}" data-name="${escapeHtml(u.display_name)}">Reset Password</button>`}${u.reset_request_id?`<button class="btn btn-light btn-sm cancel-reset" data-id="${u.id}">Dismiss Request</button>`:''}<button class="btn ${Number(u.is_active)?'btn-danger':'btn-light'} btn-sm toggle-account" data-id="${u.id}" data-active="${Number(u.is_active)?0:1}" ${Number(u.id)===Number(d.current_user_id)?'disabled':''}>${Number(u.is_active)?'Disable':'Enable'}</button></td></tr>`).join('')}</tbody></table></div></section>`;
  const role=el('accountRole'),student=el('accountStudent'),studentField=document.querySelector('.account-student-field');
  const syncRole=()=>{const isStudent=role.value==='student';studentField.style.display=isStudent?'block':'none';if(!isStudent)student.value=''}; role.onchange=syncRole; syncRole();
  student.onchange=()=>{const opt=student.selectedOptions[0];if(opt?.value){el('accountUsername').value=opt.dataset.no||'';el('accountDisplayName').value=opt.dataset.name||''}};
  el('createAccountBtn').onclick=async()=>{const payload={role:role.value,student_id:student.value||null,username:el('accountUsername').value.trim(),display_name:el('accountDisplayName').value.trim(),password:el('accountPassword').value};if(!payload.username||!payload.display_name||!payload.password)return showToast('Complete the account details');try{await api('account_create',{method:'POST',body:payload});showToast('Account created');await renderAccounts()}catch(e){showToast(e.message)}};
  document.querySelectorAll('.reset-account').forEach(b=>b.onclick=async()=>{const password=prompt(`Enter a temporary password for ${b.dataset.name} (minimum 8 characters):`);if(!password)return;if(password.length<8)return showToast('Temporary password must be at least 8 characters');try{await api('account_reset_password',{method:'POST',body:{user_id:Number(b.dataset.id),password}});showToast('Password reset. User must change it at next login.');await renderAccounts()}catch(e){showToast(e.message)}});
  document.querySelectorAll('.cancel-reset').forEach(b=>b.onclick=async()=>{if(!confirm('Dismiss this password reset request without changing the password?'))return;try{await api('account_cancel_reset_request',{method:'POST',body:{user_id:Number(b.dataset.id)}});showToast('Reset request dismissed');await renderAccounts()}catch(e){showToast(e.message)}});
  document.querySelectorAll('.toggle-account').forEach(b=>b.onclick=async()=>{try{await api('account_toggle',{method:'POST',body:{user_id:Number(b.dataset.id),is_active:Number(b.dataset.active)}});showToast(Number(b.dataset.active)?'Account enabled':'Account disabled');await renderAccounts()}catch(e){showToast(e.message)}});
}


function securityEventLabel(type){return ({login_success:'Login',logout:'Logout',blocked_login:'Blocked Login',force_logout:'Force Logout',output_submitted:'Output Submitted'}[type]||String(type||'').replace(/_/g,' '));}
function securityEventClass(type){if(type==='blocked_login')return 'review';if(type==='output_submitted')return 'pass';if(type==='force_logout')return 'review';return '';}
function securityDetails(log){const d=log.details_data||{};if(log.event_type==='output_submitted')return `${escapeHtml(d.activity_title||'Output')}${d.file_name?`<br><span class="muted">${escapeHtml(d.file_name)}</span>`:''}`;if(log.event_type==='blocked_login')return `Another active session${d.active_device?` · ${escapeHtml(d.active_device)}`:''}${d.active_browser?` ${escapeHtml(d.active_browser)}`:''}`;if(log.event_type==='force_logout')return `Ended by ${escapeHtml(log.actor_name||'teacher')}`;return '—';}
async function renderSecurityLogs(){
  el('topActions').innerHTML='<button class="btn btn-light" id="refreshSecurityBtn">Refresh Logs</button>';
  const d=await api('security_logs',{query:{limit:300}});
  const active=d.active_sessions||[],logs=d.logs||[],sum=d.summary||{};
  el('viewContainer').innerHTML=`
  <section class="summary security-summary">
    <div class="stat"><span>Student Accounts</span><strong>${Number(sum.student_accounts||0)}</strong></div>
    <div class="stat"><span>Active Now</span><strong>${Number(sum.active_students||0)}</strong></div>
    <div class="stat"><span>Blocked Logins · 24h</span><strong>${Number(sum.blocked_24h||0)}</strong></div>
    <div class="stat"><span>Submissions · 24h</span><strong>${Number(sum.submissions_24h||0)}</strong></div>
  </section>
  <section class="panel security-note"><div class="panel-heading"><div><h3>Single-device account protection</h3><p>Only one active session is allowed per account. A second login is blocked while the first session remains active. Use Force Logout only when a student has lost access to the original device or when you need to end the session.</p></div></div></section>
  <section class="panel" style="padding:0"><div class="panel-heading security-panel-heading"><div><h3>Currently Active Student Sessions</h3><p>Last activity is refreshed whenever the student uses the portal.</p></div></div><div class="table-wrap"><table class="data-table security-table"><thead><tr><th style="text-align:left">Student</th><th>Device</th><th>Browser</th><th>IP Address</th><th>Login Time</th><th>Last Seen</th><th>Action</th></tr></thead><tbody>${active.map(r=>`<tr><td style="text-align:left"><strong>${escapeHtml(r.student_name||r.display_name)}</strong><small>${escapeHtml(r.student_no||r.username)}</small></td><td>${escapeHtml(r.device_type||'—')}</td><td>${escapeHtml(r.browser||'—')}</td><td><code>${escapeHtml(r.ip_address||'—')}</code></td><td>${escapeHtml(formatDateTime(r.login_at))}</td><td>${escapeHtml(formatDateTime(r.last_seen_at))}</td><td><button class="btn btn-danger btn-sm force-logout" data-id="${r.user_id}" data-name="${escapeHtml(r.student_name||r.display_name)}">Force Logout</button></td></tr>`).join('')||'<tr><td colspan="7" class="empty">No student accounts are currently signed in.</td></tr>'}</tbody></table></div></section>
  <div class="toolbar security-toolbar"><div class="toolbar-left"><input class="search" id="securitySearch" placeholder="Search student, username, ID, or IP"><select id="securityEvent"><option value="">All events</option><option value="login_success">Login</option><option value="blocked_login">Blocked Login</option><option value="logout">Logout</option><option value="force_logout">Force Logout</option><option value="output_submitted">Output Submitted</option></select></div></div>
  <section class="panel" style="padding:0"><div class="table-wrap"><table class="data-table security-table"><thead><tr><th>Date & Time</th><th style="text-align:left">Student</th><th>Event</th><th>Device / Browser</th><th>IP Address</th><th style="text-align:left">Details</th></tr></thead><tbody id="securityLogBody"></tbody></table></div></section>`;
  const draw=()=>{const q=(el('securitySearch').value||'').toLowerCase(),ev=el('securityEvent').value;const rows=logs.filter(r=>(!ev||r.event_type===ev)&&(!q||[r.student_name,r.display_name,r.student_no,r.username,r.ip_address,r.device_type,r.browser].some(v=>String(v||'').toLowerCase().includes(q))));el('securityLogBody').innerHTML=rows.map(r=>`<tr><td>${escapeHtml(formatDateTime(r.created_at))}</td><td style="text-align:left"><strong>${escapeHtml(r.student_name||r.display_name)}</strong><small>${escapeHtml(r.student_no||r.username)}</small></td><td><span class="status ${securityEventClass(r.event_type)}">${escapeHtml(securityEventLabel(r.event_type))}</span></td><td>${escapeHtml(r.device_type||'—')}<br><span class="muted">${escapeHtml(r.browser||'—')}</span></td><td><code>${escapeHtml(r.ip_address||'—')}</code></td><td style="text-align:left">${securityDetails(r)}</td></tr>`).join('')||'<tr><td colspan="6" class="empty">No matching security events.</td></tr>';};
  draw();el('securitySearch').oninput=draw;el('securityEvent').onchange=draw;el('refreshSecurityBtn').onclick=renderSecurityLogs;
  document.querySelectorAll('.force-logout').forEach(b=>b.onclick=async()=>{if(!confirm(`Force logout ${b.dataset.name}? Their current session will stop immediately.`))return;try{await api('security_force_logout',{method:'POST',body:{user_id:Number(b.dataset.id)}});showToast('Student session ended');await renderSecurityLogs()}catch(e){showToast(e.message)}});
}

async function renderSubjects(){
  el('topActions').innerHTML='';
  const d=await api('subjects');
  const current=activeCourse();
  el('viewContainer').innerHTML=`
  <section class="panel subject-create-panel">
    <div class="panel-heading"><div><h3>Add Subject</h3><p>Add a subject, its description, level, and section. The subject will automatically become available in Grade Entry.</p></div></div>
    <div class="subject-form">
      <div class="form-field"><label>Subject</label><input id="subjectCodeNew" maxlength="40" placeholder="Example: IT 301"></div>
      <div class="form-field subject-description"><label>Description</label><input id="subjectDescriptionNew" placeholder="Example: Web Systems and Technologies"></div>
      <div class="form-field"><label>Level</label><input id="subjectLevelNew" placeholder="Example: 3 or Grade 12"></div>
      <div class="form-field"><label>Section</label><input id="subjectSectionNew" placeholder="Example: A"></div>
      <div class="form-field"><label>Semester</label><select id="subjectSemesterNew"><option value="1st Semester">1st Semester</option><option value="2nd Semester">2nd Semester</option><option value="Summer">Summer</option></select></div>
      <div class="form-field"><label>School Year</label><input id="subjectSchoolYearNew" maxlength="20" placeholder="Example: 2026-2027" value="${escapeHtml(current?.school_year||'2026-2027')}"></div>
      <div class="subject-form-action"><button class="btn btn-primary" id="saveSubjectBtn">+ Add Subject</button></div>
    </div>
    <p class="muted subject-note">Level + Section will reuse an existing class when they match. Semester and School Year are saved with each subject assignment.</p>
  </section>
  <div class="toolbar subject-toolbar"><div class="toolbar-left"><input class="search" id="subjectSearch" placeholder="Search subject, level, or section"></div></div>
  <section class="panel" style="padding:0"><div class="table-wrap"><table class="data-table subject-table"><thead><tr><th>#</th><th>Subject</th><th style="text-align:left">Description</th><th>Level</th><th>Section</th><th>Students</th><th>Semester / SY</th><th>Action</th></tr></thead><tbody id="subjectsBody"></tbody></table></div></section>`;
  if(current?.semester && el('subjectSemesterNew')) el('subjectSemesterNew').value=current.semester;
  const draw=q=>{q=(q||'').toLowerCase();const rows=d.subjects.filter(s=>[s.subject,s.description,s.level,s.section,s.class_name,s.semester,s.school_year].some(v=>String(v||'').toLowerCase().includes(q)));el('subjectsBody').innerHTML=rows.map((s,i)=>`<tr><td>${i+1}</td><td><strong>${escapeHtml(s.subject)}</strong></td><td style="text-align:left">${escapeHtml(s.description)}</td><td>${escapeHtml(s.level)}</td><td>${escapeHtml(s.section)}</td><td>${Number(s.student_count||0)}</td><td>${escapeHtml(s.semester)}<br><span class="muted">SY ${escapeHtml(s.school_year)}</span></td><td class="subject-actions"><button class="btn btn-light btn-sm open-subject" data-class="${s.class_id}" data-cc="${s.subject_assignment_id}">Open</button><button class="btn btn-light btn-sm download-subject-students" data-cc="${s.subject_assignment_id}">Download Students</button><button class="btn btn-light btn-sm bulk-subject" data-cc="${s.subject_assignment_id}">Upload Students</button><button class="btn btn-danger btn-sm delete-subject" data-id="${s.subject_assignment_id}" data-name="${escapeHtml(s.subject)}" data-classname="${escapeHtml(s.class_name)}">Remove</button></td></tr>`).join('')||'<tr><td colspan="8" class="empty">No subjects found.</td></tr>';
    document.querySelectorAll('.open-subject').forEach(b=>b.onclick=()=>openSubject(b.dataset.class,b.dataset.cc));
    document.querySelectorAll('.download-subject-students').forEach(b=>b.onclick=()=>downloadStudentsForSubject(Number(b.dataset.cc)));
    document.querySelectorAll('.bulk-subject').forEach(b=>b.onclick=()=>openBulkStudentModal(Number(b.dataset.cc)));
    document.querySelectorAll('.delete-subject').forEach(b=>b.onclick=()=>deleteSubject(b.dataset.id,b.dataset.name,b.dataset.classname));
  };
  draw(''); el('subjectSearch').oninput=e=>draw(e.target.value); el('saveSubjectBtn').onclick=createSubject;
}
async function createSubject(){
  const subject=el('subjectCodeNew').value.trim(),description=el('subjectDescriptionNew').value.trim(),level=el('subjectLevelNew').value.trim(),section=el('subjectSectionNew').value.trim();
  const semester=el('subjectSemesterNew')?.value||'1st Semester',school_year=el('subjectSchoolYearNew')?.value.trim()||'';
  if(!subject||!description||!level||!section||!school_year)return showToast('Subject, description, level, section, semester, and school year are required');
  if(!/^\d{4}\s*[-–]\s*\d{4}$/.test(school_year))return showToast('School Year should look like 2026-2027');
  const c=activeCourse();
  try{
    const r=await api('subject_create',{method:'POST',body:{subject,description,level,section,semester,school_year:school_year.replace(/\s/g,'').replace('–','-'),teacher_name:c?.teacher_name||state.bootstrap.settings.default_teacher||'CKC Faculty'}});
    state.activeClassId=Number(r.class_id);state.activeCCId=Number(r.class_course_id);await reloadBootstrap();
    showToast(r.already_exists?'Subject is already assigned to this level and section':'Subject added');await renderSubjects();
  }catch(e){showToast(e.message)}
}
async function openSubject(classId,ccId){state.activeClassId=Number(classId);state.activeCCId=Number(ccId);populateClasses();syncCourseOptions();await setView('grade')}
async function deleteSubject(id,name,className){
  if(!confirm(`Remove ${name} from ${className}? Its assessments and saved scores for this subject assignment will also be removed.`))return;
  try{await api('subject_delete',{method:'POST',body:{class_course_id:id}});showToast('Subject assignment removed');await reloadBootstrap();await renderSubjects()}catch(e){showToast(e.message)}
}

async function renderStudents(){
  if(!state.activeCCId){el('topActions').innerHTML='';el('viewContainer').innerHTML='<div class="panel"><h3>No subject selected</h3><p>Add or select a subject before managing students.</p></div>';return;}
  el('topActions').innerHTML='<button class="btn btn-light" id="downloadSubjectStudents">⇩ Download Students</button><button class="btn btn-light" id="openBulkStudents">⇧ Bulk Upload</button><button class="btn btn-primary" id="openStudent">+ Add Student</button>';
  el('openStudent').onclick=()=>openStudentEditor();
  el('downloadSubjectStudents').onclick=()=>downloadStudentsForSubject(state.activeCCId);
  el('openBulkStudents').onclick=()=>openBulkStudentModal(state.activeCCId);
  const d=await api('students',{query:{class_course_id:state.activeCCId}}); const co=activeCourse(); const c=activeClass();
  el('viewContainer').innerHTML=`<section class="panel student-subject-banner"><div><span class="muted">Selected Subject</span><h3>${escapeHtml(co?.course_code||'')} · ${escapeHtml(co?.course_description||'')}</h3><p>${escapeHtml(c?.class_name||'')} · ${d.students.length} assigned student${d.students.length===1?'':'s'}</p></div><div class="subject-actions"><button class="btn btn-light" id="bannerDownloadStudents">Download Students</button><button class="btn btn-light" id="bannerBulkUpload">Bulk Upload Students</button></div></section>
  <div class="toolbar"><div class="toolbar-left"><input class="search" id="studentListSearch" placeholder="Search student, ID, or gender"></div></div>
  <section class="panel" style="padding:0"><div class="table-wrap"><table class="data-table"><thead><tr><th>#</th><th>Student ID</th><th style="text-align:left">Full Name</th><th>Gender</th><th>Email</th><th>Action</th></tr></thead><tbody id="studentsBody"></tbody></table></div></section>`;
  el('bannerDownloadStudents').onclick=()=>downloadStudentsForSubject(state.activeCCId);
  el('bannerBulkUpload').onclick=()=>openBulkStudentModal(state.activeCCId);
  const draw=q=>{q=(q||'').toLowerCase();const rows=d.students.filter(s=>s.full_name.toLowerCase().includes(q)||s.student_no.toLowerCase().includes(q)||String(s.gender||'').toLowerCase().includes(q));el('studentsBody').innerHTML=rows.map((s,i)=>`<tr><td>${i+1}</td><td>${escapeHtml(s.student_no)}</td><td style="text-align:left"><div class="student-name"><div class="avatar">${initials(s.full_name)}</div><strong>${escapeHtml(s.full_name)}</strong></div></td><td><span class="status ${s.gender==='Male'?'gender-male':s.gender==='Female'?'gender-female':'review'}">${escapeHtml(s.gender||'Not set')}</span></td><td>${escapeHtml(s.email||'—')}</td><td><div class="subject-actions"><button class="btn btn-light btn-sm edit-student" data-id="${s.id}">Edit</button><button class="btn btn-danger btn-sm remove-student" data-id="${s.id}" data-name="${escapeHtml(s.full_name)}">Remove</button></div></td></tr>`).join('')||'<tr><td colspan="6" class="empty">No students assigned to this subject.</td></tr>';document.querySelectorAll('.edit-student').forEach(b=>b.onclick=()=>{const s=d.students.find(x=>Number(x.id)===Number(b.dataset.id));if(s)openStudentEditor(s)});document.querySelectorAll('.remove-student').forEach(b=>b.onclick=()=>removeStudent(b.dataset.id,b.dataset.name));};
  draw('');el('studentListSearch').oninput=e=>draw(e.target.value);
}
function openStudentEditor(student=null){
  state.editingStudentId=student?Number(student.id):null;
  el('studentModalTitle').textContent=student?'Edit Student':'Add Student';
  el('addStudentBtn').textContent=student?'Save Changes':'Add Student';
  el('studentNo').value=student?.student_no||'';
  el('studentFullName').value=student?.full_name||'';
  el('studentGender').value=student?.gender||'';
  el('studentEmail').value=student?.email||'';
  openModal('studentModal');
}
async function createStudent(){
  const student_no=el('studentNo').value.trim(),full_name=el('studentFullName').value.trim(),gender=el('studentGender').value,email=el('studentEmail').value.trim();
  if(!student_no||!full_name||!gender)return showToast('Student ID, full name, and gender are required');
  if(!state.activeCCId)return showToast('Select a subject first');
  try{
    if(state.editingStudentId){
      await api('student_update',{method:'POST',body:{student_id:state.editingStudentId,student_no,full_name,gender,email}});
      showToast('Student updated');
    }else{
      await api('student_create',{method:'POST',body:{class_course_id:state.activeCCId,student_no,full_name,gender,email}});
      showToast('Student added to subject');
    }
    state.editingStudentId=null;closeModal('studentModal');await reloadBootstrap();await renderStudents();
  }catch(e){showToast(e.message)}
}
async function removeStudent(id,name){if(!confirm(`Remove ${name} from this subject? Existing student records outside this subject will be kept.`))return;try{await api('student_remove',{method:'POST',body:{class_course_id:state.activeCCId,student_id:id}});showToast('Student removed from subject');await reloadBootstrap();await renderStudents()}catch(e){showToast(e.message)}}

function allSubjectOptions(){
  const out=[];(state.bootstrap?.classes||[]).forEach(c=>(c.courses||[]).forEach(co=>out.push({...co,class_id:Number(c.id),class_name:c.class_name,level:c.year_level,section:c.section})));return out;
}
function openBulkStudentModal(ccId=state.activeCCId){
  const subjects=allSubjectOptions(); if(!subjects.length)return showToast('Add a subject first');
  el('bulkSubjectSelect').innerHTML=subjects.map(s=>`<option value="${s.class_course_id}">${escapeHtml(s.course_code)} · ${escapeHtml(s.course_description)} — ${escapeHtml(s.class_name)}</option>`).join('');
  const wanted=subjects.some(s=>Number(s.class_course_id)===Number(ccId))?Number(ccId):Number(subjects[0].class_course_id);el('bulkSubjectSelect').value=wanted;
  el('bulkStudentFile').value='';el('bulkStudentPaste').value='';state.bulkRows=[];renderBulkPreview([]);openModal('bulkStudentModal');
}
function parseDelimited(text,delimiter=','){
  text=String(text||'').replace(/^\uFEFF/,''); const rows=[];let row=[],cell='',quoted=false;
  for(let i=0;i<text.length;i++){const ch=text[i],next=text[i+1];if(quoted){if(ch==='"'&&next==='"'){cell+='"';i++;}else if(ch==='"')quoted=false;else cell+=ch;}else{if(ch==='"')quoted=true;else if(ch===delimiter){row.push(cell);cell='';}else if(ch==='\n'){row.push(cell);rows.push(row);row=[];cell='';}else if(ch!=='\r')cell+=ch;}}
  if(cell!==''||row.length){row.push(cell);rows.push(row);}return rows.filter(r=>r.some(v=>String(v).trim()!==''));
}
function bulkRowsFromText(text){
  const firstLine=String(text||'').split(/\r?\n/,1)[0]||'';const delimiter=(firstLine.match(/\t/g)||[]).length>(firstLine.match(/,/g)||[]).length?'\t':',';
  const raw=parseDelimited(text,delimiter); if(!raw.length)return [];
  const norm=x=>String(x||'').toLowerCase().trim().replace(/[_\s-]+/g,' '); const header=raw[0].map(norm);
  const find=(names)=>header.findIndex(h=>names.includes(h));
  let idIdx=find(['student id','student no','student number','id']);let nameIdx=find(['full name','student name','name']);let genderIdx=find(['gender','sex']);let emailIdx=find(['email','email address']);let start=1;
  if(idIdx<0||nameIdx<0){idIdx=0;nameIdx=1;genderIdx=2;emailIdx=3;start=0;}
  const normalizeGender=v=>{const g=String(v??'').trim().toLowerCase();if(g==='m'||g==='male')return'Male';if(g==='f'||g==='female')return'Female';return String(v??'').trim();};
  return raw.slice(start).map((r,i)=>({row:i+start+1,student_no:String(r[idIdx]??'').trim(),full_name:String(r[nameIdx]??'').trim(),gender:normalizeGender(genderIdx>=0?r[genderIdx]:''),email:String(emailIdx>=0?(r[emailIdx]??''):'').trim()})).filter(r=>r.student_no||r.full_name||r.gender||r.email);
}
function studentDuplicateInfo(rows){
  const seenPairs=new Set(),seenIds=new Map(),info=new Map();
  const normId=v=>String(v||'').trim().toLowerCase();
  const normName=v=>String(v||'').trim().replace(/\s+/g,' ').toLowerCase();
  rows.forEach(r=>{
    const id=normId(r.student_no),name=normName(r.full_name),pair=`${id}\u0000${name}`;
    if(!id||!name)return;
    if(seenPairs.has(pair)){info.set(r,'Duplicate ID + name — skipped');return;}
    if(seenIds.has(id)&&seenIds.get(id)!==name){info.set(r,'Duplicate Student ID with a different name — skipped');return;}
    seenPairs.add(pair);seenIds.set(id,name);
  });
  return info;
}
function renderBulkPreview(rows){
  if(!rows.length){el('bulkPreview').innerHTML='<div class="empty">Choose a CSV file or paste student rows to preview the upload.</div>';return;}
  const dup=studentDuplicateInfo(rows);
  const isValid=r=>r.student_no&&r.full_name&&['Male','Female'].includes(r.gender)&&!dup.has(r);
  const valid=rows.filter(isValid).length;const duplicates=[...dup.values()].length;const shown=rows.slice(0,12);
  el('bulkPreview').innerHTML=`<div class="bulk-preview-head"><strong>${valid} unique student${valid===1?'':'s'} ready</strong><span class="muted">${duplicates?`${duplicates} duplicate/conflict row${duplicates===1?'':'s'} will be skipped · `:''}${rows.length-valid-duplicates} other row${rows.length-valid-duplicates===1?'':'s'} need attention${rows.length>12?' · showing first 12':''}</span></div><div class="table-wrap bulk-preview-table"><table class="data-table"><thead><tr><th>Row</th><th>Student ID</th><th style="text-align:left">Full Name</th><th>Gender</th><th>Email</th><th>Status</th></tr></thead><tbody>${shown.map(r=>{const issue=dup.get(r);const ready=isValid(r);return `<tr><td>${r.row}</td><td>${escapeHtml(r.student_no||'—')}</td><td style="text-align:left">${escapeHtml(r.full_name||'—')}</td><td>${escapeHtml(r.gender||'—')}</td><td>${escapeHtml(r.email||'—')}</td><td><span class="status ${ready?'pass':'review'}">${ready?'Ready':escapeHtml(issue||'ID, name, and Male/Female required')}</span></td></tr>`}).join('')}</tbody></table></div>`;
}
async function previewBulkStudents(){
  const text=el('bulkStudentPaste').value.trim(); if(!text){state.bulkRows=[];renderBulkPreview([]);return showToast('Choose a CSV file or paste CSV data');}
  state.bulkRows=bulkRowsFromText(text);renderBulkPreview(state.bulkRows);if(!state.bulkRows.length)showToast('No student rows found');
}
function uniqueBulkStudents(rows){
  const seenPairs=new Set(),seenIds=new Map(),out=[];
  const normId=v=>String(v||'').trim().toLowerCase();
  const normName=v=>String(v||'').trim().replace(/\s+/g,' ').toLowerCase();
  rows.forEach(r=>{
    if(!r.student_no||!r.full_name||!['Male','Female'].includes(r.gender))return;
    const id=normId(r.student_no),name=normName(r.full_name),pair=`${id}\u0000${name}`;
    if(seenPairs.has(pair))return;
    if(seenIds.has(id)&&seenIds.get(id)!==name)return;
    seenPairs.add(pair);seenIds.set(id,name);out.push({student_no:r.student_no,full_name:r.full_name,gender:r.gender,email:r.email});
  });
  return out;
}
async function importBulkStudents(){
  if(!state.bulkRows.length)await previewBulkStudents(); const students=uniqueBulkStudents(state.bulkRows);
  if(!students.length)return showToast('No valid unique students to import'); const class_course_id=Number(el('bulkSubjectSelect').value);if(!class_course_id)return showToast('Select a subject');
  const btn=el('importBulkStudentsBtn');btn.disabled=true;btn.textContent='Importing…';
  try{const r=await api('student_bulk_create',{method:'POST',body:{class_course_id,students}});showToast(`${r.assigned} assigned · ${r.created} new · ${r.updated} updated${r.duplicates_skipped?` · ${r.duplicates_skipped} duplicates skipped`:''}${r.skipped?` · ${r.skipped} invalid skipped`:''}`);closeModal('bulkStudentModal');state.activeCCId=class_course_id;const subject=allSubjectOptions().find(x=>Number(x.class_course_id)===class_course_id);if(subject)state.activeClassId=Number(subject.class_id);await reloadBootstrap();if(state.view==='subjects')await renderSubjects();else{state.view='students';document.querySelectorAll('.nav-btn').forEach(b=>b.classList.toggle('active',b.dataset.view==='students'));await refreshView();}}
  catch(e){showToast(e.message)}finally{btn.disabled=false;btn.textContent='Import & Assign Students';}
}
async function downloadStudentsForSubject(ccId=state.activeCCId){
  ccId=Number(ccId||0);if(!ccId)return showToast('Select a subject first');
  try{
    const d=await api('students',{query:{class_course_id:ccId}});const subject=allSubjectOptions().find(x=>Number(x.class_course_id)===ccId);
    const lines=[['Student ID','Full Name','Gender','Email'].map(csvValue).join(',')];
    (d.students||[]).forEach(s=>lines.push([s.student_no,s.full_name,s.gender||'',s.email||''].map(csvValue).join(',')));
    const csv='\uFEFF'+lines.join('\r\n')+'\r\n';const blob=new Blob([csv],{type:'text/csv;charset=utf-8'}),url=URL.createObjectURL(blob),a=document.createElement('a');
    const code=String(subject?.course_code||'subject').replace(/[^a-z0-9]+/gi,'_');const section=String(subject?.class_name||'class').replace(/[^a-z0-9]+/gi,'_');
    a.href=url;a.download=`${code}_${section}_students.csv`;a.click();setTimeout(()=>URL.revokeObjectURL(url),500);showToast(`${d.students?.length||0} students downloaded for ${subject?.course_code||'selected subject'}`);
  }catch(e){showToast(e.message)}
}
function downloadStudentTemplate(){
  const csv='Student ID,Full Name,Gender,Email\r\n2026-0001,Juan Dela Cruz,Male,juan@example.com\r\n2026-0002,Maria Santos,Female,\r\n';const blob=new Blob([csv],{type:'text/csv;charset=utf-8'}),url=URL.createObjectURL(blob),a=document.createElement('a');a.href=url;a.download='student_bulk_upload_template.csv';a.click();setTimeout(()=>URL.revokeObjectURL(url),500);
}

function csvValue(value){
  const text=String(value??'');return /[",\r\n]/.test(text)?`"${text.replace(/"/g,'""')}"`:text;
}
function assessmentUploadHeader(a){
  const max=Number(a.max_score);return `${labels[a.category]||a.category} - ${a.title} (HPS ${Number.isInteger(max)?max:max.toFixed(2)}) [#${a.id}]`;
}
function bulkAssessmentDestination(){
  return {class_course_id:Number(el('bulkAssessmentSubjectSelect').value||0),term:el('bulkAssessmentTermSelect').value||'prelim'};
}
async function openBulkAssessmentModal(ccId=state.activeCCId,term=state.activeTerm){
  const subjects=allSubjectOptions();if(!subjects.length)return showToast('Add a subject first');
  el('bulkAssessmentSubjectSelect').innerHTML=subjects.map(s=>`<option value="${s.class_course_id}">${escapeHtml(s.course_code)} · ${escapeHtml(s.course_description)} — ${escapeHtml(s.class_name)}</option>`).join('');
  const wanted=subjects.some(s=>Number(s.class_course_id)===Number(ccId))?Number(ccId):Number(subjects[0].class_course_id);el('bulkAssessmentSubjectSelect').value=wanted;
  el('bulkAssessmentTermSelect').value=['prelim','midterm','final'].includes(term)?term:'prelim';
  el('bulkAssessmentFile').value='';el('bulkAssessmentPaste').value='';state.bulkAssessmentRows=[];state.bulkAssessmentColumns=[];state.bulkAssessmentBook=null;
  openModal('bulkAssessmentModal');await loadBulkAssessmentContext();
}
async function resetBulkAssessmentDestination(){
  el('bulkAssessmentFile').value='';el('bulkAssessmentPaste').value='';state.bulkAssessmentRows=[];state.bulkAssessmentColumns=[];state.bulkAssessmentBook=null;await loadBulkAssessmentContext();
}
async function loadBulkAssessmentContext(){
  const {class_course_id,term}=bulkAssessmentDestination();
  if(!class_course_id)return;
  el('bulkAssessmentContext').innerHTML='<span class="muted">Loading assessment structure…</span>';
  try{
    const book=await api('gradebook',{query:{class_course_id,term}});state.bulkAssessmentBook=book;
    const a=book.assessments||[];const cats={};a.forEach(x=>cats[x.category]=(cats[x.category]||0)+1);
    el('bulkAssessmentContext').innerHTML=`<div class="assessment-upload-context-main"><strong>${escapeHtml(book.context.course_code)} · ${escapeHtml(book.context.course_description)}</strong><span>${escapeHtml(book.context.class_name)} · ${term[0].toUpperCase()+term.slice(1)} · ${book.students.length} students</span></div><div class="assessment-upload-chips">${categories.map(c=>`<span class="assessment-upload-chip">${labels[c]}: ${cats[c]||0}</span>`).join('')}</div>${a.length?'':'<div class="upload-warning">No assessments exist for this term yet. Add assessments before uploading scores.</div>'}`;
    renderBulkAssessmentPreview([]);
  }catch(e){state.bulkAssessmentBook=null;el('bulkAssessmentContext').innerHTML=`<div class="upload-warning">${escapeHtml(e.message)}</div>`;renderBulkAssessmentPreview([]);}
}
function assessmentRowsFromText(text){
  const book=state.bulkAssessmentBook;if(!book)return [];
  const firstLine=String(text||'').split(/\r?\n/,1)[0]||'';const delimiter=(firstLine.match(/\t/g)||[]).length>(firstLine.match(/,/g)||[]).length?'\t':',';
  const raw=parseDelimited(text,delimiter);if(raw.length<2)return [];
  const norm=x=>String(x||'').toLowerCase().trim().replace(/[_\s-]+/g,' ');const header=raw[0].map(x=>String(x||'').trim()),headerNorm=header.map(norm);
  let idIdx=headerNorm.findIndex(h=>['student id','student no','student number','id'].includes(h));let nameIdx=headerNorm.findIndex(h=>['full name','student name','name'].includes(h));
  if(idIdx<0)idIdx=0;
  const byId=new Map(book.assessments.map(a=>[Number(a.id),a]));const byExact=new Map(book.assessments.map(a=>[norm(assessmentUploadHeader(a)),a]));
  const titleCounts={};book.assessments.forEach(a=>{const k=norm(a.title);titleCounts[k]=(titleCounts[k]||0)+1});
  const cols=[];
  header.forEach((h,index)=>{
    if(index===idIdx||index===nameIdx)return;
    let a=null;const idMatch=h.match(/\[#(\d+)\]\s*$/);if(idMatch)a=byId.get(Number(idMatch[1]))||null;
    if(!a)a=byExact.get(norm(h))||null;
    if(!a&&titleCounts[norm(h)]===1)a=book.assessments.find(x=>norm(x.title)===norm(h))||null;
    if(a&&Number(a.id))cols.push({index,assessment:a});
  });
  state.bulkAssessmentColumns=cols;
  const assigned=new Map(book.students.map(s=>[String(s.student_no).toLowerCase(),s]));
  return raw.slice(1).map((r,i)=>{
    const student_no=String(r[idIdx]??'').trim(),full_name=String(nameIdx>=0?(r[nameIdx]??''):'').trim();const scores={},issues=[];let entered=0;
    cols.forEach(({index,assessment})=>{const rawValue=String(r[index]??'').trim();scores[assessment.id]=rawValue;if(rawValue!==''){entered++;const n=Number(rawValue);if(!Number.isFinite(n))issues.push(`${assessment.title}: not numeric`);else if(n<0||n>Number(assessment.max_score))issues.push(`${assessment.title}: must be 0–${Number(assessment.max_score)}`);}});
    if(!student_no)issues.push('Missing Student ID');else if(!assigned.has(student_no.toLowerCase()))issues.push('Student not assigned to this subject');
    if(entered===0)issues.push('No scores entered');
    return {row:i+2,student_no,full_name,scores,issues,entered};
  }).filter(r=>r.student_no||r.full_name||r.entered);
}
function renderBulkAssessmentPreview(rows){
  const book=state.bulkAssessmentBook;
  if(!book){el('bulkAssessmentPreview').innerHTML='<div class="empty">Select a valid subject and term first.</div>';return;}
  if(!(book.assessments||[]).length){el('bulkAssessmentPreview').innerHTML='<div class="empty">No assessments are available for this subject and term.</div>';return;}
  if(!rows.length){el('bulkAssessmentPreview').innerHTML=`<div class="empty">Download the score template for ${escapeHtml(book.context.course_code)}, fill in the scores, then upload or paste it here. Blank score cells preserve existing database values.</div>`;return;}
  const ready=rows.filter(r=>!r.issues.length).length,scoreCells=rows.reduce((n,r)=>n+r.entered,0),shown=rows.slice(0,12),byId=new Map(book.assessments.map(a=>[String(a.id),a]));
  el('bulkAssessmentPreview').innerHTML=`<div class="bulk-preview-head"><strong>${ready} ready row${ready===1?'':'s'} · ${scoreCells} entered score${scoreCells===1?'':'s'}</strong><span class="muted">${rows.length-ready} row${rows.length-ready===1?'':'s'} need attention${rows.length>12?' · showing first 12':''}</span></div><div class="table-wrap bulk-preview-table"><table class="data-table assessment-preview-table"><thead><tr><th>Row</th><th>Student ID</th><th style="text-align:left">Student</th><th style="text-align:left">Entered Scores</th><th>Status</th></tr></thead><tbody>${shown.map(r=>{const parts=Object.entries(r.scores).filter(([,v])=>String(v).trim()!=='').slice(0,5).map(([id,v])=>{const a=byId.get(String(id));return a?`${escapeHtml(a.title)}: ${escapeHtml(v)}/${Number(a.max_score)}`:''}).filter(Boolean);return `<tr><td>${r.row}</td><td>${escapeHtml(r.student_no||'—')}</td><td style="text-align:left">${escapeHtml(r.full_name||'—')}</td><td style="text-align:left">${parts.join(' · ')||'—'}${r.entered>5?' …':''}</td><td><span class="status ${r.issues.length?'review':'pass'}">${r.issues.length?escapeHtml(r.issues[0]):'Ready'}</span></td></tr>`}).join('')}</tbody></table></div>`;
}
async function previewBulkAssessments(){
  if(!state.bulkAssessmentBook)await loadBulkAssessmentContext();const text=el('bulkAssessmentPaste').value.trim();if(!text){state.bulkAssessmentRows=[];renderBulkAssessmentPreview([]);return showToast('Choose a CSV file or paste score data');}
  state.bulkAssessmentRows=assessmentRowsFromText(text);
  if(!state.bulkAssessmentColumns.length){state.bulkAssessmentRows=[];renderBulkAssessmentPreview([]);return showToast('No assessment columns matched this subject and term. Use the generated template.');}
  renderBulkAssessmentPreview(state.bulkAssessmentRows);if(!state.bulkAssessmentRows.length)showToast('No student score rows found');
}
async function downloadAssessmentTemplate(){
  if(!state.bulkAssessmentBook)await loadBulkAssessmentContext();const book=state.bulkAssessmentBook;if(!book)return showToast('Unable to load the selected subject');if(!book.assessments.length)return showToast('Add assessments for this term first');
  const header=['Student ID','Full Name',...book.assessments.map(assessmentUploadHeader)];const lines=[header.map(csvValue).join(',')];
  book.students.forEach(s=>{const scoreMap=book.scores?.[s.id]||{};lines.push([s.student_no,s.full_name,...book.assessments.map(a=>scoreMap[a.id]??'')].map(csvValue).join(','))});
  const csv='\uFEFF'+lines.join('\r\n')+'\r\n',blob=new Blob([csv],{type:'text/csv;charset=utf-8'}),url=URL.createObjectURL(blob),a=document.createElement('a');const safe=String(book.context.course_code||'subject').replace(/[^a-z0-9]+/gi,'_');a.href=url;a.download=`${safe}_${bulkAssessmentDestination().term}_assessment_scores.csv`;a.click();setTimeout(()=>URL.revokeObjectURL(url),500);showToast('Assessment score template downloaded');
}
async function importBulkAssessments(){
  if(!state.bulkAssessmentRows.length)await previewBulkAssessments();if(!state.bulkAssessmentRows.length)return;
  const {class_course_id,term}=bulkAssessmentDestination();const rows=state.bulkAssessmentRows.map(r=>({row:r.row,student_no:r.student_no,scores:r.scores}));const btn=el('importBulkAssessmentsBtn');btn.disabled=true;btn.textContent='Saving to MySQL…';
  try{
    const r=await api('assessment_bulk_scores',{method:'POST',body:{class_course_id,term,rows}});showToast(`${r.saved_scores} scores saved · ${r.students_processed} students processed${r.cells_skipped?` · ${r.cells_skipped} cells skipped`:''}`);
    if(r.errors?.length){const errs=r.errors.slice(0,8).map(x=>`<li>Row ${x.row}${x.assessment?` · ${escapeHtml(x.assessment)}`:''}: ${escapeHtml(x.message)}</li>`).join('');el('bulkAssessmentPreview').insertAdjacentHTML('afterbegin',`<div class="upload-result warning-result"><strong>${r.saved_scores} scores saved, but some entries were skipped.</strong><ul>${errs}</ul>${r.errors.length>8?`<div class="muted">Showing 8 of ${r.errors.length} reported issues.</div>`:''}</div>`);}else{
      closeModal('bulkAssessmentModal');state.activeCCId=class_course_id;state.activeTerm=term;const subject=allSubjectOptions().find(x=>Number(x.class_course_id)===class_course_id);if(subject)state.activeClassId=Number(subject.class_id);populateClasses();syncCourseOptions();el('termSelect').value=term;state.view='grade';document.querySelectorAll('.nav-btn').forEach(b=>b.classList.toggle('active',b.dataset.view==='grade'));await refreshView();
    }
  }catch(e){showToast(e.message)}finally{btn.disabled=false;btn.textContent='Import & Save Scores';}
}

async function reloadBootstrap(){
  const oldClass=state.activeClassId,oldCC=state.activeCCId;state.bootstrap=await api('bootstrap');
  let c=state.bootstrap.classes.find(x=>Number(x.id)===Number(oldClass));
  if(!c)c=state.bootstrap.classes.find(x=>x.courses?.length)||state.bootstrap.classes[0];
  state.activeClassId=Number(c?.id||0);
  if(!c?.courses?.some(x=>Number(x.class_course_id)===Number(oldCC)))state.activeCCId=Number(c?.courses?.[0]?.class_course_id||0);else state.activeCCId=Number(oldCC);
  populateClasses();syncCourseOptions();
}

function categoryEquivalent(studentId,cat){const items=state.book.assessments.filter(a=>a.category===cat);const max=items.reduce((n,a)=>n+Number(a.max_score),0);const raw=items.reduce((n,a)=>n+Number(state.book.scores?.[studentId]?.[a.id]||0),0);return max?(raw/max*50+50):0}
function currentEquivalent(studentId){const weights=resolvedWeights(state.book?.weights);return categories.reduce((sum,c)=>sum+categoryEquivalent(studentId,c)*weights[c],0)}
function termGrade(studentId){const cur=currentEquivalent(studentId);if(state.activeTerm==='prelim')return cur;return Number(state.book.previous[studentId]||0)*0.30+cur*0.70}
function filteredStudents(){const q=(state.search||'').toLowerCase();return state.book.students.filter(s=>{const m=s.full_name.toLowerCase().includes(q)||s.student_no.toLowerCase().includes(q);const g=termGrade(s.id);return m&&(state.filter==='all'||(state.filter==='passing'&&passingStatus(g))||(state.filter==='review'&&!passingStatus(g)))})}
function getGradeViewport(){
  const wrap=document.querySelector('.grade-table-wrap');
  return wrap?{scrollLeft:wrap.scrollLeft,scrollTop:wrap.scrollTop}:null;
}
function restoreGradeViewport(viewport,focusKey){
  requestAnimationFrame(()=>{
    const wrap=document.querySelector('.grade-table-wrap');
    if(wrap&&viewport){wrap.scrollLeft=viewport.scrollLeft||0;wrap.scrollTop=viewport.scrollTop||0;}
    if(focusKey){
      const target=document.querySelector(`.score-input[data-student="${focusKey.student}"][data-assessment="${focusKey.assessment}"]`);
      if(target){target.focus({preventScroll:true});target.select?.();}
    }
  });
}
function resetGradeViewport(){
  requestAnimationFrame(()=>{
    const wrap=document.querySelector('.grade-table-wrap');
    if(wrap){wrap.scrollLeft=0;wrap.scrollTop=0;}
    const first=document.querySelector('.grade-table tbody tr:first-child');
    if(first){first.classList.add('first-student-ready');}
  });
}
function renderGrade(options={}){
  el('topActions').innerHTML='<button class="btn btn-light" id="downloadExcel">⇩ Download Term Excel</button><span class="save-pill"><span class="save-pill-dot"></span>MySQL Auto-Save</span>';
  el('downloadExcel').onclick=downloadTermXLSX;
  const students=filteredStudents();const grades=state.book.students.map(s=>termGrade(s.id));const avg=grades.length?grades.reduce((a,b)=>a+b,0)/grades.length:0;const pass=grades.filter(g=>passingStatus(g)).length;const weights=resolvedWeights(state.book?.weights);
  el('viewContainer').innerHTML=`
  <section class="summary"><div class="stat"><span>Students</span><strong>${state.book.students.length}</strong></div><div class="stat"><span>Assessments</span><strong>${state.book.assessments.length}</strong></div><div class="stat"><span>Class Average</span><strong id="classAverage">${avg.toFixed(2)}</strong></div><div class="stat"><span>Passing</span><strong id="passingTotal">${pass} / ${grades.length}</strong></div><div class="stat"><span>Term Formula</span><strong style="font-size:14px">${state.activeTerm==='prelim'?'Current equivalent':'30% previous + 70% current'}</strong></div></section>
  <div class="weight-strip">${categories.map(c=>`<span class="weight ${c==='quiz'?'wq':c==='exam'?'we':c==='performance'?'wp':'wo'}">${labels[c]} · ${Math.round(weights[c]*100)}%</span>`).join('')}</div>
  <div class="toolbar"><div class="toolbar-left"><input id="studentSearch" class="search" placeholder="Search student or ID" value="${escapeHtml(state.search)}"><select id="viewFilter" class="small-select"><option value="all">All students</option><option value="passing">Passing</option><option value="review">For review</option></select></div><div class="toolbar-right"><button class="btn btn-light" id="bulkAssessmentUpload">⇧ Bulk Upload Scores</button><button class="btn btn-light" id="fillMissing">Fill blanks with 0</button><button class="btn btn-light" id="pasteScores">Paste scores</button><button class="btn btn-primary" id="openAssessment">+ Add Assessment</button></div></div>
  <section class="gradebook"><div class="table-wrap grade-table-wrap" aria-label="Grade entry table"><table class="grade-table" id="gradeTable">${gradeTableHtml(students)}</table></div><div class="footer-note"><span>Equivalent = (raw score ÷ HPS × 50) + 50. Scores save automatically to MySQL.</span><span>Showing ${students.length} of ${state.book.students.length} students</span></div></section>`;
  el('viewFilter').value=state.filter;
  const searchBox=el('studentSearch');
  searchBox.oninput=e=>{
    const cursor=e.target.selectionStart;
    state.search=e.target.value;
    renderGrade();
    requestAnimationFrame(()=>{
      const input=el('studentSearch');
      if(input){
        input.focus({preventScroll:true});
        input.setSelectionRange(cursor,cursor);
      }
    });
  };
  el('viewFilter').onchange=e=>{state.filter=e.target.value;renderGrade()};
  el('openAssessment').onclick=()=>{el('assessmentModalTitle').textContent='Add Assessment';el('newCategory').disabled=false;el('newTitle').value='';el('newMax').value=20;el('newDate').value=new Date().toISOString().slice(0,10);openModal('assessmentModal')};
  el('bulkAssessmentUpload').onclick=()=>openBulkAssessmentModal(state.activeCCId,state.activeTerm);el('fillMissing').onclick=fillMissing;el('pasteScores').onclick=pasteScores; bindGradeInputs();
  restoreGradeViewport(options.viewport||null,options.focusKey||null);
}
function gradeTableHtml(students){
  const weights=resolvedWeights(state.book?.weights);
  let h='<thead><tr><th rowspan="2" class="identity sticky-no">#</th><th rowspan="2" class="identity sticky-id">Student ID</th><th rowspan="2" class="identity sticky-name">Student Name</th>';
  categories.forEach(c=>{const count=state.book.assessments.filter(a=>a.category===c).length;h+=`<th colspan="${count+2}" class="group ${c}">${labels[c]} · ${Math.round(weights[c]*100)}%</th>`});
  h+=`<th rowspan="2" class="calc">Current<br>Equivalent</th><th rowspan="2" class="calc">${state.activeTerm==='prelim'?'Previous':state.activeTerm==='midterm'?'Prelim 30%':'Midterm 30%'}</th><th rowspan="2" class="calc">${state.activeTerm[0].toUpperCase()+state.activeTerm.slice(1)} Grade</th><th rowspan="2" class="calc">Remarks</th></tr><tr>`;
  categories.forEach(c=>{state.book.assessments.filter(a=>a.category===c).forEach(a=>h+=`<th class="assess-head"><div class="assess-title" title="${escapeHtml(a.title)}">${escapeHtml(a.title)}</div><div class="max-score">HPS ${Number(a.max_score)}</div><button class="head-menu remove-assessment" data-id="${a.id}" data-title="${escapeHtml(a.title)}">×</button></th>`);h+='<th>Total</th><th>Weighted</th>'});h+='</tr></thead><tbody>';
  students.forEach((s,i)=>{h+=`<tr data-student-row="${s.id}"><td class="sticky-no">${i+1}</td><td class="sticky-id">${escapeHtml(s.student_no)}</td><td class="sticky-name"><div class="student-name"><div class="avatar">${initials(s.full_name)}</div><strong>${escapeHtml(s.full_name)}</strong></div></td>`;categories.forEach(c=>{const items=state.book.assessments.filter(a=>a.category===c);items.forEach(a=>{const v=state.book.scores?.[s.id]?.[a.id]??'';h+=`<td><input class="score-input" type="number" min="0" max="${Number(a.max_score)}" value="${v}" data-student="${s.id}" data-assessment="${a.id}"></td>`});const pct=categoryEquivalent(s.id,c);h+=`<td class="total-cell" data-grade-kind="category" data-category="${c}">${pct.toFixed(1)}%</td><td class="total-cell" data-grade-kind="weighted" data-category="${c}">${(pct*weights[c]).toFixed(2)}</td>`});const cur=currentEquivalent(s.id),prev=state.activeTerm==='prelim'?null:Number(state.book.previous[s.id]||0),grade=termGrade(s.id);h+=`<td class="total-cell" data-grade-kind="current">${cur.toFixed(2)}</td><td class="total-cell" data-grade-kind="previous" ${prev===null?'':`title="Computed grade: ${prev.toFixed(2)}"`}>${prev===null?'—':roundedGrade(prev)}</td><td class="term-grade" data-grade-kind="term" title="Computed grade: ${grade.toFixed(2)}">${roundedGrade(grade)}</td><td><span class="status ${passingStatus(grade)?'pass':'review'}" data-grade-kind="remarks">${remarkLabel(grade)}</span></td></tr>`});return h+'</tbody>';
}
function updateGradeRow(studentId){
  const row=document.querySelector(`.grade-table tbody tr[data-student-row="${studentId}"]`);
  if(!row)return;
  categories.forEach(c=>{
    const pct=categoryEquivalent(studentId,c);
    const catCell=row.querySelector(`[data-grade-kind="category"][data-category="${c}"]`);
    const weightedCell=row.querySelector(`[data-grade-kind="weighted"][data-category="${c}"]`);
    if(catCell)catCell.textContent=`${pct.toFixed(1)}%`;
    if(weightedCell){const weights=resolvedWeights(state.book?.weights);weightedCell.textContent=(pct*weights[c]).toFixed(2);}
  });
  const cur=currentEquivalent(studentId),grade=termGrade(studentId);
  const currentCell=row.querySelector('[data-grade-kind="current"]');
  const termCell=row.querySelector('[data-grade-kind="term"]');
  const remarks=row.querySelector('[data-grade-kind="remarks"]');
  if(currentCell)currentCell.textContent=cur.toFixed(2);
  if(termCell){termCell.textContent=roundedGrade(grade);termCell.title=`Computed grade: ${grade.toFixed(2)}`;}
  if(remarks){remarks.textContent=remarkLabel(grade);remarks.classList.toggle('pass',passingStatus(grade));remarks.classList.toggle('review',!passingStatus(grade));}
}
function updateGradeSummary(){
  const grades=state.book.students.map(s=>termGrade(s.id));
  const avg=grades.length?grades.reduce((a,b)=>a+b,0)/grades.length:0;
  const pass=grades.filter(g=>passingStatus(g)).length;
  const avgEl=el('classAverage'),passEl=el('passingTotal');
  if(avgEl)avgEl.textContent=avg.toFixed(2);
  if(passEl)passEl.textContent=`${pass} / ${grades.length}`;
}
function bindGradeInputs(){
  document.querySelectorAll('.score-input').forEach(inp=>{inp.onchange=async()=>{
    let v=inp.value===''?'':Math.max(0,Math.min(Number(inp.max),Number(inp.value)||0));
    inp.value=v;state.book.scores[inp.dataset.student]??={};state.book.scores[inp.dataset.student][inp.dataset.assessment]=v;
    updateGradeRow(inp.dataset.student);updateGradeSummary();setSaveState('Saving…',false);
    try{await api('save_score',{method:'POST',body:{assessment_id:inp.dataset.assessment,student_id:inp.dataset.student,score:v}});setSaveState('Saved to MySQL',true)}catch(e){setSaveState('Save failed',false);showToast(e.message)}
  };inp.onkeydown=e=>{if(e.key==='Enter'){
    e.preventDefault();
    const cells=[...document.querySelectorAll(`.score-input[data-assessment="${inp.dataset.assessment}"]`)];
    const i=cells.indexOf(inp),next=cells[i+1];
    if(next){next.focus({preventScroll:true});next.select?.();next.scrollIntoView({block:'nearest',inline:'nearest'});}
  }}});
  document.querySelectorAll('.remove-assessment').forEach(b=>b.onclick=()=>removeAssessment(b.dataset.id,b.dataset.title));
}
function setSaveState(text,ok){el('saveText').textContent=text;document.querySelector('.save-dot').style.background=ok?'#168464':'#b66a00'}
async function createAssessment(){const category=el('newCategory').value,title=el('newTitle').value.trim(),max_score=Number(el('newMax').value),assessment_date=el('newDate').value;if(!title||max_score<1)return showToast('Enter an assessment name and valid HPS');try{await api('assessment_create',{method:'POST',body:{class_course_id:state.activeCCId,term:state.activeTerm,category,title,max_score,assessment_date}});closeModal('assessmentModal');showToast(`${title} added`);await refreshView();resetGradeViewport()}catch(e){showToast(e.message)}}
async function removeAssessment(id,title){if(!confirm(`Remove ${title} and all recorded scores?`))return;try{await api('assessment_delete',{method:'POST',body:{assessment_id:id}});showToast('Assessment removed');await refreshView();resetGradeViewport()}catch(e){showToast(e.message)}}
async function fillMissing(){try{await api('fill_missing',{method:'POST',body:{class_course_id:state.activeCCId,term:state.activeTerm}});showToast('Blank scores filled with 0');await refreshView()}catch(e){showToast(e.message)}}
async function pasteScores(){const raw=prompt('Paste one score per line for the first visible assessment:');if(!raw)return;const first=state.book.assessments[0];if(!first)return;const vals=raw.split(/\s+/).filter(Boolean);for(let i=0;i<vals.length&&i<state.book.students.length;i++){const s=state.book.students[i];const v=Math.max(0,Math.min(Number(first.max_score),Number(vals[i])||0));await api('save_score',{method:'POST',body:{assessment_id:first.id,student_id:s.id,score:v}})}showToast(`Scores pasted into ${first.title}`);await refreshView()}


function formatDateTime(value){
  if(!value)return 'No deadline';
  const d=new Date(String(value).replace(' ','T'));
  return Number.isNaN(d.getTime())?String(value):d.toLocaleString([], {year:'numeric',month:'short',day:'numeric',hour:'numeric',minute:'2-digit'});
}
function activityStatusBadge(a){
  if(!Number(a.is_published))return '<span class="status review">Hidden</span>';
  if(a.due_at && new Date(String(a.due_at).replace(' ','T')).getTime()<Date.now() && !Number(a.allow_late))return '<span class="status activity-closed">Closed</span>';
  return '<span class="status pass">Open</span>';
}
async function renderActivities(){
  if(!state.activeCCId){el('topActions').innerHTML='';el('viewContainer').innerHTML='<div class="panel"><h3>No subject selected</h3><p>Select a subject before creating student outputs.</p></div>';return;}
  el('topActions').innerHTML='<button class="btn btn-primary" id="openActivityCreator">+ Create Student Output</button>';
  el('openActivityCreator').onclick=openActivityCreator;
  const d=await api('activities',{query:{class_course_id:state.activeCCId,term:state.activeTerm}});state.activityData=d;
  const rows=d.activities||[];
  const submitted=rows.reduce((n,a)=>n+Number(a.submitted_count||0),0),graded=rows.reduce((n,a)=>n+Number(a.graded_count||0),0);
  el('viewContainer').innerHTML=`<section class="summary"><div class="stat"><span>Activities</span><strong>${rows.length}</strong></div><div class="stat"><span>Published</span><strong>${rows.filter(a=>Number(a.is_published)).length}</strong></div><div class="stat"><span>Student Submissions</span><strong>${submitted}</strong></div><div class="stat"><span>Graded</span><strong>${graded}</strong></div><div class="stat"><span>Selected Term</span><strong style="font-size:14px">${escapeHtml(state.activeTerm[0].toUpperCase()+state.activeTerm.slice(1))}</strong></div></section>
  <section class="panel activity-intro"><div><span class="muted">Selected Subject</span><h3>${escapeHtml(d.context.course_code)} · ${escapeHtml(d.context.course_description)}</h3><p>${escapeHtml(d.context.class_name)} · Create an output and choose whether its score belongs to Quiz, Exam, Performance Task, or Output. The assessment column is created in Grade Entry immediately.</p></div><button class="btn btn-primary" id="createActivityInline">+ Create Student Output</button></section>
  <section class="panel" style="padding:0"><div class="table-wrap"><table class="data-table activity-table"><thead><tr><th style="text-align:left">Activity</th><th>Credited To</th><th>HPS</th><th>Deadline</th><th>Submitted</th><th>Graded</th><th>Status</th><th>Actions</th></tr></thead><tbody>${rows.map(a=>`<tr><td style="text-align:left"><strong>${escapeHtml(a.title)}</strong><small class="activity-instructions-preview">${escapeHtml(a.instructions||'No instructions')}</small></td><td><span class="activity-category ${escapeHtml(a.category)}">${escapeHtml(labels[a.category]||a.category)}</span></td><td>${Number(a.max_score).toFixed(Number(a.max_score)%1?2:0)}</td><td>${escapeHtml(formatDateTime(a.due_at))}</td><td>${Number(a.submitted_count||0)} / ${Number(a.assigned_count||0)}</td><td>${Number(a.graded_count||0)}</td><td>${activityStatusBadge(a)}</td><td><div class="subject-actions"><button class="btn btn-light btn-sm activity-submissions" data-id="${a.id}" data-title="${escapeHtml(a.title)}">Submissions</button><button class="btn btn-light btn-sm activity-publish" data-id="${a.id}" data-published="${Number(a.is_published)?0:1}">${Number(a.is_published)?'Hide':'Publish'}</button><button class="btn btn-danger btn-sm activity-delete" data-id="${a.id}" data-title="${escapeHtml(a.title)}">Delete</button></div></td></tr>`).join('')||'<tr><td colspan="8" class="empty">No student outputs have been created for this subject and term.</td></tr>'}</tbody></table></div></section>`;
  el('createActivityInline').onclick=openActivityCreator;
  document.querySelectorAll('.activity-submissions').forEach(b=>b.onclick=()=>openActivitySubmissions(Number(b.dataset.id),b.dataset.title));
  document.querySelectorAll('.activity-publish').forEach(b=>b.onclick=()=>toggleActivityPublish(Number(b.dataset.id),Number(b.dataset.published)));
  document.querySelectorAll('.activity-delete').forEach(b=>b.onclick=()=>deleteActivity(Number(b.dataset.id),b.dataset.title));
}
function openActivityCreator(){
  const co=activeCourse(),cl=activeClass();
  el('activityContext').innerHTML=`<strong>${escapeHtml(co?.course_code||'')} · ${escapeHtml(co?.course_description||'')}</strong><span>${escapeHtml(cl?.class_name||'')} · ${escapeHtml(state.activeTerm[0].toUpperCase()+state.activeTerm.slice(1))}</span>`;
  el('activityTitle').value='';el('activityCategory').value='output';el('activityMax').value='20';el('activityInstructions').value='';el('activityAllowLate').checked=false;el('activityPublished').value='1';
  const due=new Date(Date.now()+7*24*60*60*1000);due.setMinutes(due.getMinutes()-due.getTimezoneOffset());el('activityDue').value=due.toISOString().slice(0,16);
  openModal('activityModal');
}
async function createActivity(){
  const title=el('activityTitle').value.trim(),category=el('activityCategory').value,max_score=Number(el('activityMax').value),instructions=el('activityInstructions').value.trim(),due_at=el('activityDue').value,allow_late=el('activityAllowLate').checked?1:0,is_published=Number(el('activityPublished').value);
  if(!title||!max_score||max_score<=0)return showToast('Enter an activity title and valid HPS');
  try{const r=await api('activity_create',{method:'POST',body:{class_course_id:state.activeCCId,term:state.activeTerm,category,title,max_score,instructions,due_at,allow_late,is_published}});closeModal('activityModal');showToast(`${title} created and added to Gradebook`);await renderActivities();}
  catch(e){showToast(e.message)}
}
async function toggleActivityPublish(id,is_published){try{await api('activity_toggle_publish',{method:'POST',body:{activity_id:id,is_published}});showToast(is_published?'Activity published':'Activity hidden from students');await renderActivities()}catch(e){showToast(e.message)}}
async function deleteActivity(id,title){if(!confirm(`Delete ${title}? This also removes its Gradebook assessment, saved scores, and uploaded submissions.`))return;try{await api('activity_delete',{method:'POST',body:{activity_id:id}});showToast('Activity removed');await renderActivities()}catch(e){showToast(e.message)}}
async function openActivitySubmissions(id,title){
  state.activeActivityId=id;el('activitySubmissionsTitle').textContent=title||'Student Submissions';el('activitySubmissionsMeta').textContent='Loading…';el('activitySubmissionsBody').innerHTML='<div class="empty">Loading submissions…</div>';openModal('activitySubmissionsModal');
  try{const d=await api('activity_submissions',{query:{activity_id:id}});const a=d.activity,rows=d.students||[];el('activitySubmissionsMeta').textContent=`${a.course_code} · ${a.class_name} · ${labels[a.category]||a.category} · HPS ${Number(a.max_score)} · ${formatDateTime(a.due_at)}`;
    el('activitySubmissionsBody').innerHTML=`<div class="submissions-summary"><span><b>${rows.length}</b> students</span><span><b>${rows.filter(r=>r.submission_id).length}</b> submitted</span><span><b>${rows.filter(r=>r.score!==null&&r.score!=='').length}</b> graded</span></div><div class="table-wrap"><table class="data-table submission-table"><thead><tr><th style="text-align:left">Student</th><th>Status</th><th>Submitted</th><th>Work</th><th>Score</th><th style="text-align:left">Feedback</th><th>Save</th></tr></thead><tbody>${rows.map(r=>`<tr><td style="text-align:left"><strong>${escapeHtml(r.full_name)}</strong><small>${escapeHtml(r.student_no)} · ${escapeHtml(r.gender||'')}</small></td><td>${submissionTeacherStatus(r,a)}</td><td>${r.submitted_at?escapeHtml(formatDateTime(r.submitted_at)):'—'}</td><td>${r.submission_id?`<div class="submission-work">${r.original_filename?`<div class="submission-file-actions"><a class="btn btn-primary btn-sm" target="_blank" rel="noopener" href="submission_view.php?submission_id=${r.submission_id}">Preview</a><a class="btn btn-light btn-sm" href="${API}?action=submission_download&submission_id=${r.submission_id}">Download</a></div><small class="submission-file-name" title="${escapeHtml(r.original_filename)}">${escapeHtml(r.original_filename)}</small>`:''}${r.submission_note?`<small title="${escapeHtml(r.submission_note)}">${escapeHtml(r.submission_note)}</small>`:''}</div>`:'—'}</td><td><input class="submission-score" type="number" min="0" max="${Number(a.max_score)}" step="0.01" value="${r.score===null||r.score===''?'':Number(r.score)}" data-student="${r.student_id}" ${r.submission_id?'':'disabled'}></td><td style="text-align:left"><textarea class="submission-feedback" rows="2" data-student="${r.student_id}" ${r.submission_id?'':'disabled'}>${escapeHtml(r.teacher_feedback||'')}</textarea></td><td><button class="btn btn-primary btn-sm save-submission-grade" data-student="${r.student_id}" ${r.submission_id?'':'disabled'}>Save Grade</button></td></tr>`).join('')}</tbody></table></div>`;
    document.querySelectorAll('.save-submission-grade').forEach(b=>b.onclick=()=>saveSubmissionGrade(Number(b.dataset.student),Number(a.id),Number(a.max_score)));
  }catch(e){el('activitySubmissionsBody').innerHTML=`<div class="empty">${escapeHtml(e.message)}</div>`;}
}
function submissionTeacherStatus(r,a){
  if(!r.submission_id)return '<span class="status review">Not submitted</span>';
  if(r.score!==null&&r.score!=='')return '<span class="status pass">Graded</span>';
  const late=a.due_at&&new Date(String(r.submitted_at).replace(' ','T')).getTime()>new Date(String(a.due_at).replace(' ','T')).getTime();
  return late?'<span class="status activity-late">Late</span>':'<span class="status activity-submitted">Submitted</span>';
}
async function saveSubmissionGrade(studentId,activityId,max){
  const scoreEl=document.querySelector(`.submission-score[data-student="${studentId}"]`),feedbackEl=document.querySelector(`.submission-feedback[data-student="${studentId}"]`);const score=scoreEl.value===''?null:Number(scoreEl.value);
  if(score===null||!Number.isFinite(score)||score<0||score>max)return showToast(`Enter a score from 0 to ${max}`);
  try{await api('submission_grade',{method:'POST',body:{activity_id:activityId,student_id:studentId,score,teacher_feedback:feedbackEl.value.trim()}});showToast('Submission graded and Gradebook updated');await openActivitySubmissions(activityId,el('activitySubmissionsTitle').textContent)}catch(e){showToast(e.message)}
}

function attendanceStatusClass(status){
  const key=String(status||'Unmarked').toLowerCase();
  return ['present','late','absent','excused'].includes(key)?`attendance-${key}`:'attendance-unmarked';
}
function attendanceButtonClass(status,selected){
  const key=String(status||'').toLowerCase();
  return `attendance-quick-btn attendance-btn-${key}${selected?' active':''}`;
}
function updateAttendanceSummary(){
  const counts={Unmarked:0,Present:0,Late:0,Absent:0,Excused:0};
  document.querySelectorAll('.attendance-status-badge').forEach(b=>{const s=b.dataset.status||'Unmarked';counts[s]=(counts[s]||0)+1});
  Object.entries(counts).forEach(([status,count])=>{const target=document.querySelector(`[data-attendance-count="${status}"]`);if(target)target.textContent=count});
}
function updateAttendanceRowUI(id,status){
  const badge=document.querySelector(`.attendance-status-badge[data-id="${id}"]`);
  if(badge){badge.dataset.status=status;badge.textContent=status;badge.className=`attendance-status-badge ${attendanceStatusClass(status)}`}
  document.querySelectorAll(`.attendance-quick-btn[data-id="${id}"]`).forEach(btn=>{
    const active=btn.dataset.status===status;
    btn.classList.toggle('active',active);
    btn.setAttribute('aria-pressed',active?'true':'false');
  });
  updateAttendanceSummary();
}

function getPerformerRows(){
  const data=state.performerData;
  if(!data?.students)return [];
  // Backend returns calculated grades. Keep all students for ranking calculations,
  // then filter only qualified top performers (90 and above).
  const basis=state.performerBasis||state.activeTerm||'prelim';
  const rows=data.students.map(s=>({...s})).sort((a,b)=>{
    const roundedDiff=roundedGrade(b[basis])-roundedGrade(a[basis]);
    if(roundedDiff!==0)return roundedDiff;
    const rawDiff=Number(b[basis]||0)-Number(a[basis]||0);
    return rawDiff!==0?rawDiff:String(a.full_name||'').localeCompare(String(b.full_name||''));
  });
  let lastGrade=null,lastRank=0;
  rows.forEach((r,i)=>{
    const g=roundedGrade(r[basis]);
    if(lastGrade===null || g!==lastGrade) lastRank=i+1;
    r.rank=lastRank; lastGrade=g;
  });
  // Top Performers qualification uses the rounded displayed grade.
  // Example: 89.49 displays as 90 and qualifies.
  const qualifiedRows=rows.filter(r=>roundedGrade(r[basis])>=90);
  const limit=state.performerLimit==='all'?qualifiedRows.length:Number(state.performerLimit||10);
  return qualifiedRows.slice(0,Math.max(0,limit));
}
function performerBasisLabel(){
  return {prelim:'Prelim Grade',midterm:'Midterm Grade',final:'Final Grade',overall:'Overall Average'}[state.performerBasis]||'Prelim Grade';
}
function performerStatus(grade){return remarkLabel(grade)}
function performerPodium(rows){
  const top=rows.slice(0,3);
  if(!top.length)return '<div class="empty">No students achieved 90.00 or above for this ranking basis.</div>';
  return `<div class="performer-podium">${top.map((s,i)=>`<article class="performer-card performer-place-${i+1}">
      <div class="performer-medal">#${s.rank}</div>
      <div class="avatar performer-avatar">${initials(s.full_name)}</div>
      <div class="performer-card-copy"><strong>${escapeHtml(s.full_name)}</strong><span>${escapeHtml(s.student_no)} · ${escapeHtml(s.gender||'Not set')}</span></div>
      <div class="performer-score" title="Computed grade: ${Number(s[state.performerBasis]||0).toFixed(2)}">${roundedGrade(s[state.performerBasis])}</div>
      <small>${performerBasisLabel()}</small>
    </article>`).join('')}</div>`;
}
async function renderTopPerformers(){
  el('topActions').innerHTML='<button class="btn btn-light" id="downloadPerformersCsv">⇩ Download CSV</button><button class="btn btn-primary" id="printPerformers">Print Ranking</button>';
  try{
    state.performerData=await api('top_performers',{query:{class_course_id:state.activeCCId}});
  }catch(e){
    el('viewContainer').innerHTML=`<div class="panel"><h3>Unable to generate ranking</h3><p>${escapeHtml(e.message)}</p></div>`;
    return;
  }
  if(!['prelim','midterm','final','overall'].includes(state.performerBasis))state.performerBasis=state.activeTerm||'prelim';
  const draw=()=>{
    const all=state.performerData.students||[];
    const rows=getPerformerRows();
    const basis=state.performerBasis;
    const grades=all.map(s=>Number(s[basis]||0));
    const highest=grades.length?Math.max(...grades):0;
    const average=grades.length?grades.reduce((a,b)=>a+b,0)/grades.length:0;
    const passing=grades.filter(g=>passingStatus(g)).length;
    const ctx=state.performerData.context||{};
    el('viewContainer').innerHTML=`
      <div id="topPerformersPrintArea">
        <section class="panel performer-header">
          <div class="performer-title-block">
            <span class="muted">Selected Subject</span>
            <h3>${escapeHtml(ctx.course_code||'')} · ${escapeHtml(ctx.course_description||'')}</h3>
            <p>${escapeHtml(ctx.class_name||'')} · ${escapeHtml(ctx.semester||'')} · SY ${escapeHtml(ctx.school_year||'')}</p>
          </div>
          <div class="performer-filters">
            <div class="form-field"><label>Ranking Basis</label><select id="performerBasis">
              <option value="prelim" ${basis==='prelim'?'selected':''}>Prelim</option>
              <option value="midterm" ${basis==='midterm'?'selected':''}>Midterm</option>
              <option value="final" ${basis==='final'?'selected':''}>Final</option>
              <option value="overall" ${basis==='overall'?'selected':''}>Overall Average</option>
            </select></div>
            <div class="form-field"><label>Show</label><select id="performerLimit">
              <option value="5" ${String(state.performerLimit)==='5'?'selected':''}>Top 5</option>
              <option value="10" ${String(state.performerLimit)==='10'?'selected':''}>Top 10</option>
              <option value="20" ${String(state.performerLimit)==='20'?'selected':''}>Top 20</option>
              <option value="all" ${state.performerLimit==='all'?'selected':''}>All Students</option>
            </select></div>
          </div>
        </section>

        <section class="summary performer-summary">
          <div class="stat"><span>Students</span><strong>${all.length}</strong></div>
          <div class="stat"><span>Highest ${escapeHtml(performerBasisLabel())}</span><strong>${roundedGrade(highest)}</strong></div>
          <div class="stat"><span>Subject Average</span><strong>${average.toFixed(2)}</strong></div>
          <div class="stat"><span>Passing</span><strong>${passing} / ${all.length}</strong></div>
        </section>

        ${performerPodium(rows)}

        <section class="panel performer-table-panel">
          <div class="panel-heading performer-table-heading"><div><h3>Subject Ranking</h3><p>Ranked by ${escapeHtml(performerBasisLabel())}. Tied grades receive the same rank.</p></div><span class="status pass">${rows.length} shown</span></div>
          <div class="table-wrap performer-table-wrap"><table class="data-table performer-table">
            <thead><tr><th>Rank</th><th>Student ID</th><th style="text-align:left">Student Name</th><th>Gender</th><th>Prelim</th><th>Midterm</th><th>Final</th><th>Overall Average</th><th>Status</th></tr></thead>
            <tbody>${rows.map(s=>`<tr>
              <td><span class="rank-badge ${s.rank<=3?'top-rank':''}">${s.rank}</span></td>
              <td>${escapeHtml(s.student_no)}</td>
              <td style="text-align:left"><div class="student-name"><div class="avatar">${initials(s.full_name)}</div><strong>${escapeHtml(s.full_name)}</strong></div></td>
              <td>${escapeHtml(s.gender||'—')}</td>
              <td class="${basis==='prelim'?'rank-highlight':''}">${roundedGrade(s.prelim)}</td>
              <td class="${basis==='midterm'?'rank-highlight':''}">${roundedGrade(s.midterm)}</td>
              <td class="${basis==='final'?'rank-highlight':''}">${roundedGrade(s.final)}</td>
              <td class="${basis==='overall'?'rank-highlight':''}">${roundedGrade(s.overall)}</td>
              <td><span class="status ${passingStatus(s[basis])?'pass':'review'}">${performerStatus(s[basis])}</span></td>
            </tr>`).join('')||'<tr><td colspan="9" class="empty">No students found.</td></tr>'}</tbody>
          </table></div>
          <div class="footer-note"><span>Overall Average = (Prelim Grade + Midterm Grade + Final Grade) ÷ 3.</span><span>Generated from saved MySQL scores</span></div>
        </section>
      </div>`;
    el('performerBasis').onchange=e=>{state.performerBasis=e.target.value;draw()};
    el('performerLimit').onchange=e=>{state.performerLimit=e.target.value==='all'?'all':Number(e.target.value);draw()};
  };
  draw();
  el('downloadPerformersCsv').onclick=downloadPerformersCsv;
  el('printPerformers').onclick=printTopPerformers;
}
function downloadPerformersCsv(){
  if(!state.performerData)return;
  const rows=getPerformerRows(),basis=performerBasisLabel();
  const csvRows=[['Rank','Student ID','Full Name','Gender','Prelim Grade','Midterm Grade','Final Grade','Overall Average','Ranking Basis','Ranking Grade']];
  rows.forEach(s=>csvRows.push([s.rank,s.student_no,s.full_name,s.gender||'',roundedGrade(s.prelim),roundedGrade(s.midterm),roundedGrade(s.final),roundedGrade(s.overall),basis,roundedGrade(s[state.performerBasis])]));
  const csv='\uFEFF'+csvRows.map(r=>r.map(csvValue).join(',')).join('\r\n')+'\r\n';
  const ctx=state.performerData.context||{};
  const file=`${String(ctx.course_code||'subject').replace(/[^a-z0-9]+/gi,'_')}_${state.performerBasis}_top_performers.csv`;
  const blob=new Blob([csv],{type:'text/csv;charset=utf-8'}),url=URL.createObjectURL(blob),a=document.createElement('a');
  a.href=url;a.download=file;a.click();setTimeout(()=>URL.revokeObjectURL(url),500);
  showToast('Top performers CSV downloaded');
}
function printTopPerformers(){
  document.body.classList.add('print-top-performers');
  const cleanup=()=>document.body.classList.remove('print-top-performers');
  window.addEventListener('afterprint',cleanup,{once:true});
  window.print();
  setTimeout(cleanup,1500);
}

async function renderAttendance(){
  el('topActions').innerHTML='';const today=new Date().toISOString().slice(0,10);const date=state.attendanceDate||today;state.attendanceDate=date;const d=await api('attendance',{query:{class_course_id:state.activeCCId,date}});
  const counts={Unmarked:0,Present:0,Late:0,Absent:0,Excused:0};d.records.forEach(r=>{const s=r.status||'Unmarked';counts[s]=(counts[s]||0)+1});
  const statuses=['Present','Late','Absent','Excused'];
  el('viewContainer').innerHTML=`<section class="summary attendance-summary"><div class="stat"><span>Unmarked</span><strong data-attendance-count="Unmarked">${counts.Unmarked}</strong></div><div class="stat"><span>Present</span><strong data-attendance-count="Present">${counts.Present}</strong></div><div class="stat"><span>Late</span><strong data-attendance-count="Late">${counts.Late}</strong></div><div class="stat"><span>Absent</span><strong data-attendance-count="Absent">${counts.Absent}</strong></div><div class="stat"><span>Excused</span><strong data-attendance-count="Excused">${counts.Excused}</strong></div></section>
  <div class="toolbar attendance-toolbar"><div class="toolbar-left"><label class="attendance-date-field"><strong>Date</strong><span class="attendance-date-input"><input type="date" id="attendanceDate" value="${date}"></span></label></div><div class="toolbar-right"><span class="muted attendance-help">Click a quick button to save attendance immediately.</span></div></div>
  <section class="panel attendance-panel" style="padding:0"><div class="table-wrap attendance-table-wrap"><table class="data-table attendance-table"><thead><tr><th>#</th><th>Student ID</th><th style="text-align:left">Student Name</th><th>Status</th><th>Quick Buttons</th><th style="text-align:left">Remarks</th></tr></thead><tbody>${d.records.map((r,i)=>{const current=r.status||'Unmarked';return `<tr data-attendance-row="${r.id}"><td>${i+1}</td><td>${escapeHtml(r.student_no)}</td><td style="text-align:left"><strong>${escapeHtml(r.full_name)}</strong></td><td><span class="attendance-status-badge ${attendanceStatusClass(current)}" data-id="${r.id}" data-status="${current}">${current}</span></td><td><div class="attendance-quick-buttons">${statuses.map(status=>`<button type="button" class="${attendanceButtonClass(status,status===current)}" data-id="${r.id}" data-status="${status}" aria-pressed="${status===current?'true':'false'}">${status}</button>`).join('')}</div></td><td><input class="remarks-input att-remarks" data-id="${r.id}" value="${escapeHtml(r.remarks)}" placeholder="Optional remarks"></td></tr>`}).join('')}</tbody></table></div></section>`;
  el('attendanceDate').onchange=e=>{state.attendanceDate=e.target.value;renderAttendance()};
  document.querySelectorAll('.attendance-quick-btn').forEach(btn=>btn.onclick=()=>saveAttendanceStatus(btn.dataset.id,btn.dataset.status));
  document.querySelectorAll('.att-remarks').forEach(inp=>inp.onchange=()=>saveAttendanceRemarks(inp.dataset.id));
}
async function saveAttendanceStatus(id,status){
  const remarks=document.querySelector(`.att-remarks[data-id="${id}"]`)?.value||'';
  const buttons=[...document.querySelectorAll(`.attendance-quick-btn[data-id="${id}"]`)];
  buttons.forEach(b=>b.disabled=true);
  try{
    await api('attendance_save',{method:'POST',body:{class_course_id:state.activeCCId,student_id:id,date:state.attendanceDate||new Date().toISOString().slice(0,10),status,remarks}});
    updateAttendanceRowUI(id,status);
    showToast(`${status} saved`);
  }catch(e){showToast(e.message)}finally{buttons.forEach(b=>b.disabled=false)}
}
async function saveAttendanceRemarks(id){
  const badge=document.querySelector(`.attendance-status-badge[data-id="${id}"]`);
  const status=badge?.dataset.status||'Unmarked';
  if(status==='Unmarked'){showToast('Choose an attendance status before saving remarks');return}
  const remarks=document.querySelector(`.att-remarks[data-id="${id}"]`)?.value||'';
  try{await api('attendance_save',{method:'POST',body:{class_course_id:state.activeCCId,student_id:id,date:state.attendanceDate||new Date().toISOString().slice(0,10),status,remarks}});showToast('Remarks saved')}catch(e){showToast(e.message)}
}

// Minimal XLSX writer adapted from the original prototype so no external library is required.
function xmlEscape(v){return String(v??'').replaceAll('&','&amp;').replaceAll('<','&lt;').replaceAll('>','&gt;').replaceAll('"','&quot;').replaceAll("'",'&apos;')}
function colName(n){let s='';while(n>0){n--;s=String.fromCharCode(65+n%26)+s;n=Math.floor(n/26)}return s}
function crc32(bytes){if(!crc32.table){crc32.table=new Uint32Array(256);for(let n=0;n<256;n++){let c=n;for(let k=0;k<8;k++)c=(c&1)?(0xEDB88320^(c>>>1)):(c>>>1);crc32.table[n]=c>>>0}}let c=0xFFFFFFFF;for(const b of bytes)c=crc32.table[(c^b)&0xFF]^(c>>>8);return(c^0xFFFFFFFF)>>>0}
function u16(a,v){a.push(v&255,(v>>>8)&255)}function u32(a,v){a.push(v&255,(v>>>8)&255,(v>>>16)&255,(v>>>24)&255)}
function zipStored(files){const enc=new TextEncoder(),out=[],central=[];let offset=0;for(const file of files){const name=enc.encode(file.name),data=typeof file.data==='string'?enc.encode(file.data):file.data,crc=crc32(data),local=[];u32(local,0x04034b50);u16(local,20);u16(local,0x0800);u16(local,0);u16(local,0);u16(local,0);u32(local,crc);u32(local,data.length);u32(local,data.length);u16(local,name.length);u16(local,0);local.push(...name,...data);out.push(...local);const cd=[];u32(cd,0x02014b50);u16(cd,20);u16(cd,20);u16(cd,0x0800);u16(cd,0);u16(cd,0);u16(cd,0);u32(cd,crc);u32(cd,data.length);u32(cd,data.length);u16(cd,name.length);u16(cd,0);u16(cd,0);u16(cd,0);u16(cd,0);u32(cd,0);u32(cd,offset);cd.push(...name);central.push(...cd);offset+=local.length}const centralOffset=out.length;out.push(...central);const end=[];u32(end,0x06054b50);u16(end,0);u16(end,0);u16(end,files.length);u16(end,files.length);u32(end,central.length);u32(end,centralOffset);u16(end,0);out.push(...end);return new Uint8Array(out)}
function cell(ref,value,style=0,type='n',formula=null){const s=style?` s="${style}"`:'';if(formula!==null)return`<c r="${ref}"${s}><f>${xmlEscape(formula)}</f></c>`;if(value===null||value===undefined||value==='')return`<c r="${ref}"${s}/>`;if(type==='s')return`<c r="${ref}" t="inlineStr"${s}><is><t xml:space="preserve">${xmlEscape(value)}</t></is></c>`;return`<c r="${ref}"${s}><v>${Number(value)}</v></c>`}
function buildClassRecordXlsx(payload){const cats=[{key:'quiz',label:'Quizzes (20%)',weight:.20},{key:'performance',label:'Performances (30%)',weight:.30},{key:'output',label:'Outputs (20%)',weight:.20},{key:'exam',label:'Exam',weight:.30}],assessments=payload.assessments||[];let col=3;const layout={};for(const cat of cats){const items=assessments.filter(a=>a.category===cat.key),rawStart=col,rawEnd=col+Math.max(items.length,1)-1;col=rawEnd+1;const hasTotal=cat.key!=='exam'||items.length>1,total=hasTotal?col++:rawStart,equivalent=col++,weighted=col++;layout[cat.key]={...cat,items,rawStart,rawEnd,total,equivalent,weighted,hasTotal}}const gradeCol=col++,rankCol=col++,previousCol=col++,lastRow=11+payload.students.length,rows=[],merges=[];const row=(r,cells,h)=>rows.push(`<row r="${r}"${h?` ht="${h}" customHeight="1"`:''}>${cells.join('')}</row>`),topLast=colName(gradeCol);for(let r=1;r<=5;r++)merges.push(`A${r}:${topLast}${r}`);merges.push(`A6:${topLast}6`,`A7:${topLast}7`,`A8:${topLast}8`,`A9:B10`);for(const cat of cats){const l=layout[cat.key];merges.push(`${colName(l.rawStart)}9:${colName(l.total)}9`,`${colName(l.equivalent)}9:${colName(l.equivalent)}11`,`${colName(l.weighted)}9:${colName(l.weighted)}11`)}merges.push(`${colName(gradeCol)}9:${colName(gradeCol)}11`,`${colName(rankCol)}9:${colName(rankCol)}11`);row(1,[cell('A1',payload.school,1,'s')],22);row(2,[cell('A2',payload.address,1,'s')],20);row(3,[cell('A3',payload.semester,1,'s')],20);row(4,[cell('A4','',1,'s')],10);row(5,[cell('A5','CLASS RECORD',1,'s')],22);row(6,[cell('A6',`Course Code: ${payload.courseCode}`,2,'s')],20);row(7,[cell('A7',`Course Description: ${payload.courseDescription}`,2,'s')],20);row(8,[cell('A8',`Term: ${payload.term.toUpperCase()}     Class/Section: ${payload.className}`,2,'s')],20);const r9=[cell('A9','Names',4,'s')];for(const cat of cats){const l=layout[cat.key];r9.push(cell(`${colName(l.rawStart)}9`,cat.label,3,'s'),cell(`${colName(l.equivalent)}9`,'Equivalent',4,'s'),cell(`${colName(l.weighted)}9`,`${Math.round(cat.weight*100)}%`,4,'s'))}r9.push(cell(`${colName(gradeCol)}9`,`${payload.termLabel} Grade`,5,'s'),cell(`${colName(rankCol)}9`,'RANK',9,'s'));row(9,r9,24);const r10=[];for(const cat of cats){const l=layout[cat.key];for(let c=l.rawStart;c<=l.rawEnd;c++)r10.push(cell(`${colName(c)}10`,'',4,'s'));if(l.hasTotal)r10.push(cell(`${colName(l.total)}10`,'Total',4,'s'))}row(10,r10,21);const r11=[cell('A11','#',8,'s'),cell('B11','Full Name',8,'s')];for(const cat of cats){const l=layout[cat.key];if(l.items.length)l.items.forEach((a,i)=>r11.push(cell(`${colName(l.rawStart+i)}11`,a.max_score,5)));else r11.push(cell(`${colName(l.rawStart)}11`,0,5));if(l.hasTotal){const start=colName(l.rawStart),end=colName(l.rawStart+Math.max(l.items.length,1)-1),tc=colName(l.total);r11.push(cell(`${tc}11`,null,5,'n',`SUM(${start}11:${end}11)`))}}row(11,r11,22);const weightedCols=cats.map(c=>colName(layout[c.key].weighted));payload.students.forEach((student,i)=>{const rr=12+i,cells=[cell(`A${rr}`,i+1,6),cell(`B${rr}`,student.name,7,'s')];for(const cat of cats){const l=layout[cat.key],scores=student.scores||{},rawCount=Math.max(l.items.length,1);for(let j=0;j<rawCount;j++){const a=l.items[j],v=a?(scores[a.id]??''):'';cells.push(cell(`${colName(l.rawStart+j)}${rr}`,v,6))}let totalRef;if(l.hasTotal){const start=colName(l.rawStart),end=colName(l.rawStart+rawCount-1),tc=colName(l.total);cells.push(cell(`${tc}${rr}`,null,5,'n',`SUM(${start}${rr}:${end}${rr})`));totalRef=`${tc}${rr}`}else totalRef=`${colName(l.rawStart)}${rr}`;const maxRef=l.hasTotal?`${colName(l.total)}$11`:`${colName(l.rawStart)}$11`,eq=colName(l.equivalent),wt=colName(l.weighted);cells.push(cell(`${eq}${rr}`,null,10,'n',`IFERROR(${totalRef}/${maxRef}*50+50,0)`),cell(`${wt}${rr}`,null,10,'n',`${eq}${rr}*${cat.weight}`))}const gc=colName(gradeCol),rc=colName(rankCol),pc=colName(previousCol),sumFormula=`SUM(${weightedCols.map(c=>`${c}${rr}`).join(',')})`;cells.push(cell(`${gc}${rr}`,null,8,'n',payload.term==='prelim'?`ROUND(${sumFormula},2)`:`ROUND(${pc}${rr}*0.3+${sumFormula}*0.7,2)`),cell(`${rc}${rr}`,null,9,'n',`RANK(${gc}${rr},$${gc}$12:$${gc}$${lastRow},0)`),cell(`${pc}${rr}`,student.previousGrade??0,0));row(rr,cells,20)});const cols=[`<col min="1" max="1" width="3.5" customWidth="1"/>`,`<col min="2" max="2" width="40" customWidth="1"/>`];for(const cat of cats){const l=layout[cat.key];for(let c=l.rawStart;c<=l.rawEnd;c++)cols.push(`<col min="${c}" max="${c}" width="6" customWidth="1"/>`);if(l.hasTotal)cols.push(`<col min="${l.total}" max="${l.total}" width="7" customWidth="1"/>`);cols.push(`<col min="${l.equivalent}" max="${l.equivalent}" width="9" customWidth="1"/>`,`<col min="${l.weighted}" max="${l.weighted}" width="7" customWidth="1"/>`)}cols.push(`<col min="${gradeCol}" max="${gradeCol}" width="13" customWidth="1"/>`,`<col min="${rankCol}" max="${rankCol}" width="8" customWidth="1"/>`,`<col min="${previousCol}" max="${previousCol}" hidden="1" width="0" customWidth="1"/>`);const sheet=`<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetPr><pageSetUpPr fitToPage="1"/></sheetPr><dimension ref="A1:${colName(previousCol)}${lastRow}"/><sheetViews><sheetView workbookViewId="0"><pane ySplit="11" topLeftCell="A12" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews><sheetFormatPr defaultRowHeight="15"/><cols>${cols.join('')}</cols><sheetData>${rows.join('')}</sheetData><mergeCells count="${merges.length}">${merges.map(m=>`<mergeCell ref="${m}"/>`).join('')}</mergeCells><pageMargins left="0.25" right="0.25" top="0.75" bottom="0.75" header="0.3" footer="0.3"/><pageSetup paperSize="9" orientation="landscape" fitToWidth="1" fitToHeight="0"/></worksheet>`;const styles=`<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><fonts count="5"><font><sz val="11"/><name val="Arial"/></font><font><b/><sz val="11"/><name val="Arial"/></font><font><sz val="10"/><name val="Arial"/></font><font><b/><sz val="10"/><name val="Arial"/></font><font><b/><sz val="9"/><name val="Arial"/></font></fonts><fills count="4"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FFFFFF00"/><bgColor indexed="64"/></patternFill></fill><fill><patternFill patternType="solid"><fgColor rgb="FF92D050"/><bgColor indexed="64"/></patternFill></fill></fills><borders count="2"><border><left/><right/><top/><bottom/><diagonal/></border><border><left style="thin"/><right style="thin"/><top style="thin"/><bottom style="thin"/><diagonal/></border></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="11"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="0" borderId="1" xfId="0" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf><xf numFmtId="0" fontId="2" fillId="0" borderId="1" xfId="0" applyAlignment="1"><alignment horizontal="left" vertical="center"/></xf><xf numFmtId="0" fontId="2" fillId="3" borderId="1" xfId="0" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf><xf numFmtId="0" fontId="2" fillId="0" borderId="1" xfId="0" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf><xf numFmtId="0" fontId="3" fillId="2" borderId="1" xfId="0" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf><xf numFmtId="0" fontId="2" fillId="0" borderId="1" xfId="0" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf><xf numFmtId="0" fontId="2" fillId="0" borderId="1" xfId="0" applyAlignment="1"><alignment horizontal="left" vertical="center"/></xf><xf numFmtId="2" fontId="3" fillId="2" borderId="1" xfId="0" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf><xf numFmtId="0" fontId="3" fillId="0" borderId="1" xfId="0" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf><xf numFmtId="2" fontId="2" fillId="0" borderId="1" xfId="0" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf></cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>`;const now=new Date().toISOString(),files=[{name:'[Content_Types].xml',data:`<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/><Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/><Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/></Types>`},{name:'_rels/.rels',data:`<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/><Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/></Relationships>`},{name:'xl/workbook.xml',data:`<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><bookViews><workbookView activeTab="0"/></bookViews><sheets><sheet name="${xmlEscape(payload.term)}" sheetId="1" r:id="rId1"/></sheets><calcPr calcMode="auto" fullCalcOnLoad="1" forceFullCalc="1"/></workbook>`},{name:'xl/_rels/workbook.xml.rels',data:`<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>`},{name:'xl/worksheets/sheet1.xml',data:sheet},{name:'xl/styles.xml',data:styles},{name:'docProps/core.xml',data:`<?xml version="1.0" encoding="UTF-8" standalone="yes"?><cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"><dc:creator>MyGradebook</dc:creator><cp:lastModifiedBy>MyGradebook</cp:lastModifiedBy><dcterms:created xsi:type="dcterms:W3CDTF">${now}</dcterms:created><dcterms:modified xsi:type="dcterms:W3CDTF">${now}</dcterms:modified></cp:coreProperties>`},{name:'docProps/app.xml',data:`<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties"><Application>MyGradebook</Application></Properties>`}];return zipStored(files)}
function downloadTermXLSX(){const c=activeClass(),co=activeCourse();const payload={school:state.bootstrap.settings.school_name||'CHRIST THE KING COLLEGE',address:state.bootstrap.settings.school_address||'9014 GINGOOG CITY',semester:`${co.semester} / SY ${co.school_year}`,courseCode:co.course_code,courseDescription:co.course_description,className:c.class_name,term:state.activeTerm,termLabel:state.activeTerm[0].toUpperCase()+state.activeTerm.slice(1),assessments:state.book.assessments,students:state.book.students.map(s=>({name:s.full_name,previousGrade:state.activeTerm==='prelim'?0:Number(state.book.previous[s.id]||0),scores:state.book.scores[s.id]||{}}))};const bytes=buildClassRecordXlsx(payload),blob=new Blob([bytes],{type:'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'}),url=URL.createObjectURL(blob),a=document.createElement('a');a.href=url;a.download=`${c.class_name.replace(/[^a-z0-9]+/gi,'_')}_${state.activeTerm}_class_record.xlsx`;a.click();setTimeout(()=>URL.revokeObjectURL(url),1000);showToast('Excel class record downloaded')}

document.addEventListener('DOMContentLoaded',init);
