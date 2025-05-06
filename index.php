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
    <link rel="stylesheet" href="bootstrap-5.3.6/css/bootstrap.css">
    <script src="bootstrap-5.3.6/js/bootstrap.bundle.js"></script>
    <title>Parking Lot Management System</title>
</head>

<body>
    <nav class="navbar navbar-dark bg-primary">
        <div class="container-fluid">
            <spam class="navbar-brand mb-0 h1">Parking Lot Management System</spam>
        </div>
    </nav>
    <!-- <form method="POST" action="admin/process/login_process.php">
            <label for="username">Username:</label>
            <input type="text" id="username" name="username" required>
            <label for="password">Password:</label>
            <input type="password" id="password" name="password" required>
            <button type="submit">Login</button>
        </form> -->
    <div class="container d-flex align-items-center justify-content-center" style="min-height: 90vh;">
        <div class="card shadow-sm p-4" style="width: 100%; max-width: 400px;">
            <div class="card-body">
                <h4 class="card-title text-center text-primary mb-4">Admin Login</h4>
                <form method="POST" action="admin/process/login_process.php">
                    <div class="mb-3">
                        <label for="username" class="form-label">Username</label>
                        <input type="text" class="form-control" id="username" name="username" required>
                    </div>
                    <div class="mb-3">
                        <label for="password" class="form-label">Password</label>
                        <input type="password" class="form-control" id="password" name="password" required>
                    </div>
                    <div class="d-grid">
                        <button type="submit" class="btn btn-primary">Login</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

</body>

</html>