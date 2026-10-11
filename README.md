# Panduan Project UTS Data Management - PostgreSQL Full-Text Search (FTS)

Proyek ini dibangun menggunakan **PHP Native (Pure PHP)**, **HTML/CSS/JS (Tanpa Library Berat)**, dan database **PostgreSQL**.

---

## 1. Lokasi Pengaturan Database & Password
Jika Anda ingin mengubah username atau password database PostgreSQL:
Buka file **`config.php`** pada baris 9–13:
```php
define('DB_HOST', 'localhost');
define('DB_PORT', '5432');
define('DB_NAME', 'dbuts');
define('DB_USER', 'postgres');
define('DB_PASS', 'postgres'); // <-- GANTI PASSWORD POSTGRESQL ANDA DI SINI
```

---

## 2. Cara Menjalankan Script Database (1 Kali Run Selesai Semua)

Anda hanya perlu login ke PostgreSQL dan membuat database `dbuts`, lalu jalankan `schema.sql`:

```bash
# 1. Buka terminal di folder project ini
# 2. Buat database dbuts (jika belum ada)
psql -U postgres -c "CREATE DATABASE dbuts;"

# 3. Jalankan schema.sql (Otomatis create table, import CSV, generate tsvector, buat index GIN/GiST, dan buat akun admin)
psql -U postgres -d dbuts -f schema.sql
```

*Jika Anda sudah berada di dalam prompt `psql -U postgres -d dbuts`, Anda cukup mengetikkan:*
```sql
\i schema.sql
```

Semua proses (import dataset CSV `amazon_updated_2.csv`, pembobotan tsvector, pembuatan index GIN & GiST, pembuatan tabel users & search logs) akan langsung berjalan otomatis.

---

## 3. Pastikan Ekstensi `pdo_pgsql` Aktif di PHP
Jika saat membuka web muncul pesan ekstensi pdo_pgsql belum aktif:
1. Buka file `php.ini` (pada Laragon: Klik Kanan -> **PHP** -> **php.ini**, atau menu **Extensions** -> centang **pdo_pgsql** dan **pgsql**).
2. Hapus tanda titik koma (`;`) di depan:
   ```ini
   extension=pdo_pgsql
   extension=pgsql
   ```
3. Restart Web Server / Laragon.

---

## 4. Cara Menjalankan Aplikasi Web
Anda dapat membukanya lewat Laragon atau via PHP Built-in Server dengan menjalankan perintah ini di terminal:
```bash
php -S localhost:8000
```
Lalu buka browser di: **`http://localhost:8000`**

---

## 5. Akun Login
- **Admin**:
  - Username: `admin`
  - Password: `password`
  - Hak Akses: Dapat mengakses Pencarian User dan **Dashboard Admin & Log EXPLAIN ANALYZE** (`admin.php`).
- **User Baru**:
  - Bisa klik **Daftar Akun Baru** di `register.php`.

---

## 6. Penjelasan Implementasi FTS & Lokasi Source Code (Untuk Laporan UTS)

Berikut adalah penjelasan detail bagaimana 5 instruksi utama UTS Data Management diimplementasikan di dalam proyek ini beserta referensi file dan baris kodenya:

---

### A. Tokenization dan Normalization (Lexemes, Stop words, Stemming)

#### 1. Penjelasan Konsep:
PostgreSQL Full-Text Search melakukan serangkaian pemrosesan teks linguistik melalui konfigurasi bahasa `'english'`:
- **Tokenization**: Mengurai kalimat menjadi token kata individual dan memisahkan spasi serta tanda baca (`!`, `?`, `,`, `.`).
- **Normalization**: Mengubah huruf besar menjadi huruf kecil (*lowercasing*) agar pencarian bersifat *case-insensitive*.
- **Stop words removal**: Menghapus kata hubung umum yang tidak menambah makna pencarian (seperti: *the*, *and*, *is*, *in*, *to*, *at*).
- **Stemming**: Memotong kata berimbuhan kembali ke bentuk dasar / leksikal (**lexeme**) menggunakan Snowball/Porter Stemmer. Contohnya: kata *delivering*, *deliveries*, dan *delivered* akan direduksi menjadi bentuk dasar yang sama, yaitu `'deliv'`.

#### 2. Implementasi di Project & Lokasi Kode:
Proses ini dieksekusi secara otomatis saat fungsi `to_tsvector('english', ...)` dijalankan:
- **Lokasi Kode di Database**:
  - File: `schema.sql` (Baris 38–43)
  ```sql
  UPDATE amazon_reviews
  SET search_vector = 
      setweight(to_tsvector('english', coalesce(review_title, '')), 'A') ||
      setweight(to_tsvector('english', coalesce(review_text, '')), 'B');
  ```
- **Lokasi Kode di Aplikasi**:
  - File: `index.php` (Baris 24–45): Query pencarian yang diketik user juga dinormalisasi ke lexeme menggunakan parser bahasa inggris: `websearch_to_tsquery('english', :query)` atau `to_tsquery('english', :query)`.
- **Query Demonstrasi di PostgreSQL (Bisa dicapture untuk laporan)**:
  ```sql
  SELECT * FROM ts_debug('english', 'Amazon is delivering packages quickly!');
  ```
  *Output query ini memperlihatkan jenis token, daftar stop words yang dibuang (seperti 'is'), dan hasil stemming lexeme ('deliv', 'packag', 'quick').*

---

### B. Transformasi Teks Menjadi Tipe Data `tsvector` dan Penyimpanan Efisien

#### 1. Penjelasan Konsep & Strategi Efisiensi:
- Jika FTS dijalankan langsung terhadap kolom teks biasa (`review_text`) di setiap query `SELECT`, PostgreSQL terpaksa membedah teks baris demi baris berulang kali (*on-the-fly parsing*). Hal ini membebani CPU dan I/O secara masif.
- **Strategi Efisiensi**: Data teks ditransformasi sekali saja dan disimpan secara permanen ke dalam kolom ber-tipe data **`tsvector`** (`search_vector`).
- **Weighting (Pembobotan)**: Judul review diberi bobot **'A'** (prioritas relevansi tertinggi) dan isi teks review diberi bobot **'B'** menggunakan fungsi `setweight()`.
- **Otomatisasi via Trigger**: Dibuatkan database trigger `trg_amazon_reviews_tsv` berjenis `BEFORE INSERT OR UPDATE`. Setiap ada review baru masuk atau data diedit, PostgreSQL otomatis memperbarui nilai `search_vector` tanpa perlu logic tambahan di level PHP.

#### 2. Implementasi di Project & Lokasi Kode:
- **Lokasi Kode**:
  - File: `schema.sql` (Baris 32–55)
  ```sql
  -- 1. Menambahkan kolom tsvector
  ALTER TABLE amazon_reviews ADD COLUMN search_vector tsvector;

  -- 2. Mengisi tsvector dengan bobot A (Judul) dan B (Isi Teks)
  UPDATE amazon_reviews
  SET search_vector = 
      setweight(to_tsvector('english', coalesce(review_title, '')), 'A') ||
      setweight(to_tsvector('english', coalesce(review_text, '')), 'B');

  -- 3. Trigger otomatis agar data baru selalu terisi secara efisien
  CREATE OR REPLACE FUNCTION trg_update_amazon_reviews_search_vector()
  RETURNS trigger AS $$
  BEGIN
      NEW.search_vector :=
          setweight(to_tsvector('english', coalesce(NEW.review_title, '')), 'A') ||
          setweight(to_tsvector('english', coalesce(NEW.review_text, '')), 'B');
      RETURN NEW;
  END;
  $$ LANGUAGE plpgsql;

  CREATE TRIGGER trg_amazon_reviews_tsv
  BEFORE INSERT OR UPDATE ON amazon_reviews
  FOR EACH ROW
  EXECUTE FUNCTION trg_update_amazon_reviews_search_vector();
  ```

---

### C. Strategi Ranking dan Highlighting untuk Data Relevan

#### 1. Penjelasan Strategi:
- **Relevance Ranking**: Mengurutkan dokumen dari yang paling relevan dengan kebutuhan pengguna ke yang terendah:
  1. `ts_rank`: Menghitung frekuensi kemunculan kata kunci dengan memperhitungkan bobot 'A' (judul) vs 'B' (isi). Kata kunci yang cocok di judul review akan menghasilkan nilai rank yang lebih tinggi daripada yang hanya cocok di isi.
  2. `ts_rank_cd` (*Cover Density*): Menghitung kerapatan atau jarak kedekatan antar kata kunci dalam kalimat. Jika dua kata kunci muncul berdampingan, skor density-nya lebih tinggi.
- **Highlighting**: Menggunakan `ts_headline` untuk mengekstrak fragmen kalimat yang relevan (*snippet*) dan membungkus kata yang cocok dengan tag `<mark>kata</mark>`. Pada antarmuka UI, tag ini di-styling dengan warna kuning cerah agar langsung terlihat oleh mata pengguna.

#### 2. Implementasi di Project & Lokasi Kode:
- **Lokasi Kode di SQL**:
  - File: `schema.sql` (Baris 82–108)
  ```sql
  -- Query Ranking
  SELECT id, review_title, ts_rank(search_vector, to_tsquery('english', 'delivery')) AS rank
  FROM amazon_reviews
  WHERE search_vector @@ to_tsquery('english', 'delivery')
  ORDER BY rank DESC;

  -- Query Highlighting
  SELECT id, review_title,
      ts_headline('english', review_text, to_tsquery('english', 'delivery'), 'StartSel=<mark>, StopSel=</mark>') AS highlighted_review
  FROM amazon_reviews
  WHERE search_vector @@ to_tsquery('english', 'delivery');
  ```
- **Lokasi Kode di UI Web**:
  - File: `index.php` (Baris 48–74): Query SQL dinamis yang mengambil `ts_headline` untuk judul dan teks serta menghitung `rank_score`.
  - File: `index.php` (Baris 190–225): Tampilan kartu review yang merender badge skor hijau: `Relevansi: <?= $item['rank_score'] ?>` dan teks ber-highlight.
  - File: `style.css` (Baris 214–221): Styling tag `<mark>` dengan warna latar kuning menyala (`background-color: #fef08a`) dan font tebal.

---

### D. Analisis Performa Query & Indeks Khusus (GIN vs GiST)

#### 1. Penjelasan Konsep GIN vs GiST:
- **Sequential Scan (Tanpa Indeks)**: Database harus membaca seluruh blok tabel di hard disk dari baris pertama sampai terakhir. Kompleksitasnya $O(N)$ dan waktu eksekusinya lambat.
- **GIN (Generalized Inverted Index)**: Struktur indeks terbalik yang memetakan setiap kata/lexeme unik ke daftar ID baris di mana kata tersebut muncul (mirip indeks di halaman belakang buku). **Sangat cepat untuk operasi pembacaan FTS**, ideal untuk data statis atau read-heavy.
- **GiST (Generalized Search Tree)**: Struktur indeks hierarki berbasis *lossy tree/signature*. Ukuran file indeksnya lebih kecil dan lebih cepat saat operasi `INSERT`/`UPDATE`, namun saat pencarian query FTS sedikit lebih lambat dari GIN karena memerlukan tahap verifikasi ulang (*re-check*).

#### 2. Implementasi di Project & Lokasi Kode:
- **Lokasi Kode Pembuatan Indeks**:
  - File: `schema.sql` (Baris 63–76)
  ```sql
  -- Membuat Index GIN
  CREATE INDEX idx_amazon_reviews_fts_gin ON amazon_reviews USING GIN(search_vector);

  -- Membuat Index GiST
  CREATE INDEX idx_amazon_reviews_fts_gist ON amazon_reviews USING GiST(search_vector);
  ```
- **Lokasi Pengujian & UI Admin (Live Benchmark & EXPLAIN ANALYZE)**:
  - File: `admin.php` (Baris 17–64): Backend simulator yang menjalankan `EXPLAIN (ANALYZE, BUFFERS)` dengan manipulasi optimizer PostgreSQL:
    - *Sequential Scan*: `SET LOCAL enable_seqscan = on; SET LOCAL enable_indexscan = off; SET LOCAL enable_bitmapscan = off;`
    - *Index Scan (GIN/GiST)*: `SET LOCAL enable_seqscan = off; SET LOCAL enable_indexscan = on; SET LOCAL enable_bitmapscan = on;`
  - File: `admin.php` (Baris 140–185): Form UI **Live EXPLAIN ANALYZE Tester**. Admin tinggal memasukkan kata kunci, memilih dropdown *Sequential Scan* / *GIN* / *GiST*, lalu menekan tombol **"Uji & Generate EXPLAIN"**.
  - File: `admin.php` (Baris 186–270): Tabel log pencarian pengguna yang dilengkapi tombol **"Lihat EXPLAIN"** untuk melihat execution plan setiap pencarian user dalam modal pop-up interaktif.
  - *Fitur ini memudahkan Anda mengambil capture/screenshot perbandingan Execution Time (ms) sebelum dan sesudah index untuk laporan UTS.*

---

### E. Implementasi Advanced Querying & Relevance Ranking

#### 1. Penjelasan Konsep:
Aplikasi menyediakan 4 mode pencarian teks tingkat lanjut (*Advanced Querying*):
1. **Web Search (`websearch_to_tsquery`)**: Sintaks pencarian ramah pengguna ala Google (mendukung kutip `"phrase search"`, kata biasa, dan tanda minus `-kata` untuk mengecualikan kata).
2. **Boolean Search (`to_tsquery`)**: Mendukung ekspresi logika matematika formal:
   - `&` (AND): Contoh `refund & return` (kedua kata harus ada).
   - `|` (OR): Contoh `damaged | broken` (salah satu kata ada).
   - `!` (NOT): Contoh `delivery & !delayed` (ada delivery tapi tanpa delayed).
3. **Phrase Search (`phraseto_tsquery`)**: Memastikan urutan kata yang dicari berurutan persis menggunakan operator positional distance `<->`. Contoh: `"customer service"`.
4. **Plain Search (`plainto_tsquery`)**: Mengubah seluruh kata inputan teks mentah menjadi gabungan logika AND.

#### 2. Implementasi di Project & Lokasi Kode:
- **Lokasi Kode Logika Backend**:
  - File: `index.php` (Baris 24–45)
  ```php
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
  ```
- **Lokasi Kode Tampilan UI**:
  - File: `index.php` (Baris 140–170): Dropdown pilihan **Mode Query** dan pilihan **Metode Ranking** (`ts_rank` vs `ts_rank_cd`).
  - File: `index.php` (Baris 171–180): Kotak petunjuk contoh pencarian interaktif untuk memudahkan pengujian berbagai kombinasi query.
