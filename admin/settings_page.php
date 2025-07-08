<?php
session_start();
require_once '../config/db.php';

if (!isset($_SESSION['admin_logged_in'])) {
    header("Location: ../index.php");
    exit;
}

// Handle Password Change
if (isset($_POST['change_password'])) {
    $new_password = trim($_POST['new_password']);
    $confirm_password = trim($_POST['confirm_password']);

    if ($new_password !== $confirm_password) {
        $password_error = "Passwords do not match.";
    } elseif (strlen($new_password) < 6) {
        $password_error = "Password must be at least 6 characters.";
    } else {
        $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
        $stmt = $conn->prepare("UPDATE admin SET password = ? WHERE id = 1");
        $stmt->bind_param("s", $hashed_password);
        $stmt->execute();
        $password_success = "Password updated successfully.";
    }
}

// Fetch current slot count
$current_slot_count = 0;
$slot_count_result = $conn->query("SELECT COUNT(*) AS total FROM parking_slot");
if ($slot_count_result) {
    $row = $slot_count_result->fetch_assoc();
    $current_slot_count = intval($row['total']);
}

// Handle Slot Sync
if (isset($_POST['sync_and_update_slots'])) {
    $total_slots = intval($_POST['total_slots']);
    if ($total_slots < 1) {
        $slot_error = "Total slots must be at least 1.";
    } else {
        if ($total_slots > $current_slot_count) {
            $slots_to_add = $total_slots - $current_slot_count;
            for ($i = 1; $i <= $slots_to_add; $i++) {
                $new_slot_number = $current_slot_count + $i;
                $stmt = $conn->prepare("INSERT INTO parking_slot (slot_number, status) VALUES (?, 'unoccupied')");
                $stmt->bind_param("i", $new_slot_number);
                $stmt->execute();
            }
            $slot_success = "$slots_to_add new slots added.";
        } elseif ($total_slots < $current_slot_count) {
            $slots_to_remove = $current_slot_count - $total_slots;
            $conn->query("DELETE FROM parking_slot ORDER BY slot_number DESC LIMIT $slots_to_remove");
            $slot_success = "$slots_to_remove slots removed.";
        } else {
            $slot_success = "Slot count is already correct.";
        }
        // Update current count
        $current_slot_count = $total_slots;
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
        $stmt = $conn->prepare("
            INSERT INTO fee (vehicle_type, first_hour_charge, rest_hour_charge)
            VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE 
                first_hour_charge = VALUES(first_hour_charge),
                rest_hour_charge = VALUES(rest_hour_charge)");
        $stmt->bind_param("sdd", $type, $data['first_hour'], $data['next_hour']);
        $stmt->execute();
    }
    $fee_success = "Fee settings updated successfully.";
}

// Fetch current fees
$fee_data = [
    '2-wheeler' => ['first_hour' => '', 'next_hour' => ''],
    '4-wheeler' => ['first_hour' => '', 'next_hour' => '']
];
$res = $conn->query("SELECT * FROM fee");
while ($row = $res->fetch_assoc()) {
    $fee_data[$row['vehicle_type']] = [
        'first_hour' => $row['first_hour_charge'],
        'next_hour' => $row['rest_hour_charge']
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
                        <form method="POST" class="needs-validation" novalidate>
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
                        <form method="POST" class="needs-validation" novalidate>
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
                        <form method="POST" class="needs-validation" novalidate>
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
