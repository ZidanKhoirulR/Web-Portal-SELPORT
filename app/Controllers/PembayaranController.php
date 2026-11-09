<?php

class PembayaranController
{
    private $db;
    private $notifikasiService;

    public function __construct($db, $notifikasiService = null)
    {
        $this->db = $db;
        $this->notifikasiService = $notifikasiService;
    }

    /**
     * Lazy migration — tambah kolom perbaikan ke payment_uploads jika belum ada.
     */
    private function ensureRevisionColumns()
    {
        $cols = [
            'revision_count INT DEFAULT 0',
            'perbaikan_1_at DATETIME DEFAULT NULL',
            'perbaikan_1_file VARCHAR(500) DEFAULT NULL',
            'perbaikan_2_at DATETIME DEFAULT NULL',
            'perbaikan_2_file VARCHAR(500) DEFAULT NULL',
            'perbaikan_3_at DATETIME DEFAULT NULL',
            'perbaikan_3_file VARCHAR(500) DEFAULT NULL',
        ];
        foreach ($cols as $col) {
            try { $this->db->exec("ALTER TABLE payment_uploads ADD COLUMN $col"); } catch (\Exception $e) {}
        }
    }

    /**
     * Upload bukti pembayaran oleh pelanggan.
     * Tidak memerlukan order_ids — pelanggan cukup upload file + keterangan.
     */
    public function uploadPembayaran($f3)
    {
        header('Content-Type: application/json');

        $userId = $f3->get('SESSION.user_id');
        if (!$userId) {
            echo json_encode(['success' => false, 'message' => 'Sesi telah berakhir, silakan login kembali.']);
            return;
        }

        // Validasi file
        if (empty($_FILES['file']['name']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            echo json_encode(['success' => false, 'message' => 'File bukti pembayaran wajib diunggah.']);
            return;
        }

        $keterangan = trim($f3->get('POST.keterangan') ?? '');
        if (empty($keterangan)) {
            echo json_encode(['success' => false, 'message' => 'Keterangan pembayaran wajib diisi.']);
            return;
        }

        $file = $_FILES['file'];

        // Validasi ekstensi
        $allowedExts = ['jpg', 'jpeg', 'png', 'pdf'];
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, $allowedExts)) {
            echo json_encode(['success' => false, 'message' => "Format file .{$ext} tidak diizinkan. Gunakan JPG, PNG, atau PDF."]);
            return;
        }

        // Validasi ukuran (maks 5MB)
        if ($file['size'] > 5 * 1024 * 1024) {
            echo json_encode(['success' => false, 'message' => 'Ukuran file maksimal 5MB.']);
            return;
        }

        $targetDir = 'public/uploads/payments/';
        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0777, true);
        }

        $fileName   = time() . '_' . $userId . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', basename($file['name']));
        $targetFile = $targetDir . $fileName;

        if (!move_uploaded_file($file['tmp_name'], $targetFile)) {
            echo json_encode(['success' => false, 'message' => 'Gagal mengunggah file. Silakan coba lagi.']);
            return;
        }

        // Pastikan kolom revisi tersedia
        $this->ensureRevisionColumns();

        // Cek apakah ini upload ulang (ada id_perbaikan)
        $idPerbaikan = $f3->get('POST.id_perbaikan');

        try {
            if ($idPerbaikan) {
                // Ambil data existing
                $existing = $this->db->exec(
                    "SELECT id, status, COALESCE(revision_count, 0) as revision_count FROM payment_uploads WHERE id = ? AND user_id = ?",
                    [$idPerbaikan, $userId]
                );

                if (empty($existing)) {
                    echo json_encode(['success' => false, 'message' => 'Data pembayaran tidak ditemukan di database.']);
                    return;
                }

                $currentStatus    = $existing[0]['status'];
                $currentRevision  = (int) $existing[0]['revision_count'];

                // Hanya boleh re-upload jika status Gagal/Perbaiki
                if (!in_array($currentStatus, ['Gagal', 'Perbaiki', 'Ditolak'])) {
                    echo json_encode(['success' => false, 'message' => "Data dengan status '{$currentStatus}' tidak bisa direvisi."]);
                    return;
                }

                // Maksimal 3 kali perbaikan
                if ($currentRevision >= 3) {
                    echo json_encode(['success' => false, 'message' => 'Batas maksimal 3 kali perbaikan telah tercapai.']);
                    return;
                }

                $newRevision = $currentRevision + 1;
                $revisiAtField   = "perbaikan_{$newRevision}_at";
                $revisiFileField = "perbaikan_{$newRevision}_file";

                $this->db->exec(
                    "UPDATE payment_uploads
                     SET file_name = ?, file_path = ?, keterangan = ?,
                         status = 'Menunggu', reason = NULL,
                         verified_by = NULL, verified_at = NULL,
                         revision_count = ?,
                         {$revisiAtField} = NOW(),
                         {$revisiFileField} = ?,
                         updated_at = NOW()
                     WHERE id = ?",
                    [$fileName, $targetFile, $keterangan, $newRevision, $file['name'], $idPerbaikan]
                );

                if ($this->notifikasiService) {
                    $userData = $this->db->exec('SELECT nama_lengkap, username FROM users WHERE id = ?', [$userId]);
                    $namaPelanggan = !empty($userData) ? ($userData[0]['nama_lengkap'] ?? $userData[0]['username'] ?? 'Pelanggan') : 'Pelanggan';
                    
                    $waMsg = "*Notifikasi Selport*\n"
                           . "Halo {nama_user}, terdapat Bukti Pembayaran (Perbaikan) baru dari pelanggan:\n"
                           . "*Nama*: {$namaPelanggan}\n"
                           . "Silakan login ke sistem untuk melakukan verifikasi.";
                    $this->notifikasiService->sendWhatsAppToKeuangan($waMsg);

                    // Notifikasi aplikasi untuk pelanggan
                    $this->notifikasiService->create(
                        $userId,
                        'Upload Bukti Pembayaran (Perbaikan)',
                        'Anda telah berhasil mengirim ulang bukti pembayaran (Perbaikan ' . $newRevision . '). Dokumen Anda sedang menunggu verifikasi dari tim Keuangan.',
                        'info',
                        '/pembayaran'
                    );
                }

                echo json_encode(['success' => true, 'message' => "Bukti pembayaran (Perbaikan {$newRevision}) berhasil dikirim ulang. Menunggu verifikasi."]);
            } else {
                // Insert baru
                // Validasi: Cegah upload baru dari form utama jika ada pembayaran yang ditolak (harus via Upload Ulang)
                $needFix = $this->db->exec(
                    "SELECT id FROM payment_uploads WHERE user_id = ? AND status IN ('Gagal', 'Perbaiki', 'Ditolak') LIMIT 1",
                    [$userId]
                );

                if (!empty($needFix)) {
                    @unlink($targetFile);
                    echo json_encode(['success' => false, 'message' => 'Anda masih memiliki bukti pembayaran yang perlu diperbaiki. Silakan gunakan tombol Upload Ulang pada baris terkait di tabel riwayat.']);
                    return;
                }

                $this->db->exec(
                    "INSERT INTO payment_uploads (user_id, file_name, file_path, keterangan, status, revision_count, created_at, updated_at)
                     VALUES (?, ?, ?, ?, 'Menunggu', 0, NOW(), NOW())",
                    [$userId, $fileName, $targetFile, $keterangan]
                );

                if ($this->notifikasiService) {
                    $userData = $this->db->exec('SELECT nama_lengkap, username FROM users WHERE id = ?', [$userId]);
                    $namaPelanggan = !empty($userData) ? ($userData[0]['nama_lengkap'] ?? $userData[0]['username'] ?? 'Pelanggan') : 'Pelanggan';
                    
                    $waMsg = "*Notifikasi Selport*\n"
                           . "Halo {nama_user}, terdapat unggahan Bukti Pembayaran baru dari pelanggan:\n"
                           . "*Nama*: {$namaPelanggan}\n"
                           . "Silakan login ke sistem untuk melakukan verifikasi.";
                    $this->notifikasiService->sendWhatsAppToKeuangan($waMsg);

                    // Notifikasi aplikasi untuk pelanggan
                    $this->notifikasiService->create(
                        $userId,
                        'Upload Bukti Pembayaran',
                        'Anda telah berhasil mengunggah bukti pembayaran. Dokumen Anda sedang menunggu verifikasi dari tim Keuangan.',
                        'info',
                        '/pembayaran'
                    );
                }

                echo json_encode(['success' => true, 'message' => 'Bukti pembayaran berhasil dikirim. Akan diverifikasi segera.']);
            }
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => 'Gagal menyimpan ke database: ' . $e->getMessage()]);
        }
    }

    /**
     * Ambil riwayat pembayaran milik pelanggan yang sedang login.
     */
    public function getRiwayat($f3)
    {
        header('Content-Type: application/json');

        $userId = $f3->get('SESSION.user_id');
        if (!$userId) {
            echo json_encode(['success' => false, 'message' => 'Unauthorized']);
            return;
        }

        // Pastikan kolom revisi tersedia
        $this->ensureRevisionColumns();

        try {
            $rows = $this->db->exec(
                "SELECT id, file_name, keterangan, status, reason, created_at, updated_at,
                        COALESCE(revision_count, 0) as revision_count
                 FROM payment_uploads
                 WHERE user_id = ?
                 ORDER BY created_at DESC",
                [$userId]
            );

            foreach ($rows as &$row) {
                $ts = strtotime($row['created_at'] ?? '');
                $row['tanggal_upload_formatted'] = $ts
                    ? date('d M Y', $ts)
                    : ($row['created_at'] ?? '-');

                $row['preview_token'] = function_exists('doc_make_token') && !empty($row['file_name']) 
                    ? doc_make_token($row['file_name'], 'payment-upload') 
                    : '';

                $revCount = (int) ($row['revision_count'] ?? 0);
                $row['revision_count'] = $revCount;

                // Hitung label status untuk UI pelanggan
                $status = $row['status'];
                if ($status === 'Diverifikasi' || $status === 'Disetujui') {
                    $row['status_label'] = 'Disetujui';
                } elseif ($status === 'Gagal' || $status === 'Perbaiki' || $status === 'Ditolak') {
                    $nextRev = $revCount + 1;
                    if ($nextRev > 3) {
                        $row['status_label'] = 'Perbaiki (Maks)';
                    } else {
                        $row['status_label'] = "Perbaiki {$nextRev}";
                    }
                } elseif ($status === 'Menunggu' || $status === 'Pending') {
                    if ($revCount > 0) {
                        $row['status_label'] = "Menunggu (Perbaikan {$revCount})";
                    } else {
                        $row['status_label'] = 'Pending';
                    }
                } else {
                    $row['status_label'] = 'Pending';
                }

                // Normalized status untuk logika tombol
                $row['status'] = ($status === 'Diverifikasi' || $status === 'Disetujui') ? 'Disetujui'
                    : (in_array($status, ['Gagal', 'Perbaiki', 'Ditolak']) ? 'Perbaiki' : 'Pending');
            }

            echo json_encode(['success' => true, 'data' => $rows]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
    }


    /**
     * Hapus bukti pembayaran milik pelanggan (hanya jika masih Pending).
     */
    public function deletePembayaran($f3)
    {
        header('Content-Type: application/json');

        $userId = $f3->get('SESSION.user_id');
        if (!$userId) {
            echo json_encode(['success' => false, 'message' => 'Unauthorized']);
            return;
        }

        $id = $f3->get('POST.id');
        if (!$id) {
            echo json_encode(['success' => false, 'message' => 'ID tidak valid.']);
            return;
        }

        try {
            $row = $this->db->exec(
                "SELECT id, file_path, status FROM payment_uploads WHERE id = ? AND user_id = ?",
                [$id, $userId]
            );

            if (empty($row)) {
                echo json_encode(['success' => false, 'message' => 'Data tidak ditemukan.']);
                return;
            }

            if (!in_array($row[0]['status'], ['Pending', 'Menunggu'])) {
                echo json_encode(['success' => false, 'message' => 'Hanya data berstatus Pending yang dapat dihapus.']);
                return;
            }

            // Hapus file fisik
            $filePath = $row[0]['file_path'];
            if ($filePath && file_exists($filePath)) {
                @unlink($filePath);
            }

            $this->db->exec("DELETE FROM payment_uploads WHERE id = ? AND user_id = ?", [$id, $userId]);

            echo json_encode(['success' => true, 'message' => 'Bukti pembayaran berhasil dihapus.']);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
    }
}