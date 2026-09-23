<?php

declare(strict_types=1);

namespace App\Http;

use RuntimeException;

/**
 * HTTP client tipis di atas ekstensi curl.
 *
 * Dipakai untuk:
 *  - mengunduh halaman/robots.txt dari web target (GET)
 *  - memanggil service worker-ingest: /health (GET), /parse (multipart),
 *    /embed (JSON)
 */
final class HttpClient
{
    /** Kode error curl yang menandakan verifikasi sertifikat TLS gagal. */
    private const TLS_ERROR_CODES = [35, 51, 58, 60, 66, 77, 83];

    /**
     * Kode error curl yang menandakan TAHAP KONEKSI gagal (server tidak pernah
     * menjawab): 7 = CURLE_COULDNT_CONNECT, 28 = CURLE_OPERATION_TIMEDOUT.
     * Kode 28 dipakai bersama pemeriksaan pesan "Connection timed out ..." pada
     * isConnectFailure() supaya habisnya batas waktu SATU PERMINTAAN
     * (CURLOPT_TIMEOUT) tidak salah dianggap kegagalan koneksi.
     */
    private const CONNECT_ERROR_CODES = [7, 28];

    /** Batas jumlah alamat IP yang dicoba pada satu unduhan (lihat retryByAddress()). */
    private const MAX_ADDRESS_ATTEMPTS = 4;

    /**
     * Alamat IP yang sudah terbukti menjawab, per host.
     *
     * Banyak situs .go.id mengumumkan beberapa alamat A dan sebagian di antaranya
     * membuang paket (firewall) sehingga curl menunggu sampai timeout koneksi
     * habis walaupun alamat lain sehat. Alamat yang berhasil dicatat di sini dan
     * DIPIN pada permintaan berikutnya (CURLOPT_RESOLVE, Host/SNI tetap nama
     * host sehingga verifikasi TLS tidak berubah).
     *
     * @var array<string, string>
     */
    private array $hostAddress = [];

    /**
     * Alamat IP yang sudah gagal dihubungi pada proses ini, per host.
     *
     * @var array<string, array<string, true>>
     */
    private array $deadAddresses = [];

    /**
     * Hasil resolusi alamat per host (cache supaya DNS tidak ditanyakan ulang).
     *
     * @var array<string, list<string>>
     */
    private array $resolvedAddresses = [];

    public function __construct(
        private string $userAgent,
        private int $connectTimeout = 10,
        private int $maxRedirects = 5,
        private int $maxBytes = 5_242_880,
        private bool $verifyTls = true,
        private bool $insecureRetry = false,
        private string $caBundle = '',
        private bool $addressRetry = true,
    ) {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('Ekstensi PHP curl wajib aktif.');
        }
    }

    /**
     * Apakah verifikasi sertifikat TLS aktif secara default.
     */
    public function verifyTls(): bool
    {
        return $this->verifyTls;
    }

    /**
     * Apakah permintaan diulang tanpa verifikasi saat rantai sertifikat server
     * tidak lengkap.
     */
    public function insecureRetry(): bool
    {
        return $this->insecureRetry;
    }

    /** Batas waktu tahap koneksi bawaan (detik). */
    public function connectTimeout(): int
    {
        return $this->connectTimeout;
    }

    /**
     * Apakah permintaan diulang ke alamat IP lain (hasil resolusi host yang
     * sama) saat tahap koneksi gagal.
     */
    public function addressRetry(): bool
    {
        return $this->addressRetry;
    }

    public function withUserAgent(string $userAgent): self
    {
        $clone = clone $this;
        $clone->userAgent = $userAgent;

        return $clone;
    }

    /**
     * @param array<string, mixed> $options
     */
    public function get(string $url, array $options = []): HttpResponse
    {
        return $this->send('GET', $url, $options);
    }

    /**
     * HEAD: ambil header respons tanpa badan (dipakai untuk MEMERIKSA keberadaan
     * berkas dokumen sebelum dimasukkan ke antrean crawl -- lihat
     * App\Crawl\DocumentHarvest).
     *
     * @param array<string, mixed> $options
     */
    public function head(string $url, array $options = []): HttpResponse
    {
        return $this->send('HEAD', $url, $options);
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $options
     */
    public function postJson(string $url, array $payload, array $options = []): HttpResponse
    {
        return $this->jsonRequest('POST', $url, $payload, $options);
    }

    /**
     * Kirim JSON dengan method PUT (dipakai tahap Qdrant: buat collection dan
     * unggah point ke /collections/{nama}/points).
     *
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $options
     */
    public function putJson(string $url, array $payload, array $options = []): HttpResponse
    {
        return $this->jsonRequest('PUT', $url, $payload, $options);
    }

    /**
     * Kirim JSON dengan method DELETE (badan boleh kosong, mis. hapus
     * collection Qdrant).
     *
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $options
     */
    public function deleteJson(string $url, array $payload = [], array $options = []): HttpResponse
    {
        return $this->jsonRequest('DELETE', $url, $payload, $options);
    }

    /**
     * Permintaan JSON generik: body diisi hanya bila ada isinya (atau method
     * POST, supaya perilaku lama `postJson` tidak berubah).
     *
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $options
     */
    private function jsonRequest(string $method, string $url, array $payload, array $options): HttpResponse
    {
        if ($payload !== [] || $method === 'POST') {
            $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            $options['body'] = $json === false ? '{}' : $json;
        }

        $options['headers'] = array_merge(
            ['Content-Type' => 'application/json', 'Accept' => 'application/json'],
            $options['headers'] ?? []
        );

        return $this->send($method, $url, $options);
    }

    /**
     * Upload multipart/form-data.
     *
     * Nilai array boleh berupa string (field form biasa) atau CURLFile (file).
     * Nama file upload dipertahankan (mis. .html) karena /parse mendeteksi
     * jenis input dari ekstensi file.
     *
     * @param array<string, string|\CURLFile> $parts
     * @param array<string, mixed>            $options
     */
    public function postMultipart(string $url, array $parts, array $options = []): HttpResponse
    {
        $options['multipart'] = $parts;
        $options['headers'] = array_merge(
            ['Accept' => 'application/json'],
            $options['headers'] ?? []
        );

        return $this->send('POST', $url, $options);
    }

    /**
     * Kirim permintaan. Bila verifikasi TLS gagal karena server mengirim rantai
     * sertifikat tidak lengkap (curl error 60) dan 'insecure_retry' aktif,
     * permintaan diulang TANPA verifikasi; hasilnya ditandai
     * HttpResponse::$insecureTls supaya tercatat di log.
     *
     * Bila kegagalannya pada TAHAP KONEKSI (alamat host tidak menjawab) dan
     * 'address_retry' aktif, permintaan diulang dengan MEMIN alamat IP lain
     * hasil resolusi host yang sama; hasilnya ditandai
     * HttpResponse::$addressRetry (lihat retryByAddress()).
     *
     * @param array<string, mixed> $options
     */
    private function send(string $method, string $url, array $options): HttpResponse
    {
        $verifyTls = (bool) ($options['verify_tls'] ?? $this->verifyTls);
        $caBundle = (string) ($options['ca_bundle'] ?? $this->caBundle);

        $errno = 0;
        $response = $this->perform($method, $url, $options, $verifyTls, $caBundle, $errno);

        if (!$response->ok() && $verifyTls && $this->insecureRetry && $this->isTlsFailure($errno, $response)) {
            $retryErrno = 0;
            $retry = $this->perform($method, $url, $options, false, '', $retryErrno);

            if ($retryErrno === 0 && $retry->status > 0) {
                $response = $retry;
                $errno = $retryErrno;
            }
        }

        return $this->retryWithOtherAddress($method, $url, $options, $verifyTls, $caBundle, $errno, $response);
    }

    /**
     * Siapkan handle curl untuk satu permintaan (belum dieksekusi).
     *
     * Dipisah dari perform() supaya handle bisa dieksekusi dalam batch oleh
     * curl_multi (lihat getMany()) tanpa mengubah perilaku satu per satu.
     *
     * @param array<string, mixed> $options
     *
     * @return array{handle: \CurlHandle|null, url: string, verify_tls: bool, state: array<string, mixed>}
     */
    private function prepare(string $method, string $url, array $options, bool $verifyTls, string $caBundle): array
    {
        $headers = $options['headers'] ?? [];
        $timeout = (int) ($options['timeout'] ?? 30);
        $connectTimeout = (int) ($options['connect_timeout'] ?? $this->connectTimeout);
        $follow = (bool) ($options['follow'] ?? true);
        $maxRedirects = (int) ($options['max_redirects'] ?? $this->maxRedirects);
        $maxBytes = (int) ($options['max_bytes'] ?? $this->maxBytes);
        $sink = isset($options['sink']) ? (string) $options['sink'] : null;

        [$host, $port] = self::hostPort($url);
        $resolve = is_array($options['resolve'] ?? null) ? array_values($options['resolve']) : [];

        // Host ini sudah pernah berhasil diunduh lewat alamat IP tertentu (lihat
        // retryByAddress()): pin alamat itu supaya unduhan berikutnya tidak
        // menunggu alamat yang tidak menjawab. Host/SNI tetap nama host, jadi
        // verifikasi TLS tidak berubah.
        if ($resolve === [] && $host !== '' && isset($this->hostAddress[$host])) {
            $resolve = [self::resolveEntry($host, $port, $this->hostAddress[$host])];
        }

        // State ditulis oleh closure header/write. PENTING: state di-capture
        // BY REFERENCE dan dikembalikan sebagai elemen referensi (&$state),
        // karena array literal biasa akan MENYALIN nilai sehingga hasil tulis
        // closure tidak terlihat oleh finish() (respons jadi kosong).
        $state = [
            'headers' => [],
            'buffer' => '',
            'exceeded' => false,
            'max_bytes' => $maxBytes,
            'sink' => $sink,
            'sink_handle' => null,
            'fatal' => null,
            'address_retry' => (bool) ($options['address_retry'] ?? false),
        ];

        $ch = curl_init();
        if ($ch === false) {
            $state['fatal'] = 'gagal membuat handle curl';

            return ['handle' => null, 'url' => $url, 'verify_tls' => $verifyTls, 'state' => &$state];
        }

        $headerLines = ['User-Agent: ' . $this->userAgent];
        foreach ($headers as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }

        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_FOLLOWLOCATION => $follow,
            CURLOPT_MAXREDIRS => $maxRedirects,
            CURLOPT_CONNECTTIMEOUT => $connectTimeout,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_ENCODING => '',
            CURLOPT_SSL_VERIFYPEER => $verifyTls,
            CURLOPT_SSL_VERIFYHOST => $verifyTls ? 2 : 0,
            CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$state): int {
                $trimmed = trim($line);

                if ($trimmed === '') {
                    return strlen($line);
                }

                if (!str_contains($trimmed, ':')) {
                    // Baris status (mis. "HTTP/1.1 200 OK") menandai awal
                    // respons baru saat mengikuti redirect.
                    $state['headers'] = [];

                    return strlen($line);
                }

                [$name, $value] = explode(':', $trimmed, 2);
                $state['headers'][strtolower(trim($name))] = trim($value);

                return strlen($line);
            },
        ]);

        if ($caBundle !== '' && is_file($caBundle)) {
            curl_setopt($ch, CURLOPT_CAINFO, $caBundle);
        }

        if ($resolve !== []) {
            curl_setopt($ch, CURLOPT_RESOLVE, $resolve);
        }

        if (isset($options['multipart'])) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $options['multipart']);
        } elseif (isset($options['body'])) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $options['body']);
        }

        if ($sink !== null) {
            ensure_dir(dirname($sink));
            $state['sink_handle'] = @fopen($sink, 'wb');

            if ($state['sink_handle'] === false) {
                curl_close($ch);
                $state['fatal'] = 'gagal menulis file: ' . $sink;

                return ['handle' => null, 'url' => $url, 'verify_tls' => $verifyTls, 'state' => &$state];
            }
        }

        curl_setopt(
            $ch,
            CURLOPT_WRITEFUNCTION,
            static function ($handle, string $chunk) use (&$state): int {
                $maxBytes = (int) $state['max_bytes'];
                $sinkHandle = $state['sink_handle'];

                if (is_resource($sinkHandle)) {
                    if (fwrite($sinkHandle, $chunk) === false) {
                        return 0;
                    }

                    $size = ftell($sinkHandle);
                    if ($maxBytes > 0 && $size !== false && $size > $maxBytes) {
                        $state['exceeded'] = true;

                        return 0;
                    }

                    return strlen($chunk);
                }

                $state['buffer'] .= $chunk;
                if ($maxBytes > 0 && strlen($state['buffer']) > $maxBytes) {
                    $state['exceeded'] = true;

                    return 0;
                }

                return strlen($chunk);
            }
        );

        return ['handle' => $ch, 'url' => $url, 'verify_tls' => $verifyTls, 'state' => &$state];
    }

    /**
     * Satu percobaan curl. $errno diisi kode error curl (0 bila sukses).
     *
     * @param array<string, mixed> $options
     */
    private function perform(
        string $method,
        string $url,
        array $options,
        bool $verifyTls,
        string $caBundle,
        int &$errno,
    ): HttpResponse {
        $job = $this->prepare($method, $url, $options, $verifyTls, $caBundle);

        if ($job['handle'] !== null) {
            curl_exec($job['handle']);
        }

        [$response, $errno] = $this->finish($job);

        return $response;
    }

    /**
     * Tutup handle + file sink lalu susun HttpResponse dari state prepare().
     *
     * @param array{handle: \CurlHandle|null, url: string, verify_tls: bool, state: array<string, mixed>} $job
     *
     * @return array{0: HttpResponse, 1: int} respons + kode error curl (0 = sukses)
     */
    private function finish(array $job): array
    {
        $ch = $job['handle'];
        $url = (string) $job['url'];
        // Elemen 'state' adalah referensi ke state di prepare(): ambil dengan
        // referensi supaya hasil tulis closure (header/body) terlihat di sini.
        $state = &$job['state'];
        $verifyTls = (bool) $job['verify_tls'];
        $maxBytes = (int) $state['max_bytes'];

        if ($ch === null) {
            $this->closeSink($state);

            $message = (string) ($state['fatal'] ?? '');
            if ($message === '') {
                $message = 'gagal membuat handle curl';
            }

            return [new HttpResponse($url, 0, [], '', $url, 0.0, $message, 0, null), 0];
        }

        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $effectiveUrl = (string) (curl_getinfo($ch, CURLINFO_EFFECTIVE_URL) ?: $url);
        $durationMs = ((float) curl_getinfo($ch, CURLINFO_TOTAL_TIME)) * 1000;
        $bytes = (int) curl_getinfo($ch, CURLINFO_SIZE_DOWNLOAD);
        $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $remoteIp = (string) (curl_getinfo($ch, CURLINFO_PRIMARY_IP) ?: '');
        $curlError = curl_error($ch);
        $curlErrno = curl_errno($ch);
        curl_close($ch);

        $this->closeSink($state);

        $error = $curlError !== '' ? $curlError : null;

        if ((bool) $state['exceeded']) {
            $error = 'ukuran respons melebihi batas ' . $maxBytes . ' byte';
            if ($state['sink'] !== null) {
                @unlink((string) $state['sink']);
                $bytes = 0;
            }
        } elseif ($curlErrno !== 0 && $error === null) {
            $error = 'curl error ' . $curlErrno;
        }

        $responseHeaders = is_array($state['headers']) ? $state['headers'] : [];
        $buffer = (string) $state['buffer'];

        $response = new HttpResponse(
            url: $url,
            status: $status,
            headers: $responseHeaders,
            body: $buffer,
            effectiveUrl: $effectiveUrl,
            durationMs: $durationMs,
            error: $error,
            bytes: $bytes > 0 ? $bytes : strlen($buffer),
            contentType: is_string($contentType) ? $contentType : null,
            insecureTls: !$verifyTls,
            remoteIp: $remoteIp,
            addressRetry: (bool) ($state['address_retry'] ?? false),
        );

        return [$response, $curlErrno];
    }

    /**
     * Tutup file sink (bila ada) supaya isi HTML ter-flush ke disk.
     *
     * @param array<string, mixed> $state
     */
    private function closeSink(array &$state): void
    {
        $sinkHandle = $state['sink_handle'] ?? null;

        if (is_resource($sinkHandle)) {
            fclose($sinkHandle);
        }
    }

    /**
     * GET beberapa URL sekaligus memakai curl_multi.
     *
     * Dipakai crawler untuk mengunduh satu batch halaman (CRAWL_CONCURRENCY).
     * Hasil dikembalikan berurutan seperti daftar permintaan sehingga pemanggil
     * cukup memasangkannya dengan URL asal.
     *
     * Bila verifikasi TLS gagal karena server mengirim rantai sertifikat tidak
     * lengkap dan 'insecure_retry' aktif, permintaan itu diulang satu per satu
     * TANPA verifikasi -- sama seperti send() -- supaya perilaku tahap
     * TLS_INSECURE tidak berubah.
     *
     * @param list<array{url: string, options?: array<string, mixed>}> $requests
     *
     * @return list<HttpResponse> respons berurutan sesuai $requests
     */
    public function getMany(array $requests, int $concurrency = 5): array
    {
        $concurrency = max(1, $concurrency);
        $requests = array_values($requests);
        $total = count($requests);

        /** @var array<int, HttpResponse|null> $results */
        $results = array_fill(0, $total, null);
        /** @var array<int, int> $errnos */
        $errnos = array_fill(0, $total, 0);

        $multi = curl_multi_init();
        $queue = $requests;

        /** @var array<int, array{index: int, job: array<string, mixed>}> $pending */
        $pending = [];
        $next = 0;

        while ($queue !== [] || $pending !== []) {
            // Isi jendela konkurensi dengan handle baru.
            while ($queue !== [] && count($pending) < $concurrency) {
                $request = array_shift($queue);
                $url = (string) ($request['url'] ?? '');
                $options = is_array($request['options'] ?? null) ? $request['options'] : [];
                $verifyTls = (bool) ($options['verify_tls'] ?? $this->verifyTls);
                $caBundle = (string) ($options['ca_bundle'] ?? $this->caBundle);

                $job = $this->prepare('GET', $url, $options, $verifyTls, $caBundle);

                if ($job['handle'] === null) {
                    // Gagal menyiapkan handle (mis. file sink tidak bisa ditulis).
                    [$response, $errno] = $this->finish($job);
                    $results[$next] = $response;
                    $errnos[$next] = $errno;
                    $next++;

                    continue;
                }

                $pending[spl_object_id($job['handle'])] = ['index' => $next, 'job' => $job];
                curl_multi_add_handle($multi, $job['handle']);
                $next++;
            }

            if ($pending === []) {
                continue;
            }

            do {
                $status = curl_multi_exec($multi, $running);
            } while ($status === CURLM_CALL_MULTI_PERFORM);

            if ($running > 0) {
                // Blokir sebentar supaya tidak sibuk menunggu (busy loop).
                if (curl_multi_select($multi, 1.0) === -1) {
                    usleep(20_000);
                }
            }

            while (($info = curl_multi_info_read($multi)) !== false) {
                $handle = $info['handle'];
                $handleId = spl_object_id($handle);
                $entry = $pending[$handleId] ?? null;

                curl_multi_remove_handle($multi, $handle);

                if ($entry === null) {
                    continue;
                }

                [$response, $errno] = $this->finish($entry['job']);
                $results[$entry['index']] = $response;
                $errnos[$entry['index']] = $errno;
                unset($pending[$handleId]);
            }
        }

        curl_multi_close($multi);

        // Percobaan ulang SATU PER SATU untuk permintaan yang gagal, memakai
        // urutan yang sama seperti send():
        //   1. alamat IP lain milik host yang sama (host dengan beberapa alamat
        //      A, sebagian tidak menjawab) -- lihat retryByAddress(),
        //   2. TLS tanpa verifikasi (rantai sertifikat tidak lengkap).
        foreach ($results as $position => $response) {
            $request = $requests[$position] ?? [];
            $url = (string) ($request['url'] ?? '');
            $options = is_array($request['options'] ?? null) ? $request['options'] : [];
            $verifyTls = (bool) ($options['verify_tls'] ?? $this->verifyTls);
            $caBundle = (string) ($options['ca_bundle'] ?? $this->caBundle);

            if ($response === null) {
                // Jaring pengaman: handle tidak pernah selesai.
                $results[$position] = $this->get($url, $options);

                continue;
            }

            $errno = (int) $errnos[$position];

            if (!$response->ok()) {
                $retry = $this->retryWithOtherAddress('GET', $url, $options, $verifyTls, $caBundle, $errno, $response);

                if ($retry !== $response) {
                    $response = $retry;
                    $results[$position] = $retry;
                    $errnos[$position] = $errno;
                }
            }

            if ($response->ok() || !$this->insecureRetry || !$this->isTlsFailure($errno, $response)) {
                continue;
            }

            $retryOptions = $options;
            $retryOptions['verify_tls'] = false;
            $retryOptions['ca_bundle'] = '';

            $retryErrno = 0;
            $retry = $this->perform('GET', $url, $retryOptions, false, '', $retryErrno);

            if ($retryErrno === 0 && $retry->status > 0) {
                $results[$position] = $retry;
                $errnos[$position] = $retryErrno;
            }
        }

        $out = [];
        foreach ($results as $response) {
            $out[] = $response instanceof HttpResponse
                ? $response
                : new HttpResponse('', 0, [], '', '', 0.0, 'respons tidak tersedia', 0, null);
        }

        return $out;
    }


    /**
     * Bila permintaan gagal pada TAHAP KONEKSI, coba alamat IP lain milik host
     * yang sama (retryByAddress()). Dipisah dari send() supaya jalur curl_multi
     * (getMany()) memakai logika yang sama persis.
     *
     * Mengembalikan respons yang dipakai: respons hasil percobaan ulang bila ada
     * yang berhasil/berstatus, atau $response apa adanya (objek yang sama, jadi
     * pemanggil bisa membandingkan identitasnya).
     *
     * @param array<string, mixed> $options
     */
    private function retryWithOtherAddress(
        string $method,
        string $url,
        array $options,
        bool $verifyTls,
        string $caBundle,
        int &$errno,
        HttpResponse $response,
    ): HttpResponse {
        if (
            $response->ok()
            || !$this->addressRetry
            || !(bool) ($options['retry_addresses'] ?? true)
            || !$this->isConnectFailure($errno, $response)
        ) {
            return $response;
        }

        [$host] = self::hostPort($url);

        // Permintaan tadi memakai alamat pin dari cache host ini (lihat
        // prepare()). Karena alamat itu sekarang gagal, catatannya sudah tidak
        // berlaku (situs mungkin pindah IP): buang catatan lama lalu ulangi
        // sekali dengan resolusi normal sebelum mencoba alamat lain.
        if ($host !== '' && isset($this->hostAddress[$host]) && !is_array($options['resolve'] ?? null)) {
            unset($this->hostAddress[$host], $this->deadAddresses[$host], $this->resolvedAddresses[$host]);

            $plainErrno = 0;
            $plain = $this->perform($method, $url, $options, $verifyTls, $caBundle, $plainErrno);
            $errno = $plainErrno;

            if ($plain->ok() || $plain->status > 0 || !$this->isConnectFailure($plainErrno, $plain)) {
                return $plain;
            }

            $response = $plain;
        }

        return $this->retryByAddress($method, $url, $options, $verifyTls, $caBundle, $errno) ?? $response;
    }

    /**
     * Ulangi permintaan dengan MEMIN alamat IP lain hasil resolusi host yang sama
     * (CURLOPT_RESOLVE). Host/SNI tetap nama host sehingga verifikasi TLS tidak
     * berubah -- yang berpindah hanya alamat tujuannya.
     *
     * Alamat yang tidak menjawab dicatat pada $deadAddresses (tidak dicoba lagi
     * pada proses ini) dan alamat yang berhasil dicatat pada $hostAddress supaya
     * unduhan berikutnya langsung memakai alamat itu.
     *
     * Mengembalikan respons terbaik, atau null bila tidak ada alamat lain yang
     * bisa dicoba (host hanya punya satu alamat atau semuanya sudah dicoba).
     *
     * @param array<string, mixed> $options
     */
    private function retryByAddress(
        string $method,
        string $url,
        array $options,
        bool $verifyTls,
        string $caBundle,
        int &$errno,
    ): ?HttpResponse {
        [$host, $port] = self::hostPort($url);

        if ($host === '') {
            return null;
        }

        $addresses = $this->resolveAddresses($host);
        if (count($addresses) < 2) {
            return null;
        }

        $connectTimeout = max(1, (int) ($options['connect_timeout'] ?? $this->connectTimeout));
        // Percobaan ke alamat cadangan memakai separuh timeout koneksi: satu
        // alamat yang membuang paket tidak boleh menghabiskan seluruh anggaran
        // waktu unduhan sebelum alamat berikutnya sempat dicoba.
        $attemptTimeout = max(2, intdiv(max(3, $connectTimeout), 2));

        $best = null;
        $attempts = 0;

        foreach ($addresses as $ip) {
            if (isset($this->deadAddresses[$host][$ip])) {
                continue; // sudah terbukti tidak menjawab pada proses ini
            }

            $attempts++;
            if ($attempts > self::MAX_ADDRESS_ATTEMPTS) {
                break;
            }

            $attemptOptions = $options;
            $attemptOptions['resolve'] = [self::resolveEntry($host, $port, $ip)];
            $attemptOptions['connect_timeout'] = $attemptTimeout;
            $attemptOptions['address_retry'] = true;

            $attemptErrno = 0;
            $attempt = $this->perform($method, $url, $attemptOptions, $verifyTls, $caBundle, $attemptErrno);
            $best = $attempt;
            $errno = $attemptErrno;

            if ($attempt->ok() || $attempt->status > 0) {
                $this->hostAddress[$host] = $ip;

                return $attempt;
            }

            if (!$this->isConnectFailure($attemptErrno, $attempt)) {
                // Gagal karena sebab lain (TLS, file tujuan, ...): berganti
                // alamat tidak akan menolong.
                return $attempt;
            }

            $this->deadAddresses[$host][$ip] = true;
        }

        return $best;
    }

    /**
     * Apakah kegagalan ini terjadi pada TAHAP KONEKSI, yaitu server tidak pernah
     * menjawab sehingga alamat lain patut dicoba?
     *
     * Batas waktu satu permintaan (CURLOPT_TIMEOUT) juga memakai kode error 28,
     * jadi kode itu hanya dianggap kegagalan koneksi bila pesannya memang dari
     * tahap koneksi ("Connection timed out after ...", bukan "Operation timed
     * out ... with N bytes received").
     */
    private function isConnectFailure(int $errno, HttpResponse $response): bool
    {
        if ($response->status > 0 || $response->bytes > 0) {
            return false; // server sudah menjawab
        }

        if (!in_array($errno, self::CONNECT_ERROR_CODES, true)) {
            return false;
        }

        if ($errno !== 28) {
            return true;
        }

        return str_contains(strtolower((string) $response->error), 'connect');
    }

    /**
     * Alamat IPv4 hasil resolusi satu host (cache per proses).
     *
     * @return list<string>
     */
    private function resolveAddresses(string $host): array
    {
        if (array_key_exists($host, $this->resolvedAddresses)) {
            return $this->resolvedAddresses[$host];
        }

        $addresses = [];

        foreach ((array) @gethostbynamel($host) as $ip) {
            $ip = trim((string) $ip);

            if ($ip === '' || filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
                continue;
            }

            if (!in_array($ip, $addresses, true)) {
                $addresses[] = $ip;
            }
        }

        return $this->resolvedAddresses[$host] = $addresses;
    }

    /**
     * Baris entri CURLOPT_RESOLVE: "HOST:PORT:ALAMAT".
     */
    private static function resolveEntry(string $host, int $port, string $ip): string
    {
        return $host . ':' . $port . ':' . $ip;
    }

    /**
     * Host + port dari URL (port dari URL, atau 80/443 menurut skema). Host
     * kosong berarti skema bukan http/https sehingga peminan alamat tidak
     * berlaku.
     *
     * @return array{0: string, 1: int}
     */
    private static function hostPort(string $url): array
    {
        $scheme = strtolower((string) (parse_url($url, PHP_URL_SCHEME) ?? ''));

        if ($scheme !== 'http' && $scheme !== 'https') {
            return ['', 0];
        }

        $host = (string) (parse_url($url, PHP_URL_HOST) ?? '');
        if ($host === '') {
            return ['', 0];
        }

        $port = (int) (parse_url($url, PHP_URL_PORT) ?? 0);
        if ($port <= 0) {
            $port = $scheme === 'http' ? 80 : 443;
        }

        return [$host, $port];
    }

    /**
     * Deteksi kegagalan verifikasi sertifikat TLS (bukan sekadar jaringan mati).
     */
    private function isTlsFailure(int $errno, HttpResponse $response): bool
    {
        if (in_array($errno, self::TLS_ERROR_CODES, true)) {
            return true;
        }

        $error = strtolower((string) $response->error);

        return $error !== '' && str_contains($error, 'ssl certificate problem');
    }
}
