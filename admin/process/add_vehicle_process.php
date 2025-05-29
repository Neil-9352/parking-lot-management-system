<?php
session_start();
require_once '../../config/db.php';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $reg_number = strtoupper(trim($_POST['reg_number']));
    $vehicle_type = $_POST['vehicle_type'];
    $slot_number = intval($_POST['slot_number']);
    $in_time = date("Y-m-d H:i:s");

    // Check if vehicle is already parked
    $check_stmt = $conn->prepare("SELECT 1 FROM parks_in WHERE registration_number = ? AND out_time IS NULL");
    $check_stmt->bind_param("s", $reg_number);
    $check_stmt->execute();
    $check_stmt->store_result();
    if ($check_stmt->num_rows > 0) {
        $check_stmt->close();
        $_SESSION['toast_error'] = "Vehicle is already parked!";
        header("Location: ../add_vehicle.php");
        exit;
    }
    $check_stmt->close();

    try {
        // Start Transaction
        $conn->begin_transaction();

        // Insert vehicle if not exists
        $insert_vehicle = "INSERT IGNORE INTO vehicle (registration_number, vehicle_type) VALUES (?, ?)";
        $stmt = $conn->prepare($insert_vehicle);
        $stmt->bind_param("ss", $reg_number, $vehicle_type);
        $stmt->execute();
        $stmt->close();

        // Get slot_id from slot_number
        $stmt = $conn->prepare("SELECT slot_id FROM parking_slot WHERE slot_number = ?");
        $stmt->bind_param("i", $slot_number);
        $stmt->execute();
        $stmt->bind_result($slot_id);
        if (!$stmt->fetch()) {
            throw new Exception("Invalid parking slot selected.");
        }
        $stmt->close();

        // Get latest fee_id
        $stmt = $conn->prepare("SELECT fee_id FROM fee WHERE vehicle_type = ? ORDER BY created_at DESC LIMIT 1");
        $stmt->bind_param("s", $vehicle_type);
        $stmt->execute();
        $stmt->bind_result($fee_id);
        if (!$stmt->fetch()) {
            throw new Exception("Fee configuration not found.");
        }
        $stmt->close();

        // Insert into parks_in
        $stmt = $conn->prepare("INSERT INTO parks_in (registration_number, slot_id, in_time, fee_id) VALUES (?, ?, ?, ?)");
        $stmt->bind_param("siss", $reg_number, $slot_id, $in_time, $fee_id);
        if (!$stmt->execute()) {
            throw new Exception("Error inserting vehicle parking entry.");
        }
        $stmt->close();

        // Update parking slot status
        $stmt = $conn->prepare("UPDATE parking_slot SET status = 'occupied' WHERE slot_number = ?");
        $stmt->bind_param("i", $slot_number);
        if (!$stmt->execute()) {
            throw new Exception("Failed to update slot status.");
        }
        $stmt->close();

        // Commit Transaction
        $conn->commit();
        $_SESSION['toast_success'] = "Vehicle parked successfully!";
        header("Location: ../add_vehicle.php");
        exit;

    } catch (Exception $e) {
        // ❌ Rollback on Error
        $conn->rollback();
        $_SESSION['toast_error'] = "Transaction failed: " . $e->getMessage();
        header("Location: ../add_vehicle.php");
        exit;
    }
}
