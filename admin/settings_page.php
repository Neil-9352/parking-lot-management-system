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
        // Hash the password (good practice)
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
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Admin Settings</title>
    <link rel="stylesheet" href="../assets/css/styles.css">
</head>
<body>
    <?php include '../includes/sidebar.php'; ?>
    <div class="main-content">
        <h2>Admin Settings</h2>

        <!-- Change Password Section -->
        <section>
            <h3>Change Password</h3>
            <?php if (isset($password_success)) echo "<p style='color: green;'>$password_success</p>"; ?>
            <?php if (isset($password_error)) echo "<p style='color: red;'>$password_error</p>"; ?>
            <form action="settings_page.php" method="POST">
                <label for="new_password">New Password:</label><br>
                <input type="password" id="new_password" name="new_password" required><br><br>

                <label for="confirm_password">Confirm Password:</label><br>
                <input type="password" id="confirm_password" name="confirm_password" required><br><br>

                <button type="submit" name="change_password">Change Password</button>
            </form>
        </section>

        <hr>

        <!-- Sync and Update Slots Section -->
        <section>
            <h3>Sync and Update Parking Slots</h3>
            <?php if (isset($slot_success)) echo "<p style='color: green;'>$slot_success</p>"; ?>
            <?php if (isset($slot_error)) echo "<p style='color: red;'>$slot_error</p>"; ?>
            <form action="settings_page.php" method="POST">
                <label for="total_slots">Total Number of Slots:</label><br>
                <input type="number" id="total_slots" name="total_slots" required min="1" value="<?php echo isset($total_slots) ? $total_slots : ''; ?>"><br><br>

                <button type="submit" name="sync_and_update_slots">Update and Sync Slots</button>
            </form>
        </section>

        <hr>

        <!-- Future Sections -->
        <section>
            <h3>Other Admin Tools (Coming Soon)</h3>
            <ul>
                <li>Backup Database</li>
                <li>Reset All Parking Data</li>
                <li>Manage Admin Users</li>
            </ul>
        </section>
    </div>
</body>
</html>