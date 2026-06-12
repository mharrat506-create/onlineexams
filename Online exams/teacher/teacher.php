<?php
require_once __DIR__ . '/../includes/config.php';
require_teacher();
require_post_csrf();

$teacherId = get_teacher_id($pdo);
if (!$teacherId) {
    die('Teacher profile not found.');
}

// Add subject for this teacher's courses
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['new_subject'])) {
    $name = trim($_POST['subject_name'] ?? '');
    $desc = trim($_POST['subject_desc'] ?? '');
    if ($name) {
        $stmt = $pdo->prepare('INSERT INTO subjects (name, description, teacher_id) VALUES (?, ?, ?)');
        $stmt->execute([$name, $desc, $teacherId]);
    }
    header('Location: teacher.php');
    exit;
}

// Add question
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['new_question'])) {
    $subject_id = (int)($_POST['subject_id'] ?? 0);
    $text = trim($_POST['question_text'] ?? '');
    $a = trim($_POST['option_a'] ?? '');
    $b = trim($_POST['option_b'] ?? '');
    $c = trim($_POST['option_c'] ?? '');
    $d = trim($_POST['option_d'] ?? '');
    $correct = $_POST['correct_option'] ?? '';
    $marks = (int)($_POST['marks'] ?? 1);
    if ($subject_id && teacher_owns_subject($pdo, $teacherId, $subject_id) && $text && $a && $b && $c && $d && in_array($correct, ['A', 'B', 'C', 'D'], true)) {
        $stmt = $pdo->prepare('
            INSERT INTO questions (subject_id, question_text, option_a, option_b, option_c, option_d, correct_option, marks)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ');
        $stmt->execute([$subject_id, $text, $a, $b, $c, $d, $correct, $marks]);
    }
    header('Location: teacher.php');
    exit;
}

// Toggle exam active
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_exam'])) {
    $exam_id = (int)($_POST['exam_id'] ?? 0);
    $is_active = (int)($_POST['is_active'] ?? 0) ? 1 : 0;
    if ($exam_id && teacher_owns_exam($pdo, $teacherId, $exam_id)) {
        $stmt = $pdo->prepare('UPDATE exams SET is_active = ? WHERE id = ? AND teacher_id = ?');
        $stmt->execute([$is_active, $exam_id, $teacherId]);
    }
    header('Location: teacher.php');
    exit;
}

// Extend expired exam
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['extend_exam'])) {
    $exam_id = (int)($_POST['exam_id'] ?? 0);
    if ($exam_id && teacher_owns_exam($pdo, $teacherId, $exam_id)) {
        $stmt = $pdo->prepare('UPDATE exams SET end_time = NOW() + INTERVAL 1 YEAR WHERE id = ? AND teacher_id = ?');
        $stmt->execute([$exam_id, $teacherId]);
    }
    header('Location: teacher.php');
    exit;
}

// Create exam
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
    if ($subject_id && teacher_owns_subject($pdo, $teacherId, $subject_id) && $title && $duration > 0) {
        $stmt = $pdo->prepare('
            INSERT INTO exams
                (subject_id, title, duration_minutes, start_time, end_time, questions_per_exam, shuffle_questions, shuffle_options, class_id, teacher_id)
            VALUES (?, ?, ?, NULLIF(?, \'\'), NULLIF(?, \'\'), ?, ?, ?, NULLIF(?, 0), ?)
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
            $teacherId,
        ]);
    }
    header('Location: teacher.php');
    exit;
}

$subjectsStmt = $pdo->prepare('
    SELECT s.*,
           (SELECT COUNT(*) FROM questions q WHERE q.subject_id = s.id) AS question_count,
           (SELECT COUNT(*) FROM exams e WHERE e.subject_id = s.id AND e.teacher_id = ?) AS exam_count
    FROM subjects s
    WHERE s.teacher_id = ?
    ORDER BY s.name
');
$subjectsStmt->execute([$teacherId, $teacherId]);
$subjects = $subjectsStmt->fetchAll();
$classes = $pdo->query('SELECT c.*, d.name AS department_name FROM classes c LEFT JOIN departments d ON c.department_id = d.id ORDER BY c.name')->fetchAll();

$examsStmt = $pdo->prepare('
    SELECT e.id,
           e.title,
           e.duration_minutes,
           e.start_time,
           e.end_time,
           e.is_active,
           s.name AS subject_name,
           (SELECT COUNT(*) FROM questions q WHERE q.subject_id = e.subject_id) AS question_count,
           (SELECT COUNT(*) FROM results r WHERE r.exam_id = e.id) AS attempt_count,
           (SELECT COALESCE(AVG(r.score_percent), 0) FROM results r WHERE r.exam_id = e.id) AS avg_score
    FROM exams e
    JOIN subjects s ON e.subject_id = s.id
    WHERE e.teacher_id = ?
    ORDER BY e.created_at DESC, e.title
');
$examsStmt->execute([$teacherId]);
$myExams = $examsStmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Teacher Dashboard - Online Exam</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<nav class="navbar navbar-expand-lg navbar-dark bg-primary">
    <div class="container-fluid">
        <span class="navbar-brand">Teacher Dashboard</span>
        <div class="d-flex align-items-center text-white gap-2">
            <span class="me-2"><?= htmlspecialchars($_SESSION['name'] ?? '') ?></span>
            <a href="../select_exam.php" class="btn btn-outline-light btn-sm">Preview Exams</a>
            <a href="../logout.php" class="btn btn-outline-light btn-sm">Logout</a>
        </div>
    </div>
</nav>

<div class="container py-4">
    <div class="card shadow-sm mb-4">
        <div class="card-body">
            <h5 class="card-title">My Exams</h5>
            <p class="small text-muted mb-3">Exams you create appear here. Students see active exams within the start/end window.</p>
            <?php if (!$myExams): ?>
                <p class="text-muted mb-0">You have not created any exams yet. Use the form below to add one.</p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead>
                        <tr>
                            <th>Title</th>
                            <th>Subject</th>
                            <th>Duration</th>
                            <th>Questions</th>
                            <th>Attempts</th>
                            <th>Avg Score</th>
                            <th>Window</th>
                            <th>Status</th>
                            <th></th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($myExams as $exam): ?>
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
                                <td><?= (int)$exam['attempt_count'] ?></td>
                                <td><?= number_format((float)$exam['avg_score'], 1) ?>%</td>
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
                                    <a href="exam_results.php?exam_id=<?= (int)$exam['id'] ?>" class="btn btn-outline-info btn-sm">Results</a>
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

    <div class="card shadow-sm mb-4">
        <div class="card-body">
            <h5 class="card-title">My Subjects</h5>
            <p class="small text-muted mb-3">You only see and manage subjects you create. Other teachers' subjects are hidden.</p>
            <?php if (!$subjects): ?>
                <p class="text-muted mb-0">No subjects yet. Add your first subject below.</p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead>
                        <tr>
                            <th>Name</th>
                            <th>Questions</th>
                            <th>Exams</th>
                            <th>Description</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($subjects as $s): ?>
                            <tr>
                                <td><?= htmlspecialchars($s['name']) ?></td>
                                <td><?= (int)$s['question_count'] ?></td>
                                <td><?= (int)$s['exam_count'] ?></td>
                                <td class="small text-muted"><?= htmlspecialchars($s['description'] ?? '—') ?></td>
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
                    <h5 class="card-title">Add Subject</h5>
                    <p class="small text-muted">Create a subject for your course before adding questions and exams.</p>
                    <form method="post">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(get_csrf_token()) ?>">
                        <input type="hidden" name="new_subject" value="1">
                        <div class="mb-2">
                            <label class="form-label">Name</label>
                            <input type="text" name="subject_name" class="form-control" required placeholder="e.g. Mathematics">
                        </div>
                        <div class="mb-2">
                            <label class="form-label">Description (optional)</label>
                            <textarea name="subject_desc" class="form-control" rows="2"></textarea>
                        </div>
                        <button class="btn btn-secondary w-100">Save Subject</button>
                    </form>
                </div>
            </div>

            <div class="card shadow-sm">
                <div class="card-body">
                    <h5 class="card-title">Create Exam</h5>
                    <?php if (!$subjects): ?>
                        <div class="alert alert-warning mb-0">Add a subject first before creating an exam.</div>
                    <?php else: ?>
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
                            <input type="text" name="exam_title" class="form-control" required placeholder="e.g. Midterm Exam">
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
                            <div class="form-text">Leave blank to keep the exam always open.</div>
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
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="col-lg-8">
            <div class="card shadow-sm">
                <div class="card-body">
                    <h5 class="card-title">Question Bank</h5>
                    <p class="small text-muted">Add multiple-choice questions for your subject. Students will get these during the exam.</p>
                    <?php if (!$subjects): ?>
                        <div class="alert alert-warning">Add a subject first before creating questions.</div>
                    <?php else: ?>
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
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
