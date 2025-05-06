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
    <link rel="stylesheet" href="../bootstrap-5.3.6/css/bootstrap.css">
    <script src="../bootstrap-5.3.6/js/bootstrap.bundle.js"></script>
</head>

<body class="bg-light">

    <div class="container-fluid">
        <div class="row">
            <!-- Sidebar -->
            <div class="col-md-3 col-lg-2 bg-dark min-vh-100 p-0">
                <?php include '../includes/sidebar.php'; ?>
            </div>

            <!-- Main Content -->
            <div class="col-md-9 col-lg-10 py-4">
                <div class="card shadow mx-3"> <!-- Use mx-3 for some horizontal margin -->
                    <div class="card-header bg-primary text-white">
                        <h4 class="mb-0">Add Vehicle</h4>
                    </div>
                    <div class="card-body">
                        <form action="process/add_vehicle_process.php" method="POST">
                            <div class="mb-3">
                                <label for="reg_number" class="form-label">Vehicle Registration Number</label>
                                <input type="text" class="form-control" id="reg_number" name="reg_number" required>
                            </div>

                            <div class="mb-3">
                                <label for="vehicle_type" class="form-label">Vehicle Type</label>
                                <select class="form-select" id="vehicle_type" name="vehicle_type" required>
                                    <option value="2-wheeler">2-Wheeler</option>
                                    <option value="4-wheeler">4-Wheeler</option>
                                </select>
                            </div>

                            <div class="mb-3">
                                <label for="slot_number" class="form-label">Parking Slot</label>
                                <select class="form-select" id="slot_number" name="slot_number" required>
                                    <?php
                                    while ($row = $slots_result->fetch_assoc()) {
                                        echo "<option value='{$row['slot_number']}'>{$row['slot_number']}</option>";
                                    }
                                    ?>
                                </select>
                            </div>

                            <button type="submit" class="btn btn-success w-100">Park Vehicle</button>
                        </form>
                    </div>
                </div>
            </div>

        </div>
    </div>
</body>


</html>