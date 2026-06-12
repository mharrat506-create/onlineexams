<?php
require_once __DIR__ . '/includes/config.php';
require_login();

$result_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

$stmt = $pdo->prepare('
    SELECT r.*, e.title AS exam_title, s.name AS subject_name, u.name AS student_name
    FROM results r
    JOIN exams e ON r.exam_id = e.id
    JOIN subjects s ON e.subject_id = s.id
    JOIN users u ON r.user_id = u.id
    WHERE r.id = ? AND r.user_id = ? AND r.passed = 1
');
$stmt->execute([$result_id, $_SESSION['user_id']]);
$result = $stmt->fetch();

if (!$result) {
    die('Certificate not available.');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Certificate of Achievement</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        body {
            font-family: "Segoe UI", system-ui, -apple-system, sans-serif;
            background: #f0f0f0;
        }
        .certificate {
            width: 100%;
            max-width: 900px;
            margin: 30px auto;
            padding: 40px;
            background: #fff;
            border: 8px solid #1f2937;
            box-shadow: 0 10px 25px rgba(0,0,0,.1);
        }
        .certificate-header {
            text-align: center;
            margin-bottom: 30px;
        }
        .certificate-header h1 {
            font-size: 32px;
            letter-spacing: 2px;
            text-transform: uppercase;
            margin-bottom: 5px;
        }
        .certificate-header p {
            margin: 0;
            color: #4b5563;
        }
        .certificate-body {
            text-align: center;
            margin: 20px 0 40px;
        }
        .certificate-body h2 {
            font-size: 26px;
            margin-bottom: 10px;
        }
        .certificate-body .name {
            font-size: 28px;
            font-weight: 600;
            margin: 10px 0 5px;
        }
        .certificate-footer {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 30px;
            font-size: 14px;
        }
        @media print {
            body {
                background: #fff;
            }
            .certificate {
                box-shadow: none;
                margin: 0;
                border-width: 4px;
            }
            .no-print {
                display: none !important;
            }
        }
    </style>
</head>
<body>
<div class="no-print" style="text-align:center; margin-top:15px;">
    <button onclick="window.print()" style="padding:8px 16px; font-size:14px;">Print / Download as PDF</button>
</div>

<div class="certificate">
    <div class="certificate-header">
        <h1>Certificate of Achievement</h1>
        <p>Online Exam System</p>
    </div>
    <div class="certificate-body">
        <p>This is to certify that</p>
        <div class="name"><?= htmlspecialchars($result['student_name']) ?></div>
        <p>has successfully completed the exam</p>
        <h2><?= htmlspecialchars($result['exam_title']) ?></h2>
        <p>in the subject <strong><?= htmlspecialchars($result['subject_name']) ?></strong></p>
        <p>with a score of <strong><?= number_format($result['score_percent'], 2) ?>%</strong>.</p>
        <p style="margin-top:20px; color:#6b7280;">
            Awarded on <?= htmlspecialchars(date('F j, Y', strtotime($result['taken_at']))) ?>
        </p>
    </div>
    <div class="certificate-footer">
        <div>
            ___________________________<br>
            Authorized Signature
        </div>
        <div>
            Result ID: <?= (int)$result['id'] ?><br>
            Student ID: <?= (int)$result['user_id'] ?>
        </div>
    </div>
</div>
</body>
</html>

