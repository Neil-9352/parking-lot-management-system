<?php
    session_start();
    if (isset($_SESSION['admin_logged_in'])) {
        header("Location: admin/dashboard.php");
        exit;
    }
?>

<!DOCTYPE html>
<html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Parking Lot Management System</title>
    </head>

    <body>
        <form method="POST" action="admin/process/login_process.php">
            <label for="username">Username:</label>
            <input type="text" id="username" name="username" required>
            <label for="password">Password:</label>
            <input type="password" id="password" name="password" required>
            <button type="submit">Login</button>
        </form>
    </body>
</html>