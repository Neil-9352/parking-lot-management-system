<?php
session_start();
require_once '../../config/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: ../../register.php");
    exit;
}

// Collect and trim inputs
$lot_name         = trim($_POST['lot_name'] ?? '');
$address          = trim($_POST['address'] ?? '');
$username         = trim($_POST['username'] ?? '');
$password         = trim($_POST['password'] ?? '');
$confirm_password = trim($_POST['confirm_password'] ?? '');
$total_slots      = intval($_POST['total_slots'] ?? 0);
$fee_2w_first     = $_POST['fee_2w_first'] ?? '';
$fee_2w_next      = $_POST['fee_2w_next'] ?? '';
$fee_4w_first     = $_POST['fee_4w_first'] ?? '';
$fee_4w_next      = $_POST['fee_4w_next'] ?? '';

// Store old values for repopulating form on error
$_SESSION['register_old'] = [
    'lot_name'     => $lot_name,
    'address'      => $address,
    'username'     => $username,
    'total_slots'  => $total_slots,
    'fee_2w_first' => $fee_2w_first,
    'fee_2w_next'  => $fee_2w_next,
    'fee_4w_first' => $fee_4w_first,
    'fee_4w_next'  => $fee_4w_next,
];

// --- Validation ---

if (empty($lot_name) || empty($address) || empty($username) || empty($password) || $total_slots < 1) {
    $_SESSION['register_error'] = "All required fields must be filled.";
    header("Location: ../../register.php");
    exit;
}

if ($fee_2w_first === '' || $fee_2w_next === '' || $fee_4w_first === '' || $fee_4w_next === '') {
    $_SESSION['register_error'] = "All parking fee fields are required.";
    header("Location: ../../register.php");
    exit;
}

$fee_2w_first = floatval($fee_2w_first);
$fee_2w_next  = floatval($fee_2w_next);
$fee_4w_first = floatval($fee_4w_first);
$fee_4w_next  = floatval($fee_4w_next);

if ($fee_2w_first < 0 || $fee_2w_next < 0 || $fee_4w_first < 0 || $fee_4w_next < 0) {
    $_SESSION['register_error'] = "Fee values cannot be negative.";
    header("Location: ../../register.php");
    exit;
}

if ($total_slots < 1 || $total_slots > 1000) {
    $_SESSION['register_error'] = "Number of parking slots must be between 1 and 1000.";
    header("Location: ../../register.php");
    exit;
}

if (strlen($lot_name) > 100) {
    $_SESSION['register_error'] = "Parking lot name must be at most 100 characters.";
    header("Location: ../../register.php");
    exit;
}

if (strlen($address) > 255) {
    $_SESSION['register_error'] = "Address must be at most 255 characters.";
    header("Location: ../../register.php");
    exit;
}

if (strlen($username) > 50) {
    $_SESSION['register_error'] = "Username must be at most 50 characters.";
    header("Location: ../../register.php");
    exit;
}

if (strlen($password) < 6) {
    $_SESSION['register_error'] = "Password must be at least 6 characters.";
    header("Location: ../../register.php");
    exit;
}

if ($password !== $confirm_password) {
    $_SESSION['register_error'] = "Passwords do not match.";
    header("Location: ../../register.php");
    exit;
}

// Check username uniqueness
$stmt = $conn->prepare("SELECT id FROM admin WHERE username = ?");
$stmt->bind_param("s", $username);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows > 0) {
    $_SESSION['register_error'] = "Username is already taken. Please choose another.";
    header("Location: ../../register.php");
    exit;
}
$stmt->close();

// --- Handle layout image upload ---
$layout_image_path = null;

if (isset($_FILES['layout_image']) && $_FILES['layout_image']['error'] === UPLOAD_ERR_OK) {
    $allowed_types = ['image/png', 'image/jpeg', 'image/jpg', 'image/gif', 'image/webp'];
    $file_type = mime_content_type($_FILES['layout_image']['tmp_name']);

    if (!in_array($file_type, $allowed_types)) {
        $_SESSION['register_error'] = "Invalid image format. Allowed: PNG, JPG, GIF, WebP.";
        header("Location: ../../register.php");
        exit;
    }

    // We'll determine the final filename after we get the lot_id
    // For now, just validate. We'll move the file after insert.
}

// --- Begin Transaction ---
$conn->begin_transaction();

try {
    // 1. Insert into parking_lot
    $stmt = $conn->prepare("INSERT INTO parking_lot (lot_name, address, layout_image_path) VALUES (?, ?, ?)");
    $stmt->bind_param("sss", $lot_name, $address, $layout_image_path);
    $stmt->execute();
    $lot_id = $conn->insert_id;
    $stmt->close();

    // 2. Handle file upload with lot_id in filename
    if (isset($_FILES['layout_image']) && $_FILES['layout_image']['error'] === UPLOAD_ERR_OK) {
        $ext = pathinfo($_FILES['layout_image']['name'], PATHINFO_EXTENSION);
        $filename = "Lot_{$lot_id}_layout." . $ext;
        $upload_dir = __DIR__ . '/../uploads/layouts/';

        // Create uploads directory if it doesn't exist
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0755, true);
        }

        $destination = $upload_dir . $filename;

        if (move_uploaded_file($_FILES['layout_image']['tmp_name'], $destination)) {
            $layout_image_path = "admin/uploads/layouts/" . $filename;

            // Update the parking_lot row with the image path
            $stmt = $conn->prepare("UPDATE parking_lot SET layout_image_path = ? WHERE lot_id = ?");
            $stmt->bind_param("si", $layout_image_path, $lot_id);
            $stmt->execute();
            $stmt->close();
        }
    }

    // 3. Insert parking slots
    $slot_stmt = $conn->prepare("INSERT INTO parking_slot (slot_no, status, lot_id) VALUES (?, 'unoccupied', ?)");
    for ($i = 1; $i <= $total_slots; $i++) {
        $slot_stmt->bind_param("ii", $i, $lot_id);
        $slot_stmt->execute();
    }
    $slot_stmt->close();

    // 4. Insert fee records (2-wheeler and 4-wheeler)
    $fee_stmt = $conn->prepare(
        "INSERT INTO fee (lot_id, vehicle_type, first_hour_charge, rest_hour_charge) VALUES (?, ?, ?, ?)"
    );
    $fees = [
        ['2-wheeler', $fee_2w_first, $fee_2w_next],
        ['4-wheeler', $fee_4w_first, $fee_4w_next],
    ];
    foreach ($fees as [$type, $first, $next]) {
        $fee_stmt->bind_param("isdd", $lot_id, $type, $first, $next);
        $fee_stmt->execute();
    }
    $fee_stmt->close();

    // 5. Insert admin with hashed password
    $hashed_password = password_hash($password, PASSWORD_DEFAULT);

    $stmt = $conn->prepare("INSERT INTO admin (username, password, lot_id) VALUES (?, ?, ?)");
    $stmt->bind_param("ssi", $username, $hashed_password, $lot_id);
    $stmt->execute();
    $stmt->close();

    // Commit transaction
    $conn->commit();

    // Clear old form data
    unset($_SESSION['register_old']);

    $_SESSION['register_success'] = "Parking lot registered successfully! Please log in.";
    header("Location: ../../index.php");
    exit;

} catch (Exception $e) {
    $conn->rollback();

    // Clean up uploaded file if it exists
    if (isset($destination) && file_exists($destination)) {
        unlink($destination);
    }

    $_SESSION['register_error'] = "Registration failed. Please try again. Error: " . $e->getMessage();
    header("Location: ../../register.php");
    exit;
}
