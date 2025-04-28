<?php
session_start();
if (!isset($_SESSION['admin_logged_in'])) {
    header("Location: ../index.php");
    exit;
}
?>
<h1>Admin Dashboard</h1>
<ul>
    <li><a href="add_vehicle.php">Add Vehicle</a></li>
    <li><a href="view_slots.php">View Slots</a></li>
    <li><a href="settings_page.php">Settings</a></li>
    <li><a href="../logout.php">Logout</a></li>
</ul>
