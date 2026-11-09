<?php

class PermohonanPengecualianController
{
    private $db;
    private $notifikasiService;

    public function __construct($db, $notifikasiService = null)
    {
        $this->db = $db;
        $this->notifikasiService = $notifikasiService;
    }

    // ─── HS Code ──────────────────────────────────────────────────────────────

    public function getHsCodes($f3)
    {
        header('Content-Type: application/json; charset=utf-8');
        if (!$f3->get('SESSION.user_id')) {
            echo json_encode(['success' => false, 'message' => 'Unauthorized']);
            return;
        }

        $permenperin = trim($f3->get('GET.permenperin') ?? '');

        try {
            try {
                $this->db->exec("
                    CREATE TABLE IF NOT EXISTS hs_code (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        permenperin VARCHAR(255) NOT NULL,
                        kode_hs VARCHAR(255) NOT NULL,
                        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
                ");
            } catch (\Exception $e) {}

            if (!empty($permenperin)) {
                $dbPermenperin  = $permenperin;
                $altPermenperin = $permenperin;
                if ($permenperin == 'Permenperin No.63 Tahun 2024') {
                    $dbPermenperin = 'Permenperin 63/2024';
                } elseif ($permenperin == 'Permenperin No.6 Tahun 2025') {
                    $dbPermenperin = 'Permenperin 6/2025';
                }
                $data = $this->db->exec(
                    'SELECT id, kode_hs FROM hs_code WHERE permenperin = ? OR permenperin = ? ORDER BY kode_hs ASC',
                    [$dbPermenperin, $altPermenperin]
                );
            } else {
                $data = $this->db->exec('SELECT id, permenperin, kode_hs FROM hs_code ORDER BY permenperin, kode_hs ASC');
            }

            echo json_encode(['success' => true, 'data' => $data, 'count' => count($data)]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => 'Gagal memuat data HS Code: ' . $e->getMessage()]);
        }
    }

    // ─── Permintaan Pengecualian ───────────────────────────────────────────────

    public function getPengecualianList($f3)
    {
        header('Content-Type: application/json; charset=utf-8');
        if (!$f3->get('SESSION.user_id')) {
            echo json_encode(['success' => false, 'message' => 'Unauthorized']);
            return;
        }

        $userId = $f3->get('SESSION.user_id');

        try {
            $orders = $this->db->exec(
                'SELECT * FROM pengecualian_permintaan WHERE user_id = ? ORDER BY created_at DESC',
                [$userId]
            );

            $includeAll = $f3->get('GET.include_all');
            if (!$includeAll) {
                $docs = $this->db->exec(
                    "SELECT permintaan_ids FROM pengecualian_dokumen WHERE user_id = ?",
                    [$userId]
                );
                $uploadedIds = [];
                foreach ($docs as $doc) {
                    if (!empty($doc['permintaan_ids'])) {
                        $ids         = explode(',', $doc['permintaan_ids']);
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
                    'SELECT * FROM pengecualian_items WHERE permintaan_id = ? ORDER BY created_at ASC',
                    [$order['id']]
                );
                $order['items'] = $items;
            }

            echo json_encode(['success' => true, 'data' => $orders]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => 'Gagal memuat data: ' . $e->getMessage()]);
        }
    }

    public function savePengecualianOrder($f3)
    {
        header('Content-Type: application/json; charset=utf-8');
        if (!$f3->get('SESSION.user_id')) {
            echo json_encode(['success' => false, 'message' => 'Unauthorized']);
            return;
        }

        $userId = $f3->get('SESSION.user_id');
        $id     = $f3->get('POST.id');

        try {
            try {
                $this->db->exec("
                    CREATE TABLE IF NOT EXISTS pengecualian_permintaan (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        user_id INT NOT NULL,
                        status VARCHAR(50) DEFAULT 'Draft',
                        lingkup VARCHAR(255),
                        jenis_api VARCHAR(50),
                        nama_penanda_tangan VARCHAR(255),
                        jabatan VARCHAR(255),
                        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
                ");
            } catch (\Exception $e) {}

            $lingkup            = trim($f3->get('POST.lingkup'));
            $jenisApi           = trim($f3->get('POST.jenis_api') ?? '');
            $namaPenandaTangan  = trim($f3->get('POST.nama_penanda_tangan') ?? '');
            $jabatan            = trim($f3->get('POST.jabatan') ?? '');

            if ($id) {
                $existing = $this->db->exec('SELECT id FROM pengecualian_permintaan WHERE id = ? AND user_id = ?', [$id, $userId]);
                if (empty($existing)) {
                    echo json_encode(['success' => false, 'message' => 'Data tidak ditemukan']);
                    return;
                }
                $this->db->exec(
                    'UPDATE pengecualian_permintaan SET lingkup = ?, jenis_api = ?, nama_penanda_tangan = ?, jabatan = ?, updated_at = NOW() WHERE id = ? AND user_id = ?',
                    [$lingkup, $jenisApi, $namaPenandaTangan, $jabatan, $id, $userId]
                );
                $orderId = $id;
            } else {
                $this->db->exec(
                    'INSERT INTO pengecualian_permintaan (user_id, status, lingkup, jenis_api, nama_penanda_tangan, jabatan, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())',
                    [$userId, 'Draft', $lingkup, $jenisApi, $namaPenandaTangan, $jabatan]
                );
                $orderId = $this->db->lastinsertid();
            }

            echo json_encode(['success' => true, 'message' => 'Permintaan berhasil disimpan', 'id' => $orderId]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => 'Gagal menyimpan permintaan: ' . $e->getMessage()]);
        }
    }

    public function deletePengecualianOrder($f3)
    {
        header('Content-Type: application/json; charset=utf-8');
        if (!$f3->get('SESSION.user_id')) {
            echo json_encode(['success' => false, 'message' => 'Unauthorized']);
            return;
        }

        $userId = $f3->get('SESSION.user_id');
        $id     = $f3->get('POST.id');

        try {
            $order = new DB\SQL\Mapper($this->db, 'pengecualian_permintaan');
            $order->load(['id = ? AND user_id = ?', $id, $userId]);
            if (!$order->dry()) $order->erase();
            echo json_encode(['success' => true, 'message' => 'Permintaan berhasil dihapus']);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => 'Gagal menghapus permintaan: ' . $e->getMessage()]);
        }
    }

    // ─── Item Pengecualian ────────────────────────────────────────────────────

    public function savePengecualianItem($f3)
    {
        header('Content-Type: application/json; charset=utf-8');
        if (!$f3->get('SESSION.user_id')) {
            echo json_encode(['success' => false, 'message' => 'Unauthorized']);
            return;
        }

        $userId        = $f3->get('SESSION.user_id');
        $permintaan_id = $f3->get('POST.permintaan_id');
        $id            = $f3->get('POST.id');

        try {
            try {
                $this->db->exec("
                    CREATE TABLE IF NOT EXISTS pengecualian_items (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        permintaan_id INT NOT NULL,
                        hs_code VARCHAR(255),
                        spesifikasi VARCHAR(255),
                        jenis_kertas VARCHAR(255),
                        varian_gramatur VARCHAR(255),
                        penggunaan VARCHAR(255),
                        merk VARCHAR(255),
                        negara_asal VARCHAR(255),
                        nama_pabrik VARCHAR(255),
                        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                        FOREIGN KEY (permintaan_id) REFERENCES pengecualian_permintaan(id) ON DELETE CASCADE
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
                ");
            } catch (\Exception $e) {
                try { $this->db->exec("ALTER TABLE pengecualian_items ADD COLUMN hs_code TEXT"); } catch (\Exception $e2) {}
            }

            $order = new DB\SQL\Mapper($this->db, 'pengecualian_permintaan');
            $order->load(['id = ? AND user_id = ?', $permintaan_id, $userId]);
            if ($order->dry()) {
                echo json_encode(['success' => false, 'message' => 'Permintaan tidak valid']);
                return;
            }

            $item = new DB\SQL\Mapper($this->db, 'pengecualian_items');
            if ($id) {
                $item->load(['id = ? AND permintaan_id = ?', $id, $permintaan_id]);
                if ($item->dry()) {
                    echo json_encode(['success' => false, 'message' => 'Item tidak ditemukan']);
                    return;
                }
            } else {
                $item->permintaan_id = $permintaan_id;
            }

            $item->hs_code         = trim($f3->get('POST.hs_code'));
            $item->spesifikasi     = trim($f3->get('POST.spesifikasi'));
            $item->jenis_kertas    = trim($f3->get('POST.jenis_kertas'));
            $item->varian_gramatur = trim($f3->get('POST.varian_gramatur'));
            $item->penggunaan      = trim($f3->get('POST.penggunaan'));
            $item->merk            = trim($f3->get('POST.merk'));
            $item->negara_asal     = trim($f3->get('POST.negara_asal'));
            $item->nama_pabrik     = trim($f3->get('POST.nama_pabrik'));
            $item->save();

            echo json_encode(['success' => true, 'message' => 'Item berhasil disimpan', 'id' => $item->id]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => 'Gagal menyimpan item: ' . $e->getMessage()]);
        }
    }

    public function deletePengecualianItem($f3)
    {
        header('Content-Type: application/json; charset=utf-8');
        if (!$f3->get('SESSION.user_id')) {
            echo json_encode(['success' => false, 'message' => 'Unauthorized']);
            return;
        }

        $id     = $f3->get('POST.id');
        $userId = $f3->get('SESSION.user_id');

        try {
            $item = new DB\SQL\Mapper($this->db, 'pengecualian_items');
            $item->load(['id = ?', $id]);
            if (!$item->dry()) {
                $order = new DB\SQL\Mapper($this->db, 'pengecualian_permintaan');
                $order->load(['id = ? AND user_id = ?', $item->permintaan_id, $userId]);
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

    // ─── Cetak PDF ────────────────────────────────────────────────────────────

    public function cetakPdfPengecualian($f3)
    {
        if (!$f3->get('SESSION.user_id')) { $f3->reroute('/'); exit; }

        $userId = $f3->get('SESSION.user_id');
        $user   = new DB\SQL\Mapper($this->db, 'users');
        $user->load(['id = ?', $userId]);

        $companyData = [];
        if (!empty($user->company_id)) {
            $company = new DB\SQL\Mapper($this->db, 'companies');
            $company->load(['id = ?', $user->company_id]);
            if (!$company->dry()) {
                $locArr  = array_filter([$company->provinsi_perusahaan ?? '', $company->kabupaten_perusahaan ?? '', $company->kecamatan_perusahaan ?? '']);
                $lokasi  = implode(', ', $locArr);
                $getValue = function($v, $d = '-') { return (!empty(trim($v ?? ''))) ? trim($v) : $d; };
                $companyData = [
                    'nama_perusahaan'  => $getValue($company->nama_perusahaan),
                    'alamat_perusahaan'=> $getValue($company->alamat_perusahaan),
                    'lokasi_perusahaan'=> $lokasi ?: '-',
                    'kodepos_perusahaan'=> $getValue($company->kodepos_perusahaan),
                    'email_perusahaan' => $getValue($company->email_perusahaan),
                    'wa_perusahaan'    => $getValue($company->wa_perusahaan),
                    'fax_perusahaan'   => $getValue($company->fax_perusahaan),
                ];
            }
        } else {
            $locArr  = array_filter([$user->provinsi_tinggal ?? '', $user->kabupaten_tinggal ?? '', $user->kecamatan_tinggal ?? '']);
            $lokasi  = implode(', ', $locArr);
            $companyData = [
                'nama_perusahaan'   => 'Perorangan',
                'alamat_perusahaan' => $user->alamat_tinggal ?? '-',
                'lokasi_perusahaan' => $lokasi ?: '-',
                'kodepos_perusahaan'=> $user->kodepos_tinggal ?? '-',
                'email_perusahaan'  => $user->email ?? '-',
                'wa_perusahaan'     => '-',
                'fax_perusahaan'    => '-',
            ];
        }
        $companyData['nama_lengkap']  = $user->nama_lengkap ?? $f3->get('SESSION.nama_lengkap') ?? '-';
        $companyData['no_whatsapp']   = $user->no_whatsapp ?? '-';

        $orderId       = $f3->get('GET.id');
        $orderIdsParam = $f3->get('GET.ids');

        if (!$orderId && !$orderIdsParam) {
            echo json_encode(['success' => false, 'message' => 'Parameter id atau ids diperlukan']);
            return;
        }

        $groups    = [];
        $orderData = [];

        if ($orderIdsParam) {
            $idsArray = array_filter(array_map('intval', explode(',', $orderIdsParam)));
            if (empty($idsArray)) { echo json_encode(['success' => false, 'message' => 'Format ID tidak valid']); return; }
            $placeholders = implode(',', array_fill(0, count($idsArray), '?'));
            $params       = array_merge($idsArray, [$userId]);
            $orderData    = $this->db->exec("SELECT * FROM pengecualian_permintaan WHERE id IN ($placeholders) AND user_id = ?", $params);
        } else {
            $orderData = $this->db->exec('SELECT * FROM pengecualian_permintaan WHERE id = ? AND user_id = ?', [$orderId, $userId]);
        }

        if ($orderData && count($orderData) > 0) {
            foreach ($orderData as $order) {
                $itemsData = $this->db->exec('SELECT * FROM pengecualian_items WHERE permintaan_id = ? ORDER BY created_at ASC', [$order['id']]);
                $groups[]  = [
                    'lingkup'            => $order['lingkup'] ?: 'Tidak Ditentukan',
                    'spesifikasi'        => $order['spesifikasi'] ?: 'Tidak Ditentukan',
                    'jenis_api'          => $order['jenis_api'] ?? '',
                    'nama_penanda_tangan'=> $order['nama_penanda_tangan'] ?? '',
                    'jabatan'            => $order['jabatan'] ?? '',
                    'items'              => $itemsData ?: [],
                ];
            }
        } else {
            echo json_encode(['success' => false, 'message' => 'Data order tidak ditemukan']);
            return;
        }

        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => true, 'data' => ['customer' => $companyData, 'groups' => array_values($groups), 'tanggal_cetak' => date('d M Y H:i') . ' WIB']]);
    }

    // ─── Dokumen Pengecualian ─────────────────────────────────────────────────

    public function uploadPengecualianDokumen($f3)
    {
        header('Content-Type: application/json; charset=utf-8');
        if (!$f3->get('SESSION.user_id')) { echo json_encode(['success' => false, 'message' => 'Unauthorized']); return; }

        $userId = $f3->get('SESSION.user_id');
        $files  = $f3->get('FILES');

        if (!isset($files['document']) || $files['document']['error'] !== UPLOAD_ERR_OK) {
            echo json_encode(['success' => false, 'message' => 'File tidak ditemukan atau terjadi kesalahan saat upload']);
            return;
        }

        $uploadDir = 'public/uploads/pengecualian/';
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);

        $file       = $files['document'];
        $ext        = pathinfo($file['name'], PATHINFO_EXTENSION);
        $newName    = $userId . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        $targetPath = $uploadDir . $newName;

        if (move_uploaded_file($file['tmp_name'], $targetPath)) {
            try {
                foreach (['permintaan_ids VARCHAR(255) NULL', 'revision_count INT DEFAULT 0', 'konfirmasi_syarat VARCHAR(1) DEFAULT NULL'] as $col) {
                    try { $this->db->exec("ALTER TABLE pengecualian_dokumen ADD COLUMN $col"); } catch (\Exception $e) {}
                }

                $doc                 = new DB\SQL\Mapper($this->db, 'pengecualian_dokumen');
                $doc->user_id        = $userId;
                $doc->file_name      = $newName;
                $doc->original_name  = $file['name'];
                $doc->file_path      = $targetPath;
                $doc->status         = 'pending';

                $permintaanIds = $f3->get('POST.permintaan_ids');
                if ($permintaanIds) $doc->permintaan_ids = $permintaanIds;

                $konfirmasiSyarat = $f3->get('POST.konfirmasi_syarat');
                if ($konfirmasiSyarat) $doc->konfirmasi_syarat = $konfirmasiSyarat;

                $doc->save();

                if ($this->notifikasiService) {
                    $userData = $this->db->exec('SELECT nama_lengkap, username, company_id FROM users WHERE id = ?', [$userId]);
                    $namaPelanggan = !empty($userData) ? ($userData[0]['nama_lengkap'] ?? $userData[0]['username'] ?? 'Pelanggan') : 'Pelanggan';
                    
                    $namaPerusahaan = '-';
                    if (!empty($userData) && !empty($userData[0]['company_id'])) {
                        $companyData = $this->db->exec('SELECT nama_perusahaan FROM companies WHERE id = ?', [$userData[0]['company_id']]);
                        if (!empty($companyData)) {
                            $namaPerusahaan = $companyData[0]['nama_perusahaan'];
                        }
                    }

                    $waMsg = "*Notifikasi Selport*\n"
                           . "Halo {nama_user}, terdapat Permohonan Pengecualian Dokumen baru dari pelanggan:\n"
                           . "*Nama*: {$namaPelanggan}\n"
                           . "*Perusahaan*: {$namaPerusahaan}\n"
                           . "Silakan login ke sistem untuk meninjau permohonan tersebut.";
                    
                    $this->notifikasiService->sendWhatsAppToMitra($waMsg);
                }

                echo json_encode(['success' => true, 'message' => 'Dokumen berhasil diunggah']);
            } catch (Exception $e) {
                if (file_exists($targetPath)) unlink($targetPath);
                echo json_encode(['success' => false, 'message' => 'Gagal menyimpan ke database: ' . $e->getMessage()]);
            }
        } else {
            echo json_encode(['success' => false, 'message' => 'Gagal memindahkan file yang diunggah']);
        }
    }

    public function getPengecualianDokumenList($f3)
    {
        header('Content-Type: application/json; charset=utf-8');
        if (!$f3->get('SESSION.user_id')) { echo json_encode(['success' => false, 'message' => 'Unauthorized']); return; }

        $userId = $f3->get('SESSION.user_id');

        try {
            try { $this->db->exec("ALTER TABLE pengecualian_dokumen ADD COLUMN revision_count INT DEFAULT 0"); } catch (\Exception $e) {}
            try { $this->db->exec("ALTER TABLE pengecualian_dokumen ADD COLUMN surat_penawaran VARCHAR(255) DEFAULT NULL"); } catch (\Exception $e) {}
            try { $this->db->exec("ALTER TABLE pengecualian_dokumen ADD COLUMN invoice VARCHAR(255) DEFAULT NULL"); } catch (\Exception $e) {}

            $data = $this->db->exec(
                "SELECT id, file_name, file_path, original_name, created_at, status, alasan, permintaan_ids, COALESCE(revision_count, 0) as revision_count, surat_penawaran, invoice FROM pengecualian_dokumen WHERE user_id = ? ORDER BY created_at DESC",
                [$userId]
            );

            foreach ($data as &$item) {
                $item['created_at_formatted'] = function_exists('formatTanggalIndo') ? formatTanggalIndo($item['created_at']) : date('d M Y', strtotime($item['created_at']));
                $item['revision_count']       = (int) $item['revision_count'];

                // Build secure preview_token for PDF preview (no direct file URL exposed)
                if (!empty($item['file_name']) && function_exists('doc_make_token')) {
                    $item['preview_token'] = doc_make_token($item['file_name'], 'pengecualian-dokumen-lokal');
                } else {
                    $item['preview_token'] = null;
                }

                if (!empty($item['surat_penawaran']) && function_exists('doc_make_token')) {
                    $item['surat_penawaran_token'] = doc_make_token($item['surat_penawaran'], 'pengecualian-mitra-sp');
                } else {
                    $item['surat_penawaran_token'] = null;
                }

                if (!empty($item['invoice']) && function_exists('doc_make_token')) {
                    $item['invoice_token'] = doc_make_token($item['invoice'], 'pengecualian-mitra-inv');
                } else {
                    $item['invoice_token'] = null;
                }

                // Remove sensitive file paths from response
                unset($item['file_name'], $item['file_path'], $item['surat_penawaran'], $item['invoice']);

                $lingkupList = [];
                if (!empty($item['permintaan_ids'])) {
                    $ids = array_filter(array_map('intval', explode(',', $item['permintaan_ids'])));
                    if (!empty($ids)) {
                        $placeholders = implode(',', array_fill(0, count($ids), '?'));
                        $perms        = $this->db->exec("SELECT lingkup FROM pengecualian_permintaan WHERE id IN ($placeholders)", $ids);
                        foreach ($perms as $p) { if (!empty($p['lingkup'])) $lingkupList[] = $p['lingkup']; }
                    }
                }
                $item['lingkup'] = implode(', ', array_unique($lingkupList));
            }

            echo json_encode(['success' => true, 'data' => $data]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => 'Gagal memuat dokumen: ' . $e->getMessage()]);
        }
    }

    public function reuploadPengecualianDokumen($f3)
    {
        header('Content-Type: application/json; charset=utf-8');
        if (!$f3->get('SESSION.user_id')) { echo json_encode(['success' => false, 'message' => 'Unauthorized']); return; }

        $userId = $f3->get('SESSION.user_id');
        $docId  = $f3->get('POST.doc_id');
        $files  = $f3->get('FILES');

        if (!$docId) { echo json_encode(['success' => false, 'message' => 'ID dokumen tidak valid']); return; }
        if (!isset($files['document']) || $files['document']['error'] !== UPLOAD_ERR_OK) {
            echo json_encode(['success' => false, 'message' => 'File tidak ditemukan atau terjadi kesalahan saat upload']);
            return;
        }

        try {
            $doc = new DB\SQL\Mapper($this->db, 'pengecualian_dokumen');
            for ($i = 1; $i <= 5; $i++) {
                try { $this->db->exec("ALTER TABLE pengecualian_dokumen ADD COLUMN revisi_upload_{$i} DATETIME DEFAULT NULL"); } catch (\Exception $e) {}
                try { $this->db->exec("ALTER TABLE pengecualian_dokumen ADD COLUMN nama_file_{$i} VARCHAR(500) DEFAULT NULL"); } catch (\Exception $e) {}
                try { $this->db->exec("ALTER TABLE pengecualian_dokumen ADD COLUMN konfirmasi_syarat_{$i} VARCHAR(1) DEFAULT NULL"); } catch (\Exception $e) {}
            }

            $doc->load(['id = ? AND user_id = ?', $docId, $userId]);
            if ($doc->dry()) { echo json_encode(['success' => false, 'message' => 'Dokumen tidak ditemukan']); return; }
            if ($doc->status !== 'rejected') {
                echo json_encode(['success' => false, 'message' => 'Hanya dokumen dengan status perbaikan yang dapat diupload ulang']);
                return;
            }

            $uploadDir = 'public/uploads/pengecualian/';
            if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);

            $file       = $files['document'];
            $ext        = pathinfo($file['name'], PATHINFO_EXTENSION);
            $newName    = $userId . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
            $targetPath = $uploadDir . $newName;

            if (move_uploaded_file($file['tmp_name'], $targetPath)) {
                if (!empty($doc->file_path) && file_exists($doc->file_path)) @unlink($doc->file_path);

                $revisionNum  = max(1, min(5, (int) ($doc->revision_count ?? 1)));
                $revisiField  = 'revisi_upload_' . $revisionNum;
                $namaField    = 'nama_file_'      . $revisionNum;
                $konfField    = 'konfirmasi_syarat_' . $revisionNum;
                $doc->$revisiField = date('Y-m-d H:i:s');
                $doc->$namaField   = $file['name'];

                $konfirmasiSyarat = $f3->get('POST.konfirmasi_syarat');
                if ($konfirmasiSyarat) $doc->$konfField = $konfirmasiSyarat;

                $doc->file_name     = $newName;
                $doc->original_name = $file['name'];
                $doc->file_path     = $targetPath;
                $doc->status        = 'pending';
                $doc->alasan        = null;
                $doc->save();

                if ($this->notifikasiService) {
                    $this->notifikasiService->create(
                        $userId,
                        'Revisi Diajukan',
                        "Revisi dokumen untuk Permohonan Pengecualian #EXC-{$docId} berhasil dikirim dan sedang ditinjau ulang.",
                        'exception_request',
                        '/permohonanpengecualian'
                    );
                }

                echo json_encode(['success' => true, 'message' => 'Dokumen berhasil diupload ulang']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Gagal memindahkan file']);
            }
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => 'Gagal upload ulang: ' . $e->getMessage()]);
        }
    }
}