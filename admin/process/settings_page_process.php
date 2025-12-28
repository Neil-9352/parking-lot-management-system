<?php
session_start();
require_once '../../config/db.php';

if (!isset($_SESSION['admin_logged_in'])) {
    header("Location: ../../index.php");
    exit;
}

// Helper to store flash messages/data in session
function flash($key, $value) {
    $_SESSION['flash'][$key] = $value;
}

// --- Fetch-only mode for first visit ---
if (isset($_GET['fetch_only'])) {
    // Fetch current slot count
    $current_slot_count = 0;
    $res = $conn->query("SELECT COUNT(*) AS total FROM parking_slot");
    if ($res) $current_slot_count = intval($res->fetch_assoc()['total']);

    // Fetch fees
    $fee_data = [
        '2-wheeler' => ['first_hour' => 0, 'next_hour' => 0],
        '4-wheeler' => ['first_hour' => 0, 'next_hour' => 0]
    ];
    $res = $conn->query("SELECT * FROM fee");
    while ($row = $res->fetch_assoc()) {
        $fee_data[$row['vehicle_type']] = [
            'first_hour' => $row['first_hour_charge'],
            'next_hour' => $row['rest_hour_charge']
        ];
    }

    $_SESSION['admin_data'] = [
        'slot_count' => $current_slot_count,
        'fees' => $fee_data
    ];

    header("Location: ../settings_page.php");
    exit;
}

// --- Password Change ---
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

// --- Slot Management ---
if (isset($_POST['sync_and_update_slots'])) {
    $total_slots = intval($_POST['total_slots']);

    // Fetch current count
    $current_slot_count = 0;
    $res = $conn->query("SELECT COUNT(*) AS total FROM parking_slot");
    if ($res) $current_slot_count = intval($res->fetch_assoc()['total']);

    if ($total_slots < 1) {
        flash('slot_error', 'Total slots must be at least 1.');
    } else {
        if ($total_slots > $current_slot_count) {
            $slots_to_add = $total_slots - $current_slot_count;
            for ($i = 1; $i <= $slots_to_add; $i++) {
                $new_slot_number = $current_slot_count + $i;
                $stmt = $conn->prepare("INSERT INTO parking_slot (slot_number, status) VALUES (?, 'unoccupied')");
                $stmt->bind_param("i", $new_slot_number);
                $stmt->execute();
            }
            flash('slot_success', "$slots_to_add new slots added.");
        } elseif ($total_slots < $current_slot_count) {
            $slots_to_remove = $current_slot_count - $total_slots;
            $conn->query("DELETE FROM parking_slot ORDER BY slot_number DESC LIMIT $slots_to_remove");
            flash('slot_success', "$slots_to_remove slots removed.");
        } else {
            flash('slot_success', "Slot count is already correct.");
        }
    }
    header("Location: ../settings_page.php");
    exit;
}

// --- Fee Update ---
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
            INSERT INTO fee (vehicle_type, first_hour_charge, rest_hour_charge)
            VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE 
                first_hour_charge = VALUES(first_hour_charge),
                rest_hour_charge = VALUES(rest_hour_charge)");
        $stmt->bind_param("sdd", $type, $data['first_hour'], $data['next_hour']);
        $stmt->execute();
    }
    flash('fee_success', 'Fee settings updated successfully.');
    header("Location: ../settings_page.php");
    exit;
}

// --- Default Fetch for Frontend (if reached directly) ---
$current_slot_count = 0;
$res = $conn->query("SELECT COUNT(*) AS total FROM parking_slot");
if ($res) $current_slot_count = intval($res->fetch_assoc()['total']);

// Fees
$fee_data = [
    '2-wheeler' => ['first_hour' => 0, 'next_hour' => 0],
    '4-wheeler' => ['first_hour' => 0, 'next_hour' => 0]
];
$res = $conn->query("SELECT * FROM fee");
while ($row = $res->fetch_assoc()) {
    $fee_data[$row['vehicle_type']] = [
        'first_hour' => $row['first_hour_charge'],
        'next_hour' => $row['rest_hour_charge']
    ];
}

// Store in session and redirect
$_SESSION['admin_data'] = [
    'slot_count' => $current_slot_count,
    'fees' => $fee_data
];

header("Location: ../settings_page.php");
exit;
?>