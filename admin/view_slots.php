<?php
session_start();
require_once '../config/db.php';

if (!isset($_SESSION['admin_logged_in'])) {
    header("Location: ../index.php");
    exit;
}

if (!isset($_SESSION['lot_id'])) {
    header("Location: ../index.php");
    exit;
}

$lot_id = intval($_SESSION['lot_id']);

// Fetch slots only for selected lot.
// Booking status is derived from the books table (ACTIVE bookings),
// not from parking_slot.status which only tracks physical occupancy.
$stmt = $conn->prepare("
    SELECT 
        ps.slot_id,
        ps.slot_no,
        ps.status,
        v.registration_number,
        v.type,
        pi.in_time,
        b.booking_id,
        b.registration_number  AS booked_reg,
        b.expected_start_time,
        b.expected_end_time,
        bv.type                AS booked_type
    FROM parking_slot ps
    LEFT JOIN parks_in pi 
        ON ps.slot_id = pi.slot_id 
        AND pi.out_time IS NULL
    LEFT JOIN vehicle v 
        ON pi.registration_number = v.registration_number
    LEFT JOIN books b
        ON  b.slot_id        = ps.slot_id
        AND b.booking_status = 'ACTIVE'
        AND b.expected_end_time > NOW()
        AND b.booking_id = (
            SELECT b2.booking_id
            FROM   books b2
            WHERE  b2.slot_id        = ps.slot_id
            AND    b2.booking_status = 'ACTIVE'
            AND    b2.expected_end_time > NOW()
            ORDER  BY b2.expected_start_time ASC, b2.booking_id ASC
            LIMIT  1
        )
    LEFT JOIN vehicle bv
        ON bv.registration_number = b.registration_number
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
                                        // Derive effective display status:
                                        // 'occupied'  — vehicle physically present (parks_in)
                                        // 'booked'    — no vehicle yet but ACTIVE booking in books table
                                        // 'unoccupied'— fully free
                                        if ($row['status'] === 'occupied') {
                                            $displayStatus = 'occupied';
                                        } elseif ($row['booking_id'] !== null) {
                                            $displayStatus = 'booked';
                                        } else {
                                            $displayStatus = 'unoccupied';
                                        }

                                        if ($displayStatus === 'occupied') {
                                            $cardClass  = 'border-danger bg-danger-subtle';
                                            $statusText = 'Occupied';
                                            $icon = ($row['type'] === '2-wheeler')
                                                ? '../assets/motorcycle.png'
                                                : '../assets/car.png';
                                        } elseif ($displayStatus === 'booked') {
                                            $cardClass  = 'border-warning bg-warning-subtle';
                                            $statusText = 'Booked';
                                            $icon = ($row['booked_type'] === '2-wheeler')
                                                ? '../assets/motorcycle.png'
                                                : '../assets/car.png';
                                        } else {
                                            $cardClass  = 'border-success bg-success-subtle';
                                            $statusText = 'Unoccupied';
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

                                                    <?php if ($displayStatus === 'occupied'): ?>

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

                                                    <?php elseif ($displayStatus === 'booked'): ?>

                                                        <p class="mb-1">
                                                            <strong>Booked by:</strong><br>
                                                            <?= htmlspecialchars($row['booked_reg']) ?>
                                                        </p>
                                                        <p class="mb-1">
                                                            <strong>From:</strong><br>
                                                            <?= htmlspecialchars($row['expected_start_time']) ?>
                                                        </p>
                                                        <p class="mb-0">
                                                            <strong>Until:</strong><br>
                                                            <?= htmlspecialchars($row['expected_end_time']) ?>
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

        <!-- ================= RECEIPT MODAL ================= -->

        <div class="modal fade" id="receiptModal" tabindex="-1">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">

                    <div class="modal-header">
                        <h5 class="modal-title">Vehicle Removal Status</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>

                    <div class="modal-body">
                        <p id="receiptMessage"></p>
                    </div>

                    <div class="modal-footer">
                        <a href="#" id="downloadReceiptBtn"
                            class="btn btn-primary"
                            target="_blank"
                            download>
                            Download Receipt
                        </a>
                        <button type="button"
                            class="btn btn-secondary"
                            data-bs-dismiss="modal">
                            Close
                        </button>
                    </div>

                </div>
            </div>
        </div>

        <?php
        // ========= Modal Trigger Logic =========
        if (isset($_SESSION['receipt_success']) && isset($_SESSION['receipt_path'])):
        ?>

            <script>
                document.addEventListener('DOMContentLoaded', function() {

                    document.getElementById('receiptMessage').textContent =
                        <?= json_encode($_SESSION['receipt_success']); ?>;

                    document.getElementById('downloadReceiptBtn').href =
                        <?= json_encode($_SESSION['receipt_path']); ?>;

                    const receiptModal = new bootstrap.Modal(
                        document.getElementById('receiptModal')
                    );
                    receiptModal.show();
                });
            </script>

        <?php
            unset($_SESSION['receipt_success'], $_SESSION['receipt_path']);
        endif;
        ?>

</body>

</html>