<?php
session_start();
require_once '../../config/db.php';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $username = $conn->real_escape_string($_POST['username']);
    $password = $conn->real_escape_string($_POST['password']);

    $sql = "SELECT * FROM admin WHERE username = '$username'";
    $result = $conn->query($sql);

    if ($result->num_rows > 0) {
        $admin = $result->fetch_assoc();
        
        // Compare passwords as plain text (without hashing)
        if ($password === $admin['password']) {  
            $_SESSION['admin_logged_in'] = true;
            header("Location: ../dashboard.php");
            exit; // Ensure script stops here
        } else {
            echo "Invalid password.";
        }
    } else {
        echo "Invalid username.";
    }
}
?>
