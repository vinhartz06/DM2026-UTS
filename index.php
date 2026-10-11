<?php
require_once __DIR__ . '/config.php';
requireLogin();

$query = trim($_GET['q'] ?? '');
$mode = $_GET['mode'] ?? 'websearch';
$ranking = $_GET['ranking'] ?? 'ts_rank';
$limit = intval($_GET['limit'] ?? 15);
if ($limit < 5 || $limit > 100) $limit = 15;

$results = [];
$totalFound = 0;
$executionTimeMs = 0;
$error = '';
$explainAnalyzeOutput = '';

if ($query !== '') {
    try {
        $pdo = getDBConnection();
        $startTime = microtime(true);

        // Pilih fungsi tsquery berdasarkan mode
        // 1. websearch_to_tsquery: Mendukung sintaks Google (kata biasa, kutip "frasa", minus -negasi)
        // 2. to_tsquery: Mendukung operator boolean (&, |, !)
        // 3. phraseto_tsquery: Pencarian urutan kata berdekatan (<->)
        // 4. plainto_tsquery: Pencarian teks biasa (semua kata digabung AND)
        switch ($mode) {
            case 'boolean':
                $tsquerySql = "to_tsquery('english', :query)";
                break;
            case 'phrase':
                $tsquerySql = "phraseto_tsquery('english', :query)";
                break;
            case 'plain':
                $tsquerySql = "plainto_tsquery('english', :query)";
                break;
            case 'websearch':
            default:
                $mode = 'websearch';
                $tsquerySql = "websearch_to_tsquery('english', :query)";
                break;
        }

        // Pilih metode ranking: ts_rank (frekuensi) atau ts_rank_cd (cover density)
        $rankFunc = ($ranking === 'ts_rank_cd') ? 'ts_rank_cd' : 'ts_rank';

        // Query Utama dengan Highlighting (ts_headline) & Ranking
        $sql = "
            SELECT
                id,
                reviewer_name,
                country,
                rating,
                review_date,
                ts_headline(
                    'english', 
                    coalesce(review_title, ''), 
                    $tsquerySql, 
                    'StartSel=<mark>, StopSel=</mark>'
                ) AS hl_title,
                ts_headline(
                    'english', 
                    coalesce(review_text, ''), 
                    $tsquerySql, 
                    'StartSel=<mark>, StopSel=</mark>, MaxWords=35, MinWords=15, ShortWord=3, HighlightAll=FALSE, MaxFragments=2, FragmentDelimiter=\" ... \"'
                ) AS hl_text,
                ROUND($rankFunc(search_vector, $tsquerySql)::numeric, 4) AS rank_score
            FROM amazon_reviews
            WHERE search_vector @@ $tsquerySql
            ORDER BY rank_score DESC
            LIMIT :limit
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':query', $query, PDO::PARAM_STR);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        $results = $stmt->fetchAll();

        // Hitung total data yang cocok
        $countSql = "SELECT COUNT(*) FROM amazon_reviews WHERE search_vector @@ $tsquerySql";
        $countStmt = $pdo->prepare($countSql);
        $countStmt->bindValue(':query', $query, PDO::PARAM_STR);
        $countStmt->execute();
        $totalFound = (int)$countStmt->fetchColumn();

        $executionTimeMs = round((microtime(true) - $startTime) * 1000, 2);

        // Jalankan EXPLAIN ANALYZE untuk pencatatan performa di log
        $explainSql = "
            EXPLAIN (ANALYZE, BUFFERS)
            SELECT id FROM amazon_reviews 
            WHERE search_vector @@ $tsquerySql 
            ORDER BY $rankFunc(search_vector, $tsquerySql) DESC 
            LIMIT :limit
        ";
        $explainStmt = $pdo->prepare($explainSql);
        $explainStmt->bindValue(':query', $query, PDO::PARAM_STR);
        $explainStmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $explainStmt->execute();
        $explainRows = $explainStmt->fetchAll(PDO::FETCH_COLUMN);
        $explainAnalyzeOutput = implode("\n", $explainRows);

        // Catat Query ke Tabel search_logs untuk Dashboard Admin
        try {
            $logStmt = $pdo->prepare("
                INSERT INTO search_logs (
                    user_id, username, search_query, search_mode, 
                    ranking_method, results_count, execution_time_ms, explain_analyze
                ) VALUES (
                    :user_id, :username, :search_query, :search_mode, 
                    :ranking_method, :results_count, :execution_time_ms, :explain_analyze
                )
            ");
            $logStmt->execute([
                ':user_id'           => $_SESSION['user_id'] ?? null,
                ':username'          => $_SESSION['username'] ?? 'guest',
                ':search_query'      => $query,
                ':search_mode'       => $mode,
                ':ranking_method'    => $rankFunc,
                ':results_count'     => $totalFound,
                ':execution_time_ms' => $executionTimeMs,
                ':explain_analyze'   => $explainAnalyzeOutput
            ]);
        } catch (Exception $logErr) {
            // Jika tabel search_logs belum dibuat, jangan gagalkan pencarian
        }

    } catch (PDOException $e) {
        if ($mode === 'boolean') {
            $error = 'Format query boolean tidak valid. Gunakan operator seperti: <code>delivery & fast</code>, <code>price | cheap</code>, atau <code>!damaged</code>. Pesan error: ' . $e->getMessage();
        } else {
            $error = 'Terjadi kesalahan saat mengeksekusi pencarian: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pencarian Review - PostgreSQL FTS</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <!-- NAVBAR -->
    <nav class="navbar">
        <a href="index.php" class="nav-brand">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
            Amazon Reviews FTS
        </a>
        <div class="nav-links">
            <span>Halo, <strong><?= htmlspecialchars($_SESSION['username'] ?? '') ?></strong></span>
            <?php if (isAdmin()): ?>
                <span class="badge-admin">ADMIN</span>
                <a href="admin.php" style="font-weight:600; color:var(--primary);">Dashboard Admin & Logs</a>
            <?php else: ?>
                <span class="badge-user">USER</span>
            <?php endif; ?>
            <a href="logout.php" class="btn-logout">Keluar</a>
        </div>
    </nav>

    <!-- CONTENT -->
    <div class="container">
        <!-- SEARCH HERO & FORM -->
        <div class="search-hero">
            <h1>Eksplorasi Review Amazon</h1>
            <p>Implementasi PostgreSQL Full-Text Search, Ranking (ts_rank), & Highlighting (ts_headline)</p>
        </div>

        <div class="card">
            <form method="GET" action="index.php" class="search-form">
                <div class="search-input-group">
                    <input type="text" name="q" class="search-input" 
                           placeholder="Ketik kata kunci review... (contoh: fast delivery, customer service, refund)" 
                           value="<?= htmlspecialchars($query) ?>" autofocus required>
                    <button type="submit" class="btn-primary">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
                        Cari Review
                    </button>
                </div>

                <div class="search-options">
                    <label>
                        Mode Query:
                        <select name="mode" onchange="updateHint()">
                            <option value="websearch" <?= $mode === 'websearch' ? 'selected' : '' ?>>Web Search (Alami / Google Syntax)</option>
                            <option value="boolean" <?= $mode === 'boolean' ? 'selected' : '' ?>>Boolean Search (&, |, !)</option>
                            <option value="phrase" <?= $mode === 'phrase' ? 'selected' : '' ?>>Phrase Search (Urutan Kata)</option>
                            <option value="plain" <?= $mode === 'plain' ? 'selected' : '' ?>>Plain Search (AND Biasa)</option>
                        </select>
                    </label>

                    <label>
                        Metode Ranking:
                        <select name="ranking">
                            <option value="ts_rank" <?= $ranking === 'ts_rank' ? 'selected' : '' ?>>ts_rank (Frekuensi & Bobot A/B)</option>
                            <option value="ts_rank_cd" <?= $ranking === 'ts_rank_cd' ? 'selected' : '' ?>>ts_rank_cd (Cover Density / Kerapatan)</option>
                        </select>
                    </label>

                    <label>
                        Batas:
                        <select name="limit">
                            <option value="10" <?= $limit == 10 ? 'selected' : '' ?>>10 Hasil</option>
                            <option value="15" <?= $limit == 15 ? 'selected' : '' ?>>15 Hasil</option>
                            <option value="30" <?= $limit == 30 ? 'selected' : '' ?>>30 Hasil</option>
                            <option value="50" <?= $limit == 50 ? 'selected' : '' ?>>50 Hasil</option>
                        </select>
                    </label>
                </div>

                <div class="search-hints" id="search-hint">
                    <strong>Contoh Cepat:</strong> 
                    <a href="?q=delivery&mode=websearch" style="color:inherit; text-decoration:underline;">delivery</a> | 
                    <a href="?q=%22customer+service%22&mode=websearch" style="color:inherit; text-decoration:underline;">"customer service"</a> | 
                    <a href="?q=refund+%26+return&mode=boolean" style="color:inherit; text-decoration:underline;">refund & return (boolean)</a> | 
                    <a href="?q=fast+delivery&ranking=ts_rank_cd" style="color:inherit; text-decoration:underline;">fast delivery (density)</a>
                </div>
            </form>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-danger"><?= $error ?></div>
        <?php endif; ?>

        <!-- RESULTS SECTION -->
        <?php if ($query !== '' && empty($error)): ?>
            <div class="results-summary">
                <div>
                    Ditemukan <strong><?= number_format($totalFound) ?></strong> review untuk kata kunci 
                    "<strong><?= htmlspecialchars($query) ?></strong>" 
                    (Menampilkan <?= count($results) ?> teratas)
                </div>
                <div>
                    Waktu Eksekusi: <strong><?= $executionTimeMs ?> ms</strong>
                </div>
            </div>

            <?php if (empty($results)): ?>
                <div class="card" style="text-align:center; padding:3rem;">
                    <h3 style="color:var(--text-muted); margin-bottom:0.5rem;">Tidak Ditemukan Review yang Cocok</h3>
                    <p style="color:var(--text-muted); font-size:0.95rem;">Coba gunakan variasi kata lain atau gunakan mode Web Search.</p>
                </div>
            <?php else: ?>
                <div class="results-list">
                    <?php foreach ($results as $item): ?>
                        <article class="review-item">
                            <div class="review-item-header">
                                <h3 class="review-title"><?= $item['hl_title'] ?: '(Tanpa Judul)' ?></h3>
                                <div class="rank-badge" title="Skor Relevansi Berdasarkan Bobot Judul & Isi">
                                    Relevansi: <?= htmlspecialchars($item['rank_score']) ?>
                                </div>
                            </div>

                            <div class="review-meta">
                                <?php if (!empty($item['rating'])): ?>
                                    <span class="rating-badge"><?= htmlspecialchars($item['rating']) ?></span>
                                <?php endif; ?>
                                <span><strong>Oleh:</strong> <?= htmlspecialchars($item['reviewer_name'] ?: 'Pengguna Anonim') ?></span>
                                <?php if (!empty($item['country'])): ?>
                                    <span><strong>Negara:</strong> <?= htmlspecialchars($item['country']) ?></span>
                                <?php endif; ?>
                                <?php if (!empty($item['review_date'])): ?>
                                    <span><strong>Tanggal:</strong> <?= htmlspecialchars(date('d M Y', strtotime($item['review_date']))) ?></span>
                                <?php endif; ?>
                            </div>

                            <div class="review-text">
                                <?= $item['hl_text'] ?: '<em>(Tidak ada teks review)</em>' ?>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

        <?php elseif ($query === ''): ?>
            <!-- EMPTY / INITIAL STATE -->
            <div class="card" style="text-align: center; padding: 2.5rem 1.5rem; background: #ffffff;">
                <h3 style="margin-bottom: 0.5rem;">Fitur Pencarian Full-Text Search (FTS) PostgreSQL</h3>
                <p style="color: var(--text-muted); max-width: 650px; margin: 0 auto 1.5rem; font-size: 0.95rem;">
                    Sistem ini mengimplementasikan normalisasi kata (lexemes, stemming, stop words),
                    pembobotan judul (bobot A) vs isi (bobot B), ranking relevansi (ts_rank), 
                    dan highlighting kata kunci (ts_headline).
                </p>
                <div style="display:flex; justify-content:center; gap:0.5rem; flex-wrap:wrap;">
                    <a href="?q=damaged+box" class="btn-sm">damaged box</a>
                    <a href="?q=fast+shipping" class="btn-sm">fast shipping</a>
                    <a href="?q=customer+service" class="btn-sm">customer service</a>
                    <a href="?q=wrong+item" class="btn-sm">wrong item</a>
                    <a href="?q=prime+membership" class="btn-sm">prime membership</a>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <script>
        function updateHint() {
            // Feedback interaktif bila dibutuhkan
        }
    </script>
</body>
</html>
