<?php
require_once __DIR__ . '/config.php';

// Jika sudah login, redirect langsung ke index.php
if (isLoggedIn()) {
    redirect('index.php');
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');
    $password_confirm = trim($_POST['password_confirm'] ?? '');

    if ($username === '' || $password === '') {
        $error = 'Username dan password wajib diisi!';
    } elseif (strlen($username) < 3) {
        $error = 'Username minimal 3 karakter!';
    } elseif ($password !== $password_confirm) {
        $error = 'Konfirmasi password tidak cocok!';
    } else {
        try {
            $pdo = getDBConnection();
            
            // Cek apakah username sudah ada
            $check = $pdo->prepare("SELECT id FROM users WHERE LOWER(username) = LOWER(:username)");
            $check->execute([':username' => $username]);
            if ($check->fetch()) {
                $error = 'Username sudah digunakan, silakan pilih username lain.';
            } else {
                $hashed = password_hash($password, PASSWORD_BCRYPT);
                $ins = $pdo->prepare("INSERT INTO users (username, password, role) VALUES (:username, :password, 'USER')");
                $ins->execute([
                    ':username' => $username,
                    ':password' => $hashed
                ]);

                redirect('login.php?registered=1');
            }
        } catch (Exception $e) {
            $error = 'Gagal mendaftar: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register - FTS Amazon Reviews</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <div class="container">
        <div class="auth-box">
            <div class="auth-header">
                <h1>Daftar Akun Baru</h1>
                <p>Mulai eksplorasi pencarian review Amazon</p>
            </div>

            <?php if ($error): ?>
                <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <form method="POST" action="register.php">
                <div class="form-group">
                    <label for="username">Username</label>
                    <input type="text" id="username" name="username" class="form-control" required autofocus placeholder="Contoh: user123">
                </div>
                <div class="form-group">
                    <label for="password">Password</label>
                    <input type="password" id="password" name="password" class="form-control" required placeholder="Minimal 4 karakter">
                </div>
                <div class="form-group">
                    <label for="password_confirm">Konfirmasi Password</label>
                    <input type="password" id="password_confirm" name="password_confirm" class="form-control" required placeholder="Ulangi password">
                </div>
                <button type="submit" class="btn-primary" style="width: 100%;">Daftar Sekarang</button>
            </form>

            <div class="auth-footer">
                Sudah memiliki akun? <a href="login.php">Masuk di Sini</a>
            </div>
        </div>
    </div>
</body>
</html>
