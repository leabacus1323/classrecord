<?php
require_once "config/database.php";

$teacherPassword = password_hash("Teacher123!", PASSWORD_DEFAULT);
$studentPassword = password_hash("Student123!", PASSWORD_DEFAULT);

try {

    // Fix teacher account
    $stmt = $pdo->prepare("
        UPDATE users 
        SET password_hash = ?, 
            must_change_password = 1
        WHERE username = 'teacher'
    ");

    $stmt->execute([$teacherPassword]);


    // Fix all student accounts
    $stmt = $pdo->prepare("
        UPDATE users 
        SET password_hash = ?, 
            must_change_password = 1
        WHERE role = 'student'
    ");

    $stmt->execute([$studentPassword]);


    echo "Password reset completed successfully.<br><br>";

    echo "Teacher Login:<br>";
    echo "Username: teacher<br>";
    echo "Password: Teacher123!<br><br>";

    echo "Student Login:<br>";
    echo "Username: Student ID<br>";
    echo "Password: Student123!";

} catch(Exception $e){

    echo "Error: ".$e->getMessage();

}
?>