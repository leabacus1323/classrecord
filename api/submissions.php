<?php
// Compatibility endpoint for MyGrades v1.27.1.
// The main application continues to use api/index.php.
if (!isset($_GET['action'])) $_GET['action'] = 'activities';
require __DIR__ . '/index.php';
