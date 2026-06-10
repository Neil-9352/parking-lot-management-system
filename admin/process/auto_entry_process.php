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


    // =====================
    // 2.5⃣ Mark expired bookings as NO_SHOW
    // If the grace window (expected_start_time + 15 min) has already passed
    // and the vehicle never arrived, cancel the deposit refund.
    // =====================
    $no_show_stmt = $conn->prepare("
        UPDATE books
        SET booking_status = 'NO_SHOW', refund_status = 'NOT_APPLICABLE'
        WHERE registration_number = ?
        AND booking_status = 'ACTIVE'
        AND DATE_ADD(expected_start_time, INTERVAL 15 MINUTE) < NOW()
    ");
    $no_show_stmt->bind_param("s", $plate);
    $no_show_stmt->execute();
    $no_show_stmt->close();


    $conn->begin_transaction();

    // =====================
    // 3⃣ Insert vehicle
    // =====================
    $stmt = $conn->prepare("
        INSERT IGNORE INTO vehicle (registration_number, type) 
        VALUES (?, ?)
    ");
    $stmt->bind_param("ss", $plate, $vehicle_type);
    $stmt->execute();
    $stmt->close();


    // =====================
    // 4⃣ Check for an active booking within ±15 mins of expected_start_time
    // If found, assign the pre-booked slot. Otherwise fall back to walk-in.
    // =====================
    $booking_id = null;
    $slot_id    = null;
    $slot_no    = null;
    $entry_type = 'walk-in';
    $early_walkin        = false;
    $cancelled_booking_id = null;
    $refund_amount        = null;

    $book_stmt = $conn->prepare("
        SELECT b.booking_id, b.slot_id, ps.slot_no
        FROM books b
        JOIN parking_slot ps ON b.slot_id = ps.slot_id
        WHERE b.registration_number = ?
        AND b.booking_status = 'ACTIVE'
        AND NOW() BETWEEN DATE_SUB(b.expected_start_time, INTERVAL 15 MINUTE)
                      AND DATE_ADD(b.expected_start_time, INTERVAL 15 MINUTE)
        AND ps.lot_id = ?
        ORDER BY b.expected_start_time ASC
        LIMIT 1
        FOR UPDATE
    ");
    $book_stmt->bind_param("si", $plate, $lot_id);
    $book_stmt->execute();
    $book_stmt->bind_result($booking_id, $slot_id, $slot_no);
    $has_booking = $book_stmt->fetch();
    $book_stmt->close();

    if ($has_booking) {
        // Use the pre-booked slot; trust the registered vehicle type if available
        $entry_type = 'booked';
        $vt_stmt = $conn->prepare("
            SELECT type FROM vehicle WHERE registration_number = ? LIMIT 1
        ");
        $vt_stmt->bind_param("s", $plate);
        $vt_stmt->execute();
        $vt_stmt->bind_result($registered_type);
        if ($vt_stmt->fetch() && !empty($registered_type)) {
            $vehicle_type = $registered_type;
        }
        $vt_stmt->close();
    } else {
        // =====================
        // 4a⃣ Early arrival? Check for an upcoming booking >15 min away.
        // If found, cancel it with 90% refund (10% penalty) and treat as walk-in.
        // =====================
        $early_stmt = $conn->prepare("
            SELECT b.booking_id, b.booking_amount
            FROM books b
            JOIN parking_slot ps ON b.slot_id = ps.slot_id
            WHERE b.registration_number = ?
            AND b.booking_status = 'ACTIVE'
            AND NOW() < DATE_SUB(b.expected_start_time, INTERVAL 15 MINUTE)
            AND ps.lot_id = ?
            ORDER BY b.expected_start_time ASC
            LIMIT 1
            FOR UPDATE
        ");
        $early_stmt->bind_param("si", $plate, $lot_id);
        $early_stmt->execute();
        $early_stmt->bind_result($cancelled_booking_id, $original_amount);
        $has_early_booking = $early_stmt->fetch();
        $early_stmt->close();

        if ($has_early_booking) {
            // Cancel booking with 90% refund
            $refund_amount = round($original_amount * 0.90, 2);
            $cancel_stmt = $conn->prepare("
                UPDATE books
                SET booking_status = 'CANCELLED', refund_status = 'REFUNDED'
                WHERE booking_id = ?
            ");
            $cancel_stmt->bind_param("i", $cancelled_booking_id);
            $cancel_stmt->execute();
            $cancel_stmt->close();
            $early_walkin = true;
        }

        // =====================
        // 4b⃣ Walk-in slot selection — priority order:
        //   1st: status = 'unoccupied'  (truly free)
        //   2nd: status = 'booked' where booking starts > 3 hours from now
        // =====================

        // --- Phase 1: prefer unoccupied ---
        $slot_stmt = $conn->prepare("
            SELECT slot_id, slot_no
            FROM parking_slot
            WHERE lot_id = ?
            AND status = 'unoccupied'
            ORDER BY RAND()
            LIMIT 1
            FOR UPDATE
        ");
        $slot_stmt->bind_param("i", $lot_id);
        $slot_stmt->execute();
        $slot_stmt->bind_result($slot_id, $slot_no);
        $found_slot = $slot_stmt->fetch();
        $slot_stmt->close();

        // --- Phase 2: fallback — booked slot with >3h until booking start ---
        $displaced_booking_id     = null;
        $displaced_booking_amount = null;

        if (!$found_slot) {
            $fallback_stmt = $conn->prepare("
                SELECT ps.slot_id, ps.slot_no, b.booking_id, b.booking_amount
                FROM parking_slot ps
                JOIN books b ON b.slot_id = ps.slot_id
                WHERE ps.lot_id = ?
                AND ps.status = 'booked'
                AND b.booking_status = 'ACTIVE'
                AND b.expected_start_time > DATE_ADD(NOW(), INTERVAL 3 HOUR)
                ORDER BY b.expected_start_time DESC
                LIMIT 1
                FOR UPDATE
            ");
            $fallback_stmt->bind_param("i", $lot_id);
            $fallback_stmt->execute();
            $fallback_stmt->bind_result($slot_id, $slot_no,
                                         $displaced_booking_id, $displaced_booking_amount);
            $found_slot = $fallback_stmt->fetch();
            $fallback_stmt->close();

            if ($found_slot) {
                // Cancel the displaced booking (full refund — 3+ hours notice)
                $disp_cancel = $conn->prepare("
                    UPDATE books
                    SET booking_status = 'CANCELLED', refund_status = 'REFUNDED'
                    WHERE booking_id = ?
                ");
                $disp_cancel->bind_param("i", $displaced_booking_id);
                $disp_cancel->execute();
                $disp_cancel->close();
                $entry_type = 'walkin_on_booked';
            }
        }

        if (!$found_slot) {
            $conn->rollback();
            respond(['error' => 'No available slots in this lot'], 409);
        }
    }


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
        (registration_number, slot_id, lot_id, in_time, fee_id) 
        VALUES (?, ?, ?, ?, ?)
    ");
    $insert_stmt->bind_param("siisi", $plate, $slot_id, $lot_id, $in_time, $fee_id);
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

    $response = [
        'plate'        => $plate,
        'type'         => $vehicle_type,
        'slot'         => $slot_no,
        'lot'          => $lot_id,
        'entry_type'   => $entry_type,
        'early_walkin' => $early_walkin,
    ];
    if ($booking_id !== null) {
        $response['booking_id'] = $booking_id;
    }
    if ($early_walkin) {
        $response['cancelled_booking_id'] = $cancelled_booking_id;
        $response['refund_amount']         = $refund_amount;
    }
    if ($entry_type === 'walkin_on_booked' && $displaced_booking_id !== null) {
        $response['displaced_booking_id'] = $displaced_booking_id;
    }
    respond($response, 200);

} catch (Exception $e) {

    $conn->rollback();
    respond(['error' => 'Server error: ' . $e->getMessage()], 500);
}
