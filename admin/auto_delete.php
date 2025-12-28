<?php
// auto_delete.php
session_start();
require_once '../config/db.php';
if (!isset($_SESSION['admin_logged_in'])) {
    header("Location: ../index.php");
    exit;
}
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width,initial-scale=1" />
    <title>Automatic Vehicle Removal</title>
    <link rel="stylesheet" href="../bootstrap-5.3.6/css/bootstrap.css">
    <script src="../bootstrap-5.3.6/js/bootstrap.bundle.js"></script>
    <style>
        video,
        canvas {
            width: 100%;
            max-width: 640px;
            border-radius: 8px;
            box-shadow: 0 4px 14px rgba(0, 0, 0, 0.08);
        }

        .controls {
            margin-top: .75rem;
            display: flex;
            gap: .5rem;
            flex-wrap: wrap;
            align-items: center;
        }

        #loader {
            display: none;
            margin-left: .5rem;
        }
    </style>
</head>

<body class="bg-light">
    <div class="container-fluid">
        <div class="row">
            <div class="col-md-3 col-lg-2 bg-dark min-vh-100 p-0">
                <?php include '../includes/sidebar.php'; ?>
            </div>

            <div class="col-md-9 col-lg-10 py-4">
                <div class="card shadow mx-3">
                    <div class="card-header bg-primary text-white">
                        <h4 class="mb-0">Automatic Vehicle Removal</h4>
                    </div>
                    <div class="card-body">
                        <p>Allow camera access, frame the plate clearly, then click <strong>Capture</strong>.</p>

                        <div class="d-flex flex-column align-items-center">
                            <video id="video" autoplay playsinline></video>
                            <canvas id="canvas" style="display:none;"></canvas>

                            <div class="controls">
                                <button id="captureBtn" class="btn btn-primary">Capture</button>
                                <button id="retakeBtn" class="btn btn-secondary" style="display:none;">Retake</button>
                                <button id="submitBtn" class="btn btn-danger" style="display:none;">Remove Vehicle</button>
                                <a href="view_slots.php" class="btn btn-outline-secondary">View Slots</a>
                                <span id="loader" class="text-muted">Processing…</span>
                            </div>

                            <!-- result card -->
                            <div id="resultCard" class="card w-100 mt-3" style="display:none;">
                                <div class="card-body">
                                    <h5>Recognition</h5>
                                    <p><strong>Plate:</strong> <span id="plateText">—</span></p>
                                    <p><strong>Detected Type:</strong> <span id="typeText">—</span></p>
                                    <p id="matchMsg" class="text-warning"></p>
                                    <div id="errorMsg" class="text-danger"></div>
                                </div>
                            </div>

                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal to show removal confirmation + receipt link -->
    <div class="modal fade" id="removeModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Vehicle Removed</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close" id="modalCloseBtn"></button>
                </div>
                <div class="modal-body">
                    <p><strong>Plate:</strong> <span id="mPlate">—</span></p>
                    <p><strong>Type:</strong> <span id="mType">—</span></p>
                    <p><strong>Slot:</strong> <span id="mSlot">—</span></p>
                    <p><strong>Duration (hours):</strong> <span id="mDuration">—</span></p>
                    <p><strong>Charge:</strong> ₹ <span id="mCharge">—</span></p>
                    <div id="mError" class="text-danger"></div>
                </div>
                <div class="modal-footer">
                    <a id="mReceiptLink" class="btn btn-primary" target="_blank" rel="noopener noreferrer" href="#">Download Receipt</a>
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <script>
        (async function() {
            const video = document.getElementById('video');
            const canvas = document.getElementById('canvas');
            const captureBtn = document.getElementById('captureBtn');
            const retakeBtn = document.getElementById('retakeBtn');
            const submitBtn = document.getElementById('submitBtn');
            const loader = document.getElementById('loader');

            const resultCard = document.getElementById('resultCard');
            const plateText = document.getElementById('plateText');
            const typeText = document.getElementById('typeText');
            const matchMsg = document.getElementById('matchMsg');
            const errorMsg = document.getElementById('errorMsg');

            const modalEl = document.getElementById('removeModal');
            const bsModal = new bootstrap.Modal(modalEl, {
                backdrop: 'static',
                keyboard: false
            });
            const mPlate = document.getElementById('mPlate');
            const mType = document.getElementById('mType');
            const mSlot = document.getElementById('mSlot');
            const mDuration = document.getElementById('mDuration');
            const mCharge = document.getElementById('mCharge');
            const mReceiptLink = document.getElementById('mReceiptLink');
            const mError = document.getElementById('mError');

            let stream = null;

            function showLoader(on = true) {
                loader.style.display = on ? 'inline' : 'none';
            }

            // start camera
            try {
                stream = await navigator.mediaDevices.getUserMedia({
                    video: {
                        facingMode: "environment"
                    },
                    audio: false
                });
                video.srcObject = stream;
            } catch (e) {
                alert('Camera access failed: ' + e.message);
                console.error(e);
                return;
            }

            function drawToCanvas() {
                canvas.width = video.videoWidth || 640;
                canvas.height = video.videoHeight || 480;
                const ctx = canvas.getContext('2d');
                ctx.drawImage(video, 0, 0, canvas.width, canvas.height);
                return canvas.toDataURL('image/jpeg', 0.8);
            }

            captureBtn.addEventListener('click', () => {
                drawToCanvas();
                canvas.style.display = 'block';
                video.style.display = 'none';
                captureBtn.style.display = 'none';
                retakeBtn.style.display = 'inline-block';
                submitBtn.style.display = 'inline-block';
                resultCard.style.display = 'none';
                errorMsg.textContent = '';
                matchMsg.textContent = '';
            });

            retakeBtn.addEventListener('click', () => {
                canvas.style.display = 'none';
                video.style.display = 'block';
                captureBtn.style.display = 'inline-block';
                retakeBtn.style.display = 'none';
                submitBtn.style.display = 'none';
                resultCard.style.display = 'none';
                errorMsg.textContent = '';
                matchMsg.textContent = '';
            });

            // Main: submit image to server which will call recognizer and process deletion if match found
            submitBtn.addEventListener('click', async () => {
                showLoader(true);
                resultCard.style.display = 'none';
                errorMsg.textContent = '';
                matchMsg.textContent = '';

                const image_base64 = drawToCanvas();

                try {
                    const resp = await fetch('./process/auto_delete_process.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json'
                        },
                        body: JSON.stringify({
                            image_base64
                        })
                    });

                    const json = await resp.json().catch(() => null);
                    showLoader(false);

                    if (!resp.ok) {
                        // recognition semantic failure or server error
                        const err = (json && json.error) ? json.error : `Server returned ${resp.status}`;
                        errorMsg.textContent = err;
                        resultCard.style.display = 'block';
                        return;
                    }

                    // server returns different responses:
                    // { status: 'no_match', plate, type, message } -> show message, do not remove
                    // { status: 'removed', plate, type, slot, duration_hours, charge, receipt_path } -> show modal with receipt link
                    if (json.status === 'no_match') {
                        plateText.textContent = json.plate ?? '—';
                        typeText.textContent = json.type ?? '—';
                        matchMsg.textContent = json.message ?? 'No matching parked vehicle found (plate/type mismatch).';
                        resultCard.style.display = 'block';
                        return;
                    }

                    if (json.status === 'removed') {
                        // populate modal and show
                        mPlate.textContent = json.plate ?? '—';
                        mType.textContent = json.type ?? '—';
                        mSlot.textContent = json.slot ?? '—';
                        mDuration.textContent = json.duration_hours ?? '—';
                        mCharge.textContent = json.charge ?? '—';
                        if (json.receipt_path) {
                            mReceiptLink.href = json.receipt_path;
                            mReceiptLink.style.display = 'inline-block';
                        } else {
                            mReceiptLink.style.display = 'none';
                        }
                        mError.textContent = '';
                        bsModal.show();
                        return;
                    }

                    // fallback: show entire response
                    resultCard.style.display = 'block';
                    errorMsg.textContent = 'Unexpected response: ' + JSON.stringify(json);
                } catch (err) {
                    showLoader(false);
                    console.error(err);
                    errorMsg.textContent = 'Network or unexpected error: ' + err.message;
                    resultCard.style.display = 'block';
                }
            });

            // When modal hidden, reset UI for next operation
            modalEl.addEventListener('hidden.bs.modal', () => {
                canvas.style.display = 'none';
                video.style.display = 'block';
                captureBtn.style.display = 'inline-block';
                retakeBtn.style.display = 'none';
                submitBtn.style.display = 'none';
                resultCard.style.display = 'none';
                plateText.textContent = '—';
                typeText.textContent = '—';
                matchMsg.textContent = '';
                errorMsg.textContent = '';
            });

        })();
    </script>
</body>

</html>