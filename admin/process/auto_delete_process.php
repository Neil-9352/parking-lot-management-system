<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

require_once '../../config/db.php';
require_once '../../vendor/tecnickcom/tcpdf/tcpdf.php';

function respond($data, $status = 200)
{
    http_response_code($status);
    echo json_encode($data);
    exit;
}

// 🔐 Validate session + lot
if (!isset($_SESSION['admin_logged_in']) || !isset($_SESSION['lot_id'])) {
    respond(['error' => 'Unauthorized access'], 401);
}

$lot_id = intval($_SESSION['lot_id']);

// =====================
// 1️⃣ Read Input
// =====================
$raw = file_get_contents('php://input');
$input = json_decode($raw, true);

if (!is_array($input) || empty($input['image_base64'])) {
    respond(['error' => 'No image provided'], 400);
}

$image_base64 = $input['image_base64'];


// =====================
// 2️⃣ Recognition
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
if ($rec_http !== 200 || empty($rec_json['plate'])) {
    respond(['error' => 'Recognition failed'], 422);
}

$plate = strtoupper(trim($rec_json['plate']));
$out_time = date("Y-m-d H:i:s");

try {

    // =====================
    // 3️⃣ Begin transaction
    // =====================
    $conn->begin_transaction();

    // =====================
    // 4️⃣ Find active record IN THIS LOT ONLY
    // =====================
    $stmt = $conn->prepare("
        SELECT pi.id, pi.slot_id, pi.in_time, pi.fee_id,
               s.slot_no
        FROM parks_in pi
        JOIN parking_slot s ON pi.slot_id = s.slot_id
        WHERE pi.registration_number = ?
        AND pi.out_time IS NULL
        AND s.lot_id = ?
        LIMIT 1
        FOR UPDATE
    ");
    $stmt->bind_param("si", $plate, $lot_id);
    $stmt->execute();
    $stmt->bind_result($parks_in_id, $slot_id, $in_time, $fee_id, $slot_no);

    if (!$stmt->fetch()) {
        $stmt->close();
        $conn->rollback();
        respond([
            'status' => 'no_match',
            'plate' => $plate,
            'message' => 'No parked vehicle found in this lot.'
        ], 200);
    }
    $stmt->close();

    // =====================
    // 5️⃣ Calculate Fee
    // =====================
    $fee_stmt = $conn->prepare("
        SELECT 
            v.type,
            CEIL(TIMESTAMPDIFF(SECOND, pi.in_time, ?) / 60) AS minutes_parked,
            GREATEST(1, CEIL(TIMESTAMPDIFF(SECOND, pi.in_time, ?) / 3600)) AS hours_parked,
            f.first_hour_charge,
            f.rest_hour_charge,
            CASE 
                WHEN GREATEST(1, CEIL(TIMESTAMPDIFF(SECOND, pi.in_time, ?) / 3600)) = 1
                THEN f.first_hour_charge
                ELSE f.first_hour_charge +
                     (GREATEST(1, CEIL(TIMESTAMPDIFF(SECOND, pi.in_time, ?) / 3600)) - 1)
                     * f.rest_hour_charge
            END AS parking_fee
        FROM parks_in pi
        JOIN vehicle v ON pi.registration_number = v.registration_number
        JOIN fee f ON pi.fee_id = f.fee_id
        WHERE pi.id = ?
        LIMIT 1
    ");

    $fee_stmt->bind_param("ssssi", $out_time, $out_time, $out_time, $out_time, $parks_in_id);
    $fee_stmt->execute();
    $fee_stmt->bind_result(
        $vehicle_type,
        $minutes_parked,
        $hours_parked,
        $first_hour_charge,
        $rest_hour_charge,
        $parking_fee
    );
    $fee_stmt->fetch();
    $fee_stmt->close();

    // =====================
    // 6️⃣ Update parks_in
    // =====================
    $upd = $conn->prepare("
        UPDATE parks_in 
        SET out_time = ?, fee = ?
        WHERE id = ?
    ");
    $upd->bind_param("sdi", $out_time, $parking_fee, $parks_in_id);
    $upd->execute();
    $upd->close();

    // =====================
    // 7️⃣ Update slot (restricted by lot)
    // =====================
    $upd_slot = $conn->prepare("
        UPDATE parking_slot 
        SET status = 'unoccupied'
        WHERE slot_id = ?
        AND lot_id = ?
    ");
    $upd_slot->bind_param("ii", $slot_id, $lot_id);
    $upd_slot->execute();
    $upd_slot->close();


    // =====================
    // 7.5⃣ Complete booking and issue refund if applicable
    // Find any ACTIVE booking for this vehicle on this slot and mark it done.
    // =====================
    $booking_id     = null;
    $booking_amount = null;
    $refund_status  = null;

    $bk_stmt = $conn->prepare("
        SELECT booking_id, booking_amount
        FROM books
        WHERE registration_number = ?
        AND slot_id = ?
        AND booking_status = 'ACTIVE'
        LIMIT 1
        FOR UPDATE
    ");
    $bk_stmt->bind_param("si", $plate, $slot_id);
    $bk_stmt->execute();
    $bk_stmt->bind_result($booking_id, $booking_amount);
    $has_booking = $bk_stmt->fetch();
    $bk_stmt->close();

    if ($has_booking) {
        $upd_bk = $conn->prepare("
            UPDATE books
            SET booking_status = 'COMPLETED', refund_status = 'REFUNDED'
            WHERE booking_id = ?
        ");
        $upd_bk->bind_param("i", $booking_id);
        $upd_bk->execute();
        $upd_bk->close();
        $refund_status = 'REFUNDED';
    }


    // =====================
    // 8️⃣ Generate receipt
    // =====================
    $receipts_dir = __DIR__ . "/../receipts";
    if (!is_dir($receipts_dir)) {
        mkdir($receipts_dir, 0777, true);
    }

    $safe_plate = preg_replace('/[^A-Z0-9_-]/', '_', $plate);
    $file_name = "receipt_{$safe_plate}_" . time() . ".pdf";
    $file_path = $receipts_dir . "/" . $file_name;

    $pdf = new TCPDF();
    $pdf->AddPage();
    $pdf->SetFont('dejavusans', '', 12);

    $pdf->Cell(0, 10, "Parking Receipt", 0, 1, 'C');
    $pdf->Ln(5);

    $pdf->Cell(0, 8, "Vehicle Reg. No: {$plate}", 0, 1);
    $pdf->Cell(0, 8, "Vehicle Type: {$vehicle_type}", 0, 1);
    $pdf->Cell(0, 8, "In Time: {$in_time}", 0, 1);
    $pdf->Cell(0, 8, "Out Time: {$out_time}", 0, 1);
    $pdf->Cell(0, 8, "Minutes Parked: {$minutes_parked}", 0, 1);
    $pdf->Cell(0, 8, "Hours Parked (rounded): {$hours_parked}", 0, 1);
    $pdf->Cell(0, 8, "First Hour: \xe2\x82\xb9 " . number_format($first_hour_charge, 2), 0, 1);
    $pdf->Cell(0, 8, "Next Hour: \xe2\x82\xb9 " . number_format($rest_hour_charge, 2), 0, 1);
    $pdf->Cell(0, 8, "Total Fee: \xe2\x82\xb9 " . number_format($parking_fee, 2), 0, 1);

    if ($booking_id !== null) {
        $pdf->Ln(3);
        $pdf->Cell(0, 8, "--- Booking Details ---", 0, 1, 'C');
        $pdf->Cell(0, 8, "Booking ID: #{$booking_id}", 0, 1);
        $pdf->Cell(0, 8, "Deposit Paid: \xe2\x82\xb9 " . number_format($booking_amount, 2), 0, 1);
        $pdf->Cell(0, 8, "Deposit Refund: {$refund_status}", 0, 1);
    }

    $pdf->Output($file_path, "F");

    $receipt_db_path = "receipts/" . $file_name;

    $upd_receipt = $conn->prepare("
        UPDATE parks_in 
        SET receipt_path = ?
        WHERE id = ?
    ");
    $upd_receipt->bind_param("si", $receipt_db_path, $parks_in_id);
    $upd_receipt->execute();
    $upd_receipt->close();

    $conn->commit();

    $response = [
        'status'         => 'removed',
        'type'           => $vehicle_type,
        'plate'          => $plate,
        'slot'           => $slot_no,
        'duration_hours' => (int)$hours_parked,
        'charge'         => (float)$parking_fee,
        'receipt_path'   => $receipt_db_path
    ];
    if ($booking_id !== null) {
        $response['booking_id']     = $booking_id;
        $response['booking_amount'] = (float)$booking_amount;
        $response['refund_status']  = $refund_status;
    }
    respond($response, 200);

} catch (Exception $e) {
    $conn->rollback();
    respond(['error' => 'Server error: ' . $e->getMessage()], 500);
}
