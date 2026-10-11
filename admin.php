<?php
require_once __DIR__ . '/config.php';
requireAdmin();

$pdo = getDBConnection();
$message = '';

// Handle Clear Logs
if (isset($_POST['action']) && $_POST['action'] === 'clear_logs') {
    try {
        $pdo->exec("TRUNCATE TABLE search_logs");
        $message = 'Riwayat log query berhasil dibersihkan.';
    } catch (Exception $e) {
        $message = 'Gagal membersihkan log: ' . $e->getMessage();
    }
}

// Fitur Interaktif: Live Benchmark & EXPLAIN ANALYZE Tester (Untuk Laporan UTS)
$benchKeyword = trim($_GET['bench_q'] ?? '');
$benchIndex = $_GET['bench_index'] ?? 'gin'; // 'seq', 'gin', 'gist'
$benchResult = '';
$benchTime = 0;

if ($benchKeyword !== '') {
    try {
        $pdo->beginTransaction();

        // Konfigurasi optimizer PostgreSQL untuk simulasi
        if ($benchIndex === 'seq') {
            // Paksa Sequential Scan (Tanpa Index)
            $pdo->exec("SET LOCAL enable_seqscan = on;");
            $pdo->exec("SET LOCAL enable_indexscan = off;");
            $pdo->exec("SET LOCAL enable_bitmapscan = off;");
        } else {
            // Aktifkan Index Scan / Bitmap Scan
            $pdo->exec("SET LOCAL enable_seqscan = off;");
            $pdo->exec("SET LOCAL enable_indexscan = on;");
            $pdo->exec("SET LOCAL enable_bitmapscan = on;");
        }

        $benchStart = microtime(true);
        $testSql = "
            EXPLAIN (ANALYZE, BUFFERS, TIMING)
            SELECT id, review_title, review_text, ts_rank(search_vector, to_tsquery('english', :q)) AS rank
            FROM amazon_reviews
            WHERE search_vector @@ to_tsquery('english', :q)
            ORDER BY rank DESC
            LIMIT 15;
        ";
        $testStmt = $pdo->prepare($testSql);
        $testStmt->bindValue(':q', $benchKeyword, PDO::PARAM_STR);
        $testStmt->execute();
        $planRows = $testStmt->fetchAll(PDO::FETCH_COLUMN);
        $benchResult = implode("\n", $planRows);
        $benchTime = round((microtime(true) - $benchStart) * 1000, 2);

        $pdo->commit();
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $benchResult = "Gagal menjalankan EXPLAIN ANALYZE: " . $e->getMessage();
    }
}

// Ambil Statistik Ringkasan
$totalReviews = 0;
$totalLogs = 0;
$avgExecTime = 0;

try {
    $totalReviews = (int)$pdo->query("SELECT COUNT(*) FROM amazon_reviews")->fetchColumn();
    $totalLogs = (int)$pdo->query("SELECT COUNT(*) FROM search_logs")->fetchColumn();
    $avgExecTime = round((float)$pdo->query("SELECT COALESCE(AVG(execution_time_ms), 0) FROM search_logs")->fetchColumn(), 2);
} catch (Exception $e) {
    // Tabel mungkin baru dibuat
}

// Ambil Data Log Query Terbaru
$logs = [];
try {
    $logQuery = "
        SELECT id, username, search_query, search_mode, ranking_method, 
               results_count, execution_time_ms, explain_analyze, created_at
        FROM search_logs
        ORDER BY id DESC
        LIMIT 50
    ";
    $logs = $pdo->query($logQuery)->fetchAll();
} catch (Exception $e) {
    // Abaikan jika belum ada log
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard & Query Logs - FTS PostgreSQL</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <!-- NAVBAR -->
    <nav class="navbar">
        <a href="index.php" class="nav-brand">
            <span class="nav-brand-badge">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
            </span>
            <span>Amazon Reviews FTS</span>
        </a>
        <div class="nav-links">
            <a href="index.php" style="display:inline-flex; align-items:center; gap:0.4rem;">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="15 18 9 12 15 6"></polyline></svg>
                Kembali ke Pencarian
            </a>
            <span class="badge-admin">ADMINISTRATOR</span>
            <a href="logout.php" class="btn-logout">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path><polyline points="16 17 21 12 16 7"></polyline><line x1="21" y1="12" x2="9" y2="12"></line></svg>
                Keluar
            </a>
        </div>
    </nav>

    <div class="container">
        <!-- HEADER -->
        <div class="card-header" style="margin-top: 1rem;">
            <div>
                <h1 style="font-size: 1.85rem; font-weight: 800; color:var(--text-main); letter-spacing:-0.025em;">Dashboard Analisis & Log Query</h1>
                <p style="color: var(--text-muted); font-size: 0.95rem; margin-top:0.25rem;">Monitoring Performa Query, EXPLAIN ANALYZE, dan Optimasi Index (GIN vs GiST)</p>
            </div>
        </div>

        <?php if ($message): ?>
            <div class="alert alert-success">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
                <div><?= htmlspecialchars($message) ?></div>
            </div>
        <?php endif; ?>

        <!-- STATS OVERVIEW -->
        <div class="stats-grid">
            <div class="stat-card">
                <div style="display:flex; justify-content:space-between; align-items:flex-start;">
                    <div>
                        <div class="stat-label">Total Data Review (CSV)</div>
                        <div class="stat-value"><?= number_format($totalReviews) ?></div>
                    </div>
                    <div style="width:40px; height:40px; border-radius:10px; background:#eef2ff; color:var(--primary); display:flex; align-items:center; justify-content:center;">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><ellipse cx="12" cy="5" rx="9" ry="3"></ellipse><path d="M21 12c0 1.66-4 3-9 3s-9-1.34-9-3"></path><path d="M3 5v14c0 1.66 4 3 9 3s9-1.34 9-3V5"></path></svg>
                    </div>
                </div>
            </div>
            <div class="stat-card">
                <div style="display:flex; justify-content:space-between; align-items:flex-start;">
                    <div>
                        <div class="stat-label">Total Pencarian Dilakukan</div>
                        <div class="stat-value"><?= number_format($totalLogs) ?></div>
                    </div>
                    <div style="width:40px; height:40px; border-radius:10px; background:#eff6ff; color:#2563eb; display:flex; align-items:center; justify-content:center;">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
                    </div>
                </div>
            </div>
            <div class="stat-card">
                <div style="display:flex; justify-content:space-between; align-items:flex-start;">
                    <div>
                        <div class="stat-label">Rata-rata Waktu Eksekusi</div>
                        <div class="stat-value"><?= $avgExecTime ?> <span style="font-size:1rem; font-weight:normal; color:var(--text-muted);">ms</span></div>
                    </div>
                    <div style="width:40px; height:40px; border-radius:10px; background:#ecfdf5; color:#059669; display:flex; align-items:center; justify-content:center;">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"></polygon></svg>
                    </div>
                </div>
            </div>
        </div>

        <!-- BENCHMARK / LIVE EXPLAIN ANALYZE TESTER -->
        <div class="card" style="border-top: 4px solid var(--primary);">
            <div class="card-header">
                <div>
                    <h2>Live EXPLAIN ANALYZE Tester (Analisis Sebelum & Sesudah Index)</h2>
                    <p style="color: var(--text-muted); font-size: 0.88rem; margin-top:2px;">Gunakan alat ini untuk menguji dan mengambil capture performa query untuk laporan tugas UTS.</p>
                </div>
            </div>

            <form method="GET" action="admin.php" style="display: flex; flex-direction: column; gap: 1rem;">
                <div style="display: flex; gap: 0.75rem; flex-wrap: wrap;">
                    <input type="text" name="bench_q" class="search-input" style="flex: 2; min-width: 250px;" 
                           placeholder="Kata kunci FTS (contoh: delivery, refund, service)..." 
                           value="<?= htmlspecialchars($benchKeyword ?: 'delivery') ?>" required>

                    <select name="bench_index" class="form-control" style="flex: 1; min-width: 200px;">
                        <option value="gin" <?= $benchIndex === 'gin' ? 'selected' : '' ?>>Index GIN (Optimasi FTS Rekomendasi)</option>
                        <option value="gist" <?= $benchIndex === 'gist' ? 'selected' : '' ?>>Index GiST (Alternative Tree-based)</option>
                        <option value="seq" <?= $benchIndex === 'seq' ? 'selected' : '' ?>>Tanpa Index (Sequential Scan)</option>
                    </select>

                    <button type="submit" class="btn-primary" style="white-space: nowrap;">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><polygon points="5 3 19 12 5 21 5 3"></polygon></svg>
                        Uji & Generate EXPLAIN
                    </button>
                </div>
            </form>

            <?php if ($benchKeyword !== ''): ?>
                <div style="margin-top: 1.25rem;">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem;">
                        <span style="font-weight: 700; font-size: 0.9rem; color:var(--text-main);">
                            Hasil Execution Plan (<?= strtoupper($benchIndex) ?>) | Total Request: <span style="color:var(--primary);"><?= $benchTime ?> ms</span>
                        </span>
                        <button type="button" class="btn-sm" onclick="copyBenchPlan()">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"></rect><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path></svg>
                            Salin Output
                        </button>
                    </div>
                    <pre class="explain-box" id="benchPlanText"><?= htmlspecialchars($benchResult) ?></pre>
                </div>
            <?php endif; ?>
        </div>

        <!-- QUERY LOGS TABLE -->
        <div class="card">
            <div class="card-header">
                <div>
                    <h2>Riwayat Log Query Pengguna</h2>
                    <p style="color: var(--text-muted); font-size: 0.88rem; margin-top:2px;">Setiap pencarian yang dilakukan user otomatis tercatat beserta rencana eksekusinya.</p>
                </div>
                <?php if (!empty($logs)): ?>
                    <form method="POST" action="admin.php" onsubmit="return confirm('Yakin ingin membersihkan semua log?');">
                        <input type="hidden" name="action" value="clear_logs">
                        <button type="submit" class="btn-sm" style="color:var(--danger); border-color:#fca5a5;">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>
                            Hapus Log
                        </button>
                    </form>
                <?php endif; ?>
            </div>

            <?php if (empty($logs)): ?>
                <div style="text-align: center; padding: 2.5rem; color: var(--text-muted);">
                    Belum ada log pencarian. Silakan lakukan pencarian di <a href="index.php" style="color:var(--primary); font-weight:600;">halaman pencarian</a>.
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Waktu</th>
                                <th>User</th>
                                <th>Kata Kunci Query</th>
                                <th>Mode</th>
                                <th>Hasil</th>
                                <th>Durasi</th>
                                <th>Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($logs as $log): ?>
                                <tr>
                                    <td><strong><?= $log['id'] ?></strong></td>
                                    <td style="white-space: nowrap; font-size: 0.82rem; color: var(--text-muted);">
                                        <?= date('d/m/Y H:i:s', strtotime($log['created_at'])) ?>
                                    </td>
                                    <td>
                                        <div style="display:inline-flex; align-items:center; gap:0.35rem;">
                                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg>
                                            <strong><?= htmlspecialchars($log['username']) ?></strong>
                                        </div>
                                    </td>
                                    <td>
                                        <code style="background:#f1f5f9; padding:3px 8px; border-radius:6px; font-weight:600; font-family:'JetBrains Mono', monospace; font-size:0.85rem; color:var(--text-main);">
                                            <?= htmlspecialchars($log['search_query']) ?>
                                        </code>
                                    </td>
                                    <td>
                                        <span class="badge-user"><?= htmlspecialchars($log['search_mode']) ?></span>
                                    </td>
                                    <td>
                                        <strong><?= number_format($log['results_count']) ?></strong> baris
                                    </td>
                                    <td>
                                        <span class="exec-time-pill" style="<?= $log['execution_time_ms'] > 100 ? 'background:#fff7ed; color:#c2410c; border-color:#ffedd5;' : '' ?>">
                                            <?= $log['execution_time_ms'] ?> ms
                                        </span>
                                    </td>
                                    <td>
                                        <button type="button" class="btn-sm btn-sm-primary" 
                                                onclick="showExplain(<?= $log['id'] ?>)">
                                            Lihat EXPLAIN
                                        </button>
                                        <!-- Hidden storage for modal -->
                                        <textarea id="log-explain-<?= $log['id'] ?>" style="display:none;"><?= htmlspecialchars($log['explain_analyze']) ?></textarea>
                                        <input type="hidden" id="log-query-<?= $log['id'] ?>" value="<?= htmlspecialchars($log['search_query']) ?>">
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- MODAL POPUP FOR EXPLAIN ANALYZE -->
    <div class="modal-overlay" id="explainModal">
        <div class="modal-content">
            <div class="modal-header">
                <div>
                    <h3 id="modalTitle">PostgreSQL EXPLAIN ANALYZE</h3>
                    <p id="modalSubtitle" style="color:var(--text-muted); font-size:0.85rem; margin-top:2px;"></p>
                </div>
                <button class="modal-close" onclick="closeModal()">&times;</button>
            </div>
            <div class="modal-body">
                <pre class="explain-box" id="modalExplainContent" style="margin: 0;"></pre>
            </div>
        </div>
    </div>

    <script>
        function showExplain(id) {
            const query = document.getElementById('log-query-' + id).value;
            const content = document.getElementById('log-explain-' + id).value;
            
            document.getElementById('modalTitle').innerText = 'EXPLAIN (ANALYZE, BUFFERS) Plan';
            document.getElementById('modalSubtitle').innerText = 'Query: "' + query + '"';
            document.getElementById('modalExplainContent').innerText = content || 'Tidak ada detail explain analyze untuk query ini.';
            document.getElementById('explainModal').classList.add('active');
        }

        function closeModal() {
            document.getElementById('explainModal').classList.remove('active');
        }

        // Close on background click
        document.getElementById('explainModal').addEventListener('click', function(e) {
            if (e.target === this) closeModal();
        });

        // Close on ESC
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') closeModal();
        });

        function copyBenchPlan() {
            const text = document.getElementById('benchPlanText').innerText;
            navigator.clipboard.writeText(text).then(() => {
                alert('Output EXPLAIN ANALYZE berhasil disalin!');
            });
        }
    </script>
</body>
</html>
