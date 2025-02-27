<?php
session_start();
require_once '../config/db.php';
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Parking Slots</title>
    <link rel="stylesheet" href="../assets/css/styles.css">
</head>

<body>
    <?php include '../includes/sidebar.php'; ?>
    <div class="main-content">
        <h2>Parking Slot Status</h2>
        <table border="1">
            <thead>
                <tr>
                    <th>Slot Number</th>
                    <th>Status</th>
                    <th>Vehicle Reg. Number</th>
                    <th>Vehicle Type</th>
                    <th>In Time</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php
                // Fetch all parking slots
                $query = "SELECT * FROM parking_slots ORDER BY slot_number";
                $result = $conn->query($query);

                if ($result->num_rows > 0) {
                    while ($row = $result->fetch_assoc()) {
                        echo "<tr>";
                        echo "<td>{$row['slot_number']}</td>";
                        echo "<td>{$row['status']}</td>";
                        echo "<td>" . ($row['status'] == 'occupied' ? $row['vehicle_reg_number'] : '-') . "</td>";
                        echo "<td>" . ($row['status'] == 'occupied' ? $row['vehicle_type'] : '-') . "</td>";
                        echo "<td>" . ($row['status'] == 'occupied' ? $row['in_time'] : '-') . "</td>";

                        // Show "Remove Vehicle" button only for occupied slots
                        echo "<td>";
                        if ($row['status'] == 'occupied') {
                            echo "<form action='process/delete_vehicle_process.php' method='POST'>
                                    <input type='hidden' name='slot_number' value='{$row['slot_number']}'>
                                    <button type='submit'>Remove Vehicle</button>
                                  </form>";
                        } else {
                            echo "-";
                        }
                        echo "</td>";
                        echo "</tr>";
                    }
                } else {
                    echo "<tr><td colspan='6'>No parking slots available.</td></tr>";
                }
                ?>
            </tbody>
        </table>

        <?php
        if (isset($_GET['success'])) {
            echo "<script>alert('{$_GET['success']}');</script>";
        }
        ?>

        <?php
        if (isset($_GET['receipt'])) {
            echo "<p><a href='{$_GET['receipt']}' target='_blank'>Download Receipt</a></p>";
        }
        ?>
    </div>
</body>

</html>