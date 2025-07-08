<?php
session_start();
require_once '../config/db.php';

if (!isset($_SESSION['admin_logged_in'])) {
    header("Location: ../index.php");
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>View Parking Slots</title>
    <link rel="stylesheet" href="../bootstrap-5.3.6/css/bootstrap.css" />
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
                            <h4 class="mb-0">Parking Lot Overview</h4>
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
                                        // Get all parking slots, and only join parks_in if out_time is NULL
                                        $query = "
                                            SELECT 
                                                ps.slot_id,
                                                ps.slot_number,
                                                v.registration_number,
                                                v.vehicle_type,
                                                pi.in_time
                                            FROM parking_slot ps
                                            LEFT JOIN parks_in pi ON ps.slot_id = pi.slot_id AND pi.out_time IS NULL
                                            LEFT JOIN vehicle v ON pi.registration_number = v.registration_number
                                            ORDER BY ps.slot_number;
                                        ";

                                        $result = $conn->query($query);

                                        if ($result && $result->num_rows > 0) {
                                            while ($row = $result->fetch_assoc()) {
                                                $isOccupied = !is_null($row['registration_number']);

                                                echo "<tr>";
                                                echo "<td>" . htmlspecialchars($row['slot_number']) . "</td>";

                                                // Status
                                                $statusText = $isOccupied ? "occupied" : "unoccupied";
                                                $statusClass = $isOccupied ? "text-danger" : "text-success";
                                                echo "<td class='$statusClass'>" . htmlspecialchars($statusText) . "</td>";

                                                // Vehicle Info
                                                echo "<td>" . ($isOccupied ? htmlspecialchars($row['registration_number']) : "-") . "</td>";
                                                echo "<td>" . ($isOccupied ? htmlspecialchars($row['vehicle_type']) : "-") . "</td>";
                                                echo "<td>" . ($isOccupied ? htmlspecialchars($row['in_time']) : "-") . "</td>";

                                                // Action
                                                echo "<td>";
                                                if ($isOccupied) {
                                                    echo "<form action='process/delete_vehicle_process.php' method='POST' class='d-inline'>
                                                            <input type='hidden' name='slot_id' value='" . intval($row['slot_id']) . "'>
                                                            <button type='submit' class='btn btn-danger btn-sm'>Remove Vehicle</button>
                                                          </form>";
                                                } else {
                                                    echo "-";
                                                }
                                                echo "</td>";

                                                echo "</tr>";
                                            }
                                        } else {
                                            echo "<tr><td colspan='6' class='text-center'>No parking slots found.</td></tr>";
                                        }
                                        ?>
                                    </tbody>
                                </table>
                            </div>


                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Receipt Modal -->
    <div class="modal fade" id="receiptModal" tabindex="-1" aria-labelledby="receiptModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="receiptModalLabel">Vehicle Removed</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close" id="modalCloseBtn"></button>
                </div>
                <div class="modal-body">
                    <p>Vehicle removed successfully.</p>
                </div>
                <div class="modal-footer">
                    <a href="#" id="downloadReceiptBtn" class="btn btn-primary" target="_blank" rel="noopener noreferrer" download>Download Receipt</a>
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal" id="modalCloseBtnFooter">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- <script>
        document.addEventListener('DOMContentLoaded', function() {
            const receiptUrl = new URLSearchParams(window.location.search).get('receipt');
            if (receiptUrl) {
                // Set the href for download button
                const downloadBtn = document.getElementById('downloadReceiptBtn');
                downloadBtn.href = receiptUrl;

                // Show the modal
                const receiptModal = new bootstrap.Modal(document.getElementById('receiptModal'));
                receiptModal.show();

                // When modal is hidden, remove 'receipt' and 'success' from URL
                const modalElement = document.getElementById('receiptModal');
                modalElement.addEventListener('hidden.bs.modal', function() {
                    // Remove query params 'receipt' and 'success'
                    const url = new URL(window.location);
                    url.searchParams.delete('receipt');
                    url.searchParams.delete('success');
                    window.history.replaceState({}, document.title, url.toString());
                });
            }
        });
    </script> -->
    <?php if (isset($_SESSION['receipt_path']) && isset($_SESSION['receipt_success'])): ?>
        <script>
            document.addEventListener('DOMContentLoaded', function() {
                const downloadBtn = document.getElementById('downloadReceiptBtn');
                downloadBtn.href = <?= json_encode($_SESSION['receipt_path']); ?>;

                const modalBody = document.querySelector('#receiptModal .modal-body p');
                modalBody.textContent = <?= json_encode($_SESSION['receipt_success']); ?>;

                const receiptModal = new bootstrap.Modal(document.getElementById('receiptModal'));
                receiptModal.show();

                // Unset receipt session data using a self-reloading mechanism (no external PHP needed)
                const modalElement = document.getElementById('receiptModal');
                modalElement.addEventListener('hidden.bs.modal', function() {
                    // Reload the page without session-based modal trigger
                    const url = new URL(window.location);
                    window.location.href = url.pathname;
                });
            });
        </script>
        <?php
        // Unset the session immediately so it's only available to this first load
        unset($_SESSION['receipt_path'], $_SESSION['receipt_success']);
        ?>
    <?php endif; ?>

</body>

</html>