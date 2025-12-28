<?php
// auto_entry.php
session_start();
require_once '../config/db.php';

// Check admin session
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
    <title>Automatic Vehicle Entry</title>
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
                        <h4 class="mb-0">Automatic Vehicle Entry</h4>
                    </div>
                    <div class="card-body">
                        <p>Allow camera access, frame the number plate clearly, then click <strong>Capture</strong>.</p>

                        <div class="d-flex flex-column align-items-center">
                            <video id="video" autoplay playsinline></video>
                            <canvas id="canvas" style="display:none;"></canvas>

                            <div class="controls">
                                <button id="captureBtn" class="btn btn-primary">Capture</button>
                                <button id="retakeBtn" class="btn btn-secondary" style="display:none;">Retake</button>
                                <button id="submitBtn" class="btn btn-success" style="display:none;">Submit</button>
                                <a href="add_vehicle.php" class="btn btn-outline-danger">Manual Entry</a>
                                <span id="loader" class="text-muted">Processing…</span>
                            </div>
                        </div>

                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal to show plate/type/slot -->
    <div class="modal fade" id="resultModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Vehicle Parking Status</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close" id="modalCloseBtn"></button>
                </div>
                <div class="modal-body">
                    <p><strong>Plate:</strong> <span id="mPlate">—</span></p>
                    <p><strong>Type:</strong> <span id="mType">—</span></p>
                    <p><strong>Assigned Slot:</strong> <span id="mSlot">—</span></p>
                    <div id="mError" class="text-danger"></div>                    
                    <div id="mSuccess" class="text-success"></div>                    
                </div>
                <div class="modal-footer">
                    <button id="okBtn" type="button" class="btn btn-primary" data-bs-dismiss="modal">OK</button>
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

            const modalEl = document.getElementById('resultModal');
            const bsModal = new bootstrap.Modal(modalEl, {
                backdrop: 'static',
                keyboard: false
            });
            const mPlate = document.getElementById('mPlate');
            const mType = document.getElementById('mType');
            const mSlot = document.getElementById('mSlot');
            const mError = document.getElementById('mError');
            const mSuccess = document.getElementById('mSuccess');
            const modalCloseBtn = document.getElementById('modalCloseBtn');

            let stream = null;

            function showLoader(on = true) {
                loader.style.display = on ? 'inline' : 'none';
            }

            // Start camera
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
                mError.textContent = '';
            });

            retakeBtn.addEventListener('click', () => {
                canvas.style.display = 'none';
                video.style.display = 'block';
                captureBtn.style.display = 'inline-block';
                retakeBtn.style.display = 'none';
                submitBtn.style.display = 'none';
                mError.textContent = '';
            });

            // When the modal closes (OK), resume camera and reset UI
            modalEl.addEventListener('hidden.bs.modal', () => {
                // resume camera view for next vehicle
                canvas.style.display = 'none';
                video.style.display = 'block';
                captureBtn.style.display = 'inline-block';
                retakeBtn.style.display = 'none';
                submitBtn.style.display = 'none';
                mPlate.textContent = '—';
                mType.textContent = '—';
                mSlot.textContent = '—';
                mError.textContent = '';
            });

            // Submit: send image only to server process which calls recognizer server-side
            submitBtn.addEventListener('click', async () => {
                showLoader(true);
                mError.textContent = '';
                try {
                    const image_base64 = drawToCanvas();

                    const resp = await fetch('./process/auto_entry_process.php', {
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
                        // show error inside modal and open it
                        mError.textContent = (json && json.error) ? json.error : `Server returned ${resp.status}`;
                        mPlate.textContent = '—';
                        mType.textContent = '—';
                        mSlot.textContent = '—';
                        bsModal.show();
                        return;
                    }

                    // success: expect { plate, type, slot }
                    mSuccess.textContent = 'Vehicle parked successfully.';
                    mPlate.textContent = json.plate ?? '—';
                    mType.textContent = json.type ?? '—';
                    mSlot.textContent = json.slot ?? '—';
                    bsModal.show();

                    // Consider stopping the camera stream if you want to lock UI until operator clears modal.
                    // If you want to stop camera: stopStream();
                } catch (err) {
                    showLoader(false);
                    mError.textContent = 'Network or unexpected error: ' + err.message;
                    mPlate.textContent = '—';
                    mType.textContent = '—';
                    mSlot.textContent = '—';
                    bsModal.show();
                }
            });

            // Utility to stop camera (if ever needed)
            function stopStream() {
                if (stream) {
                    stream.getTracks().forEach(t => t.stop());
                    stream = null;
                }
            }

            // Ensure camera freed if user navigates away
            window.addEventListener('beforeunload', () => stopStream());

        })();
    </script>
</body>

</html>