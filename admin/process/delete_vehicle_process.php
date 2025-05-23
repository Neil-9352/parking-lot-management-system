<?php
session_start();
require_once '../../config/db.php';
require_once '../../vendor/tecnickcom/tcpdf/tcpdf.php'; // Include TCPDF properly

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['slot_number'])) {
    $slot_number = intval($_POST['slot_number']);
    $out_time = date("Y-m-d H:i:s");

    // Step 1: Get vehicle info from parking_slots
    $query = "SELECT vehicle_reg_number, vehicle_type, in_time FROM parking_slots WHERE slot_number = ? AND status = 'occupied'";
    if ($stmt = $conn->prepare($query)) {
        $stmt->bind_param("i", $slot_number);
        $stmt->execute();
        $stmt->bind_result($reg_number, $vehicle_type, $in_time);
        $stmt->fetch();
        $stmt->close();

        if ($reg_number) {
            // Step 2: Get fees from fee table based on vehicle_type
            $fee_query = "SELECT first_hour_charge, next_hour_charge FROM fee WHERE vehicle_type = ?";
            if ($fee_stmt = $conn->prepare($fee_query)) {
                $fee_stmt->bind_param("s", $vehicle_type);
                $fee_stmt->execute();
                $fee_stmt->bind_result($first_hour_charge, $next_hour_charge);
                $fee_stmt->fetch();
                $fee_stmt->close();

                // Step 3: Calculate parking fee
                $in_timestamp = strtotime($in_time);
                $out_timestamp = strtotime($out_time);
                $hours_parked = ceil(($out_timestamp - $in_timestamp) / 3600);

                if ($hours_parked <= 1) {
                    $parking_fee = $first_hour_charge;
                } else {
                    $parking_fee = $first_hour_charge + (($hours_parked - 1) * $next_hour_charge);
                }

                // Step 4: Update parking_slots table (free the slot)
                $update_query = "UPDATE parking_slots SET status = 'unoccupied', vehicle_reg_number = NULL, vehicle_type = NULL, in_time = NULL, out_time = NULL WHERE slot_number = ?";
                if ($update_stmt = $conn->prepare($update_query)) {
                    $update_stmt->bind_param("i", $slot_number);
                    $update_stmt->execute();
                    $update_stmt->close();
                }

                // Step 5: Update parked_vehicles table (set out_time and fee)
                $update_parked_query = "UPDATE parked_vehicles SET out_time = ?, fee = ? WHERE reg_number = ? AND out_time IS NULL";
                if ($update_parked_stmt = $conn->prepare($update_parked_query)) {
                    $update_parked_stmt->bind_param("sis", $out_time, $parking_fee, $reg_number);
                    $update_parked_stmt->execute();
                    $update_parked_stmt->close();
                }

                // Step 6: Generate PDF receipt
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
                $pdf->Cell(0, 10, "Total Hours Parked: $hours_parked", 0, 1);
                $pdf->Cell(0, 10, "Total Fee: ₹ " . number_format($parking_fee, 2), 0, 1);

                $file_path = $receipts_dir . "/receipt_$reg_number.pdf";
                $pdf->Output($file_path, "F");

                // Redirect with success and receipt link
                header("Location: ../view_slots.php?success=Vehicle+removed+successfully!&receipt=receipts/receipt_$reg_number.pdf");
                exit;
            } else {
                echo "Error fetching fee details: " . $conn->error;
            }
        } else {
            echo "No occupied vehicle found for this slot.";
        }
    } else {
        echo "Error preparing statement: " . $conn->error;
    }
}
?>
