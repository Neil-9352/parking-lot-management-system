<?php
session_start();
require_once '../config/db.php';

// Check if admin is logged in
if (!isset($_SESSION['admin_logged_in'])) {
    header("Location: ../index.php");
    exit;
}

// Fetch available parking slots (only unoccupied slots)
$slots_query = "SELECT slot_number FROM parking_slots WHERE status = 'unoccupied'";
$slots_result = $conn->query($slots_query);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Vehicle</title>
    <link rel="stylesheet" href="../assets/css/styles.css">
</head>
<body>

<?php include '../includes/sidebar.php'; ?>

<div class="container">
    <h2>Add Vehicle</h2>
    <form action="process/add_vehicle_process.php" method="POST">
        <label for="reg_number">Vehicle Registration Number:</label>
        <input type="text" id="reg_number" name="reg_number" required>

        <label for="vehicle_type">Vehicle Type:</label>
        <select id="vehicle_type" name="vehicle_type" required>
            <option value="2-wheeler">2-Wheeler</option>
            <option value="4-wheeler">4-Wheeler</option>
        </select>

        <label for="slot_number">Parking Slot:</label>
        <select id="slot_number" name="slot_number" required>
            <?php
            while ($row = $slots_result->fetch_assoc()) {
                echo "<option value='{$row['slot_number']}'>{$row['slot_number']}</option>";
            }
            ?>
        </select>

        <button type="submit">Park Vehicle</button>
    </form>
</div>

</body>
</html>
