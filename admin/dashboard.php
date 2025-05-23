<?php
session_start();
if (!isset($_SESSION['admin_logged_in'])) {
    header("Location: ../index.php");
    exit;
}
?>
<!-- <h1>Admin Dashboard</h1>
<ul>
    <li><a href="add_vehicle.php">Add Vehicle</a></li>
    <li><a href="view_slots.php">View Slots</a></li>
    <li><a href="settings_page.php">Settings</a></li>
    <li><a href="../logout.php">Logout</a></li>
</ul> -->

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Admin Dashboard</title>
    <link rel="stylesheet" href="../bootstrap-5.3.6/css/bootstrap.css">
    <script src="../bootstrap-5.3.6/js/bootstrap.bundle.js"></script>
</head>

<body class="bg-light">

    <!-- Navbar -->
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary">
        <div class="container-fluid">
            <span class="navbar-brand">Admin Dashboard</span>
            <a href="../logout.php" class="btn btn-outline-light">Logout</a>
        </div>
    </nav>

    <!-- Content -->
    <div class="container mt-5">
        <div class="text-center mb-4">
            <h2>Welcome to the Admin Dashboard</h2>
        </div>

        <div class="row justify-content-center">
            <div class="col-md-6">
                <div class="list-group shadow">
                    <a href="add_vehicle.php" class="list-group-item list-group-item-action">🚗 Add Vehicle</a>
                    <a href="view_slots.php" class="list-group-item list-group-item-action">📋 View Slots</a>
                    <a href="report.php" class="list-group-item list-group-item-action">📊 Report</a>
                    <a href="settings_page.php" class="list-group-item list-group-item-action">⚙️ Settings</a>
                </div>
            </div>
        </div>
    </div>

</body>

</html>