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
            <div class="col-md-9 col-lg-10 py-4 overflow-y-auto">
                <div class="container">
                    <div class="card shadow">
                        <div class="card-header bg-primary text-white">
                            <h4 class="mb-0">Parking Slot Status</h4>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-bordered table-striped">
                                    <thead class="table-dark">
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
                                        $query = "SELECT * FROM parking_slots ORDER BY slot_number";
                                        $result = $conn->query($query);

                                        if ($result->num_rows > 0) {
                                            while ($row = $result->fetch_assoc()) {
                                                echo "<tr>";
                                                echo "<td>{$row['slot_number']}</td>";
                                                echo "<td class='" . ($row['status'] == 'occupied' ? 'text-danger' : 'text-success') . "'>{$row['status']}</td>";
                                                echo "<td>" . ($row['status'] == 'occupied' ? $row['vehicle_reg_number'] : '-') . "</td>";
                                                echo "<td>" . ($row['status'] == 'occupied' ? $row['vehicle_type'] : '-') . "</td>";
                                                echo "<td>" . ($row['status'] == 'occupied' ? $row['in_time'] : '-') . "</td>";

                                                echo "<td>";
                                                if ($row['status'] == 'occupied') {
                                                    echo "<form action='process/delete_vehicle_process.php' method='POST' class='d-inline'>
                                                            <input type='hidden' name='slot_number' value='{$row['slot_number']}'>
                                                            <button type='submit' class='btn btn-danger btn-sm'>Remove Vehicle</button>
                                                          </form>";
                                                } else {
                                                    echo "-";
                                                }
                                                echo "</td>";
                                                echo "</tr>";
                                            }
                                        } else {
                                            echo "<tr><td colspan='6' class='text-center'>No parking slots available.</td></tr>";
                                        }
                                        ?>
                                    </tbody>
                                </table>
                            </div>

                            <?php if (isset($_GET['success'])): ?>
                                <div class="alert alert-success mt-3"><?= htmlspecialchars($_GET['success']) ?></div>
                            <?php endif; ?>

                            <?php if (isset($_GET['receipt'])): ?>
                                <div class="mt-3">
                                    <a href="<?= htmlspecialchars($_GET['receipt']) ?>" target="_blank" class="btn btn-outline-primary">Download Receipt</a>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </div>
</body>

</html>
