<?php
session_start();
require_once '../config/db.php';

if (!isset($_SESSION['admin_logged_in']) || !isset($_SESSION['lot_id'])) {
    header("Location: ../index.php");
    exit;
}

error_reporting(E_ALL);
ini_set('display_errors', 1);

$lot_id = intval($_SESSION['lot_id']);

$vehicleRegFilter = $_GET['reg_number'] ?? '';
$dateFilter = $_GET['date'] ?? '';
$minFee = $_GET['min_fee'] ?? '';
$maxFee = $_GET['max_fee'] ?? '';
$fromDate = $_GET['from_date'] ?? '';
$toDate = $_GET['to_date'] ?? '';

// ==========================
// Build Query (Lot Isolated)
// ==========================
$sql = "SELECT pi.*, v.type
        FROM parks_in pi
        LEFT JOIN vehicle v ON pi.registration_number = v.registration_number
        WHERE pi.lot_id = ?";

$params = [$lot_id];
$types = "i";

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

$sql .= " ORDER BY pi.id DESC";

$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();

// ==========================
// Fetch Fee Structure (Lot Specific)
// ==========================
$fee_stmt = $conn->prepare("
    SELECT * FROM fee
    WHERE lot_id = ?
    ORDER BY fee_id ASC
");
$fee_stmt->bind_param("i", $lot_id);
$fee_stmt->execute();
$fee_result = $fee_stmt->get_result();
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

            <!-- Main -->
            <div class="col-md-9 col-lg-10 py-4 justify-content-center">
                <div class="card mx-3 shadow">
                    <div class="card-header bg-primary text-white">
                        <h4 class="mb-0">Vehicle Parking Reports</h4>
                    </div>

                    <div class="card-body">

                        <!-- Filters -->
                        <form method="GET" class="row g-3 mb-4">
                            <div class="col-md-4">
                                <label class="form-label">Vehicle Registration Number</label>
                                <input type="text" class="form-control" name="reg_number"
                                    value="<?= htmlspecialchars($vehicleRegFilter) ?>">
                            </div>

                            <div class="col-md-3">
                                <label class="form-label">Specific Date</label>
                                <input type="date" class="form-control" name="date"
                                    value="<?= htmlspecialchars($dateFilter) ?>">
                            </div>

                            <div class="col-md-3">
                                <label class="form-label">Min Fee (₹)</label>
                                <input type="number" step="0.01" class="form-control"
                                    name="min_fee" value="<?= htmlspecialchars($minFee) ?>">
                            </div>

                            <div class="col-md-3">
                                <label class="form-label">Max Fee (₹)</label>
                                <input type="number" step="0.01" class="form-control"
                                    name="max_fee" value="<?= htmlspecialchars($maxFee) ?>">
                            </div>

                            <div class="col-md-3">
                                <label class="form-label">From Date</label>
                                <input type="date" class="form-control"
                                    name="from_date" value="<?= htmlspecialchars($fromDate) ?>">
                            </div>

                            <div class="col-md-3">
                                <label class="form-label">To Date</label>
                                <input type="date" class="form-control"
                                    name="to_date" value="<?= htmlspecialchars($toDate) ?>">
                            </div>

                            <div class="col-md-6 d-flex align-items-end gap-2">
                                <button type="submit" class="btn btn-success w-50">Filter</button>
                                <a href="report.php" class="btn btn-secondary w-50">Reset</a>
                            </div>
                        </form>

                        <!-- Parks In Table -->
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
                                        <th>Receipt</th>
                                    </tr>
                                </thead>
                                <tbody>

                                    <?php if ($result->num_rows > 0): ?>
                                        <?php while ($row = $result->fetch_assoc()): ?>
                                            <tr>
                                                <td><?= $row['id'] ?></td>
                                                <td><?= htmlspecialchars($row['registration_number']) ?></td>
                                                <td><?= htmlspecialchars($row['type'] ?? '-') ?></td>
                                                <td><?= htmlspecialchars($row['slot_id']) ?></td>
                                                <td><?= htmlspecialchars($row['in_time']) ?></td>
                                                <td><?= $row['out_time'] ? htmlspecialchars($row['out_time']) : '-' ?></td>
                                                <td><?= isset($row['fee']) ? number_format($row['fee'], 2) : '-' ?></td>
                                                <td>
                                                    <?php if (!empty($row['receipt_path'])): ?>
                                                        <a href="<?= htmlspecialchars($row['receipt_path']) ?>"
                                                            target="_blank"
                                                            class="btn btn-sm btn-outline-primary">
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
                                            <td colspan="8" class="text-center text-muted">
                                                No records found for this lot.
                                            </td>
                                        </tr>
                                    <?php endif; ?>

                                </tbody>
                            </table>
                        </div>

                        <?php $stmt->close(); ?>

                        <!-- Fee Structure -->
                        <h4 class="mb-3">Fee Structure (Current Lot)</h4>

                        <div class="table-responsive">
                            <table class="table table-bordered table-striped">
                                <thead class="table-dark">
                                    <tr>
                                        <th>Fee ID</th>
                                        <th>Vehicle Type</th>
                                        <th>First Hour (₹)</th>
                                        <th>Rest Hour (₹)</th>
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
                                            <td colspan="5" class="text-center text-muted">
                                                No fee data found for this lot.
                                            </td>
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