-- ============================================================================
-- JONATHAN VINCENT HARTONO 24.K1.0065
-- TUGAS PENGGANTI UTS DATA MANAGEMENT - FULL-TEXT SEARCH (FTS) POSTGRESQL
-- Studi Kasus : Analisis Review Produk Amazon (Amazon Customer Reviews)
-- Dataset     : amazon_updated_2.csv
-- ============================================================================

-- 1. RECREATE DATABASE
\c postgres
DROP DATABASE IF EXISTS dbuts;
CREATE DATABASE dbuts;
\c dbuts

-- 2. MEMBUAT TABEL UTAMA
CREATE TABLE amazon_reviews (
    id SERIAL PRIMARY KEY,
    reviewer_name TEXT,
    profile_link TEXT,
    country TEXT,
    review_count TEXT,
    review_date TIMESTAMP,
    rating TEXT,
    review_title TEXT,
    review_text TEXT,
    date_of_experience DATE
);

-- 3. IMPORT DATASET CSV
\copy amazon_reviews (reviewer_name, profile_link, country, review_count, review_date, rating, review_title, review_text, date_of_experience) FROM 'amazon_updated_2.csv' WITH (FORMAT CSV, HEADER TRUE, DELIMITER ',', QUOTE '"', ESCAPE '"', ENCODING 'UTF8');

-- 4. MENAMBAHKAN KOLOM TSVECTOR
ALTER TABLE amazon_reviews
ADD COLUMN search_vector tsvector;

-- 5. MENGUBAH REVIEW TITLE DAN REVIEW TEXT MENJADI TSVECTOR
-- Ditambahkan pembobotan (Weighting): Bobot 'A' untuk Judul dan 'B' untuk Isi Review agar relevansi ranking jauh lebih akurat
UPDATE amazon_reviews
SET search_vector = 
    setweight(to_tsvector('english', coalesce(review_title, '')), 'A') ||
    setweight(to_tsvector('english', coalesce(review_text, '')), 'B');

-- TRIGGER OTOMATIS: Memastikan data baru / update otomatis mengisi search_vector
CREATE OR REPLACE FUNCTION trg_update_amazon_reviews_search_vector()
RETURNS trigger AS $$
BEGIN
    NEW.search_vector :=
        setweight(to_tsvector('english', coalesce(NEW.review_title, '')), 'A') ||
        setweight(to_tsvector('english', coalesce(NEW.review_text, '')), 'B');
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_amazon_reviews_tsv ON amazon_reviews;
CREATE TRIGGER trg_amazon_reviews_tsv
BEFORE INSERT OR UPDATE ON amazon_reviews
FOR EACH ROW
EXECUTE FUNCTION trg_update_amazon_reviews_search_vector();

-- 6. ANALISIS PERFORMA SEBELUM MENGGUNAKAN INDEX
EXPLAIN ANALYZE
SELECT *
FROM amazon_reviews
WHERE search_vector @@ to_tsquery('english', 'delivery');

-- 7. MEMBUAT INDEX GIN
CREATE INDEX idx_amazon_reviews_fts_gin
ON amazon_reviews
USING GIN(search_vector);

-- 8. MEMBUAT INDEX GIST
CREATE INDEX idx_amazon_reviews_fts_gist
ON amazon_reviews
USING GiST(search_vector);

-- 9. ANALISIS PERFORMA SETELAH MENGGUNAKAN INDEX GIN
EXPLAIN ANALYZE
SELECT *
FROM amazon_reviews
WHERE search_vector @@ to_tsquery('english', 'delivery');

-- 10. PENCARIAN REVIEW BERDASARKAN KEYWORD
SELECT
    id,
    review_title,
    review_text
FROM amazon_reviews
WHERE search_vector @@ to_tsquery('english', 'delivery')
LIMIT 5;

-- 11. PENCARIAN DENGAN RANKING
SELECT
    id,
    review_title,
    review_text,
    ts_rank(
        search_vector,
        to_tsquery('english', 'delivery')
    ) AS rank
FROM amazon_reviews
WHERE search_vector @@ to_tsquery('english', 'delivery')
ORDER BY rank DESC
LIMIT 5;

-- 12. HIGHLIGHTING HASIL PENCARIAN
SELECT
    id,
    review_title,
    ts_headline(
        'english',
        review_text,
        to_tsquery('english', 'delivery'),
        'StartSel=<mark>, StopSel=</mark>'
    ) AS highlighted_review
FROM amazon_reviews
WHERE search_vector @@ to_tsquery('english', 'delivery')
LIMIT 5;

-- 13. TABEL USERS (LOGIN & REGISTER)
CREATE TABLE users (
    id SERIAL PRIMARY KEY,
    username VARCHAR(50) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    role VARCHAR(20) DEFAULT 'USER',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Akun dummy ADMIN (username: admin, password: password)
INSERT INTO users (username, password, role)
VALUES ('admin', '$2y$10$wT0Xk6zY2mH4j9J7hG9S6.9W1v1zV1zX0W7gS5x0n0e0q0u0i0t0y', 'ADMIN')
ON CONFLICT (username) DO NOTHING;

-- 14. TABEL SEARCH_LOGS (UNTUK DASHBOARD ADMIN & EXPLAIN ANALYZE)
CREATE TABLE search_logs (
    id SERIAL PRIMARY KEY,
    user_id INT REFERENCES users(id) ON DELETE SET NULL,
    username VARCHAR(50),
    search_query TEXT NOT NULL,
    search_mode VARCHAR(50) DEFAULT 'websearch',
    ranking_method VARCHAR(50) DEFAULT 'ts_rank',
    results_count INT DEFAULT 0,
    execution_time_ms NUMERIC(10, 2),
    explain_analyze TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);