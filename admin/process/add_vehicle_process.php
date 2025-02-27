<?php
session_start();
require_once '../../config/db.php';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $reg_number = strtoupper(trim($_POST['reg_number'])); // Ensure case consistency
    $vehicle_type = $_POST['vehicle_type'];
    $slot_number = intval($_POST['slot_number']);
    $in_time = date("Y-m-d H:i:s");

    // Step 1: Check if the vehicle is already parked
    $check_query = "SELECT * FROM parking_slots WHERE vehicle_reg_number = ? AND status = 'occupied'";
    if ($check_stmt = $conn->prepare($check_query)) {
        $check_stmt->bind_param("s", $reg_number);
        $check_stmt->execute();
        $result = $check_stmt->get_result();

        if ($result->num_rows > 0) {
            // Vehicle is already in the parking lot
            $check_stmt->close();
            header("Location: ../view_slots.php?error=Vehicle+is+already+parked!");
            exit;
        }
        $check_stmt->close();
    }

    // Step 2: Update the parking_slots table instead of inserting into parked_vehicles
    $update_slot_query = "UPDATE parking_slots 
                          SET status = 'occupied', vehicle_reg_number = ?, vehicle_type = ?, in_time = ? 
                          WHERE slot_number = ? AND status = 'unoccupied'"; // Ensure only unoccupied slots are updated
                          
    if ($update_stmt = $conn->prepare($update_slot_query)) {
        $update_stmt->bind_param("sssi", $reg_number, $vehicle_type, $in_time, $slot_number);
        if ($update_stmt->execute() && $update_stmt->affected_rows > 0) {
            $update_stmt->close();
            header("Location: ../view_slots.php?success=Vehicle+parked+successfully!");
            exit;
        } else {
            echo "Error: Either the slot is already occupied or there was an issue updating.";
        }
    } else {
        echo "Error preparing statement: " . $conn->error;
    }
}
?>
