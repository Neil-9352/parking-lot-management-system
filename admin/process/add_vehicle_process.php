<?php
session_start();
require_once '../../config/db.php';

// Validate login + lot selection
if (!isset($_SESSION['admin_logged_in']) || !isset($_SESSION['lot_id'])) {
    header("Location: ../../index.php");
    exit;
}

$lot_id = intval($_SESSION['lot_id']);

if ($_SERVER['REQUEST_METHOD'] == 'POST') {

    $reg_number   = strtoupper(trim($_POST['reg_number']));
    $vehicle_type = $_POST['vehicle_type'];
    $slot_no      = intval($_POST['slot_number']);
    $in_time      = date("Y-m-d H:i:s");

    // 1️⃣ Check if vehicle already parked anywhere
    $check_stmt = $conn->prepare("
        SELECT 1 
        FROM parks_in 
        WHERE registration_number = ? 
        AND out_time IS NULL
    ");
    $check_stmt->bind_param("s", $reg_number);
    $check_stmt->execute();
    $check_stmt->store_result();

    if ($check_stmt->num_rows > 0) {
        $_SESSION['toast_error'] = "Vehicle is already parked!";
        header("Location: ../add_vehicle.php");
        exit;
    }
    $check_stmt->close();

    try {

        $conn->begin_transaction();

        // 2️⃣ Insert vehicle if not exists
        $stmt = $conn->prepare("
            INSERT IGNORE INTO vehicle (registration_number, type) 
            VALUES (?, ?)
        ");
        $stmt->bind_param("ss", $reg_number, $vehicle_type);
        $stmt->execute();
        $stmt->close();

        // 3️⃣ Get slot_id ONLY from this lot and ensure it's unoccupied
        $stmt = $conn->prepare("
            SELECT slot_id 
            FROM parking_slot 
            WHERE slot_no = ? 
            AND lot_id = ?
            AND status = 'unoccupied'
            FOR UPDATE
        ");
        $stmt->bind_param("ii", $slot_no, $lot_id);
        $stmt->execute();
        $stmt->bind_result($slot_id);

        if (!$stmt->fetch()) {
            throw new Exception("Invalid or unavailable slot selected.");
        }
        $stmt->close();

        // 4️⃣ Get latest fee configuration
        $stmt = $conn->prepare("
            SELECT fee_id 
            FROM fee 
            WHERE vehicle_type = ? 
            ORDER BY created_at DESC 
            LIMIT 1
        ");
        $stmt->bind_param("s", $vehicle_type);
        $stmt->execute();
        $stmt->bind_result($fee_id);

        if (!$stmt->fetch()) {
            throw new Exception("Fee configuration not found.");
        }
        $stmt->close();

        // 5️⃣ Insert parking record
        $stmt = $conn->prepare("
            INSERT INTO parks_in 
            (registration_number, slot_id, lot_id, in_time, fee_id) 
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->bind_param("siisi", $reg_number, $slot_id, $lot_id, $in_time, $fee_id);

        if (!$stmt->execute()) {
            throw new Exception("Error inserting parking entry.");
        }
        $stmt->close();

        // 6️⃣ Update slot status (strictly for this lot)
        $stmt = $conn->prepare("
            UPDATE parking_slot 
            SET status = 'occupied' 
            WHERE slot_id = ? 
            AND lot_id = ?
        ");
        $stmt->bind_param("ii", $slot_id, $lot_id);

        if (!$stmt->execute()) {
            throw new Exception("Failed to update slot status.");
        }
        $stmt->close();

        $conn->commit();

        $_SESSION['toast_success'] = "Vehicle parked successfully!";
        header("Location: ../add_vehicle.php");
        exit;

    } catch (Exception $e) {

        $conn->rollback();

        $_SESSION['toast_error'] = "Transaction failed: " . $e->getMessage();
        header("Location: ../add_vehicle.php");
        exit;
    }
}
