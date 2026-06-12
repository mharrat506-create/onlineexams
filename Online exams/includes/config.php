<?php
// Database config - detect environment
$environment = getenv('RENDER_EXTERNAL_URL') ? 'production' : 'local';

if ($environment === 'production') {
    // Render.com deployment - use environment variables
    $db_host = getenv('DB_HOST') ?: 'localhost';
    $db_name = getenv('DB_NAME') ?: 'online_exam';
    $db_user = getenv('DB_USER') ?: 'root';
    $db_pass = getenv('DB_PASS') ?: '';
    $db_port = getenv('DB_PORT') ?: '3306';
} else {
    // Local XAMPP development
    $db_host = 'localhost';
    $db_name = 'online_exam';
    $db_user = 'root';
    $db_pass = '';
    $db_port = '3306';
}

$dsn = "mysql:host=$db_host;port=$db_port;dbname=$db_name;charset=utf8mb4";

try {
    $pdo = new PDO($dsn, $db_user, $db_pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_TIMEOUT => 10,
    ]);
} catch (PDOException $e) {
    die('Database connection failed: ' . htmlspecialchars($e->getMessage()));
}

session_start();

function is_logged_in(): bool {
    return isset($_SESSION['user_id']);
}

function require_login(): void {
    if (!is_logged_in()) {
        header('Location: index.php');
        exit;
    }
}

function is_admin(): bool {
    return isset($_SESSION['role']) && $_SESSION['role'] === 'admin';
}

function is_teacher(): bool {
    return isset($_SESSION['role']) && $_SESSION['role'] === 'teacher';
}

function require_teacher(): void {
    require_login();
    if (!is_teacher()) {
        die('Teachers only.');
    }
}

function get_teacher_id(PDO $pdo): ?int {
    if (!is_teacher() || empty($_SESSION['user_id'])) {
        return null;
    }
    $stmt = $pdo->prepare('SELECT id FROM teachers WHERE user_id = ? LIMIT 1');
    $stmt->execute([$_SESSION['user_id']]);
    $row = $stmt->fetch();
    if ($row) {
        return (int)$row['id'];
    }
    $stmt = $pdo->prepare('INSERT INTO teachers (user_id) VALUES (?)');
    $stmt->execute([$_SESSION['user_id']]);
    return (int)$pdo->lastInsertId();
}

function teacher_owns_subject(PDO $pdo, int $teacherId, int $subjectId): bool {
    $stmt = $pdo->prepare('SELECT id FROM subjects WHERE id = ? AND teacher_id = ? LIMIT 1');
    $stmt->execute([$subjectId, $teacherId]);
    return (bool)$stmt->fetch();
}

function teacher_owns_exam(PDO $pdo, int $teacherId, int $examId): bool {
    $stmt = $pdo->prepare('SELECT id FROM exams WHERE id = ? AND teacher_id = ? LIMIT 1');
    $stmt->execute([$examId, $teacherId]);
    return (bool)$stmt->fetch();
}

function get_csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['csrf_token'];
}

function verify_csrf_token(?string $token): bool {
    return isset($_SESSION['csrf_token']) && is_string($token) && hash_equals($_SESSION['csrf_token'], $token);
}

function require_post_csrf(): void {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $token = $_POST['csrf_token'] ?? '';
        if (!verify_csrf_token($token)) {
            http_response_code(400);
            die('Invalid CSRF token.');
        }
    }
}
