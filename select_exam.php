<?php
require_once __DIR__ . '/includes/config.php';
require_login();

// load active and in-window exams by subject
$stmt = $pdo->query('
    SELECT exams.id,
           exams.title,
           exams.duration_minutes,
           exams.start_time,
           exams.end_time,
           subjects.name AS subject_name
    FROM exams
    JOIN subjects ON exams.subject_id = subjects.id
    WHERE exams.is_active = 1
      AND (exams.start_time IS NULL OR exams.start_time <= NOW())
      AND (exams.end_time IS NULL OR exams.end_time >= NOW())
    ORDER BY subjects.name, exams.title
');
$exams = $stmt->fetchAll();

$hiddenCount = 0;
if (!$exams) {
    $hiddenCount = (int)$pdo->query('SELECT COUNT(*) FROM exams')->fetchColumn();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Select Exam</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<nav class="navbar navbar-expand-lg navbar-dark bg-dark">
    <div class="container-fluid">
        <span class="navbar-brand">Online Exam</span>
        <div class="d-flex align-items-center text-white gap-2">
            <span class="me-2">Hi, <?= htmlspecialchars($_SESSION['name'] ?? '') ?></span>
            <?php if (is_admin()): ?>
                <a href="admin/admin.php" class="btn btn-outline-light btn-sm">Admin</a>
            <?php endif; ?>
            <?php if (is_teacher()): ?>
                <a href="teacher/teacher.php" class="btn btn-outline-light btn-sm">Teacher</a>
            <?php endif; ?>
            <a href="logout.php" class="btn btn-outline-light btn-sm">Logout</a>
        </div>
    </div>
</nav>
<div class="container py-4">
    <h1 class="h4 mb-3">Choose an Exam</h1>
    <?php if (!$exams): ?>
        <div class="alert alert-info">
            <?php if ($hiddenCount > 0): ?>
                No exams are available right now. <?= $hiddenCount ?> exam(s) exist but may be inactive, not yet started, or past their end date.
                <?php if (is_admin()): ?> Check the <a href="admin/admin.php" class="alert-link">admin dashboard</a> to review them.<?php endif; ?>
            <?php else: ?>
                No active exams available yet.
                <?php if (is_admin()): ?> <a href="admin/admin.php" class="alert-link">Create an exam</a> in the admin dashboard.<?php endif; ?>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <div class="row g-3">
            <?php foreach ($exams as $exam): ?>
                <div class="col-md-4">
                    <div class="card h-100 shadow-sm">
                        <div class="card-body d-flex flex-column">
                            <h5 class="card-title"><?= htmlspecialchars($exam['title']) ?></h5>
                            <p class="card-text mb-1"><strong>Subject:</strong> <?= htmlspecialchars($exam['subject_name']) ?></p>
                            <p class="card-text mb-3"><strong>Duration:</strong> <?= (int)$exam['duration_minutes'] ?> minutes</p>
                            <a href="take_exam.php?exam_id=<?= (int)$exam['id'] ?>" class="btn btn-primary mt-auto">Start Exam</a>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

