<?php
session_start();
require_once '../config/db.php';

// Handle Password Change
if (isset($_POST['change_password'])) {
    $new_password = trim($_POST['new_password']);
    $confirm_password = trim($_POST['confirm_password']);

    if ($new_password !== $confirm_password) {
        $password_error = "Passwords do not match.";
    } elseif (strlen($new_password) < 6) {
        $password_error = "Password must be at least 6 characters.";
    } else {
        // Hash the password
        $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);

        // Assuming you have an 'admin' table with a single admin user (id = 1)
        $update_password_query = "UPDATE admin SET password = ? WHERE id = 1";
        $stmt = $conn->prepare($update_password_query);
        $stmt->bind_param("s", $hashed_password);

        if ($stmt->execute()) {
            $password_success = "Password updated successfully.";
        } else {
            $password_error = "Failed to update password.";
        }
    }
}

// Handle Slot Sync and Update
if (isset($_POST['sync_and_update_slots'])) {
    // Get the number of total slots from the form input
    $total_slots = intval($_POST['total_slots']);

    // Update the 'total_slots' value in the settings table
    $update_settings_query = "UPDATE settings SET value = ? WHERE `key` = 'total_slots'";
    $stmt = $conn->prepare($update_settings_query);
    $stmt->bind_param("i", $total_slots);
    $stmt->execute();

    // Sync Parking Slots with the updated total slots
    $slot_count_query = "SELECT COUNT(*) as total FROM parking_slots";
    $slot_count_result = $conn->query($slot_count_query);
    $slot_row = $slot_count_result->fetch_assoc();
    $current_slots = intval($slot_row['total']);

    if ($total_slots > $current_slots) {
        // Add new slots
        $slots_to_add = $total_slots - $current_slots;
        for ($i = 1; $i <= $slots_to_add; $i++) {
            $new_slot_number = $current_slots + $i;
            $insert_slot = "INSERT INTO parking_slots (slot_number, status) VALUES (?, 'unoccupied')";
            $stmt = $conn->prepare($insert_slot);
            $stmt->bind_param("i", $new_slot_number);
            $stmt->execute();
        }
        $slot_success = "$slots_to_add new slots added.";
    } elseif ($total_slots < $current_slots) {
        // Delete all slots exceeding the new total (regardless of occupation)
        $slots_to_remove = $current_slots - $total_slots;
        $remove_query = "DELETE FROM parking_slots ORDER BY slot_number DESC LIMIT $slots_to_remove";
        if ($conn->query($remove_query)) {
            $slot_success = "$slots_to_remove slots removed.";
        } else {
            $slot_error = "Error removing slots: " . $conn->error;
        }
    } else {
        $slot_success = "Slot count is already correct.";
    }
}

// Handle Fee Update
if (isset($_POST['update_fee'])) {
    $fees = [
        '2-wheeler' => [
            'first_hour' => floatval($_POST['fee_2w_first']),
            'next_hour' => floatval($_POST['fee_2w_next']),
        ],
        '4-wheeler' => [
            'first_hour' => floatval($_POST['fee_4w_first']),
            'next_hour' => floatval($_POST['fee_4w_next']),
        ]
    ];

    foreach ($fees as $type => $data) {
        $stmt = $conn->prepare("INSERT INTO fee (vehicle_type, first_hour_charge, next_hour_charge)
                                VALUES (?, ?, ?)
                                ON DUPLICATE KEY UPDATE first_hour_charge = VALUES(first_hour_charge), next_hour_charge = VALUES(next_hour_charge)");
        $stmt->bind_param("sdd", $type, $data['first_hour'], $data['next_hour']);
        $stmt->execute();
    }

    $fee_success = "Fee settings updated successfully.";
}

// Fetch existing fees
$fee_data = [
    '2-wheeler' => ['first_hour' => '', 'next_hour' => ''],
    '4-wheeler' => ['first_hour' => '', 'next_hour' => '']
];
$result = $conn->query("SELECT * FROM fee");
while ($row = $result->fetch_assoc()) {
    $fee_data[$row['vehicle_type']] = [
        'first_hour' => $row['first_hour_charge'],
        'next_hour' => $row['next_hour_charge']
    ];
}
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
            <!-- Sidebar -->
            <div class="col-md-3 col-lg-2 bg-dark min-vh-100 p-0">
                <?php include '../includes/sidebar.php'; ?>
            </div>

            <!-- Main Content -->
            <div class="col-md-9 col-lg-10 py-4 justify-content-center">
                <div class="card mx-3 shadow">
                    <div class="card-header bg-primary text-white">
                        <h4 class="mb-0">Admin Settings</h4>
                    </div>
                    <div class="card-body">
                        <!-- Change Password Section -->
                        <section class="mb-5">
                            <h3>Change Password</h3>
                            <?php if (isset($password_success)) : ?>
                                <div class="alert alert-success"><?= htmlspecialchars($password_success) ?></div>
                            <?php endif; ?>
                            <?php if (isset($password_error)) : ?>
                                <div class="alert alert-danger"><?= htmlspecialchars($password_error) ?></div>
                            <?php endif; ?>
                            <form action="settings_page.php" method="POST" class="needs-validation" novalidate>
                                <div class="mb-3">
                                    <label for="new_password" class="form-label">New Password:</label>
                                    <input type="password" class="form-control" id="new_password" name="new_password" required />
                                    <div class="invalid-feedback">Please enter a new password.</div>
                                </div>

                                <div class="mb-3">
                                    <label for="confirm_password" class="form-label">Confirm Password:</label>
                                    <input type="password" class="form-control" id="confirm_password" name="confirm_password" required />
                                    <div class="invalid-feedback">Please confirm your password.</div>
                                </div>

                                <button type="submit" name="change_password" class="btn btn-primary">Change Password</button>
                            </form>
                        </section>

                        <hr />

                        <!-- Sync and Update Slots Section -->
                        <section class="mb-5">
                            <h3>Sync and Update Parking Slots</h3>
                            <?php if (isset($slot_success)) : ?>
                                <div class="alert alert-success"><?= htmlspecialchars($slot_success) ?></div>
                            <?php endif; ?>
                            <?php if (isset($slot_error)) : ?>
                                <div class="alert alert-danger"><?= htmlspecialchars($slot_error) ?></div>
                            <?php endif; ?>
                            <form action="settings_page.php" method="POST" class="needs-validation" novalidate>
                                <div class="mb-3">
                                    <label for="total_slots" class="form-label">Total Number of Slots:</label>
                                    <input type="number" class="form-control" id="total_slots" name="total_slots" required min="1"
                                        value="<?= isset($total_slots) ? intval($total_slots) : '' ?>" />
                                    <div class="invalid-feedback">Please enter the total number of slots (minimum 1).</div>
                                </div>

                                <button type="submit" name="sync_and_update_slots" class="btn btn-primary">Update and Sync Slots</button>
                            </form>
                        </section>

                        <hr />

                        <!-- Change Fee Settings -->
                        <section class="mb-5">
                            <h3>Change Fee Settings</h3>
                            <?php if (isset($fee_success)) : ?>
                                <div class="alert alert-success"><?= htmlspecialchars($fee_success) ?></div>
                            <?php endif; ?>
                            <form method="POST" class="needs-validation" novalidate>
                                <div class="mb-4">
                                    <h4>2-Wheeler</h4>
                                    <div class="mb-3">
                                        <label for="fee_2w_first" class="form-label">First Hour Fee (₹):</label>
                                        <input type="number" step="0.01" min="0" class="form-control" id="fee_2w_first" name="fee_2w_first" required
                                            value="<?= htmlspecialchars($fee_data['2-wheeler']['first_hour']) ?>" />
                                        <div class="invalid-feedback">Please enter the first hour fee for 2-wheelers.</div>
                                    </div>
                                    <div class="mb-3">
                                        <label for="fee_2w_next" class="form-label">Next Hour Fee (₹):</label>
                                        <input type="number" step="0.01" min="0" class="form-control" id="fee_2w_next" name="fee_2w_next" required
                                            value="<?= htmlspecialchars($fee_data['2-wheeler']['next_hour']) ?>" />
                                        <div class="invalid-feedback">Please enter the next hour fee for 2-wheelers.</div>
                                    </div>
                                </div>

                                <div class="mb-4">
                                    <h4>4-Wheeler</h4>
                                    <div class="mb-3">
                                        <label for="fee_4w_first" class="form-label">First Hour Fee (₹):</label>
                                        <input type="number" step="0.01" min="0" class="form-control" id="fee_4w_first" name="fee_4w_first" required
                                            value="<?= htmlspecialchars($fee_data['4-wheeler']['first_hour']) ?>" />
                                        <div class="invalid-feedback">Please enter the first hour fee for 4-wheelers.</div>
                                    </div>
                                    <div class="mb-3">
                                        <label for="fee_4w_next" class="form-label">Next Hour Fee (₹):</label>
                                        <input type="number" step="0.01" min="0" class="form-control" id="fee_4w_next" name="fee_4w_next" required
                                            value="<?= htmlspecialchars($fee_data['4-wheeler']['next_hour']) ?>" />
                                        <div class="invalid-feedback">Please enter the next hour fee for 4-wheelers.</div>
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

    <!-- Optional: Bootstrap form validation script -->
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
        })()
    </script>
</body>



</html>