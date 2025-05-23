<?php
session_start();
require_once '../config/db.php';

if (!isset($_SESSION['admin_logged_in'])) {
    header("Location: ../index.php");
    exit;
}

$vehicleRegFilter = $_GET['reg_number'] ?? '';
$dateFilter = $_GET['date'] ?? '';

// Build base query
$sql = "SELECT * FROM parked_vehicles WHERE 1";
$params = [];
$types = "";

// Add filters
if (!empty($vehicleRegFilter)) {
    $sql .= " AND reg_number = ?";
    $params[] = $vehicleRegFilter;
    $types .= "s";
}

if (!empty($dateFilter)) {
    $sql .= " AND DATE(in_time) = ?";
    $params[] = $dateFilter;
    $types .= "s";
}

$stmt = $conn->prepare($sql);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Vehicle Reports</title>
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
            <div class="col-md-9 col-lg-10 py-4 justify-content-center">
                <div class="card mx-3 shadow">
                    <div class="card-header bg-primary text-white">
                        <h4 class="mb-0">Vehicle Parking Reports</h4>
                    </div>
                    <div class="card-body">
                        <form method="GET" class="row g-3 mb-4">
                            <div class="col-md-5">
                                <label for="reg_number" class="form-label">Vehicle Registration Number</label>
                                <input type="text" class="form-control" id="reg_number" name="reg_number" value="<?= htmlspecialchars($vehicleRegFilter) ?>">
                            </div>
                            <div class="col-md-4">
                                <label for="date" class="form-label">Date</label>
                                <input type="date" class="form-control" id="date" name="date" value="<?= htmlspecialchars($dateFilter) ?>">
                            </div>
                            <div class="col-md-3 d-flex align-items-end gap-2">
                                <button type="submit" class="btn btn-success w-50">Filter</button>
                                <a href="report.php" class="btn btn-secondary w-50">Reset</a>
                            </div>

                        </form>

                        <div class="table-responsive">
                            <table class="table table-bordered table-striped">
                                <thead class="table-dark">
                                    <tr>
                                        <th>ID</th>
                                        <th>Reg. Number</th>
                                        <th>Type</th>
                                        <th>Slot</th>
                                        <th>In Time</th>
                                        <th>Out Time</th>
                                        <th>Fee (₹)</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if ($result->num_rows > 0): ?>
                                        <?php while ($row = $result->fetch_assoc()): ?>
                                            <tr>
                                                <td><?= $row['id'] ?></td>
                                                <td><?= htmlspecialchars($row['reg_number']) ?></td>
                                                <td><?= $row['vehicle_type'] ?></td>
                                                <td><?= $row['slot_number'] ?></td>
                                                <td><?= $row['in_time'] ?></td>
                                                <td><?= $row['out_time'] ?? '-' ?></td>
                                                <td><?= $row['fee'] ?? '-' ?></td>
                                            </tr>
                                        <?php endwhile; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="7" class="text-center text-muted">No records found.</td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>

                        <?php $stmt->close(); ?>

                    </div>
                </div>
            </div>
        </div>
    </div>
</body>

</html>