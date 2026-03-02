<?php
// session_start();
// require_once '../../config/db.php';

// if ($_SERVER['REQUEST_METHOD'] == 'POST') {
//     $username = trim($_POST['username']);
//     $password = trim($_POST['password']);
//     $lot_id   = isset($_POST['lot_id']) ? intval($_POST['lot_id']) : 0;

//     // Use prepared statement
//     $sql = "SELECT * FROM admin WHERE username = ?";
//     $stmt = $conn->prepare($sql);
//     $stmt->bind_param("s", $username);
//     $stmt->execute();
//     $result = $stmt->get_result();

//     if ($result && $result->num_rows > 0) {
//         $admin = $result->fetch_assoc();

//         if (password_verify($password, $admin['password'])) {
//             $_SESSION['admin_logged_in'] = true;
//             header("Location: ../dashboard.php");
//             exit;
//         } else {
//             $_SESSION['login_error'] = "Invalid password.";
//             header("Location: ../../index.php");
//             exit;
//         }
//     } else {
//         $_SESSION['login_error'] = "Invalid username.";
//         header("Location: ../../index.php");
//         exit;
//     }

//     $stmt->close(); // Close prepared statement
// }
?>

<?php
session_start();
require_once '../../config/db.php';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {

    $username = trim($_POST['username']);
    $password = trim($_POST['password']);
    $lot_id   = isset($_POST['lot_id']) ? intval($_POST['lot_id']) : 0;

    // ✅ Validate lot selection
    if ($lot_id <= 0) {
        $_SESSION['login_error'] = "Please select a parking lot.";
        header("Location: ../../index.php");
        exit;
    }

    // ✅ Check if selected lot exists
    $lot_check = $conn->prepare("SELECT lot_id FROM parking_lot WHERE lot_id = ?");
    $lot_check->bind_param("i", $lot_id);
    $lot_check->execute();
    $lot_result = $lot_check->get_result();

    if ($lot_result->num_rows === 0) {
        $_SESSION['login_error'] = "Invalid parking lot selected.";
        header("Location: ../../index.php");
        exit;
    }

    $lot_check->close();

    // ✅ Authenticate admin
    $sql = "SELECT * FROM admin WHERE username = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result && $result->num_rows > 0) {
        $admin = $result->fetch_assoc();

        if (password_verify($password, $admin['password'])) {

            $_SESSION['admin_logged_in'] = true;
            $_SESSION['admin_id'] = $admin['id'];
            $_SESSION['lot_id'] = $lot_id;  // 🔥 Store selected lot

            header("Location: ../dashboard.php");
            exit;

        } else {
            $_SESSION['login_error'] = "Invalid password.";
            header("Location: ../../index.php");
            exit;
        }
    } else {
        $_SESSION['login_error'] = "Invalid username.";
        header("Location: ../../index.php");
        exit;
    }

    $stmt->close();
}
