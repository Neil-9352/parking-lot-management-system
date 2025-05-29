<?php
session_start();
require_once '../config/db.php';

// Check if admin is logged in
if (!isset($_SESSION['admin_logged_in'])) {
    header("Location: ../index.php");
    exit;
}

$success_msg = $_SESSION['toast_success'] ?? null;
$error_msg = $_SESSION['toast_error'] ?? null;
unset($_SESSION['toast_success'], $_SESSION['toast_error']);

// Fetch available parking slots (only unoccupied slots)
$slots_query = "SELECT slot_number FROM parking_slot WHERE status = 'unoccupied'";
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
            <div class="col-md-9 col-lg-10 py-4 justify-content-center">
                <div class="card shadow mx-3"> 
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

    <?php if ($success_msg): ?>
        <div class="toast-container position-fixed bottom-0 end-0 p-3">
            <div class="toast align-items-center text-bg-success border-0 show" role="alert" aria-live="assertive" aria-atomic="true">
                <div class="d-flex">
                    <div class="toast-body">
                        <?= htmlspecialchars($success_msg) ?>
                    </div>
                    <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($error_msg): ?>
        <div class="toast-container position-fixed bottom-0 end-0 p-3">
            <div class="toast align-items-center text-bg-danger border-0 show" role="alert" aria-live="assertive" aria-atomic="true">
                <div class="d-flex">
                    <div class="toast-body">
                        <?= htmlspecialchars($error_msg) ?>
                    </div>
                    <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const toastElList = [].slice.call(document.querySelectorAll('.toast'));
            toastElList.forEach(function(toastEl) {
                const toast = new bootstrap.Toast(toastEl);
                toast.show();
            });
        });
    </script>

</body>

</html>