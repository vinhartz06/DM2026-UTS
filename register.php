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
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="8.5" cy="7" r="4"></circle><line x1="20" y1="8" x2="20" y2="14"></line><line x1="23" y1="11" x2="17" y2="11"></line></svg>
                </div>
                <h1>Daftar</h1>
                <p>Mulai eksplorasi pencarian review Amazon</p>
            </div>

            <?php if ($error): ?>
                <div class="alert alert-danger">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>
                    <div><?= htmlspecialchars($error) ?></div>
                </div>
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
                <button type="submit" class="btn-primary" style="width: 100%; margin-top:0.5rem;">
                    Daftar
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="9 18 15 12 9 6"></polyline></svg>
                </button>
            </form>

            <div class="auth-footer">
                Sudah memiliki akun? <a href="login.php">Masuk di Sini</a>
            </div>
        </div>
    </div>
</body>
</html>
