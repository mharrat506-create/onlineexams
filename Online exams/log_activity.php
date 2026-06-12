<?php
require_once __DIR__ . '/includes/config.php';
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !is_logged_in()) {
    http_response_code(403);
    echo json_encode(['status' => 'error']);
    exit;
}

$raw = file_get_contents('php://input');
if (!$raw) {
    echo json_encode(['status' => 'ok']);
    exit;
}

try {
    $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
} catch (Throwable $e) {
    echo json_encode(['status' => 'ok']);
    exit;
}

$exam_id = isset($data['exam_id']) ? (int)$data['exam_id'] : 0;
$type = substr((string)($data['type'] ?? ''), 0, 50);
$details = substr((string)($data['details'] ?? ''), 0, 500);
$csrf = $data['csrf_token'] ?? null;

if (!verify_csrf_token(is_string($csrf) ? $csrf : null)) {
    http_response_code(400);
    echo json_encode(['status' => 'invalid_csrf']);
    exit;
}

if ($exam_id && $type) {
    $stmt = $pdo->prepare('
        INSERT INTO exam_activity_logs (user_id, exam_id, event_type, event_details)
        VALUES (?, ?, ?, ?)
    ');
    $stmt->execute([$_SESSION['user_id'], $exam_id, $type, $details]);
}

echo json_encode(['status' => 'ok']);

