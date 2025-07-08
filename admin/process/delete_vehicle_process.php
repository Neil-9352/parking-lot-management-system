<?php
session_start();
require_once '../../config/db.php';
require_once '../../vendor/tecnickcom/tcpdf/tcpdf.php';

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['slot_id'])) {
    $slot_id = intval($_POST['slot_id']);
    $out_time = date("Y-m-d H:i:s");

    // Step 1: Fetch parked vehicle info and calculate fee in one query
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
        WHERE pi.slot_id = ? AND pi.out_time IS NULL
        ORDER BY f.created_at DESC
        LIMIT 1
    ";

    if ($stmt = $conn->prepare($fee_calc_query)) {
        // Bind current time 4 times + slot_id once
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
            die("No vehicle currently parked in slot $slot_id.");
        }

        // Begin transaction
        $conn->begin_transaction();

        try {
            // Update parks_in to set out_time, fee, and receipt_path (receipt path will be set after PDF generation)
            $update_parks_in = "UPDATE parks_in SET out_time = ?, fee = ? WHERE id = ?";
            $upd_parks_stmt = $conn->prepare($update_parks_in);
            $upd_parks_stmt->bind_param("sdi", $out_time, $parking_fee, $parks_in_id);
            $upd_parks_stmt->execute();
            $upd_parks_stmt->close();

            // Update parking_slot to mark unoccupied
            $update_slot = "UPDATE parking_slot SET status = 'unoccupied' WHERE slot_id = ?";
            $upd_slot_stmt = $conn->prepare($update_slot);
            $upd_slot_stmt->bind_param("i", $slot_id);
            $upd_slot_stmt->execute();
            $upd_slot_stmt->close();

            // Generate PDF receipt
            $receipts_dir = __DIR__ . "/../receipts";
            if (!is_dir($receipts_dir)) {
                mkdir($receipts_dir, 0777, true);
            }

            $pdf = new TCPDF();
            $pdf->AddPage();
            $pdf->SetFont('dejavusans', '', 12);
            $pdf->Cell(0, 10, "Parking Receipt", 0, 1, 'C');
            $pdf->Ln(5);
            $pdf->Cell(0, 10, "Vehicle Reg. No: $reg_number", 0, 1);
            $pdf->Cell(0, 10, "Vehicle Type: $vehicle_type", 0, 1);
            $pdf->Cell(0, 10, "In Time: $in_time", 0, 1);
            $pdf->Cell(0, 10, "Out Time: $out_time", 0, 1);
            $pdf->Cell(0, 10, "Total Minutes Parked: $minutes_parked", 0, 1);
            $pdf->Cell(0, 10, "Total Hours Parked (rounded): $hours_parked", 0, 1);
            $pdf->Cell(0, 10, "First Hour Charge: ₹ " . number_format($first_hour_charge, 2), 0, 1);
            $pdf->Cell(0, 10, "Subsequent Hour Charges: ₹ " . number_format($rest_hour_charge, 2), 0, 1);
            $pdf->Cell(0, 10, "Total Parking Fee: ₹ " . number_format($parking_fee, 2), 0, 1);

            $file_name = "receipt_{$reg_number}_" . time() . ".pdf";
            $file_path = $receipts_dir . "/" . $file_name;
            $pdf->Output($file_path, "F");

            // Update parks_in record with receipt path
            $receipt_db_path = "receipts/" . $file_name; // relative path to store in DB
            $upd_receipt_stmt = $conn->prepare("UPDATE parks_in SET receipt_path = ? WHERE id = ?");
            $upd_receipt_stmt->bind_param("si", $receipt_db_path, $parks_in_id);
            $upd_receipt_stmt->execute();
            $upd_receipt_stmt->close();

            // Commit transaction
            $conn->commit();

            // Redirect with success message and receipt link
            // header("Location: ../view_slots.php?success=Vehicle+removed+successfully!&receipt=$receipt_db_path");
            $_SESSION['receipt_success'] = "Vehicle removed successfully!";
            $_SESSION['receipt_path'] = $receipt_db_path;

            header("Location: ../view_slots.php");
            exit;
        } catch (Exception $e) {
            $conn->rollback();
            echo "Transaction failed: " . $e->getMessage();
        }
    } else {
        echo "Failed to prepare statement: " . $conn->error;
    }
} else {
    echo "Invalid request.";
}
