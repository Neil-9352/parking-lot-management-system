<?php
session_start();
require_once '../../config/db.php';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {

    $username = trim($_POST['username']);
    $password = trim($_POST['password']);

    // Validate inputs
    if (empty($username) || empty($password)) {
        $_SESSION['login_error'] = "Please enter both username and password.";
        header("Location: ../../index.php");
        exit;
    }

    // Authenticate admin and fetch lot_id from the database
    $sql = "SELECT * FROM admin WHERE username = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result && $result->num_rows > 0) {
        $admin = $result->fetch_assoc();

        if (password_verify($password, $admin['password'])) {

            // Verify the admin's lot still exists
            $lot_id = intval($admin['lot_id']);
            $lot_check = $conn->prepare("SELECT lot_id FROM parking_lot WHERE lot_id = ?");
            $lot_check->bind_param("i", $lot_id);
            $lot_check->execute();
            $lot_result = $lot_check->get_result();

            if ($lot_result->num_rows === 0) {
                $_SESSION['login_error'] = "Associated parking lot not found. Please contact support.";
                header("Location: ../../index.php");
                exit;
            }
            $lot_check->close();

            $_SESSION['admin_logged_in'] = true;
            $_SESSION['admin_id'] = $admin['id'];
            $_SESSION['lot_id'] = $lot_id;

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

    $stmt->close();
}
