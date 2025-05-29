<?php
session_start();
require_once '../config/db.php';

if (!isset($_SESSION['admin_logged_in'])) {
    header("Location: ../index.php");
    exit;
}

$vehicleRegFilter = $_GET['reg_number'] ?? '';
$dateFilter = $_GET['date'] ?? '';
$minFee = $_GET['min_fee'] ?? '';
$maxFee = $_GET['max_fee'] ?? '';
$fromDate = $_GET['from_date'] ?? '';
$toDate = $_GET['to_date'] ?? '';

// Build base query with joins
$sql = "SELECT pi.*, v.vehicle_type, f.first_hour_charge, f.rest_hour_charge, f.created_at AS fee_created_at
        FROM parks_in pi
        LEFT JOIN vehicle v ON pi.registration_number = v.registration_number
        LEFT JOIN fee f ON pi.fee_id = f.fee_id
        WHERE 1";

$params = [];
$types = "";

// Filters
if (!empty($vehicleRegFilter)) {
    $sql .= " AND pi.registration_number = ?";
    $params[] = $vehicleRegFilter;
    $types .= "s";
}

if (!empty($dateFilter)) {
    $sql .= " AND DATE(pi.in_time) = ?";
    $params[] = $dateFilter;
    $types .= "s";
}

if ($minFee !== '') {
    $sql .= " AND pi.fee >= ?";
    $params[] = $minFee;
    $types .= "d";
}
if ($maxFee !== '') {
    $sql .= " AND pi.fee <= ?";
    $params[] = $maxFee;
    $types .= "d";
}

if (!empty($fromDate)) {
    $sql .= " AND DATE(pi.in_time) >= ?";
    $params[] = $fromDate;
    $types .= "s";
}
if (!empty($toDate)) {
    $sql .= " AND DATE(pi.in_time) <= ?";
    $params[] = $toDate;
    $types .= "s";
}

$stmt = $conn->prepare($sql);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();

// Fetch all fees from fee table for the Fee Structure section
$fee_result = $conn->query("SELECT * FROM fee ORDER BY fee_id ASC");

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
                            <div class="col-md-4">
                                <label for="reg_number" class="form-label">Vehicle Registration Number</label>
                                <input type="text" class="form-control" id="reg_number" name="reg_number" value="<?= htmlspecialchars($vehicleRegFilter) ?>">
                            </div>
                            <div class="col-md-3">
                                <label for="date" class="form-label">Specific Date</label>
                                <input type="date" class="form-control" id="date" name="date" value="<?= htmlspecialchars($dateFilter) ?>">
                            </div>
                            <div class="col-md-3">
                                <label for="min_fee" class="form-label">Min Fee (₹)</label>
                                <input type="number" step="0.01" class="form-control" name="min_fee" value="<?= htmlspecialchars($minFee) ?>">
                            </div>
                            <div class="col-md-3">
                                <label for="max_fee" class="form-label">Max Fee (₹)</label>
                                <input type="number" step="0.01" class="form-control" name="max_fee" value="<?= htmlspecialchars($maxFee) ?>">
                            </div>
                            <div class="col-md-3">
                                <label for="from_date" class="form-label">From Date</label>
                                <input type="date" class="form-control" name="from_date" value="<?= htmlspecialchars($fromDate) ?>">
                            </div>
                            <div class="col-md-3">
                                <label for="to_date" class="form-label">To Date</label>
                                <input type="date" class="form-control" name="to_date" value="<?= htmlspecialchars($toDate) ?>">
                            </div>
                            <div class="col-md-6 d-flex align-items-end gap-2">
                                <button type="submit" class="btn btn-success w-50">Filter</button>
                                <a href="report.php" class="btn btn-secondary w-50">Reset</a>
                            </div>
                        </form>

                        <!-- Parked Vehicles Table -->
                        <div class="table-responsive mb-5">
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
                                        <th>Fee ID</th>
                                        <th>Receipt Path</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if ($result->num_rows > 0): ?>
                                        <?php while ($row = $result->fetch_assoc()): ?>
                                            <tr>
                                                <td><?= $row['id'] ?></td>
                                                <td><?= htmlspecialchars($row['registration_number']) ?></td>
                                                <td><?= htmlspecialchars($row['vehicle_type'] ?? '-') ?></td>
                                                <td><?= htmlspecialchars($row['slot_id']) ?></td>
                                                <td><?= htmlspecialchars($row['in_time']) ?></td>
                                                <td><?= !empty($row['out_time']) ? htmlspecialchars($row['out_time']) : '-' ?></td>
                                                <td><?= isset($row['fee']) ? number_format($row['fee'], 2) : '-' ?></td>
                                                <td><?= htmlspecialchars($row['fee_id']) ?></td>
                                                <td>
                                                    <?php if (!empty($row['receipt_path'])):
                                                        $receiptUrl = htmlspecialchars($row['receipt_path']);
                                                    ?>
                                                        <a href="<?= $receiptUrl ?>" target="_blank" rel="noopener noreferrer" class="btn btn-sm btn-outline-primary">
                                                            View Receipt
                                                        </a>
                                                    <?php else: ?>
                                                        -
                                                    <?php endif; ?>
                                                </td>

                                            </tr>
                                        <?php endwhile; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="9" class="text-center text-muted">No records found.</td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>


                        <?php $stmt->close(); ?>

                        <!-- Fee Table -->
                        <h4 class="mb-3">Fee Structure</h4>
                        <div class="table-responsive">
                            <table class="table table-bordered table-striped">
                                <thead class="table-dark">
                                    <tr>
                                        <th>Fee ID</th>
                                        <th>Vehicle Type</th>
                                        <th>First Hour Charges (₹)</th>
                                        <th>Rest Hour Charges (₹)</th>
                                        <th>Created At</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if ($fee_result && $fee_result->num_rows > 0): ?>
                                        <?php while ($fee_row = $fee_result->fetch_assoc()): ?>
                                            <tr>
                                                <td><?= $fee_row['fee_id'] ?></td>
                                                <td><?= htmlspecialchars($fee_row['vehicle_type']) ?></td>
                                                <td><?= number_format($fee_row['first_hour_charge'], 2) ?></td>
                                                <td><?= number_format($fee_row['rest_hour_charge'], 2) ?></td>
                                                <td><?= htmlspecialchars($fee_row['created_at']) ?></td>
                                            </tr>
                                        <?php endwhile; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="5" class="text-center text-muted">No fee data found.</td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>

                    </div>
                </div>
            </div>
        </div>
    </div>
</body>

</html>