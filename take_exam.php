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
if (empty($_SESSION['exam_token'])) {
    $_SESSION['exam_token'] = bin2hex(random_bytes(16));
}
$exam_token = $_SESSION['exam_token'];
// basic CSRF token for exam submission
if (empty($_SESSION['exam_csrf'])) {
    $_SESSION['exam_csrf'] = bin2hex(random_bytes(16));
}
$exam_csrf = $_SESSION['exam_csrf'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Exam - <?= htmlspecialchars($exam['title']) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        #webcamPreview {
            width: 160px;
            height: 120px;
            background: #000;
            border-radius: 9px;
            overflow: hidden;
        }
        #webcamPreview video {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        .anti-cheat-warning {
            position: fixed;
            top: 10px;
            left: 10px;
            z-index: 1060;
        }
        .sticky-webcam-card {
            top: 5.5rem;
            z-index: 1020;
        }
    </style>
</head>
<body class="bg-light">
<nav class="navbar navbar-expand-lg navbar-dark bg-dark">
    <div class="container-fluid">
        <span class="navbar-brand">Exam: <?= htmlspecialchars($exam['title']) ?></span>
        <div class="d-flex align-items-center text-white">
            <div class="me-3">
                Time left: <span id="timeLeft"></span>
            </div>
            <a href="logout.php" class="btn btn-outline-light btn-sm">Logout</a>
        </div>
    </div>
</nav>

<div class="container-fluid py-3">
    <div class="row g-3">
        <div class="col-lg-9">
            <div class="card shadow-sm">
                <div class="card-body">
                    <h1 class="h5 mb-3"><?= htmlspecialchars($exam['subject_name']) ?> - Questions</h1>
                    <form id="examForm" method="post" action="submit_exam.php">
                        <input type="hidden" name="exam_id" value="<?= (int)$exam['id'] ?>">
                        <input type="hidden" name="token" value="<?= htmlspecialchars($exam_token) ?>">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($exam_csrf) ?>">
                        <?php foreach ($questions as $index => $q): ?>
                            <div class="mb-3 border-bottom pb-2">
                                <p><strong>Q<?= $index + 1 ?>.</strong> <?= htmlspecialchars($q['question_text']) ?></p>
                                <?php
                                $options = ['A','B','C','D'];
                                if (!empty($exam['shuffle_options'])) {
                                    shuffle($options);
                                }
                                foreach ($options as $opt):
                                    $field = 'option_' . strtolower($opt);
                                ?>
                                    <div class="form-check">
                                        <input class="form-check-input" type="radio"
                                               name="answers[<?= (int)$q['id'] ?>]"
                                               id="q<?= (int)$q['id'] . $opt ?>"
                                               value="<?= $opt ?>">
                                        <label class="form-check-label" for="q<?= (int)$q['id'] . $opt ?>">
                                            <?= $opt ?>. <?= htmlspecialchars($q[$field]) ?>
                                        </label>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endforeach; ?>
                        <button type="submit" class="btn btn-success">Submit Exam</button>
                    </form>
                </div>
            </div>
        </div>
        <div class="col-lg-3">
            <div class="card shadow-sm mb-3 sticky-top sticky-webcam-card">
                <div class="card-body">
                    <h6 class="card-title">Webcam Anti-Cheating</h6>
                    <p class="small text-muted mb-2">
                        Stay in view of the webcam. If you are out of frame for 5 seconds, the exam submits automatically.
                        A face model loads once when the exam starts (needs a few seconds on first visit).
                    </p>
                    <p class="small text-info mb-0" id="faceModelStatus">Preparing webcam…</p>
                    <div id="webcamPreview">
                        <video id="webcamVideo" autoplay playsinline muted></video>
                    </div>
                </div>
            </div>
            <div class="card shadow-sm">
                <div class="card-body">
                    <h6 class="card-title">Other rules</h6>
                    <p class="small text-muted mb-0">
                        Switching tabs, leaving the window, or closing the page can also submit the exam after confirmation.
                    </p>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="toast align-items-center text-bg-danger border-0 anti-cheat-warning" id="cheatToast" role="alert">
    <div class="d-flex">
        <div class="toast-body">
            Anti-cheat: your exam has been submitted.
        </div>
        <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
    </div>
</div>

<!-- Face detection: BlazeFace + TFJS (load Bootstrap first so toasts work) -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/@tensorflow/tfjs@3.21.0/dist/tf.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/@tensorflow-models/blazeface@0.0.7/dist/blazeface.min.js"></script>
<script>
    const csrfToken = <?= json_encode($exam_csrf) ?>;
    const EXAM_DURATION_MINUTES = <?= (int)$exam['duration_minutes'] ?>;
    const timeLeftEl = document.getElementById('timeLeft');
    const examForm = document.getElementById('examForm');
    let allowLeave = false; // allow page unload without warning when auto-submitting

    let timeRemaining = EXAM_DURATION_MINUTES * 60;
    function updateTimer() {
        const minutes = Math.floor(timeRemaining / 60);
        const seconds = timeRemaining % 60;
        timeLeftEl.textContent = `${String(minutes).padStart(2,'0')}:${String(seconds).padStart(2,'0')}`;
        if (timeRemaining <= 0) {
            clearInterval(timerInterval);
            alert('Time is over. Your exam will be submitted.');
            allowLeave = true;
            examForm.submit();
        }
        timeRemaining--;
    }
    const timerInterval = setInterval(updateTimer, 1000);
    updateTimer();

    // Webcam setup + no-face timer (5s out of frame → submit)
    const videoEl = document.getElementById('webcamVideo');
    const WEBCAM_ABSENT_MS = 5000;
    const FACE_CHECK_INTERVAL_MS = 400;
    let noFaceSince = null;
    let faceWatchStarted = false;
    const faceModelStatusEl = document.getElementById('faceModelStatus');

    function setFaceStatus(msg) {
        if (faceModelStatusEl) faceModelStatusEl.textContent = msg;
    }

    /**
     * Builds a hasFace() async function using native FaceDetector (often disabled) or BlazeFace (CDN).
     */
    async function createFacePresenceChecker() {
        if ('FaceDetector' in window) {
            try {
                const fd = new FaceDetector({ fastMode: true, maxDetectedFaces: 1 });
                setFaceStatus('Face monitoring active (browser API) — stay in view.');
                return async function nativeDetect() {
                    const faces = await fd.detect(videoEl);
                    return faces.length > 0;
                };
            } catch (e) {
                console.warn('Native FaceDetector failed, using BlazeFace.', e);
            }
        }
        if (typeof blazeface === 'undefined' || typeof blazeface.load !== 'function') {
            throw new Error('BlazeFace script failed to load. Check your internet and refresh.');
        }
        await tf.ready();
        await tf.setBackend('webgl').catch(function() { return tf.setBackend('cpu'); });
        setFaceStatus('Loading face model…');
        const model = await blazeface.load();
        setFaceStatus('Face monitoring active — stay in view.');
        return async function blazeDetect() {
            const preds = await model.estimateFaces(videoEl);
            return preds.length > 0;
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
            setFaceStatus('Face check unavailable. Allow webcam and reload; check internet for model download.');
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
                        logCheatEvent('webcam_no_face', 'No face in webcam for 5 seconds');
                    }
                } else {
                    noFaceSince = null;
                }
            } catch (err) {
                // ignore single-frame errors
            }
        }, FACE_CHECK_INTERVAL_MS);
    }

    if (navigator.mediaDevices && navigator.mediaDevices.getUserMedia) {
        navigator.mediaDevices.getUserMedia({ video: { facingMode: 'user' }, audio: false })
            .then(stream => {
                videoEl.srcObject = stream;
                videoEl.onloadeddata = function() {
                    startWebcamNoFaceWatch().catch(function(err) {
                        console.error(err);
                        setFaceStatus('Could not start face monitoring: ' + (err && err.message ? err.message : err));
                    });
                };
            })
            .catch(err => {
                console.warn('Webcam access denied', err);
                setFaceStatus('Webcam denied — face check cannot run.');
            });
    } else {
        setFaceStatus('This browser cannot access the webcam.');
    }

    // Anti-cheating: auto-submit when user leaves focus or when they return after leaving
    let alreadySubmitted = false;
    let userLeftFocus = false;
    let pageReadyAt = 0;
    let lastTimeVisible = Date.now();
    let lastBlurTime = 0;
    setTimeout(function() { pageReadyAt = Date.now(); }, 1500);

    function logCheatEvent(type, details, payload, autoSubmit) {
        if (autoSubmit !== false && alreadySubmitted) return;
        if (autoSubmit !== false) {
            alreadySubmitted = true;
            allowLeave = true;
        }

        const toastEl = document.getElementById('cheatToast');
        if (autoSubmit !== false) {
            const toast = new bootstrap.Toast(toastEl, { delay: 3000 });
            toast.show();
        }

        // log to server
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

        if (autoSubmit !== false) {
            alert('Your exam has been submitted (anti-cheat rule).');
            examForm.submit();
        }
    }

    function submitBecauseLeftFocus(reason) {
        userLeftFocus = true;
        logCheatEvent(reason, 'User left exam window focus');
    }

    // When user leaves or returns: tab switch / visibility
    document.addEventListener('visibilitychange', () => {
        if (document.hidden) {
            submitBecauseLeftFocus('tab_switch');
        } else {
            // Came back to tab – submit if they were away > 2 sec (catches when leave event didn't fire)
            const awayMs = Date.now() - lastTimeVisible;
            if (!alreadySubmitted && pageReadyAt > 0 && awayMs > 2000) {
                logCheatEvent('return_after_leave', 'Returned to tab after ' + Math.round(awayMs/1000) + 's away');
            }
            lastTimeVisible = Date.now();
        }
    });

    window.addEventListener('blur', () => {
        lastBlurTime = Date.now();
        submitBecauseLeftFocus('window_blur');
    });

    // When user returns to window – submit if they were away > 2 sec (in case blur submit didn't fire)
    window.addEventListener('focus', () => {
        if (alreadySubmitted || pageReadyAt === 0) return;
        if (lastBlurTime > 0 && (Date.now() - lastBlurTime) > 2000) {
            logCheatEvent('return_after_leave', 'Returned to window after leaving');
        }
    });

    window.addEventListener('beforeunload', (e) => {
        if (allowLeave) {
            return;
        }
        // Do not call logCheatEvent here (it would try to submit during unload).
        e.returnValue = 'Are you sure you want to leave the exam?';
        return e.returnValue;
    });

    // AI hook: periodic webcam snapshot placeholder
    function captureWebcamSnapshot() {
        if (!videoEl || !videoEl.srcObject) {
            return;
        }
        const canvas = document.createElement('canvas');
        canvas.width = 160;
        canvas.height = 120;
        const ctx = canvas.getContext('2d');
        if (!ctx) return;
        ctx.drawImage(videoEl, 0, 0, canvas.width, canvas.height);
        const dataUrl = canvas.toDataURL('image/jpeg', 0.4);
        // In production, send a binary blob or reference ID instead of full data URL
        logCheatEvent('webcam_snapshot', 'Periodic snapshot', { preview: dataUrl.substring(0, 100) }, false);
    }

    // Take a lightweight snapshot every 60 seconds
    setInterval(captureWebcamSnapshot, 60000);
</script>
</body>
</html>

