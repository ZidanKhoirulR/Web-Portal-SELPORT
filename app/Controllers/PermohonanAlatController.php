<?php

class PermohonanAlatController
{
    private $db;
    private $notifikasiService;

    public function __construct($db, $notifikasiService = null)
    {
        $this->db = $db;
        $this->notifikasiService = $notifikasiService;
        $this->createTablesIfNotExist();
    }

    // ─── Permintaan Alat ───────────────────────────────────────────────

    public function getPermohonanList($f3)
    {
        header('Content-Type: application/json; charset=utf-8');
        if (!$f3->get('SESSION.user_id')) {
            echo json_encode(['success' => false, 'message' => 'Unauthorized']);
            return;
        }

        $userId = $f3->get('SESSION.user_id');

        try {
            $orders = $this->db->exec(
                'SELECT * FROM alat_permintaan WHERE id_pengguna = ? ORDER BY dibuat_pada DESC',
                [$userId]
            );

            $includeAll = $f3->get('GET.include_all');
            if (!$includeAll) {
                $docs = $this->db->exec(
                    "SELECT id_permintaan_list FROM alat_dokumen WHERE id_pengguna = ?",
                    [$userId]
                );
                $uploadedIds = [];
                foreach ($docs as $doc) {
                    if (!empty($doc['id_permintaan_list'])) {
                        $ids         = explode(',', $doc['id_permintaan_list']);
                        $uploadedIds = array_merge($uploadedIds, array_map('trim', $ids));
                    }
                }
                $uploadedIds = array_unique(array_filter($uploadedIds));

                if (!empty($uploadedIds)) {
                    $orders = array_values(array_filter($orders, function ($order) use ($uploadedIds) {
                        return !in_array((string) $order['id'], $uploadedIds);
                    }));
                }
            }

            foreach ($orders as &$order) {
                $items          = $this->db->exec(
                    'SELECT * FROM alat_item WHERE id_permintaan = ? ORDER BY dibuat_pada ASC',
                    [$order['id']]
                );
                $order['items'] = $items;
            }

            echo json_encode(['success' => true, 'data' => $orders]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => 'Gagal memuat data: ' . $e->getMessage()]);
        }
    }

    public function savePermohonanOrder($f3)
    {
        header('Content-Type: application/json; charset=utf-8');
        if (!$f3->get('SESSION.user_id')) {
            echo json_encode(['success' => false, 'message' => 'Unauthorized']);
            return;
        }

        $userId = $f3->get('SESSION.user_id');
        $id     = $f3->get('POST.id');

        try {
            $this->createTablesIfNotExist();

            $kategori            = trim($f3->get('POST.kategori'));
            $namaPenandaTangan   = trim($f3->get('POST.nama_penanda_tangan') ?? '');
            $jabatan             = trim($f3->get('POST.jabatan') ?? '');
            $catatan             = trim($f3->get('POST.catatan') ?? '');

            if ($id) {
                $existing = $this->db->exec('SELECT id FROM alat_permintaan WHERE id = ? AND id_pengguna = ?', [$id, $userId]);
                if (empty($existing)) {
                    echo json_encode(['success' => false, 'message' => 'Data tidak ditemukan']);
                    return;
                }
                $this->db->exec(
                    'UPDATE alat_permintaan SET kategori = ?, nama_penanda_tangan = ?, jabatan = ?, catatan = ?, diubah_pada = NOW() WHERE id = ? AND id_pengguna = ?',
                    [$kategori, $namaPenandaTangan, $jabatan, $catatan, $id, $userId]
                );
                $orderId = $id;
            } else {
                $this->db->exec(
                    'INSERT INTO alat_permintaan (id_pengguna, status, kategori, nama_penanda_tangan, jabatan, catatan, dibuat_pada, diubah_pada) VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())',
                    [$userId, 'Draft', $kategori, $namaPenandaTangan, $jabatan, $catatan]
                );
                $orderId = $this->db->lastinsertid();
            }

            echo json_encode(['success' => true, 'message' => 'Permintaan berhasil disimpan', 'id' => $orderId]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => 'Gagal menyimpan permintaan: ' . $e->getMessage()]);
        }
    }

    public function deletePermohonanOrder($f3)
    {
        header('Content-Type: application/json; charset=utf-8');
        if (!$f3->get('SESSION.user_id')) {
            echo json_encode(['success' => false, 'message' => 'Unauthorized']);
            return;
        }

        $userId = $f3->get('SESSION.user_id');
        $id     = $f3->get('POST.id');

        try {
            $order = new DB\SQL\Mapper($this->db, 'alat_permintaan');
            $order->load(['id = ? AND id_pengguna = ?', $id, $userId]);
            if (!$order->dry()) $order->erase();
            echo json_encode(['success' => true, 'message' => 'Permintaan berhasil dihapus']);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => 'Gagal menghapus permintaan: ' . $e->getMessage()]);
        }
    }

    // ─── Item Alat ────────────────────────────────────────────────────

    public function savePermohonanItem($f3)
    {
        header('Content-Type: application/json; charset=utf-8');
        if (!$f3->get('SESSION.user_id')) {
            echo json_encode(['success' => false, 'message' => 'Unauthorized']);
            return;
        }

        $userId        = $f3->get('SESSION.user_id');
        $permintaan_id = $f3->get('POST.id_permintaan');
        $id            = $f3->get('POST.id');

        try {
            $this->createTablesIfNotExist();

            $order = new DB\SQL\Mapper($this->db, 'alat_permintaan');
            $order->load(['id = ? AND id_pengguna = ?', $permintaan_id, $userId]);
            if ($order->dry()) {
                echo json_encode(['success' => false, 'message' => 'Permintaan tidak valid']);
                return;
            }

            $item = new DB\SQL\Mapper($this->db, 'alat_item');
            if ($id) {
                $item->load(['id = ? AND id_permintaan = ?', $id, $permintaan_id]);
                if ($item->dry()) {
                    echo json_encode(['success' => false, 'message' => 'Item tidak ditemukan']);
                    return;
                }
            } else {
                $item->id_permintaan = $permintaan_id;
            }

            $item->nama_alat    = trim($f3->get('POST.nama_alat'));
            $item->merk         = trim($f3->get('POST.merk'));
            $item->tipe_seri    = trim($f3->get('POST.tipe_seri'));
            $item->kapasitas    = trim($f3->get('POST.kapasitas'));
            $item->ketelitian   = trim($f3->get('POST.ketelitian'));
            $item->satuan       = trim($f3->get('POST.satuan'));
            $item->jumlah       = (int)$f3->get('POST.jumlah');
            $item->titik_ukur   = trim($f3->get('POST.titik_ukur'));
            $item->kondisi_alat = trim($f3->get('POST.kondisi_alat'));
            $item->keterangan   = trim($f3->get('POST.keterangan'));

            // Handle Image Upload
            if (isset($_FILES['gambar']) && $_FILES['gambar']['error'] === UPLOAD_ERR_OK) {
                $uploadDir = 'public/uploads/permohonan-alat/foto/';
                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0777, true);
                }
                
                $fileName = time() . '_' . basename($_FILES['gambar']['name']);
                $targetFile = $uploadDir . $fileName;
                
                if (move_uploaded_file($_FILES['gambar']['tmp_name'], $targetFile)) {
                    $item->lokasi_gambar = '/' . $targetFile;
                }
            }
            
            $item->save();

            echo json_encode(['success' => true, 'message' => 'Item berhasil disimpan', 'id' => $item->id]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => 'Gagal menyimpan item: ' . $e->getMessage()]);
        }
    }

    public function deletePermohonanItem($f3)
    {
        header('Content-Type: application/json; charset=utf-8');
        if (!$f3->get('SESSION.user_id')) {
            echo json_encode(['success' => false, 'message' => 'Unauthorized']);
            return;
        }

        $id     = $f3->get('POST.id');
        $userId = $f3->get('SESSION.user_id');

        try {
            $item = new DB\SQL\Mapper($this->db, 'alat_item');
            $item->load(['id = ?', $id]);
            if (!$item->dry()) {
                $order = new DB\SQL\Mapper($this->db, 'alat_permintaan');
                $order->load(['id = ? AND id_pengguna = ?', $item->id_permintaan, $userId]);
                if (!$order->dry()) {
                    $item->erase();
                } else {
                    echo json_encode(['success' => false, 'message' => 'Unauthorized to delete this item']);
                    return;
                }
            }
            echo json_encode(['success' => true, 'message' => 'Item berhasil dihapus']);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => 'Gagal menghapus item: ' . $e->getMessage()]);
        }
    }

    // ─── Cetak Dokumen ────────────────────────────────────────────────────────────

    public function cetakPermohonan($f3)
    {
        if (!$f3->get('SESSION.user_id')) {
            $f3->reroute('/otentikasi');
            return;
        }

        $userId = $f3->get('SESSION.user_id');
        $id = $f3->get('GET.id');

        if (!$id) {
            echo "ID Permohonan tidak ditemukan.";
            return;
        }

        try {
            $order = $this->db->exec('SELECT * FROM alat_permintaan WHERE id = ? AND id_pengguna = ?', [$id, $userId]);
            
            if (empty($order)) {
                echo "Permohonan tidak ditemukan atau Anda tidak memiliki akses.";
                return;
            }

            $order = $order[0];
            $items = $this->db->exec('SELECT * FROM alat_item WHERE id_permintaan = ? ORDER BY dibuat_pada ASC', [$id]);

            $user = $this->db->exec('SELECT * FROM users WHERE id = ?', [$userId]);
            $user = $user[0];
            
            $company = [];
            if (!empty($user['company_id'])) {
                $c = $this->db->exec('SELECT * FROM companies WHERE id = ?', [$user['company_id']]);
                if (!empty($c)) $company = $c[0];
            }

            $lokasi_perusahaan = array_filter([$company['provinsi_perusahaan'] ?? '', $company['kabupaten_perusahaan'] ?? '', $company['kecamatan_perusahaan'] ?? '']);
            
            $f3->set('nama_perusahaan', $company['nama_perusahaan'] ?? 'Perorangan');
            $f3->set('nama_lengkap', $user['nama_lengkap']);
            $f3->set('alamat_perusahaan', $company['alamat_perusahaan'] ?? '-');
            $f3->set('lokasi_perusahaan', !empty($lokasi_perusahaan) ? implode(', ', $lokasi_perusahaan) : '-');
            $f3->set('kodepos_perusahaan', $company['kodepos_perusahaan'] ?? '-');
            $f3->set('kategori', $order['kategori'] ?? 'Permintaan Permohonan Alat');
            $f3->set('nama_penanda_tangan', $order['nama_penanda_tangan'] ?? '_________________________');
            $f3->set('jabatan', $order['jabatan'] ?? '');

            // Ambil no_identitas dari alat_dokumen yang terhubung ke permintaan ini
            $noIdentitas = '-';
            try {
                $dokRows = $this->db->exec(
                    "SELECT no_identitas FROM alat_dokumen WHERE id_pengguna = ? AND FIND_IN_SET(?, id_permintaan_list) ORDER BY dibuat_pada DESC LIMIT 1",
                    [$userId, $id]
                );
                if (!empty($dokRows) && !empty($dokRows[0]['no_identitas'])) {
                    $noIdentitas = $dokRows[0]['no_identitas'];
                }
            } catch (\Exception $e) {}
            $f3->set('no_identitas', $noIdentitas);
            
            $months = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
            $d = explode('-', date('Y-n-d'));
            $f3->set('tanggal_cetak', $d[2] . ' ' . $months[$d[1]-1] . ' ' . $d[0]);

            $itemsHtml = '';
            
            if (count($items) > 0) {
                foreach ($items as $index => $item) {
                    $itemNum = $index + 1;
                    $fotoHtml = '';
                    if (!empty($item['lokasi_gambar'])) {
                        $fotoHtml = '<div class="alat-photo">
                            <img src="' . $f3->get('BASE') . $item['lokasi_gambar'] . '" alt="Foto Alat ' . htmlspecialchars($item['nama_alat']) . '">
                        </div>';
                    } else {
                        $fotoHtml = '<div class="alat-photo" style="color:#94a3b8; font-style:italic; padding: 24px;">Tidak ada foto terlampir</div>';
                    }
                    
                    $extraStyle = ($index === count($items) - 1) ? ' style="page-break-after: auto;"' : '';
                    
                    $itemsHtml .= '
                    <div class="alat-item-print"' . $extraStyle . '>
                        <div class="alat-item-title">Data Alat ' . $itemNum . '</div>
                        <table class="table-print">
                            <tbody>
                                <tr><th>Nama Alat</th><td>' . htmlspecialchars($item['nama_alat'] ?? '-') . '</td></tr>
                                <tr><th>Merk</th><td>' . htmlspecialchars($item['merk'] ?? '-') . '</td></tr>
                                <tr><th>Tipe / Nomor Seri</th><td>' . htmlspecialchars($item['tipe_seri'] ?? '-') . '</td></tr>
                                <tr><th>Kapasitas</th><td>' . htmlspecialchars($item['kapasitas'] ?? '-') . '</td></tr>
                                <tr><th>Ketelitian</th><td>' . htmlspecialchars($item['ketelitian'] ?? '-') . '</td></tr>
                                <tr><th>Satuan</th><td>' . htmlspecialchars($item['satuan'] ?? '-') . '</td></tr>
                                <tr><th>Jumlah</th><td>' . htmlspecialchars($item['jumlah'] ?? '-') . '</td></tr>
                                <tr><th>Titik Ukur</th><td>' . htmlspecialchars($item['titik_ukur'] ?? '-') . '</td></tr>
                                <tr><th>Kondisi Alat</th><td>' . htmlspecialchars($item['kondisi_alat'] ?? '-') . '</td></tr>
                                <tr><th>Keterangan</th><td>' . nl2br(htmlspecialchars($item['keterangan'] ?? '-')) . '</td></tr>
                            </tbody>
                        </table>
                        ' . $fotoHtml . '
                    </div>';
                }
                
                // Tanda tangan hanya diletakkan satu kali setelah item terakhir
                $signatureHtml = '
                    <div class="signature-section">
                        <div class="signature-box">
                            <p>Yang Bertanda Tangan,</p>
                            <div class="signature-space"></div>
                            <p style="font-weight: 700;">' . htmlspecialchars($order['nama_penanda_tangan'] ?? '_________________________') . '</p>
                            <p>' . htmlspecialchars($order['jabatan'] ?? '') . '</p>
                        </div>
                    </div>';
                $itemsHtml .= $signatureHtml;
                
            } else {
                $itemsHtml = '<div style="padding: 24px; text-align: center; color: #94a3b8; background: #f8fafc; border-radius: 8px;">Tidak ada alat yang dilampirkan dalam permohonan ini.</div>';
            }

            $f3->set('ALAT_ITEMS_HTML', $itemsHtml);
            
            echo \Template::instance()->render('pelanggan/cetakpermohonanalat.html');
        } catch (Exception $e) {
            echo "Terjadi kesalahan saat memuat data: " . $e->getMessage();
        }
    }

    // ─── Internal Helper ────────────────────────────────────────────────────────────

    private function createTablesIfNotExist() {
        try {
            $this->db->exec("
                CREATE TABLE IF NOT EXISTS alat_permintaan (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    id_pengguna INT NOT NULL,
                    status VARCHAR(50) DEFAULT 'Draft',
                    kategori VARCHAR(255),
                    nama_penanda_tangan VARCHAR(255),
                    jabatan VARCHAR(255),
                    dibuat_pada DATETIME DEFAULT CURRENT_TIMESTAMP,
                    diubah_pada DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    FOREIGN KEY (id_pengguna) REFERENCES users(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
        } catch (\Exception $e) {}

        try {
            $this->db->exec("ALTER TABLE alat_permintaan ADD COLUMN catatan TEXT AFTER jabatan");
        } catch (\Exception $e) {}

        try {
            $this->db->exec("
                CREATE TABLE IF NOT EXISTS alat_item (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    id_permintaan INT NOT NULL,
                    nama_alat VARCHAR(255),
                    merk VARCHAR(255),
                    tipe_seri VARCHAR(255),
                    kapasitas VARCHAR(255),
                    ketelitian VARCHAR(255),
                    satuan VARCHAR(50),
                    jumlah INT,
                    titik_ukur VARCHAR(255),
                    kondisi_alat VARCHAR(100),
                    keterangan TEXT,
                    lokasi_gambar VARCHAR(500),
                    dibuat_pada DATETIME DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (id_permintaan) REFERENCES alat_permintaan(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
        } catch (\Exception $e) {}

        try {
            // Memastikan kolom ditambahkan jika tabel sudah ada sebelumnya
            $this->db->exec("ALTER TABLE alat_item ADD COLUMN lokasi_gambar VARCHAR(500)");
        } catch (\Exception $e) {}

        try {
            $this->db->exec("ALTER TABLE alat_item ADD COLUMN titik_ukur VARCHAR(255) AFTER jumlah");
        } catch (\Exception $e) {}

        try {
            $this->db->exec("
                CREATE TABLE IF NOT EXISTS alat_dokumen (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    id_pengguna INT NOT NULL,
                    nama_file_acak VARCHAR(255),
                    nama_file_asli VARCHAR(255),
                    lokasi_file VARCHAR(500),
                    status VARCHAR(50) DEFAULT 'pending',
                    alasan_penolakan TEXT,
                    id_permintaan_list VARCHAR(255),
                    jumlah_revisi INT DEFAULT 0,
                    konfirmasi_syarat VARCHAR(1) DEFAULT 'Y',
                    dibuat_pada DATETIME DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (id_pengguna) REFERENCES users(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
        } catch (\Exception $e) {}

        // ─── Migrasi Audit Trail Kolom (1-5) ────────────────────────────────────────
        $auditMigrations = [
            "ALTER TABLE alat_dokumen ADD COLUMN konfirmasi_syarat_1 TINYINT(1) DEFAULT 0",
            "ALTER TABLE alat_dokumen ADD COLUMN konfirmasi_syarat_2 TINYINT(1) DEFAULT 0",
            "ALTER TABLE alat_dokumen ADD COLUMN konfirmasi_syarat_3 TINYINT(1) DEFAULT 0",
            "ALTER TABLE alat_dokumen ADD COLUMN konfirmasi_syarat_4 TINYINT(1) DEFAULT 0",
            "ALTER TABLE alat_dokumen ADD COLUMN konfirmasi_syarat_5 TINYINT(1) DEFAULT 0",
            "ALTER TABLE alat_dokumen ADD COLUMN revisi_upload_1 DATETIME NULL",
            "ALTER TABLE alat_dokumen ADD COLUMN revisi_upload_2 DATETIME NULL",
            "ALTER TABLE alat_dokumen ADD COLUMN revisi_upload_3 DATETIME NULL",
            "ALTER TABLE alat_dokumen ADD COLUMN revisi_upload_4 DATETIME NULL",
            "ALTER TABLE alat_dokumen ADD COLUMN revisi_upload_5 DATETIME NULL",
            "ALTER TABLE alat_dokumen ADD COLUMN nama_file_1 VARCHAR(255) NULL",
            "ALTER TABLE alat_dokumen ADD COLUMN nama_file_2 VARCHAR(255) NULL",
            "ALTER TABLE alat_dokumen ADD COLUMN nama_file_3 VARCHAR(255) NULL",
            "ALTER TABLE alat_dokumen ADD COLUMN nama_file_4 VARCHAR(255) NULL",
            "ALTER TABLE alat_dokumen ADD COLUMN nama_file_5 VARCHAR(255) NULL",
            "ALTER TABLE alat_dokumen ADD COLUMN nama_file_acak_1 VARCHAR(255) NULL",
            "ALTER TABLE alat_dokumen ADD COLUMN nama_file_acak_2 VARCHAR(255) NULL",
            "ALTER TABLE alat_dokumen ADD COLUMN nama_file_acak_3 VARCHAR(255) NULL",
            "ALTER TABLE alat_dokumen ADD COLUMN nama_file_acak_4 VARCHAR(255) NULL",
            "ALTER TABLE alat_dokumen ADD COLUMN nama_file_acak_5 VARCHAR(255) NULL",
            // ─── Migrasi Nomor Identitas Permohonan ──────────────────────────
            "ALTER TABLE alat_dokumen ADD COLUMN no_identitas VARCHAR(100) NULL AFTER id",
        ];
        foreach ($auditMigrations as $sql) {
            try { $this->db->exec($sql); } catch (\Exception $e) {}
        }
    }

    // ─── Upload Dokumen Permohonan Alat ──────────────────────────────────────────

    public function uploadDokumenPermohonan($f3)
    {
        header('Content-Type: application/json; charset=utf-8');
        if (!$f3->get('SESSION.user_id')) {
            echo json_encode(['success' => false, 'message' => 'Unauthorized']);
            return;
        }

        $userId = $f3->get('SESSION.user_id');

        try {
            if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
                echo json_encode(['success' => false, 'message' => 'Gagal mengunggah file.']);
                return;
            }

            $file = $_FILES['file'];
            
            // Fallback jika ekstensi fileinfo tidak aktif di server
            if (function_exists('mime_content_type')) {
                $fileType = mime_content_type($file['tmp_name']);
            } else {
                $fileType = $file['type'];
            }
            
            if ($fileType !== 'application/pdf') {
                echo json_encode(['success' => false, 'message' => 'Hanya format PDF yang diperbolehkan.']);
                return;
            }

            if ($file['size'] > 10 * 1024 * 1024) {
                echo json_encode(['success' => false, 'message' => 'Ukuran file maksimal 10MB.']);
                return;
            }

            $orderIdsJson = $f3->get('POST.order_ids');
            if (empty($orderIdsJson)) {
                echo json_encode(['success' => false, 'message' => 'Tidak ada order yang dipilih.']);
                return;
            }
            
            // Expected list of IDs
            $orderIds = json_decode($orderIdsJson, true);
            if (!is_array($orderIds) || empty($orderIds)) {
                echo json_encode(['success' => false, 'message' => 'Order tidak valid.']);
                return;
            }
            
            $id_permintaan_list = implode(',', $orderIds);

            $uploadDir = 'public/uploads/permohonan-alat/dokumen/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0777, true);
            }

            $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
            $randomName = bin2hex(random_bytes(16)) . '.' . $ext;
            $targetPath = $uploadDir . $randomName;

            if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
                echo json_encode(['success' => false, 'message' => 'Gagal menyimpan file ke server.']);
                return;
            }

            // Simpan data file ke tabel alat_dokumen
            $konfirmasiSyarat = (int)($f3->get('POST.konfirmasi_syarat') ?? 1);

            $dokumen = new DB\SQL\Mapper($this->db, 'alat_dokumen');
            $dokumen->id_pengguna = $userId;
            $dokumen->nama_file_asli = $file['name'];
            $dokumen->nama_file_acak = $randomName;
            $dokumen->lokasi_file = '/' . $targetPath;
            $dokumen->id_permintaan_list = $id_permintaan_list;
            $dokumen->status = 'pending';
            $dokumen->save();
            
            $docId = $dokumen->id;

            // Generate & simpan nomor identitas permohonan ke database
            $romans = ['01'=>'I','02'=>'II','03'=>'III','04'=>'IV','05'=>'V','06'=>'VI','07'=>'VII','08'=>'VIII','09'=>'IX','10'=>'X','11'=>'XI','12'=>'XII'];
            $mNow = date('m');
            $yNow = date('Y');
            $noIdentitas = $docId . '/kal/' . ($romans[$mNow] ?? $mNow) . '/' . $yNow;
            $this->db->exec("UPDATE alat_dokumen SET no_identitas = ? WHERE id = ?", [$noIdentitas, $docId]);

            // Untuk upload perdana, kita TIDAK mengisi slot _1 s/d _5
            // Kolom utama (nama_file_asli, dsb) sudah cukup untuk upload awal.

            // Ubah status alat_permintaan agar diketahui telah diajukan
            foreach ($orderIds as $oId) {
                $this->db->exec(
                    "UPDATE alat_permintaan SET status = 'menunggu_verifikasi' WHERE id = ? AND id_pengguna = ?",
                    [$oId, $userId]
                );
            }

            if ($this->notifikasiService) {
                $userData = $this->db->exec('SELECT nama_lengkap, username FROM users WHERE id = ?', [$userId]);
                $namaPelanggan = !empty($userData) ? ($userData[0]['nama_lengkap'] ?? $userData[0]['username'] ?? 'Pelanggan') : 'Pelanggan';
                
                $waMsg = "*Notifikasi Selport*\n"
                       . "Halo {nama_user}, terdapat unggahan Dokumen Permohonan Alat baru dari pelanggan:\n"
                       . "*Nama*: {$namaPelanggan}\n"
                       . "Silakan login ke sistem untuk melakukan verifikasi dokumen.";
                $this->notifikasiService->sendWhatsAppToKalibrasi($waMsg);
                $this->notifikasiService->sendWhatsAppToMitra($waMsg);
            }

            echo json_encode([
                'success' => true,
                'message' => 'Dokumen berhasil diunggah.'
            ]);
        } catch (\Throwable $e) {
            echo json_encode(['success' => false, 'message' => 'Terjadi kesalahan sistem: ' . $e->getMessage() . ' di baris ' . $e->getLine()]);
        }
    }

    // ─── List Dokumen Permohonan Alat ──────────────────────────────────────────────

    public function getDokumenList($f3)
    {
        header('Content-Type: application/json; charset=utf-8');
        if (!$f3->get('SESSION.user_id')) {
            echo json_encode(['success' => false, 'message' => 'Unauthorized']);
            return;
        }

        $userId = $f3->get('SESSION.user_id');

        try {
            $dokumen = $this->db->exec(
                'SELECT d.*
                 FROM alat_dokumen d
                 WHERE d.id_pengguna = ?
                 ORDER BY d.dibuat_pada DESC',
                [$userId]
            );

            $romans = ['01'=>'I','02'=>'II','03'=>'III','04'=>'IV','05'=>'V','06'=>'VI','07'=>'VII','08'=>'VIII','09'=>'IX','10'=>'X','11'=>'XI','12'=>'XII'];

            // Ambil kategori dari alat_permintaan berdasarkan id pertama di id_permintaan_list
            foreach ($dokumen as &$doc) {
                // Buat secure token on-the-fly
                $doc['preview_token'] = doc_make_token($doc['nama_file_acak'], 'permohonan-alat');
                for ($i = 1; $i <= 5; $i++) {
                    if (!empty($doc["nama_file_acak_$i"])) {
                        $doc["preview_token_$i"] = doc_make_token($doc["nama_file_acak_$i"], 'permohonan-alat');
                    }
                }
                
                // Gunakan no_identitas dari database; jika kosong (data lama), generate otomatis
                if (empty($doc['no_identitas'])) {
                    $m = date('m', strtotime($doc['dibuat_pada']));
                    $y = date('Y', strtotime($doc['dibuat_pada']));
                    $doc['no_identitas'] = $doc['id'] . '/kal/' . ($romans[$m] ?? $m) . '/' . $y;
                    // Backfill ke database agar data lama juga tersimpan
                    try {
                        $this->db->exec("UPDATE alat_dokumen SET no_identitas = ? WHERE id = ?", [$doc['no_identitas'], $doc['id']]);
                    } catch (\Exception $e) {}
                }
                
                $doc['kategori'] = '-';
                $doc['catatan'] = '-';
                $doc['proses_input'] = '-';
                if (!empty($doc['id_permintaan_list'])) {
                    $ids = explode(',', $doc['id_permintaan_list']);
                    $firstId = trim($ids[0]);
                    if ($firstId) {
                        $perm = $this->db->exec(
                            'SELECT kategori, catatan, nama_penanda_tangan FROM alat_permintaan WHERE id = ?',
                            [$firstId]
                        );
                        if ($perm && count($perm) > 0) {
                            $doc['kategori'] = $perm[0]['kategori'];
                            $doc['catatan']  = $perm[0]['catatan'] ?: '-';
                            if (trim($perm[0]['nama_penanda_tangan']) === '-') {
                                $doc['proses_input'] = 'Upload Langsung';
                            } else {
                                $doc['proses_input'] = 'Input Manual';
                            }
                        }
                    }
                }
            }
            unset($doc);

            echo json_encode(['success' => true, 'data' => $dokumen]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => 'Gagal memuat dokumen: ' . $e->getMessage()]);
        }
    }

    public function reuploadDokumen($f3)
    {
        header('Content-Type: application/json; charset=utf-8');
        if (!$f3->get('SESSION.user_id')) {
            echo json_encode(['success' => false, 'message' => 'Unauthorized']);
            return;
        }

        $userId = $f3->get('SESSION.user_id');
        $docId = $f3->get('POST.doc_id');
        $file = $f3->get('FILES.document');

        if (empty($docId) || empty($file) || $file['error'] !== UPLOAD_ERR_OK) {
            echo json_encode(['success' => false, 'message' => 'Data tidak lengkap atau file bermasalah.']);
            return;
        }

        if (function_exists('mime_content_type')) {
            $fileType = mime_content_type($file['tmp_name']);
        } else {
            $fileType = $file['type'];
        }

        if ($fileType !== 'application/pdf') {
            echo json_encode(['success' => false, 'message' => 'Hanya file PDF yang diperbolehkan.']);
            return;
        }

        if ($file['size'] > 10 * 1024 * 1024) {
            echo json_encode(['success' => false, 'message' => 'Ukuran file maksimal 10MB.']);
            return;
        }

        $doc = $this->db->exec('SELECT * FROM alat_dokumen WHERE id = ? AND id_pengguna = ?', [$docId, $userId]);
        if (!$doc || count($doc) === 0) {
            echo json_encode(['success' => false, 'message' => 'Dokumen tidak ditemukan atau bukan milik Anda.']);
            return;
        }
        $doc = $doc[0];

        // Hapus file lama jika ada (opsional)
        $uploadDir = 'public/uploads/permohonan-alat/dokumen/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }

        $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
        $randomName = bin2hex(random_bytes(16)) . '.' . $ext;
        $targetPath = $uploadDir . $randomName;

        if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
            echo json_encode(['success' => false, 'message' => 'Gagal menyimpan file ke server.']);
            return;
        }

        try {
            $slot = 1;
            for ($i = 1; $i <= 5; $i++) {
                if (empty($doc["nama_file_acak_$i"])) {
                    $slot = $i;
                    break;
                }
            }
            $konfirmasiSyarat = (int)($f3->get('POST.konfirmasi_syarat') ?? 1);

            if ($slot >= 1 && $slot <= 5 && empty($doc["nama_file_acak_5"])) {
                $colKonfirmasi = "konfirmasi_syarat_{$slot}";
                $colRevisi     = "revisi_upload_{$slot}";
                $colNamaFile   = "nama_file_{$slot}";
                $colNamaFileAcak = "nama_file_acak_{$slot}";

                $oldAsli = $doc['nama_file_asli'];
                $oldAcak = $doc['nama_file_acak'];

                $this->db->exec(
                    "UPDATE alat_dokumen 
                     SET nama_file_asli = ?, 
                         nama_file_acak = ?, 
                         lokasi_file = ?,
                         status = 'pending',
                         {$colKonfirmasi} = ?,
                         {$colRevisi} = NOW(),
                         {$colNamaFile} = ?,
                         {$colNamaFileAcak} = ?
                     WHERE id = ?",
                    [$file['name'], $randomName, '/' . $targetPath, $konfirmasiSyarat, $oldAsli, $oldAcak, $docId]
                );
            } else {
                // Slot melebihi batas 5, update minimum saja
                $this->db->exec(
                    "UPDATE alat_dokumen 
                     SET nama_file_asli = ?, nama_file_acak = ?, lokasi_file = ?, status = 'pending'
                     WHERE id = ?",
                    [$file['name'], $randomName, '/' . $targetPath, $docId]
                );
            }

            // Update status permohonan ke menunggu verifikasi
            if (!empty($doc['id_permintaan_list'])) {
                $ids = array_filter(array_map('trim', explode(',', $doc['id_permintaan_list'])));
                if (!empty($ids)) {
                    $placeholders = implode(',', array_fill(0, count($ids), '?'));
                    $params = array_merge(['menunggu_verifikasi'], $ids);
                    $this->db->exec("UPDATE alat_permintaan SET status = ? WHERE id IN ($placeholders)", $params);
                }
            }

            if ($this->notifikasiService) {
                $this->notifikasiService->create(
                    $userId,
                    'Revisi Diajukan',
                    "Revisi dokumen untuk Permohonan Alat #TLR-{$docId} berhasil dikirim dan sedang ditinjau ulang.",
                    'tool_request',
                    '/permohonanalat'
                );

                $userData = $this->db->exec('SELECT nama_lengkap, username FROM users WHERE id = ?', [$userId]);
                $namaPelanggan = !empty($userData) ? ($userData[0]['nama_lengkap'] ?? $userData[0]['username'] ?? 'Pelanggan') : 'Pelanggan';
                
                $waMsg = "*Notifikasi Selport*\n"
                       . "Halo {nama_user}, terdapat perbaikan Dokumen Permohonan Alat dari pelanggan:\n"
                       . "*Nama*: {$namaPelanggan}\n"
                       . "Silakan login ke sistem untuk melakukan verifikasi ulang.";
                $this->notifikasiService->sendWhatsAppToKalibrasi($waMsg);
                $this->notifikasiService->sendWhatsAppToMitra($waMsg);
            }

            echo json_encode(['success' => true, 'message' => 'Dokumen berhasil diunggah ulang.']);
        } catch (\Throwable $e) {
            echo json_encode(['success' => false, 'message' => 'Terjadi kesalahan: ' . $e->getMessage() . ' di baris ' . $e->getLine()]);
        }
    }

    // ─── Upload Langsung (Tanpa Draft Permohonan) ────────────────────────────────

    public function uploadLangsung($f3)
    {
        header('Content-Type: application/json; charset=utf-8');
        if (!$f3->get('SESSION.user_id')) {
            echo json_encode(['success' => false, 'message' => 'Unauthorized']);
            return;
        }

        $userId = $f3->get('SESSION.user_id');

        try {
            $file = $_FILES['document'] ?? null;
            if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
                echo json_encode(['success' => false, 'message' => 'Gagal mengunggah file atau tidak ada file yang dipilih.']);
                return;
            }

            // Validasi tipe file
            if (function_exists('mime_content_type')) {
                $fileType = mime_content_type($file['tmp_name']);
            } else {
                $fileType = $file['type'];
            }

            $allowedMimeTypes = [
                'application/pdf'
            ];
            
            $allowedExts = ['pdf'];
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

            if (!in_array($fileType, $allowedMimeTypes) && !in_array($ext, $allowedExts)) {
                echo json_encode(['success' => false, 'message' => 'Hanya format PDF yang diperbolehkan.']);
                return;
            }

            if ($file['size'] > 10 * 1024 * 1024) {
                echo json_encode(['success' => false, 'message' => 'Ukuran file maksimal 10MB.']);
                return;
            }

            $catatan   = trim($f3->get('POST.catatan') ?? '');
            $reuploadDocId = $f3->get('POST.reupload_doc_id');

            // Buat upload directory
            $uploadDir = 'public/uploads/permohonan-alat/dokumen/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0777, true);
            }

            $ext        = pathinfo($file['name'], PATHINFO_EXTENSION);
            $randomName = bin2hex(random_bytes(16)) . '.' . $ext;
            $targetPath = $uploadDir . $randomName;

            if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
                echo json_encode(['success' => false, 'message' => 'Gagal menyimpan file ke server.']);
                return;
            }

            if (!empty($reuploadDocId)) {
                // Update dokumen yang ada (re-upload)
                $doc = $this->db->exec('SELECT * FROM alat_dokumen WHERE id = ? AND id_pengguna = ?', [$reuploadDocId, $userId]);
                if (!$doc || count($doc) === 0) {
                    echo json_encode(['success' => false, 'message' => 'Dokumen asli tidak ditemukan atau bukan milik Anda.']);
                    return;
                }
                $doc = $doc[0];

                // Simpan file lama ke slot history SEBELUM ditimpa, dan catat tanggal re-upload
                $slot = 1;
                for ($i = 1; $i <= 5; $i++) {
                    if (empty($doc["nama_file_acak_$i"])) {
                        $slot = $i;
                        break;
                    }
                }
                
                if ($slot >= 1 && $slot <= 5 && empty($doc["nama_file_acak_5"])) {
                    $colNamaFile     = "nama_file_{$slot}";
                    $colNamaFileAcak = "nama_file_acak_{$slot}";
                    $colRevisiUpload = "revisi_upload_{$slot}";
                    $this->db->exec(
                        "UPDATE alat_dokumen SET {$colNamaFile} = ?, {$colNamaFileAcak} = ?, {$colRevisiUpload} = NOW() WHERE id = ?",
                        [$doc['nama_file_asli'], $doc['nama_file_acak'], $reuploadDocId]
                    );
                }

                // Update dokumen dengan file baru; JANGAN timpa catatan / dibuat_pada / jumlah_revisi
                $this->db->exec(
                    "UPDATE alat_dokumen SET nama_file_asli = ?, nama_file_acak = ?, lokasi_file = ?, status = 'pending' WHERE id = ?",
                    [$file['name'], $randomName, '/' . $targetPath, $reuploadDocId]
                );

                // Update status alat_permintaan dan APPEND catatan jika ada
                if (!empty($doc['id_permintaan_list'])) {
                    $orderIds = explode(',', $doc['id_permintaan_list']);
                    foreach ($orderIds as $idPermintaan) {
                        if (!empty($catatan)) {
                            $perm = $this->db->exec('SELECT catatan FROM alat_permintaan WHERE id = ?', [trim($idPermintaan)]);
                            $oldCatatan = $perm[0]['catatan'] ?? '';
                            $newCatatan = $oldCatatan;
                            if ($oldCatatan !== '' && $oldCatatan !== '-') {
                                $newCatatan .= "\n\n---\n\n";
                            } else {
                                $newCatatan = '';
                            }
                            $newCatatan .= "Perbaikan " . $slot . ":\n" . $catatan;
                            $this->db->exec('UPDATE alat_permintaan SET catatan = ?, status = ? WHERE id = ?', [$newCatatan, 'menunggu_verifikasi', trim($idPermintaan)]);
                        } else {
                            $this->db->exec("UPDATE alat_permintaan SET status = 'menunggu_verifikasi' WHERE id = ?", [trim($idPermintaan)]);
                        }
                    }
                }

                // Gunakan $doc (array lama) untuk relasi notifikasi
                $dokumen = (object)['id_permintaan_list' => $doc['id_permintaan_list']];

                $message = 'Dokumen revisi berhasil diunggah.';
                $waContext = "Revisi Dokumen Permohonan Alat (Upload Langsung)";
            } else {
                // Buat dummy alat_permintaan agar relasi tetap terjaga
                // Dengan kategori 'Permohonan Kalibrasi' dan catatan dari user
                $this->db->exec(
                    "INSERT INTO alat_permintaan (id_pengguna, status, kategori, catatan, nama_penanda_tangan, jabatan) VALUES (?, 'menunggu_verifikasi', 'Permohonan Kalibrasi', ?, '-', '-')",
                    [$userId, $catatan]
                );
                $orderId = $this->db->exec("SELECT LAST_INSERT_ID() as id")[0]['id'];

                // Simpan dokumen
                $dokumen = new DB\SQL\Mapper($this->db, 'alat_dokumen');
                $dokumen->id_pengguna       = $userId;
                $dokumen->nama_file_asli    = $file['name'];
                $dokumen->nama_file_acak    = $randomName;
                $dokumen->lokasi_file       = '/' . $targetPath;
                $dokumen->id_permintaan_list = (string)$orderId;
                $dokumen->status            = 'pending';
                $dokumen->konfirmasi_syarat = 'Y';
                $dokumen->save();

                // Generate & simpan nomor identitas permohonan ke database
                $romansMap = ['01'=>'I','02'=>'II','03'=>'III','04'=>'IV','05'=>'V','06'=>'VI','07'=>'VII','08'=>'VIII','09'=>'IX','10'=>'X','11'=>'XI','12'=>'XII'];
                $mNow = date('m');
                $yNow = date('Y');
                $noIdent = $dokumen->id . '/kal/' . ($romansMap[$mNow] ?? $mNow) . '/' . $yNow;
                $this->db->exec("UPDATE alat_dokumen SET no_identitas = ? WHERE id = ?", [$noIdent, $dokumen->id]);

                $message = 'Dokumen berhasil diunggah.';
                $waContext = "Upload Langsung Dokumen Permohonan Alat";
            }

            if ($this->notifikasiService) {
                $userData = $this->db->exec('SELECT nama_lengkap, username FROM users WHERE id = ?', [$userId]);
                $namaPelanggan = !empty($userData) ? ($userData[0]['nama_lengkap'] ?? $userData[0]['username'] ?? 'Pelanggan') : 'Pelanggan';

                $waMsg = "*Notifikasi Selport*\n"
                       . "Terdapat {$waContext} dari pelanggan:\n"
                       . "*Nama*: {$namaPelanggan}\n"
                       . "Silakan login ke sistem untuk melakukan verifikasi dokumen.";
                $this->notifikasiService->sendWhatsAppToKalibrasi($waMsg);
                $this->notifikasiService->sendWhatsAppToMitra($waMsg);
            }

            echo json_encode(['success' => true, 'message' => $message]);
        } catch (\Throwable $e) {
            echo json_encode(['success' => false, 'message' => 'Terjadi kesalahan sistem: ' . $e->getMessage()]);
        }
    }
}
