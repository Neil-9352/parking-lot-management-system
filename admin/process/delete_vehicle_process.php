<?php
session_start();
require_once '../../config/db.php';
require_once '../../vendor/tecnickcom/tcpdf/tcpdf.php';

// Validate login + lot
if (!isset($_SESSION['admin_logged_in']) || !isset($_SESSION['lot_id'])) {
    header("Location: ../../index.php");
    exit;
}

$lot_id = intval($_SESSION['lot_id']);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['slot_id'])) {

    $slot_id = intval($_POST['slot_id']);
    $out_time = date("Y-m-d H:i:s");

    // Begin transaction early for locking consistency
    $conn->begin_transaction();

    try {

        // 1️⃣ Validate slot belongs to this lot and lock it
        $stmt = $conn->prepare("
            SELECT slot_id 
            FROM parking_slot 
            WHERE slot_id = ? 
            AND lot_id = ?
            FOR UPDATE
        ");
        $stmt->bind_param("ii", $slot_id, $lot_id);
        $stmt->execute();
        $stmt->store_result();

        if ($stmt->num_rows === 0) {
            throw new Exception("Invalid slot for this parking lot.");
        }
        $stmt->close();

        // 2️⃣ Fetch active parking record + calculate fee
        $fee_calc_query = "
            SELECT 
                pi.id,
                pi.registration_number, 
                v.type, 
                pi.in_time,
                TIMESTAMPDIFF(MINUTE, pi.in_time, ?) AS minutes_parked,
                CEIL(TIMESTAMPDIFF(MINUTE, pi.in_time, ?) / 60) AS hours_parked,
                f.first_hour_charge, 
                f.rest_hour_charge,
                CASE 
                  WHEN CEIL(TIMESTAMPDIFF(MINUTE, pi.in_time, ?) / 60) <= 1 
                  THEN f.first_hour_charge
                  ELSE f.first_hour_charge + 
                       (CEIL(TIMESTAMPDIFF(MINUTE, pi.in_time, ?) / 60) - 1) * f.rest_hour_charge
                END AS parking_fee
            FROM parks_in pi
            JOIN vehicle v ON pi.registration_number = v.registration_number
            JOIN fee f ON pi.fee_id = f.fee_id
            WHERE pi.slot_id = ? 
            AND pi.out_time IS NULL
            LIMIT 1
        ";

        $stmt = $conn->prepare($fee_calc_query);
        $stmt->bind_param("ssssi", $out_time, $out_time, $out_time, $out_time, $slot_id);
        $stmt->execute();
        $stmt->bind_result(
            $parks_in_id,
            $reg_number,
            $vehicle_type,
            $in_time,
            $minutes_parked,
            $hours_parked,
            $first_hour_charge,
            $rest_hour_charge,
            $parking_fee
        );
        $stmt->fetch();
        $stmt->close();

        if (!$reg_number) {
            throw new Exception("No vehicle currently parked in this slot.");
        }

        // 3️⃣ Update parks_in (set out_time + fee)
        $stmt = $conn->prepare("
            UPDATE parks_in 
            SET out_time = ?, fee = ? 
            WHERE id = ?
        ");
        $stmt->bind_param("sdi", $out_time, $parking_fee, $parks_in_id);
        $stmt->execute();
        $stmt->close();

        // 4️⃣ Smart slot restoration
        // If an ACTIVE future booking still holds this slot, restore to 'booked'.
        // Otherwise restore to 'unoccupied'.
        $restore_check = $conn->prepare("
            SELECT 1
            FROM books
            WHERE slot_id = ?
            AND booking_status = 'ACTIVE'
            AND expected_end_time > NOW()
            LIMIT 1
        ");
        $restore_check->bind_param("i", $slot_id);
        $restore_check->execute();
        $restore_check->store_result();
        $restore_status = ($restore_check->num_rows > 0) ? 'booked' : 'unoccupied';
        $restore_check->close();

        $stmt = $conn->prepare("
            UPDATE parking_slot
            SET status = ?
            WHERE slot_id = ?
            AND lot_id = ?
        ");
        $stmt->bind_param("sii", $restore_status, $slot_id, $lot_id);
        $stmt->execute();
        $stmt->close();

        // 5️⃣ Generate PDF receipt
        $receipts_dir = __DIR__ . "/../receipts";
        if (!is_dir($receipts_dir)) {
            mkdir($receipts_dir, 0777, true);
        }

        $pdf = new TCPDF();
        $pdf->AddPage();
        $pdf->SetFont('dejavusans', '', 12);

        $pdf->Cell(0, 10, "Parking Receipt", 0, 1, 'C');
        $pdf->Ln(5);

        $pdf->Cell(0, 8, "Vehicle Reg. No: $reg_number", 0, 1);
        $pdf->Cell(0, 8, "Vehicle Type: $vehicle_type", 0, 1);
        $pdf->Cell(0, 8, "In Time: $in_time", 0, 1);
        $pdf->Cell(0, 8, "Out Time: $out_time", 0, 1);
        $pdf->Cell(0, 8, "Minutes Parked: $minutes_parked", 0, 1);
        $pdf->Cell(0, 8, "Hours Parked (rounded): $hours_parked", 0, 1);
        $pdf->Cell(0, 8, "First Hour: ₹ " . number_format($first_hour_charge, 2), 0, 1);
        $pdf->Cell(0, 8, "Next Hour: ₹ " . number_format($rest_hour_charge, 2), 0, 1);
        $pdf->Cell(0, 8, "Total Fee: ₹ " . number_format($parking_fee, 2), 0, 1);

        $file_name = "receipt_{$reg_number}_" . time() . ".pdf";
        $file_path = $receipts_dir . "/" . $file_name;
        $pdf->Output($file_path, "F");

        // 6️⃣ Save receipt path in DB
        $receipt_db_path = "receipts/" . $file_name;

        $stmt = $conn->prepare("
            UPDATE parks_in 
            SET receipt_path = ? 
            WHERE id = ?
        ");
        $stmt->bind_param("si", $receipt_db_path, $parks_in_id);
        $stmt->execute();
        $stmt->close();

        // Commit everything
        $conn->commit();

        $_SESSION['receipt_success'] = "Vehicle removed successfully!";
        $_SESSION['receipt_path'] = $receipt_db_path;

        header("Location: ../view_slots.php");
        exit;

    } catch (Exception $e) {

        $conn->rollback();

        $_SESSION['toast_error'] = "Transaction failed: " . $e->getMessage();
        header("Location: ../view_slots.php");
        exit;
    }

} else {
    echo "Invalid request.";
}
