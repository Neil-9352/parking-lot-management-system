<?php
session_start();

// If already logged in, redirect to dashboard
if (isset($_SESSION['admin_logged_in'])) {
    header("Location: admin/dashboard.php");
    exit;
}

// Flash messages from register_process.php
$success = $_SESSION['register_success'] ?? null;
$error   = $_SESSION['register_error'] ?? null;
$old     = $_SESSION['register_old'] ?? [];
unset($_SESSION['register_success'], $_SESSION['register_error'], $_SESSION['register_old']);
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="bootstrap-5.3.6/css/bootstrap.css">
    <script src="bootstrap-5.3.6/js/bootstrap.bundle.js"></script>
    <title>Register New Parking Lot</title>
    <style>
        .step-indicator {
            display: flex;
            justify-content: center;
            gap: 0.5rem;
            margin-bottom: 1.5rem;
        }
        .step-indicator .step {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            font-size: 0.9rem;
            border: 2px solid #dee2e6;
            color: #6c757d;
            background: #fff;
            transition: all 0.3s ease;
        }
        .step-indicator .step.active {
            border-color: #0d6efd;
            background: #0d6efd;
            color: #fff;
        }
        .step-indicator .step.completed {
            border-color: #198754;
            background: #198754;
            color: #fff;
        }
        .step-connector {
            width: 40px;
            height: 2px;
            background: #dee2e6;
            align-self: center;
            transition: background 0.3s ease;
        }
        .step-connector.completed {
            background: #198754;
        }
    </style>
</head>

<body>
    <nav class="navbar navbar-dark bg-primary">
        <div class="container-fluid">
            <span class="navbar-brand mb-0 h1">Parking Information Management System</span>
        </div>
    </nav>

    <div class="container d-flex align-items-center justify-content-center" style="min-height: 90vh;">
        <div class="card shadow-sm p-4" style="width: 100%; max-width: 500px;">
            <div class="card-body">
                <h4 class="card-title text-center text-primary mb-3">Register New Parking Lot</h4>

                <!-- Step Indicator -->
                <div class="step-indicator">
                    <div class="step active" id="step-dot-1">1</div>
                    <div class="step-connector" id="step-conn-1"></div>
                    <div class="step" id="step-dot-2">2</div>
                </div>
                <p class="text-center text-muted mb-4" id="step-label">Step 1: Parking Lot Details</p>

                <?php if ($error): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <?= htmlspecialchars($error) ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>

                <form method="POST" action="admin/process/register_process.php" enctype="multipart/form-data" id="registerForm" class="needs-validation" novalidate>

                    <!-- Step 1: Parking Lot Details -->
                    <div id="step-1">
                        <div class="mb-3">
                            <label for="lot_name" class="form-label">Parking Lot Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="lot_name" name="lot_name" 
                                   value="<?= htmlspecialchars($old['lot_name'] ?? '') ?>" 
                                   placeholder="e.g. City Center Parking" required maxlength="100">
                            <div class="invalid-feedback">Please enter a parking lot name.</div>
                        </div>

                        <div class="mb-3">
                            <label for="address" class="form-label">Address <span class="text-danger">*</span></label>
                            <textarea class="form-control" id="address" name="address" rows="2" 
                                      placeholder="e.g. 123 Main Street, Downtown" required maxlength="255"><?= htmlspecialchars($old['address'] ?? '') ?></textarea>
                            <div class="invalid-feedback">Please enter an address.</div>
                        </div>

                        <div class="mb-3">
                            <label for="layout_image" class="form-label">Layout Image <span class="text-muted">(optional)</span></label>
                            <input type="file" class="form-control" id="layout_image" name="layout_image" accept="image/*">
                            <div class="form-text">Upload a layout image of your parking lot (PNG, JPG).</div>
                        </div>

                        <div class="mb-3">
                            <label for="total_slots" class="form-label">Number of Parking Slots <span class="text-danger">*</span></label>
                            <input type="number" class="form-control" id="total_slots" name="total_slots"
                                   value="<?= htmlspecialchars($old['total_slots'] ?? '') ?>"
                                   placeholder="e.g. 20" min="1" max="1000" required>
                            <div class="invalid-feedback">Please enter the number of parking slots (min 1).</div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-semibold">Parking Fee Settings <span class="text-danger">*</span></label>
                            <div class="border rounded p-3 bg-light">
                                <p class="fw-medium mb-2">🏍️ 2-Wheeler</p>
                                <div class="row g-2 mb-3">
                                    <div class="col-6">
                                        <label for="fee_2w_first" class="form-label small">First Hour (₹)</label>
                                        <input type="number" class="form-control" id="fee_2w_first" name="fee_2w_first"
                                               value="<?= htmlspecialchars($old['fee_2w_first'] ?? '') ?>"
                                               placeholder="e.g. 30" min="0" step="0.01" required>
                                        <div class="invalid-feedback">Required.</div>
                                    </div>
                                    <div class="col-6">
                                        <label for="fee_2w_next" class="form-label small">Subsequent Hours (₹)</label>
                                        <input type="number" class="form-control" id="fee_2w_next" name="fee_2w_next"
                                               value="<?= htmlspecialchars($old['fee_2w_next'] ?? '') ?>"
                                               placeholder="e.g. 15" min="0" step="0.01" required>
                                        <div class="invalid-feedback">Required.</div>
                                    </div>
                                </div>
                                <p class="fw-medium mb-2">🚗 4-Wheeler</p>
                                <div class="row g-2">
                                    <div class="col-6">
                                        <label for="fee_4w_first" class="form-label small">First Hour (₹)</label>
                                        <input type="number" class="form-control" id="fee_4w_first" name="fee_4w_first"
                                               value="<?= htmlspecialchars($old['fee_4w_first'] ?? '') ?>"
                                               placeholder="e.g. 50" min="0" step="0.01" required>
                                        <div class="invalid-feedback">Required.</div>
                                    </div>
                                    <div class="col-6">
                                        <label for="fee_4w_next" class="form-label small">Subsequent Hours (₹)</label>
                                        <input type="number" class="form-control" id="fee_4w_next" name="fee_4w_next"
                                               value="<?= htmlspecialchars($old['fee_4w_next'] ?? '') ?>"
                                               placeholder="e.g. 25" min="0" step="0.01" required>
                                        <div class="invalid-feedback">Required.</div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="d-grid">
                            <button type="button" class="btn btn-primary" id="nextBtn" onclick="goToStep2()">
                                Next &rarr;
                            </button>
                        </div>
                    </div>

                    <!-- Step 2: Admin Credentials -->
                    <div id="step-2" style="display: none;">
                        <div class="mb-3">
                            <label for="username" class="form-label">Admin Username <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="username" name="username" 
                                   value="<?= htmlspecialchars($old['username'] ?? '') ?>"
                                   placeholder="Choose a username" required maxlength="50">
                            <div class="invalid-feedback">Please choose a username.</div>
                        </div>

                        <div class="mb-3">
                            <label for="password" class="form-label">Password <span class="text-danger">*</span></label>
                            <input type="password" class="form-control" id="password" name="password" 
                                   placeholder="Minimum 6 characters" required minlength="6">
                            <div class="invalid-feedback">Password must be at least 6 characters.</div>
                        </div>

                        <div class="mb-3">
                            <label for="confirm_password" class="form-label">Confirm Password <span class="text-danger">*</span></label>
                            <input type="password" class="form-control" id="confirm_password" name="confirm_password" 
                                   placeholder="Re-enter password" required minlength="6">
                            <div class="invalid-feedback" id="confirm-feedback">Please confirm your password.</div>
                        </div>

                        <div class="d-flex gap-2">
                            <button type="button" class="btn btn-outline-secondary flex-fill" onclick="goToStep1()">
                                &larr; Back
                            </button>
                            <button type="submit" class="btn btn-primary flex-fill" id="submitBtn">
                                Register
                            </button>
                        </div>
                    </div>
                </form>

                <div class="text-center mt-3">
                    <small>Already registered? <a href="index.php">Login here</a></small>
                </div>
            </div>
        </div>
    </div>

    <script>
        function goToStep2() {
            const lotName    = document.getElementById('lot_name');
            const address    = document.getElementById('address');
            const totalSlots = document.getElementById('total_slots');
            const feeFields  = ['fee_2w_first','fee_2w_next','fee_4w_first','fee_4w_next'];

            // Validate step 1 fields
            let valid = true;
            if (!lotName.value.trim()) {
                lotName.classList.add('is-invalid'); valid = false;
            } else { lotName.classList.remove('is-invalid'); }

            if (!address.value.trim()) {
                address.classList.add('is-invalid'); valid = false;
            } else { address.classList.remove('is-invalid'); }

            const slotVal = parseInt(totalSlots.value, 10);
            if (!totalSlots.value || slotVal < 1 || slotVal > 1000) {
                totalSlots.classList.add('is-invalid'); valid = false;
            } else { totalSlots.classList.remove('is-invalid'); }

            feeFields.forEach(id => {
                const el = document.getElementById(id);
                if (el.value === '' || parseFloat(el.value) < 0) {
                    el.classList.add('is-invalid'); valid = false;
                } else { el.classList.remove('is-invalid'); }
            });

            if (!valid) return;

            document.getElementById('step-1').style.display = 'none';
            document.getElementById('step-2').style.display = 'block';

            // Update step indicators
            document.getElementById('step-dot-1').classList.remove('active');
            document.getElementById('step-dot-1').classList.add('completed');
            document.getElementById('step-dot-1').innerHTML = '&#10003;';
            document.getElementById('step-conn-1').classList.add('completed');
            document.getElementById('step-dot-2').classList.add('active');
            document.getElementById('step-label').textContent = 'Step 2: Admin Credentials';
        }

        function goToStep1() {
            document.getElementById('step-2').style.display = 'none';
            document.getElementById('step-1').style.display = 'block';

            // Update step indicators
            document.getElementById('step-dot-2').classList.remove('active');
            document.getElementById('step-dot-1').classList.remove('completed');
            document.getElementById('step-dot-1').classList.add('active');
            document.getElementById('step-dot-1').innerHTML = '1';
            document.getElementById('step-conn-1').classList.remove('completed');
            document.getElementById('step-label').textContent = 'Step 1: Parking Lot Details';
        }

        // Password match validation
        document.getElementById('registerForm').addEventListener('submit', function(e) {
            const password = document.getElementById('password').value;
            const confirm  = document.getElementById('confirm_password').value;

            if (password !== confirm) {
                e.preventDefault();
                document.getElementById('confirm_password').classList.add('is-invalid');
                document.getElementById('confirm-feedback').textContent = 'Passwords do not match.';
                return;
            }

            // Bootstrap validation
            if (!this.checkValidity()) {
                e.preventDefault();
                e.stopPropagation();
            }
            this.classList.add('was-validated');
        });
    </script>
</body>

</html>
