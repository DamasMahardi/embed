<?php

declare(strict_types=1);

use App\Crawl\SiteConfig;
use App\Qdrant\QdrantClient;
use App\Support\Env;

$base = dirname(__DIR__);

return [
    'app' => [
        'name' => 'Crawler Web -> Parse -> Embed (PHP)',
        // Zona waktu dipakai untuk tanggal/jam pada log txt.
        'timezone' => Env::get('APP_TIMEZONE', 'Asia/Jakarta'),
    ],

    /*
     * Service worker-ingest (FastAPI) yang menyediakan /health, /parse, /embed.
     * Crawler tidak pernah memanggil URL target secara langsung ke service ini:
     * crawler mengunduh HTML, menyimpannya sebagai file .html, lalu upload file
     * tersebut ke /parse (hasil markdown), kemudian chunk dikirim ke /embed.
     */
    'service' => [
        'base_url' => Env::get('INGEST_SERVICE_URL', 'http://localhost:8001'),
        'health_path' => '/health',
        'parse_path' => '/parse',
        'embed_path' => '/embed',
        'token' => (string) Env::get('INGEST_SERVICE_TOKEN', ''),
        'connect_timeout' => Env::int('INGEST_CONNECT_TIMEOUT', 10),
        'parse_timeout' => Env::int('INGEST_PARSE_TIMEOUT', 600),
        'parse_max_pages' => Env::int('INGEST_PARSE_MAX_PAGES', 500),
        'embed_timeout' => Env::int('INGEST_EMBED_TIMEOUT', 300),
        'retries' => Env::int('INGEST_RETRIES', 3),
        'retry_delay_ms' => Env::int('INGEST_RETRY_DELAY_MS', 1000),
    ],

    /*
     * MySQL lokal (Laragon) untuk riwayat crawl per website: dipakai mencegah
     * duplikat (website sukses dilewati) dan mencatat yang gagal akses
     * ("failed_akses"). Database & tabel dibuat otomatis pada pemakaian pertama.
     */
    'mysql' => [
        'host' => Env::get('MYSQL_HOST', '127.0.0.1'),
        'port' => Env::int('MYSQL_PORT', 3306),
        'database' => Env::get('MYSQL_DB', 'crawler_embed'),
        'user' => Env::get('MYSQL_USER', 'root'),
        'password' => Env::get('MYSQL_PASS', ''),
    ],

    'crawl' => [
        'user_agent' => Env::get(
            'CRAWLER_USER_AGENT',
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) '
            . 'Chrome/124.0 Safari/537.36'
        ),
        'request_timeout' => Env::int('CRAWLER_REQUEST_TIMEOUT', 60),

        /*
         * Batas waktu TAHAP KONEKSI satu unduhan (detik). Sebagian situs .go.id
         * mengumumkan beberapa alamat A dan sebagian alamatnya membuang paket
         * (firewall) sehingga curl menunggu sampai batas ini habis walaupun
         * alamat lain sehat -- dukcapil.kemendagri.go.id misalnya punya
         * 118.97.79.23 (tidak menjawab) dan 36.66.118.179 (normal). Karena itu
         * kegagalan koneksi dicoba ulang ke alamat lain ('retry_addresses') dan
         * nilai kecil (5-10 detik) lebih baik daripada besar.
         */
        'connect_timeout' => Env::int('CRAWLER_CONNECT_TIMEOUT', 10),

        /*
         * true (bawaan) = bila unduhan gagal pada TAHAP KONEKSI (server tidak
         * pernah menjawab), crawler mencoba ULANG ke alamat IP lain hasil
         * resolusi host yang sama memakai CURLOPT_RESOLVE (Host/SNI tetap nama
         * host sehingga verifikasi TLS tidak berubah). Alamat yang berhasil
         * diingat dan dipakai untuk sisa run supaya host dengan alamat mati tidak
         * menghabiskan timeout koneksi pada setiap halaman. Kejadian ini dicatat
         * pada log txt sebagai tahap ADDR_RETRY; false = perilaku lama (satu
         * percobaan, unduhan gagal bila alamat pertama tidak menjawab).
         */
        'retry_addresses' => Env::bool('CRAWLER_RETRY_ADDRESSES', true),
        'max_bytes' => Env::int('CRAWLER_MAX_BYTES', 5_242_880),

        /*
         * Batas ukuran unduhan untuk URL DOKUMEN (berkas .pdf/.docx/.xlsx/...).
         * Berkas dokumen APBD/LPPD sering jauh lebih besar dari halaman HTML,
         * jadi batasnya dipisah dan defaultnya 64 MB.
         */
        'document_max_bytes' => Env::int('CRAWLER_DOCUMENT_MAX_BYTES', 67_108_864),

        /*
         * Jelajahi SITUS LAIN yang ditautkan halaman yang sedang di-crawl.
         * Contoh nyata: halaman www.kemendagri.go.id menautkan
         * otda.kemendagri.go.id, polpum.kemendagri.go.id, dan sebagainya --
         * tanpa setelan ini tautan tersebut dilewati karena hostnya berbeda.
         *
         *   off    = hanya host pada start_urls (perilaku lama);
         *   family = hanya host SATU KELUARGA DOMAIN (keluarga domain dari
         *            start_urls + subdomainnya, mis. *.kemendagri.go.id);
         *   all    = semua host http(s) yang ditemukan (dibatasi
         *            follow_external_max_hosts supaya tidak menjelajahi
         *            seluruh internet).
         *
         * Bisa ditimpa per entri sites.json ("follow_external") dan per run
         * (--follow-external=off|family|all). Kejadian dicatat pada log:
         * HOST_BARU, HOST_LIMIT, CROSS_SITE_SKIP.
         */
        'follow_external' => SiteConfig::followExternalMode(Env::get('CRAWLER_FOLLOW_EXTERNAL', 'family')),
        'follow_external_max_hosts' => max(0, Env::int('CRAWLER_FOLLOW_EXTERNAL_MAX_HOSTS', 25)),

        /*
         * Tautan GOOGLE DRIVE pada halaman yang di-crawl: folder publik
         * dibaca isinya lalu setiap berkasnya diunduh -> /parse -> /embed ->
         * Qdrant; berkas Google Docs/Sheets/Slides diekspor ke docx/xlsx/pptx.
         * Per entri sites.json boleh dimatikan ("google_drive": false).
         * Batas berkas per folder: CRAWLER_GDRIVE_MAX_FILES (bawaan 200).
         * Log: GDRIVE_SCAN, GDRIVE_QUEUE, GDRIVE_GAGAL, GDRIVE_SKIP.
         */
        'google_drive' => Env::bool('CRAWLER_GOOGLE_DRIVE', true),
        'gdrive_max_files' => max(1, Env::int('CRAWLER_GDRIVE_MAX_FILES', 200)),

        /*
         * Catat hasil crawl ke MySQL lokal (lihat blok "mysql"): website yang
         * sukses dicatat "success" (dilewati pada run berikutnya bila
         * mysql_skip_success=true), yang gagal akses dicatat "failed_akses".
         */
        'mysql_record' => Env::bool('CRAWLER_MYSQL_RECORD', true),
        'mysql_skip_success' => Env::bool('CRAWLER_MYSQL_SKIP_SUCCESS', true),
        // Batas jumlah kandidat host yang di-probe saat pencarian domain.
        'discovery_max_hosts' => max(5, Env::int('CRAWLER_DISCOVERY_MAX_HOSTS', 80)),

        /*
         * Tautan berkas dokumen (.pdf/.docx/.xlsx/...) yang ditemukan PADA
         * halaman web: bila true, crawler mengikutinya seperti tautan biasa
         * tetapi URL-nya diproses dalam MODE DOKUMEN -- berkasnya diunduh apa
         * adanya (batas document_max_bytes), tidak dirender JavaScript, dan
         * dikirim ke /parse supaya parser dipilih dari ekstensi berkas
         * (mis. parse_pdf.py untuk .pdf).
         *
         * Bila false (bawaan), tautan dokumen dilewati seperti sebelumnya:
         * PDF hanya terambil bila URL-nya ditulis sebagai entri dokumen pada
         * config/sites.json.
         *
         * Catatan:
         *  - tautan dokumen diprioritaskan: URL-nya disisipkan di depan antrean
         *    sehingga PDF pada halaman detail tidak kalah kuota dari halaman
         *    daftar/paginasi (kuota max_pages site dan max_requests_per_crawl
         *    run tetap berlaku);
         *  - berkas yang ditulis sebagai <object data>/<embed src>/<iframe src>
         *    (pratinjau PDF) ikut dikenali, bukan hanya <a href>;
         *  - endpoint unduhan tanpa ekstensi (mis. "/unduh?id=12" atau
         *    "/front/dokumen/download/12") juga diikuti; jenis berkasnya
         *    ditentukan dari Content-Type, Content-Disposition, atau isi berkas
         *    sehingga application/octet-stream pun terdeteksi;
         *  - host dokumen tetap harus lolos aturan host site, jadi lampiran
         *    di host lain perlu "same_host_only": false pada entri site;
         *  - gambar/aset/arsip (.jpg/.zip/.css/...) tetap dilewati; daftar
         *    ekstensi yang dilewati bisa diubah lewat crawl.skip_extensions.
         */
        // Nama utama CRAWLER_FOLLOW_DOCUMENT_LINKS (sejalan dengan variabel
        // CRAWLER_* lain dan pesan pada `php bin/crawl.php list`). Nama pendek
        // CRAWL_FOLLOW_DOCUMENT_LINKS tetap dibaca sebagai alias, dipakai HANYA
        // bila nama utama tidak diisi (mis. .env lama yang sudah memakai nama
        // pendek). Bila keduanya diisi, nama utama yang menang.
        'follow_document_links' => Env::bool(
            'CRAWLER_FOLLOW_DOCUMENT_LINKS',
            Env::bool('CRAWL_FOLLOW_DOCUMENT_LINKS', false)
        ),
        'respect_robots' => Env::bool('CRAWLER_RESPECT_ROBOTS', true),
        'default_delay_ms' => Env::int('CRAWLER_DELAY_MS', 1000),
        'save_html' => Env::bool('CRAWLER_SAVE_HTML', true),

        /*
         * true (bawaan) = berkas DOKUMEN mentah (PDF/DOCX/XLSX) yang vektornya
         * sudah terkirim ke Qdrant DIHAPUS dari storage/documents supaya tidak
         * menumpuk dan tidak menghabiskan penyimpanan. Isi dokumen tetap bisa
         * dicari karena markdown, payload Qdrant, dan berkas vektor (.jsonl /
         * .qdrant.json) tidak dihapus.
         *
         * Penghapusan hanya dilakukan SETELAH push Qdrant untuk site itu selesai
         * (QDRANT_PUSH_AFTER_CRAWL / --push-qdrant); bila push dimatikan, berkas
         * tetap disimpan. Dokumen yang gagal diparse/di-embed (tidak menghasilkan
         * vektor) juga tetap disimpan supaya masih bisa diproses ulang.
         * Per entri sites.json: "delete_documents_after_push": false.
         */
        'delete_documents_after_push' => Env::bool('CRAWLER_DELETE_DOCUMENTS_AFTER_PUSH', true),

        /*
         * Penemuan berkas dokumen OTOMATIS (bawaan: aktif). Setiap halaman yang
         * dirender browser headless dicatat log jaringannya (Chrome NetLog),
         * sehingga berkas/API yang dipanggil halaman bisa dibaca crawler:
         *   1. URL berkas dokumen yang diminta halaman langsung diantrekan,
         *   2. endpoint JSON yang dipanggil halaman dipanggil ulang (GET saja)
         *      lalu isinya ditelusuri untuk mencari berkas dokumen (PDF/XLSX/...);
         *      path relatif diselesaikan ke basis yang benar dengan probe HEAD
         *      (mis. https://backend.kemendagri.go.id/uploads) sehingga tidak
         *      menghasilkan ratusan URL 404.
         * Ini yang membuat tautan unduhan yang TIDAK ADA di HTML (dibuat
         * JavaScript, mis. /informasi-publik Kemendagri) tetap terunduh ->
         * /parse -> /embed -> Qdrant tanpa aturan per situs. Endpoint pelacakan
         * (analytics/visitor-track) dan host pihak ketiga tidak dipanggil.
         *
         * auto_documents_max  : batas jumlah berkas per halaman.
         * auto_documents_apis : batas endpoint API yang dipanggil ulang per halaman.
         * Per entri sites.json: "auto_documents": false.
         * Kejadiannya dicatat pada log: DOC_AUTO / DOC_AUTO_QUEUE / DOC_AUTO_SKIP.
         */
        'auto_documents' => Env::bool('CRAWLER_AUTO_DOCUMENTS', true),
        'auto_documents_max' => max(1, Env::int('CRAWLER_AUTO_DOCUMENTS_MAX', 200)),
        'auto_documents_apis' => max(0, Env::int('CRAWLER_AUTO_DOCUMENTS_APIS', 8)),
        'max_redirects' => 5,

        /*
         * Batas TOTAL permintaan (unduhan halaman) untuk SATU run crawl,
         * dihitung lintas semua site. 0 = tanpa batas (BAWAAN) supaya crawl
         * bisa maksimal; isi nilai > 0 hanya bila ingin membatasi (mis. agar
         * run panjang 5 site tidak mengunduh tanpa henti).
         *
         * Bila kuota habis di tengah run, sisa antrean tidak diproses dan
         * dicatat pada log: LIMIT sebab=max_requests_per_crawl dan LIMIT_RUN.
         */
        'max_requests_per_crawl' => max(0, Env::int('MAX_REQUESTS_PER_CRAWL', 0)),

        /*
         * Jumlah halaman yang diunduh BERSAMAAN dalam satu batch
         * (curl_multi). 1 = satu per satu seperti sebelumnya (jeda antar
         * permintaan tetap per URL). Nilai > 1 membuat jeda 'rate_limit_ms'
         * berlaku antar BATCH, jadi pakai nilai kecil untuk web yang sensitif.
         *
         * Tahap /parse dan /embed tetap berurutan (satu permintaan ke service
         * pada satu waktu), jadi konkurensi tidak membebani worker-ingest.
         */
        'concurrency' => max(1, Env::int('CRAWL_CONCURRENCY', 5)),

        /*
         * Batas halaman per site untuk site yang tidak menulis "max_pages"
         * pada config/sites.json. 0 = TANPA BATAS (BAWAAN): seluruh halaman
         * dan berkas dokumen yang ditemukan diproses sampai antrean habis
         * sehingga hasil crawl maksimal.
         *
         * Isi nilai > 0 hanya bila ingin membatasi (mis. uji coba cepat).
         * Entri site pada config/sites.json, kolom "Batas halaman / site" pada
         * dashboard, dan opsi CLI --max-pages=N tetap bisa menimpanya per
         * site/per run. Bila batas tercapai, log mencatat:
         * LIMIT sebab=max_pages (sisa antrean tidak diproses).
         */
        'max_pages' => max(0, Env::int('CRAWL_MAX_PAGES', 0)),

        /*
         * Kedalaman tautan default untuk site yang tidak menulis "max_depth"
         * pada config/sites.json: 0 = hanya start_urls, 1 = boleh ikut tautan
         * dari start_urls, dst. -1 = TANPA BATAS (BAWAAN): seluruh situs
         * dijelajahi sampai tidak ada tautan baru sehingga tidak ada data yang
         * terlewat (lihat CRAWL_MAX_DEPTH pada .env).
         * Site/tombol UI/opsi CLI --max-depth=N tetap bisa menimpanya.
         */
        'max_depth' => max(-1, Env::int('CRAWL_MAX_DEPTH', -1)),

        /*
         * Sakelar GLOBAL "follow_links": nilai bawaan untuk SEMUA site yang
         * tidak menulis "follow_links" pada config/sites.json (lihat
         * SiteRepository::defaults()).
         *
         * true  = crawler mengikuti tautan halaman sampai max_depth site;
         * false = hanya start_urls yang diunduh.
         *
         * Nilai ini hanya bawaan: entri site pada config/sites.json, opsi CLI
         * --follow/--no-follow, dan kotak centang "Ikuti tautan" pada UI tetap
         * bisa menimpanya per site/per run.
         *
         * Catatan: entri bertipe DOKUMEN (kind dokumen / URL berkas) tetap
         * memakai follow_links=false sebagai bawaan -- lihat SiteConfig -- jadi
         * satu PDF pada sites.json tidak ikut menelusuri tautan di dalamnya.
         */
        'follow_links' => Env::bool('CRAWLER_FOLLOW_LINKS', true),

        /*
         * TLS. Sebagian situs .go.id mengirim rantai sertifikat tidak lengkap
         * (hanya sertifikat leaf, tanpa intermediate) sehingga curl gagal dengan
         * error 60 "unable to get local issuer certificate" walaupun di browser
         * normal. Bila 'insecure_retry' = true, crawler mengulang permintaan
         * TANPA verifikasi sertifikat untuk host tersebut dan mencatatnya pada
         * log txt (tahap TLS_INSECURE) supaya tetap transparan.
         *
         * Isi 'ca_bundle' bila ingin memakai CA bawaan sendiri (mis. hasil
         * curl.se/ca/cacert.pem) sebagai pengganti curl.cainfo milik php.ini.
         */
        'verify_tls' => Env::bool('CRAWLER_VERIFY_TLS', true),
        'insecure_retry' => Env::bool('CRAWLER_INSECURE_RETRY', true),
        'ca_bundle' => (string) Env::get('CRAWLER_CA_BUNDLE', ''),

        /*
         * Situs berbasis JavaScript (Next.js/Nuxt/Vue dsb.) mengirim HTML
         * kerangka saat diunduh tanpa menjalankan JavaScript: yang terunduh
         * hanya menu, tagline, dan footer sehingga isi halaman tidak pernah
         * ikut ke chunk/Qdrant. Crawler menanganinya sendiri dengan merender
         * halaman memakai browser headless yang sudah ada di mesin
         * (Chrome/Edge/Chromium) lalu membaca DOM-nya, jadi tidak perlu aturan
         * API per situs. Lihat src/Crawl/HeadlessRenderer.php.
         *
         * CRAWLER_JS_RENDER: off | auto | always
         *   - off   : tidak pernah merender (HTML apa adanya)
         *   - auto  : hanya halaman yang terdeteksi kerangka SPA dirender
         *   - always: setiap halaman HTML dirender
         * CRAWLER_BROWSER: path biner browser (kosong = deteksi otomatis;
         *   "none" mematikan render tanpa mengubah mode).
         * CRAWLER_BROWSER_WAIT: batas waktu virtual (ms) yang diberikan browser
         *   untuk menjalankan JavaScript + mengambil data sebelum DOM diambil.
         * CRAWLER_BROWSER_TIMEOUT: batas proses browser per halaman (ms).
         * CRAWLER_BROWSER_FLAGS: argumen tambahan dipisah spasi (mis.
         *   "--ignore-certificate-errors" bila situs punya rantai TLS tidak
         *   lengkap).
         * CRAWLER_BROWSER_UA: UA yang dipakai browser headless (kosong = UA
         *   Chrome bawaan supaya situs tidak menolak "HeadlessChrome").
         * CRAWLER_RENDER_MIN_TEXT / CRAWLER_RENDER_MAX_TEXT_RATIO: ambang
         *   deteksi halaman kerangka (teks terlihat di bawah nilai ini dan ada
         *   penanda SPA / rasio teks sangat kecil).
         * CRAWLER_RENDER_TEMP_HOURS: umur maksimal (jam) sisa berkas sementara
         *   render (folder profil browser, DOM, NetLog) sebelum dibersihkan
         *   otomatis. Proses yang berhenti mendadak (galat fatal, dihentikan
         *   paksa) tidak sempat menjalankan pembersihan normal sehingga folder
         *   profil browser menumpuk di folder temp sistem; setiap run menyapu
         *   sisa yang lebih tua dari batas ini, dan perintah
         *   `php bin/crawl.php temp --umur=<jam>` bisa dipakai manual.
         */
        'js_render' => (string) (Env::get('CRAWLER_JS_RENDER', 'auto') ?? 'auto'),
        'browser_bin' => (string) (Env::get('CRAWLER_BROWSER', '') ?? ''),
        'browser_wait_ms' => Env::int('CRAWLER_BROWSER_WAIT', 6000),
        'browser_timeout_ms' => Env::int('CRAWLER_BROWSER_TIMEOUT', 25000),
        'browser_flags' => (string) (Env::get('CRAWLER_BROWSER_FLAGS', '') ?? ''),
        'browser_user_agent' => (string) (Env::get('CRAWLER_BROWSER_UA', '') ?? ''),
        'render_min_text' => Env::int('CRAWLER_RENDER_MIN_TEXT', 2000),
        'render_max_text_ratio' => (float) (Env::get('CRAWLER_RENDER_MAX_TEXT_RATIO', '0.03') ?? '0.03'),
        'render_temp_hours' => max(1, Env::int('CRAWLER_RENDER_TEMP_HOURS', 6)),

        /*
         * JEDA / LANJUTKAN job dari dashboard (lihat src/Support/JobControl.php).
         * UI membuat berkas storage/jobs/<job-id>.pause; proses CLI memeriksa
         * berkas itu di titik aman (antar batch halaman dan antar site) setiap
         * CRAWLER_PAUSE_POLL_MS milidetik, lalu menunggu sampai berkasnya
         * dihapus. Batch yang sedang berjalan diselesaikan lebih dahulu sehingga
         * tidak ada unduhan yang terpotong.
         */
        'pause_poll_ms' => max(200, Env::int('CRAWLER_PAUSE_POLL_MS', 1000)),

        /*
         * Jumlah pengulangan OTOMATIS untuk job yang prosesnya MATI karena galat
         * fatal (galat tak tertangani di tengah run -- mis. TypeError saat
         * membaca bentuk JSON API baru). Job lama tetap berstatus GAGAL dengan
         * lognya, sedangkan job baru dibuat dengan site + opsi yang sama dan
         * ditautkan lewat kolom ulangi_dari/ulangi_ke pada berkas job.
         *
         * 0 = tidak ada pengulangan otomatis; pengulangan manual selalu tersedia
         * lewat tombol "Ulangi job" di dashboard atau
         * `php bin/crawl.php retry --job=<id>`. Nilai per run:
         * --auto-retry=N (dipakai juga oleh job buatan UI).
         */
        'auto_retry' => max(0, Env::int('CRAWLER_AUTO_RETRY', 1)),
    ],

    'chunk' => [
        /*
         * Ukuran chunk bawaan 12000 karakter dengan tumpang tindih 1200 (10%)
         * supaya satu point Qdrant memuat konteks yang banyak (mis. satu bagian
         * dokumen APBD/PDF hasil /parse), sejalan dengan skema dokumen yang
         * sudah ada di collection. Nilai efektif tetap bisa ditimpa per entri
         * config/sites.json lewat "chunk_size"/"chunk_overlap".
         */
        'size' => Env::int('CHUNK_SIZE', 12000),
        'overlap' => Env::int('CHUNK_OVERLAP', 1200),
        'min_length' => Env::int('CHUNK_MIN_LENGTH', 50),

        /*
         * Pembersihan markdown hasil /parse sebelum dipecah menjadi chunk.
         *
         * Layanan /parse mengembalikan markdown apa adanya termasuk menu
         * navigasi, ornament/ikon kosong, dan footer yang sama di setiap
         * halaman. Bila ikut dikirim ke /embed maka (1) vektor "isi halaman"
         * menjadi mirip vektor "daftar menu" sehingga pencarian kurang tepat,
         * dan (2) jumlah chunk/point Qdrant membengkak tanpa nilai tambah.
         *
         * clean_markdown=true membuang bagian tersebut secara deterministik
         * (lihat src/Pipeline/MarkdownCleaner.php). Bisa ditimpa per entri
         * config/sites.json lewat "clean_markdown": false.
         *
         * max_link_ratio adalah jaring pengaman kedua di tahap chunk: chunk
         * yang porsi markup tautannya melebihi rasio ini (mis. 0.6 = 60%
         * karakter adalah tautan, ciri menu/daftar isi) tidak dikirim ke
         * /embed. 1.0 = tanpa batas.
         *
         * drop_duplicate_content adalah jaring pengaman ketiga: pada situs
         * yang isi halamannya baru dibuat di browser (mis. Next.js App Router)
         * banyak URL menghasilkan markdown yang sama karena hanya
         * menu/tagline/footer yang terbaca. Markdown yang identik ATAU hampir
         * sama (mirip >= duplicate_similarity) pada site yang sama hanya
         * dikirim sekali ke /embed; sisanya dicatat pada log (CONTENT_DUPLIKAT).
         * duplicate_similarity 1.0 = hanya isi yang identik yang dibuang.
         */
        'clean_markdown' => Env::bool('CHUNK_CLEAN_MARKDOWN', true),
        'max_link_ratio' => (float) (Env::get('CHUNK_MAX_LINK_RATIO', '0.6') ?? '0.6'),
        'drop_duplicate_content' => Env::bool('CHUNK_DROP_DUPLICATE_CONTENT', true),
        'duplicate_similarity' => (float) (Env::get('CHUNK_DUPLICATE_SIMILARITY', '0.85') ?? '0.85'),
    ],

    'embed' => [
        'batch_size' => Env::int('EMBED_BATCH_SIZE', 8),

        /*
         * Batas TOTAL karakter untuk satu permintaan /embed. Setelah ukuran
         * chunk dinaikkan (bawaan 12000 karakter), satu batch berisi
         * site->embed_batch_size chunk bisa memuat puluhan ribu karakter;
         * service embedding lokal memprosesnya berurutan sehingga permintaan
         * sebesar itu mudah melewati INGEST_EMBED_TIMEOUT (bawaan 300 detik) dan
         * seluruh chunk dokumen gagal di-embed. Chunk tetap dikelompokkan per
         * embed_batch_size, tetapi batch dipotong lebih awal bila total
         * karakternya menembus batas ini (satu chunk selalu muat di satu
         * permintaan). 0 = tanpa batas.
         */
        'max_chars_per_batch' => max(0, Env::int('EMBED_MAX_CHARS_PER_BATCH', 40000)),

        // BGE-M3 menghasilkan 1024 dimensi. Dimensi ini divalidasi sebelum
        // vektor ditulis, sama seperti EMBEDDING_DIMENSIONS di crawl-web.
        'expected_dimension' => Env::int('EMBED_DIMENSION', 1024),
        'store_vectors' => Env::bool('EMBED_STORE_VECTORS', true),
    ],

    /*
     * Tujuan Qdrant untuk tahap unggah vektor (`php bin/crawl.php qdrant`).
     * Berkas vektor tetap ditulis ke storage/vectors lebih dulu; unggahan
     * dilakukan manual/terpisah supaya bisa diperiksa dulu.
     */
    'qdrant' => [
        'base_url' => Env::get('QDRANT_URL', 'http://localhost:6333'),
        'collection' => Env::get('QDRANT_COLLECTION', 'documents'),
        'distance' => Env::get('QDRANT_DISTANCE', 'Cosine'),
        'api_key' => (string) Env::get('QDRANT_API_KEY', ''),
        'batch_size' => Env::int('QDRANT_BATCH_SIZE', 64),

        /*
         * QDRANT_TIMEOUT menerima DETIK (nilai < 1000, mis. 120) atau
         * MILIDETIK (nilai >= 1000, mis. 10000 = 10 detik) supaya .env gaya
         * Node/JS bisa dipakai apa adanya. QdrantClient/curl memakai detik.
         */
        'timeout' => QdrantClient::timeoutSeconds(Env::get('QDRANT_TIMEOUT', '120')),

        'retries' => Env::int('QDRANT_RETRIES', 3),
        'retry_delay_ms' => Env::int('QDRANT_RETRY_DELAY_MS', 1000),

        /*
         * Dimensi vektor collection (BGE-M3 = 1024). Dipakai untuk MEMBUAT
         * collection dan untuk memvalidasi berkas vektor sebelum dikirim,
         * sehingga koleksi tidak pernah dibuat dengan dimensi yang salah.
         * 0 = ikut dimensi berkas vektor (perilaku lama).
         */
        'vector_size' => max(0, Env::int('QDRANT_VECTOR_SIZE', Env::int('EMBED_DIMENSION', 1024))),

        /*
         * Siklus hidup collection:
         *   QDRANT_RECREATE=false -> pastikan collection ada: dibuat bila belum
         *                            ada, dibiarkan (tidak dihapus) bila sudah ada.
         *   QDRANT_RECREATE=true  -> hapus lalu buat ulang.
         * auto_create=false (bawaan lama): collection harus sudah ada kecuali
         * --create dipakai; namun menulis QDRANT_RECREATE pada .env sudah
         * dianggap sebagai pernyataan eksplisit untuk mengurus collection.
         */
        'recreate' => Env::bool('QDRANT_RECREATE', false),
        'auto_create' => Env::bool('QDRANT_AUTO_CREATE', false) || Env::has('QDRANT_RECREATE'),

        /*
         * Push otomatis: setiap site yang selesai tahap /embed (berkas
         * storage/vectors/<site>/<run-id>.jsonl tertutup) LANGSUNG diunggah ke
         * Qdrant memakai pengaturan di atas, jadi tidak perlu menjalankan
         * `php bin/crawl.php qdrant` secara manual.
         *
         * Kegagalan unggah TIDAK menggagalkan crawl: markdown, .jsonl, dan
         * .qdrant.json tetap tersimpan, kegagalan dicatat pada log txt
         * (QDRANT_PUSH) + ringkasan run, dan bisa diulang dengan:
         *   php bin/crawl.php qdrant --file=storage/vectors/<site>/<run>.jsonl
         *
         * QDRANT_PUSH_AFTER_CRAWL=false -> matikan (unggah manual via CLI).
         * Opsi CLI satu run: --push-qdrant / --no-push-qdrant.
         */
        'push_after_crawl' => Env::bool('QDRANT_PUSH_AFTER_CRAWL', true),

        /*
         * Ekspor JSON: setiap run yang menghasilkan vektor juga menulis SATU
         * berkas <run-id>.qdrant.json berisi {"points":[...]} -- persis badan
         * permintaan PUT /collections/<collection>/points, jadi bisa dikirim
         * apa adanya (Invoke-RestMethod ... -InFile ...).
         *
         * export_max_points mencegah satu berkas JSON raksasa: bila jumlah
         * point melebihi batas, ekspor dilewati (tercatat QDRANT_EXPORT
         * DILEWATI) dan run itu diunggah bertahap lewat CLI/--out NDJSON.
         * 0 = tanpa batas.
         */
        'export_json' => Env::bool('QDRANT_EXPORT_JSON', true),
        'export_max_points' => Env::int('QDRANT_EXPORT_MAX_POINTS', 10000),
    ],

    /*
     * Parser cadangan lokal: HTML -> Markdown murni PHP.
     * Dipakai HANYA bila /parse menolak input web (mis. EMBED_WEB_PAGE=false
     * atau Docling tidak terpasang di service). Semua kejadian fallback tetap
     * dicatat pada log txt agar tidak ada kejutan.
     */
    'parse_fallback' => [
        'local_markdown' => Env::bool('PARSE_FALLBACK_LOCAL', true),
        // try_service=false melewati /parse sepenuhnya (langsung parser lokal).
        // Berguna bila worker-ingest memang berjalan dengan EMBED_WEB_PAGE=false
        // sehingga tiap halaman tidak perlu satu permintaan /parse yang gagal.
        'try_service' => Env::bool('PARSE_TRY_SERVICE', true),
    ],

    'logging' => [
        'enabled' => Env::bool('LOG_ENABLED', true),
        'daily_master' => Env::bool('LOG_DAILY_MASTER', true),
        'per_run' => Env::bool('LOG_PER_RUN', true),
        'per_site' => Env::bool('LOG_PER_SITE', true),
        'console' => Env::bool('LOG_CONSOLE', true),
    ],

    'paths' => [
        'logs' => $base . '/logs',
        'runs' => $base . '/logs/runs',
        'sites' => $base . '/logs/sites',
        'storage' => $base . '/storage',
        'html' => $base . '/storage/html',
        // Berkas dokumen mentah (pdf/docx/xlsx/...) hasil unduhan URL dokumen.
        'documents' => $base . '/storage/documents',
        'markdown' => $base . '/storage/markdown',
        'vectors' => $base . '/storage/vectors',
        'jobs' => $base . '/storage/jobs',
        'sites_config' => $base . '/config/sites.json',
    ],

    'ui' => [
        'enabled' => Env::bool('UI_ENABLED', true),
        'allow_localhost_only' => Env::bool('UI_LOCALHOST_ONLY', true),
    ],
];
