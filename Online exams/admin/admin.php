<?php
require_once __DIR__ . '/../includes/config.php';
require_login();
if (!is_admin()) {
    die('Admins only.');
}

require_post_csrf();

// Handle department creation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['new_department'])) {
    $name = trim($_POST['department_name'] ?? '');
    $desc = trim($_POST['department_desc'] ?? '');
    if ($name) {
        $stmt = $pdo->prepare('INSERT INTO departments (name, description) VALUES (?, ?)');
        $stmt->execute([$name, $desc]);
    }
    header('Location: admin.php');
    exit;
}

// Handle subject creation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['new_subject'])) {
    $name = trim($_POST['subject_name'] ?? '');
    $desc = trim($_POST['subject_desc'] ?? '');
    if ($name) {
        $stmt = $pdo->prepare('INSERT INTO subjects (name, description) VALUES (?, ?)');
        $stmt->execute([$name, $desc]);
    }
    header('Location: admin.php');
    exit;
}

// Handle question creation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['new_question'])) {
    $subject_id = (int)($_POST['subject_id'] ?? 0);
    $text = trim($_POST['question_text'] ?? '');
    $a = trim($_POST['option_a'] ?? '');
    $b = trim($_POST['option_b'] ?? '');
    $c = trim($_POST['option_c'] ?? '');
    $d = trim($_POST['option_d'] ?? '');
    $correct = $_POST['correct_option'] ?? '';
    $marks = (int)($_POST['marks'] ?? 1);
    if ($subject_id && $text && $a && $b && $c && $d && in_array($correct, ['A','B','C','D'], true)) {
        $stmt = $pdo->prepare('
            INSERT INTO questions (subject_id, question_text, option_a, option_b, option_c, option_d, correct_option, marks)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ');
        $stmt->execute([$subject_id, $text, $a, $b, $c, $d, $correct, $marks]);
    }
    header('Location: admin.php');
    exit;
}

// Toggle exam active status
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_exam'])) {
    $exam_id = (int)($_POST['exam_id'] ?? 0);
    $is_active = (int)($_POST['is_active'] ?? 0) ? 1 : 0;
    if ($exam_id) {
        $stmt = $pdo->prepare('UPDATE exams SET is_active = ? WHERE id = ?');
        $stmt->execute([$is_active, $exam_id]);
    }
    header('Location: admin.php');
    exit;
}

// Extend expired exam end dates
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['extend_exam'])) {
    $exam_id = (int)($_POST['exam_id'] ?? 0);
    if ($exam_id) {
        $stmt = $pdo->prepare('UPDATE exams SET end_time = NOW() + INTERVAL 1 YEAR WHERE id = ?');
        $stmt->execute([$exam_id]);
    }
    header('Location: admin.php');
    exit;
}

// Handle exam creation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['new_exam'])) {
    $subject_id = (int)($_POST['exam_subject_id'] ?? 0);
    $class_id = (int)($_POST['exam_class_id'] ?? 0);
    $title = trim($_POST['exam_title'] ?? '');
    $duration = (int)($_POST['duration_minutes'] ?? 30);
    $start = trim($_POST['start_time'] ?? '');
    $end = trim($_POST['end_time'] ?? '');
    $questionsPerExam = (int)($_POST['questions_per_exam'] ?? 0);
    $shuffleQuestions = isset($_POST['shuffle_questions']) ? 1 : 0;
    $shuffleOptions = isset($_POST['shuffle_options']) ? 1 : 0;
    if ($subject_id && $title && $duration > 0) {
        $stmt = $pdo->prepare('
            INSERT INTO exams
                (subject_id, title, duration_minutes, start_time, end_time, questions_per_exam, shuffle_questions, shuffle_options, class_id)
            VALUES (?, ?, ?, NULLIF(?, \'\'), NULLIF(?, \'\'), ?, ?, ?, NULLIF(?, 0))
        ');
        $stmt->execute([
            $subject_id,
            $title,
            $duration,
            $start,
            $end,
            $questionsPerExam,
            $shuffleQuestions,
            $shuffleOptions,
            $class_id,
        ]);
    }
    header('Location: admin.php');
    exit;
}

// Load data for forms and analytics
$subjects = $pdo->query('SELECT * FROM subjects ORDER BY name')->fetchAll();
$departments = $pdo->query('SELECT * FROM departments ORDER BY name')->fetchAll();
$classes = $pdo->query('SELECT c.*, d.name AS department_name FROM classes c LEFT JOIN departments d ON c.department_id = d.id ORDER BY c.name')->fetchAll();

$analyticsStmt = $pdo->query('
    SELECT s.id AS subject_id,
           s.name AS subject_name,
           COUNT(r.id) AS attempts,
           COALESCE(AVG(r.score_percent), 0) AS avg_score
    FROM subjects s
    LEFT JOIN exams e ON e.subject_id = s.id
    LEFT JOIN results r ON r.exam_id = e.id
    GROUP BY s.id, s.name
    ORDER BY s.name
');
$analytics = $analyticsStmt->fetchAll();

$examsStmt = $pdo->query('
    SELECT e.id,
           e.title,
           e.duration_minutes,
           e.start_time,
           e.end_time,
           e.is_active,
           s.name AS subject_name,
           (SELECT COUNT(*) FROM questions q WHERE q.subject_id = e.subject_id) AS question_count
    FROM exams e
    JOIN subjects s ON e.subject_id = s.id
    ORDER BY e.created_at DESC, e.title
');
$allExams = $examsStmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Admin - Online Exam</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<nav class="navbar navbar-expand-lg navbar-dark bg-dark">
    <div class="container-fluid">
        <span class="navbar-brand">Admin Dashboard</span>
        <div class="d-flex align-items-center text-white gap-2">
            <span class="me-2"><?= htmlspecialchars($_SESSION['name'] ?? '') ?></span>
            <a href="../select_exam.php" class="btn btn-outline-light btn-sm">View Exams</a>
            <a href="../logout.php" class="btn btn-outline-light btn-sm">Logout</a>
        </div>
    </div>
</nav>

<div class="container py-4">
    <div class="card shadow-sm mb-4">
        <div class="card-body">
            <h5 class="card-title">Exams</h5>
            <p class="small text-muted mb-3">Students only see active exams within their start/end window.</p>
            <?php if (!$allExams): ?>
                <p class="text-muted mb-0">No exams yet. Create one below.</p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead>
                        <tr>
                            <th>Title</th>
                            <th>Subject</th>
                            <th>Duration</th>
                            <th>Questions</th>
                            <th>Window</th>
                            <th>Status</th>
                            <th></th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($allExams as $exam): ?>
                            <?php
                            $now = time();
                            $start = $exam['start_time'] ? strtotime($exam['start_time']) : null;
                            $end = $exam['end_time'] ? strtotime($exam['end_time']) : null;
                            if (!(int)$exam['is_active']) {
                                $status = 'Inactive';
                                $badge = 'secondary';
                            } elseif ($start && $start > $now) {
                                $status = 'Scheduled';
                                $badge = 'warning';
                            } elseif ($end && $end < $now) {
                                $status = 'Expired';
                                $badge = 'danger';
                            } elseif ((int)$exam['question_count'] === 0) {
                                $status = 'No questions';
                                $badge = 'warning';
                            } else {
                                $status = 'Available';
                                $badge = 'success';
                            }
                            ?>
                            <tr>
                                <td><?= htmlspecialchars($exam['title']) ?></td>
                                <td><?= htmlspecialchars($exam['subject_name']) ?></td>
                                <td><?= (int)$exam['duration_minutes'] ?> min</td>
                                <td><?= (int)$exam['question_count'] ?></td>
                                <td class="small">
                                    <?php if ($exam['start_time'] || $exam['end_time']): ?>
                                        <?= $exam['start_time'] ? htmlspecialchars(date('Y-m-d H:i', strtotime($exam['start_time']))) : '—' ?>
                                        →
                                        <?= $exam['end_time'] ? htmlspecialchars(date('Y-m-d H:i', strtotime($exam['end_time']))) : '—' ?>
                                    <?php else: ?>
                                        Always open
                                    <?php endif; ?>
                                </td>
                                <td><span class="badge text-bg-<?= $badge ?>"><?= $status ?></span></td>
                                <td class="text-nowrap">
                                    <form method="post" class="d-inline">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(get_csrf_token()) ?>">
                                        <input type="hidden" name="toggle_exam" value="1">
                                        <input type="hidden" name="exam_id" value="<?= (int)$exam['id'] ?>">
                                        <input type="hidden" name="is_active" value="<?= (int)$exam['is_active'] ? 0 : 1 ?>">
                                        <button class="btn btn-outline-secondary btn-sm">
                                            <?= (int)$exam['is_active'] ? 'Deactivate' : 'Activate' ?>
                                        </button>
                                    </form>
                                    <?php if ($status === 'Expired'): ?>
                                        <form method="post" class="d-inline">
                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(get_csrf_token()) ?>">
                                            <input type="hidden" name="extend_exam" value="1">
                                            <input type="hidden" name="exam_id" value="<?= (int)$exam['id'] ?>">
                                            <button class="btn btn-outline-primary btn-sm">Extend 1 year</button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <div class="row g-4">
        <div class="col-lg-4">
            <div class="card shadow-sm mb-3">
                <div class="card-body">
                    <h5 class="card-title">Add Department</h5>
                    <form method="post">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(get_csrf_token()) ?>">
                        <input type="hidden" name="new_subject" value="0">
                        <input type="hidden" name="new_exam" value="0">
                        <input type="hidden" name="new_question" value="0">
                        <input type="hidden" name="new_department" value="1">
                        <div class="mb-2">
                            <label class="form-label">Name</label>
                            <input type="text" name="department_name" class="form-control" required>
                        </div>
                        <div class="mb-2">
                            <label class="form-label">Description (optional)</label>
                            <textarea name="department_desc" class="form-control" rows="2"></textarea>
                        </div>
                        <button class="btn btn-secondary w-100">Save Department</button>
                    </form>
                </div>
            </div>
            <div class="card shadow-sm mb-3">
                <div class="card-body">
                    <h5 class="card-title">Add Subject</h5>
                    <form method="post">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(get_csrf_token()) ?>">
                        <input type="hidden" name="new_subject" value="1">
                        <div class="mb-2">
                            <label class="form-label">Name</label>
                            <input type="text" name="subject_name" class="form-control" required>
                        </div>
                        <div class="mb-2">
                            <label class="form-label">Description (optional)</label>
                            <textarea name="subject_desc" class="form-control" rows="2"></textarea>
                        </div>
                        <button class="btn btn-primary w-100">Save Subject</button>
                    </form>
                </div>
            </div>

            <div class="card shadow-sm">
                <div class="card-body">
                    <h5 class="card-title">Create Exam</h5>
                    <form method="post">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(get_csrf_token()) ?>">
                        <input type="hidden" name="new_exam" value="1">
                        <div class="mb-2">
                            <label class="form-label">Subject</label>
                            <select name="exam_subject_id" class="form-select" required>
                                <option value="">Select subject</option>
                                <?php foreach ($subjects as $s): ?>
                                    <option value="<?= (int)$s['id'] ?>"><?= htmlspecialchars($s['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-2">
                            <label class="form-label">Class (optional)</label>
                            <select name="exam_class_id" class="form-select">
                                <option value="0">All classes</option>
                                <?php foreach ($classes as $c): ?>
                                    <option value="<?= (int)$c['id'] ?>">
                                        <?= htmlspecialchars($c['name']) ?><?php if ($c['department_name']): ?> (<?= htmlspecialchars($c['department_name']) ?>)<?php endif; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-2">
                            <label class="form-label">Exam Title</label>
                            <input type="text" name="exam_title" class="form-control" required placeholder="e.g. Mathematics Final">
                        </div>
                        <div class="mb-2">
                            <label class="form-label">Duration (minutes)</label>
                            <input type="number" name="duration_minutes" class="form-control" value="30" min="5">
                        </div>
                        <div class="mb-2">
                            <label class="form-label">Start Time (optional)</label>
                            <input type="datetime-local" name="start_time" class="form-control">
                        </div>
                        <div class="mb-2">
                            <label class="form-label">End Time (optional)</label>
                            <input type="datetime-local" name="end_time" class="form-control">
                        </div>
                        <div class="row">
                            <div class="col-sm-6 mb-2">
                                <label class="form-label">Questions per attempt (0 = all)</label>
                                <input type="number" name="questions_per_exam" class="form-control" value="0" min="0">
                            </div>
                            <div class="col-sm-6 mb-2">
                                <label class="form-label">Randomization</label>
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="shuffle_questions" id="shuffleQuestions" checked>
                                    <label class="form-check-label" for="shuffleQuestions">Shuffle questions</label>
                                </div>
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="shuffle_options" id="shuffleOptions" checked>
                                    <label class="form-check-label" for="shuffleOptions">Shuffle options</label>
                                </div>
                            </div>
                        </div>
                        <button class="btn btn-success w-100">Create Exam</button>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-lg-5">
            <div class="card shadow-sm">
                <div class="card-body">
                    <h5 class="card-title">Question Bank (admin.html)</h5>
                    <p class="small text-muted">
                        Add questions per subject. Each question is multiple choice (A–D).
                    </p>
                    <form method="post">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(get_csrf_token()) ?>">
                        <input type="hidden" name="new_question" value="1">
                        <div class="mb-2">
                            <label class="form-label">Subject</label>
                            <select name="subject_id" class="form-select" required>
                                <option value="">Select subject</option>
                                <?php foreach ($subjects as $s): ?>
                                    <option value="<?= (int)$s['id'] ?>"><?= htmlspecialchars($s['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-2">
                            <label class="form-label">Question Text</label>
                            <textarea name="question_text" class="form-control" rows="3" required></textarea>
                        </div>
                        <div class="row">
                            <div class="col-sm-6 mb-2">
                                <label class="form-label">Option A</label>
                                <input type="text" name="option_a" class="form-control" required>
                            </div>
                            <div class="col-sm-6 mb-2">
                                <label class="form-label">Option B</label>
                                <input type="text" name="option_b" class="form-control" required>
                            </div>
                            <div class="col-sm-6 mb-2">
                                <label class="form-label">Option C</label>
                                <input type="text" name="option_c" class="form-control" required>
                            </div>
                            <div class="col-sm-6 mb-2">
                                <label class="form-label">Option D</label>
                                <input type="text" name="option_d" class="form-control" required>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-sm-6 mb-2">
                                <label class="form-label">Correct Option</label>
                                <select name="correct_option" class="form-select" required>
                                    <option value="A">A</option>
                                    <option value="B">B</option>
                                    <option value="C">C</option>
                                    <option value="D">D</option>
                                </select>
                            </div>
                            <div class="col-sm-6 mb-2">
                                <label class="form-label">Marks</label>
                                <input type="number" name="marks" class="form-control" value="1" min="1">
                            </div>
                        </div>
                        <button class="btn btn-primary w-100 mt-2">Add Question</button>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-lg-3">
            <div class="card shadow-sm">
                <div class="card-body">
                    <h5 class="card-title">Analytics Dashboard</h5>
                    <p class="small text-muted">Subject-wise average score and attempts.</p>
                    <?php if (!$analytics): ?>
                        <p class="text-muted">No data yet.</p>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-sm align-middle">
                                <thead>
                                <tr>
                                    <th>Subject</th>
                                    <th>Avg Score</th>
                                    <th>Attempts</th>
                                </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($analytics as $row): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($row['subject_name']) ?></td>
                                        <td><?= number_format($row['avg_score'], 1) ?>%</td>
                                        <td><?= (int)$row['attempts'] ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

