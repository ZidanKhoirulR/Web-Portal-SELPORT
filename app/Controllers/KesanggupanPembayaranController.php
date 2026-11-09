<?php

class KesanggupanPembayaranController
{
    private $db;
    private $notifikasiService;

    public function __construct($db, $notifikasiService = null)
    {
        $this->db = $db;
        $this->notifikasiService = $notifikasiService;
    }

    /**
     * Helper untuk validasi file secara ketat (Security Patch)
     */
    private function validateFile($file, $allowedMimes, $allowedExts, $maxSize) {
        // 1. Cek error upload
        if ($file['error'] !== UPLOAD_ERR_OK) {
            return ['success' => false, 'message' => 'Terjadi kesalahan saat mengunggah file.'];
        }

        // 2. Cek ukuran
        if ($file['size'] > $maxSize) {
            $mb = $maxSize / (1024 * 1024);
            return ['success' => false, 'message' => "Ukuran file terlalu besar. Maksimal {$mb}MB."];
        }

        // 3. Cek ekstensi
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, $allowedExts)) {
            return ['success' => false, 'message' => "Ekstensi file .{$ext} tidak diizinkan. Gunakan: " . implode(', ', $allowedExts)];
        }

        // 4. Cek konten asli (MIME Type) menggunakan finfo
        if (!function_exists('finfo_open')) {
            return ['success' => true];
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);

        if ($mime === 'image/jpg') $mime = 'image/jpeg';

        if (!in_array($mime, $allowedMimes)) {
            return ['success' => false, 'message' => "Konten file tidak sesuai dengan format yang diizinkan (Isi file terdeteksi sebagai {$mime})."];
        }

        return ['success' => true];
    }

    // Download template kesanggupan (untuk pelanggan)
    public function downloadTemplateKesanggupan($f3) {
        $row = $this->db->exec(
            'SELECT * FROM template_files WHERE jenis = ? AND is_active = 1
             ORDER BY created_at DESC LIMIT 1',
            ['kesanggupan']
        );
        if (!$row) { $f3->error(404); return; }

        $file = __DIR__ . '/../../public/uploads/templates/' . $row[0]['nama_file'];
        if (!file_exists($file)) { $f3->error(404); return; }

        $ext = strtolower(pathinfo($row[0]['nama_file'], PATHINFO_EXTENSION));
        $mime = $ext === 'docx'
            ? 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
            : 'application/msword';

        header('Content-Type: ' . $mime);
        header('Content-Disposition: attachment; filename="' . $row[0]['nama_asli'] . '"');
        header('Content-Length: ' . filesize($file));
        readfile($file);
        exit;
    }

    public function getTemplateInfo($f3) {
        $row = $this->db->exec(
            'SELECT * FROM template_files WHERE jenis = ? AND is_active = 1
             ORDER BY created_at DESC LIMIT 1',
            ['kesanggupan']
        );
        header('Content-Type: application/json');
        if (!$row) {
            echo json_encode(['success' => false, 'data' => null]);
            return;
        }
        echo json_encode(['success' => true, 'data' => $row[0]]);
    }

    public function getKesanggupanStatus($f3) {
        $userId = $f3->get('SESSION.user_id');
        $row = $this->db->exec(
            'SELECT * FROM kesanggupan_uploads WHERE user_id = ? ORDER BY created_at DESC LIMIT 1',
            [$userId]
        );
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'data' => $row ? $row[0] : null]);
    }

    public function uploadKesanggupan($f3) {
        header('Content-Type: application/json');
        
        try {
            $userId = $f3->get('SESSION.user_id');
            if (!$userId) { 
                echo json_encode(['success' => false, 'message' => 'Silakan login terlebih dahulu.']);
                return;
            }

            // Lazy Migration — sudah ditangani oleh KeuanganController


            if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
                $errCode = $_FILES['file']['error'] ?? -1;
                echo json_encode(['success' => false, 'message' => 'File tidak valid. Kode Error: ' . $errCode]); 
                return;
            }

            $file = $_FILES['file'];
            $validation = $this->validateFile($file, [
                'application/pdf'
            ], ['pdf'], 10 * 1024 * 1024);

            if (!$validation['success']) {
                echo json_encode(['success' => false, 'message' => $validation['message']]);
                return;
            }

            $ext      = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            $namaAsli = $file['name'];
            $namaFile = 'kesanggupan_' . $userId . '_' . time() . '.' . $ext;
            $uploadDir = __DIR__ . '/../../public/uploads/kesanggupan/';
            if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

            if (!move_uploaded_file($file['tmp_name'], $uploadDir . $namaFile)) {
                echo json_encode(['success' => false, 'message' => 'Gagal memindahkan file ke server.']); 
                return;
            }

            $keterangan = trim($f3->get('POST.keterangan') ?? '');
            $idPerbaikan = $f3->get('POST.id_perbaikan');

            if ($idPerbaikan) {
                // === UPLOAD REVISI ===
                $existing = $this->db->exec(
                    "SELECT * FROM kesanggupan_uploads
                     WHERE id = ? AND user_id = ? AND status = 'rejected'",
                    [$idPerbaikan, $userId]
                );

                if (empty($existing)) {
                    @unlink($uploadDir . $namaFile);
                    echo json_encode(['success' => false, 'message' => 'Dokumen perbaikan tidak ditemukan atau statusnya tidak valid.']);
                    return;
                }

                $record       = $existing[0];
                $jumlahRevisi = (int)($record['revision_count'] ?? 0);

                if ($jumlahRevisi >= 3) {
                    @unlink($uploadDir . $namaFile);
                    echo json_encode([
                        'success' => false,
                        'message' => 'Batas maksimal revisi (3 kali) telah tercapai. Hubungi tim kami.'
                    ]);
                    return;
                }

                $revNum = $jumlahRevisi + 1;
                $tsCol   = "perbaikan_{$revNum}_at";
                $fileCol = "perbaikan_{$revNum}_file";

                $this->db->exec(
                    "UPDATE kesanggupan_uploads
                     SET file_name     = ?,
                         original_name = ?,
                         keterangan    = ?,
                         $tsCol        = NOW(),
                         $fileCol      = ?,
                         revision_count = ?,
                         status        = 'pending',
                         note          = NULL
                     WHERE id = ?",
                    [$namaFile, $namaAsli, $keterangan, $namaFile, $revNum, $record['id']]
                );

                if ($this->notifikasiService) {
                    $keuanganUsers = $this->db->exec("SELECT id FROM users WHERE role IN ('keuangan', 'superkeuangan')");
                    foreach (($keuanganUsers ?: []) as $ku) {
                        $this->notifikasiService->create(
                            $ku['id'],
                            'Revisi Kesanggupan',
                            'Pelanggan mengunggah revisi surat kesanggupan (revisi ke-' . ($jumlahRevisi + 1) . ').',
                            'info',
                            '/keuangan/kesanggupan'
                        );
                    }

                    $userData = $this->db->exec('SELECT nama_lengkap, username FROM users WHERE id = ?', [$userId]);
                    $namaPelanggan = !empty($userData) ? ($userData[0]['nama_lengkap'] ?? $userData[0]['username'] ?? 'Pelanggan') : 'Pelanggan';
                    
                    $waMsg = "*Notifikasi Selport*\n"
                           . "Halo {nama_user}, terdapat Surat Kesanggupan (Revisi) baru dari pelanggan:\n"
                           . "*Nama*: {$namaPelanggan}\n"
                           . "Silakan login ke sistem untuk melakukan verifikasi.";
                    $this->notifikasiService->sendWhatsAppToKeuangan($waMsg);

                    // Notifikasi aplikasi untuk pelanggan
                    $this->notifikasiService->create(
                        $userId,
                        'Upload Surat Kesanggupan (Revisi)',
                        'Anda telah berhasil mengirim ulang Surat Kesanggupan Pembayaran (Revisi ke-' . ($jumlahRevisi + 1) . '). Dokumen Anda sedang menunggu verifikasi dari tim Keuangan.',
                        'info',
                        '/kesanggupan'
                    );
                }

                echo json_encode(['success' => true, 'message' => 'Upload revisi berhasil. Menunggu diverifikasi.']);

            } else {
                // === UPLOAD PERTAMA ===
                $needFix = $this->db->exec(
                    "SELECT id FROM kesanggupan_uploads
                     WHERE user_id = ? AND status = 'rejected' LIMIT 1",
                    [$userId]
                );
                
                if (!empty($needFix)) {
                    @unlink($uploadDir . $namaFile);
                    echo json_encode(['success' => false, 'message' => 'Anda masih memiliki riwayat dokumen kesanggupan yang perlu diperbaiki. Silakan gunakan tombol Upload Ulang pada baris terkait di tabel riwayat.']);
                    return;
                }

                $this->db->exec(
                    'INSERT INTO kesanggupan_uploads (user_id, file_name, original_name, keterangan, status)
                     VALUES (?, ?, ?, ?, ?)',
                    [$userId, $namaFile, $namaAsli, $keterangan, 'pending']
                );
                // Set revision_count=0 secara terpisah agar tidak gagal jika kolom baru saja ditambahkan
                try {
                    $this->db->exec(
                        'UPDATE kesanggupan_uploads SET revision_count = 0 WHERE id = LAST_INSERT_ID()'
                    );
                } catch (\Throwable $e) { /* abaikan jika kolom belum ada */ }

                if ($this->notifikasiService) {
                    $keuanganUsers = $this->db->exec("SELECT id FROM users WHERE role IN ('keuangan', 'superkeuangan')");
                    foreach (($keuanganUsers ?: []) as $ku) {
                        $this->notifikasiService->create(
                            $ku['id'],
                            'Surat Kesanggupan Baru',
                            'Pelanggan mengunggah surat kesanggupan baru.',
                            'info',
                            '/keuangan/kesanggupan'
                        );
                    }

                    $userData = $this->db->exec('SELECT nama_lengkap, username FROM users WHERE id = ?', [$userId]);
                    $namaPelanggan = !empty($userData) ? ($userData[0]['nama_lengkap'] ?? $userData[0]['username'] ?? 'Pelanggan') : 'Pelanggan';
                    
                    $waMsg = "*Notifikasi Selport*\n"
                           . "Halo {nama_user}, terdapat unggahan Surat Kesanggupan Pembayaran baru dari pelanggan:\n"
                           . "*Nama*: {$namaPelanggan}\n"
                           . "Silakan login ke sistem untuk melakukan verifikasi.";
                    $this->notifikasiService->sendWhatsAppToKeuangan($waMsg);

                    // Notifikasi aplikasi untuk pelanggan
                    $this->notifikasiService->create(
                        $userId,
                        'Upload Surat Kesanggupan',
                        'Anda telah berhasil mengunggah Surat Kesanggupan Pembayaran. Dokumen Anda sedang menunggu verifikasi dari tim Keuangan.',
                        'info',
                        '/kesanggupan'
                    );
                }

                echo json_encode([
                    'success' => true,
                    'message' => 'Surat kesanggupan berhasil dikirim. Menunggu verifikasi tim keuangan.'
                ]);
            }
        } catch (\Throwable $e) {
            echo json_encode(['success' => false, 'message' => 'Error Server: ' . $e->getMessage()]);
        }
    }

    public function getRiwayatKesanggupan($f3) {
        $userId = $f3->get('SESSION.user_id');
        if (!$userId) { $f3->error(401); return; }

        try {
            // Lazy Migration
            $revCols = [
                'revision_count INT DEFAULT 0',
                'perbaikan_1_at DATETIME DEFAULT NULL',
                'perbaikan_1_file VARCHAR(500) DEFAULT NULL',
                'perbaikan_2_at DATETIME DEFAULT NULL',
                'perbaikan_2_file VARCHAR(500) DEFAULT NULL',
                'perbaikan_3_at DATETIME DEFAULT NULL',
                'perbaikan_3_file VARCHAR(500) DEFAULT NULL'
            ];
            foreach ($revCols as $col) {
                try { $this->db->exec("ALTER TABLE kesanggupan_uploads ADD COLUMN $col"); } catch (\Throwable $e) {}
            }

            $rows = $this->db->exec(
                "SELECT id, status, note, COALESCE(revision_count, 0) as revision_count,
                        file_name, original_name, keterangan,
                        CASE
                            WHEN revision_count = 3 AND perbaikan_3_at IS NOT NULL THEN perbaikan_3_at
                            WHEN revision_count = 2 AND perbaikan_2_at IS NOT NULL THEN perbaikan_2_at
                            WHEN revision_count = 1 AND perbaikan_1_at IS NOT NULL THEN perbaikan_1_at
                            ELSE created_at
                        END AS created_at
                 FROM kesanggupan_uploads
                 WHERE user_id = ?
                 ORDER BY created_at DESC",
                [$userId]
            );
        } catch (\Throwable $e) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => $e->getMessage(), 'data' => []]);
            return;
        }

        foreach ($rows as &$r) {
            $timestamp = strtotime($r['created_at'] ?? '');
            $r['created_at_formatted'] = $timestamp
                ? (function_exists('formatTanggalIndo') ? formatTanggalIndo($r['created_at']) : date('d M Y', $timestamp))
                : ($r['created_at'] ?? '-');
            $r['is_revision_pending'] = strtolower($r['status'] ?? '') === 'pending'
                && trim($r['note'] ?? '') !== '';
            
            if (!empty($r['file_name'])) {
                $r['preview_token'] = doc_make_token($r['file_name'], 'kesanggupan-upload');
            }
        }

        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'data' => $rows]);
    }

    public function deleteKesanggupan($f3) {
        header('Content-Type: application/json');
        $userId = $f3->get('SESSION.user_id');
        if (!$userId) {
            echo json_encode(['success' => false, 'message' => 'Unauthorized']);
            return;
        }

        $id = $f3->get('POST.id');
        if (!$id) {
            echo json_encode(['success' => false, 'message' => 'ID tidak ditemukan']);
            return;
        }

        try {
            $row = $this->db->exec('SELECT * FROM kesanggupan_uploads WHERE id = ? AND user_id = ?', [$id, $userId]);
            if (empty($row)) {
                echo json_encode(['success' => false, 'message' => 'Data tidak ditemukan atau Anda tidak memiliki akses']);
                return;
            }

            $record = $row[0];

            // Hanya izinkan hapus jika status pending DAN tidak ada catatan admin
            if (strtolower($record['status'] ?? '') !== 'pending') {
                echo json_encode(['success' => false, 'message' => 'Dokumen tidak dapat dihapus karena sudah diproses oleh admin']);
                return;
            }

            if (trim($record['note'] ?? '') !== '') {
                echo json_encode(['success' => false, 'message' => 'Dokumen tidak dapat dihapus setelah dilakukan upload ulang']);
                return;
            }

            // Hapus file fisik
            $filePath = __DIR__ . '/../../public/uploads/kesanggupan/' . $record['file_name'];
            if (file_exists($filePath)) {
                @unlink($filePath);
            }

            $this->db->exec('DELETE FROM kesanggupan_uploads WHERE id = ? AND user_id = ?', [$id, $userId]);
            echo json_encode(['success' => true, 'message' => 'Dokumen kesanggupan berhasil dihapus']);
            return;
        } catch (\Exception $e) {
            echo json_encode(['success' => false, 'message' => 'Gagal menghapus data: ' . $e->getMessage()]);
        }
    }
}