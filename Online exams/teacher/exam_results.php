<?php
require_once __DIR__ . '/../includes/config.php';
require_teacher();

$teacherId = get_teacher_id($pdo);
if (!$teacherId) {
    die('Teacher profile not found.');
}

$exam_id = isset($_GET['exam_id']) ? (int)$_GET['exam_id'] : 0;

$examStmt = $pdo->prepare('
    SELECT e.*, s.name AS subject_name
    FROM exams e
    JOIN subjects s ON e.subject_id = s.id
    WHERE e.id = ? AND e.teacher_id = ?
    LIMIT 1
');
$examStmt->execute([$exam_id, $teacherId]);
$exam = $examStmt->fetch();

if (!$exam) {
    die('Exam not found or you do not have access.');
}

$resultsStmt = $pdo->prepare('
    SELECT r.*, u.name AS student_name, u.email AS student_email
    FROM results r
    JOIN users u ON r.user_id = u.id
    WHERE r.exam_id = ?
    ORDER BY r.taken_at DESC
');
$resultsStmt->execute([$exam_id]);
$results = $resultsStmt->fetchAll();

$statsStmt = $pdo->prepare('
    SELECT COUNT(*) AS attempts,
           COALESCE(AVG(score_percent), 0) AS avg_score,
           COALESCE(MAX(score_percent), 0) AS highest_score,
           COALESCE(MIN(score_percent), 0) AS lowest_score,
           SUM(passed) AS passed_count
    FROM results
    WHERE exam_id = ?
');
$statsStmt->execute([$exam_id]);
$stats = $statsStmt->fetch();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Exam Results - <?= htmlspecialchars($exam['title']) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<nav class="navbar navbar-expand-lg navbar-dark bg-primary">
    <div class="container-fluid">
        <span class="navbar-brand">Exam Results</span>
        <div class="d-flex align-items-center text-white gap-2">
            <a href="teacher.php" class="btn btn-outline-light btn-sm">Back to Dashboard</a>
            <a href="../logout.php" class="btn btn-outline-light btn-sm">Logout</a>
        </div>
    </div>
</nav>

<div class="container py-4">
    <div class="card shadow-sm mb-4">
        <div class="card-body">
            <h1 class="h4 mb-1"><?= htmlspecialchars($exam['title']) ?></h1>
            <p class="text-muted mb-3"><?= htmlspecialchars($exam['subject_name']) ?> · <?= (int)$exam['duration_minutes'] ?> minutes</p>
            <div class="row g-3">
                <div class="col-sm-6 col-md-3">
                    <div class="border rounded p-3 text-center">
                        <div class="h4 mb-0"><?= (int)$stats['attempts'] ?></div>
                        <div class="small text-muted">Attempts</div>
                    </div>
                </div>
                <div class="col-sm-6 col-md-3">
                    <div class="border rounded p-3 text-center">
                        <div class="h4 mb-0"><?= number_format((float)$stats['avg_score'], 1) ?>%</div>
                        <div class="small text-muted">Average Score</div>
                    </div>
                </div>
                <div class="col-sm-6 col-md-3">
                    <div class="border rounded p-3 text-center">
                        <div class="h4 mb-0"><?= number_format((float)$stats['highest_score'], 1) ?>%</div>
                        <div class="small text-muted">Highest</div>
                    </div>
                </div>
                <div class="col-sm-6 col-md-3">
                    <div class="border rounded p-3 text-center">
                        <div class="h4 mb-0"><?= (int)$stats['passed_count'] ?> / <?= (int)$stats['attempts'] ?></div>
                        <div class="small text-muted">Passed</div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="card shadow-sm">
        <div class="card-body">
            <h5 class="card-title">Student Results</h5>
            <?php if (!$results): ?>
                <p class="text-muted mb-0">No students have taken this exam yet.</p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead>
                        <tr>
                            <th>Student</th>
                            <th>Email</th>
                            <th>Score</th>
                            <th>Correct</th>
                            <th>Status</th>
                            <th>Taken At</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($results as $row): ?>
                            <tr>
                                <td><?= htmlspecialchars($row['student_name']) ?></td>
                                <td><?= htmlspecialchars($row['student_email']) ?></td>
                                <td><?= number_format((float)$row['score_percent'], 1) ?>%</td>
                                <td><?= (int)$row['correct_answers'] ?> / <?= (int)$row['total_questions'] ?></td>
                                <td>
                                    <?php if ((int)$row['passed']): ?>
                                        <span class="badge text-bg-success">Passed</span>
                                    <?php else: ?>
                                        <span class="badge text-bg-danger">Failed</span>
                                    <?php endif; ?>
                                </td>
                                <td class="small"><?= htmlspecialchars(date('Y-m-d H:i', strtotime($row['taken_at']))) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
