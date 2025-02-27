<?php
session_start();
require_once '../../config/db.php';
require_once '../../vendor/tecnickcom/tcpdf/tcpdf.php'; // Include TCPDF properly

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['slot_number'])) {
    $slot_number = intval($_POST['slot_number']);
    $out_time = date("Y-m-d H:i:s");

    $query = "SELECT vehicle_reg_number, in_time FROM parking_slots WHERE slot_number = ? AND status = 'occupied'";
    if ($stmt = $conn->prepare($query)) {
        $stmt->bind_param("i", $slot_number);
        $stmt->execute();
        $stmt->bind_result($reg_number, $in_time);
        $stmt->fetch();
        $stmt->close();

        if ($reg_number) {
            // Calculate parking fee
            $in_timestamp = strtotime($in_time);
            $out_timestamp = strtotime($out_time);
            $hours_parked = ceil(($out_timestamp - $in_timestamp) / 3600);
            $parking_fee = ($hours_parked <= 1) ? 20 : (20 + (($hours_parked - 1) * 10));

            // Update slot
            $update_query = "UPDATE parking_slots SET status = 'unoccupied', vehicle_reg_number = NULL, vehicle_type = NULL, in_time = NULL, out_time = NULL WHERE slot_number = ?";
            if ($update_stmt = $conn->prepare($update_query)) {
                $update_stmt->bind_param("i", $slot_number);
                $update_stmt->execute();
                $update_stmt->close();
            }

            // Ensure the receipts folder exists
            $receipts_dir = __DIR__ . "/../receipts";
            if (!is_dir($receipts_dir)) {
                mkdir($receipts_dir, 0777, true);
            }

            // Generate PDF receipt
            $pdf = new TCPDF();
            $pdf->AddPage();
            $pdf->SetFont('dejavusans', '', 12);
            $pdf->Cell(0, 10, "Parking Receipt", 0, 1, 'C');
            $pdf->Ln(5);
            $pdf->Cell(0, 10, "Vehicle Reg. No: $reg_number", 0, 1);
            $pdf->Cell(0, 10, "In Time: $in_time", 0, 1);
            $pdf->Cell(0, 10, "Out Time: $out_time", 0, 1);
            $pdf->Cell(0, 10, "Total Hours Parked: $hours_parked", 0, 1);
            $pdf->Cell(0, 10, "Total Fee: ₹ $parking_fee", 0, 1);

            // Save PDF
            $file_path = $receipts_dir . "/receipt_$reg_number.pdf";
            $pdf->Output($file_path, "F");

            // Redirect with success message
            header("Location: ../view_slots.php?success=Vehicle+removed+successfully!&receipt=receipts/receipt_$reg_number.pdf");
            exit;
        } else {
            echo "No occupied vehicle found for this slot.";
        }
    } else {
        echo "Error preparing statement: " . $conn->error;
    }
}

?>
