-- JONATHAN VINCENT HARTONO 24.K1.0065

\c postgres
DROP DATABASE IF EXISTS dbuts;
CREATE DATABASE dbuts;
\c dbuts

-- 1. Membuat tabel
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

-- 2. Import dataset CSV
\copy amazon_reviews (reviewer_name, profile_link, country, review_count, review_date, rating, review_title, review_text, date_of_experience) FROM 'amazon_updated_2.csv' WITH (FORMAT CSV, HEADER TRUE, DELIMITER ',', QUOTE '"', ESCAPE '"', ENCODING 'UTF8');

-- 3. Menambahkan kolom TSVECTOR
ALTER TABLE amazon_reviews
ADD COLUMN search_vector tsvector;

-- 4. Mengubah Review Title dan Review Text menjadi TSVECTOR
UPDATE amazon_reviews
SET search_vector = to_tsvector(
    'english',
    coalesce(review_title, '') || ' ' ||
    coalesce(review_text, '')
);

-- 5. Analisis performa SEBELUM menggunakan index
EXPLAIN ANALYZE
SELECT *
FROM amazon_reviews
WHERE search_vector @@ to_tsquery('english', 'delivery');

-- 6. Membuat index GIN
CREATE INDEX idx_amazon_reviews_fts_gin
ON amazon_reviews
USING GIN(search_vector);

-- 7. Analisis performa SETELAH menggunakan index GIN
EXPLAIN ANALYZE
SELECT *
FROM amazon_reviews
WHERE search_vector @@ to_tsquery('english', 'delivery');

-- 8. Pencarian review berdasarkan keyword
SELECT
    id,
    review_title,
    review_text
FROM amazon_reviews
WHERE search_vector @@ to_tsquery('english', 'delivery');

-- 9. Pencarian dengan ranking
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
ORDER BY rank DESC;

-- 10. Highlighting hasil pencarian
SELECT
    id,
    review_title,
    ts_headline(
        'english',
        review_text,
        to_tsquery('english', 'delivery')
    ) AS highlighted_review
FROM amazon_reviews
WHERE search_vector @@ to_tsquery('english', 'delivery');