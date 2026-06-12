<?php
require_once __DIR__ . '/includes/config.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: select_exam.php');
    exit;
}

$exam_id = (int)($_POST['exam_id'] ?? 0);
// Relaxed validation so auto-submits from anti-cheat cannot fail due to token mismatch.
if (!$exam_id || !is_logged_in()) {
    die('Invalid exam submission.');
}

$stmt = $pdo->prepare('
    SELECT exams.*, subjects.name AS subject_name
    FROM exams
    JOIN subjects ON exams.subject_id = subjects.id
    WHERE exams.id = ? LIMIT 1
');
$stmt->execute([$exam_id]);
$exam = $stmt->fetch();
if (!$exam) {
    die('Exam not found.');
}

$scopeKey = 'exam_answers_scope_' . $exam_id;
$scopedIds = $_SESSION[$scopeKey] ?? null;
unset($_SESSION[$scopeKey]);

if (!is_array($scopedIds) || $scopedIds === []) {
    die('Exam session expired or invalid. Please start the exam again from the exam list.');
}

$scopedIds = array_values(array_unique(array_map('intval', $scopedIds)));
$placeholders = implode(',', array_fill(0, count($scopedIds), '?'));

// Only score the questions that were actually shown for this attempt
$qStmt = $pdo->prepare(
    "SELECT id, correct_option, marks FROM questions WHERE subject_id = ? AND id IN ($placeholders)"
);
$qStmt->execute(array_merge([(int)$exam['subject_id']], $scopedIds));
$questions = $qStmt->fetchAll();

if (count($questions) !== count($scopedIds)) {
    die('Could not verify exam questions. Please start the exam again.');
}

$answers = $_POST['answers'] ?? [];
$totalQuestions = count($questions);
$correctCount = 0;
$totalMarks = 0;
$scoredMarks = 0;

foreach ($questions as $q) {
    $totalMarks += (int)$q['marks'];
    $given = $answers[$q['id']] ?? null;
    if ($given && strtoupper($given) === $q['correct_option']) {
        $correctCount++;
        $scoredMarks += (int)$q['marks'];
    }
}

$scorePercent = $totalMarks > 0 ? ($scoredMarks / $totalMarks) * 100 : 0;
$passed = $scorePercent >= 70 ? 1 : 0;

$ins = $pdo->prepare('
    INSERT INTO results (user_id, exam_id, total_questions, correct_answers, score_percent, passed)
    VALUES (?, ?, ?, ?, ?, ?)
');
$ins->execute([
    $_SESSION['user_id'],
    $exam_id,
    $totalQuestions,
    $correctCount,
    $scorePercent,
    $passed
]);

$result_id = (int)$pdo->lastInsertId();

header('Location: view_result.php?id=' . $result_id);
exit;

