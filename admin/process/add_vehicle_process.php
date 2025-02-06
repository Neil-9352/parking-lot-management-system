<?php
session_start();
require_once '../../config/db.php';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $reg_number = $_POST['reg_number'];
    $vehicle_type = $_POST['vehicle_type'];
    $slot_number = intval($_POST['slot_number']);
    $in_time = date("Y-m-d H:i:s"); // Capture current timestamp

    // Insert vehicle data securely into parked_vehicles table
    $insert_query = "INSERT INTO parked_vehicles (reg_number, vehicle_type, slot_number, in_time)
                     VALUES (?, ?, ?, ?)";
    
    if ($stmt = $conn->prepare($insert_query)) {
        $stmt->bind_param("ssis", $reg_number, $vehicle_type, $slot_number, $in_time);
        if ($stmt->execute()) {
            // Update parking_slots table with vehicle details
            $update_slot_query = "UPDATE parking_slots 
                                  SET status = 'occupied', 
                                      vehicle_reg_number = ?, 
                                      vehicle_type = ?, 
                                      in_time = ? 
                                  WHERE slot_number = ?";
            if ($update_stmt = $conn->prepare($update_slot_query)) {
                $update_stmt->bind_param("sssi", $reg_number, $vehicle_type, $in_time, $slot_number);
                $update_stmt->execute();
                $update_stmt->close();
            }

            $stmt->close();
            header("Location: ../view_slots.php?success=Vehicle+parked+successfully!");
            exit;
        } else {
            echo "Error: " . $stmt->error;
        }
    } else {
        echo "Error preparing statement: " . $conn->error;
    }
}
?>
