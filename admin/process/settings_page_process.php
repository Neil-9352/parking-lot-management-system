<?php
session_start();
require_once '../../config/db.php';

if (!isset($_SESSION['admin_logged_in'])) {
    header("Location: ../../index.php");
    exit;
}

if (!isset($_SESSION['lot_id'])) {
    die("Lot not selected.");
}

$lot_id = intval($_SESSION['lot_id']);

error_reporting(E_ALL);
ini_set('display_errors', 1);

function flash($key, $value)
{
    $_SESSION['flash'][$key] = $value;
}

/* =====================================================
   FETCH MODE (Used when opening settings page)
===================================================== */
if (isset($_GET['fetch_only'])) {

    // Slot count for THIS LOT
    $stmt = $conn->prepare("SELECT COUNT(*) AS total FROM parking_slot WHERE lot_id = ?");
    $stmt->bind_param("i", $lot_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $current_slot_count = intval($res->fetch_assoc()['total']);

    // Lot details
    $stmt = $conn->prepare("SELECT lot_name, address, layout_image_path FROM parking_lot WHERE lot_id = ?");
    $stmt->bind_param("i", $lot_id);
    $stmt->execute();
    $lot_row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    // Fee data for THIS LOT
    $fee_data = [
        '2-wheeler' => ['first_hour' => 0, 'next_hour' => 0],
        '4-wheeler' => ['first_hour' => 0, 'next_hour' => 0]
    ];

    $stmt = $conn->prepare("SELECT * FROM fee WHERE lot_id = ?");
    $stmt->bind_param("i", $lot_id);
    $stmt->execute();
    $res = $stmt->get_result();

    while ($row = $res->fetch_assoc()) {
        $fee_data[$row['vehicle_type']] = [
            'first_hour' => $row['first_hour_charge'],
            'next_hour' => $row['rest_hour_charge']
        ];
    }

    $_SESSION['admin_data'] = [
        'slot_count'   => $current_slot_count,
        'fees'         => $fee_data,
        'lot_name'     => $lot_row['lot_name'] ?? '',
        'address'      => $lot_row['address'] ?? '',
        'layout_image' => $lot_row['layout_image_path'] ?? ''
    ];

    header("Location: ../settings_page.php");
    exit;
}

/* =====================================================
   PASSWORD CHANGE
===================================================== */
if (isset($_POST['change_password'])) {

    $new_password = trim($_POST['new_password']);
    $confirm_password = trim($_POST['confirm_password']);

    if ($new_password !== $confirm_password) {
        flash('password_error', 'Passwords do not match.');
    } elseif (strlen($new_password) < 6) {
        flash('password_error', 'Password must be at least 6 characters.');
    } else {

        $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);

        $stmt = $conn->prepare("UPDATE admin SET password = ? WHERE id = 1");
        $stmt->bind_param("s", $hashed_password);
        $stmt->execute();

        flash('password_success', 'Password updated successfully.');
    }

    header("Location: ../settings_page.php");
    exit;
}

/* =====================================================
   SLOT MANAGEMENT (LOT SPECIFIC)
===================================================== */
if (isset($_POST['sync_and_update_slots'])) {

    $total_slots = intval($_POST['total_slots']);

    $stmt = $conn->prepare("SELECT COUNT(*) AS total FROM parking_slot WHERE lot_id = ?");
    $stmt->bind_param("i", $lot_id);
    $stmt->execute();
    $res = $stmt->get_result();

    $current_slot_count = intval($res->fetch_assoc()['total']);

    if ($total_slots < 1) {
        flash('slot_error', 'Total slots must be at least 1.');
    } else {

        if ($total_slots > $current_slot_count) {

            $slots_to_add = $total_slots - $current_slot_count;

            for ($i = 1; $i <= $slots_to_add; $i++) {

                $new_slot_number = $current_slot_count + $i;

                $stmt = $conn->prepare("
                    INSERT INTO parking_slot (slot_no, status, lot_id)
                    VALUES (?, 'unoccupied', ?)
                ");

                $stmt->bind_param("ii", $new_slot_number, $lot_id);
                $stmt->execute();
            }

            flash('slot_success', "$slots_to_add new slots added for this lot.");

        } elseif ($total_slots < $current_slot_count) {

            $slots_to_remove = $current_slot_count - $total_slots;

            $stmt = $conn->prepare("
                DELETE FROM parking_slot
                WHERE lot_id = ?
                ORDER BY slot_no DESC
                LIMIT $slots_to_remove
            ");

            $stmt->bind_param("i", $lot_id);
            $stmt->execute();

            flash('slot_success', "$slots_to_remove slots removed for this lot.");

        } else {

            flash('slot_success', "Slot count is already correct.");
        }
    }

    header("Location: ../settings_page.php");
    exit;
}

/* =====================================================
   FEE UPDATE (LOT SPECIFIC)
===================================================== */
if (isset($_POST['update_fee'])) {

    $fees = [
        '2-wheeler' => [
            'first_hour' => floatval($_POST['fee_2w_first']),
            'next_hour' => floatval($_POST['fee_2w_next']),
        ],
        '4-wheeler' => [
            'first_hour' => floatval($_POST['fee_4w_first']),
            'next_hour' => floatval($_POST['fee_4w_next']),
        ]
    ];

    foreach ($fees as $type => $data) {

        $stmt = $conn->prepare("
            INSERT INTO fee (lot_id, vehicle_type, first_hour_charge, rest_hour_charge)
            VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                first_hour_charge = VALUES(first_hour_charge),
                rest_hour_charge = VALUES(rest_hour_charge)
        ");

        $stmt->bind_param(
            "isdd",
            $lot_id,
            $type,
            $data['first_hour'],
            $data['next_hour']
        );

        $stmt->execute();
    }

    flash('fee_success', 'Fee settings updated successfully.');
    header("Location: ../settings_page.php");
    exit;
}

/* =====================================================
   UPDATE LOT DETAILS
===================================================== */
if (isset($_POST['update_lot_details'])) {

    $lot_name = trim($_POST['lot_name'] ?? '');
    $address  = trim($_POST['address'] ?? '');

    if (empty($lot_name) || empty($address)) {
        flash('lot_error', 'Lot name and address are required.');
        header("Location: ../settings_page.php");
        exit;
    }

    // Handle optional new layout image
    $new_image_path = null;
    if (isset($_FILES['layout_image']) && $_FILES['layout_image']['error'] === UPLOAD_ERR_OK) {
        $allowed_types = ['image/png', 'image/jpeg', 'image/jpg', 'image/gif', 'image/webp'];
        $file_type = mime_content_type($_FILES['layout_image']['tmp_name']);

        if (!in_array($file_type, $allowed_types)) {
            flash('lot_error', 'Invalid image format. Allowed: PNG, JPG, GIF, WebP.');
            header("Location: ../settings_page.php");
            exit;
        }

        $ext = pathinfo($_FILES['layout_image']['name'], PATHINFO_EXTENSION);
        $filename = "Lot_{$lot_id}_layout." . $ext;
        $upload_dir = __DIR__ . '/../uploads/layouts/';
        if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
        $destination = $upload_dir . $filename;

        if (move_uploaded_file($_FILES['layout_image']['tmp_name'], $destination)) {
            $new_image_path = "admin/uploads/layouts/" . $filename;
        } else {
            flash('lot_error', 'Failed to upload image.');
            header("Location: ../settings_page.php");
            exit;
        }
    }

    if ($new_image_path !== null) {
        $stmt = $conn->prepare("UPDATE parking_lot SET lot_name = ?, address = ?, layout_image_path = ? WHERE lot_id = ?");
        $stmt->bind_param("sssi", $lot_name, $address, $new_image_path, $lot_id);
    } else {
        $stmt = $conn->prepare("UPDATE parking_lot SET lot_name = ?, address = ? WHERE lot_id = ?");
        $stmt->bind_param("ssi", $lot_name, $address, $lot_id);
    }
    $stmt->execute();
    $stmt->close();

    flash('lot_success', 'Parking lot details updated successfully.');
    header("Location: ../settings_page.php");
    exit;
}

/* =====================================================
   DEFAULT FETCH (Safety fallback)
===================================================== */

$stmt = $conn->prepare("SELECT COUNT(*) AS total FROM parking_slot WHERE lot_id = ?");
$stmt->bind_param("i", $lot_id);
$stmt->execute();
$res = $stmt->get_result();
$current_slot_count = intval($res->fetch_assoc()['total']);

$stmt = $conn->prepare("SELECT lot_name, address, layout_image_path FROM parking_lot WHERE lot_id = ?");
$stmt->bind_param("i", $lot_id);
$stmt->execute();
$lot_row = $stmt->get_result()->fetch_assoc();
$stmt->close();

$fee_data = [
    '2-wheeler' => ['first_hour' => 0, 'next_hour' => 0],
    '4-wheeler' => ['first_hour' => 0, 'next_hour' => 0]
];

$stmt = $conn->prepare("SELECT * FROM fee WHERE lot_id = ?");
$stmt->bind_param("i", $lot_id);
$stmt->execute();
$res = $stmt->get_result();

while ($row = $res->fetch_assoc()) {
    $fee_data[$row['vehicle_type']] = [
        'first_hour' => $row['first_hour_charge'],
        'next_hour' => $row['rest_hour_charge']
    ];
}

$_SESSION['admin_data'] = [
    'slot_count'   => $current_slot_count,
    'fees'         => $fee_data,
    'lot_name'     => $lot_row['lot_name'] ?? '',
    'address'      => $lot_row['address'] ?? '',
    'layout_image' => $lot_row['layout_image_path'] ?? ''
];

header("Location: ../settings_page.php");
exit;
