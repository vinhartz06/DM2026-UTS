<?php
/**
 * ============================================================================
 * KONFIGURASI DATABASE & APLIKASI
 * ============================================================================
 * Anda dapat menyesuaikan konfigurasi database PostgreSQL di bawah ini:
 */

define('DB_HOST', 'localhost');
define('DB_PORT', '5432');
define('DB_NAME', 'dbuts');
define('DB_USER', 'dm');
define('DB_PASS', 'dm'); // <<-- GANTI PASSWORD POSTGRESQL ANDA DI SINI JIKA BERBEDA

// Inisialisasi Session jika belum aktif
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * Mendapatkan koneksi PDO PostgreSQL
 */
function getDBConnection() {
    static $pdo = null;
    if ($pdo === null) {
        // Cek ekstensi pdo_pgsql
        if (!extension_loaded('pdo_pgsql')) {
            die("
                <div style='font-family:sans-serif; max-width:600px; margin:40px auto; padding:20px; border:2px solid #ef4444; border-radius:8px; background:#fef2f2; color:#991b1b;'>
                    <h2 style='margin-top:0;'>Ekstensi pdo_pgsql Belum Aktif di PHP!</h2>
                    <p>Silakan aktifkan ekstensi <strong>pdo_pgsql</strong> pada file <code>php.ini</code> Anda:</p>
                    <ol>
                        <li>Buka file <code>php.ini</code> (atau via menu Laragon -> PHP -> Extensions).</li>
                        <li>Hapus tanda titik koma (<code>;</code>) pada baris <code>extension=pdo_pgsql</code> dan <code>extension=pgsql</code>.</li>
                        <li>Restart server web / Laragon Anda.</li>
                    </ol>
                </div>
            ");
        }

        try {
            $dsn = sprintf("pgsql:host=%s;port=%s;dbname=%s", DB_HOST, DB_PORT, DB_NAME);
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        } catch (PDOException $e) {
            die("
                <div style='font-family:sans-serif; max-width:600px; margin:40px auto; padding:20px; border:2px solid #f59e0b; border-radius:8px; background:#fffbeb; color:#92400e;'>
                    <h2 style='margin-top:0;'>Gagal Terhubung ke PostgreSQL</h2>
                    <p>Pastikan PostgreSQL berjalan dan database sudah dibuat.</p>
                    <p><strong>Pesan Error:</strong> " . htmlspecialchars($e->getMessage()) . "</p>
                    <hr style='border:0; border-top:1px solid #fcd34d; margin:15px 0;'>
                    <p><em>Periksa konfigurasi di file <code>config.php</code> (Host, Port, User, Password, DB Name).</em></p>
                </div>
            ");
        }
    }
    return $pdo;
}

/**
 * Cek apakah user sedang login
 */
function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

/**
 * Cek apakah user memiliki hak akses ADMIN
 */
function isAdmin() {
    return isset($_SESSION['user_role']) && strtoupper($_SESSION['user_role']) === 'ADMIN';
}

/**
 * Redirect halaman
 */
function redirect($url) {
    header("Location: $url");
    exit;
}

/**
 * Validasi otentikasi login
 */
function requireLogin() {
    if (!isLoggedIn()) {
        redirect('login.php');
    }
}

/**
 * Validasi otentikasi admin
 */
function requireAdmin() {
    requireLogin();
    if (!isAdmin()) {
        die("
            <div style='font-family:sans-serif; max-width:500px; margin:50px auto; text-align:center; padding:30px; border-radius:10px; background:#f8fafc; border:1px solid #e2e8f0;'>
                <h2 style='color:#ef4444;'>Akses Ditolak</h2>
                <p>Halaman ini hanya dapat diakses oleh user dengan role <strong>ADMIN</strong>.</p>
                <a href='index.php' style='display:inline-block; margin-top:15px; padding:10px 20px; background:#3b82f6; color:#fff; text-decoration:none; border-radius:6px;'>Kembali ke Pencarian</a>
            </div>
        ");
    }
}
