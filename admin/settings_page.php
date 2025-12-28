<?php
session_start();
if (!isset($_SESSION['admin_logged_in'])) {
    header("Location: ../index.php");
    exit;
}

// Redirect to backend to fetch data if not set
if (!isset($_SESSION['admin_data'])) {
    header("Location: ./process/settings_page_process.php?fetch_only=1");
    exit;
}

// Get data from session
$admin_data = $_SESSION['admin_data'];
$current_slot_count = $admin_data['slot_count'];
$fee_data = $admin_data['fees'];

// Flash messages
$password_success = $_SESSION['flash']['password_success'] ?? NULL;
$password_error   = $_SESSION['flash']['password_error'] ?? NULL;
$slot_success     = $_SESSION['flash']['slot_success'] ?? NULL;
$slot_error       = $_SESSION['flash']['slot_error'] ?? NULL;
$fee_success      = $_SESSION['flash']['fee_success'] ?? NULL;

// Clear flash and admin_data (optional, keep for next reload)
unset($_SESSION['flash'], $_SESSION['admin_data']);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Admin Settings</title>
    <link rel="stylesheet" href="../bootstrap-5.3.6/css/bootstrap.css">
    <script src="../bootstrap-5.3.6/js/bootstrap.bundle.js"></script>
</head>
<body class="bg-light">
<div class="container-fluid">
    <div class="row">
        <div class="col-md-3 col-lg-2 bg-dark min-vh-100 p-0">
            <?php include '../includes/sidebar.php'; ?>
        </div>

        <div class="col-md-9 col-lg-10 py-4 justify-content-center">
            <div class="card mx-3 shadow">
                <div class="card-header bg-primary text-white">
                    <h4 class="mb-0">Admin Settings</h4>
                </div>
                <div class="card-body">

                    <!-- Password Change -->
                    <section class="mb-5">
                        <h3>Change Password</h3>
                        <?php if (isset($password_success)) echo "<div class='alert alert-success'>$password_success</div>"; ?>
                        <?php if (isset($password_error)) echo "<div class='alert alert-danger'>$password_error</div>"; ?>
                        <form method="POST" action="./process/settings_page_process.php" class="needs-validation" novalidate>
                            <div class="mb-3">
                                <label for="new_password" class="form-label">New Password</label>
                                <input type="password" class="form-control" id="new_password" name="new_password" required />
                            </div>
                            <div class="mb-3">
                                <label for="confirm_password" class="form-label">Confirm Password</label>
                                <input type="password" class="form-control" id="confirm_password" name="confirm_password" required />
                            </div>
                            <button type="submit" name="change_password" class="btn btn-primary">Change Password</button>
                        </form>
                    </section>

                    <hr />

                    <!-- Slot Management -->
                    <section class="mb-5">
                        <h3>Manage Parking Slots</h3>
                        <?php if (isset($slot_success)) echo "<div class='alert alert-success'>$slot_success</div>"; ?>
                        <?php if (isset($slot_error)) echo "<div class='alert alert-danger'>$slot_error</div>"; ?>
                        <form method="POST" action="./process/settings_page_process.php" class="needs-validation" novalidate>
                            <div class="mb-3">
                                <label for="total_slots" class="form-label">Total Slots</label>
                                <input type="number" min="1" class="form-control" id="total_slots" name="total_slots" value="<?= $current_slot_count ?>" required />
                            </div>
                            <button type="submit" name="sync_and_update_slots" class="btn btn-primary">Update Slots</button>
                        </form>
                    </section>

                    <hr />

                    <!-- Fee Settings -->
                    <section class="mb-5">
                        <h3>Update Fee Settings</h3>
                        <?php if (isset($fee_success)) echo "<div class='alert alert-success'>$fee_success</div>"; ?>
                        <form method="POST" action="./process/settings_page_process.php" class="needs-validation" novalidate>
                            <div class="mb-4">
                                <h4>2-Wheeler</h4>
                                <div class="mb-3">
                                    <label class="form-label">First Hour (₹)</label>
                                    <input type="number" step="0.01" min="0" name="fee_2w_first" class="form-control" value="<?= htmlspecialchars($fee_data['2-wheeler']['first_hour']) ?>" required />
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Next Hour (₹)</label>
                                    <input type="number" step="0.01" min="0" name="fee_2w_next" class="form-control" value="<?= htmlspecialchars($fee_data['2-wheeler']['next_hour']) ?>" required />
                                </div>
                            </div>
                            <div class="mb-4">
                                <h4>4-Wheeler</h4>
                                <div class="mb-3">
                                    <label class="form-label">First Hour (₹)</label>
                                    <input type="number" step="0.01" min="0" name="fee_4w_first" class="form-control" value="<?= htmlspecialchars($fee_data['4-wheeler']['first_hour']) ?>" required />
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Next Hour (₹)</label>
                                    <input type="number" step="0.01" min="0" name="fee_4w_next" class="form-control" value="<?= htmlspecialchars($fee_data['4-wheeler']['next_hour']) ?>" required />
                                </div>
                            </div>
                            <button type="submit" name="update_fee" class="btn btn-primary">Update Fees</button>
                        </form>
                    </section>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
(() => {
    'use strict'
    const forms = document.querySelectorAll('.needs-validation')
    Array.from(forms).forEach(form => {
        form.addEventListener('submit', event => {
            if (!form.checkValidity()) {
                event.preventDefault()
                event.stopPropagation()
            }
            form.classList.add('was-validated')
        }, false)
    })
})();
</script>
</body>
</html>
