<?php
require_once __DIR__ . '/includes/config.php';

// Simple login / registration on same page for demo
$error = '';
// Basic in-session rate limiting for login attempts
if (!isset($_SESSION['login_attempts'])) {
    $_SESSION['login_attempts'] = ['count' => 0, 'first_time' => time()];
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $windowSeconds = 300;
    $limit = 10;
    $la = &$_SESSION['login_attempts'];
    if (time() - $la['first_time'] > $windowSeconds) {
        $la = ['count' => 0, 'first_time' => time()];
    }
    if ($la['count'] >= $limit && ($_POST['action'] ?? '') === 'login') {
        $error = 'Too many login attempts. Please wait a few minutes.';
    }
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        $error = 'Invalid session. Please refresh and try again.';
    } elseif (isset($_POST['action']) && $_POST['action'] === 'login') {
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';

        $stmt = $pdo->prepare('SELECT * FROM users WHERE email = ? LIMIT 1');
        $stmt->execute([$email]);
        $user = $stmt->fetch();
        if ($user && password_verify($password, $user['password_hash'])) {
            $_SESSION['login_attempts'] = ['count' => 0, 'first_time' => time()];
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['name'] = $user['name'];
            $_SESSION['role'] = $user['role'];
            if ($user['role'] === 'admin') {
                header('Location: admin/admin.php');
            } elseif ($user['role'] === 'teacher') {
                get_teacher_id($pdo);
                header('Location: teacher/teacher.php');
            } else {
                header('Location: select_exam.php');
            }
            exit;
        } else {
            $error = 'Invalid credentials.';
            $_SESSION['login_attempts']['count']++;
        }
    } elseif (isset($_POST['action']) && $_POST['action'] === 'register') {
        $name = trim($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $role = ($_POST['role'] ?? 'student') === 'teacher' ? 'teacher' : 'student';

        if ($name && $email && $password) {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            try {
                $stmt = $pdo->prepare('INSERT INTO users (name, email, password_hash, role) VALUES (?, ?, ?, ?)');
                $stmt->execute([$name, $email, $hash, $role]);
                if ($role === 'teacher') {
                    $userId = (int)$pdo->lastInsertId();
                    $stmt = $pdo->prepare('INSERT INTO teachers (user_id) VALUES (?)');
                    $stmt->execute([$userId]);
                }
                $success = $role === 'teacher'
                    ? 'Teacher account created. Please log in to create your exams.'
                    : 'Account created. Please log in.';
            } catch (PDOException $e) {
                $error = 'Could not create account (email may already exist).';
            }
        } else {
            $error = 'Please fill in all fields.';
        }
    }
}
$csrf = get_csrf_token();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Online Exam System - Login</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-lg-5">
            <div class="card shadow-sm">
                <div class="card-body">
                    <h2 class="h4 mb-3 text-center">Online Exam System</h2>
                    <?php if (!empty($error)): ?>
                        <div class="alert alert-danger py-2"><?= htmlspecialchars($error) ?></div>
                    <?php endif; ?>
                    <?php if (!empty($success ?? '')): ?>
                        <div class="alert alert-success py-2"><?= htmlspecialchars($success) ?></div>
                    <?php endif; ?>
                    <ul class="nav nav-tabs mb-3" id="authTabs">
                        <li class="nav-item">
                            <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#loginTab">Login</button>
                        </li>
                        <li class="nav-item">
                            <button class="nav-link" data-bs-toggle="tab" data-bs-target="#registerTab">Register</button>
                        </li>
                    </ul>
                    <div class="tab-content">
                        <div class="tab-pane fade show active" id="loginTab">
                            <form method="post">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                                <input type="hidden" name="action" value="login">
                                <div class="mb-3">
                                    <label class="form-label">Email</label>
                                    <input type="email" name="email" class="form-control" required>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Password</label>
                                    <input type="password" name="password" class="form-control" required>
                                </div>
                                <button type="submit" class="btn btn-primary w-100">Login</button>
                            </form>
                        </div>
                        <div class="tab-pane fade" id="registerTab">
                            <form method="post">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                                <input type="hidden" name="action" value="register">
                                <div class="mb-3">
                                    <label class="form-label">Full Name</label>
                                    <input type="text" name="name" class="form-control" required>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Email</label>
                                    <input type="email" name="email" class="form-control" required>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Password</label>
                                    <input type="password" name="password" class="form-control" required>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Register as</label>
                                    <select name="role" class="form-select">
                                        <option value="student">Student</option>
                                        <option value="teacher">Teacher</option>
                                    </select>
                                </div>
                                <button type="submit" class="btn btn-success w-100">Register</button>
                            </form>
                        </div>
                    </div>
                    <p class="small text-muted mt-3 mb-0 text-center">
                        Demo accounts (password: <strong>admin123</strong>):<br>
                        Admin — <strong>admin@onlineexam.local</strong><br>
                        Teacher — <strong>teacher@onlineexam.local</strong>
                    </p>
                </div>
            </div>
        </div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

