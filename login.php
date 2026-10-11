<?php
require_once __DIR__ . '/config.php';

// Jika sudah login, redirect langsung ke index.php
if (isLoggedIn()) {
    redirect('index.php');
}

$error = '';
$success = '';

if (isset($_GET['registered'])) {
    $success = 'Registrasi berhasil! Silakan login dengan akun Anda.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');

    if ($username === '' || $password === '') {
        $error = 'Username dan password wajib diisi!';
    } else {
        try {
            $pdo = getDBConnection();
            $stmt = $pdo->prepare("SELECT * FROM users WHERE LOWER(username) = LOWER(:username) LIMIT 1");
            $stmt->execute([':username' => $username]);
            $user = $stmt->fetch();

            // Cek kecocokan password: via password_verify ATAU default admin
            $isValid = false;
            if ($user) {
                if (password_verify($password, $user['password']) || 
                    ($user['username'] === 'admin' && $password === 'password')) {
                    $isValid = true;
                }
            } elseif ($username === 'admin' && $password === 'password') {
                // Auto create dummy admin jika belum ada di tabel users
                $hashed = password_hash('password', PASSWORD_BCRYPT);
                $ins = $pdo->prepare("INSERT INTO users (username, password, role) VALUES ('admin', :pwd, 'ADMIN') RETURNING id, username, role");
                $ins->execute([':pwd' => $hashed]);
                $user = $ins->fetch();
                $isValid = true;
            }

            if ($isValid && $user) {
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['user_role'] = strtoupper($user['role']);
                redirect('index.php');
            } else {
                $error = 'Username atau password salah!';
            }
        } catch (Exception $e) {
            $error = 'Gagal memproses login: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - FTS Amazon Reviews</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <div class="container" style="display:flex; align-items:center; justify-content:center; min-height:85vh;">
        <div class="auth-box">
            <div class="auth-header">
                <div class="auth-brand-badge">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
                </div>
                <h1>Masuk</h1>
                <p>PostgreSQL Full-Text Search Amazon Review Explorer</p>
            </div>

            <?php if ($error): ?>
                <div class="alert alert-danger">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>
                    <div><?= htmlspecialchars($error) ?></div>
                </div>
            <?php endif; ?>

            <?php if ($success): ?>
                <div class="alert alert-success">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
                    <div><?= htmlspecialchars($success) ?></div>
                </div>
            <?php endif; ?>

            <form method="POST" action="login.php">
                <div class="form-group">
                    <label for="username">Username</label>
                    <input type="text" id="username" name="username" class="form-control" required autofocus placeholder="Masukkan username">
                </div>
                <div class="form-group">
                    <label for="password">Password</label>
                    <input type="password" id="password" name="password" class="form-control" required placeholder="Masukkan password">
                </div>
                <button type="submit" class="btn-primary" style="width: 100%; margin-top:0.5rem;">
                    Masuk
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="9 18 15 12 9 6"></polyline></svg>
                </button>
            </form>

            <div class="auth-footer">
                Belum punya akun? <a href="register.php">Daftar Akun Baru</a>
            </div>
        </div>
    </div>
</body>
</html>
