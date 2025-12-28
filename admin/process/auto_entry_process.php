<?php
// auto_entry_process.php
// Receives image_base64 (JSON) from auto_entry.php, calls recognition API on localhost:8000,
// then inserts vehicle into DB and returns assigned slot as JSON.

session_start();
header('Content-Type: application/json');

require_once '../../config/db.php'; // adjust path if your project structure differs
// $conn is expected to be a mysqli connection from db.php

// Helper: send JSON response and exit
function respond($data, $http_status = 200)
{
    http_response_code($http_status);
    echo json_encode($data);
    exit;
}

// Read input — prefer JSON body
$raw = file_get_contents('php://input');
$input = json_decode($raw, true);

// If not JSON, attempt to read from $_POST (form submit fallback)
if (!$input) {
    if (!empty($_POST['image_base64'])) {
        $input = ['image_base64' => $_POST['image_base64']];
    } else {
        respond(['error' => 'Invalid request payload'], 400);
    }
}

// Validate input
if (empty($input['image_base64'])) {
    respond(['error' => 'No image provided'], 400);
}

$image_base64 = $input['image_base64'];

// Optional: if frontend already sent reg_number & vehicle_type (when frontend calls recognizer itself),
// accept and skip calling recognizer. But per your request we'll call the recognition API server-side.
// If you want to support the other flow, you can provide reg_number/vehicle_type in the request and
// set $skip_recognizer = true.
$skip_recognizer = false;
$reg_number_override = null;
$vehicle_type_override = null;
if (!empty($input['reg_number']) && !empty($input['vehicle_type'])) {
    // If you want always to run recognizer, comment out this block.
    $skip_recognizer = false; // keep false to always call recognizer
    // If you want to use frontend-recognized values, set to true and uncomment:
    // $skip_recognizer = true;
    // $reg_number_override = strtoupper(trim($input['reg_number']));
    // $vehicle_type_override = $input['vehicle_type'];
}

$plate = null;
$vehicle_type = null;

// 1) Call recognition API unless skipped
if (!$skip_recognizer) {
    $rec_url = 'https://localhost:8000/api/detect'; // your FastAPI endpoint
    $payload = json_encode(['image_base64' => $image_base64]);

    $ch = curl_init($rec_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_TIMEOUT, 20);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

    $rec_resp = curl_exec($ch);
    $rec_http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_err = curl_error($ch);
    curl_close($ch);

    if ($rec_resp === false) {
        respond(['error' => 'Recognition service error: ' . $curl_err], 502);
    }

    $rec_json = json_decode($rec_resp, true);
    if ($rec_json === null) {
        // If recognition server returned non-JSON, return raw message (but truncated)
        respond(['error' => 'Bad response from recognition service', 'raw' => substr($rec_resp, 0, 1000)], 502);
    }

    // Propagate semantic errors from recognition API:
    if ($rec_http === 422) {
        // e.g., {"error":"No valid plate detected"} — pass through
        respond(['error' => $rec_json['error'] ?? 'No valid plate/vehicle detected'], 422);
    }

    if ($rec_http === 400) {
        respond(['error' => $rec_json['error'] ?? 'Bad image input'], 400);
    }

    if ($rec_http !== 200) {
        respond(['error' => 'Recognition service returned HTTP ' . $rec_http, 'details' => $rec_json], 502);
    }

    // success: expect {"plate":"MH12AB1234", "type":"car"}
    if (empty($rec_json['plate']) || empty($rec_json['type'])) {
        respond(['error' => 'Recognition returned incomplete data'], 502);
    }

    $plate = strtoupper(trim($rec_json['plate']));
    $vehicle_type = trim($rec_json['type']);
} else {
    // Use overrides
    $plate = $reg_number_override;
    $vehicle_type = $vehicle_type_override;
}

$type_map = [
    'car' => '4-wheeler',
    'vehicle' => '4-wheeler',
    '4-wheeler' => '4-wheeler',
    'bike' => '2-wheeler',
    'motorbike' => '2-wheeler',
    '2-wheeler' => '2-wheeler'
];

$vehicle_type_mapped = $type_map[strtolower($vehicle_type)] ?? $vehicle_type; // fallback to raw if unknown

$in_time = date("Y-m-d H:i:s");

try {
    // 1) Check if vehicle already parked
    $check_stmt = $conn->prepare("SELECT 1 FROM parks_in WHERE registration_number = ? AND out_time IS NULL");
    if (!$check_stmt) throw new Exception("DB prepare failed: " . $conn->error);
    $check_stmt->bind_param("s", $plate);
    $check_stmt->execute();
    $check_stmt->store_result();
    if ($check_stmt->num_rows > 0) {
        $check_stmt->close();
        respond(['error' => 'Vehicle is already parked'], 409);
    }
    $check_stmt->close();

    // Start transaction
    $conn->begin_transaction();

    // Insert vehicle if not exists
    $insert_vehicle = "INSERT IGNORE INTO vehicle (registration_number, vehicle_type) VALUES (?, ?)";
    $stmt = $conn->prepare($insert_vehicle);
    if (!$stmt) throw new Exception("DB prepare failed (insert vehicle): " . $conn->error);
    $stmt->bind_param("ss", $plate, $vehicle_type_mapped);
    if (!$stmt->execute()) throw new Exception("Failed to insert vehicle: " . $stmt->error);
    $stmt->close();

    // Select an available slot (lock it with FOR UPDATE)
    $slot_query = "SELECT slot_id, slot_number FROM parking_slot WHERE status = 'unoccupied' ORDER BY slot_number LIMIT 1 FOR UPDATE";
    $res = $conn->query($slot_query);
    if (!$res) throw new Exception("Failed selecting slot: " . $conn->error);

    if ($res->num_rows === 0) {
        $conn->rollback();
        respond(['error' => 'No available parking slots'], 409);
    }

    $slot_row = $res->fetch_assoc();
    $slot_id = intval($slot_row['slot_id']);
    $slot_number = intval($slot_row['slot_number']);

    // Get latest fee_id for this vehicle type (matching your manual logic)
    $fee_stmt = $conn->prepare("SELECT fee_id FROM fee WHERE vehicle_type = ? ORDER BY created_at DESC LIMIT 1");
    if (!$fee_stmt) throw new Exception("DB prepare failed (fee): " . $conn->error);
    $fee_stmt->bind_param("s", $vehicle_type_mapped);
    $fee_stmt->execute();
    $fee_stmt->bind_result($fee_id);
    if (!$fee_stmt->fetch()) {
        $fee_stmt->close();
        $conn->rollback();
        throw new Exception("Fee configuration not found for vehicle type: " . $vehicle_type_mapped);
    }
    $fee_stmt->close();

    // Insert into parks_in
    $insert_parks_in = "INSERT INTO parks_in (registration_number, slot_id, in_time, fee_id) VALUES (?, ?, ?, ?)";
    $pstmt = $conn->prepare($insert_parks_in);
    if (!$pstmt) throw new Exception("DB prepare failed (parks_in): " . $conn->error);
    $pstmt->bind_param("sisi", $plate, $slot_id, $in_time, $fee_id);
    if (!$pstmt->execute()) {
        $pstmt->close();
        $conn->rollback();
        throw new Exception("Error inserting vehicle parking entry: " . $pstmt->error);
    }
    $pstmt->close();

    // Update parking_slot status to occupied (use slot_id)
    $update_slot = $conn->prepare("UPDATE parking_slot SET status = 'occupied' WHERE slot_id = ?");
    if (!$update_slot) throw new Exception("DB prepare failed (update slot): " . $conn->error);
    $update_slot->bind_param("i", $slot_id);
    if (!$update_slot->execute()) {
        $update_slot->close();
        $conn->rollback();
        throw new Exception("Failed to update slot status: " . $update_slot->error);
    }
    $update_slot->close();

    // Commit transaction
    $conn->commit();

    // Optionally, you may want to store the captured image on server or in DB.
    // This script currently does NOT store the image; if you want to save it,
    // decode $image_base64 and write to disk or to a DB blob column here.

    // Return success with assigned slot and recognized items to frontend
    respond(['plate' => $plate, 'type' => $vehicle_type_mapped, 'slot' => $slot_number], 200);
} catch (Exception $e) {
    // Rollback if transaction is active
    if ($conn->connect_errno === 0) {
        // If connection exists and autocommit is disabled due to begin_transaction
        @$conn->rollback();
    }
    // For security avoid exposing raw DB errors in production; here we include the message for debugging
    respond(['error' => 'Server error: ' . $e->getMessage()], 500);
}
