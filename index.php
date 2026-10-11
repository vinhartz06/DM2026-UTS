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
            <div class="nav-user-pill">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg>
                <span>Halo, <strong><?= htmlspecialchars($_SESSION['username'] ?? '') ?></strong></span>
            </div>
            <?php if (isAdmin()): ?>
                <span class="badge-admin">ADMIN</span>
                <a href="admin.php" style="font-weight:700; color:var(--primary); display:inline-flex; align-items:center; gap:0.35rem;">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7"></rect><rect x="14" y="3" width="7" height="7"></rect><rect x="14" y="14" width="7" height="7"></rect><rect x="3" y="14" width="7" height="7"></rect></svg>
                    Dashboard Admin
                </a>
            <?php else: ?>
                <span class="badge-user">USER</span>
            <?php endif; ?>
            <a href="logout.php" class="btn-logout" title="Keluar dari akun">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path><polyline points="16 17 21 12 16 7"></polyline><line x1="21" y1="12" x2="9" y2="12"></line></svg>
                Keluar
            </a>
        </div>
    </nav>

    <!-- CONTENT -->
    <div class="container">
        <!-- SEARCH HERO & FORM -->
        <div class="search-hero">
            <div class="hero-pill">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"></polygon></svg>
                PostgreSQL Full-Text Search Engine
            </div>
            <h1>Eksplorasi Review Amazon</h1>
            <p>Pencarian semantik berkecepatan tinggi dengan ranking relevansi (<code>ts_rank</code>) dan penyorotan frasa kunci (<code>ts_headline</code>)</p>
        </div>

        <div class="card">
            <form method="GET" action="index.php" class="search-form">
                <div class="search-input-group">
                    <div class="search-input-wrapper">
                        <span class="search-icon-inside">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
                        </span>
                        <input type="text" name="q" class="search-input" 
                               placeholder="Ketik kata kunci review... (contoh: fast delivery, customer service, refund)" 
                               value="<?= htmlspecialchars($query) ?>" autofocus required>
                    </div>
                    <button type="submit" class="btn-primary">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
                        Cari Review
                    </button>
                </div>

                <div class="search-options">
                    <label>
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"></polygon></svg>
                        Mode Query:
                        <select name="mode">
                            <option value="websearch" <?= $mode === 'websearch' ? 'selected' : '' ?>>Web Search (Google Syntax: kata, "frasa", -negasi)</option>
                            <option value="boolean" <?= $mode === 'boolean' ? 'selected' : '' ?>>Boolean Search (&, |, !)</option>
                            <option value="phrase" <?= $mode === 'phrase' ? 'selected' : '' ?>>Phrase Search (Urutan Kata)</option>
                            <option value="plain" <?= $mode === 'plain' ? 'selected' : '' ?>>Plain Search (AND Otomatis)</option>
                        </select>
                    </label>

                    <label>
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="20" x2="18" y2="10"></line><line x1="12" y1="20" x2="12" y2="4"></line><line x1="6" y1="20" x2="6" y2="14"></line></svg>
                        Metode Ranking:
                        <select name="ranking">
                            <option value="ts_rank" <?= $ranking === 'ts_rank' ? 'selected' : '' ?>>ts_rank (Frekuensi & Bobot Judul/Isi)</option>
                            <option value="ts_rank_cd" <?= $ranking === 'ts_rank_cd' ? 'selected' : '' ?>>ts_rank_cd (Cover Density / Kerapatan)</option>
                        </select>
                    </label>

                    <label>
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect><line x1="3" y1="9" x2="21" y2="9"></line><line x1="9" y1="21" x2="9" y2="9"></line></svg>
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
                    <span style="font-weight: 700; color: #475569;">Contoh Cepat:</span> 
                    <a href="?q=delivery&mode=websearch" class="quick-chip">⚡ delivery</a>
                    <a href="?q=%22customer+service%22&mode=websearch" class="quick-chip">💬 "customer service"</a>
                    <a href="?q=refund+%26+return&mode=boolean" class="quick-chip">🔄 refund & return</a>
                    <a href="?q=fast+delivery&ranking=ts_rank_cd" class="quick-chip">🚀 fast delivery (density)</a>
                </div>
            </form>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-danger">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>
                <div><?= $error ?></div>
            </div>
        <?php endif; ?>

        <!-- RESULTS SECTION -->
        <?php if ($query !== '' && empty($error)): ?>
            <div class="results-summary">
                <div>
                    Ditemukan <strong><?= number_format($totalFound) ?></strong> review untuk kata kunci 
                    "<strong><?= htmlspecialchars($query) ?></strong>" 
                    <span style="color:var(--text-muted); font-size:0.85rem;">(Menampilkan <?= count($results) ?> teratas)</span>
                </div>
                <div>
                    <span class="exec-time-pill" title="Kecepatan eksekusi query PostgreSQL">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"></polygon></svg>
                        <?= $executionTimeMs ?> ms
                    </span>
                </div>
            </div>

            <?php if (empty($results)): ?>
                <div class="card" style="text-align:center; padding:3.5rem 1.5rem;">
                    <div style="width:56px; height:56px; border-radius:50%; background:#f1f5f9; display:inline-flex; align-items:center; justify-content:center; color:#94a3b8; margin-bottom:1rem;">
                        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
                    </div>
                    <h3 style="color:var(--text-main); margin-bottom:0.5rem; font-weight:700;">Tidak Ditemukan Review yang Cocok</h3>
                    <p style="color:var(--text-muted); font-size:0.95rem; max-width:480px; margin:0 auto;">Coba gunakan variasi kata lain, periksa ejaan, atau gunakan mode <strong>Web Search</strong> untuk hasil lebih fleksibel.</p>
                </div>
            <?php else: ?>
                <div class="results-list">
                    <?php foreach ($results as $item): ?>
                        <article class="review-item">
                            <div class="review-item-header">
                                <h3 class="review-title"><?= $item['hl_title'] ?: '(Tanpa Judul)' ?></h3>
                                <div class="rank-badge" title="Skor Relevansi Berdasarkan Bobot Judul (A) & Isi (B)">
                                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"></polygon></svg>
                                    Relevansi: <?= htmlspecialchars($item['rank_score']) ?>
                                </div>
                            </div>

                            <div class="review-meta">
                                <?php if (!empty($item['rating'])): ?>
                                    <span class="rating-badge" title="Rating Bintang">
                                        ★ <?= htmlspecialchars($item['rating']) ?>
                                    </span>
                                <?php endif; ?>
                                <span class="review-meta-item">
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg>
                                    <strong><?= htmlspecialchars($item['reviewer_name'] ?: 'Pengguna Anonim') ?></strong>
                                </span>
                                <?php if (!empty($item['country'])): ?>
                                    <span class="review-meta-item">
                                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><line x1="2" y1="12" x2="22" y2="12"></line><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"></path></svg>
                                        <?= htmlspecialchars($item['country']) ?>
                                    </span>
                                <?php endif; ?>
                                <?php if (!empty($item['review_date'])): ?>
                                    <span class="review-meta-item">
                                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg>
                                        <?= htmlspecialchars(date('d M Y', strtotime($item['review_date']))) ?>
                                    </span>
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
            <div class="card" style="text-align: center; padding: 3rem 1.5rem; background: #ffffff;">
                <div style="width:52px; height:52px; border-radius:12px; background:var(--primary-light); color:var(--primary); display:inline-flex; align-items:center; justify-content:center; margin-bottom:1.25rem;">
                    <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line><polyline points="10 9 9 9 8 9"></polyline></svg>
                </div>
                <h3 style="margin-bottom: 0.5rem; font-size:1.35rem; font-weight:700;">Fitur Pencarian Full-Text Search (FTS) PostgreSQL</h3>
                <p style="color: var(--text-muted); max-width: 650px; margin: 0 auto 1.75rem; font-size: 0.95rem; line-height: 1.6;">
                    Sistem ini mengimplementasikan normalisasi kata (<em>lexemes, stemming, stop words</em>),
                    pembobotan judul (bobot A) vs isi (bobot B), ranking relevansi (<code>ts_rank</code>), 
                    dan penyorotan kata kunci (<code>ts_headline</code>).
                </p>
                <div style="display:flex; justify-content:center; gap:0.6rem; flex-wrap:wrap;">
                    <a href="?q=damaged+box" class="quick-chip">📦 damaged box</a>
                    <a href="?q=fast+shipping" class="quick-chip">🚀 fast shipping</a>
                    <a href="?q=customer+service" class="quick-chip">💬 customer service</a>
                    <a href="?q=wrong+item" class="quick-chip">⚠️ wrong item</a>
                    <a href="?q=prime+membership" class="quick-chip">👑 prime membership</a>
                </div>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>
