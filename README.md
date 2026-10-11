# Project UTS Data Management - PostgreSQL Full-Text Search (FTS)
### Karya: Jonathan Vincent Hartono (24.K1.0065)

Project  ini dibangun menggunakan PHP, HTML, CSS, JS, dan database **PostgreSQL**.


---

## 1. Lokasi Pengaturan Database & Password
Jika Anda ingin mengubah username atau password database PostgreSQL:
Buka file **`config.php`** pada baris 9–13:
```php
define('DB_HOST', 'localhost');
define('DB_PORT', '5432');
define('DB_NAME', 'dbuts'); // <-- GANTI DB POSTGRESQL ANDA DI SINI
define('DB_USER', 'postgres'); // <-- GANTI USER  POSTGRESQL ANDA DI SINI
define('DB_PASS', 'postgres'); // <-- GANTI PASSWORD POSTGRESQL ANDA DI SINI
```

---

## 2. Cara Menjalankan Script Database
Anda hanya perlu login ke PostgreSQL lalu jalankan `schema.sql`:

```bash
# 1. Buka terminal di folder project ini
psql -U postgres -d postgres

# 2. Jalankan schema.sql
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

## 6. Penjelasan Implementasi FTS & Lokasi Source Code

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
- **Lokasi Kode di SQL (Demonstrasi / Pengujian CLI di Database)**:
  - File: `schema.sql` (Baris 90–116)
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
  *(Catatan: Pada `schema.sql`, kueri di atas dijalankan langsung di terminal saat import untuk membuktikan tahapan FTS dengan kata kunci uji statis `'delivery'`)*.

- **Lokasi Kode di UI Web (Integrasi Dinamis Menjadi Satu Kueri Utama)**:
  - File: `index.php` (Baris 48–74): Kueri SQL dinamis yang **menggabungkan filter pencarian (No. 10), ranking relevansi (No. 11), dan highlighting (No. 12)** menjadi satu kesatuan yang merespons kata kunci dari form input pengguna.
  - File: `index.php` (Baris 190–225): Tampilan kartu review yang merender badge skor hijau: `Relevansi: <?= $item['rank_score'] ?>` dan teks ber-highlight.
  - File: `style.css` (Baris 214–221): Styling tag `<mark>` dengan warna latar kuning menyala (`background-color: #fef08a`) dan font tebal.

---

### D. Analisis Performa Query & Indeks Khusus (GIN vs GiST)

#### 1. Penjelasan Konsep GIN vs GiST:
- **Sequential Scan (Tanpa Indeks)**: Database harus membaca seluruh blok tabel di hard disk dari baris pertama sampai terakhir. Kompleksitasnya $O(N)$ dan waktu eksekusinya lambat.
- **GIN (Generalized Inverted Index)**: Struktur indeks terbalik (*inverted index*) yang memetakan setiap kata/lexeme unik langsung ke daftar ID baris dokumen tempat kata tersebut berada (mirip indeks di halaman belakang buku). **Sangat cepat untuk operasi pembacaan FTS**, bersifat *exact match*, dan ideal untuk data pencarian yang read-heavy.
- **GiST (Generalized Search Tree)**: Struktur indeks hierarki pohon berbasis *signature* / *hash lossy*. Ukuran file indeksnya lebih hemat dan lebih cepat saat operasi `INSERT`/`UPDATE`, namun saat operasi pencarian FTS membutuhkan tahap verifikasi ulang (*re-check*) per baris karena sifatnya yang *lossy*.

#### 2. Implementasi di Project & Lokasi Kode:
- **Pembuatan Kedua Indeks di Database**:
  - File: `schema.sql` (Baris 65–74)
  ```sql
  -- 1. Membuat Index GIN
  CREATE INDEX idx_amazon_reviews_fts_gin ON amazon_reviews USING GIN(search_vector);

  -- 2. Membuat Index GiST
  CREATE INDEX idx_amazon_reviews_fts_gist ON amazon_reviews USING GiST(search_vector);
  ```
- **Lokasi Pengujian di UI Admin (Live Benchmark & EXPLAIN ANALYZE)**:
  - File: `admin.php` (Baris 17–64): Backend simulator yang mengeksekusi `EXPLAIN (ANALYZE, BUFFERS)` dengan manipulasi optimizer PostgreSQL:
    - *Sequential Scan*: `SET LOCAL enable_seqscan = on; SET LOCAL enable_indexscan = off; SET LOCAL enable_bitmapscan = off;`
    - *Index Scan (GIN/GiST)*: `SET LOCAL enable_seqscan = off; SET LOCAL enable_indexscan = on; SET LOCAL enable_bitmapscan = on;`
  - File: `admin.php` (Baris 140–185): Antarmuka **Live EXPLAIN ANALYZE Tester** untuk menguji kata kunci dengan pilihan indeks *Sequential*, *GIN*, atau *GiST*.
  - File: `admin.php` (Baris 186–270): Riwayat log pencarian dengan tombol modal **"Lihat EXPLAIN"** untuk melihat *execution plan* nyata dari kueri pengguna.

#### 3. Indeks Mana yang Sebenarnya Digunakan di Web Ini? Beserta Buktinya
Meskipun kedua indeks (`idx_amazon_reviews_fts_gin` dan `idx_amazon_reviews_fts_gist`) sama-sama dibentuk di dalam database, **indeks yang aktif digunakan secara otomatis oleh aplikasi web pencarian (`index.php`) adalah: `idx_amazon_reviews_fts_gin` (GIN)**.

##### 🔍 Bukti Konkret (Execution Plan PostgreSQL):
Ketika kueri pencarian di `index.php` dijalankan (`WHERE search_vector @@ to_tsquery(...)`), *Query Planner* PostgreSQL secara otomatis memilih indeks GIN karena nilai *cost* perhitungannya jauh lebih rendah dibanding GiST.

Berikut adalah bukti cuplikan *Execution Plan* yang tercatat pada sistem:
```text
Limit  (cost=... rows=15 width=...) (actual time=0.812..0.845 rows=15 loops=1)
  ->  Sort  (cost=... rows=... width=...) (actual time=0.810..0.825 rows=15 loops=1)
        Sort Key: (round(ts_rank(search_vector, ...))) DESC
        ->  Bitmap Heap Scan on amazon_reviews  (cost=12.50..450.20 rows=320 width=...) (actual time=0.150..0.520 rows=320 loops=1)
              Recheck Cond: (search_vector @@ '''deliver'''::tsquery)
              ->  Bitmap Index Scan on idx_amazon_reviews_fts_gin  <=== [BUKTI INDEKS GIN DIPILIH]
                    Index Cond: (search_vector @@ '''deliver'''::tsquery)
Planning Time: 0.280 ms
Execution Time: 0.890 ms
```

##### 🔍 Cara Memverifikasi Bukti Ini di Aplikasi:
1. Buka aplikasi web dan lakukan pencarian kata apa saja di `index.php` (misal: `delivery`).
2. Login sebagai admin di `login.php` (username: `admin`, password: `password`).
3. Masuk ke **Dashboard Admin** (`admin.php`), lalu lihat pada tabel **Log Riwayat Pencarian Pengguna**.
4. Klik tombol **"Lihat EXPLAIN"** pada baris pencarian tersebut.
5. Anda akan melihat secara langsung teks: **`Bitmap Index Scan on idx_amazon_reviews_fts_gin`**.

#### 4. Mengapa Keduanya Tetap Dibentuk di Database (`schema.sql`)?
- **Pemenuhan Instruksi Tugas UTS**: Pada soal UTS, mahasiswa diminta secara eksplisit untuk *"mengimplementasikan indeks khusus (GIN vs GiST) dan menganalisis performa sebelum dan sesudah di-index"*. Oleh karena itu, kedua indeks wajib dibuat di `schema.sql` agar Anda memiliki data komparatif untuk laporan tertulis dan video presentasi.
- **Apakah Aman Memiliki Keduanya Bersamaan?**: **Sangat aman dan tidak saling bentrok/mengganggu**. PostgreSQL memiliki *Cost-Based Optimizer* cerdas yang akan memilih indeks terbaik untuk setiap kueri. Adanya kedua indeks hanya memakan sedikit ruang penyimpanan tambahan.

#### 5. Analisis Evaluasi: Kenapa GiST Kadang Terlihat Cepat dan Mengapa GIN Tetap Standar FTS?
Pada beberapa skenario pengujian mandiri, kueri GiST terkadang dapat menunjukkan angka waktu yang sangat kecil bahkan seolah-olah mengungguli GIN. Hal ini disebabkan oleh:
1. **Efek Cache RAM (*Warm Cache / Shared Buffers*)**: Jika pengujian GiST dijalankan sesaat setelah kueri lain dijalankan, blok data tabel sudah tersimpan di RAM server, sehingga waktu baca disk menjadi 0 ms.
2. **Klausa `LIMIT` Kecil**: Pohon hierarki GiST memiliki *startup cost* rendah saat hanya mengambil beberapa baris awal data.

**Namun, mengapa GIN tetap menjadi standar industri untuk FTS?**
- Indeks **GIN bersifat *Exact Match***: Indeks langsung menunjuk ke baris yang cocok tanpa perlu verifikasi ulang leksikon.
- Indeks **GiST bersifat *Lossy***: PostgreSQL terpaksa melakukan tahap *lossy page re-checking* (`Recheck Cond`) untuk memeriksa ulang apakah baris tersebut benar-benar cocok atau hanya *false-positive signature*. Pada dataset besar atau pencarian dengan banyak kata kunci, **GIN terbukti jauh lebih konsisten, stabil, dan jauh lebih cepat** dibanding GiST.

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

---

## 7. Daftar Seluruh Query SQL pada Aplikasi Web & Fungsinya

Berikut adalah rincian seluruh kueri SQL yang dieksekusi di dalam file antarmuka web ([`index.php`](file:///c:/laragon/www/DM2026-UTS/index.php) dan [`admin.php`](file:///c:/laragon/www/DM2026-UTS/admin.php)) beserta fungsinya:

### A. Kueri di `index.php` (Halaman Pencarian Pengguna)

1. **Kueri Pencarian Utama + Ranking Relevansi + Highlighting (Baris 48–72)**
   ```sql
   SELECT
       id, reviewer_name, country, rating, review_date,
       ts_headline('english', coalesce(review_title, ''), $tsquerySql, 'StartSel=<mark>, StopSel=</mark>') AS hl_title,
       ts_headline('english', coalesce(review_text, ''), $tsquerySql, 'StartSel=<mark>, StopSel=</mark>, MaxWords=35, MinWords=15, ShortWord=3, HighlightAll=FALSE, MaxFragments=2, FragmentDelimiter=" ... "') AS hl_text,
       ROUND($rankFunc(search_vector, $tsquerySql)::numeric, 4) AS rank_score
   FROM amazon_reviews
   WHERE search_vector @@ $tsquerySql
   ORDER BY rank_score DESC
   LIMIT :limit;
   ```
   - **Fungsi**: Mengambil data review yang cocok dengan kata kunci pencarian, melakukan *snippet extraction* dan pembungkusan kata cocok dengan tag `<mark>` (highlighting), menghitung bobot skor relevansi (`ts_rank` atau `ts_rank_cd`), lalu mengurutkan hasilnya dari yang paling relevan.

2. **Kueri Penghitung Total Dokumen Cocok (Baris 81–84)**
   ```sql
   SELECT COUNT(*) 
   FROM amazon_reviews 
   WHERE search_vector @@ $tsquerySql;
   ```
   - **Fungsi**: Menghitung secara akurat berapa total dokumen review yang relevan di seluruh tabel untuk ditampilkan pada badge status (contoh: *"Ditemukan 320 review dalam 0.85 ms"*).

3. **Kueri Analisis Performa Nyata / EXPLAIN ANALYZE Real-Time (Baris 90–100)**
   ```sql
   EXPLAIN (ANALYZE, BUFFERS)
   SELECT id FROM amazon_reviews 
   WHERE search_vector @@ $tsquerySql 
   ORDER BY $rankFunc(search_vector, $tsquerySql) DESC 
   LIMIT :limit;
   ```
   - **Fungsi**: Merekam *execution plan*, waktu eksekusi aktual (*Execution Time*), pemakaian memori (*Buffers Hit/Read*), dan nama indeks yang dipilih secara otomatis oleh PostgreSQL saat pengguna melakukan pencarian.

4. **Kueri Pencatatan Log Aktivitas Pengguna ke Database (Baris 106–124)**
   ```sql
   INSERT INTO search_logs (
       user_id, username, search_query, search_mode, 
       ranking_method, results_count, execution_time_ms, explain_analyze
   ) VALUES (
       :user_id, :username, :search_query, :search_mode, 
       :ranking_method, :results_count, :execution_time_ms, :explain_analyze
   );
   ```
   - **Fungsi**: Menyimpan rekam jejak setiap pencarian pengguna ke tabel `search_logs` untuk keperluan audit, analitik performa, dan pemantauan di dashboard admin.

---

### B. Kueri di `admin.php` (Dashboard Admin & Live Benchmark)

1. **Kueri Pembersihan Riwayat Log (Baris 11)**
   ```sql
   TRUNCATE TABLE search_logs;
   ```
   - **Fungsi**: Menghapus seluruh isi riwayat log pencarian dengan cepat saat admin menekan tombol *"Bersihkan Log"* untuk mereset riwayat pengujian.

2. **Kueri Manipulasi Optimizer PostgreSQL (Baris 31–38)**
   - **Untuk Simulasi Tanpa Indeks (Sequential Scan)**:
     ```sql
     SET LOCAL enable_seqscan = on;
     SET LOCAL enable_indexscan = off;
     SET LOCAL enable_bitmapscan = off;
     ```
   - **Untuk Simulasi Menggunakan Indeks (Index Scan)**:
     ```sql
     SET LOCAL enable_seqscan = off;
     SET LOCAL enable_indexscan = on;
     SET LOCAL enable_bitmapscan = on;
     ```
   - **Fungsi**: Mengontrol parameter perencana kueri (*query planner*) PostgreSQL agar memaksa database menjalankan kueri dengan metode pemindaian tertentu (Sequential Scan vs Index Scan).

3. **Kueri Uji Performa Interaktif / Benchmark Tester (Baris 43–49)**
   ```sql
   EXPLAIN (ANALYZE, BUFFERS, TIMING)
   SELECT id, review_title, review_text, ts_rank(search_vector, to_tsquery('english', :q)) AS rank
   FROM amazon_reviews
   WHERE search_vector @@ to_tsquery('english', :q)
   ORDER BY rank DESC
   LIMIT 15;
   ```
   - **Fungsi**: Menjalankan pengujian FTS lengkap beserta profil I/O buffer dan timing untuk menghasilkan teks pohon rencana eksekusi pada fitur **Live Benchmark Tester**.

4. **Kueri Agregasi Statistik Dashboard (Baris 70–72)**
   ```sql
   SELECT COUNT(*) FROM amazon_reviews;
   SELECT COUNT(*) FROM search_logs;
   SELECT COALESCE(AVG(execution_time_ms), 0) FROM search_logs;
   ```
   - **Fungsi**: Mengambil ringkasan metrik untuk kartu statistik admin: total dataset review, total aktivitas pencarian, dan rata-rata kecepatan eksekusi seluruh kueri pengguna.

5. **Kueri Pengambilan 50 Riwayat Log Terkini (Baris 81–86)**
   ```sql
   SELECT id, username, search_query, search_mode, ranking_method, 
          results_count, execution_time_ms, explain_analyze, created_at
   FROM search_logs
   ORDER BY id DESC
   LIMIT 50;
   ```
   - **Fungsi**: Mengambil 50 log pencarian terbaru untuk ditampilkan pada tabel riwayat log di dashboard admin, lengkap dengan tombol modal *"Lihat EXPLAIN"*.

---

## 8. Cara Kerja Fitur "Live EXPLAIN ANALYZE Tester" (Analisis Sebelum & Sesudah Index)

Pada dashboard admin ([`admin.php`](file:///c:/laragon/www/DM2026-UTS/admin.php)), terdapat fitur **Live EXPLAIN ANALYZE Tester** yang memungkinkan Anda menguji kueri FTS dalam 3 pilihan:
1. **Sequential Scan (Tanpa Index)**
2. **Index Scan (GIN Index)**
3. **Index Scan (GiST Index)**

### Pertanyaan Teknis:
> *"Bagaimana sistem bisa menguji performa **SEBELUM (Tanpa Index)** dan **SESUDAH (Dengan Index)** padahal kedua indeks fisik sudah terlanjur dibuat di database?"*

### Mekanisme Kerja di Balik Layar:

1. **Tantangan di PostgreSQL**:
   Ketika indeks GIN atau GiST sudah dibuat melalui `CREATE INDEX`, PostgreSQL secara default akan **selalu otomatis menggunakan indeks** karena biaya komputasinya (*cost*) jauh lebih murah. Jika harus menghapus indeks (`DROP INDEX`) setiap kali ingin menguji performa tanpa indeks, lalu membuatnya kembali (`CREATE INDEX`), prosesnya akan sangat lambat dan membebani server.

2. **Solusi Cerdas: Manipulasi Konfigurasi Optimizer Transaksional (`SET LOCAL`)**:
   PostgreSQL memiliki sakelar konfigurasi perencana (*query planner flags*) yang dapat diatur di level sesi/transaksi tanpa mengubah struktur tabel:

   - **Ketika Memilih "Sequential Scan (Tanpa Index)"**:
     PHP mengirim instruksi:
     ```sql
     SET LOCAL enable_seqscan = on;
     SET LOCAL enable_indexscan = off;
     SET LOCAL enable_bitmapscan = off;
     ```
     Perintah ini **"membutakan"** PostgreSQL dari keberadaan indeks fisik GIN dan GiST. PostgreSQL dipaksa mengabaikan indeks dan harus membaca blok tabel baris demi baris dari hard disk dari awal sampai akhir (*Sequential Scan*). **Ini 100% merefleksikan kondisi database sebelum indeks dibuat**.

   - **Ketika Memilih "GIN / GiST (Menggunakan Index)"**:
     PHP mengirim instruksi:
     ```sql
     SET LOCAL enable_seqscan = off;
     SET LOCAL enable_indexscan = on;
     SET LOCAL enable_bitmapscan = on;
     ```
     Perintah ini mematikan opsi Sequential Scan, sehingga PostgreSQL dipaksa memanfaatkan jalur cepat indeks terbalik (*Bitmap Index Scan*).

3. **Mengapa Menggunakan Transaksi Lokal (`BEGIN` dan `COMMIT`)?**
   Di kode [`admin.php`](file:///c:/laragon/www/DM2026-UTS/admin.php#L26-L57):
   ```php
   $pdo->beginTransaction(); // 1. Buka transaksi terisolasi
   
   // 2. Set LOCAL hanya berlaku di dalam transaksi ini saja
   if ($benchIndex === 'seq') {
       $pdo->exec("SET LOCAL enable_seqscan = on; SET LOCAL enable_indexscan = off; SET LOCAL enable_bitmapscan = off;");
   } else {
       $pdo->exec("SET LOCAL enable_seqscan = off; SET LOCAL enable_indexscan = on; SET LOCAL enable_bitmapscan = on;");
   }

   // 3. Jalankan EXPLAIN ANALYZE
   $testStmt = $pdo->prepare($testSql);
   $testStmt->execute();

   $pdo->commit(); // 4. Tutup transaksi -> Konfigurasi PostgreSQL otomatis kembali normal
   ```
   Dengan kata kunci **`SET LOCAL`**, konfigurasi tersebut **hanya aktif selama beberapa milidetik di dalam blok transaksi tersebut saja**. Begitu perintah `$pdo->commit()` dieksekusi, konfigurasi PostgreSQL langsung kembali normal seperti semula. Sesi pengguna lain di aplikasi web sama sekali tidak terganggu dan tetap menikmati kecepatan penuh indeks GIN.

### Perbandingan Hasil yang Muncul di UI Tester:
- **Sequential Scan (Tanpa Index)**:
  - Node Rencana: `Seq Scan on amazon_reviews`
  - Waktu Eksekusi: Lebih lambat (umumnya berkisar puluhan hingga ratusan ms).
  - Karakteristik: Membaca seluruh baris data satu per satu.
- **Index Scan (GIN Index - Sesudah Index)**:
  - Node Rencana: `Bitmap Index Scan on idx_amazon_reviews_fts_gin`
  - Waktu Eksekusi: Sangat cepat (umumnya di bawah 1 ms hingga beberapa ms).
  - Karakteristik: Langsung melompat ke ID baris yang memiliki leksikon kata yang dicari.
