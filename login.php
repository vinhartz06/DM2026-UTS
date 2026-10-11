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
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <div class="container">
        <div class="auth-box">
            <div class="auth-header">
                <h1>Masuk ke Sistem</h1>
                <p>PostgreSQL Full-Text Search Review Explorer</p>
            </div>

            <?php if ($error): ?>
                <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <?php if ($success): ?>
                <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
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
                <button type="submit" class="btn-primary" style="width: 100%;">Masuk</button>
            </form>

            <div class="auth-footer">
                Belum punya akun? <a href="register.php">Daftar Akun Baru</a>
            </div>
        </div>
    </div>

    <script>
        function fillAdmin() {
            document.getElementById('username').value = 'admin';
            document.getElementById('password').value = 'password';
        }
    </script>
</body>
</html>
