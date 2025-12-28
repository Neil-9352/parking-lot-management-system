<?php
// auto_delete_process.php
session_start();
header('Content-Type: application/json; charset=utf-8');

require_once '../../config/db.php';
require_once '../../vendor/tecnickcom/tcpdf/tcpdf.php';

// Helper responder
function respond($data, $status = 200)
{
    http_response_code($status);
    echo json_encode($data);
    exit;
}

// Read and validate JSON body
$raw = file_get_contents('php://input');
$input = json_decode($raw, true);
if (!is_array($input) || empty($input['image_base64']) || !is_string($input['image_base64'])) {
    respond(['error' => 'No image provided or invalid request body'], 400);
}

$image_base64 = $input['image_base64'];

// Optional: protect against extremely large uploads
if (strlen($image_base64) > 10 * 1024 * 1024) { // ~10MB base64
    respond(['error' => 'Image too large'], 413);
}

// Call recognition API server-side
$rec_url = 'https://localhost:8000/api/detect';
$payload = json_encode(['image_base64' => $image_base64]);

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $rec_url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
curl_setopt($ch, CURLOPT_TIMEOUT, 20);

// Development option for self-signed certs (remove in production)
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

$rec_resp = curl_exec($ch);
$curl_errno = curl_errno($ch);
$curl_err = curl_error($ch);
$rec_http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($rec_resp === false || $curl_errno) {
    respond(['error' => 'Recognition service error', 'details' => $curl_err], 502);
}

$rec_json = json_decode($rec_resp, true);
if ($rec_json === null) {
    respond(['error' => 'Bad response from recognition service', 'raw' => substr($rec_resp, 0, 2000)], 502);
}

if ($rec_http === 422) {
    respond(['error' => $rec_json['error'] ?? 'No valid plate detected'], 422);
}
if ($rec_http === 400) {
    respond(['error' => $rec_json['error'] ?? 'Bad image input'], 400);
}
if ($rec_http !== 200) {
    respond(['error' => 'Recognition returned HTTP ' . $rec_http, 'details' => $rec_json], 502);
}

if (empty($rec_json['plate']) || empty($rec_json['type'])) {
    respond(['error' => 'Recognition returned incomplete data'], 502);
}

$plate = strtoupper(trim($rec_json['plate']));
$rec_type = strtolower(trim($rec_json['type']));

// Map recognition types to DB vehicle_type values (optional)
$type_map = [
    'car' => '4-wheeler',
    'vehicle' => '4-wheeler',
    '4-wheeler' => '4-wheeler',
    'bike' => '2-wheeler',
    'motorbike' => '2-wheeler',
    '2-wheeler' => '2-wheeler'
];
$vehicle_type_mapped = $type_map[$rec_type] ?? $rec_type;

try {
    // 1) Find active parks_in entry for the detected plate
    $q = "
      SELECT pi.id, pi.registration_number, pi.slot_id, pi.in_time, pi.fee_id, s.slot_number
      FROM parks_in pi
      JOIN parking_slot s ON pi.slot_id = s.slot_id
      JOIN vehicle v ON pi.registration_number = v.registration_number
      WHERE pi.registration_number = ? AND pi.out_time IS NULL
      ORDER BY pi.in_time DESC
      LIMIT 1
    ";
    $stmt = $conn->prepare($q);
    if (!$stmt) throw new Exception("DB prepare failed (find parks_in): " . $conn->error);
    $stmt->bind_param("s", $plate);
    if (!$stmt->execute()) {
        $stmt->close();
        throw new Exception("DB execute failed (find parks_in): " . $stmt->error);
    }
    $res = $stmt->get_result();
    if ($res === false) {
        $stmt->close();
        throw new Exception("DB get_result failed (find parks_in)");
    }

    if ($res->num_rows === 0) {
        $stmt->close();
        respond([
            'status' => 'no_match',
            'plate' => $plate,
            'type' => $vehicle_type_mapped,
            'message' => 'No parked vehicle with detected plate found.'
        ], 200);
    }

    $row = $res->fetch_assoc();
    $parks_in_id = $row['id'];
    $reg_number_db = $row['registration_number'];
    $slot_id = intval($row['slot_id']);
    $slot_number = $row['slot_number'] !== null ? intval($row['slot_number']) : null;
    $in_time_db = $row['in_time'];
    $fee_id = $row['fee_id'];
    $stmt->close();

    $db_vehicle_type = null;
    $vstmt = $conn->prepare("SELECT vehicle_type FROM vehicle WHERE registration_number = ? LIMIT 1");
    if ($vstmt) {
        $vstmt->bind_param("s", $plate);
        if ($vstmt->execute()) {
            $vstmt->bind_result($db_vehicle_type);
            $vstmt->fetch();
        }
        $vstmt->close();
    }

    if (!empty($db_vehicle_type) && $db_vehicle_type !== $vehicle_type_mapped) {
        respond([
            'status' => 'no_match',
            'plate' => $plate,
            'type' => $vehicle_type_mapped,
            'message' => "Detected type '{$vehicle_type_mapped}' does not match stored type '{$db_vehicle_type}'."
        ], 200);
    }

    $fee_calc_query = "
        SELECT 
            pi.id,
            pi.registration_number, 
            v.vehicle_type, 
            pi.in_time,
            TIMESTAMPDIFF(MINUTE, pi.in_time, ?) AS minutes_parked,
            CEIL(TIMESTAMPDIFF(MINUTE, pi.in_time, ?) / 60) AS hours_parked,
            f.first_hour_charge, 
            f.rest_hour_charge,
            CASE 
              WHEN CEIL(TIMESTAMPDIFF(MINUTE, pi.in_time, ?) / 60) <= 1 THEN f.first_hour_charge
              ELSE f.first_hour_charge + (CEIL(TIMESTAMPDIFF(MINUTE, pi.in_time, ?) / 60) - 1) * f.rest_hour_charge
            END AS parking_fee
        FROM parks_in pi
        JOIN vehicle v ON pi.registration_number = v.registration_number
        JOIN fee f ON pi.fee_id = f.fee_id
        WHERE pi.id = ?
        ORDER BY f.created_at DESC
        LIMIT 1
    ";

    $out_time = date("Y-m-d H:i:s");
    $stmt_fee = $conn->prepare($fee_calc_query);
    if (!$stmt_fee) throw new Exception("DB prepare failed (fee_calc_query): " . $conn->error);

    // Bind current time 4 times + parks_in_id
    $stmt_fee->bind_param("ssssi", $out_time, $out_time, $out_time, $out_time, $parks_in_id);

    if (!$stmt_fee->execute()) {
        $stmt_fee->close();
        throw new Exception("DB execute failed (fee_calc_query): " . $stmt_fee->error);
    }

    $stmt_fee->bind_result(
        $pi_id,
        $reg_number,
        $vehicle_type,
        $in_time,
        $minutes_parked,
        $hours_parked,
        $first_hour_charge,
        $rest_hour_charge,
        $parking_fee
    );

    $stmt_fee->fetch();
    $stmt_fee->close();

    if (empty($reg_number)) {
        throw new Exception("Fee calculation returned no record");
    }

    $conn->begin_transaction();

    // Update parks_in: set out_time and fee
    $update_parks_in = $conn->prepare("UPDATE parks_in SET out_time = ?, fee = ? WHERE id = ?");
    if (!$update_parks_in) {
        $conn->rollback();
        throw new Exception("DB prepare failed (update parks_in): " . $conn->error);
    }
    $update_parks_in->bind_param("sdi", $out_time, $parking_fee, $parks_in_id);
    if (!$update_parks_in->execute()) {
        $update_parks_in->close();
        $conn->rollback();
        throw new Exception("Failed updating parks_in: " . $update_parks_in->error);
    }
    $update_parks_in->close();

    // Update parking_slot status
    $update_slot = $conn->prepare("UPDATE parking_slot SET status = 'unoccupied' WHERE slot_id = ?");
    if (!$update_slot) {
        $conn->rollback();
        throw new Exception("DB prepare failed (update slot): " . $conn->error);
    }
    $update_slot->bind_param("i", $slot_id);
    if (!$update_slot->execute()) {
        $update_slot->close();
        $conn->rollback();
        throw new Exception("Failed updating parking_slot: " . $update_slot->error);
    }
    $update_slot->close();

    // Generate PDF receipt using DB-computed values
    $receipts_dir = __DIR__ . "/../receipts";
    if (!is_dir($receipts_dir) && !mkdir($receipts_dir, 0777, true)) {
        $conn->rollback();
        throw new Exception("Failed to create receipts directory");
    }

    // Sanitize filename
    $safe_plate = preg_replace('/[^A-Z0-9_-]/', '_', $plate);
    $file_name = "receipt_{$safe_plate}_" . time() . ".pdf";
    $file_path = $receipts_dir . DIRECTORY_SEPARATOR . $file_name;

    $pdf = new TCPDF();
    $pdf->AddPage();
    $pdf->SetFont('dejavusans', '', 12);
    $pdf->Cell(0, 10, "Parking Receipt", 0, 1, 'C');
    $pdf->Ln(5);
    $pdf->Cell(0, 8, "Vehicle Reg. No: {$plate}", 0, 1);
    $pdf->Cell(0, 8, "Vehicle Type: {$vehicle_type}", 0, 1);
    $pdf->Cell(0, 8, "In Time: {$in_time}", 0, 1);
    $pdf->Cell(0, 8, "Out Time: {$out_time}", 0, 1);
    $pdf->Cell(0, 8, "Total Minutes Parked: {$minutes_parked}", 0, 1);
    $pdf->Cell(0, 8, "Total Hours Parked (rounded): {$hours_parked}", 0, 1);
    $pdf->Cell(0, 8, "First Hour Charge: ₹ " . number_format($first_hour_charge, 2), 0, 1);
    $pdf->Cell(0, 8, "Subsequent Hour Charges: ₹ " . number_format($rest_hour_charge, 2), 0, 1);
    $pdf->Cell(0, 8, "Total Parking Fee: ₹ " . number_format($parking_fee, 2), 0, 1);

    $pdf->Output($file_path, "F");

    // Store relative path into DB
    $receipt_db_path = "receipts/" . $file_name;
    $upd_receipt = $conn->prepare("UPDATE parks_in SET receipt_path = ? WHERE id = ?");
    if ($upd_receipt) {
        $upd_receipt->bind_param("si", $receipt_db_path, $parks_in_id);
        if (!$upd_receipt->execute()) {
            $upd_receipt->close();
            $conn->rollback();
            throw new Exception("Failed updating receipt_path: " . $upd_receipt->error);
        }
        $upd_receipt->close();
    } else {
        $conn->rollback();
        throw new Exception("DB prepare failed (update receipt_path): " . $conn->error);
    }

    $conn->commit();

    // Return JSON success with DB-derived fee details
    respond([
        'status' => 'removed',
        'plate' => $plate,
        'type' => $vehicle_type,               // from fee query (matches vehicle table)
        'slot' => $slot_number,
        'in_time' => $in_time,
        'out_time' => $out_time,
        'duration_hours' => (int)$hours_parked,
        'duration_minutes' => (int)$minutes_parked,
        'first_hour_charge' => (float)$first_hour_charge,
        'rest_hour_charge' => (float)$rest_hour_charge,
        'charge' => (float)$parking_fee,
        'receipt_path' => $receipt_db_path
    ], 200);
} catch (Exception $e) {
    if (isset($conn) && $conn->connect_errno === 0) {
        @$conn->rollback();
    }
    respond(['error' => 'Server error: ' . $e->getMessage()], 500);
}
