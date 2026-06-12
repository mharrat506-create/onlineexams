<?php
require_once __DIR__ . '/includes/config.php';
require_login();

$exam_id = isset($_GET['exam_id']) ? (int)$_GET['exam_id'] : 0;

$stmt = $pdo->prepare('
    SELECT exams.*, subjects.name AS subject_name
    FROM exams
    JOIN subjects ON exams.subject_id = subjects.id
    WHERE exams.id = ? AND exams.is_active = 1
    LIMIT 1
');
$stmt->execute([$exam_id]);
$exam = $stmt->fetch();

if (!$exam) {
    die('Invalid or inactive exam.');
}

// load questions for this subject
$limitClause = '';
if (!empty($exam['questions_per_exam']) && (int)$exam['questions_per_exam'] > 0) {
    $limitClause = ' LIMIT ' . (int)$exam['questions_per_exam'];
}
$order = !empty($exam['shuffle_questions']) ? 'ORDER BY RAND()' : 'ORDER BY id';
$qStmt = $pdo->prepare("SELECT * FROM questions WHERE subject_id = ? {$order}{$limitClause}");
$qStmt->execute([$exam['subject_id']]);
$questions = $qStmt->fetchAll();

if (!$questions) {
    die('No questions configured for this exam.');
}

// Remember exactly which questions were shown (for fair scoring on submit)
$_SESSION['exam_answers_scope_' . $exam_id] = array_map('intval', array_column($questions, 'id'));

// basic CSRF token for exam submission
if (empty($_SESSION['exam_csrf'])) {
    $_SESSION['exam_csrf'] = bin2hex(random_bytes(16));
}
$exam_csrf = $_SESSION['exam_csrf'];

// Detect mobile device
$isMobile = preg_match('/(android|webos|iphone|ipad|ipod|blackberry|iemobile|opera mini)/i', $_SERVER['HTTP_USER_AGENT']) ? true : false;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Exam - <?= htmlspecialchars($exam['title']) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover, maximum-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        :root {
            --safe-top: env(safe-area-inset-top, 0);
            --safe-bottom: env(safe-area-inset-bottom, 0);
        }

        body {
            padding-top: var(--safe-top);
            padding-bottom: var(--safe-bottom);
        }

        .navbar {
            padding-top: calc(0.5rem + var(--safe-top));
            padding-bottom: calc(0.5rem + var(--safe-bottom));
        }

        #webcamPreview {
            width: 100%;
            max-width: 200px;
            height: auto;
            aspect-ratio: 4 / 3;
            background: #000;
            border-radius: 9px;
            overflow: hidden;
            margin: 0 auto;
        }

        #webcamPreview video {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
        }

        .anti-cheat-warning {
            position: fixed;
            top: 10px;
            left: 10px;
            right: 10px;
            z-index: 1060;
            max-width: none;
        }

        .sticky-webcam-card {
            z-index: 1020;
        }

        .exam-container {
            max-width: 100%;
        }

        /* Mobile-specific adjustments */
        @media (max-width: 768px) {
            .navbar-brand {
                font-size: 1rem;
            }

            .navbar .btn-sm {
                padding: 0.25rem 0.5rem;
                font-size: 0.7rem;
            }

            .card-body {
                padding: 1rem 0.75rem;
            }

            .mb-3 {
                margin-bottom: 1rem !important;
            }

            .form-check {
                padding-left: 0;
                margin-left: 1.5rem;
            }

            .question-text {
                font-size: 1rem;
                line-height: 1.4;
            }

            .timer-display {
                font-weight: bold;
                padding: 0.5rem;
                background: rgba(255,255,255,0.1);
                border-radius: 0.25rem;
            }
        }

        /* Landscape mode adjustments */
        @media (max-width: 768px) and (orientation: landscape) {
            .navbar {
                padding: 0.25rem;
            }

            .navbar-brand {
                display: none;
            }

            .container-fluid {
                padding: 0.5rem;
            }

            .card {
                margin-bottom: 0.5rem;
            }
        }

        /* Questions styling for better mobile readability */
        .question-item {
            padding: 1rem 0;
            border-bottom: 1px solid #dee2e6;
        }

        .question-item:last-child {
            border-bottom: none;
        }

        .option-label {
            word-wrap: break-word;
            word-break: break-word;
        }

        /* Prevent zoom on input focus on iOS */
        input[type="radio"],
        input[type="checkbox"],
        button {
            font-size: 16px;
        }

        /* Better button touch targets */
        .btn {
            min-height: 44px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .form-check-input {
            width: 1.25em;
            height: 1.25em;
            margin-top: 0.125em;
        }
    </style>
</head>
<body class="bg-light">
<nav class="navbar navbar-expand-lg navbar-dark bg-dark sticky-top">
    <div class="container-fluid">
        <span class="navbar-brand">Exam: <?= htmlspecialchars($exam['title']) ?></span>
        <div class="d-flex align-items-center text-white gap-2">
            <div class="timer-display">
                <span id="timeLeft">--:--</span>
            </div>
            <a href="logout.php" class="btn btn-outline-light btn-sm">Logout</a>
        </div>
    </div>
</nav>

<div class="container-fluid exam-container py-2 py-md-3">
    <div class="row g-2 g-md-3">
        <!-- Questions Column -->
        <div class="col-12 col-lg-9 order-1 order-lg-1">
            <div class="card shadow-sm">
                <div class="card-body">
                    <h1 class="h5 mb-3"><?= htmlspecialchars($exam['subject_name']) ?> - Questions</h1>
                    <p class="small text-muted mb-3">Total: <?= count($questions) ?> questions</p>
                    
                    <form id="examForm" method="post" action="submit_exam.php">
                        <input type="hidden" name="exam_id" value="<?= (int)$exam['id'] ?>">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($exam_csrf) ?>">
                        
                        <?php foreach ($questions as $index => $q): ?>
                            <div class="question-item">
                                <p class="question-text mb-2">
                                    <strong>Q<?= $index + 1 ?>.</strong> <?= htmlspecialchars($q['question_text']) ?>
                                </p>
                                <?php
                                $options = ['A','B','C','D'];
                                if (!empty($exam['shuffle_options'])) {
                                    shuffle($options);
                                }
                                foreach ($options as $opt):
                                    $field = 'option_' . strtolower($opt);
                                ?>
                                    <div class="form-check mb-2">
                                        <input class="form-check-input" type="radio"
                                               name="answers[<?= (int)$q['id'] ?>]"
                                               id="q<?= (int)$q['id'] . $opt ?>"
                                               value="<?= $opt ?>">
                                        <label class="form-check-label option-label" for="q<?= (int)$q['id'] . $opt ?>">
                                            <?= $opt ?>. <?= htmlspecialchars($q[$field]) ?>
                                        </label>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endforeach; ?>
                        
                        <button type="submit" class="btn btn-success w-100 mt-3">Submit Exam</button>
                    </form>
                </div>
            </div>
        </div>

        <!-- Webcam & Info Column (mobile: below questions) -->
        <div class="col-12 col-lg-3 order-2 order-lg-2">
            <?php if ($isMobile): ?>
                <!-- Mobile: Compact webcam card -->
                <div class="card shadow-sm mb-2">
                    <div class="card-body p-2">
                        <h6 class="card-title mb-2">Camera Check</h6>
                        <div id="webcamPreview" class="mb-2">
                            <video id="webcamVideo" autoplay playsinline muted></video>
                        </div>
                        <p class="small text-muted mb-0" id="faceModelStatus">Initializing camera…</p>
                        <p class="small text-info mt-2" id="mobileWarning" style="display:none;">
                            ⚠️ Camera required for test integrity.
                        </p>
                    </div>
                </div>
            <?php else: ?>
                <!-- Desktop: Sticky webcam card -->
                <div class="card shadow-sm mb-3 sticky-top sticky-webcam-card" style="top: 6rem;">
                    <div class="card-body">
                        <h6 class="card-title">Webcam Anti-Cheating</h6>
                        <p class="small text-muted mb-2">
                            Stay in view of the webcam. If you are out of frame for 5 seconds, the exam submits automatically.
                        </p>
                        <p class="small text-info mb-2" id="faceModelStatus">Preparing webcam…</p>
                        <div id="webcamPreview">
                            <video id="webcamVideo" autoplay playsinline muted></video>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <div class="card shadow-sm">
                <div class="card-body">
                    <h6 class="card-title">Rules</h6>
                    <ul class="small text-muted mb-0 ps-3">
                        <?php if ($isMobile): ?>
                            <li>Keep your face visible to the camera</li>
                            <li>Do not switch apps</li>
                            <li>Do not lock your phone</li>
                            <li>Complete before time expires</li>
                        <?php else: ?>
                            <li>Stay in webcam view</li>
                            <li>No tab switching</li>
                            <li>No page refresh</li>
                            <li>Complete before time expires</li>
                        <?php endif; ?>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Auto-submit toast -->
<div class="toast align-items-center text-bg-danger border-0 anti-cheat-warning" id="cheatToast" role="alert">
    <div class="d-flex">
        <div class="toast-body">
            ⚠️ Test integrity check: exam submitting.
        </div>
        <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
    </div>
</div>

<!-- Face detection: BlazeFace + TFJS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/@tensorflow/tfjs@3.21.0/dist/tf.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/@tensorflow-models/blazeface@0.0.7/dist/blazeface.min.js"></script>
<script>
    const csrfToken = <?= json_encode($exam_csrf) ?>;
    const EXAM_DURATION_MINUTES = <?= (int)$exam['duration_minutes'] ?>;
    const IS_MOBILE = <?= json_encode($isMobile) ?>;
    const timeLeftEl = document.getElementById('timeLeft');
    const examForm = document.getElementById('examForm');
    const mobileWarningEl = document.getElementById('mobileWarning');
    let allowLeave = false;

    // ========== TIMER ==========
    let timeRemaining = EXAM_DURATION_MINUTES * 60;
    function updateTimer() {
        const minutes = Math.floor(timeRemaining / 60);
        const seconds = timeRemaining % 60;
        timeLeftEl.textContent = `${String(minutes).padStart(2,'0')}:${String(seconds).padStart(2,'0')}`;
        if (timeRemaining <= 0) {
            clearInterval(timerInterval);
            allowLeave = true;
            logCheatEvent('time_expired', 'Exam time expired');
        }
        timeRemaining--;
    }
    const timerInterval = setInterval(updateTimer, 1000);
    updateTimer();

    // ========== WEBCAM & FACE DETECTION ==========
    const videoEl = document.getElementById('webcamVideo');
    const WEBCAM_ABSENT_MS = IS_MOBILE ? 8000 : 5000; // More lenient on mobile
    const FACE_CHECK_INTERVAL_MS = 600; // Less frequent on mobile to save battery
    let noFaceSince = null;
    let faceWatchStarted = false;
    const faceModelStatusEl = document.getElementById('faceModelStatus');

    function setFaceStatus(msg) {
        if (faceModelStatusEl) faceModelStatusEl.textContent = msg;
    }

    async function createFacePresenceChecker() {
        // Try native API first (better on mobile)
        if ('FaceDetector' in window) {
            try {
                const fd = new FaceDetector({ fastMode: true, maxDetectedFaces: 1 });
                setFaceStatus('✓ Camera active — stay in view');
                return async function nativeDetect() {
                    try {
                        const faces = await fd.detect(videoEl);
                        return faces.length > 0;
                    } catch (e) {
                        return false;
                    }
                };
            } catch (e) {
                console.warn('Native FaceDetector failed, using BlazeFace.', e);
            }
        }

        // Fall back to BlazeFace
        if (typeof blazeface === 'undefined' || typeof blazeface.load !== 'function') {
            throw new Error('Face detection unavailable. Check internet connection and permissions.');
        }

        await tf.ready();
        await tf.setBackend('webgl').catch(() => tf.setBackend('cpu'));
        setFaceStatus('Loading face model…');
        
        const model = await blazeface.load();
        setFaceStatus('✓ Camera active — stay in view');
        
        return async function blazeDetect() {
            try {
                const preds = await model.estimateFaces(videoEl);
                return preds.length > 0;
            } catch (e) {
                return false;
            }
        };
    }

    async function startWebcamNoFaceWatch() {
        if (faceWatchStarted) return;
        faceWatchStarted = true;
        let hasFace = null;

        try {
            hasFace = await createFacePresenceChecker();
        } catch (e) {
            faceWatchStarted = false;
            console.error(e);
            setFaceStatus('✗ Camera unavailable or denied');
            if (mobileWarningEl) mobileWarningEl.style.display = 'block';
            return;
        }

        setInterval(async function() {
            if (alreadySubmitted || !videoEl.srcObject) return;
            if (videoEl.readyState < 2 || !videoEl.videoWidth) return;

            try {
                const ok = await hasFace();
                if (!ok) {
                    if (noFaceSince === null) {
                        noFaceSince = Date.now();
                    } else if (Date.now() - noFaceSince >= WEBCAM_ABSENT_MS) {
                        noFaceSince = null;
                        logCheatEvent('webcam_no_face', `No face detected for ${Math.round(WEBCAM_ABSENT_MS/1000)}s`);
                    }
                } else {
                    noFaceSince = null;
                }
            } catch (err) {
                // Silent fail on frame errors
            }
        }, FACE_CHECK_INTERVAL_MS);
    }

    // Request camera with mobile-friendly fallback
    if (navigator.mediaDevices && navigator.mediaDevices.getUserMedia) {
        const constraints = IS_MOBILE 
            ? { video: { facingMode: 'user', width: { ideal: 320 } }, audio: false }
            : { video: { facingMode: 'user' }, audio: false };

        navigator.mediaDevices.getUserMedia(constraints)
            .then(stream => {
                videoEl.srcObject = stream;
                videoEl.onloadedmetadata = function() {
                    videoEl.play().then(() => {
                        startWebcamNoFaceWatch().catch(err => {
                            console.error(err);
                            setFaceStatus('✗ ' + (err && err.message ? err.message : 'Camera check failed'));
                        });
                    });
                };
            })
            .catch(err => {
                console.warn('Webcam access denied', err);
                setFaceStatus('✗ Camera permission denied');
                if (mobileWarningEl) mobileWarningEl.style.display = 'block';
            });
    } else {
        setFaceStatus('✗ Browser does not support camera');
    }

    // ========== ANTI-CHEATING: TAB/APP SWITCHING ==========
    let alreadySubmitted = false;
    let pageReadyAt = 0;
    let lastTimeVisible = Date.now();
    let lastBlurTime = 0;
    setTimeout(() => { pageReadyAt = Date.now(); }, 1500);

    function logCheatEvent(type, details, payload, autoSubmit = true) {
        if (autoSubmit && alreadySubmitted) return;
        if (autoSubmit) {
            alreadySubmitted = true;
            allowLeave = true;
        }

        const toastEl = document.getElementById('cheatToast');
        if (autoSubmit) {
            const toast = new bootstrap.Toast(toastEl, { delay: 3000 });
            toast.show();
        }

        // Log to server
        fetch('log_activity.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                exam_id: <?= (int)$exam['id'] ?>,
                type: type,
                details: details || '',
                csrf_token: csrfToken,
                payload: payload || null
            })
        }).catch(() => {});

        if (autoSubmit) {
            alert('Your exam has been submitted (test integrity check).');
            examForm.submit();
        }
    }

    // Tab/App visibility detection
    document.addEventListener('visibilitychange', () => {
        if (document.hidden) {
            logCheatEvent('app_switch', `App switched on ${IS_MOBILE ? 'mobile' : 'desktop'}`);
        } else {
            const awayMs = Date.now() - lastTimeVisible;
            if (!alreadySubmitted && pageReadyAt > 0 && awayMs > 2000) {
                logCheatEvent('returned_from_background', `Returned after ${Math.round(awayMs/1000)}s`);
            }
            lastTimeVisible = Date.now();
        }
    });

    if (!IS_MOBILE) {
        // Desktop-only: window blur (doesn't work reliably on mobile)
        window.addEventListener('blur', () => {
            lastBlurTime = Date.now();
            logCheatEvent('window_blur', 'Window lost focus');
        });

        window.addEventListener('focus', () => {
            if (alreadySubmitted || pageReadyAt === 0) return;
            if (lastBlurTime > 0 && (Date.now() - lastBlurTime) > 2000) {
                logCheatEvent('return_after_blur', 'Window regained focus after leaving');
            }
        });

        window.addEventListener('beforeunload', (e) => {
            if (allowLeave) return;
            e.returnValue = 'Are you sure you want to leave the exam?';
            return e.returnValue;
        });
    } else {
        // Mobile: Lock screen / app backgrounding is enough
        document.addEventListener('pagehide', () => {
            if (!alreadySubmitted) {
                logCheatEvent('page_hidden', 'Page hidden on mobile', {}, false);
            }
        });

        window.addEventListener('beforeunload', (e) => {
            if (!allowLeave && pageReadyAt > 0) {
                logCheatEvent('page_close_attempt', 'User tried to close exam', {}, false);
            }
        });
    }

    // Periodic webcam snapshot
    function captureWebcamSnapshot() {
        if (!videoEl || !videoEl.srcObject) return;
        const canvas = document.createElement('canvas');
        canvas.width = 160;
        canvas.height = 120;
        const ctx = canvas.getContext('2d');
        if (!ctx) return;
        try {
            ctx.drawImage(videoEl, 0, 0, canvas.width, canvas.height);
            // Don't send full data URL; just log
            logCheatEvent('snapshot', 'Periodic camera check', {}, false);
        } catch (e) {
            // CORS or timing error
        }
    }

    setInterval(captureWebcamSnapshot, 120000); // Every 2 minutes on mobile, 1 min on desktop

    // Prevent accidental pinch-zoom on mobile
    document.addEventListener('touchmove', (e) => {
        if (e.touches.length > 1) {
            e.preventDefault();
        }
    }, { passive: false });
</script>
</body>
</html>
