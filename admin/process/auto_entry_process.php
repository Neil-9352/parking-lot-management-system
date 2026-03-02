<?php
session_start();
header('Content-Type: application/json');

require_once '../../config/db.php';

function respond($data, $http_status = 200)
{
    http_response_code($http_status);
    echo json_encode($data);
    exit;
}

// 🔐 Validate session + lot
if (!isset($_SESSION['admin_logged_in']) || !isset($_SESSION['lot_id'])) {
    respond(['error' => 'Unauthorized access'], 401);
}

$lot_id = intval($_SESSION['lot_id']);

// Read JSON input
$raw = file_get_contents('php://input');
$input = json_decode($raw, true);

if (!$input || empty($input['image_base64'])) {
    respond(['error' => 'No image provided'], 400);
}

$image_base64 = $input['image_base64'];


// =====================
// 1️⃣ Recognition Call
// =====================

$rec_url = 'https://localhost:8000/api/detect';
$payload = json_encode(['image_base64' => $image_base64]);

$ch = curl_init($rec_url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
    CURLOPT_POSTFIELDS => $payload,
    CURLOPT_TIMEOUT => 20,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_SSL_VERIFYHOST => false
]);

$rec_resp = curl_exec($ch);
$rec_http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curl_err = curl_error($ch);
curl_close($ch);

if ($rec_resp === false) {
    respond(['error' => 'Recognition error: ' . $curl_err], 502);
}

$rec_json = json_decode($rec_resp, true);
if ($rec_http !== 200 || empty($rec_json['plate']) || empty($rec_json['type'])) {
    respond(['error' => 'Recognition failed'], 422);
}

$plate = strtoupper(trim($rec_json['plate']));
$vehicle_type_raw = strtolower(trim($rec_json['type']));

$type_map = [
    'car' => '4-wheeler',
    'vehicle' => '4-wheeler',
    '4-wheeler' => '4-wheeler',
    'bike' => '2-wheeler',
    'motorbike' => '2-wheeler',
    '2-wheeler' => '2-wheeler'
];

$vehicle_type = $type_map[$vehicle_type_raw] ?? '4-wheeler';
$in_time = date("Y-m-d H:i:s");


try {

    // =====================
    // 2️⃣ Already Parked?
    // =====================
    $check_stmt = $conn->prepare("
        SELECT 1 
        FROM parks_in 
        WHERE registration_number = ? 
        AND out_time IS NULL
    ");
    $check_stmt->bind_param("s", $plate);
    $check_stmt->execute();
    $check_stmt->store_result();

    if ($check_stmt->num_rows > 0) {
        respond(['error' => 'Vehicle already parked'], 409);
    }
    $check_stmt->close();


    $conn->begin_transaction();

    // =====================
    // 3️⃣ Insert vehicle
    // =====================
    $stmt = $conn->prepare("
        INSERT IGNORE INTO vehicle (registration_number, type) 
        VALUES (?, ?)
    ");
    $stmt->bind_param("ss", $plate, $vehicle_type);
    $stmt->execute();
    $stmt->close();


    // =====================
    // 4️⃣ Get slot FROM THIS LOT ONLY
    // =====================
    $slot_stmt = $conn->prepare("
        SELECT slot_id, slot_no 
        FROM parking_slot 
        WHERE lot_id = ?
        AND status = 'unoccupied'
        ORDER BY slot_no
        LIMIT 1
        FOR UPDATE
    ");
    $slot_stmt->bind_param("i", $lot_id);
    $slot_stmt->execute();
    $slot_stmt->bind_result($slot_id, $slot_no);

    if (!$slot_stmt->fetch()) {
        $slot_stmt->close();
        $conn->rollback();
        respond(['error' => 'No available slots in this lot'], 409);
    }
    $slot_stmt->close();


    // =====================
    // 5️⃣ Get latest fee
    // =====================
    $fee_stmt = $conn->prepare("
        SELECT fee_id 
        FROM fee 
        WHERE vehicle_type = ?
        ORDER BY created_at DESC 
        LIMIT 1
    ");
    $fee_stmt->bind_param("s", $vehicle_type);
    $fee_stmt->execute();
    $fee_stmt->bind_result($fee_id);

    if (!$fee_stmt->fetch()) {
        $fee_stmt->close();
        $conn->rollback();
        throw new Exception("Fee configuration not found");
    }
    $fee_stmt->close();


    // =====================
    // 6️⃣ Insert parks_in
    // =====================
    $insert_stmt = $conn->prepare("
        INSERT INTO parks_in 
        (registration_number, slot_id, in_time, fee_id) 
        VALUES (?, ?, ?, ?)
    ");
    $insert_stmt->bind_param("sisi", $plate, $slot_id, $in_time, $fee_id);
    $insert_stmt->execute();
    $insert_stmt->close();


    // =====================
    // 7️⃣ Mark slot occupied
    // =====================
    $update_stmt = $conn->prepare("
        UPDATE parking_slot 
        SET status = 'occupied'
        WHERE slot_id = ?
        AND lot_id = ?
    ");
    $update_stmt->bind_param("ii", $slot_id, $lot_id);
    $update_stmt->execute();
    $update_stmt->close();


    $conn->commit();

    respond([
        'plate' => $plate,
        'type'  => $vehicle_type,
        'slot'  => $slot_no,
        'lot'   => $lot_id
    ], 200);

} catch (Exception $e) {

    $conn->rollback();
    respond(['error' => 'Server error: ' . $e->getMessage()], 500);
}
