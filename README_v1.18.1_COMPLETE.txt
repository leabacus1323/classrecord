MYGRADEBOOK v1.18.1 COMPLETE PACKAGE
=====================================

This is the complete current application package, including:
- Homepage and Teacher/Student login
- Teacher dashboard
- Student private dashboard
- Students and gender management
- Subject management and subject-specific rosters
- Bulk student upload/download per subject
- Grade Entry with automatic MySQL saving
- Bulk assessment score upload
- Attendance quick buttons
- Top Performing Students
- Accounts and Password Reset Center
- Activities & Student Submissions
- Automatic Gradebook assessment creation for activities
- Student file/note submissions
- Teacher grading linked directly to Grade Entry
- Teacher submission preview for PDF, images, DOCX, PPTX, TXT, and CSV

IMPORTANT FOR EXISTING INFINITYFREE INSTALLATION
-------------------------------------------------
Do NOT overwrite your working config/database.php.
This package contains config/database.example.php only.
Keep your existing htdocs/config/database.php with your InfinityFree credentials.

UPLOAD
------
Upload the CONTENTS of this folder directly into htdocs and overwrite existing
application files. Do not create an extra MyGradebook folder inside htdocs.

Expected structure:
htdocs/
  index.php
  login.php
  logout.php
  forgot_password.php
  change_password.php
  teacher.php
  student.php
  submission_view.php
  api/
  assets/
  includes/
  templates/
  uploads/submissions/
  config/database.php     <-- keep your existing working file

DATABASE
--------
Existing installation:
- If your database already has password_reset_requests, activities, and
  activity_submissions, no destructive reinstall is needed.
- sql/MyGradebook_v1.18.1_Safe_Upgrade.sql only updates the stored schema
  version and does not delete data.

Fresh installation only:
- sql/MyGradebook_v1.18.1_Latest_Full_Database_InfinityFree.sql
  recreates all MyGradebook tables. Do NOT import this over a live database
  if you want to keep existing students, grades, attendance, and submissions.

AFTER UPLOAD
------------
1. Open your site.
2. Press Ctrl+F5 once.
3. Log in as Teacher.
4. Sidebar should show Activities & Submissions.
5. Test one activity creation and verify that it appears in Grade Entry.
