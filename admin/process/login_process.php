<?php
session_start();
require_once '../../config/db.php';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $username = trim($_POST['username']);
    $password = trim($_POST['password']);

    // Use prepared statement
    $sql = "SELECT * FROM admin WHERE username = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result && $result->num_rows > 0) {
        $admin = $result->fetch_assoc();

        if (password_verify($password, $admin['password'])) {
            $_SESSION['admin_logged_in'] = true;
            header("Location: ../dashboard.php");
            exit;
        } else {
            $_SESSION['login_error'] = "Invalid password.";
            header("Location: ../../index.php");
            exit;
        }
    } else {
        $_SESSION['login_error'] = "Invalid username.";
        header("Location: ../../index.php");
        exit;
    }

    $stmt->close(); // Close prepared statement
}
