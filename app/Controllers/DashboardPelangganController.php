<?php

require_once __DIR__ . '/DBSilController.php';
require_once __DIR__ . '/DBSertiController.php';
require_once __DIR__ . '/DBKalibrasiController.php';

class DashboardPelangganController
{
    private $db;
    private $tracking;
    private $notifikasiService;
    private $dbSil;
    private $silService;
    private $dbSerti;
    private $sertiService;
    private $dbKalib;
    private $kalibService;

    public function __construct($db, $notifikasiService = null, $dbSil = null, $dbSerti = null, $dbKalib = null)
    {
        $this->db = $db;
        $this->tracking = new TrackingService($db);
        $this->notifikasiService = $notifikasiService;
        $this->dbSil = $dbSil;
        $this->silService = $dbSil ? new DBSilController($dbSil) : null;
        $this->dbSerti = $dbSerti;
        $this->sertiService = $dbSerti ? new DBSertiController($dbSerti) : null;
        $this->dbKalib = $dbKalib;
        $this->kalibService = $dbKalib ? new DBKalibrasiController($dbKalib) : null;
    }

    private function validateCsrf($f3)
    {
        $token = $f3->get('POST.csrf_token');
        $sessionToken = $f3->get('SESSION.csrf_token');
        if (empty($token) || empty($sessionToken) || $token !== $sessionToken) {
            return false;
        }
        return true;
    }

    /**
     * Resolve order_id dari request secara aman.
     *
     * Prioritas:
     *  1. GET.t  → token HMAC (dari URL browser yang sudah di-obfuscate)
     *  2. GET.order_id → langsung dari query string (hanya diizinkan dari
     *                    internal AJAX fetch, bukan dari URL browser langsung)
     *
     * Validasi kepemilikan: pastikan order benar-benar milik user yang login
     * sebelum mengembalikan id.
     *
     * @param  object $f3
     * @return string|null  order_id asli, atau null jika tidak valid / tidak ditemukan.
     */
    private function resolveOrderId($f3): ?string
    {
        // ── 1. Coba resolve dari token HMAC (URL publik) ───────────────────
        // Jika ada ?t=... di URL, ini berasal dari URL browser yang sudah di-obfuscate.
        $token = trim($f3->get('GET.t') ?? '');
        if (!empty($token) && function_exists('order_verify_token')) {
            $data = order_verify_token($token);
            if ($data && !empty($data['id'])) {
                return (string) $data['id'];
            }
            // Token ada tapi tidak valid/expired → tolak, jangan fallback
            return null;
        }

        // ── 2. Fallback ke GET.order_id (internal AJAX dari dashboard.js) ──
        // Path ini digunakan saat JS memanggil /dashboard/section/... secara internal.
        // Keamanan utama: user harus sudah login (session aktif).
        $rawId = trim($f3->get('GET.order_id') ?? '');
        error_log("resolveOrderId: rawId = " . $rawId);
        if (empty($rawId)) return null;

        // Pastikan user sedang login — tanpa ini langsung tolak
        $userId = $f3->get('SESSION.user_id');
        if (!$userId) return null;

        // Soft ownership check:
        // Hanya blokir jika order ini TERBUKTI milik customer lain.
        // Jika id_customer = 0, kolom tidak ada, atau query error → izinkan (fallback aman).
        try {
            $userRow    = $this->db->exec('SELECT id_customer FROM users WHERE id = ? LIMIT 1', [$userId]);
            $idCustomer = (int) ($userRow[0]['id_customer'] ?? 0);

            if ($idCustomer > 0 && $this->dbSil) {
                // Cek apakah order ini milik id_customer yang berbeda dari user saat ini
                $foreignCheck = $this->dbSil->exec(
                    'SELECT id_customer FROM tb_arsip_detail WHERE id_arsip = ? LIMIT 1',
                    [$rawId]
                );
                if (!empty($foreignCheck)) {
                    $ownerCustomer = (int) ($foreignCheck[0]['id_customer'] ?? 0);
                    // Blokir HANYA jika terbukti milik customer lain (bukan 0 dan bukan milik user ini)
                    if ($ownerCustomer > 0 && $ownerCustomer !== $idCustomer) {
                        error_log("resolveOrderId: BLOCKED because ownerCustomer ($ownerCustomer) != idCustomer ($idCustomer)");
                        return null;
                    }
                }
            }
        } catch (\Exception $e) {
            // Query gagal (kolom tidak ada, koneksi SIL error) → tetap izinkan
            error_log("resolveOrderId: DB error: " . $e->getMessage());
        }

        error_log("resolveOrderId: Returning valid rawId = " . $rawId);
        return $rawId;
    }

    /**
     * Tentukan jenis layanan berdasarkan section dan sample_type parameter.
     * Digunakan sebagai kunci pengecekan kuesioner per layanan.
     *
     * @param  object $f3
     * @param  string $section       Nama section yang sedang dimuat
     * @param  string $sampleTypeParam Parameter sample_type dari GET
     * @return string Jenis layanan: 'Pengujian', 'Kalibrasi', 'Sertifikasi', atau 'Pengujian Pengecualian'
     */
    private function resolveJenisLayananFromSection($f3, string $section, string $sampleTypeParam): string
    {
        // Section khusus sertifikasi
        if ($section === 'detailorderserti') return 'Sertifikasi';

        // Section khusus kalibrasi
        if ($section === 'detailorder-kalibrasi') return 'Kalibrasi';

        // Untuk kuesioner, kalibrasi, sertifikasi — baca dari sample_type param
        $lower = strtolower(trim($sampleTypeParam));
        if ($lower === 'sertifikasi') return 'Sertifikasi';
        if ($lower === 'kalibrasi')   return 'Kalibrasi';
        if ($lower === 'pengecualian') return 'Pengujian Pengecualian';

        // Section detailorder umum — cek source=pengecualian dari GET
        if ($f3->get('GET.source') === 'pengecualian') return 'Pengujian Pengecualian';

        return 'Pengujian'; // default
    }

    private function validatePhoneNumber($phone, $fieldName = "Nomor telepon")
    {
        $phone = trim($phone);

        if (empty($phone)) {
            return ["error" => "$fieldName harus diisi", "cleaned" => null];
        }

        $cleanedPhone = ltrim($phone, '+');

        if (!ctype_digit($cleanedPhone)) {
            return ["error" => "$fieldName hanya boleh berisi angka (dengan atau tanpa awalan +)", "cleaned" => null];
        }

        if (strlen($cleanedPhone) < 10) {
            return ["error" => "$fieldName minimal 10 digit", "cleaned" => null];
        }

        if (strlen($cleanedPhone) > 15) {
            return ["error" => "$fieldName maksimal 15 digit", "cleaned" => null];
        }

        return ["error" => null, "cleaned" => $phone];
    }

    public function index($f3, $section = 'beranda', $subsection = '')
    {
        if (!$f3->get('SESSION.user_id') || $f3->get('SESSION.role') !== 'customer') {
            $f3->reroute('/');
            exit;
        }

        $user = new DB\SQL\Mapper($this->db, 'users');
        $user->load(['id = ?', $f3->get('SESSION.user_id')]);
        
        $profilePhotoUrl = $f3->get('BASE') . '/public/images/default-avatar.png';
        if (!empty($user->profile_photo) && is_string($user->profile_photo) && strpos($user->profile_photo, '.') !== false) {
            // Direct static file URL - no PHP overhead, served directly by web server
            $profilePhotoUrl = $f3->get('BASE') . '/public/uploads/profile/' . $user->profile_photo;
        } elseif (!empty($user->profile_photo)) {
            // Legacy BLOB - still use PHP route
            $profilePhotoUrl = $f3->get('BASE') . '/image/profile/' . $user->id;
        }

        $html = file_get_contents(__DIR__ . '/../../ui/dashboard/layout.html');

        // Resolve order_id secara aman (mendukung token HMAC maupun internal AJAX)
        $orderId    = $this->resolveOrderId($f3) ?? '';
        $sampleType = $f3->get('GET.sample_type') ?? '';

        $html = str_replace('{{ BASE }}', $f3->get('BASE'), $html);
        $html = str_replace('<!-- CSRF_TOKEN -->', $f3->get('SESSION.csrf_token') ?? '', $html);
        $html = str_replace('<!-- INITIAL_SECTION -->', $section, $html);
        $html = str_replace('<!-- INITIAL_SUBSECTION -->', $subsection, $html);
        $html = str_replace('<!-- INITIAL_ORDER_ID -->', $orderId, $html);
        $html = str_replace('<!-- INITIAL_SAMPLE_TYPE -->', htmlspecialchars($sampleType), $html);
        $html = str_replace('{{ nama_lengkap }}', $f3->get('SESSION.nama_lengkap') ?? 'Pelanggan', $html);
        $html = str_replace('{{ profile_photo_url }}', $profilePhotoUrl, $html);
        
        header('Content-Type: text/html; charset=utf-8');
        echo $html;
    }

    public function getProfilePhoto($f3)
    {
        $id   = $f3->get('PARAMS.id');
        $user = new DB\SQL\Mapper($this->db, 'users');
        $user->load(['id = ?', $id]);

        if (!$user->dry() && !empty($user->profile_photo)) {
            $photo      = $user->profile_photo;
            $isFileName = is_string($photo) && strlen($photo) < 255 && strpos($photo, '.') !== false;

            if ($isFileName) {
                $filePath = __DIR__ . '/../../public/uploads/profile/' . $photo;
                if (file_exists($filePath)) {
                    $finfo    = new finfo(FILEINFO_MIME_TYPE);
                    $mimeType = $finfo->file($filePath);
                    header('Content-Type: ' . $mimeType);
                    header('Cache-Control: no-cache, no-store, must-revalidate');
                    header('Pragma: no-cache');
                    header('Expires: 0');
                    readfile($filePath);
                    exit;
                }
            } else {
                $finfo    = new finfo(FILEINFO_MIME_TYPE);
                $mimeType = $finfo->buffer($photo);
                header('Content-Type: ' . $mimeType);
                header('Cache-Control: no-cache, no-store, must-revalidate');
                header('Pragma: no-cache');
                header('Expires: 0');
                echo $photo;
                exit;
            }
        }

        $defaultPath = __DIR__ . '/../../public/images/default-avatar.png';
        if (file_exists($defaultPath)) {
            header('Content-Type: image/png');
            header('Cache-Control: max-age=86400');
            readfile($defaultPath);
        }
        exit;
    }

    public function loadSection($f3)
    {
        if (!$f3->get('SESSION.user_id') || $f3->get('SESSION.role') !== 'customer') {
            echo json_encode(['success' => false, 'message' => 'Sesi tidak valid atau peran telah berubah. Silakan muat ulang halaman.', 'relogin' => true]);
            return;
        }

        try {

        $section = $f3->get('PARAMS.section');

        $user = new DB\SQL\Mapper($this->db, 'users');
        $user->load(['id = ?', $f3->get('SESSION.user_id')]);

        $sectionFile = __DIR__ . '/../../ui/pelanggan/' . $section . '.html';

        if (!file_exists($sectionFile)) {
            echo json_encode(['success' => false, 'message' => 'Section not found']);
            return;
        }

        $html = file_get_contents($sectionFile);

        // Check kuesioner interval requirement — per user per jenis layanan
        $userId = $f3->get('SESSION.user_id');
        $isKuesionerDone = false;
        $sampleTypeForCheck = $f3->get('GET.sample_type') ?? '';
        $currentJenisLayanan = $this->resolveJenisLayananFromSection($f3, $section, $sampleTypeForCheck);
        try {
            $this->db->exec("CREATE TABLE IF NOT EXISTS konfigurasi_kuesioner (
                id INT AUTO_INCREMENT PRIMARY KEY,
                config_key VARCHAR(100) NOT NULL UNIQUE,
                config_value VARCHAR(255) NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            )");
            $this->db->exec("CREATE TABLE IF NOT EXISTS data_responden (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                order_id VARCHAR(50) NOT NULL,
                nama_lengkap VARCHAR(255),
                instansi VARCHAR(255),
                UR1 INT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )");

            $configs = $this->db->exec("SELECT config_key, config_value FROM konfigurasi_kuesioner");
            $intervalVal = 3;
            $intervalUnit = 'bulan';
            if (!empty($configs)) {
                foreach($configs as $c) {
                    if ($c['config_key'] == 'kuesioner_interval_value') $intervalVal = (int) $c['config_value'];
                    if ($c['config_key'] == 'kuesioner_interval_unit') $intervalUnit = strtolower($c['config_value']);
                }
            }

            if ($intervalVal == 0) {
                // Mode per-order: cek berdasarkan user_id + order_id
                $checkOrderId = $this->resolveOrderId($f3) ?? '';
                $check = $this->db->exec(
                    "SELECT id FROM data_responden WHERE user_id = ? AND order_id = ? LIMIT 1",
                    [$userId, $checkOrderId]
                );
            } else {
                // Mode interval: cek berdasarkan user_id + jenis_pelayanan + waktu
                $unitMap = ['hari' => 'DAY', 'bulan' => 'MONTH', 'tahun' => 'YEAR'];
                $mysqlUnit = $unitMap[$intervalUnit] ?? 'MONTH';
                $intervalInt = (int) $intervalVal;
                $check = $this->db->exec(
                    "SELECT id FROM data_responden 
                     WHERE user_id = ? 
                     AND jenis_pelayanan = ?
                     AND created_at >= DATE_SUB(NOW(), INTERVAL {$intervalInt} {$mysqlUnit})
                     LIMIT 1",
                    [$userId, $currentJenisLayanan]
                );
            }

            if (!empty($check)) $isKuesionerDone = true;
            error_log("[Kuesioner Check] user=$userId layanan=$currentJenisLayanan done=" . ($isKuesionerDone ? 'true' : 'false'));
        } catch (\Exception $e) {
            error_log("[Kuesioner Check ERROR] " . $e->getMessage());
        }
        $html = str_replace('{{ is_kuesioner_done }}', $isKuesionerDone ? 'true' : 'false', $html);
        $html = str_replace('{{ jenis_layanan }}', htmlspecialchars($currentJenisLayanan), $html);

        if ($section === 'beranda') {
            $sil_id = !empty($user->id_customer) ? $user->id_customer : 0;

            $activeOrders = [];
            $dbError = null;
            $countPengujian = 0;
            $countKalibrasi = 0;
            $countSertifikasi = 0;

            try {
                if (!$this->silService) throw new \Exception("Koneksi database SIL2020 tidak tersedia. Pastikan konfigurasi Controller benar.");
                
                $counts = $this->silService->getServiceCounts($sil_id);
                $countPengujian   = $counts['pengujian'];
                // Kalibrasi dihitung dari sikalib2024 jika tersedia, fallback ke SIL
                $countKalibrasi   = $this->kalibService
                                  ? $this->kalibService->getOrderCount($sil_id)
                                  : $counts['kalibrasi'];
                $countSertifikasi = $counts['sertifikasi'];

                // Tambahkan count sertifikasi dari database serti2020
                if ($this->sertiService) {
                    $sertiCount = $this->sertiService->getOrderCount($sil_id);
                    $countSertifikasi += $sertiCount;
                }

                $years = $this->silService->getAvailableYears($sil_id);
                // Gabungkan tahun dari Sertifikasi
                if ($this->sertiService) {
                    $sertiYears = $this->sertiService->getAvailableYears($sil_id);
                    $allYears = array_unique(array_merge(
                        array_column($years, 'thn'),
                        array_column($sertiYears, 'thn')
                    ));
                    rsort($allYears);
                    $years = [];
                    foreach ($allYears as $y) {
                        $years[] = ['thn' => $y];
                    }
                }
                // Gabungkan tahun dari Kalibrasi
                if ($this->kalibService) {
                    $kalibYears = $this->kalibService->getAvailableYears($sil_id);
                    $allYears = array_unique(array_merge(
                        array_column($years, 'thn'),
                        array_column($kalibYears, 'thn')
                    ));
                    rsort($allYears);
                    $years = [];
                    foreach ($allYears as $y) {
                        $years[] = ['thn' => $y];
                    }
                }
                
                // Filter hanya yang berupa 4 digit angka yang valid
                $filteredYears = [];
                foreach ($years as $y) {
                    $val = trim($y['thn']);
                    if (is_numeric($val) && strlen($val) === 4) {
                        $filteredYears[] = ['thn' => $val];
                    }
                }
                $years = $filteredYears;

                $filterStatus = $f3->get('GET.status') ?? 'active';
                $filterLayanan = $f3->get('GET.layanan') ?? '';
                $filterTahun = $f3->get('GET.tahun') ?? '';
                
                $statusOptions = [
                    'active' => 'Order Berjalan',
                    'completed' => 'Order Selesai'
                ];
                $statusHtml = '';
                foreach ($statusOptions as $val => $label) {
                    $sel = ($filterStatus === $val) ? 'selected' : '';
                    $statusHtml .= "<div class='option $sel' data-value='$val'>$label</div>";
                }
                $html = str_replace('<!-- STATUS_OPTIONS -->', $statusHtml, $html);
                $html = str_replace('<!-- SELECTED_STATUS_TEXT -->', $statusOptions[$filterStatus] ?? 'Order Berjalan', $html);

                $layananOptions = [
                    '' => 'Pilih Layanan',
                    'Pengujian' => 'Pengujian',
                    'Kalibrasi' => 'Kalibrasi',
                    'Sertifikasi' => 'Sertifikasi',
                    'Pengujian Pengecualian' => 'Pengujian Pengecualian'
                ];
                $layananHtml = '';
                foreach ($layananOptions as $val => $label) {
                    $sel = ($filterLayanan === $val) ? 'selected' : '';
                    $layananHtml .= "<div class='option $sel' data-value='$val'>$label</div>";
                }
                $html = str_replace('<!-- LAYANAN_OPTIONS -->', $layananHtml, $html);
                $html = str_replace('<!-- SELECTED_LAYANAN_TEXT -->', $layananOptions[$filterLayanan] ?? 'Pilih Layanan', $html);

                $yearHtml = '<div class="option ' . (empty($filterTahun) ? 'selected' : '') . '" data-value="">Semua Tahun</div>';
                $selectedYearLabel = 'Semua Tahun';
                foreach ($years as $y) {
                    $isSel = ($filterTahun == $y['thn']);
                    if ($isSel) $selectedYearLabel = $y['thn'];
                    $yearHtml .= '<div class="option ' . ($isSel ? 'selected' : '') . '" data-value="'.$y['thn'].'">'.$y['thn'].'</div>';
                }
                $html = str_replace('<!-- YEAR_OPTIONS -->', $yearHtml, $html);
                $html = str_replace('<!-- SELECTED_TAHUN_TEXT -->', $selectedYearLabel, $html);

                $activeOrders = $this->silService->getOrderHistory($sil_id, $filterStatus, $filterLayanan, $filterTahun);

                if ($activeOrders) {
                    $ids = array_column($activeOrders, 'id_arsip');
                    $batchMap = $this->silService->getOrderBiayaBatch($ids);

                    foreach ($activeOrders as &$order) {
                        $aid = $order['id_arsip'];
                        $order['pembayaran_tanggal_bayar'] = $batchMap[$aid]['pembayaran_tanggal_bayar'] ?? null;
                        $order['total_biaya'] = $batchMap[$aid]['total_biaya'] ?? 0;
                        $order['_source'] = 'sil';
                    }
                    unset($order);
                }

                // ── Data Sertifikasi ──────────────────────────────────────────
                $sertiOrders = [];
                if ($this->sertiService && (empty($filterLayanan) || $filterLayanan === 'Sertifikasi')) {
                    $sertiOrders = $this->sertiService->getOrderHistory($sil_id, $filterStatus, $filterTahun);
                    foreach ($sertiOrders as &$sr) {
                        $sr['_source'] = 'serti';
                    }
                    unset($sr);
                }

                // ── Data Kalibrasi dari sikalib2024 ───────────────────────────
                $kalibrasiOrders = [];
                if ($this->kalibService && (empty($filterLayanan) || $filterLayanan === 'Kalibrasi')) {
                    $kalibrasiOrders = $this->kalibService->getOrderHistory($sil_id, $filterStatus, $filterTahun);
                    foreach ($kalibrasiOrders as &$kr) {
                        $kr['_source'] = 'kalib';
                    }
                    unset($kr);
                }

                // ── Merge & sort semua sumber (terbaru di atas) ───────────────
                // Jika filter Kalibrasi dan kalibService tersedia, jangan ambil dari SIL
                $silOrdersFiltered = $activeOrders ?: [];
                if ($filterLayanan === 'Kalibrasi' && $this->kalibService) {
                    $silOrdersFiltered = [];
                }

                $allOrders = array_merge($silOrdersFiltered, $sertiOrders, $kalibrasiOrders);
                usort($allOrders, function($a, $b) {
                    $da = $a['Tanggal_order'] ?? $a['tanggal_pendaftaran'] ?? $a['tanggal_order'] ?? '';
                    $db = $b['Tanggal_order'] ?? $b['tanggal_pendaftaran'] ?? $b['tanggal_order'] ?? '';
                    // Ubah format dd-mm-yyyy ke yyyy-mm-dd agar sortable
                    $toSort = function($d) {
                        if (preg_match('/^(\d{2})-(\d{2})-(\d{4})$/', $d, $m)) return $m[3].'-'.$m[2].'-'.$m[1];
                        return $d;
                    };
                    return strcmp($toSort($db), $toSort($da));
                });
                $activeOrders = $allOrders;
            } catch (\Exception $e) {
                $dbError = $e->getMessage();
            }

            $html = str_replace('<!-- COUNT_PENGUJIAN -->', $countPengujian, $html);
            $html = str_replace('<!-- COUNT_KALIBRASI -->', $countKalibrasi, $html);
            $html = str_replace('<!-- COUNT_SERTIFIKASI -->', $countSertifikasi, $html);

            $orderRows = '';
            
            if ($dbError) {
                $orderRows = "<tr><td colspan='7' style='padding: 32px; text-align: center; color: #DC2626; background: #FEF2F2;'>
                    <div style='font-weight: bold; margin-bottom: 8px;'>⚠️ Database Error</div>
                    <div style='font-size: 12px; font-family: monospace; white-space: pre-wrap;'>" . htmlspecialchars($dbError) . "</div>
                </td></tr>";
            } elseif ($activeOrders) {
                foreach ($activeOrders as $row) {

                    // ── Baris Sertifikasi ──────────────────────────────────
                    if (($row['_source'] ?? '') === 'serti') {
                        $orderNo    = $row['no_id_order'] ?? '-';
                        $date       = $row['tanggal_pendaftaran'] ?? '-';
                        $singkatan  = $row['singkatan'] ? ' (' . $row['singkatan'] . ')' : '';
                        $jenis      = ($row['nama_jenis_sertifikasi'] ?? '-') . $singkatan;
                        $layanan    = 'Sertifikasi';
                        $statusLabel = $this->sertiService->getStatusLabel($row);
                        $prosesDisplay = "<div style='font-size: 13px; font-weight: 600; color: #1e293b;'>$statusLabel</div>";
                        $totalBiaya  = (float)($row['jumlah_biaya_akomodasi'] ?? 0)
                                     + (float)($row['jumlah_biaya_sertifikasi'] ?? 0)
                                     + (float)($row['jumlah_biaya_transportasi'] ?? 0)
                                     + (float)($row['jumlah_biaya_uji'] ?? 0);
                        $biayaFormat = 'Rp ' . number_format($totalBiaya, 0, ',', '.');
                        $sertiId     = $row['id_arsip_sertifikasi'];

                        $orderRows .= "
                        <tr>
                            <td style='padding: 16px; font-weight: 600; color: #1e293b; font-size: 13px;'>$orderNo</td>
                            <td style='padding: 16px; color: #1e293b; font-size: 13px;'>$date</td>
                            <td style='padding: 16px; color: #1e293b; font-size: 13px;'>" . htmlspecialchars($jenis) . "</td>
                            <td style='padding: 16px; color: #1e293b; font-size: 13px;'>$layanan</td>
                            <td style='padding: 16px;'>$prosesDisplay</td>
                            <td style='padding: 16px; font-weight: 600; color: #3b82f6; font-size: 13px;'>$biayaFormat</td>
                            <td style='padding: 16px; text-align: center; white-space: nowrap;'>
                                <div style='display: inline-flex; gap: 6px; align-items: center;'>
                                    <button type='button'
                                       data-section='detailorderserti'
                                       data-id='$sertiId'
                                       data-source='serti'
                                       class='action-btn-detail view-order-detail'
                                       title='Detail Order Sertifikasi'>
                                        Detail
                                    </button>
                                </div>
                            </td>
                        </tr>";
                        continue;
                    }

                    // ── Baris Kalibrasi (sikalib2024) ──────────────────────
                    if (($row['_source'] ?? '') === 'kalib') {
                        $orderNo     = htmlspecialchars($row['nomor_order_lengkap'] ?? '-');
                        $date        = htmlspecialchars($row['tanggal_order'] ?? '-');
                        $jenis       = htmlspecialchars($row['uraian_order'] ?? '-');
                        $layanan     = 'Kalibrasi';
                        $statusLabel = $this->kalibService->getSimpleStatusLabel($row);
                        $prosesDisplay = "<div style='font-size: 13px; font-weight: 600; color: #1e293b;'>$statusLabel</div>";
                        $totalBiaya  = (float)($row['struktural_biaya_kerjasama_total'] ?? 0);
                        $biayaFormat = 'Rp ' . number_format($totalBiaya, 0, ',', '.');
                        $kalibId     = $row['id_arsip_kalibrasi'];

                        $orderRows .= "
                        <tr>
                            <td style='padding: 16px; font-weight: 600; color: #1e293b; font-size: 13px;'>$orderNo</td>
                            <td style='padding: 16px; color: #1e293b; font-size: 13px;'>$date</td>
                            <td style='padding: 16px; color: #1e293b; font-size: 13px;'>$jenis</td>
                            <td style='padding: 16px; color: #1e293b; font-size: 13px;'>$layanan</td>
                            <td style='padding: 16px;'>$prosesDisplay</td>
                            <td style='padding: 16px; font-weight: 600; color: #3b82f6; font-size: 13px;'>$biayaFormat</td>
                            <td style='padding: 16px; text-align: center; white-space: nowrap;'>
                                <div style='display: inline-flex; gap: 6px; align-items: center;'>
                                    <button type='button'
                                       data-section='detailorder-kalibrasi'
                                       data-id='$kalibId'
                                       data-source='kalib'
                                       class='action-btn-detail view-order-detail'
                                       title='Detail Order Kalibrasi'>
                                        Detail
                                    </button>
                                </div>
                            </td>
                        </tr>";
                        continue;
                    }

                    // ── Baris SIL (Pengujian / Kalibrasi / Pengecualian) ───
                    $orderNo = $row['No_order'] ?: $row['no_permintaan'];
                    $date = $row['Tanggal_order'];
                    
                    $layanan = 'Pengujian';
                    if (!empty($row['uji_pengecualian']) && $row['uji_pengecualian'] === 'Y') {
                        $layanan = 'Pengujian Pengecualian';
                    } elseif (isset($row['Uraian_contoh'])) {
                        if (strpos($row['Uraian_contoh'], 'Kalibrasi') !== false) $layanan = 'Kalibrasi';
                        if (strpos($row['Uraian_contoh'], 'Sertifikasi') !== false) $layanan = 'Sertifikasi';
                    }

                    $sampleInfo = ($row['Uraian_contoh'] ?? '-') . ' <br><span style="font-size: 11px; color: #64748b;">('. ($row['Banyak_contoh'] ?? '0') .' contoh)</span>';
                    
                    $statusLabelFinal = $this->silService->getSimpleStatusLabel($row);
                    $prosesDisplay = "<div style='font-size: 13px; font-weight: 600; color: #1e293b;'>$statusLabelFinal</div>";

                    $totalBiaya = $row['total_biaya'] ?? 0;
                    $biayaFormat = 'Rp ' . number_format($totalBiaya, 0, ',', '.');
                    
                    $sampleData = htmlspecialchars($row['Uraian_contoh'] ?? '', ENT_QUOTES);
                    $orderId = $row['id_arsip'];

                    $dataSource = ($layanan === 'Pengujian Pengecualian') ? "pengecualian" : "sil";
                    $orderRows .= "
                    <tr>
                        <td style='padding: 16px; font-weight: 600; color: #1e293b; font-size: 13px;'>$orderNo</td>
                        <td style='padding: 16px; color: #1e293b; font-size: 13px;'>$date</td>
                        <td style='padding: 16px; color: #1e293b; font-size: 13px;'>$sampleInfo</td>
                        <td style='padding: 16px; color: #1e293b; font-size: 13px;'>$layanan</td>
                        <td style='padding: 16px;'>$prosesDisplay</td>
                        <td style='padding: 16px; font-weight: 600; color: #3b82f6; font-size: 13px;'>$biayaFormat</td>
                        <td style='padding: 16px; text-align: center; white-space: nowrap;'>
                            <div style='display: inline-flex; gap: 6px; align-items: center;'>
                                <button type='button' 
                                   data-section='detailorder'
                                   data-sample='$sampleData'
                                   data-id='$orderId'
                                   data-source='$dataSource'
                                   class='action-btn-detail view-order-detail'
                                   title='Detail Order'>
                                    Detail
                                </button>
                            </div>
                        </td>
                    </tr>";
                }
            } else {
                $orderRows = "<tr><td colspan='7' style='padding: 32px; text-align: center; color: #94a3b8;'>
                    <div style='margin-bottom: 8px;'>📭</div>
                    <div>Tidak ada order berjalan saat ini</div>
                </td></tr>";
            }
            $html = str_replace('<!-- ORDER_HISTORY_ROWS -->', $orderRows, $html);

            if ($f3->get('GET.only_table')) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['success' => true, 'html' => $orderRows], JSON_INVALID_UTF8_SUBSTITUTE);
                exit;
            }

            $activeAnnouncements = [];
            try {
                $activeAnnouncements = $this->db->exec("SELECT * FROM announcements WHERE is_active = 1 ORDER BY created_at DESC") ?: [];
            } catch (\Exception $e) {
            }
            $announcementsHtml = '';
            
            if ($activeAnnouncements && count($activeAnnouncements) > 0) {
                $announcementsHtml = '<div class="content-card announcement-banner" style="display: flex; gap: 24px; padding: 28px; align-items: flex-start;">';
                
                $announcementsHtml .= '<div style="flex-shrink: 0; padding-top: 4px;">
                            <img src="' . $f3->get('BASE') . '/public/images/beranda/pemberitahuan.png" alt="Pengumuman" style="width: 64px; height: auto;">
                        </div>';
                
                $announcementsHtml .= '<div style="flex: 1;">';
                
                $announcementsHtml .= '<h4 style="margin: 0 0 20px 0; font-size: 18px; font-weight: 800; color: #1e293b; letter-spacing: -0.5px;">Pemberitahuan</h4>';
                
                $announcementsHtml .= '<div style="display: flex; flex-direction: column; gap: 16px;">';
                foreach ($activeAnnouncements as $announcement) {
                    $date = date('d F Y', strtotime($announcement['created_at']));
                    $announcementsHtml .= '
                    <div style="display: flex; gap: 12px; align-items: flex-start;">
                        <div style="flex: 1;">
                            <p style="margin: 0; color: #000000; font-size: 14px; line-height: 1.6; font-weight: 500; font-family: var(--font-main);">
                                <span style="font-weight: 500; font-family: \'Inter\', sans-serif;">[' . $date . ']</span> - ' . nl2br(htmlspecialchars($announcement['content'])) . '
                            </p>
                        </div>
                    </div>';
                }
                $announcementsHtml .= '</div>'; // End List
                $announcementsHtml .= '</div>'; // End Right Side Area
                $announcementsHtml .= '</div>'; // End Card
            }
            $html = str_replace('<!-- ANNOUNCEMENTS_LIST -->', $announcementsHtml, $html);
        }

        if ($section === 'pembayaran') {
            $sil_id = !empty($user->id_customer) ? $user->id_customer : 0;
            $billItemsHtml = '';
            $billDataJson = '[]';

            try {
                if (!$this->silService) throw new \Exception("Koneksi database SIL2020 tidak tersedia.");

                $activeOrders = $this->silService->getOrderHistory($sil_id, 'active');

                if ($activeOrders) {
                    $ids = array_column($activeOrders, 'id_arsip');
                    $batchMap = $this->silService->getOrderBiayaBatch($ids);

                    $pendingUploads = [];
                    try {
                        $pendingUploads = $this->db->exec(
                            "SELECT order_id FROM payment_uploads WHERE user_id = ? AND status = 'Menunggu'",
                            [$f3->get('SESSION.user_id')]
                        );
                    } catch (\Exception $e) {}
                    
                    $pendingOrderIds = array_column($pendingUploads, 'order_id');

                    $unpaidOrders = [];
                    foreach ($activeOrders as $order) {
                        $aid = $order['id_arsip'];
                        $tglBayar = $batchMap[$aid]['pembayaran_tanggal_bayar'] ?? null;
                        
                        if (empty($tglBayar) && !in_array($aid, $pendingOrderIds)) {
                            $order['total_biaya'] = $batchMap[$aid]['total_biaya'] ?? 0;
                            $unpaidOrders[] = $order;
                        }
                    }

                    $billDataArr = [];
                    foreach ($unpaidOrders as $order) {
                        $orderNo = $order['No_order'] ?: $order['no_permintaan'];
                        $sampleName = $order['Uraian_contoh'] ?? '-';
                        $totalBiaya = (float)($order['total_biaya'] ?? 0);
                        $biayaFormat = 'Rp ' . number_format($totalBiaya, 0, ',', '.');
                        $orderId = $order['id_arsip'];

                        $billItemsHtml .= "
                        <div class=\"bill-item\" data-id=\"{$orderId}\" data-no=\"{$orderNo}\" data-amount=\"{$totalBiaya}\">
                            <div class=\"bill-checkbox bill-checkbox-checked\" data-checked=\"true\">
                                <svg xmlns=\"http://www.w3.org/2000/svg\" width=\"16\" height=\"16\" viewBox=\"0 0 24 24\" fill=\"none\"
                                    stroke=\"currentColor\" stroke-width=\"3\" stroke-linecap=\"round\" stroke-linejoin=\"round\">
                                    <polyline points=\"20 6 9 17 4 12\"></polyline>
                                </svg>
                            </div>
                            <div class=\"bill-info\">
                                <div class=\"bill-no\">{$orderNo}</div>
                                <div class=\"bill-spec\">" . htmlspecialchars($sampleName) . "</div>
                            </div>
                            <div class=\"bill-amount\">{$biayaFormat}</div>
                        </div>";

                        $billDataArr[] = [
                            'id' => $orderId,
                            'no' => $orderNo,
                            'amount' => $totalBiaya
                        ];
                    }

                    $billDataJson = json_encode($billDataArr);
                }
            } catch (\Exception $e) {
                $billItemsHtml = "<div style='padding: 24px; text-align: center; color: #ef4444;'>⚠️ " . htmlspecialchars($e->getMessage()) . "</div>";
            }

            if (empty($billItemsHtml)) {
                $billItemsHtml = "
                <div class=\"bill-empty-state\">
                    <svg xmlns=\"http://www.w3.org/2000/svg\" width=\"48\" height=\"48\" viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"#cbd5e1\" stroke-width=\"1.5\" stroke-linecap=\"round\" stroke-linejoin=\"round\">
                        <rect x=\"2\" y=\"5\" width=\"20\" height=\"14\" rx=\"2\" ry=\"2\"></rect>
                        <line x1=\"2\" y1=\"10\" x2=\"22\" y2=\"10\"></line>
                    </svg>
                    <p>Tidak ada tagihan aktif saat ini.</p>
                </div>";
            }

            $html = str_replace('<!-- BILL_ITEMS -->', $billItemsHtml, $html);
            $html = str_replace('<!-- BILL_DATA_JSON -->', htmlspecialchars($billDataJson, ENT_QUOTES | ENT_HTML5, 'UTF-8'), $html);

            $user_id = $f3->get('SESSION.user_id');
            $paymentHistory = [];
            try {
                $paymentHistory = $this->db->exec(
                    'SELECT * FROM payment_uploads WHERE user_id = ? ORDER BY created_at DESC LIMIT 20',
                    [$user_id]
                );
            } catch (\Exception $e) {
            }

            $historyRows = '';
            if ($paymentHistory && count($paymentHistory) > 0) {
                foreach ($paymentHistory as $ph) {
                    $statusStyle = '';
                    $statusText = $ph['status'] ?? 'Menunggu';
                    if ($statusText === 'Gagal') {
                        $statusStyle = "color: #ef4444; background: #fee2e2;";
                    } elseif ($statusText === 'Diverifikasi') {
                        $statusStyle = "color: #16a34a; background: #dcfce7;";
                    } else {
                        $statusStyle = "color: #d97706; background: #fef3c7;";
                    }
                    $alasan = !empty($ph['reason']) ? htmlspecialchars($ph['reason']) : '-';
                    $historyRows .= "<tr>
                        <td>" . htmlspecialchars($ph['order_no'] ?? '-') . "</td>
                        <td>" . htmlspecialchars($ph['file_name'] ?? '-') . "</td>
                        <td>{$alasan}</td>
                        <td><span style=\"{$statusStyle} font-weight: 700; font-size: 13px; padding: 4px 10px; border-radius: 6px;\">{$statusText}</span></td>
                    </tr>";
                }
            } else {
                $historyRows = "<tr><td colspan='4' style='padding: 24px; text-align: center; color: #94a3b8;'>Belum ada riwayat pembayaran.</td></tr>";
            }
            $html = str_replace('<!-- PAYMENT_HISTORY_ROWS -->', $historyRows, $html);
        }


        if ($section === 'detailorderserti') {
            // Resolve order_id secara aman — tolak jika token tidak valid atau bukan milik user
            $orderId   = $this->resolveOrderId($f3);
            $orderData = null;

            if ($orderId && $this->sertiService) {
                try {
                    $orderData = $this->sertiService->getOrderDetail($orderId);
                } catch (\Exception $e) {}
            }

            $displayOrderId = $orderData['no_id_order'] ?? $orderId;
            $html = str_replace('{{ order_id }}', htmlspecialchars($displayOrderId), $html);
            $html = str_replace('{{ BASE }}', $f3->get('BASE'), $html);

            // ── Stepper tracking ──────────────────────────────────────────────
            $tracking    = $this->sertiService
                           ? $this->sertiService->getOrderTracking($orderData ?? [])
                           : ['current_step' => 1, 'steps' => []];
            $stepsData   = $tracking['steps'];

            $stepperHtml = '<div class="order-stepper-modern"><div class="stepper-track-v2">';
            for ($i = 1; $i <= 6; $i++) {
                $step  = $stepsData[$i] ?? ['label' => '-', 'status' => 'pending', 'date' => '-'];
                $class = $step['status'] === 'active' ? 'current' : $step['status'];

                $stepperHtml .= '<div class="step-v2 ' . $class . '">';
                $stepperHtml .= '<div class="step-icon-v2">';
                if ($step['status'] === 'completed') {
                    $stepperHtml .= '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>';
                } else {
                    $stepperHtml .= $i;
                }
                $stepperHtml .= '</div>';
                $stepperHtml .= '<div class="step-info-v2">';
                $stepperHtml .= '<span class="step-label-v2">' . $step['label'] . '</span>';
                if ($step['status'] === 'completed') {
                    $stepperHtml .= '<span class="step-date-v2">' . $step['date'] . '</span>';
                } elseif ($step['status'] === 'active') {
                    $stepperHtml .= '<span class="step-status-v2">Sedang Berjalan</span>';
                }
                $stepperHtml .= '</div></div>';

                if ($i < 6) {
                    $lineClass    = ($stepsData[$i]['status'] ?? '') === 'completed' ? 'completed' : '';
                    if (($stepsData[$i]['status'] ?? '') === 'completed'
                        && ($stepsData[$i+1]['status'] ?? '') === 'active') {
                        $lineClass = 'active';
                    }
                    $stepperHtml .= '<div class="step-line-v2 ' . $lineClass . '"></div>';
                }
            }
            $stepperHtml .= '</div></div>';
            $html = str_replace('<!-- STEPPER_HTML -->', $stepperHtml, $html);

            // ── Informasi Biaya ───────────────────────────────────────────────
            $biayaItems = [
                'Biaya Sertifikasi'      => (float)($orderData['jumlah_biaya_sertifikasi'] ?? 0),
                'Biaya Pengujian Produk' => (float)($orderData['jumlah_biaya_uji']         ?? 0),
                'Biaya Transportasi'     => (float)($orderData['jumlah_biaya_transportasi'] ?? 0),
                'Biaya Akomodasi'        => (float)($orderData['jumlah_biaya_akomodasi']   ?? 0),
            ];
            $totalBiaya = array_sum($biayaItems);
            $fmt = function($v) { return 'Rp. ' . number_format($v, 0, ',', '.'); };

            $biayaRows = '';
            foreach ($biayaItems as $label => $nominal) {
                $biayaRows .= "<div class='info-row-modern'>
                    <span class='info-label-modern'>$label</span>
                    <span class='info-value-modern'>" . $fmt($nominal) . "</span>
                </div>";
            }
            $biayaRows .= "<div class='info-row-modern' style='border-top: 2px solid #f1f5f9; margin-top: 8px;'>
                <span class='info-label-modern' style='color: #0f172a;'>Total Biaya</span>
                <span class='info-value-modern' style='color: #2C7BE5; font-size: 18px; font-weight: 800;'>" . $fmt($totalBiaya) . "</span>
            </div>";
            $html = str_replace('<!-- BIAYA_ROWS -->', $biayaRows, $html);
            $html = str_replace('{{ id_arsip_sertifikasi }}', htmlspecialchars($orderId ?? ''), $html);
        }

        if ($section === 'detailorder-kalibrasi') {
            $orderId   = $this->resolveOrderId($f3);
            $orderData = null;

            if ($orderId && $this->kalibService) {
                try {
                    $orderData = $this->kalibService->getOrderDetail($orderId);
                } catch (\Exception $e) {}
            }

            $displayOrderId = $orderData['nomor_order_lengkap'] ?? $orderId;
            $html = str_replace('{{ order_id }}', htmlspecialchars($displayOrderId ?? ''), $html);
            $html = str_replace('{{ BASE }}', $f3->get('BASE'), $html);

            // ── Stepper tracking kalibrasi (4 step) ──────────────────────────
            $tglPembayaran  = (!empty($orderData['pembayaran_tanggal_bayar']) && $orderData['pembayaran_tanggal_bayar'] !== '0000-00-00 00:00:00')
                              ? date('d-m-Y', strtotime($orderData['pembayaran_tanggal_bayar'])) : null;
            $tglPendaftaran = (!empty($orderData['tanggal_order']) && $orderData['tanggal_order'] !== '0000-00-00')
                              ? date('d-m-Y', strtotime($orderData['tanggal_order'])) : null;
            
            $tglPulang = $orderData['tanggal_kalibrasi_pulang'] ?? null;
            $rawLabTek = $this->kalibService ? $this->kalibService->calculateLabTeknisDate($tglPulang) : null;
            $tglLabtek = (!empty($rawLabTek)) ? date('d-m-Y', strtotime($rawLabTek)) : null;

            $tglSelesai     = (!empty($orderData['tanggal_selesai_realisasi']) && $orderData['tanggal_selesai_realisasi'] !== '0000-00-00')
                              ? date('d-m-Y', strtotime($orderData['tanggal_selesai_realisasi'])) : null;

            // Tentukan status tiap step
            $steps = [
                1 => ['label' => 'Pembayaran',          'date' => $tglPembayaran],
                2 => ['label' => 'Pendaftaran',          'date' => $tglPendaftaran],
                3 => ['label' => 'Laboratorium Teknis',  'date' => $tglLabtek],
                4 => ['label' => 'Selesai',              'date' => $tglSelesai],
            ];

            // Tentukan step mana yang completed/active/pending
            $lastCompleted = 0;
            foreach ($steps as $i => $s) {
                if ($s['date']) $lastCompleted = $i;
            }
            $currentStep = $lastCompleted < 4 ? $lastCompleted + 1 : 4;

            $stepsData = [];
            foreach ($steps as $i => $s) {
                if ($s['date']) {
                    $stepsData[$i] = ['label' => $s['label'], 'status' => 'completed', 'date' => $s['date']];
                } elseif ($i === $currentStep) {
                    $stepsData[$i] = ['label' => $s['label'], 'status' => 'active', 'date' => '-'];
                } else {
                    $stepsData[$i] = ['label' => $s['label'], 'status' => 'pending', 'date' => '-'];
                }
            }

            $stepperHtml = '<div class="order-stepper-modern"><div class="stepper-track-v2">';
            for ($i = 1; $i <= 4; $i++) {
                $step  = $stepsData[$i];
                $class = $step['status'] === 'active' ? 'current' : $step['status'];

                $stepperHtml .= '<div class="step-v2 ' . $class . '">';
                $stepperHtml .= '<div class="step-icon-v2">';
                if ($step['status'] === 'completed') {
                    $stepperHtml .= '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>';
                } else {
                    $stepperHtml .= $i;
                }
                $stepperHtml .= '</div>';
                $stepperHtml .= '<div class="step-info-v2">';
                $stepperHtml .= '<span class="step-label-v2">' . $step['label'] . '</span>';
                if ($step['status'] === 'completed') {
                    $stepperHtml .= '<span class="step-date-v2">' . $step['date'] . '</span>';
                } elseif ($step['status'] === 'active') {
                    $stepperHtml .= '<span class="step-status-v2">Sedang Berjalan</span>';
                }
                $stepperHtml .= '</div></div>';

                if ($i < 4) {
                    $lineClass = ($stepsData[$i]['status'] === 'completed') ? 'completed' : '';
                    if ($stepsData[$i]['status'] === 'completed' && $stepsData[$i + 1]['status'] === 'active') {
                        $lineClass = 'active';
                    }
                    $stepperHtml .= '<div class="step-line-v2 ' . $lineClass . '"></div>';
                }
            }
            $stepperHtml .= '</div></div>';
            $html = str_replace('<!-- STEPPER_HTML -->', $stepperHtml, $html);

            // ── Data order kalibrasi ──────────────────────────────────────────
            $html = str_replace('{{ tgl_pembayaran }}',  htmlspecialchars($tglPembayaran  ?? '-'), $html);
            $html = str_replace('{{ tgl_pendaftaran }}', htmlspecialchars($tglPendaftaran ?? '-'), $html);
            $html = str_replace('{{ lab_teknis }}',      htmlspecialchars($orderData['uraian_order'] ?? '-'), $html);

            // ── Data Alat (Ambil dulu untuk menghitung total biaya alat) ──
            $alatRows = '';
            $totalBiayaAlat = 0;
            $logUploadMap = []; // Lookup tanggal dari tb_log_upload_xls
            $arsipLhuMap  = []; // Lookup file LHU dari tb_arsip_lhu
            if ($orderId && $this->kalibService) {
                try {
                    $logUploadMap = $this->kalibService->getLogUploadAlat($orderId);
                } catch (\Exception $e) {}
                try {
                    $arsipLhuMap = $this->kalibService->getArsipLhu($orderId);
                } catch (\Exception $e) {}
                try {
                    $alatData = $this->kalibService->getDetailAlat($orderId);
                    $no = 1;
                    foreach ($alatData as $alat) {
                        $namaAlat       = htmlspecialchars($alat['nama_alat'] ?? $alat['merk_alat'] ?? '-');
                        $nomorAlat      = htmlspecialchars($alat['lhu_nomor_order_postfix'] ?? '-');
                            $prefixOrder    = $alat['lhu_nomor_order_lengkap_prefix'] ?? '';
                        $postfixOrder   = $alat['lhu_nomor_order_lengkap_postfix'] ?? '';
                        $nomorOrder     = htmlspecialchars($prefixOrder . $postfixOrder);
                        if (empty($nomorOrder)) $nomorOrder = '-';

                        $tarifVal       = (float)($alat['tarif'] ?? 0);
                        $totalBiayaAlat += $tarifVal;
                        $tarif          = 'Rp. ' . number_format($tarifVal, 0, ',', '.');
                        
                        $jenisPekerjaan = !empty($alat['jenis_pekerjaan']) ? htmlspecialchars($alat['jenis_pekerjaan']) : htmlspecialchars($orderData['uraian_order'] ?? '-');
                        
                        // Tanggal Hasil Uji & Validasi dari tb_log_upload_xls
                        $alatId = $alat['id'];
                        $logData = $logUploadMap[$alatId] ?? null;

                        $tglHasilUjiRaw = $logData['tanggal_upload'] ?? null;
                        $tglHasilUji    = (!empty($tglHasilUjiRaw) && $tglHasilUjiRaw != '0000-00-00 00:00:00') ? date('d-m-Y', strtotime($tglHasilUjiRaw)) : '-';

                        $tglValidasiRaw = $logData['tanggal_validasi'] ?? null;
                        $tglValidasi    = (!empty($tglValidasiRaw) && $tglValidasiRaw != '0000-00-00 00:00:00') ? date('d-m-Y', strtotime($tglValidasiRaw)) : '-';

                        $lembarHasil = '-';
                        // Cek arsip LHU dari tb_arsip_lhu
                        $alatId    = $alat['id']; // id_penawaran_alat
                        $lhuRecord = $arsipLhuMap[$alatId] ?? null;
                        $svgIcon   = '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg>';

                        if (!empty($lhuRecord) && !empty($lhuRecord['nama_file_proses'])) {
                            if (!$isKuesionerDone) {
                                // Gate: wajib isi kuesioner Kalibrasi dulu
                                $kaibOidEsc = htmlspecialchars((string)$orderId);
                                $lembarHasil = "<a href='javascript:void(0)' onclick=\"loadSection('kuesioner','Kalibrasi','{$kaibOidEsc}')\" style='color:#d97706;font-weight:600;display:inline-flex;align-items:center;gap:6px;'>Isi Kuesioner</a>";
                            } else {
                                // Sudah isi kuesioner — tampilkan link unduh
                                $prosesLhu = strtolower(trim($lhuRecord['proses_lhu'] ?? ''));
                                $lhuType   = ($prosesLhu === 'amandemen') ? 'kalib-lhu-amandemen' : 'kalib-lhu-pertama';
                                $lhuToken  = doc_make_token($lhuRecord['nama_file_proses'], $lhuType);
                                $lhuUrl    = $f3->get('BASE') . '/dokumen/view/' . $lhuToken;
                                $lembarHasil = "<a href='" . htmlspecialchars($lhuUrl) . "' target='_blank' style='color: #2563eb; text-decoration: none; font-weight: 600; display: inline-flex; align-items: center; gap: 6px;'>Unduh {$svgIcon}</a>";
                            }
                        } else {
                            // Belum ada arsip LHU untuk alat ini
                            $lembarHasil = "<span style='color: #94a3b8; font-weight: 600; display: inline-flex; align-items: center; gap: 6px; cursor: not-allowed;' title='Lembar Hasil belum tersedia'>- {$svgIcon}</span>";
                        }


                        $alatRows .= "<tr>
                            <td>{$no}</td>
                            <td>{$nomorAlat}</td>
                            <td>{$nomorOrder}</td>
                            <td class='param-name'>{$namaAlat}</td>
                            <td>{$tarif}</td>
                            <td>{$jenisPekerjaan}</td>
                            <td>{$tglHasilUji}</td>
                            <td>{$tglValidasi}</td>
                            <td>{$lembarHasil}</td>
                        </tr>";
                        $no++;
                    }
                } catch (\Exception $e) {}
            }
            if (empty($alatRows)) {
                $alatRows = '<tr><td colspan="7" style="padding: 24px; text-align: center; color: #94a3b8;">Data alat belum tersedia.</td></tr>';
            }
            $html = str_replace('<!-- ITERATE DATA ALAT ROWS -->', $alatRows, $html);

            // ── Informasi Biaya ───────────────────────────────────────────────
            $biayaAlat       = $totalBiayaAlat; // Gunakan total tarif yang dihitung
            $biayaTransport  = (float)($orderData['struktural_biaya_kerjasama_transportasi']  ?? 0);
            $jasaInsitu      = (float)($orderData['struktural_biaya_kerjasama_insitu']      ?? 0);
            $biayaPembulatan = (float)($orderData['struktural_biaya_kerjasama_pembulatan']  ?? 0);
            $totalBiaya      = (float)($orderData['struktural_biaya_kerjasama_total']       ?? ($biayaAlat + $biayaTransport + $jasaInsitu + $biayaPembulatan));
            $fmt = function($v) { return 'Rp. ' . number_format($v, 0, ',', '.'); };

            $html = str_replace('{{ biaya_alat }}',       $fmt($biayaAlat),       $html);
            $html = str_replace('{{ biaya_transport }}',  $fmt($biayaTransport),  $html);
            $html = str_replace('{{ jasa_insitu }}',      $fmt($jasaInsitu),      $html);
            $biayaSelisihHtml = '';
            if ($biayaPembulatan != 0) {
                $biayaSelisihHtml = '<div class="info-row-modern">
                                        <span class="info-label-modern">Biaya Selisih Alat</span>
                                        <span class="info-value-modern">' . $fmt($biayaPembulatan) . '</span>
                                     </div>';
            }
            $html = str_replace('<!-- BIAYA_SELISIH_ALAT_ROW -->', $biayaSelisihHtml, $html);
            $html = str_replace('{{ total_biaya }}',     $fmt($totalBiaya),     $html);
            $html = str_replace('{{ total_biaya_alat }}', $fmt($totalBiayaAlat), $html);

            // ── Download Berkas: Invoice ──────────────────────────────────────
            $invoicesHtml = '';
            if ($orderId && $this->kalibService) {
                try {
                    $invRecord = $this->kalibService->getInvoiceFiles($orderId);
                    if (!empty($invRecord)) {
                        $invFields = [
                            ['field' => 'invoice',   'label' => 'Invoice',   'desc' => 'Rincian Tagihan'],
                        ];
                        foreach ($invFields as $inv) {
                            $fileName = $invRecord[$inv['field']] ?? null;
                            if (!empty($fileName) && trim($fileName) !== '') {
                                $token  = doc_make_token(trim($fileName), 'kalib-invoice');
                                $invUrl = $f3->get('BASE') . '/dokumen/view/' . $token;
                                $invoicesHtml .= '
                        <div class="document-list-item">
                            <div class="doc-list-info"><div class="doc-list-text">
                                <span class="doc-list-title">'.$inv['label'].'</span>
                                <span class="doc-list-desc">'.$inv['desc'].'</span>
                            </div></div>
                            <div class="doc-list-status">
                                <span class="doc-status-simple available" style="text-transform:none!important;font-size:14px;">Tersedia</span>
                            </div>
                            <div class="doc-list-action">
                                <a href="'.htmlspecialchars($invUrl).'" class="link-text-action" target="_blank">Unduh</a>
                            </div>
                        </div>';
                            }
                        }
                    }
                } catch (\Exception $e) {}
            }
            if (empty($invoicesHtml)) {
                $invoicesHtml = '
                        <div class="document-list-item">
                            <div class="doc-list-info"><div class="doc-list-text">
                                <span class="doc-list-title">Invoice</span>
                                <span class="doc-list-desc">Faktur Pembayaran</span>
                            </div></div>
                            <div class="doc-list-status">
                                <span class="doc-status-simple" style="color:#64748b;font-weight:500;text-transform:none!important;font-size:14px;">Belum tersedia</span>
                            </div>
                            <div class="doc-list-action">
                                <span class="link-text-action" style="color:#cbd5e1;cursor:not-allowed;">Unduh</span>
                            </div>
                        </div>';
            }
            $html = str_replace('<!-- INVOICES_KALIBRASI_HTML -->', $invoicesHtml, $html);

            // ── Dokumen Permohonan Alat ───────────────────────────────────────
            $dokPermohonanAlatHtml = '';
            $currentUserId = (int) $f3->get('SESSION.user_id');
            $idAlatDokumen = (int) ($orderData['id_alat_dokumen'] ?? 0);

            $berkasAlatLinks = [];
            $alatDokFound    = false;

            if ($idAlatDokumen > 0) {
                try {
                    $alatDokRec = $this->db->exec(
                        "SELECT id, id_pengguna, nama_file_acak,
                                nama_file_acak_1, nama_file_acak_2, nama_file_acak_3,
                                nama_file_acak_4, nama_file_acak_5,
                                nama_file_1, nama_file_2, nama_file_3, nama_file_4, nama_file_5,
                                status
                         FROM alat_dokumen
                         WHERE id = ? LIMIT 1",
                        [$idAlatDokumen]
                    );

                    if (!empty($alatDokRec) && (int)$alatDokRec[0]['id_pengguna'] === $currentUserId) {
                        $dok = $alatDokRec[0];
                        $alatDokFound = true;

                        // File utama
                        if (!empty(trim($dok['nama_file_acak'] ?? ''))) {
                            $token = doc_make_token((string)trim($dok['nama_file_acak']), 'kalib-permohonan-alat');
                            $berkasAlatLinks[] = [
                                'label' => 'Dokumen Permohonan Alat',
                                'url'   => $f3->get('BASE') . '/dokumen/view/' . $token
                            ];
                        }

                        // Berkas revisi 1–5
                        for ($ri = 1; $ri <= 5; $ri++) {
                            $namaAcak = $dok["nama_file_acak_{$ri}"] ?? '';
                            if (!empty(trim($namaAcak))) {
                                $token = doc_make_token((string)trim($namaAcak), 'kalib-permohonan-alat');
                                $berkasAlatLinks[] = [
                                    'label' => "Berkas Revisi {$ri}",
                                    'url'   => $f3->get('BASE') . '/dokumen/view/' . $token
                                ];
                            }
                        }
                    }
                } catch (\Exception $e) {
                    error_log("[DokPermohonanAlat ERROR] " . $e->getMessage());
                }
            }

            // Build HTML
            if ($alatDokFound && !empty($berkasAlatLinks)) {
                foreach ($berkasAlatLinks as $berkas) {
                    $dokPermohonanAlatHtml .= '
                    <div class="document-list-item">
                        <div class="doc-list-info">
                            <div class="doc-list-text">
                                <span class="doc-list-title">Dokumen Permohonan Alat</span>
                                <span class="doc-list-desc">' . htmlspecialchars($berkas['label']) . '</span>
                            </div>
                        </div>
                        <div class="doc-list-status">
                            <span class="doc-status-simple available"
                                style="text-transform: none !important; font-size: 14px;">Tersedia</span>
                        </div>
                        <div class="doc-list-action">
                            <a href="' . htmlspecialchars($berkas['url']) . '"
                               class="link-text-action" target="_blank">Unduh</a>
                        </div>
                    </div>';
                }
            } else {
                $dokPermohonanAlatHtml = '
                    <div class="document-list-item">
                        <div class="doc-list-info">
                            <div class="doc-list-text">
                                <span class="doc-list-title">Dokumen Permohonan Alat</span>
                                <span class="doc-list-desc">Dokumen permohonan kalibrasi alat</span>
                            </div>
                        </div>
                        <div class="doc-list-status">
                            <span style="color:#64748b; font-weight:500;
                                         text-transform: none !important; font-size: 14px;">Belum tersedia</span>
                        </div>
                        <div class="doc-list-action">
                            <span class="link-text-action" style="color:#cbd5e1; cursor:not-allowed;">Unduh</span>
                        </div>
                    </div>';
            }
            $html = str_replace('<!-- DOK_PERMOHONAN_ALAT_HTML -->', $dokPermohonanAlatHtml, $html);
        }


        if ($section === 'detailorder' || $section === 'download_berkas' || $section === 'kuesioner') {

            // Resolve order_id secara aman — tolak jika token tidak valid atau bukan milik user
            $orderId         = $this->resolveOrderId($f3);
            $sampleTypeParam = $f3->get('GET.sample_type') ?: 'Kertas';
            
            $orderData = null;
            if ($orderId && $this->silService) {
                try {
                    $orderData = $this->silService->getOrderDetail($orderId);
                } catch (\Exception $e) {
                }
            }

            $sampleType = $orderData['Uraian_contoh'] ?? $sampleTypeParam;
            $displayOrderId = $orderData['No_order'] ?? $orderId;
            $detailContoh = !empty($orderData['Ket_contoh']) ? $orderData['Ket_contoh'] : ($orderData['perihal'] ?? '-');

            if ($this->silService) {
                $tracking = $this->silService->getOrderTracking($orderData);
            } else {
                // Fallback when silService is unavailable
                $tracking = [
                    'current_step' => 0,
                    'status_label' => 'Tidak Tersedia',
                    'steps' => [
                        1 => ['label' => 'Pendaftaran',             'status' => 'pending', 'date' => '-'],
                        2 => ['label' => 'Pembayaran',              'status' => 'pending', 'date' => '-'],
                        3 => ['label' => 'Proses Lab',              'status' => 'pending', 'date' => '-'],
                        4 => ['label' => 'Hasil Uji',               'status' => 'pending', 'date' => '-'],
                        5 => ['label' => 'Validasi Data Hasil Uji', 'status' => 'pending', 'date' => '-'],
                        6 => ['label' => 'Selesai',                 'status' => 'pending', 'date' => '-'],
                    ]
                ];
            }
            $currentStep = $tracking['current_step'];
            $stepsData = $tracking['steps'];

            $stepperHtml = '<div class="order-stepper-modern"><div class="stepper-track-v2">';
            for ($i = 1; $i <= 6; $i++) {
                $step = $stepsData[$i];
                $class = $step['status']; // completed, active, or pending
                if ($class === 'active') $class = 'current'; // UI uses 'current' for active step

                $stepperHtml .= '<div class="step-v2 ' . $class . '">';
                $stepperHtml .= '<div class="step-icon-v2">';
                if ($step['status'] === 'completed') {
                    $stepperHtml .= '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>';
                } else {
                    $stepperHtml .= $i;
                }
                $stepperHtml .= '</div>';
                $stepperHtml .= '<div class="step-info-v2">';
                $stepperHtml .= '<span class="step-label-v2">' . $step['label'] . '</span>';
                if ($step['status'] === 'completed') {
                    $stepperHtml .= '<span class="step-date-v2">' . $step['date'] . '</span>';
                } elseif ($step['status'] === 'active') {
                    $stepperHtml .= '<span class="step-status-v2">Sedang Berjalan</span>';
                }
                $stepperHtml .= '</div></div>';

                if ($i < 6) {
                    $lineClass = ($stepsData[$i]['status'] === 'completed') ? 'completed' : '';
                    if ($stepsData[$i]['status'] === 'completed' && $stepsData[$i+1]['status'] === 'active') {
                        $lineClass = 'active';
                    }
                    $stepperHtml .= '<div class="step-line-v2 ' . $lineClass . '"></div>';
                }
            }
            $stepperHtml .= '</div></div>';
            
            $html = str_replace('<!-- STEPPER_HTML -->', $stepperHtml, $html);
            
            $html = str_replace('{{ sample_type }}', $sampleType, $html);

            $detailRows = '';
            $exemptionRows = '';
            
            $isPengecualian = ($f3->get('GET.source') === 'pengecualian')
                || (!empty($orderData['uji_pengecualian']) && $orderData['uji_pengecualian'] === 'Y');

            // usedPengecualianTable hanya true jika lookup lokal BENAR-BENAR berhasil.
            // Order SIL dengan uji_pengecualian='Y' memiliki id_arsip yang BERBEDA
            // dengan pengecualian_permintaan.id, sehingga akan fall-through ke SIL parser.
            $usedPengecualianTable = false;

            if ($isPengecualian) {
                // ── Coba ambil dari local pengecualian_items berdasarkan orderId ──
                $userId = $f3->get('SESSION.user_id');
                $allItems = [];

                if ($orderId) {
                    try {
                        $checkPermintaan = $this->db->exec(
                            'SELECT id FROM pengecualian_permintaan WHERE id = ? AND user_id = ?',
                            [$orderId, $userId]
                        );
                        if (!empty($checkPermintaan)) {
                            $allItems = $this->db->exec(
                                'SELECT * FROM pengecualian_items WHERE permintaan_id = ? ORDER BY id ASC',
                                [$orderId]
                            ) ?: [];
                        }
                    } catch (\Exception $e) {}
                }

                // Hanya gunakan tabel lokal jika lookup langsung berhasil.
                // Jika tidak cocok (order SIL), biarkan fall-through ke SIL parser di bawah.
                if (!empty($allItems)) {
                    $usedPengecualianTable = true;
                    $no = 1;
                    foreach ($allItems as $item) {
                        $detailRows .= '<tr>';
                        $detailRows .= '<td class="text-center" style="padding: 10px 8px; font-weight: 600; color: #1e293b;">' . $no++ . '</td>';
                        $detailRows .= '<td style="padding: 10px 8px;">' . htmlspecialchars($item['hs_code'] ?? '-') . '</td>';
                        $detailRows .= '<td style="padding: 10px 8px;">' . htmlspecialchars($item['jenis_kertas'] ?? '-') . '</td>';
                        $detailRows .= '<td style="padding: 10px 8px;">' . htmlspecialchars($item['varian_gramatur'] ?? '-') . '</td>';
                        $detailRows .= '<td style="padding: 10px 8px;">' . htmlspecialchars($item['spesifikasi'] ?? '-') . '</td>';
                        $detailRows .= '<td style="padding: 10px 8px;">' . htmlspecialchars($item['merk'] ?? '-') . '</td>';
                        $detailRows .= '<td style="padding: 10px 8px;">' . htmlspecialchars($item['negara_asal'] ?? '-') . '</td>';
                        $detailRows .= '<td style="padding: 10px 8px;">' . htmlspecialchars($item['nama_pabrik'] ?? '-') . '</td>';
                        $detailRows .= '</tr>';
                    }
                }
                // Jika $allItems kosong → $usedPengecualianTable tetap false
                // → SIL parser di bawah akan menangani dengan getRincianContoh()
            }

            if (!$usedPengecualianTable) {
                // SIL: Ambil rincian contoh dari tb_identitas_contoh_dump
                if ($orderId && $this->silService) {
                    try {
                        $contohData = $this->silService->getRincianContoh($orderId);
                        $no = 1;
                        foreach ($contohData as $row) {
                            $nomorContoh = $row['nomor_contoh'] ?? '-';

                            $detailRows .= '<tr>';
                            $detailRows .= '<td class="text-center" style="padding: 10px 8px; font-weight: 600; color: #1e293b;">' . $no++ . '</td>';
                            $detailRows .= '<td style="padding: 10px 8px; font-weight: 600;">' . htmlspecialchars($nomorContoh) . '</td>';

                            if ($isPengecualian) {
                                // Pengujian pengecualian via SIL: tampilkan kolom detail
                                $hsCode      = trim($row['hscode']         ?? '') ?: '-';
                                $jenisKertas = trim($row['jenis_kertas']   ?? '') ?: '-';
                                $gramatur    = trim($row['varian_gramatur'] ?? '') ?: '-';
                                $merk        = trim($row['merk']           ?? '') ?: '-';
                                $negaraAsal  = trim($row['negara_asal']    ?? '') ?: '-';
                                $pabrik      = html_entity_decode(trim($row['pabrik_pembuat'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8') ?: '-';
                                $detailRows .= '<td style="padding: 10px 8px;">' . htmlspecialchars($hsCode) . '</td>';
                                $detailRows .= '<td style="padding: 10px 8px;">' . htmlspecialchars($jenisKertas) . '</td>';
                                $detailRows .= '<td style="padding: 10px 8px;">' . htmlspecialchars($gramatur) . '</td>';
                                $detailRows .= '<td style="padding: 10px 8px;">' . htmlspecialchars($merk) . '</td>';
                                $detailRows .= '<td style="padding: 10px 8px;">' . htmlspecialchars($negaraAsal) . '</td>';
                                $detailRows .= '<td style="padding: 10px 8px;">' . htmlspecialchars($pabrik) . '</td>';
                            } else {
                                // Pengujian biasa: hanya tampilkan uraian/keterangan
                                $uraian = trim($row['keterangan'] ?? '') ?: '-';
                                $detailRows .= '<td style="padding: 10px 8px;">' . htmlspecialchars($uraian) . '</td>';
                            }

                            $detailRows .= '</tr>';
                        }
                    } catch (\Exception $e) {}
                }

                if ($isPengecualian) {
                    // Header tabel pengujian pengecualian via SIL
                    $rincianHeader = '<th style="min-width:40px">No.</th>
                        <th style="min-width:100px">Nomor Contoh</th>
                        <th style="min-width:120px">HS Code</th>
                        <th style="min-width:130px">Jenis Kertas</th>
                        <th style="min-width:120px">Varian Gramatur</th>
                        <th style="min-width:120px">Merk</th>
                        <th style="min-width:110px">Negara Asal</th>
                        <th style="min-width:200px">Pabrik Pembuat</th>';
                } else {
                    // Header tabel pengujian biasa: hanya 3 kolom
                    $rincianHeader = '<th style="min-width:40px">No.</th>
                        <th style="min-width:120px">Nomor Contoh</th>
                        <th style="min-width:300px">Uraian</th>';
                }
            } else {
                // usedPengecualianTable = true: pengecualian dari tabel lokal (pengecualian_items)
                $rincianHeader = '<th style="min-width:50px">No.</th>
                    <th style="min-width:110px">HS Code</th>
                    <th style="min-width:130px">Jenis Kertas</th>
                    <th style="min-width:130px">Varian Gramatur</th>
                    <th style="min-width:160px">Spesifikasi Pengecualian</th>
                    <th style="min-width:120px">Merk</th>
                    <th style="min-width:120px">Negara Asal</th>
                    <th style="min-width:140px">Pabrik Pembuat</th>';
            }



            
            $tglAnalisis = '-';
            $tglTerbitLhu = '-';

            $displayNoOrder = '-';
            if ($orderData && isset($orderData['No_order']) && !empty($orderData['No_order'])) {
                $displayNoOrder = $orderData['No_order'];
            } elseif ($orderData && isset($orderData['no_permintaan'])) {
                $displayNoOrder = $orderData['no_permintaan'];
            }

            $noSurat = $orderData['no_surat'] ?? '-';

            $html = str_replace('{{ detail_contoh }}', $detailContoh, $html);
            $html = str_replace('<!-- RINCIAN_CONTOH_HEADER -->', $rincianHeader ?? '<th width="20%">Nomor Contoh</th><th width="80%">Uraian</th>', $html);
            $html = str_replace('<!-- ORDER_DETAILS_ROWS -->', $detailRows, $html);
            
            $parameterRows = '';
            $totalBiaya = 0;
            $paramResult = ['rows' => [], 'total' => 0, 'multiplier' => 1.0];
            $jenisPengujianValue = $orderData['jenis_pengujian'] ?? '';
            if ($orderId && $this->silService) {
                try {
                    $paramResult = $this->silService->getParameterPengujian($orderId, $jenisPengujianValue);
                    $totalBiaya = $paramResult['total'];
                    $multiplier = $paramResult['multiplier'] ?? 1.0;

                    if (!empty($paramResult['rows'])) {
                        $noParam = 1;
                        foreach ($paramResult['rows'] as $p) {
                            $paramName = $p['uraian_parameter'];
                            $tarifFmt = 'Rp. ' . number_format($p['tarif'], 0, ',', '.');
                            $biayaFmt = 'Rp. ' . number_format($p['biaya'], 0, ',', '.');

                            // Tampilkan tarif normal jika ada multiplier
                            $tarifDisplay = $tarifFmt;
                            if ($multiplier != 1.0 && isset($p['tarif_normal']) && $p['tarif_normal'] != $p['tarif']) {
                                $tarifNormalFmt = 'Rp. ' . number_format($p['tarif_normal'], 0, ',', '.');
                                $tarifDisplay = "<span style='color:#2C7BE5;font-weight:700;'>{$tarifFmt}</span><br><small style='color:#94a3b8;text-decoration:line-through;font-size:11px;'>{$tarifNormalFmt}</small>";
                            }

                            $parameterRows .= "<tr>
                                <td>{$noParam}.</td>
                                <td class='param-name'>{$paramName}</td>
                                <td>{$p['jumlah']}</td>
                                <td>{$tarifDisplay}</td>
                                <td>{$biayaFmt}</td>
                            </tr>";
                            
                            // Render sub-parameters if exist
                            if (!empty($p['sub_params'])) {
                                $noSubParam = 1;
                                foreach ($p['sub_params'] as $subParam) {
                                    $subParamName = $subParam;
                                    // Use style to indent and distinguish from main parameter
                                    $parameterRows .= "<tr>
                                        <td></td>
                                        <td class='param-name' style='padding-left: 30px;'>{$noSubParam}. {$subParamName}</td>
                                        <td></td>
                                        <td></td>
                                        <td></td>
                                    </tr>";
                                    $noSubParam++;
                                }
                            }
                            $noParam++;
                        }
                    }
                } catch (\Exception $e) {
                }
            }

            if (empty($parameterRows)) {
                $parameterRows = '<tr><td colspan="5" style="padding: 24px; text-align: center; color: #94a3b8;">Data parameter pengujian belum tersedia.</td></tr>';
            }

            $totalBiayaFmt = 'Rp. ' . number_format($totalBiaya, 0, ',', '.');
            $html = str_replace('<!-- PARAMETER_ROWS -->', $parameterRows, $html);
            $html = str_replace('<!-- PARAMETER_TOTAL -->', $totalBiayaFmt, $html);

            $html = str_replace('{{ no_permintaan }}', $displayNoOrder, $html);
            $html = str_replace('{{ no_surat }}', $noSurat, $html);
            
            $html = str_replace('{{ order_id }}', $displayOrderId, $html);
            $html = str_replace('{{ order_id_raw }}', $orderId, $html); // Raw ID for kuesioner link
            $html = str_replace('{{ status_bayar }}', $orderData['status_bayar'] ?? '-', $html);
            $statusLabel = $tracking['status_label'];
            $html = str_replace('{{ keterangan_proses }}', $statusLabel, $html);
            $TglSelesai = !empty($orderData['Tgl_selesai_LHU']) ? date('d-m-Y', strtotime($orderData['Tgl_selesai_LHU'])) : '-';
            $html = str_replace('{{ tgl_selesai_lhu }}', $TglSelesai, $html);

            $TglSelesai_plus3 = '-';
            $baseDate = null;
            if (!empty($orderData['tanggal_terima_lhu']) && $orderData['tanggal_terima_lhu'] !== '0000-00-00') {
                $baseDate = $orderData['tanggal_terima_lhu'];
            } elseif (!empty($orderData['Tgl_selesai_LHU_raw']) && $orderData['Tgl_selesai_LHU_raw'] !== '0000-00-00') {
                $baseDate = $orderData['Tgl_selesai_LHU_raw'];
            }

            if ($baseDate) {
                $hariLibur = [];
                if ($this->silService) {
                    try {
                        $hariLibur = $this->silService->getHariLibur();
                    } catch (\Exception $e) {
                    }
                }

                $currentDate = new \DateTime($baseDate);
                $addedDays = 0;
                while ($addedDays < 3) {
                    $currentDate->modify('+1 day');
                    $dayOfWeek = (int)$currentDate->format('N'); // 6=Sabtu, 7=Minggu
                    $dateStr = $currentDate->format('Y-m-d');

                    if ($dayOfWeek >= 6 || in_array($dateStr, $hariLibur)) {
                        continue;
                    }
                    $addedDays++;
                }
                $TglSelesai_plus3 = $currentDate->format('d-m-Y');
            }
            $html = str_replace('{{ tgl_selesai_lhu_plus3 }}', $TglSelesai_plus3, $html);
            
            $html = str_replace('{{ tgl_terima }}', $orderData['Tanggal_order'] ?? '-', $html);
            $html = str_replace('{{ banyak_contoh }}', $orderData['Banyak_contoh'] ?? '-', $html);
            $html = str_replace('{{ jumlah_lhu }}', $orderData['jumlah_lhu'] ?? '-', $html);
            $html = str_replace('{{ jenis_pengujian }}', htmlspecialchars($orderData['jenis_pengujian'] ?? '-'), $html);
            $biayaInfo = $this->silService
                ? $this->silService->getBiayaData($orderData ?: [])
                : ['items' => [['label' => 'Biaya Pengujian', 'value' => 0]], 'total' => 0];
            $fmt = function($v) { return 'Rp. ' . number_format($v, 0, ',', '.'); };
            $biayaHtml = '';
            foreach ($biayaInfo['items'] as $item) {
                $biayaHtml .= "<div class='info-row-modern'>
                    <span class='info-label-modern'>{$item['label']}</span>
                    <span class='info-value-modern'>" . $fmt($item['value']) . "</span>
                </div>";
            }
            $biayaHtml .= "<div class='info-row-modern' style='border-top: 2px solid #f1f5f9; margin-top: 8px;'>
                <span class='info-label-modern' style='color: #0f172a;'>Total Biaya</span>
                <span class='info-value-modern' style='color: #2C7BE5; font-size: 18px; font-weight: 800;'>" . $fmt($biayaInfo['total']) . "</span>
            </div>";

            $html = str_replace('<!-- BIAYA_ROWS -->', $biayaHtml, $html);
            $html = str_replace('{{ tgl_analisis }}', $tglAnalisis, $html);
            $html = str_replace('11 Januari 2026', $tglTerbitLhu, $html); // Replace dummy date in LHU section
            
            $bahasaLhu = 'Indonesia'; // Default fallback
            if ($orderData) {
                $isIndo = (!empty($orderData['indonesia']) && $orderData['indonesia'] === 'Iya');
                $isIng = (!empty($orderData['inggris']) && $orderData['inggris'] === 'Iya');
                
                if ($isIndo && $isIng) {
                    $bahasaLhu = 'Indonesia & Inggris';
                } elseif ($isIng) {
                    $bahasaLhu = 'Inggris';
                } elseif ($isIndo) {
                    $bahasaLhu = 'Indonesia';
                }
            }
            $html = str_replace('{{ bahasa_lhu }}', $bahasaLhu, $html);
            
            $sumberSampel = !empty($orderData['sumber_sampel']) ? $orderData['sumber_sampel'] : 'Kiriman Pelanggan';
            $html = str_replace('{{ sumber_sampel }}', $sumberSampel, $html);

            $sumberSampelRaw = htmlspecialchars($orderData['sumber_sampel'] ?? '');
            $html = str_replace('id="current-sample-type" value="Kertas"', 'id="current-sample-type" value="' . htmlspecialchars($sampleType) . '"', $html);
            $html = str_replace('id="current-sumber-sampel" value=""', 'id="current-sumber-sampel" value="' . $sumberSampelRaw . '"', $html);

            $bapcData = ['no_sp' => '-', 'tanggal_sp' => '-', 'petugas' => '-', 'prosedur' => '-'];
            if ($orderId && $this->silService) {
                try {
                    $bapcData = $this->silService->getBapcData($orderId, $orderData['No_order'] ?? '');
                } catch (\Exception $e) {
                }
            }

            $html = str_replace('{{ bapc_no_sp }}', htmlspecialchars($bapcData['no_sp']), $html);
            $html = str_replace('{{ bapc_tanggal_sp }}', htmlspecialchars($bapcData['tanggal_sp']), $html);
            $html = str_replace('{{ bapc_petugas }}', htmlspecialchars($bapcData['petugas']), $html);
            $html = str_replace('{{ bapc_prosedur }}', htmlspecialchars($bapcData['prosedur']), $html);

            // ── BAPC: bangun URL dari nama_berkas_bapc (field di tb_penerimaan_contoh) ──
            $bapcNamaFile = $bapcData['nama_berkas_bapc'] ?? null;
            if (!empty($bapcNamaFile)) {
                $f3 = \Base::instance();
                $bapcType = $isPengecualian ? 'pengecualian-bapc' : 'bapc';
                $token = doc_make_token($bapcNamaFile, $bapcType);
                $bapcFileUrl = $f3->get('BASE') . '/dokumen/view/' . $token;
                $bapcStatusHtml = '
                            <div class="doc-list-status">
                                <span class="doc-status-simple available" style="text-transform: none !important; font-size: 14px;">Tersedia</span>
                            </div>
                            <div class="doc-list-action">
                                <a href="' . htmlspecialchars($bapcFileUrl) . '" class="link-text-action" target="_blank">Unduh</a>
                            </div>';
            } else {
                $bapcStatusHtml = '
                            <div class="doc-list-status">
                                <span class="doc-status-simple" style="color:#64748b; font-weight:500; text-transform: none !important; font-size: 14px;">Belum Tersedia</span>
                            </div>
                            <div class="doc-list-action">
                                <span class="link-text-action" style="color:#cbd5e1; cursor:not-allowed;">Unduh</span>
                            </div>';
            }

            $f3 = \Base::instance();
            $lhuNamaFile = $bapcData['nama_berkas_lhu_dengan_hal'] ?? null;
            $lhuStatusHtml = '';

            if (!empty($lhuNamaFile)) {
                $lhuType = $isPengecualian ? 'pengecualian-lhu' : 'lhu';
                $token = doc_make_token($lhuNamaFile, $lhuType);
                $lhuUrl = $f3->get('BASE') . '/dokumen/view/' . $token;

                $lhuStatusHtml = '
                        <div class="document-list-item">
                            <div class="doc-list-info">
                                <div class="doc-list-text">
                                    <span class="doc-list-title">LHU</span>
                                    <span class="doc-list-desc">Laporan Hasil Uji</span>
                                </div>
                            </div>
                            <div class="doc-list-status">
                                <span class="doc-status-simple pending" id="tab-list-lhu-status" style="text-transform: none !important; font-size: 14px;">Wajib kuesioner</span>
                            </div>
                            <div class="doc-list-action">
                                <div class="doc-list-actions-group" id="tab-list-lhu-actions">
                                    <a href="javascript:void(0)" class="link-text-action secondary"
                                        onclick="loadSection(\'kuesioner\', \'{{ sample_type }}\', \'{{ order_id_raw }}\')">
                                        Isi Kuesioner
                                    </a>
                                    <a href="'.htmlspecialchars($lhuUrl).'" class="link-text-action"
                                        id="tab-btn-list-unduh-lhu" target="_blank" style="display: none;">
                                        Unduh
                                    </a>
                                </div>
                            </div>
                        </div>';
            } else {
                $lhuStatusHtml = '
                        <div class="document-list-item">
                            <div class="doc-list-info">
                                <div class="doc-list-text">
                                    <span class="doc-list-title">LHU</span>
                                    <span class="doc-list-desc">Laporan Hasil Uji</span>
                                </div>
                            </div>
                            <div class="doc-list-status">
                                <span class="doc-status-simple" style="color:#64748b; font-weight:500; text-transform: none !important; font-size: 14px;">Belum tersedia</span>
                            </div>
                            <div class="doc-list-action">
                                <span class="link-text-action" style="color:#cbd5e1; cursor:not-allowed;">Unduh</span>
                            </div>
                        </div>';
            }

            // Fetch Invoice files from tb_arsip_detail (sil2020)
            $invoicesHtml = '';
            if ($orderId && $this->dbSil) {
                try {
                    $invRecord = $this->dbSil->exec(
                        "SELECT pembayaran_nama_berkas_pembayaran, pembayaran_nama_berkas_pembayaran_2, pembayaran_nama_berkas_pembayaran_3 FROM tb_arsip_detail WHERE id_arsip = ? LIMIT 1",
                        [$orderId]
                    );
                    if (!empty($invRecord)) {
                        $invoiceType = $isPengecualian ? 'pengecualian-invoice' : 'invoice';
                        $invoiceFields = [
                            ['field' => 'pembayaran_nama_berkas_pembayaran', 'label' => 'Invoice', 'desc' => 'Rincian Tagihan'],
                            ['field' => 'pembayaran_nama_berkas_pembayaran_2', 'label' => 'Invoice 2', 'desc' => 'Rincian Tagihan 2'],
                            ['field' => 'pembayaran_nama_berkas_pembayaran_3', 'label' => 'Invoice 3', 'desc' => 'Rincian Tagihan 3'],
                        ];
                        foreach ($invoiceFields as $inv) {
                            $fileName = $invRecord[0][$inv['field']] ?? null;
                            if (!empty($fileName) && trim($fileName) !== '') {
                                $token = doc_make_token(trim($fileName), $invoiceType);
                                $invUrl = $f3->get('BASE') . '/dokumen/view/' . $token;
                                $invoicesHtml .= '
                        <div class="document-list-item">
                            <div class="doc-list-info">
                                <div class="doc-list-text">
                                    <span class="doc-list-title">'.$inv['label'].'</span>
                                    <span class="doc-list-desc">'.$inv['desc'].'</span>
                                </div>
                            </div>
                            <div class="doc-list-status">
                                <span class="doc-status-simple available" style="text-transform: none !important; font-size: 14px;">Tersedia</span>
                            </div>
                            <div class="doc-list-action">
                                <a href="'.htmlspecialchars($invUrl).'" class="link-text-action" target="_blank">Unduh</a>
                            </div>
                        </div>';
                            }
                        }
                    }
                } catch (\Exception $e) {}
            }
            // Jika tidak ada invoice sama sekali, tampilkan satu item "Belum tersedia"
            if (empty($invoicesHtml)) {
                $invoicesHtml = '
                        <div class="document-list-item">
                            <div class="doc-list-info">
                                <div class="doc-list-text">
                                    <span class="doc-list-title">Invoice</span>
                                    <span class="doc-list-desc">Rincian Tagihan</span>
                                </div>
                            </div>
                            <div class="doc-list-status">
                                <span class="doc-status-simple" style="color:#64748b; font-weight:500; text-transform: none !important; font-size: 14px;">Belum tersedia</span>
                            </div>
                            <div class="doc-list-action">
                                <span class="link-text-action" style="color:#cbd5e1; cursor:not-allowed;">Unduh</span>
                            </div>
                        </div>';
            }

            // Fetch Surat Penawaran from surat_penawaran table
            $suratPenawaranStatusHtml = '
                            <div class="doc-list-status">
                                <span class="doc-status-simple" style="color:#64748b; font-weight:500; text-transform: none !important; font-size: 14px;">Belum tersedia</span>
                            </div>
                            <div class="doc-list-action">
                                <span class="link-text-action" style="color:#cbd5e1; cursor:not-allowed;">Unduh</span>
                            </div>';

            if ($displayNoOrder !== '-') {
                try {
                    $spRecord = $this->db->exec("SELECT file_name FROM surat_penawaran WHERE order_no = ? LIMIT 1", [$displayNoOrder]);
                    if (!empty($spRecord) && !empty($spRecord[0]['file_name'])) {
                        $spType = $isPengecualian ? 'pengecualian-surat-penawaran' : 'surat-penawaran';
                        $token = doc_make_token((string)$spRecord[0]['file_name'], $spType);
                        $spUrl = $f3->get('BASE') . '/dokumen/view/' . $token;
                        $suratPenawaranStatusHtml = '
                            <div class="doc-list-status">
                                <span class="doc-status-simple available" style="text-transform: none !important; font-size: 14px;">Tersedia</span>
                            </div>
                            <div class="doc-list-action">
                                <a href="'.$spUrl.'" class="link-text-action" target="_blank">Unduh</a>
                            </div>';
                    }
                } catch (\Exception $e) {
                }
            }
            
            $html = str_replace('<!-- INVOICES_HTML -->', $invoicesHtml, $html);
            $html = str_replace('<!-- SURAT_PENAWARAN_STATUS_HTML -->', $suratPenawaranStatusHtml, $html);
            $html = str_replace('<!-- BAPC_STATUS_HTML -->', $bapcStatusHtml, $html);
            
            $dokPengecualianHtml = '';
            if ($isPengecualian) {
                $currentUserId = (int) $f3->get('SESSION.user_id');

                $idPengecualianDok = (int) ($orderData['id_pengecualian_dokumen'] ?? 0);

                $berkasLinks = [];
                $dokFound    = false;

                if ($idPengecualianDok > 0) {
                    try {
                        $dokRec = $this->db->exec(
                            "SELECT id, user_id, file_name, nama_file_1, nama_file_2, nama_file_3, nama_file_4, nama_file_5, status
                             FROM pengecualian_dokumen
                             WHERE id = ? LIMIT 1",
                            [$idPengecualianDok]
                        );

                        if (!empty($dokRec) && (int)$dokRec[0]['user_id'] === $currentUserId) {
                            $dok     = $dokRec[0];
                            $dokFound = true;

                            // File utama (dokumen permohonan)
                            if (!empty($dok['file_name'])) {
                                $token = doc_make_token((string)$dok['file_name'], 'pengecualian-berkas');
                                $berkasLinks[] = [
                                    'label' => 'Dokumen Permohonan',
                                    'url'   => $f3->get('BASE') . '/dokumen/view/' . $token
                                ];
                            }

                            // Berkas revisi 1–5
                            for ($ri = 1; $ri <= 5; $ri++) {
                                $namaFile = $dok["nama_file_{$ri}"] ?? '';
                                if (!empty(trim($namaFile))) {
                                    $token = doc_make_token((string)trim($namaFile), 'pengecualian-berkas');
                                    $berkasLinks[] = [
                                        'label' => "Berkas Revisi {$ri}",
                                        'url'   => $f3->get('BASE') . '/dokumen/view/' . $token
                                    ];
                                }
                            }
                        }
                    } catch (\Exception $e) {
                        error_log("[DokumenPengecualian ERROR] " . $e->getMessage());
                    }
                }

                // Jika tidak ada lewat id_pengecualian_dokumen, fallback ke cara lama (permintaan_ids)
                if (!$dokFound && $orderId) {
                    try {
                        $dokumenLama = $this->db->exec(
                            "SELECT file_name, status FROM pengecualian_dokumen WHERE user_id = ? AND (FIND_IN_SET(?, permintaan_ids) OR permintaan_ids = ?) LIMIT 1",
                            [$currentUserId, $orderId, $orderId]
                        );
                        if (!empty($dokumenLama) && !empty($dokumenLama[0]['file_name'])) {
                            $token = doc_make_token((string)$dokumenLama[0]['file_name'], 'pengecualian-berkas');
                            $berkasLinks[] = [
                                'label' => 'Dokumen Permohonan',
                                'url'   => $f3->get('BASE') . '/dokumen/view/' . $token
                            ];
                            $dokFound = true;
                        }
                    } catch (\Exception $e) {}
                }

                // Build HTML
                if ($dokFound && !empty($berkasLinks)) {
                    if (!$isKuesionerDone) {
                        // Wajib isi kuesioner dulu
                        $dokPengecualianHtml = '
                        <div class="document-list-item">
                            <div class="doc-list-info">
                                <div class="doc-list-text">
                                    <span class="doc-list-title">Dokumen Pengecualian</span>
                                    <span class="doc-list-desc">Permohonan Pengujian Pengecualian</span>
                                </div>
                            </div>
                            <div class="doc-list-status">
                                <span style="color:#d97706; font-weight:500; font-size:14px;">Wajib kuesioner</span>
                            </div>
                            <div class="doc-list-action">
                                <a href="javascript:void(0)" onclick="loadSection(\'kuesioner\', \'Pengecualian\', \''.htmlspecialchars((string)$orderId).'\')" class="link-text-action">Isi Kuesioner</a>
                            </div>
                        </div>';
                    } else {
                        // Tampilkan semua berkas yang tersedia
                        $dokPengecualianHtml = '';
                        foreach ($berkasLinks as $berkas) {
                            $dokPengecualianHtml .= '
                            <div class="document-list-item">
                                <div class="doc-list-info">
                                    <div class="doc-list-text">
                                        <span class="doc-list-title">Dokumen Pengecualian</span>
                                        <span class="doc-list-desc">' . htmlspecialchars($berkas['label']) . '</span>
                                    </div>
                                </div>
                                <div class="doc-list-status">
                                    <span class="doc-status-simple available" style="text-transform: none !important; font-size: 14px;">Tersedia</span>
                                </div>
                                <div class="doc-list-action">
                                    <a href="' . htmlspecialchars($berkas['url']) . '" class="link-text-action" target="_blank">Unduh</a>
                                </div>
                            </div>';
                        }
                    }
                } elseif ($isPengecualian) {
                    // Dokumen belum tersedia
                    $dokPengecualianHtml = '
                    <div class="document-list-item">
                        <div class="doc-list-info">
                            <div class="doc-list-text">
                                <span class="doc-list-title">Dokumen Pengecualian</span>
                                <span class="doc-list-desc">Permohonan Pengujian Pengecualian</span>
                            </div>
                        </div>
                        <div class="doc-list-status">
                            <span style="color:#64748b; font-weight:500; font-size:14px;">Belum tersedia</span>
                        </div>
                        <div class="doc-list-action">
                            <span class="link-text-action" style="color:#cbd5e1; cursor:not-allowed;">Unduh</span>
                        </div>
                    </div>';
                }
            }
            $html = str_replace('<!-- DOK_PENGECUALIAN_HTML -->', $dokPengecualianHtml, $html);
            
            $html = str_replace('<!-- LHU_STATUS_HTML -->', $lhuStatusHtml, $html);

            // Fetch No Resi & Nama Ekspedisi from pengiriman_resi table
            $noResi = '-';
            $namaEkspedisi = 'JNE';
            if ($displayNoOrder !== '-') {
                try {
                    $resiRecord = $this->db->exec(
                        "SELECT no_resi, nama_ekspedisi FROM pengiriman_resi WHERE order_no = ? LIMIT 1",
                        [$displayNoOrder]
                    );
                    if (!empty($resiRecord)) {
                        $noResi = $resiRecord[0]['no_resi'] ?? '-';
                        $namaEkspedisi = $resiRecord[0]['nama_ekspedisi'] ?? '-';
                    }
                } catch (\Exception $e) {
                    // Table may not exist yet; silently ignore
                }
            }
            $html = str_replace('{{ no_resi }}', htmlspecialchars($noResi), $html);
            $html = str_replace('{{ nama_ekspedisi }}', htmlspecialchars($namaEkspedisi), $html);

            if ($section === 'kuesioner') {
                $status_responden = 'Perorangan';
                if (!empty($user->company_id)) {
                    $status_responden = 'Perusahaan Industri';
                }
                $html = str_replace('{{ status_responden }}', htmlspecialchars($status_responden), $html);

                $jenisPelayananKuesioner = 'Pengujian';
                $sampleParamLower = strtolower($sampleTypeParam);
                if ($sampleParamLower === 'sertifikasi') {
                    $jenisPelayananKuesioner = 'Sertifikasi';
                } elseif ($sampleParamLower === 'kalibrasi') {
                    $jenisPelayananKuesioner = 'Kalibrasi';
                } elseif ($sampleParamLower === 'pengecualian') {
                    $jenisPelayananKuesioner = 'Pengujian Pengecualian';
                }
                $html = str_replace('{{ jenis_pelayanan_otomatis }}', htmlspecialchars($jenisPelayananKuesioner), $html);

                // Pastikan order_id_raw & sample_type ter-replace di form kuesioner
                $html = str_replace('{{ order_id_raw }}', htmlspecialchars($orderId ?? ''), $html);
                $html = str_replace('{{ sample_type }}', htmlspecialchars($sampleTypeParam ?? ''), $html);
            }
        }

        $companyName = '';
        $companyEmail = '';
        $companyAddress = '';
        $companyTelp = '-';
        $companyWa = '-';
        $companyFax = '-';
        $companyLokasi = '';
        $companyKodepos = '';
        $companyProvinsi = '';
        $companyKabupaten = '';
        $companyKecamatan = '';

        if (!empty($user->company_id)) {
            $company = new DB\SQL\Mapper($this->db, 'companies');
            $company->load(['id = ?', $user->company_id]);
            if (!$company->dry()) {
                $companyName = $company->nama_perusahaan;
                $companyEmail = $company->email_perusahaan;
                $companyAddress = $company->alamat_perusahaan;
                $companyTelp = $company->telp_perusahaan;
                $companyWa = $company->wa_perusahaan;
                $companyFax = $company->fax_perusahaan;
                $companyKodepos = $company->kodepos_perusahaan;
                $companyProvinsi = $company->provinsi_perusahaan;
                $companyKabupaten = $company->kabupaten_perusahaan;
                $companyKecamatan = $company->kecamatan_perusahaan;
                
                $locArr = array_filter([
                    $companyProvinsi,
                    $companyKabupaten,
                    $companyKecamatan
                ]);
                $companyLokasi = implode(', ', $locArr);
            }
        }

        // Fallback for Personal / Newly Registered Accounts
        if (empty($companyName)) {
            $companyName = $user->nama_lengkap ?: ($f3->get('SESSION.nama_lengkap') ?: ($user->username ?: 'Pelanggan'));
        }
        if (empty($companyEmail)) $companyEmail = $user->email;
        if (empty($companyAddress)) $companyAddress = $user->alamat_tinggal;
        if (empty($companyKodepos)) $companyKodepos = $user->kodepos_tinggal;
        
        if (empty($companyLokasi)) {
            $locArr = array_filter([
                $user->provinsi_tinggal ?? '',
                $user->kabupaten_tinggal ?? '',
                $user->kecamatan_tinggal ?? ''
            ]);
            $companyLokasi = implode(', ', $locArr);
        }

        $isMahasiswa = strtolower(trim($user->kategori_pelanggan ?? '')) === 'perorangan';

        if ($companyTelp === '-') {
             $companyTelp = $isMahasiswa ? '-' : $user->no_whatsapp;
        }
        if ($companyWa === '-') {
             $companyWa = $isMahasiswa ? '-' : $user->no_whatsapp;
        }
        
        if (empty($companyProvinsi)) $companyProvinsi = $user->provinsi_tinggal;
        if (empty($companyKabupaten)) $companyKabupaten = $user->kabupaten_tinggal;
        if (empty($companyKecamatan)) $companyKecamatan = $user->kecamatan_tinggal;

        $html = str_replace('{{ BASE }}', $f3->get('BASE'), $html);
        $html = str_replace('<!-- CSRF_TOKEN -->', $f3->get('SESSION.csrf_token') ?? '', $html);
        $html = str_replace('{{ current_date }}', date('d M Y'), $html);
        $nama_lengkap = $user->nama_lengkap;
        if (empty($nama_lengkap)) {
            $nama_lengkap = $f3->get('SESSION.nama_lengkap');
        }
        if (empty($nama_lengkap)) {
            $nama_lengkap = $user->username; // Fallback to username
        }
        $html = str_replace('{{ nama_lengkap }}', htmlspecialchars($nama_lengkap ?? 'Pelanggan'), $html);
        $html = preg_replace('/\{\{\s*nama_lengkap\s*\}\}/', htmlspecialchars($nama_lengkap ?? 'Pelanggan'), $html);
        $html = str_replace('{{ username }}', htmlspecialchars($user->username ?? ''), $html);
        $html = str_replace('{{ tanggal_lahir }}', htmlspecialchars($user->tanggal_lahir ?? ''), $html);

        $html = str_replace('{{ jenis_kelamin }}', htmlspecialchars($user->jenis_kelamin ?? ''), $html);
        $html = str_replace('{{ alamat_tinggal }}', htmlspecialchars($user->alamat_tinggal ?? ''), $html);
        $html = str_replace('{{ no_whatsapp }}', htmlspecialchars($user->no_whatsapp ?: '-'), $html);
        $html = str_replace('{{ email }}', htmlspecialchars($user->email ?? ''), $html);
        $html = str_replace('{{ jabatan }}', htmlspecialchars($user->jabatan ?? ''), $html);
        $html = str_replace('{{ provinsi_tinggal }}', htmlspecialchars($user->provinsi_tinggal ?? ''), $html);
        $html = str_replace('{{ kabupaten_tinggal }}', htmlspecialchars($user->kabupaten_tinggal ?? ''), $html);
        $html = str_replace('{{ kecamatan_tinggal }}', htmlspecialchars($user->kecamatan_tinggal ?? ''), $html);
        $html = str_replace('{{ kodepos_tinggal }}', htmlspecialchars($user->kodepos_tinggal ?? ''), $html);
        
        $profilePhotoUrl = $f3->get('BASE') . '/public/images/default-avatar.png';
        if (!empty($user->profile_photo) && is_string($user->profile_photo) && strpos($user->profile_photo, '.') !== false) {
            $profilePhotoUrl = $f3->get('BASE') . '/public/uploads/profile/' . $user->profile_photo;
        } elseif (!empty($user->profile_photo)) {
            // Legacy BLOB - still use PHP route
            $profilePhotoUrl = $f3->get('BASE') . '/image/profile/' . $user->id;
        }
        $html = str_replace('{{ profile_photo_url }}', $profilePhotoUrl, $html);
        
        $html = str_replace('{{ nama_perusahaan }}', htmlspecialchars($companyName ?: '-'), $html);
        $html = str_replace('{{ email_perusahaan }}', htmlspecialchars($companyEmail ?: '-'), $html);
        $html = str_replace('{{ alamat_perusahaan }}', htmlspecialchars($companyAddress ?: '-'), $html);
        $html = str_replace('{{ telp_perusahaan }}', htmlspecialchars($companyTelp ?: '-'), $html);
        $html = str_replace('{{ wa_perusahaan }}', htmlspecialchars($companyWa ?: '-'), $html);
        $html = str_replace('{{ fax_perusahaan }}', htmlspecialchars($companyFax ?: '-'), $html);
        $html = str_replace('{{ lokasi_perusahaan }}', htmlspecialchars($companyLokasi ?: ''), $html);
        $html = str_replace('{{ kodepos_perusahaan }}', htmlspecialchars($companyKodepos ?: ''), $html);
        $html = str_replace('{{ provinsi_perusahaan }}', htmlspecialchars($companyProvinsi ?? ''), $html);
        $html = str_replace('{{ kabupaten_perusahaan }}', htmlspecialchars($companyKabupaten ?? ''), $html);
        $html = str_replace('{{ kecamatan_perusahaan }}', htmlspecialchars($companyKecamatan ?? ''), $html);

        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => true, 'html' => $html], JSON_INVALID_UTF8_SUBSTITUTE);

        } catch (\Exception $e) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => false, 'message' => 'Terjadi kesalahan internal: ' . $e->getMessage()]);
        }
    }

    /**
     * GET /api/sertifikasi/berkas?id_arsip_sertifikasi=X
     */
    public function getBerkasSertifikasi($f3)
    {
        header('Content-Type: application/json');
        $userId = $f3->get('SESSION.user_id');
        if (!$userId || $f3->get('SESSION.role') !== 'customer') {
            echo json_encode(['success' => false, 'message' => 'Unauthorized']); return;
        }
        $id = $f3->get('GET.id_arsip_sertifikasi');
        if (!$id) { echo json_encode(['success' => false, 'message' => 'ID tidak valid']); return; }

        $user = new DB\SQL\Mapper($this->db, 'users');
        $user->load(['id = ?', $userId]);
        $idCustomer = (int)($user->id_customer ?? 0);

        // Jika id_customer = 0 atau sertiService tidak tersedia, kembalikan data kosong (bukan error)
        // agar halaman tetap tampil meski akun belum terhubung
        if (!$idCustomer || !$this->sertiService || !$this->dbSerti) {
            echo json_encode(['success' => true, 'data' => null]); return;
        }

        if (!$this->sertiService->checkArsipOwnership($id, $idCustomer)) {
            echo json_encode(['success' => true, 'data' => null]); return;
        }
        try {
            // Baca dari portal_selport (tempat pelanggan upload)
            $rows = $this->db->exec(
                "SELECT * FROM tb_arsip_sertifikasi_berkas WHERE id_arsip_sertifikasi = ? LIMIT 1", [$id]
            );

            // Jika belum ada berkas sama sekali, buat array kosong agar VTP bisa dibaca
            $data = !empty($rows) ? $rows[0] : [
                'id_arsip_sertifikasi'                       => $id,
                'formulir_verifikasi_tindakan_perbaikan'     => null,
                'formulir_verifikasi_tindakan_perbaikan_2'   => null,
                'formulir_verifikasi_tindakan_perbaikan_3'   => null,
                'tanggal_validasi_vtp'                       => null,
            ];

            // Selalu ambil tanggal_validasi_vtp dari tracking serti2020
            if ($this->dbSerti) {
                try {
                    $track = $this->dbSerti->exec(
                        "SELECT tanggal_validasi_vtp FROM tb_arsip_sertifikasi_tracking WHERE id_arsip_sertifikasi = ? LIMIT 1",
                        [$id]
                    );
                    $data['tanggal_validasi_vtp'] = (!empty($track) && isset($track[0]['tanggal_validasi_vtp'])) ? $track[0]['tanggal_validasi_vtp'] : null;
                } catch (\Exception $e) {}
            }

            // Fetch from serti2020 to compare for per-file verification
            $dataSerti = null;
            if ($this->dbSerti) {
                $rowsSerti = $this->dbSerti->exec(
                    "SELECT * FROM tb_arsip_sertifikasi_berkas WHERE id_arsip_sertifikasi = ? LIMIT 1", [$id]
                );
                $dataSerti = !empty($rowsSerti) ? $rowsSerti[0] : null;
            }

            // Fetch status from sertifikasi_berkas_status
            $statusBerkas = 'pending';
            $statusVtp = 'pending';
            $statusVtp1 = 'pending';
            $statusVtp2 = 'pending';
            $statusVtp3 = 'pending';
            try {
                $statusRow = $this->db->exec(
                    "SELECT status, status_vtp, status_vtp_1, status_vtp_2, status_vtp_3 FROM sertifikasi_berkas_status WHERE id_arsip_sertifikasi = ? LIMIT 1",
                    [$id]
                );
                if (!empty($statusRow)) {
                    $statusBerkas = $statusRow[0]['status'];
                    $statusVtp    = $statusRow[0]['status_vtp']   ?? 'pending';
                    $statusVtp1   = $statusRow[0]['status_vtp_1'] ?? 'pending';
                    $statusVtp2   = $statusRow[0]['status_vtp_2'] ?? 'pending';
                    $statusVtp3   = $statusRow[0]['status_vtp_3'] ?? 'pending';
                }
            } catch (\Exception $e) {}

            $data['status_berkas_pendaftaran'] = $statusBerkas;
            $data['status_vtp']   = $statusVtp;
            $data['status_vtp_1'] = $statusVtp1;
            $data['status_vtp_2'] = $statusVtp2;
            $data['status_vtp_3'] = $statusVtp3;

            echo json_encode(['success' => true, 'data' => $data, 'data_serti' => $dataSerti]);
        } catch (\Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    /**
     * GET /api/sertifikasi/download-berkas
     * Mengambil semua dokumen yang bisa didownload pelanggan untuk order sertifikasi
     */
    public function getDownloadBerkas($f3)
    {
        header('Content-Type: application/json');
        $userId = $f3->get('SESSION.user_id');
        if (!$userId || $f3->get('SESSION.role') !== 'customer') {
            echo json_encode(['success' => false, 'message' => 'Unauthorized']);
            return;
        }

        $idArsip = trim((string)($f3->get('GET.id_arsip_sertifikasi') ?? ''));
        if (!$idArsip || !is_numeric($idArsip)) {
            echo json_encode(['success' => false, 'message' => 'ID arsip tidak valid']);
            return;
        }

        // Cek kepemilikan arsip berdasarkan id_customer
        $user = new DB\SQL\Mapper($this->db, 'users');
        $user->load(['id = ?', $userId]);
        $idCustomer = (int)($user->id_customer ?? 0);

        if (!$idCustomer || !$this->sertiService) {
            echo json_encode(['success' => false, 'message' => 'Akun belum terhubung ke data pelanggan.']);
            return;
        }

        if (!$this->sertiService->checkArsipOwnership($idArsip, $idCustomer)) {
            echo json_encode(['success' => false, 'message' => 'Akses ditolak: order ini tidak terdaftar atas nama akun Anda.']);
            return;
        }

        if (!$this->dbSerti) {
            echo json_encode(['success' => false, 'message' => 'Database sertifikasi tidak tersedia']);
            return;
        }

        try {
            // Ambil konfigurasi URL base dari config.cfg
            $urlInvoice        = $f3->get('SERTI_INVOICE_AUDIT_URL') ?? '';
            $urlSuratPenawaran = $f3->get('SERTI_SURAT_PENAWARAN_URL') ?? '';
            $urlTandaTerima    = $f3->get('SERTI_TANDA_TERIMA_PERMOHONAN_URL') ?? '';
            $urlLaporanKtdks   = $f3->get('SERTI_LAPORAN_KETIDAKSESUAIAN_URL') ?? '';
            $urlLaporanAudit   = $f3->get('SERTI_LAPORAN_AUDIT_URL') ?? '';
            $urlVtp1           = $f3->get('SERTI_VERIFIKASI_TINDAKAN_PERBAIKAN_1_URL') ?? '';
            $urlVtp2           = $f3->get('SERTI_VERIFIKASI_TINDAKAN_PERBAIKAN_2_URL') ?? '';
            $urlVtp3           = $f3->get('SERTI_VERIFIKASI_TINDAKAN_PERBAIKAN_3_URL') ?? '';
            $urlSertifikat     = $f3->get('SERTI_SERTIFIKAT_URL') ?? '';
            $urlLhu            = $f3->get('SERTI_LHU_URL') ?? '';

            $docs = [];

            // =========================================================================
            // [TODO MENTOR] 1. TAMBAH QUERY DATABASE DI SINI
            // =========================================================================
            // Saat ini semua dokumen mengambil data dari tb_arsip_sertifikasi_pembayaran
            // menggunakan field `nama_berkas_invoice`.
            // 
            // Jika ingin memisahkan setiap dokumen sesuai tabel/field aslinya, 
            // silakan buat/aktifkan query baru di bawah ini dan tampung hasilnya ke dalam 
            // variabel baru (misal: $namaSertifikat, $namaLaporanAudit, dll).
            
            /* CONTOH CARA MENGGANTI LOGIC UNTUK SERTIFIKAT:
            $namaSertifikat = null;
            try {
                // Sesuaikan 'nama_file_sertifikat' dan 'tb_sertifikat' dengan nama yang benar di dbSerti
                $rowSerti = $this->dbSerti->exec(
                    "SELECT nama_file_sertifikat FROM tb_sertifikat WHERE id_arsip_sertifikasi = ? LIMIT 1",
                    [(int)$idArsip]
                );
                if (!empty($rowSerti)) $namaSertifikat = $rowSerti[0]['nama_file_sertifikat'];
            } catch (\Exception $e) {}
            */

            /* CONTOH CARA MENGGANTI LOGIC UNTUK VTP (JIKA LOKAL ATAU DARI INTERNAL):
            // Saat ini VTP sebenarnya sudah pernah diquery, tinggal ganti '$namaInvoice'
            // menjadi '$namaVtp1' di bagian array $docs di bawah. 
            // Dan jika URL nya lokal (bukan SIPPISAN), aktifkan kode URL base lokal ini:
            // $baseUrl = rtrim($f3->get('BASE') ?? '', '/');
            // $urlVtp1 = $baseUrl . '/public/uploads/sertifikasi/berkas-verifikasi-perbaikan/';
            // $urlVtp2 = $baseUrl . '/public/uploads/sertifikasi/berkas-verifikasi-perbaikan/';
            // $urlVtp3 = $baseUrl . '/public/uploads/sertifikasi/berkas-verifikasi-perbaikan/';
            */
            
            // 1. Surat Penawaran & Invoice — dari tb_arsip_sertifikasi_pembayaran
            $pembayaranRows = [];
            try {
                $pembayaranRows = $this->dbSerti->exec(
                    "SELECT nama_berkas_invoice, nomor_invoice FROM tb_arsip_sertifikasi_pembayaran
                     WHERE id_arsip_sertifikasi = ? AND nama_berkas_invoice IS NOT NULL AND nama_berkas_invoice != ''
                     ORDER BY id_pembayaran ASC LIMIT 1",
                    [(int)$idArsip]
                ) ?: [];
            } catch (\Exception $e) {}

            $namaInvoice = !empty($pembayaranRows) ? $pembayaranRows[0]['nama_berkas_invoice'] : null;

            // 2. VTP 1, 2, 3 — dari portal_selport tb_arsip_sertifikasi_berkas
            $berkasRow = [];
            try {
                $rows = $this->db->exec(
                    "SELECT formulir_verifikasi_tindakan_perbaikan,
                            formulir_verifikasi_tindakan_perbaikan_2,
                            formulir_verifikasi_tindakan_perbaikan_3
                     FROM tb_arsip_sertifikasi_berkas WHERE id_arsip_sertifikasi = ? LIMIT 1",
                    [(int)$idArsip]
                ) ?: [];
                if (!empty($rows)) $berkasRow = $rows[0];
            } catch (\Exception $e) {}

            $namaVtp1 = !empty($berkasRow['formulir_verifikasi_tindakan_perbaikan']) ? $berkasRow['formulir_verifikasi_tindakan_perbaikan'] : null;
            $namaVtp2 = !empty($berkasRow['formulir_verifikasi_tindakan_perbaikan_2']) ? $berkasRow['formulir_verifikasi_tindakan_perbaikan_2'] : null;
            $namaVtp3 = !empty($berkasRow['formulir_verifikasi_tindakan_perbaikan_3']) ? $berkasRow['formulir_verifikasi_tindakan_perbaikan_3'] : null;

            // Normalize null-like string values
            foreach (['namaVtp1','namaVtp2','namaVtp3','namaInvoice'] as $var) {
                if ($$var === 'null' || $$var === 'undefined' || $$var === '') $$var = null;
            }

            // =========================================================================
            // [TODO MENTOR] 2. UBAH VARIABEL PADA ARRAY DI BAWAH INI
            // =========================================================================
            // Setelah membuat query baru di atas (misal variabel $namaLaporanAudit),
            // silakan ganti variabel `$namaInvoice` di bawah ini dengan variabel 
            // yang sesuai untuk masing-masing dokumen.

            // Build dokumen list
            $docs = [
                [
                    'label' => 'Surat Penawaran',
                    'desc'  => 'Surat penawaran harga sertifikasi',
                    'file'  => $namaInvoice,
                    'url'   => $namaInvoice ? $urlSuratPenawaran . $namaInvoice : null,
                ],
                [
                    'label' => 'Invoice',
                    'desc'  => 'Dokumen invoice biaya sertifikasi',
                    'file'  => $namaInvoice,
                    'url'   => $namaInvoice ? $urlInvoice . $namaInvoice : null,
                ],
                [
                    'label' => 'Tanda Terima Permohonan Sertifikasi',
                    'desc'  => 'Dokumen tanda terima permohonan sertifikasi',
                    'file'  => $namaInvoice,
                    'url'   => $namaInvoice ? $urlTandaTerima . $namaInvoice : null,
                ],
                [
                    'label' => 'Laporan Ketidaksesuaian dan Observasi',
                    'desc'  => 'Laporan temuan ketidaksesuaian dan observasi audit',
                    'file'  => $namaInvoice,
                    'url'   => $namaInvoice ? $urlLaporanKtdks . $namaInvoice : null,
                ],
                [
                    'label' => 'Laporan Audit',
                    'desc'  => 'Laporan hasil audit',
                    'file'  => $namaInvoice,
                    'url'   => $namaInvoice ? $urlLaporanAudit . $namaInvoice : null,
                ],
                [
                    'label' => 'Verifikasi Tindakan Perbaikan 1',
                    'desc'  => 'Dokumen verifikasi tindakan perbaikan tahap 1',
                    'file'  => $namaInvoice,
                    'url'   => $namaInvoice ? $urlVtp1 . $namaInvoice : null,
                ],
                [
                    'label' => 'Verifikasi Tindakan Perbaikan 2',
                    'desc'  => 'Dokumen verifikasi tindakan perbaikan tahap 2',
                    'file'  => $namaInvoice,
                    'url'   => $namaInvoice ? $urlVtp2 . $namaInvoice : null,
                ],
                [
                    'label' => 'Verifikasi Tindakan Perbaikan 3',
                    'desc'  => 'Dokumen verifikasi tindakan perbaikan tahap 3',
                    'file'  => $namaInvoice,
                    'url'   => $namaInvoice ? $urlVtp3 . $namaInvoice : null,
                ],
                [
                    'label' => 'Sertifikat',
                    'desc'  => 'Sertifikat kesesuaian produk',
                    'file'  => $namaInvoice,
                    'url'   => $namaInvoice ? $urlSertifikat . $namaInvoice : null,
                ],
                [
                    'label'  => 'Laporan Hasil Uji (LHU)',
                    'desc'   => 'Laporan hasil pengujian produk',
                    'file'   => $namaInvoice,
                    'url'    => $namaInvoice ? $urlLhu . $namaInvoice : null,
                    'is_lhu' => true,
                ],
            ];

            echo json_encode(['success' => true, 'data' => $docs]);
        } catch (\Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    /**
     * POST /dashboard/sertifikasi/berkas/upload
     */
    public function uploadBerkasSertifikasi($f3)
    {
        header('Content-Type: application/json');
        $userId = $f3->get('SESSION.user_id');
        if (!$userId || $f3->get('SESSION.role') !== 'customer') {
            echo json_encode(['success' => false, 'message' => 'Unauthorized']); return;
        }
        $id = $f3->get('POST.id_arsip_sertifikasi');
        error_log("[SELPORT_DEBUG] uploadBerkasSertifikasi called by user " . $userId . " for ID: " . ($id ?: 'EMPTY'));
        if (!$id) { echo json_encode(['success' => false, 'message' => 'ID tidak valid']); return; }

        $user = new DB\SQL\Mapper($this->db, 'users');
        $user->load(['id = ?', $userId]);
        $idCustomer = (int)($user->id_customer ?? 0);

        if (!$idCustomer) {
            echo json_encode(['success' => false, 'message' => 'Akun Anda belum terhubung ke data pelanggan (id_customer kosong). Hubungi admin.']); return;
        }

        if (!$this->sertiService || !$this->dbSerti) {
            echo json_encode(['success' => false, 'message' => 'Layanan sertifikasi tidak tersedia saat ini.']); return;
        }

        if (!$this->sertiService->checkArsipOwnership($id, $idCustomer)) {
            echo json_encode(['success' => false, 'message' => 'Akses ditolak: order sertifikasi ini tidak terdaftar atas nama akun Anda.']); return;
        }

        $existing = null;
        try {
            $rows = $this->db->exec(
                "SELECT id_berkas FROM tb_arsip_sertifikasi_berkas WHERE id_arsip_sertifikasi = ? LIMIT 1", [$id]
            );
            $existing = !empty($rows) ? $rows[0] : null;
        } catch (\Exception $e) {
            error_log('DB error in uploadBerkasSertifikasi: ' . $e->getMessage());
            echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]); return;
        }

        $fieldMap = [
            'file_surat_permohonan'       => 'b7104_nama_berkas_surat_permohonan',
            'file_permohonan_sertifikasi' => 'b7104_nama_berkas_permohonan_sertifikasi_produk',
            'file_informasi_sertifikasi'  => 'b7104_nama_berkas_informasi_sertifikasi_produk',
            'file_akte_perusahaan'        => 'b7104_nama_berkas_akte_perusahaan',
            'file_izin_usaha'             => 'b7104_nama_berkas_izin_usaha_industri',
            'file_sertifikat_merek'       => 'b7104_nama_berkas_sertifikat_merek',
            'file_surat_pelimpahan_merek' => 'b7104_nama_berkas_sertifikat_merek_2',
            'file_alur_proses_produksi'   => 'b7104_nama_berkas_alur_proses_produksi',
            'file_ilustrasi_tanda_sni'    => 'b7104_nama_berkas_ilustrasi_cara_pembubuhan_tanda_sni',
            'file_struktur_organisasi'    => 'b7104_nama_berkas_struktur_organisasi_perusahaan',
            'file_daftar_peralatan'       => 'b7104_nama_berkas_daftar_peralatan_pengujian',
            'file_kopi_laporan_kalibrasi' => 'b7104_nama_berkas_kopi_laporan_sertifikat',
            'file_pedoman_mutu'           => 'b7104_nama_berkas_pedoman_mutu',
            'file_daftar_dokumentasi_smm' => 'b7104_nama_berkas_daftar_dokumentasi_smm',
            'file_kopi_sertifikat_smm'    => 'b7104_nama_berkas_kopi_sertifikat_sistem_manajemen_mutu',
            'file_sipa'                   => 'b7104_nama_berkas_sipa_surat_keterangan_kerjasama',
            'file_hasil_uji_air_baku'     => 'b7104_nama_berkas_sertifikat_hasil_uji_air_baku',
            'file_vtp_1'                  => 'formulir_verifikasi_tindakan_perbaikan',
            'file_vtp_2'                  => 'formulir_verifikasi_tindakan_perbaikan_2',
            'file_vtp_3'                  => 'formulir_verifikasi_tindakan_perbaikan_3',
        ];

        // Gunakan absolute path berbasis __DIR__ agar tidak bergantung pada CWD Apache/XAMPP
        $targetUploadPath = __DIR__ . '/../../public/uploads/sertifikasi';
        if (!is_dir($targetUploadPath)) {
            @mkdir($targetUploadPath, 0755, true);
        }
        $baseUploadDir = realpath($targetUploadPath) . DIRECTORY_SEPARATOR;
        if (!$baseUploadDir || $baseUploadDir === DIRECTORY_SEPARATOR) {
            $baseUploadDir = $targetUploadPath . DIRECTORY_SEPARATOR;
        }

        $dirPendaftaran = $baseUploadDir . 'berkas-pendaftaran' . DIRECTORY_SEPARATOR;
        $dirVtp = $baseUploadDir . 'berkas-verifikasi-perbaikan' . DIRECTORY_SEPARATOR;

        if (!is_dir($dirPendaftaran)) @mkdir($dirPendaftaran, 0755, true);
        if (!is_dir($dirVtp)) @mkdir($dirVtp, 0755, true);

        $updates = []; $errors = [];
        foreach ($fieldMap as $inputName => $dbField) {
            if (empty($_FILES[$inputName]['name']) || $_FILES[$inputName]['error'] === UPLOAD_ERR_NO_FILE) continue;
            if ($_FILES[$inputName]['error'] !== UPLOAD_ERR_OK) {
                error_log("Upload error for {$inputName}: code " . $_FILES[$inputName]['error']);
                $errors[] = "Gagal mengunggah {$inputName} (Error code: " . $_FILES[$inputName]['error'] . ")"; continue;
            }
            $file = $_FILES[$inputName];
            $ext  = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            if ($ext !== 'pdf') {
                $errors[] = "Format file {$inputName} (.{$ext}) tidak didukung. Harap unggah file berformat PDF."; continue;
            }
            if ($file['size'] > 10 * 1024 * 1024) {
                $errors[] = "Ukuran file {$inputName} melebihi batas maksimal 10MB"; continue;
            }
            $fileName = time() . '_' . str_replace(' ', '_', $file['name']);
            $isVtp = (strpos($inputName, 'file_vtp_') === 0);
            $currentUploadDir = $isVtp ? $dirVtp : $dirPendaftaran;
            if (!move_uploaded_file($file['tmp_name'], $currentUploadDir . $fileName)) {
                error_log("Failed to move uploaded file {$inputName} to {$currentUploadDir}");
                $errors[] = "Gagal memproses penyimpanan file {$inputName}"; continue;
            }
            $updates[$dbField] = $fileName;
        }

        if (empty($updates)) {
            echo json_encode(['success' => false, 'message' => !empty($errors) ? implode(', ', $errors) : 'Tidak ada file baru yang diunggah.']);
            return;
        }

        try {
            if ($existing) {
                $set = []; $params = [];
                foreach ($updates as $f => $v) { $set[] = "`$f` = ?"; $params[] = $v; }
                // Catat waktu upload berkas
                $set[] = "`tanggal_upload_berkas` = NOW()";
                $params[] = $id;
                $res = $this->db->exec("UPDATE tb_arsip_sertifikasi_berkas SET " . implode(', ', $set) . " WHERE id_arsip_sertifikasi = ?", $params);
                if ($res === false) throw new \Exception("Gagal melakukan update data ke database.");
            } else {
                $fields = ['id_arsip_sertifikasi', '`tanggal_upload_berkas`']; $ph = ['?', 'NOW()']; $params = [$id];
                foreach ($updates as $f => $v) { $fields[] = "`$f`"; $ph[] = '?'; $params[] = $v; }
                $res = $this->db->exec("INSERT INTO tb_arsip_sertifikasi_berkas (" . implode(',', $fields) . ") VALUES (" . implode(',', $ph) . ")", $params);
                if ($res === false) throw new \Exception("Gagal melakukan insert data ke database.");
            }

            // Cek apakah ada file pendaftaran atau VTP yang diupload
            $hasPendaftaran = false;
            $hasVTP = false;
            foreach ($updates as $dbField => $val) {
                if (strpos($dbField, 'formulir_verifikasi_tindakan_perbaikan') === 0) {
                    $hasVTP = true;
                } else {
                    $hasPendaftaran = true;
                }
            }

            // Kembalikan status ke 'pending' di sertifikasi_berkas_status sesuai jenis dokumen yang diupload
            try {
                if ($hasPendaftaran) {
                    $this->db->exec("UPDATE sertifikasi_berkas_status SET status = 'pending' WHERE id_arsip_sertifikasi = ?", [$id]);
                }
                if ($hasVTP) {
                    $this->db->exec("UPDATE sertifikasi_berkas_status SET status_vtp = 'pending' WHERE id_arsip_sertifikasi = ?", [$id]);
                }
            } catch (\Exception $e) {}

            // Kembalikan status ke belum divalidasi (0000-00-00) untuk Verifikasi Tindakan Perbaikan
            if ($this->dbSerti) {
                try {
                    $this->dbSerti->exec("UPDATE tb_arsip_sertifikasi_tracking SET tanggal_validasi_vtp = '0000-00-00' WHERE id_arsip_sertifikasi = ?", [$id]);
                } catch (\Exception $e) {}
            }

            if ($this->notifikasiService) {
                $namaPelanggan = $user->nama_lengkap ?? $user->username ?? 'Pelanggan';
                
                $waMsg = "*Notifikasi Selport*\n"
                       . "Halo {nama_user}, terdapat unggahan Berkas Sertifikasi (Pendaftaran / Tindakan Perbaikan) baru dari pelanggan:\n"
                       . "*Nama*: {$namaPelanggan}\n"
                       . "*ID Order*: {$id}\n"
                       . "Silakan login ke sistem untuk melakukan verifikasi berkas.";
                $this->notifikasiService->sendWhatsAppToSertifikasi($waMsg, $idCustomer);
            }

            $msg = 'Berkas pendaftaran berhasil disimpan.';
            if (!empty($errors)) $msg .= ' Peringatan: ' . implode('; ', $errors);
            echo json_encode(['success' => true, 'message' => $msg]);
        } catch (\Exception $e) {
            // Check if error is about missing column, then try to add it and retry once
            if (strpos($e->getMessage(), 'Unknown column \'tanggal_upload_berkas\'') !== false) {
                try {
                    $this->db->exec("ALTER TABLE tb_arsip_sertifikasi_berkas ADD COLUMN tanggal_upload_berkas DATETIME DEFAULT NULL");
                    // retry logic could be added here, but to be safe we just return error and let user click again
                } catch (\Exception $e2) {
                    // Ignore
                }
            }
            error_log('Critical DB failure in uploadBerkasSertifikasi: ' . $e->getMessage());
            echo json_encode(['success' => false, 'message' => 'Kesalahan database saat menyimpan berkas: ' . $e->getMessage()]);
        }
    }

    public function submitKuesioner($f3)
    {
        header('Content-Type: application/json');

        $userId = $f3->get('SESSION.user_id');
        if (!$userId) {
            echo json_encode(['success' => false, 'message' => 'Tidak terautentikasi']);
            return;
        }

        // Ensure table exists
        $this->db->exec("CREATE TABLE IF NOT EXISTS data_responden (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            order_id VARCHAR(50) NOT NULL,
            jenis_pelayanan VARCHAR(255),
            instansi VARCHAR(255),
            nama_lengkap VARCHAR(255),
            no_hp VARCHAR(50),
            usia VARCHAR(50),
            jenis_kelamin VARCHAR(50),
            pendidikan VARCHAR(50),
            status_responden VARCHAR(50),
            U1 VARCHAR(255), U2 VARCHAR(255),
            T1 VARCHAR(255), T2 VARCHAR(255), T3 TEXT,
            U3 VARCHAR(255), U4 VARCHAR(255), T4 TEXT, T5 TEXT,
            U5 VARCHAR(255), U6 VARCHAR(255), U7 VARCHAR(255), U8 VARCHAR(255),
            T6 VARCHAR(255), T7 VARCHAR(255), T8 TEXT, T9 VARCHAR(255), T10 TEXT, T11 VARCHAR(255), T12 TEXT,
            U9_1 VARCHAR(255), U9_2 VARCHAR(255), U9_3 VARCHAR(255), U9_4 VARCHAR(255), U9_5 VARCHAR(255), U9_6 VARCHAR(255),
            T13 TEXT, T14 TEXT, opini TEXT,
            UK1 VARCHAR(255), UK2 VARCHAR(255), UK3 VARCHAR(255), UK4 VARCHAR(255),
            UK5 VARCHAR(255), UK6 VARCHAR(255), UK7 VARCHAR(255), UK8 VARCHAR(255),
            UR1 INT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )");

        // Use $_POST directly for better compatibility with FormData
        $post = $_POST;
        error_log("submitKuesioner POST DATA: " . print_r($post, true));

        $stringFields = [
            'order_id', 'jenis_pelayanan', 'instansi', 'nama_lengkap', 'no_hp',
            'usia', 'jenis_kelamin', 'pendidikan', 'status_responden',
            'U1', 'U2', 'T1', 'T2', 'T3', 'U3', 'U4', 'T4', 'T5',
            'U5', 'U6', 'U7', 'U8', 'T6', 'T7', 'T8', 'T9', 'T10', 'T11', 'T12',
            'U9_1', 'U9_2', 'U9_3', 'U9_4', 'U9_5', 'U9_6', 'T13', 'T14', 'opini',
            'UK1', 'UK2', 'UK3', 'UK4', 'UK5', 'UK6', 'UK7', 'UK8'
        ];

        $data = ['user_id' => (int)$userId];
        foreach ($stringFields as $field) {
            $val = isset($post[$field]) && $post[$field] !== '' ? $post[$field] : null;
            $data[$field] = $val;
        }

        // UR1 is INT — cast properly
        $ur1Raw = isset($post['UR1']) && $post['UR1'] !== '' ? $post['UR1'] : null;
        $data['UR1'] = ($ur1Raw !== null) ? (int)$ur1Raw : null;

        if (empty($data['order_id'])) {
            $receivedOrderId = isset($post['order_id']) ? $post['order_id'] : 'TIDAK_ADA_SAMA_SEKALI';
            echo json_encode(['success' => false, 'message' => 'Gagal: Order ID kosong. Nilai yang diterima form: ' . $receivedOrderId]);
            return;
        }

        $cols = implode(', ', array_keys($data));
        
        $placeholdersArr = [];
        $namedValues = [];
        foreach ($data as $key => $val) {
            $placeholdersArr[] = ':' . $key;
            $namedValues[':' . $key] = $val;
        }
        $placeholders = implode(', ', $placeholdersArr);

        try {
            $this->db->exec("INSERT INTO data_responden ($cols) VALUES ($placeholders)", $namedValues);

            if ($this->notifikasiService) {
                $namaPelanggan = $data['nama_lengkap'] ?? 'Pelanggan';
                $waMsgToMitra = "*Notifikasi Selport*\n"
                              . "Halo {nama_user}, pelanggan {$namaPelanggan} baru saja mengisi dan mengirimkan Kuesioner Kepuasan.\n"
                              . "Data kuesioner kini telah direkam ke dalam database.";
                $this->notifikasiService->sendWhatsAppToMitra($waMsgToMitra);
            }

            echo json_encode(['success' => true, 'message' => 'Kuesioner berhasil disimpan']);
        } catch (\Exception $e) {
            error_log('submitKuesioner error: ' . $e->getMessage());
            echo json_encode(['success' => false, 'message' => 'Gagal: ' . $e->getMessage()]);
        }
    }
}