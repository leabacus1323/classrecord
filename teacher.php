<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/asset_loader.php';
$user=requireSecureLoginPage($pdo,'teacher');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="theme-color" content="#172554">
  <title>MYGRADES · Teacher Dashboard</title>
  <style><?=mygradebook_asset('styles.css')?></style>
  <meta name="csrf-token" content="<?=htmlspecialchars(csrfToken())?>">
</head>
<body class="teacher-ui">
<div class="sidebar-backdrop" id="sidebarBackdrop" aria-hidden="true"></div>
<div class="app">
  <aside class="sidebar" id="sidebar" aria-label="Main navigation">
    <div class="brand"><div class="logo">MG</div><div class="brand-copy"><h2>MYGRADES</h2><small>Faculty Gradebook</small></div><button class="sidebar-close" id="sidebarClose" type="button" aria-label="Close navigation">×</button></div>
    <div class="side-label">Workspace</div>
    <nav>
      <button class="nav-btn" data-view="dashboard"><b><svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true"><path fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" d="M3 11.5 12 4l9 7.5M5 10v10h14V10M9 20v-6h6v6"/></svg></b><span>Dashboard</span></button>
      <button class="nav-btn" data-view="students"><b><svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true"><path fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8ZM22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/></svg></b><span>Students</span></button>
      <button class="nav-btn" data-view="subjects"><b><svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true"><path fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20M4 4.5A2.5 2.5 0 0 1 6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15Z"/></svg></b><span>Subjects</span></button>
      <button class="nav-btn active" data-view="grade"><b><svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true"><path fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" d="M4 4h16v16H4zM8 9h8M8 13h3M14 13h2M8 17h8"/></svg></b><span>Grade Entry</span></button>
      <button class="nav-btn" data-view="activities"><b><svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true"><path fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" d="M4 5h16v14H4zM8 9h8M8 13h5M7 2v3M17 2v3"/></svg></b><span>Activities & Submissions</span></button>
      <button class="nav-btn" data-view="performers"><b><svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true"><path fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" d="m12 2 3 6 6.5 1-4.7 4.6 1.1 6.4L12 17l-5.9 3 1.1-6.4L2.5 9 9 8l3-6Z"/></svg></b><span>Top Performers</span></button>
      <button class="nav-btn" data-view="attendance"><b><svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true"><path fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" d="M9 11l2 2 4-4M5 3h14a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2Z"/></svg></b><span>Attendance</span></button>
      <button class="nav-btn" data-view="accounts"><b><svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true"><path fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" d="M12 15.5A3.5 3.5 0 1 0 12 8a3.5 3.5 0 0 0 0 7.5ZM19.4 15a1.7 1.7 0 0 0 .34 1.88l.06.06-2.83 2.83-.06-.06A1.7 1.7 0 0 0 15 19.4a1.7 1.7 0 0 0-1 .6 1.7 1.7 0 0 0-.4 1.1V21H9.6v-.1A1.7 1.7 0 0 0 8.5 19.4a1.7 1.7 0 0 0-1.88.34l-.06.06-2.83-2.83.06-.06A1.7 1.7 0 0 0 4.1 15a1.7 1.7 0 0 0-.6-1 1.7 1.7 0 0 0-1.1-.4H2.3V9.6h.1A1.7 1.7 0 0 0 4.1 8.5a1.7 1.7 0 0 0-.34-1.88l-.06-.06 2.83-2.83.06.06A1.7 1.7 0 0 0 8.5 4.1a1.7 1.7 0 0 0 1-.6 1.7 1.7 0 0 0 .4-1.1V2.3h4v.1A1.7 1.7 0 0 0 15 4.1a1.7 1.7 0 0 0 1.88-.34l.06-.06 2.83 2.83-.06.06A1.7 1.7 0 0 0 19.4 8.5a1.7 1.7 0 0 0 .6 1 1.7 1.7 0 0 0 1.1.4h.1v4h-.1A1.7 1.7 0 0 0 19.4 15Z"/></svg></b><span>Accounts & Passwords</span></button>
      <button class="nav-btn" data-view="security"><b><svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true"><path fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" d="M12 3 20 6v5c0 5-3.4 8.5-8 10-4.6-1.5-8-5-8-10V6l8-3Zm-3 9 2 2 4-5"/></svg></b><span>Student Security Logs</span></button>
    </nav>
    <div class="side-label">My Subjects</div>
    <div class="class-mini subject-mini" id="subjectMini"></div>
    <div class="teacher-card"><div class="teacher-avatar"><?=htmlspecialchars(strtoupper(substr($user['display_name'],0,2)))?></div><div><strong id="teacherName"><?=htmlspecialchars($user['display_name'])?></strong><small>Teacher account · <a href="change_password.php" class="sidebar-signout">Change password</a> · <a href="logout.php" class="sidebar-signout">Sign out</a></small></div></div>
  </aside>

  <main class="main">
    <div class="topbar">
      <div class="title-wrap"><button class="mobile-menu-toggle" id="mobileMenuToggle" type="button" aria-label="Open navigation" aria-controls="sidebar" aria-expanded="false"><span>☰</span><span>Menu</span></button><div class="title"><h1 id="viewTitle">Class Grade Entry</h1><p id="viewSubtitle">Enter and compute scores for every student in the selected class.</p></div></div>
      <div class="top-actions" id="topActions"></div>
    </div>

    <section class="context-card" id="contextCard">
      <div class="field"><label>Class and Section</label><select id="classSelect"></select></div>
      <div class="field"><label>Course Description</label><select id="subjectSelect"></select></div>
      <div class="field"><label>Grading Term</label><select id="termSelect"><option value="prelim">Prelim</option><option value="midterm">Midterm</option><option value="final">Final</option></select></div>
      <div class="field"><label>Course Code</label><input id="courseCode" readonly></div>
      <div class="field"><label>Semester / School Year</label><input id="semesterSY" readonly></div>
      <div class="save-state"><span class="save-dot"></span><span id="saveText">Connected to MySQL</span></div>
    </section>

    <div id="viewContainer"></div>
  </main>
</div>

<div class="modal" id="assessmentModal">
  <div class="modal-box">
    <div class="modal-head"><h3 id="assessmentModalTitle">Add Assessment</h3><button class="close" data-close="assessmentModal">×</button></div>
    <div class="form-grid">
      <div class="form-field"><label>Category</label><select id="newCategory"><option value="quiz">Quiz</option><option value="exam">Exam</option><option value="performance">Performance Task</option><option value="output">Output</option></select></div>
      <div class="form-field"><label>Highest Possible Score</label><input id="newMax" type="number" min="1" value="20"></div>
      <div class="form-field full"><label>Assessment Name</label><input id="newTitle" placeholder="Example: Quiz 3"></div>
      <div class="form-field"><label>Date</label><input id="newDate" type="date"></div>
      <div class="form-field"><label>Apply to</label><select disabled><option>Selected class only</option></select></div>
    </div>
    <div class="modal-actions"><button class="btn btn-light" data-close="assessmentModal">Cancel</button><button class="btn btn-primary" id="addAssessmentBtn">Add Assessment</button></div>
  </div>
</div>

<div class="modal" id="studentModal">
  <div class="modal-box">
    <div class="modal-head"><h3 id="studentModalTitle">Add Student</h3><button class="close" data-close="studentModal">×</button></div>
    <div class="form-grid">
      <div class="form-field"><label>Student ID</label><input id="studentNo" placeholder="2026-0001"></div>
      <div class="form-field"><label>Gender</label><select id="studentGender"><option value="">Select gender</option><option value="Male">Male</option><option value="Female">Female</option></select></div>
      <div class="form-field full"><label>Full Name</label><input id="studentFullName" placeholder="Student full name"></div>
      <div class="form-field full"><label>Email</label><input id="studentEmail" type="email" placeholder="optional"></div>
    </div>
    <div class="modal-actions"><button class="btn btn-light" data-close="studentModal">Cancel</button><button class="btn btn-primary" id="addStudentBtn">Add Student</button></div>
  </div>
</div>


<div class="modal" id="bulkStudentModal">
  <div class="modal-box modal-wide">
    <div class="modal-head"><div><h3>Bulk Upload Students</h3><p class="muted modal-subtitle">Download the current subject roster, edit it in Excel/CSV, then upload it back. Duplicate Student ID + Full Name rows are kept only once.</p></div><button class="close" data-close="bulkStudentModal">×</button></div>
    <div class="form-grid bulk-form-grid">
      <div class="form-field full"><label>Assign to Subject</label><select id="bulkSubjectSelect"></select></div>
      <div class="form-field full"><label>CSV File</label><input id="bulkStudentFile" type="file" accept=".csv,text/csv,.txt,text/plain"><div class="field-help">Required columns: Student ID, Full Name, Gender. Email is optional. Existing students are updated by Student ID; exact duplicate ID + name rows are skipped after the first occurrence.</div></div>
      <div class="form-field full"><label>Or Paste CSV / Excel Rows</label><textarea id="bulkStudentPaste" rows="7" placeholder="Student ID,Full Name,Gender,Email&#10;2026-0001,Juan Dela Cruz,Male,juan@example.com&#10;2026-0002,Maria Santos,Female,"></textarea></div>
    </div>
    <div class="bulk-tools"><button class="btn btn-light" id="downloadCurrentSubjectStudentsBtn">⇩ Download Current Subject</button><button class="btn btn-light" id="downloadStudentTemplateBtn">⇩ Blank CSV Template</button><button class="btn btn-light" id="previewBulkStudentsBtn">Preview Students</button></div>
    <div id="bulkPreview" class="bulk-preview"><div class="empty">Choose a CSV file or paste student rows to preview the upload.</div></div>
    <div class="modal-actions"><button class="btn btn-light" data-close="bulkStudentModal">Cancel</button><button class="btn btn-primary" id="importBulkStudentsBtn">Import & Assign Students</button></div>
  </div>
</div>

<div class="modal" id="bulkAssessmentModal">
  <div class="modal-box modal-wide">
    <div class="modal-head"><div><h3>Bulk Upload Assessment Scores</h3><p class="muted modal-subtitle">Upload or paste student scores for a selected subject and grading term. Scores are saved directly to MySQL.</p></div><button class="close" data-close="bulkAssessmentModal">×</button></div>
    <div class="form-grid bulk-form-grid">
      <div class="form-field"><label>Subject</label><select id="bulkAssessmentSubjectSelect"></select></div>
      <div class="form-field"><label>Grading Term</label><select id="bulkAssessmentTermSelect"><option value="prelim">Prelim</option><option value="midterm">Midterm</option><option value="final">Final</option></select></div>
      <div class="form-field full"><div id="bulkAssessmentContext" class="assessment-upload-context"></div></div>
      <div class="form-field full"><label>CSV File</label><input id="bulkAssessmentFile" type="file" accept=".csv,text/csv,.txt,text/plain"><div class="field-help">Use the downloadable template for the selected subject/term. You may also fill the template in Excel, then save as CSV or paste the copied cells below.</div></div>
      <div class="form-field full"><label>Or Paste CSV / Excel Rows</label><textarea id="bulkAssessmentPaste" rows="8" placeholder="Student ID,Full Name,Quiz - Quiz 1 (HPS 20),Performance Task - Performance 1 (HPS 50),Output - Output 1 (HPS 30)"></textarea></div>
    </div>
    <div class="bulk-tools"><button class="btn btn-light" id="downloadAssessmentTemplateBtn">⇩ Download Score Template</button><button class="btn btn-light" id="previewBulkAssessmentsBtn">Preview Scores</button></div>
    <div id="bulkAssessmentPreview" class="bulk-preview"><div class="empty">Select a subject and term, then download the template or paste assessment scores.</div></div>
    <div class="modal-actions"><button class="btn btn-light" data-close="bulkAssessmentModal">Cancel</button><button class="btn btn-primary" id="importBulkAssessmentsBtn">Import & Save Scores</button></div>
  </div>
</div>


<div class="modal" id="activityModal">
  <div class="modal-box modal-wide">
    <div class="modal-head"><div><h3>Create Student Output</h3><p class="muted modal-subtitle">The activity is also created as a Gradebook assessment for the selected subject and term.</p></div><button class="close" data-close="activityModal">×</button></div>
    <div id="activityContext" class="activity-context"></div>
    <div class="form-grid">
      <div class="form-field full"><label>Activity / Output Title</label><input id="activityTitle" placeholder="Example: Chapter 3 Reflection Paper"></div>
      <div class="form-field"><label>Credit To</label><select id="activityCategory"><option value="quiz">Quiz</option><option value="exam">Exam</option><option value="performance">Performance Task</option><option value="output" selected>Output</option></select></div>
      <div class="form-field"><label>Highest Possible Score</label><input id="activityMax" type="number" min="1" step="0.01" value="20"></div>
      <div class="form-field"><label>Deadline</label><input id="activityDue" type="datetime-local"></div>
      <div class="form-field"><label>Student Visibility</label><select id="activityPublished"><option value="1" selected>Publish immediately</option><option value="0">Save hidden</option></select></div>
      <div class="form-field full"><label>Instructions</label><textarea id="activityInstructions" rows="6" placeholder="Describe what students need to submit, format requirements, and other instructions."></textarea></div>
      <div class="form-field full"><label class="activity-check"><input id="activityAllowLate" type="checkbox" value="1"> <span>Allow submissions after the deadline</span></label></div>
    </div>
    <div class="modal-actions"><button class="btn btn-light" data-close="activityModal">Cancel</button><button class="btn btn-primary" id="createActivityBtn">Create & Add to Gradebook</button></div>
  </div>
</div>

<div class="modal" id="activitySubmissionsModal">
  <div class="modal-box modal-wide submissions-modal-box">
    <div class="modal-head"><div><h3 id="activitySubmissionsTitle">Student Submissions</h3><p id="activitySubmissionsMeta" class="muted modal-subtitle"></p></div><button class="close" data-close="activitySubmissionsModal">×</button></div>
    <div id="activitySubmissionsBody" class="activity-submissions-body"><div class="empty">Loading submissions…</div></div>
    <div class="modal-actions"><button class="btn btn-light" data-close="activitySubmissionsModal">Close</button></div>
  </div>
</div>

<div class="toast" id="toast"></div>
<script><?=mygradebook_asset('app.js')?></script>
</body>
</html>
