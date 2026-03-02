<?php
session_start();
require_once '../config/db.php';

if (!isset($_SESSION['admin_logged_in'])) {
    header("Location: ../index.php");
    exit;
}

error_reporting(E_ALL);
ini_set('display_errors', 1);

if (!isset($_SESSION['lot_id'])) {
    header("Location: ../index.php");
    exit;
}

$lot_id = intval($_SESSION['lot_id']);

// Fetch slots only for selected lot
$stmt = $conn->prepare("
    SELECT 
        ps.slot_id,
        ps.slot_no,
        ps.status,
        v.registration_number,
        v.type,
        pi.in_time
    FROM parking_slot ps
    LEFT JOIN parks_in pi 
        ON ps.slot_id = pi.slot_id 
        AND pi.out_time IS NULL
    LEFT JOIN vehicle v 
        ON pi.registration_number = v.registration_number
    WHERE ps.lot_id = ?
    ORDER BY ps.slot_no
");
$stmt->bind_param("i", $lot_id);
$stmt->execute();
$result = $stmt->get_result();
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
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
            <div class="col-md-9 col-lg-10 py-4">
                <div class="container">
                    <div class="card shadow">
                        <div class="card-header bg-primary text-white">
                            <h4 class="mb-0">Parking Lot Overview</h4>
                        </div>

                        <div class="card-body">
                            <div class="row g-4">

                                <?php if ($result && $result->num_rows > 0): ?>
                                    <?php while ($row = $result->fetch_assoc()): ?>

                                        <?php
                                        $status = $row['status'];

                                        if ($status === 'occupied') {
                                            $cardClass = 'border-danger bg-danger-subtle';
                                            $statusText = 'Occupied';
                                        } elseif ($status === 'booked') {
                                            $cardClass = 'border-warning bg-warning-subtle';
                                            $statusText = 'Booked';
                                        } else {
                                            $cardClass = 'border-success bg-success-subtle';
                                            $statusText = 'Unoccupied';
                                        }

                                        // Choose icon
                                        if ($status === 'occupied') {
                                            $icon = ($row['type'] === '2-wheeler')
                                                ? '../assets/motorcycle.png'
                                                : '../assets/car.png';
                                        } elseif ($status === 'booked') {
                                            $icon = '../assets/reserved.png'; // optional custom icon
                                        } else {
                                            $icon = '../assets/not-available-circle.png';
                                        }
                                        ?>

                                        <div class="col-sm-6 col-md-4 col-lg-3">
                                            <div class="card h-100 <?= $cardClass ?> shadow-sm">
                                                <div class="card-body text-center">

                                                    <h5 class="card-title">
                                                        Slot <?= htmlspecialchars($row['slot_no']) ?>
                                                    </h5>

                                                    <div class="mb-3">
                                                        <img src="<?= htmlspecialchars($icon) ?>"
                                                            style="width:64px;height:64px;"
                                                            alt="Vehicle Icon">
                                                    </div>

                                                    <p class="fw-bold"><?= $statusText ?></p>

                                                    <?php if ($status === 'occupied'): ?>
                                                        <p class="mb-1">
                                                            <strong>Reg:</strong><br>
                                                            <?= htmlspecialchars($row['registration_number']) ?>
                                                        </p>

                                                        <p class="mb-3">
                                                            <strong>In Time:</strong><br>
                                                            <?= htmlspecialchars($row['in_time']) ?>
                                                        </p>

                                                        <form action="process/delete_vehicle_process.php" method="POST">
                                                            <input type="hidden" name="slot_id"
                                                                value="<?= intval($row['slot_id']) ?>">
                                                            <button type="submit"
                                                                class="btn btn-danger btn-sm w-100">
                                                                Remove Vehicle
                                                            </button>
                                                        </form>

                                                    <?php elseif ($status === 'booked'): ?>
                                                        <p class="text-warning mt-3">
                                                            Reserved Slot
                                                        </p>

                                                    <?php else: ?>
                                                        <p class="text-muted mt-3">
                                                            No vehicle parked
                                                        </p>
                                                    <?php endif; ?>

                                                </div>
                                            </div>
                                        </div>

                                    <?php endwhile; ?>
                                <?php else: ?>
                                    <div class="col-12 text-center">
                                        <p>No parking slots found for this lot.</p>
                                    </div>
                                <?php endif; ?>

                            </div>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </div>

    <?php
    // Receipt Modal Logic
    if (isset($_SESSION['receipt_path']) && isset($_SESSION['receipt_success'])):
    ?>
        <script>
            document.addEventListener('DOMContentLoaded', function() {
                const receiptModal = new bootstrap.Modal(document.getElementById('receiptModal'));
                receiptModal.show();
            });
        </script>
    <?php
        unset($_SESSION['receipt_path'], $_SESSION['receipt_success']);
    endif;
    ?>

</body>

</html>