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
    WHERE r.id = ? AND r.user_id = ?
');
$stmt->execute([$result_id, $_SESSION['user_id']]);
$result = $stmt->fetch();

if (!$result) {
    die('Result not found.');
}

$passed = (bool)$result['passed'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Exam Result</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<nav class="navbar navbar-expand-lg navbar-dark bg-dark">
    <div class="container-fluid">
        <span class="navbar-brand">Exam Result</span>
        <div class="d-flex align-items-center text-white">
            <span class="me-3">Hi, <?= htmlspecialchars($_SESSION['name'] ?? '') ?></span>
            <a href="logout.php" class="btn btn-outline-light btn-sm">Logout</a>
        </div>
    </div>
</nav>
<div class="container py-4">
    <div class="card shadow-sm">
        <div class="card-body">
            <h1 class="h4 mb-3"><?= htmlspecialchars($result['exam_title']) ?> - <?= htmlspecialchars($result['subject_name']) ?></h1>
            <p><strong>Total Questions:</strong> <?= (int)$result['total_questions'] ?></p>
            <p><strong>Correct Answers:</strong> <?= (int)$result['correct_answers'] ?></p>
            <p><strong>Score:</strong> <?= number_format($result['score_percent'], 2) ?>%</p>
            <p><strong>Status:</strong>
                <?php if ($passed): ?>
                    <span class="badge bg-success">Passed</span>
                <?php else: ?>
                    <span class="badge bg-danger">Failed</span>
                <?php endif; ?>
            </p>

            <?php if ($passed): ?>
                <a class="btn btn-primary" href="certificate.php?id=<?= (int)$result['id'] ?>" target="_blank">
                    Download Certificate
                </a>
            <?php endif; ?>

            <a href="select_exam.php" class="btn btn-link">Back to Exams</a>
        </div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

