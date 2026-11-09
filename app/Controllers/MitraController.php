<?php

class MitraController
{
    private $db;
    private $notifikasiService;
    private $dbSil;

    public function __construct($db, $notifikasiService = null, $dbSil = null)
    {
        $this->db = $db;
        $this->notifikasiService = $notifikasiService;
        $this->dbSil = $dbSil;
    }

    private function checkMitra($f3)
    {
        $role = $f3->get('SESSION.role');
        if ($role !== 'mitra' && $role !== 'supermitra') {
            // Jika request adalah API (URL mengandung /api/), kembalikan JSON 401
            $uri = $f3->get('PATH');
            if (strpos($uri, '/api/') !== false || strpos($uri, '/mitra/load/') !== false) {
                http_response_code(401);
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'session_expired' => true, 'message' => 'Sesi telah berakhir.']);
                exit;
            }
            $f3->reroute('/login');
            exit;
        }
    }

    /**
     * Helper untuk validasi file secara ketat (Security Patch)
     */
    private function validateFile($file, $allowedMimes, $allowedExts, $maxSize = 10 * 1024 * 1024) {
        if ($file['error'] !== UPLOAD_ERR_OK) {
            return ['success' => false, 'message' => 'Terjadi kesalahan saat mengunggah file.'];
        }

        if ($file['size'] > $maxSize) {
            $mb = $maxSize / (1024 * 1024);
            return ['success' => false, 'message' => "Ukuran file terlalu besar. Maksimal {$mb}MB."];
        }

        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, $allowedExts)) {
            return ['success' => false, 'message' => "Ekstensi file .{$ext} tidak diizinkan. Gunakan: " . implode(', ', $allowedExts)];
        }

        if (!function_exists('finfo_open')) {
            return ['success' => true];
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);

        if ($mime === 'image/jpg') $mime = 'image/jpeg';

        if (!in_array($mime, $allowedMimes)) {
            return ['success' => false, 'message' => "Konten file tidak sesuai dengan format yang diizinkan (Terdeteksi sebagai {$mime})."];
        }

        return ['success' => true];
    }

    private function getFileExtension($filename)
    {
        return strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    }

    public function index($f3, $section = 'ringkasan')
    {
        $this->checkMitra($f3);
        
        $html = file_get_contents('ui/mitra/dashboard.html');
        $html = str_replace('{{ @BASE }}', $f3->get('BASE'), $html);
        $html = str_replace('{{ @SESSION.nama_lengkap }}', $f3->get('SESSION.nama_lengkap') ?? 'Mitra', $html);
        $html = str_replace('{{ @SECTION }}', $section, $html);
        
        $roleLabel = strtoupper($f3->get('SESSION.role') ?? 'MITRA');
        $html = str_replace('{{ @SESSION.role }}', $roleLabel, $html);
        
        // Show/Hide management menu based on role
        if ($roleLabel !== 'SUPERMITRA') {
            $html = preg_replace('/<!-- MITRA_MANAGEMENT_MENU -->.*?<!-- \/MITRA_MANAGEMENT_MENU -->/s', '', $html);
        } else {
            $html = str_replace(['<!-- MITRA_MANAGEMENT_MENU -->', '<!-- /MITRA_MANAGEMENT_MENU -->'], '', $html);
        }

        $avatar = $f3->get('SESSION.nama_avatar') ?? '';
        $html = str_replace('{{ @SESSION.nama_avatar }}', $avatar, $html);
        $html = str_replace('{{ @SESSION.username }}', $f3->get('SESSION.username') ?? 'mitra', $html);

        echo $html;
    }

    public function getPendingCustomers($f3)
    {
        $this->checkMitra($f3);
        header('Content-Type: application/json');
        
        $search = $f3->get('GET.search');
        $status = $f3->get('GET.status') ?: 'pending';
        $startDate = $f3->get('GET.start_date');
        $endDate = $f3->get('GET.end_date');

        $where = "WHERE u.role='customer' AND u.status = ?";
        $params = [$status];

        if ($search) {
            $where .= " AND (u.username LIKE ? OR u.nama_lengkap LIKE ? OR u.email LIKE ? OR c.nama_perusahaan LIKE ?)";
            $term = "%$search%";
            $params[] = $term; $params[] = $term; $params[] = $term; $params[] = $term;
        }

        if ($startDate) {
            $where .= " AND u.created_at >= ?";
            $params[] = $startDate . " 00:00:00";
        }
        if ($endDate) {
            $where .= " AND u.created_at <= ?";
            $params[] = $endDate . " 23:59:59";
        }

        try { $this->db->exec("ALTER TABLE users ADD COLUMN verified_by INT DEFAULT NULL"); } catch (\Exception $e) {}
        try { $this->db->exec("ALTER TABLE users ADD COLUMN verified_by_name VARCHAR(255) DEFAULT NULL"); } catch (\Exception $e) {}

        $sql = "SELECT u.id, u.username, u.nama_lengkap, u.email, u.jenis_kelamin, u.kategori_pelanggan,
                       u.alamat_tinggal, u.provinsi_tinggal, u.kabupaten_tinggal, u.kecamatan_tinggal, u.kodepos_tinggal,
                       u.no_whatsapp, u.status, u.id_customer, u.rejection_reason, u.created_at, u.verified_by_name,
                       c.nama_perusahaan, c.email_perusahaan, c.alamat_perusahaan, 
                       c.provinsi_perusahaan, c.kabupaten_perusahaan, c.kecamatan_perusahaan, c.kodepos_perusahaan,
                       c.telp_perusahaan, c.wa_perusahaan, c.fax_perusahaan
                FROM users u 
                LEFT JOIN companies c ON u.company_id = c.id 
                $where 
                ORDER BY u.created_at DESC";
        
        $customers = $this->db->exec($sql, $params);
        echo json_encode(['data' => $customers]);
    }

    public function validateCustomer($f3)
    {
        $this->checkMitra($f3);
        header('Content-Type: application/json');
        
        $userId = $f3->get('POST.user_id');
        $action = $f3->get('POST.action'); // 'approve' or 'reject'
        $reason = $f3->get('POST.reason'); // For rejection
        $idCustomer = $f3->get('POST.id_customer'); // Customer ID from sil2020

        if (!$userId || !$action) {
            echo json_encode(['success' => false, 'message' => 'Data tidak lengkap']);
            return;
        }

        if ($action === 'approve' && empty($idCustomer)) {
            echo json_encode(['success' => false, 'message' => 'Pilih perusahaan terlebih dahulu']);
            return;
        }

        $user = new DB\SQL\Mapper($this->db, 'users');
        $user->load(['id = ?', $userId]);

        if ($user->dry()) {
            echo json_encode(['success' => false, 'message' => 'User tidak ditemukan']);
            return;
        }

        $adminId = $f3->get('SESSION.user_id');
        $adminName = $f3->get('SESSION.nama_lengkap') ?: $f3->get('SESSION.username') ?: '-';

        if ($action === 'approve') {
            $user->status = 'active';
            $user->id_customer = $idCustomer; // Save id_customer from sil2020
            $user->verified_by = $adminId;
            $user->verified_by_name = $adminName;
            
            // Sync company data from SIL if company_id exists
            if ($user->company_id && $this->dbSil) {
                try {
                    $silData = $this->dbSil->exec("SELECT * FROM tb_customer WHERE id_customer = ?", [$idCustomer]);
                    if (!empty($silData)) {
                        $sd = $silData[0];
                        $company = new DB\SQL\Mapper($this->db, 'companies');
                        $company->load(['id = ?', $user->company_id]);
                        
                        if (!$company->dry()) {
                            // Seluruh inputan dari pelanggan dipertahankan (TIDAK ditimpa oleh data SIL)
                            /*
                            $company->nama_perusahaan = $sd['nmcustomer'] ?: $company->nama_perusahaan;
                            $company->alamat_perusahaan = $sd['alamatcustomer'] ?: $company->alamat_perusahaan;
                            $company->email_perusahaan = $sd['emailcustomer'] ?: $company->email_perusahaan;
                            $company->telp_perusahaan = $sd['notelpcustomer'] ?: $company->telp_perusahaan;
                            $company->fax_perusahaan = $sd['nofaxcustomer'] ?: $company->fax_perusahaan;
                            $company->kodepos_perusahaan = $sd['kode_pos'] ?: $company->kodepos_perusahaan;
                            $company->wa_perusahaan = $sd['nohpcontactperson'] ?: $company->wa_perusahaan;
                            */
                            $company->save();
                        }
                    }
                } catch (\Exception $e) {
                    error_log("Failed to sync SIL data during approval: " . $e->getMessage());
                }
            }
            
            $user->save();
            
            if ($this->notifikasiService) {
                $this->notifikasiService->create(
                    $userId,
                    'Akun Disetujui',
                    'Akun Anda berhasil diverifikasi. Silakan login.',
                    'success',
                    '/auth'
                );
                if (!empty($user->no_whatsapp)) {
                    $this->notifikasiService->sendWhatsApp($user->no_whatsapp, "Pemberitahuan: Akun Anda berhasil diverifikasi oleh tim mitra. Silakan login.");
                }
            }

            if (!class_exists('EmailService')) {
                $possiblePaths = [
                    __DIR__ . '/../Services/EmailService.php',
                    'app/Services/EmailService.php'
                ];
                foreach ($possiblePaths as $path) {
                    if (file_exists($path)) {
                        require_once $path;
                        break;
                    }
                }
            }

            if (class_exists('EmailService')) {
                try {
                    $emailService = new EmailService();
                    $baseUrl = $f3->get('BASE');
                    if (empty($baseUrl)) {
                         $baseUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://$_SERVER[HTTP_HOST]";
                    }
                    $loginLink = $baseUrl . '/auth';
                    
                    $emailService->sendCredentialsEmail(
                        $user->email, 
                        $user->nama_lengkap, 
                        $user->username,
                        $user->no_whatsapp, 
                        $loginLink
                    );
                } catch (\Exception $e) {
                    error_log("Failed to send approval email: " . $e->getMessage());
                }
            }
            
            echo json_encode(['success' => true, 'message' => 'Pelanggan berhasil disetujui']);
        } elseif ($action === 'reject') {
            $user->status = 'rejected';
            $user->rejection_reason = $reason ?: null;
            $user->verified_by = $adminId;
            $user->verified_by_name = $adminName;
            $user->save();
            
            if ($this->notifikasiService) {
                $messageNotification = 'Mohon maaf, data akun Anda perlu diperbaiki.';
                if ($reason) {
                    $messageNotification .= ' Alasan: ' . $reason;
                }
                $this->notifikasiService->create(
                    $userId,
                    'Akun Perlu Diperbaiki',
                    $messageNotification,
                    'error',
                    '/auth'
                );
                if (!empty($user->no_whatsapp)) {
                    $this->notifikasiService->sendWhatsApp($user->no_whatsapp, "Pemberitahuan: " . $messageNotification);
                }
            }

            if (!class_exists('EmailService')) {
                $possiblePaths = [
                    __DIR__ . '/../Services/EmailService.php',
                    'app/Services/EmailService.php'
                ];
                foreach ($possiblePaths as $path) {
                    if (file_exists($path)) {
                        require_once $path;
                        break;
                    }
                }
            }

            if (class_exists('EmailService')) {
                try {
                    $emailService = new EmailService();
                    $emailService->sendRejectionEmail(
                        $user->email, 
                        $user->nama_lengkap, 
                        $reason
                    );
                } catch (\Exception $e) {
                    error_log("Failed to send rejection email: " . $e->getMessage());
                }
            }
            
            // Hapus data pelanggan secara otomatis setelah ditolak (beserta company jika ada)
            $companyId = $user->company_id;
            try {
                $this->db->exec('DELETE FROM users WHERE id = ?', [$userId]);
                if ($companyId) {
                    $this->db->exec('DELETE FROM companies WHERE id = ?', [$companyId]);
                }
            } catch (\Exception $e) {
                error_log("Gagal menghapus user/company yang ditolak: " . $e->getMessage());
            }
            
            echo json_encode(['success' => true, 'message' => 'Status pelanggan berhasil ditolak dan data dihapus']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Action tidak valid']);
        }
    }

    public function getMitraUsers($f3)
    {
        $this->checkMitra($f3);
        header('Content-Type: application/json');
        
        $search = $f3->get('GET.search');

        $where = "WHERE role = 'mitra'";
        $params = [];

        if ($search) {
            $where .= " AND (username LIKE ? OR nama_lengkap LIKE ? OR email LIKE ?)";
            $term = "%$search%";
            $params = [$term, $term, $term];
        }

        $mitras = $this->db->exec("SELECT id, username, nama_lengkap, email, no_whatsapp, created_at FROM users $where ORDER BY created_at DESC", $params);
        echo json_encode(['data' => $mitras]);
    }

    public function addMitra($f3)
    {
        $this->checkMitra($f3);
        header('Content-Type: application/json');
        
        $username = $f3->get('POST.username');
        $password = $f3->get('POST.password');
        $name = $f3->get('POST.nama_lengkap');
        $email = $f3->get('POST.email');
        $whatsapp = $f3->get('POST.no_whatsapp');
        
        if (!$username || !$password || !$email || !$whatsapp) {
            echo json_encode(['success' => false, 'message' => 'Data tidak lengkap']);
            return;
        }

        if (strlen($password) < 8) {
            echo json_encode(['success' => false, 'message' => 'Password minimal 8 karakter']);
            return;
        }

        $existing = $this->db->exec("SELECT id FROM users WHERE username = ?", $username);
        if (!empty($existing)) {
            echo json_encode(['success' => false, 'message' => 'Username sudah terdaftar']);
            return;
        }

        $user = new DB\SQL\Mapper($this->db, 'users');
        $user->username = $username;
        $user->password = password_hash($password, PASSWORD_BCRYPT);
        $user->nama_lengkap = $name;
        $user->email = $email;
        $user->no_whatsapp = $whatsapp;
        $user->role = 'mitra';
        $user->status = 'active';
        $user->created_at = date('Y-m-d H:i:s');
        $user->save();
        
        echo json_encode(['success' => true, 'message' => 'Mitra berhasil ditambahkan']);
    }

    public function deleteMitra($f3)
    {
        $this->checkMitra($f3);
        header('Content-Type: application/json');
        
        $id = $f3->get('POST.id');
        $currentUserId = $f3->get('SESSION.user_id');

        if (!$id) {
            echo json_encode(['success' => false, 'message' => 'ID tidak valid']);
            return;
        }

        if ($id == $currentUserId) {
            echo json_encode(['success' => false, 'message' => 'Tidak dapat menghapus akun sendiri']);
            return;
        }

        $target = $this->db->exec("SELECT role FROM users WHERE id = ?", $id);
        if (empty($target) || $target[0]['role'] !== 'mitra') {
            echo json_encode(['success' => false, 'message' => 'User bukan mitra']);
            return;
        }

        $this->db->exec("DELETE FROM users WHERE id = ?", $id);
        echo json_encode(['success' => true, 'message' => 'Mitra berhasil dihapus']);
    }

    public function getMitraUser($f3)
    {
        $this->checkMitra($f3);
        header('Content-Type: application/json');
        
        $id = $f3->get('PARAMS.id');
        if (!$id) {
            echo json_encode(['success' => false, 'message' => 'ID tidak valid']);
            return;
        }

        $user = $this->db->exec("SELECT id, username, nama_lengkap, email, no_whatsapp FROM users WHERE id = ? AND role = 'mitra'", $id);
        if (empty($user)) {
            echo json_encode(['success' => false, 'message' => 'User tidak ditemukan']);
            return;
        }

        echo json_encode(['success' => true, 'data' => $user[0]]);
    }

    public function updateMitra($f3)
    {
        $this->checkMitra($f3);
        header('Content-Type: application/json');
        
        $id = $f3->get('POST.id');
        $username = $f3->get('POST.username');
        $password = $f3->get('POST.password');
        $name = $f3->get('POST.nama_lengkap');
        $email = $f3->get('POST.email');
        $whatsapp = $f3->get('POST.no_whatsapp');
        
        if (!$id || !$username || !$email || !$whatsapp) {
            echo json_encode(['success' => false, 'message' => 'Data tidak lengkap']);
            return;
        }

        $user = new DB\SQL\Mapper($this->db, 'users');
        $user->load(['id = ? AND role = "mitra"', $id]);
        
        if ($user->dry()) {
            echo json_encode(['success' => false, 'message' => 'User tidak ditemukan']);
            return;
        }

        $existing = $this->db->exec("SELECT id FROM users WHERE username = ? AND id != ?", [$username, $id]);
        if (!empty($existing)) {
            echo json_encode(['success' => false, 'message' => 'Username sudah digunakan']);
            return;
        }

        $user->username = $username;
        if (!empty($password)) {
            if (strlen($password) < 8) {
                echo json_encode(['success' => false, 'message' => 'Password minimal 8 karakter']);
                return;
            }
            $user->password = password_hash($password, PASSWORD_BCRYPT);
        }
        $user->nama_lengkap = $name;
        $user->email = $email;
        $user->no_whatsapp = $whatsapp;
        $user->save();
        
        echo json_encode(['success' => true, 'message' => 'Mitra berhasil diperbarui']);
    }

    public function getStats($f3)
    {
        $this->checkMitra($f3);
        header('Content-Type: application/json');
        
        $this->ensureDataRespondenTable();

        // Validasi Pelanggan
        $vp_pending = $this->db->exec('SELECT COUNT(*) as c FROM users WHERE role="customer" AND status="pending"')[0]['c'] ?? 0;
        $vp_approved = $this->db->exec('SELECT COUNT(*) as c FROM users WHERE role="customer" AND status="active"')[0]['c'] ?? 0;
        $vp_rejected = $this->db->exec('SELECT COUNT(*) as c FROM users WHERE role="customer" AND status="rejected"')[0]['c'] ?? 0;

        // Permohonan Pengecualian
        $pp_pending = $this->db->exec('SELECT COUNT(*) as c FROM pengecualian_dokumen WHERE status IN ("Menunggu", "Pending", "pending")')[0]['c'] ?? 0;
        $pp_approved = $this->db->exec('SELECT COUNT(*) as c FROM pengecualian_dokumen WHERE status IN ("Diverifikasi", "Disetujui", "Selesai", "verified", "approved")')[0]['c'] ?? 0;
        $pp_rejected = $this->db->exec('SELECT COUNT(*) as c FROM pengecualian_dokumen WHERE status IN ("Perbaiki", "Ditolak", "Gagal", "rejected")')[0]['c'] ?? 0;

        // Permohonan Alat
        $pa_pending = 0; $pa_approved = 0; $pa_rejected = 0;
        try {
            $pa_pending = $this->db->exec('SELECT COUNT(*) as c FROM alat_dokumen WHERE status IN ("Menunggu", "Pending", "pending")')[0]['c'] ?? 0;
            $pa_approved = $this->db->exec('SELECT COUNT(*) as c FROM alat_dokumen WHERE status IN ("Diverifikasi", "Disetujui", "Selesai", "verified", "approved")')[0]['c'] ?? 0;
            $pa_rejected = $this->db->exec('SELECT COUNT(*) as c FROM alat_dokumen WHERE status IN ("Perbaiki", "Ditolak", "Gagal", "rejected")')[0]['c'] ?? 0;
        } catch (\Exception $e) {}

        // Kelola Pembayaran
        $kp_pending = 0; $kp_approved = 0; $kp_rejected = 0;
        try {
            $kp_pending = $this->db->exec('SELECT COUNT(*) as c FROM payment_uploads WHERE status IN ("Menunggu", "Pending", "pending")')[0]['c'] ?? 0;
            $kp_approved = $this->db->exec('SELECT COUNT(*) as c FROM payment_uploads WHERE status IN ("Diverifikasi", "Disetujui", "Selesai", "verified", "approved")')[0]['c'] ?? 0;
            $kp_rejected = $this->db->exec('SELECT COUNT(*) as c FROM payment_uploads WHERE status IN ("Perbaiki", "Ditolak", "Gagal", "rejected")')[0]['c'] ?? 0;
        } catch (\Exception $e) {}

        // Kesanggupan Pembayaran
        $ksp_pending = 0; $ksp_approved = 0; $ksp_rejected = 0;
        try {
            $ksp_pending = $this->db->exec('SELECT COUNT(*) as c FROM kesanggupan_uploads WHERE status IN ("Menunggu", "Pending", "pending")')[0]['c'] ?? 0;
            $ksp_approved = $this->db->exec('SELECT COUNT(*) as c FROM kesanggupan_uploads WHERE status IN ("Diverifikasi", "Disetujui", "Selesai", "verified", "approved")')[0]['c'] ?? 0;
            $ksp_rejected = $this->db->exec('SELECT COUNT(*) as c FROM kesanggupan_uploads WHERE status IN ("Perbaiki", "Ditolak", "Gagal", "rejected")')[0]['c'] ?? 0;
        } catch (\Exception $e) {}

        // Layanan Pelanggan
        $lp_keluhan = 0; $lp_layanan = 0;
        try {
            $lp_keluhan = $this->db->exec('SELECT COUNT(*) as c FROM complaints')[0]['c'] ?? 0;
        } catch (Exception $e) {}
        try {
            $lp_layanan = $this->db->exec('SELECT COUNT(*) as c FROM services')[0]['c'] ?? 0;
        } catch (Exception $e) {}

        $announcements = $this->db->exec('SELECT COUNT(*) as c FROM announcements')[0]['c'] ?? 0;
        
        $kuesionerStats = $this->db->exec('SELECT COUNT(*) as total FROM data_responden')[0];
        $total_responden = $kuesionerStats['total'] ?? 0;
        
        echo json_encode([
            'vp_pending' => $vp_pending,
            'vp_approved' => $vp_approved,
            'vp_rejected' => $vp_rejected,

            'pp_pending' => $pp_pending,
            'pp_approved' => $pp_approved,
            'pp_rejected' => $pp_rejected,

            'pa_pending' => $pa_pending,
            'pa_approved' => $pa_approved,
            'pa_rejected' => $pa_rejected,

            'kp_pending' => $kp_pending,
            'kp_approved' => $kp_approved,
            'kp_rejected' => $kp_rejected,

            'ksp_pending' => $ksp_pending,
            'ksp_approved' => $ksp_approved,
            'ksp_rejected' => $ksp_rejected,

            'lp_keluhan' => $lp_keluhan,
            'lp_layanan' => $lp_layanan,

            'announcements' => $announcements,
            'total_responden' => $total_responden
        ]);
    }

    public function getAnnouncements($f3)
    {
        $this->checkMitra($f3);
        header('Content-Type: application/json');

        $announcements = $this->db->exec('SELECT * FROM announcements ORDER BY created_at DESC');
        echo json_encode(['data' => $announcements]);
    }

    public function getKelolaPembayaran($f3)
    {
        $this->checkMitra($f3);
        header('Content-Type: application/json');

        $search = trim((string)($f3->get('GET.search') ?? ''));
        $status = $f3->get('GET.status') ?? 'all';

        $where = '1=1';
        $params = [];

        if ($status !== 'all') {
            $statusAliases = [
                'Pending' => ['Pending', 'Menunggu'],
                'Disetujui' => ['Disetujui', 'Diverifikasi'],
                'Perbaiki' => ['Perbaiki', 'Ditolak', 'Gagal'],
            ];
            $statuses = $statusAliases[$status] ?? [$status];
            $where .= ' AND pu.status IN (' . implode(',', array_fill(0, count($statuses), '?')) . ')';
            foreach ($statuses as $statusValue) {
                $params[] = $statusValue;
            }
        }

        if ($search !== '') {
            $where .= ' AND (c.nama_perusahaan LIKE ? OR u.nama_lengkap LIKE ?)';
            $term = "%{$search}%";
            $params[] = $term;
            $params[] = $term;
        }

        try {
            $rows = $this->db->exec(
                "SELECT pu.id, pu.file_name AS nama_file, pu.keterangan, pu.reason AS catatan, pu.status,
                        COALESCE(pu.revision_count, 0) AS revision_count,
                        pu.created_at, pu.order_no,
                        COALESCE(c.nama_perusahaan, u.nama_lengkap, 'Perorangan') AS nama_perusahaan,
                        COALESCE(admin.nama_lengkap, admin.username, '-') AS dikelola_oleh
                 FROM payment_uploads pu
                 LEFT JOIN users u ON u.id = pu.user_id
                 LEFT JOIN companies c ON u.company_id = c.id
                 LEFT JOIN users admin ON admin.id = pu.verified_by
                 WHERE $where
                 ORDER BY pu.created_at DESC",
                $params
            );

            foreach ($rows as &$row) {
                $timestamp = strtotime($row['created_at'] ?? '');
                $row['tanggal_upload_formatted'] = $timestamp
                    ? date('d M Y', $timestamp)
                    : ($row['created_at'] ?? '-');

                $revCount = (int) ($row['revision_count'] ?? 0);
                $dbStatus = $row['status'] ?? '';

                // Compute UI label
                if ($dbStatus === 'Diverifikasi') {
                    $row['ui_status_label'] = 'Disetujui';
                } elseif ($dbStatus === 'Gagal') {
                    $nextRev = $revCount + 1;
                    $row['ui_status_label'] = $nextRev > 3 ? 'Perbaiki (Maks)' : "Perbaiki {$nextRev}";
                } elseif ($dbStatus === 'Menunggu') {
                    $row['ui_status_label'] = $revCount > 0 ? "Re-upload (Perbaikan {$revCount})" : 'Pending';
                } else {
                    $row['ui_status_label'] = 'Pending';
                }

                // Normalize status string (for filter compatibility)
                if ($dbStatus === 'Menunggu') $row['status'] = 'Pending';
                if ($dbStatus === 'Diverifikasi') $row['status'] = 'Disetujui';
                if ($dbStatus === 'Ditolak' || $dbStatus === 'Gagal') $row['status'] = 'Perbaiki';
            }

            echo json_encode(['success' => true, 'data' => $rows]);
        } catch (\Exception $e) {
            echo json_encode(['success' => false, 'data' => [], 'message' => 'Tabel pembayaran belum tersedia: ' . $e->getMessage()]);
        }
    }

    public function saveAnnouncement($f3)
    {
        $this->checkMitra($f3);
        header('Content-Type: application/json');
        
        $id = $f3->get('POST.id');
        $title = $f3->get('POST.title');
        $content = $f3->get('POST.content');
        $isActive = $f3->get('POST.is_active') === 'true' ? 1 : 0;

        if (!$content) {
            echo json_encode(['success' => false, 'message' => 'Konten wajib diisi']);
            return;
        }

        $announcement = new DB\SQL\Mapper($this->db, 'announcements');
        if ($id) {
            $announcement->load(['id=?', $id]);
        }

        $announcement->title = $title ?: 'Pemberitahuan';
        $announcement->content = $content;
        $announcement->is_active = $isActive;
        
        try {
            $announcement->save();
            echo json_encode(['success' => true, 'message' => 'Pengumuman berhasil disimpan']);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => 'Gagal menyimpan: ' . $e->getMessage()]);
        }
    }

    public function deleteAnnouncement($f3)
    {
        $this->checkMitra($f3);
        header('Content-Type: application/json');

        $id = $f3->get('POST.id');
        if (!$id) {
            echo json_encode(['success' => false, 'message' => 'ID tidak valid']);
            return;
        }

        try {
            $this->db->exec('DELETE FROM announcements WHERE id=?', $id);
            echo json_encode(['success' => true, 'message' => 'Pengumuman berhasil dihapus']);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => 'Gagal menghapus: ' . $e->getMessage()]);
        }
    }

    public function toggleAnnouncement($f3)
    {
        $this->checkMitra($f3);
        header('Content-Type: application/json');

        $id = $f3->get('POST.id');
        $isActive = $f3->get('POST.is_active') === 'true' ? 1 : 0;

        try {
            $this->db->exec('UPDATE announcements SET is_active = ? WHERE id = ?', [$isActive, $id]);
            echo json_encode(['success' => true, 'message' => 'Status pengumuman berhasil diubah']);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => 'Gagal mengubah status: ' . $e->getMessage()]);
        }
    }

    public function updateAccount($f3)
    {
        $this->checkMitra($f3);
        header('Content-Type: application/json');

        if (!$this->dbSil) {
            echo json_encode(['success' => false, 'message' => 'Database SIL tidak tersedia']);
            return;
        }

        $userId = $f3->get('SESSION.user_id');
        $namaLengkap = $f3->get('POST.nama_lengkap');
        $password = $f3->get('POST.password');
        
        // Get current user data from SIL database
        $user = $this->dbSil->exec('SELECT * FROM tb_arsipuser WHERE id_user = ?', [$userId]);
        if (empty($user)) {
            echo json_encode(['success' => false, 'message' => 'User tidak ditemukan']);
            return;
        }

        $avatarName = $user[0]['nama_avatar'];

        // Handle avatar upload
        $files = $f3->get('FILES');
        if (!empty($files['avatar']['name']) && $files['avatar']['error'] === UPLOAD_ERR_OK) {
            $validation = $this->validateFile($files['avatar'], ['image/jpeg', 'image/png', 'image/gif'], ['jpg', 'jpeg', 'png', 'gif'], 2 * 1024 * 1024);
            
            if (!$validation['success']) {
                echo json_encode(['success' => false, 'message' => $validation['message']]);
                return;
            }

            $ext = strtolower(pathinfo($files['avatar']['name'], PATHINFO_EXTENSION));

            // Create a sanitized filename
            $sanitizedName = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $user[0]['nama_user']));
            $avatarName = 'mitra_' . $userId . '_' . $sanitizedName . '_' . time() . '.' . $ext;
            $destination = 'public/avatar/' . $avatarName;
            
            if (move_uploaded_file($files['avatar']['tmp_name'], $destination)) {
                $this->dbSil->exec(
                    'UPDATE tb_arsipuser SET nama_avatar = ? WHERE id_user = ?',
                    [$avatarName, $userId]
                );
                $f3->set('SESSION.nama_avatar', $avatarName);
            }
        }

        // Update Name
        if ($namaLengkap && $namaLengkap !== $user[0]['nama_user']) {
            $this->dbSil->exec(
                'UPDATE tb_arsipuser SET nama_user = ? WHERE id_user = ?',
                [$namaLengkap, $userId]
            );
            $f3->set('SESSION.nama_lengkap', $namaLengkap);
        }

        // Update Password
        if ($password && strlen($password) >= 8) {
            $hashedPassword = md5($password);
            $this->dbSil->exec(
                'UPDATE tb_arsipuser SET password = ? WHERE id_user = ?',
                [$hashedPassword, $userId]
            );
        }

        echo json_encode([
            'success' => true, 
            'message' => 'Profil berhasil diperbarui',
            'avatar' => $avatarName,
            'nama' => $namaLengkap ?: $user[0]['nama_user']
        ]);
    }

    public function loadSection($f3, $file)
    {
        $this->checkMitra($f3);
        $path = "mitra/{$file}.html";
        if (file_exists("ui/".$path)) {
            echo \Template::instance()->render($path);
        } else {
            echo "Section not found: {$file}";
        }
    }

    // ─── Permohonan Alat (Mitra) ───────────────────────────────────────────────

    public function getAllPermohonan($f3)
    {
        $this->checkMitra($f3);
        header('Content-Type: application/json; charset=utf-8');

        // Pastikan kolom handled_by ada
        try { $this->db->exec("ALTER TABLE alat_dokumen ADD COLUMN handled_by INT NULL"); } catch (\Exception $e) {}

        $search = $f3->get('GET.search');
        $status = $f3->get('GET.status');

        $where = "WHERE 1=1";
        $params = [];

        if ($search) {
            $where .= " AND (c.nama_perusahaan LIKE ? OR u.nama_lengkap LIKE ? OR d.id LIKE ?)";
            $term = "%$search%";
            $params = [$term, $term, $term];
        }

        if ($status) {
            $where .= " AND d.status = ?";
            $params[] = $status;
        }

        try {
            $sql = "SELECT d.*,
                           COALESCE(c.nama_perusahaan, u.nama_lengkap) as nama_perusahaan,
                           DATE_FORMAT(d.dibuat_pada, '%d-%m-%Y %H:%i') as created_at_formatted
                    FROM alat_dokumen d
                    LEFT JOIN users u ON d.id_pengguna = u.id
                    LEFT JOIN companies c ON u.company_id = c.id
                    $where
                    ORDER BY d.dibuat_pada DESC";

            $orders = $this->db->exec($sql, $params);

            $romans = ['01'=>'I','02'=>'II','03'=>'III','04'=>'IV','05'=>'V','06'=>'VI','07'=>'VII','08'=>'VIII','09'=>'IX','10'=>'X','11'=>'XI','12'=>'XII'];

            foreach ($orders as &$order) {
                $namaFile = $order['nama_file_acak'] ?? '';
                $order['preview_token'] = $namaFile ? doc_make_token($namaFile, 'permohonan-alat') : '';

                // Generate preview tokens untuk slot history 1–5
                for ($i = 1; $i <= 5; $i++) {
                    $slotFile = $order["nama_file_acak_{$i}"] ?? '';
                    $order["preview_token_{$i}"] = $slotFile ? doc_make_token($slotFile, 'permohonan-alat') : '';
                }

                $m = date('m', strtotime($order['dibuat_pada']));
                $y = date('Y', strtotime($order['dibuat_pada']));
                $order['no_identitas'] = $order['id'] . '/kal/' . $romans[$m] . '/' . $y;

                $order['kategori'] = '-';
                $order['catatan'] = '-';
                $order['proses_input'] = '-';
                if (!empty($order['id_permintaan_list'])) {
                    $ids = explode(',', $order['id_permintaan_list']);
                    $firstId = trim($ids[0]);
                    if ($firstId) {
                        $perm = $this->db->exec(
                            'SELECT kategori, catatan, nama_penanda_tangan FROM alat_permintaan WHERE id = ?',
                            [$firstId]
                        );
                        if ($perm && count($perm) > 0) {
                            $order['kategori'] = $perm[0]['kategori'];
                            $order['catatan'] = $perm[0]['catatan'] ?: '-';
                            if (trim($perm[0]['nama_penanda_tangan']) === '-') {
                                $order['proses_input'] = 'Upload Langsung';
                            } else {
                                $order['proses_input'] = 'Input Manual';
                            }
                        }
                    }
                }

                $order['handled_by_name'] = null;
                if ($this->dbSil && !empty($order['handled_by'])) {
                    $admin = $this->dbSil->exec(
                        'SELECT nama_user FROM tb_arsipuser WHERE id_user = ?',
                        [$order['handled_by']]
                    );
                    if ($admin && count($admin) > 0) {
                        $order['handled_by_name'] = $admin[0]['nama_user'];
                    }
                }
            }
            unset($order);

            echo json_encode(['success' => true, 'data' => $orders]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    public function getPermohonanDetails($f3)
    {
        $this->checkMitra($f3);
        header('Content-Type: application/json; charset=utf-8');
        $doc_id = $f3->get('PARAMS.id');

        try {
            $dokumen = $this->db->exec("SELECT id_permintaan_list FROM alat_dokumen WHERE id = ?", [$doc_id]);
            if (empty($dokumen)) {
                echo json_encode(['success' => false, 'message' => 'Dokumen tidak ditemukan']);
                return;
            }

            $id_permintaan_list = $dokumen[0]['id_permintaan_list'];
            $permintaan_ids = array_map('intval', explode(',', $id_permintaan_list));

            if (empty($permintaan_ids)) {
                echo json_encode(['success' => true, 'data' => []]);
                return;
            }

            $placeholders = implode(',', array_fill(0, count($permintaan_ids), '?'));
            $details = $this->db->exec(
                "SELECT * FROM alat_item WHERE id_permintaan IN ($placeholders)",
                $permintaan_ids
            );

            echo json_encode(['success' => true, 'data' => $details]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    public function updatePermohonanStatus($f3)
    {
        $this->checkMitra($f3);
        header('Content-Type: application/json; charset=utf-8');

        $id       = $f3->get('POST.id');
        $status   = $f3->get('POST.status');
        $alasan   = $f3->get('POST.alasan');
        $admin_id = $f3->get('SESSION.user_id');

        try {
            try { $this->db->exec("ALTER TABLE alat_dokumen ADD COLUMN handled_by INT NULL"); } catch (\Exception $e) {}

            $this->db->exec(
                "UPDATE alat_dokumen SET status = ?, alasan_penolakan = ?, handled_by = ? WHERE id = ?",
                [$status, $alasan, $admin_id, $id]
            );

            if ($status === 'rejected') {
                $this->db->exec("UPDATE alat_dokumen SET jumlah_revisi = jumlah_revisi + 1 WHERE id = ?", [$id]);
            }

            if ($this->notifikasiService) {
                $dokumen = $this->db->exec(
                    "SELECT d.id_pengguna, u.no_whatsapp FROM alat_dokumen d LEFT JOIN users u ON d.id_pengguna = u.id WHERE d.id = ?",
                    [$id]
                );
                if (!empty($dokumen)) {
                    $userId = $dokumen[0]['id_pengguna'];
                    $noWa   = $dokumen[0]['no_whatsapp'] ?? null;
                    if ($status === 'verified') {
                        $msg = "Permohonan Alat #TLR-{$id} Anda telah disetujui. Alat sudah dapat dikirimkan ke laboratorium.";
                    } else {
                        $revResult = $this->db->exec("SELECT jumlah_revisi FROM alat_dokumen WHERE id = ?", [$id]);
                        $revCount  = !empty($revResult) ? (int)$revResult[0]['jumlah_revisi'] : 1;
                        $msg = "Permohonan Alat #TLR-{$id} Anda ditolak/dikembalikan. Silakan periksa catatan revisi. (Revisi ke-{$revCount}).";
                    }
                    $this->notifikasiService->create($userId, 'Update Permohonan Alat', $msg, 'tool_request', '/permohonanalat');
                    if (!empty($noWa)) {
                        $this->notifikasiService->sendWhatsApp($noWa, "Pemberitahuan: " . $msg);
                    }
                }
            }

            echo json_encode(['success' => true, 'message' => 'Status berhasil diperbarui']);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    public function getSilCustomers($f3)
    {
        $this->checkMitra($f3);
        header('Content-Type: application/json');

        if (!$this->dbSil) {
            echo json_encode(['success' => false, 'message' => 'Database sil2020 tidak terhubung', 'data' => []]);
            return;
        }

        $search = $f3->get('GET.search') ?: '';

        try {
            $where = "WHERE kodex_perusahaan IS NOT NULL AND kodex_perusahaan != ''";
            $params = [];

            if ($search) {
                $where .= " AND (nmcustomer LIKE ? OR kodex_perusahaan LIKE ?)";
                $params[] = "%$search%";
                $params[] = "%$search%";
            }

            $sql = "SELECT MAX(id_customer) as id_customer, nmcustomer, MAX(kodex_perusahaan) as kodex_perusahaan, MAX(alamatcustomer) as alamatcustomer, MAX(emailcustomer) as emailcustomer, MAX(contactperson) as contactperson FROM tb_customer $where GROUP BY nmcustomer ORDER BY nmcustomer ASC LIMIT 100";
            $customers = $this->dbSil->exec($sql, $params);
            
            echo json_encode(['success' => true, 'data' => $customers]);
        } catch (\Exception $e) {
            error_log("Error fetching sil2020 customers: " . $e->getMessage());
            echo json_encode(['success' => false, 'message' => 'Gagal mengambil data customer', 'data' => []]);
        }
    }

    public function getPengecualianDokumenAll($f3)
    {
        $this->checkMitra($f3);
        header('Content-Type: application/json; charset=utf-8');
        
        $status = $f3->get('GET.status');
        $search = $f3->get('GET.search');
        
        try {
            $where = [];
            $params = [];
            
            if (!empty($status)) {
                $where[] = "d.status = ?";
                $params[] = $status;
            }
            
            if (!empty($search)) {
                $where[] = "(u.nama_lengkap LIKE ? OR c.nama_perusahaan LIKE ? OR d.original_name LIKE ?)";
                $searchParam = "%$search%";
                $params[] = $searchParam;
                $params[] = $searchParam;
                $params[] = $searchParam;
            }
            
            $whereClause = !empty($where) ? "WHERE " . implode(" AND ", $where) : "";
            
            try { $this->db->exec("ALTER TABLE pengecualian_dokumen ADD COLUMN verified_by INT DEFAULT NULL"); } catch (\Exception $e) {}
            try { $this->db->exec("ALTER TABLE pengecualian_dokumen ADD COLUMN verified_by_name VARCHAR(255) DEFAULT NULL"); } catch (\Exception $e) {}
            try { $this->db->exec("ALTER TABLE pengecualian_dokumen ADD COLUMN surat_penawaran VARCHAR(255) DEFAULT NULL"); } catch (\Exception $e) {}
            try { $this->db->exec("ALTER TABLE pengecualian_dokumen ADD COLUMN invoice VARCHAR(255) DEFAULT NULL"); } catch (\Exception $e) {}
            
            $sql = "SELECT d.id, d.file_name, d.original_name, d.created_at, d.status, d.alasan, d.permintaan_ids, d.verified_by, d.surat_penawaran, d.invoice, u.id as user_id, u.nama_lengkap, c.nama_perusahaan, COALESCE(d.verified_by_name, admin.nama_lengkap, admin.username, '-') as handled_by_name
                    FROM pengecualian_dokumen d
                    JOIN users u ON d.user_id = u.id
                    LEFT JOIN companies c ON u.company_id = c.id
                    LEFT JOIN users admin ON d.verified_by = admin.id
                    $whereClause
                    ORDER BY d.created_at DESC";
            
            $data = $this->db->exec($sql, $params);

            foreach ($data as &$item) {
                $item['created_at_formatted'] = function_exists('formatTanggalIndo') ? formatTanggalIndo($item['created_at']) : date('d M Y', strtotime($item['created_at']));
                $item['uploader'] = (!empty($item['nama_perusahaan']) && $item['nama_perusahaan'] !== 'Perorangan') ? $item['nama_perusahaan'] : $item['nama_lengkap'];
                
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

                // Remove sensitive file info from response
                unset($item['file_name'], $item['surat_penawaran'], $item['invoice']);

                $lingkupList = [];
                if (!empty($item['permintaan_ids'])) {
                    $ids = array_filter(array_map('intval', explode(',', $item['permintaan_ids'])));
                    if (!empty($ids)) {
                        $placeholders = implode(',', array_fill(0, count($ids), '?'));
                        $perms = $this->db->exec("SELECT lingkup FROM pengecualian_permintaan WHERE id IN ($placeholders)", $ids);
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

    public function downloadPengecualianDokumen($f3)
    {
        $this->checkMitra($f3);
        $id = $f3->get('PARAMS.id');

        if (!$id) {
            header('HTTP/1.1 400 Bad Request');
            die('ID tidak valid');
        }

        try {
            $doc = $this->db->exec("SELECT * FROM pengecualian_dokumen WHERE id = ?", $id);
            if (empty($doc)) {
                header('HTTP/1.1 404 Not Found');
                die('Dokumen tidak ditemukan');
            }

            $file = $doc[0];
            $filePath = $file['file_path'];

            if (!file_exists($filePath)) {
                header('HTTP/1.1 404 Not Found');
                die('File fisik tidak ditemukan di server: ' . $filePath);
            }

            $originalName = $file['original_name'];
            
            header('Content-Description: File Transfer');
            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename="' . $originalName . '"');
            header('Expires: 0');
            header('Cache-Control: must-revalidate');
            header('Pragma: public');
            header('Content-Length: ' . filesize($filePath));
            
            readfile($filePath);
            exit;
        } catch (Exception $e) {
            header('HTTP/1.1 500 Internal Server Error');
            die('Terjadi kesalahan: ' . $e->getMessage());
        }
    }

    public function updatePengecualianDokumenStatus($f3)
    {
        $this->checkMitra($f3);
        header('Content-Type: application/json; charset=utf-8');

        $id = $f3->get('POST.id');
        $status = $f3->get('POST.status');
        $alasan = $f3->get('POST.alasan');
        $files = $f3->get('FILES');

        if (!$id || !$status) {
            echo json_encode(['success' => false, 'message' => 'Data tidak lengkap']);
            return;
        }

        try {
            // Ensure new columns exist
            try { $this->db->exec("ALTER TABLE pengecualian_dokumen ADD COLUMN surat_penawaran VARCHAR(255) DEFAULT NULL"); } catch (\Exception $e) {}
            try { $this->db->exec("ALTER TABLE pengecualian_dokumen ADD COLUMN invoice VARCHAR(255) DEFAULT NULL"); } catch (\Exception $e) {}

            $doc = $this->db->exec("SELECT * FROM pengecualian_dokumen WHERE id = ?", $id);

            if (empty($doc)) {
                echo json_encode(['success' => false, 'message' => 'Dokumen tidak ditemukan']);
                return;
            }

            $oldStatus = $doc[0]['status'];
            $adminId = $f3->get('SESSION.user_id');
            $adminName = $f3->get('SESSION.nama_lengkap') ?: $f3->get('SESSION.username') ?: '-';
            
            // Ensure verified_by_name column exists
            try { $this->db->exec("ALTER TABLE pengecualian_dokumen ADD COLUMN verified_by_name VARCHAR(255) DEFAULT NULL"); } catch (\Exception $e) {}

            $uploadDir = 'public/uploads/pengecualian/mitra/';
            if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);
            
            $suratPenawaran = $doc[0]['surat_penawaran'] ?? null;
            $uploadedDocs = [];

            if (isset($files['surat_penawaran']) && $files['surat_penawaran']['error'] === UPLOAD_ERR_OK) {
                $file = $files['surat_penawaran'];
                $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                $newName = 'sp_' . $id . '_' . time() . '.' . $ext;
                if (move_uploaded_file($file['tmp_name'], $uploadDir . $newName)) {
                    if (!empty($suratPenawaran) && file_exists($uploadDir . $suratPenawaran)) {
                        @unlink($uploadDir . $suratPenawaran);
                    }
                    $suratPenawaran = $newName;
                    $uploadedDocs[] = 'Surat Penawaran';
                }
            }
            
            $invoice = $doc[0]['invoice'] ?? null;
            if (isset($files['invoice']) && $files['invoice']['error'] === UPLOAD_ERR_OK) {
                $file = $files['invoice'];
                $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                $newName = 'inv_' . $id . '_' . time() . '.' . $ext;
                if (move_uploaded_file($file['tmp_name'], $uploadDir . $newName)) {
                    if (!empty($invoice) && file_exists($uploadDir . $invoice)) {
                        @unlink($uploadDir . $invoice);
                    }
                    $invoice = $newName;
                    $uploadedDocs[] = 'Invoice';
                }
            }

            // Menggunakan query langsung untuk menghindari isu caching schema pada F3 Mapper
            $this->db->exec(
                "UPDATE pengecualian_dokumen SET status = ?, alasan = ?, verified_by = ?, verified_by_name = ?, surat_penawaran = ?, invoice = ? WHERE id = ?",
                [$status, $alasan ?: null, $adminId, $adminName, $suratPenawaran, $invoice, $id]
            );

            if ($status === 'rejected' || $status === 'Perbaiki') {
                $this->db->exec("UPDATE pengecualian_dokumen SET revision_count = COALESCE(revision_count, 0) + 1 WHERE id = ?", [$id]);
            }

            if ($this->notifikasiService && ($oldStatus !== $status || count($uploadedDocs) > 0)) {
                $docMsg = '';
                if (count($uploadedDocs) > 0) {
                    $docMsg = " Dokumen " . implode(" dan ", $uploadedDocs) . " telah dilampirkan oleh Mitra.";
                }

                if ($oldStatus !== $status) {
                    if ($status === 'verified' || $status === 'Disetujui') {
                        $msg = "Permohonan Pengecualian Anda telah disetujui." . $docMsg;
                        $notifType = 'exception_request';
                    } else if ($status === 'rejected' || $status === 'Perbaiki') {
                        $revResult = $this->db->exec("SELECT revision_count FROM pengecualian_dokumen WHERE id = ?", [$id]);
                        $revCount = !empty($revResult) ? (int)$revResult[0]['revision_count'] : 1;
                        $msg = "Permohonan Pengecualian #EXC-{$id} Anda ditolak/dikembalikan. Silakan periksa catatan revisi. (Revisi ke-{$revCount} dari batas 5)." . $docMsg;
                        $notifType = 'exception_request';
                    } else {
                        $msg = "Status Permohonan Pengecualian #EXC-{$id} Anda berubah menjadi {$status}." . $docMsg;
                        $notifType = 'exception_request';
                    }
                } else {
                    // Status tidak berubah, tapi ada dokumen baru
                    $msg = "Pembaruan Dokumen: Mitra telah melampirkan " . implode(" dan ", $uploadedDocs) . " untuk Permohonan Pengecualian #EXC-{$id} Anda.";
                    $notifType = 'exception_request';
                }

                $this->notifikasiService->create(
                    $doc[0]['user_id'],
                    'Update Permohonan Pengecualian',
                    $msg,
                    $notifType,
                    '/permohonanpengecualian'
                );

                // Kirim notifikasi WhatsApp ke customer
                $customerWa = $this->db->exec("SELECT no_whatsapp FROM users WHERE id = ?", [$doc[0]['user_id']]);
                if (!empty($customerWa) && !empty($customerWa[0]['no_whatsapp'])) {
                    $this->notifikasiService->sendWhatsApp($customerWa[0]['no_whatsapp'], "Pemberitahuan: " . $msg);
                }
            }

            echo json_encode(['success' => true, 'message' => 'Status dokumen berhasil diperbarui']);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => 'Gagal memperbarui status: ' . $e->getMessage()]);
        }
    }

    public function getUserPengecualianDetails($f3)
    {
        $this->checkMitra($f3);
        header('Content-Type: application/json; charset=utf-8');

        $userId = $f3->get('PARAMS.user_id');
        if (!$userId) {
            echo json_encode(['success' => false, 'message' => 'User ID tidak valid']);
            return;
        }

        try {
            $user = new DB\SQL\Mapper($this->db, 'users');
            $user->load(['id = ?', $userId]);

            if ($user->dry()) {
                echo json_encode(['success' => false, 'message' => 'User tidak ditemukan']);
                return;
            }

            $companyData = [];
            if (!empty($user->company_id)) {
                $company = new DB\SQL\Mapper($this->db, 'companies');
                $company->load(['id = ?', $user->company_id]);
                
                if (!$company->dry()) {
                    $locArr = array_filter([
                        $company->provinsi_perusahaan ?? '',
                        $company->kabupaten_perusahaan ?? '',
                        $company->kecamatan_perusahaan ?? ''
                    ]);
                    $lokasi = implode(', ', $locArr);
                    
                    $getValue = function($val, $default = '-') {
                        return (!empty(trim($val ?? ''))) ? trim($val) : $default;
                    };
                    
                    $companyData = [
                        'nama_perusahaan' => $getValue($company->nama_perusahaan),
                        'alamat_perusahaan' => $getValue($company->alamat_perusahaan),
                        'lokasi_perusahaan' => $lokasi ?: '-',
                        'kodepos_perusahaan' => $getValue($company->kodepos_perusahaan),
                        'email_perusahaan' => $getValue($company->email_perusahaan),
                        'wa_perusahaan' => $getValue($company->wa_perusahaan),
                        'fax_perusahaan' => $getValue($company->fax_perusahaan),
                    ];
                }
            } else {
                $locArr = array_filter([
                    $user->provinsi_tinggal ?? '',
                    $user->kabupaten_tinggal ?? '',
                    $user->kecamatan_tinggal ?? ''
                ]);
                $lokasi = implode(', ', $locArr);

                $companyData = [
                    'nama_perusahaan' => 'Perorangan',
                    'alamat_perusahaan' => $user->alamat_tinggal ?? '-',
                    'lokasi_perusahaan' => $lokasi ?: '-',
                    'kodepos_perusahaan' => $user->kodepos_tinggal ?? '-',
                    'email_perusahaan' => $user->email ?? '-',
                    'wa_perusahaan' => '-',
                    'fax_perusahaan' => '-',
                ];
            }

            $companyData['nama_lengkap'] = $user->nama_lengkap ?? '-';
            $companyData['no_whatsapp'] = $user->no_whatsapp ?? '-';

            $pengecualianData = $this->db->exec(
                'SELECT * FROM pengecualian WHERE user_id = ? ORDER BY lingkup, spesifikasi, created_at DESC',
                [$userId]
            );

            $groups = [];
            if ($pengecualianData) {
                foreach ($pengecualianData as $item) {
                    $key = ($item['lingkup'] ?: 'Tidak Ditentukan') . '|||' . ($item['spesifikasi'] ?: 'Tidak Ditentukan');
                    if (!isset($groups[$key])) {
                        $groups[$key] = [
                            'lingkup' => $item['lingkup'] ?: 'Tidak Ditentukan',
                            'spesifikasi' => $item['spesifikasi'] ?: 'Tidak Ditentukan',
                            'items' => []
                        ];
                    }
                    $groups[$key]['items'][] = $item;
                }
            }

            echo json_encode([
                'success' => true, 
                'data' => [
                    'customer' => $companyData,
                    'groups' => array_values($groups),
                    'tanggal_cetak' => date('d M Y H:i') . ' WIB'
                ]
            ]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => 'Gagal memuat detail permohonan: ' . $e->getMessage()]);
        }
    }

    public function getComplaints($f3)
    {
        $this->checkMitra($f3);
        header('Content-Type: application/json');

        $id = $f3->get('GET.id');
        $search = $f3->get('GET.search');
        
        if ($id) {
            try {
                $complaint = $this->db->exec(
                    "SELECT c.*, u.username, u.nama_lengkap, u.email, u.no_whatsapp, 
                            r.nama_lengkap as resolved_by_name, ct.name as topic_name
                     FROM complaints c 
                     JOIN users u ON c.user_id = u.id 
                     LEFT JOIN users r ON c.resolved_by = r.id
                     LEFT JOIN complaint_topics ct ON c.topic_id = ct.id
                     WHERE c.id = ?",
                    $id
                );

                if (empty($complaint)) {
                    echo json_encode(['success' => false, 'message' => 'Not found']);
                    return;
                }

                $this->db->exec(
                    "UPDATE complaint_chats SET is_read = 1 
                     WHERE complaint_id = ? AND is_admin = 0 AND is_read = 0",
                    $id
                );

                $messages = $this->db->exec(
                    "SELECT cc.*, u.nama_lengkap as nama_pengirim 
                     FROM complaint_chats cc 
                     LEFT JOIN users u ON cc.user_id = u.id 
                     WHERE cc.complaint_id = ? 
                     ORDER BY cc.created_at ASC, cc.id ASC",
                    $id
                );

                // Format timestamps to WIB
                foreach ($messages as &$msg) {
                    $dt = new DateTime($msg['created_at']);

                    $msg['created_at_formatted'] = $dt->format('d M Y, H.i') . ' WIB';
                }

                $complaint[0]['messages'] = $messages ?: [];
                echo json_encode(['success' => true, 'data' => $complaint]);
            } catch (Exception $e) {
                error_log('[MitraController::getComplaints] Error fetching detail: ' . $e->getMessage());
                echo json_encode(['success' => false, 'message' => 'Gagal memuat detail: ' . $e->getMessage()]);
            }
            return;
        }

        try {
            $where = "";
            $params = null; // Use null, not [], to avoid F3 PDO binding issues on parameterless queries

            if ($search) {
                // Search by ticket number, customer name, username, or order number
                // Note: use no_pengaduan (varchar) not topic_id (int) for LIKE search
                $where = "WHERE (c.no_pengaduan LIKE ? OR u.username LIKE ? OR u.nama_lengkap LIKE ? OR c.order_no LIKE ?)";
                $term = "%$search%";
                $params = [$term, $term, $term, $term];
            }

            // Fetch complaints list with user details
            $sql = "SELECT c.id, c.no_pengaduan, c.user_id, c.topic_id, c.order_no, c.order_name,
                           c.message, c.file_path, c.original_filename, c.status, c.created_at, c.updated_at,
                           c.resolved_by, c.is_chat_only,
                           u.username, u.nama_lengkap, u.email, u.no_whatsapp,
                           r.nama_lengkap as resolved_by_name,
                           ct.name as topic_name,
                           (SELECT COUNT(*) FROM complaint_chats ch 
                            WHERE ch.complaint_id = c.id AND ch.is_admin = 0 AND ch.is_read = 0) as unread_count
                    FROM complaints c 
                    JOIN users u ON c.user_id = u.id 
                    LEFT JOIN users r ON c.resolved_by = r.id
                    LEFT JOIN complaint_topics ct ON c.topic_id = ct.id
                    $where
                    ORDER BY c.updated_at DESC, c.created_at DESC";
                    
            $complaints = $params ? $this->db->exec($sql, $params) : $this->db->exec($sql);
            
            if ($complaints === false || $complaints === null) {
                $complaints = [];
            }

            echo json_encode(['data' => $complaints, 'total' => count($complaints)]);
        } catch (Exception $e) {
            error_log('[MitraController::getComplaints] SQL Error: ' . $e->getMessage());
            // Try simpler query without complaint_topics join in case that table doesn't exist
            try {
                $sql2 = "SELECT c.id, c.no_pengaduan, c.user_id, c.topic_id, c.order_no, c.order_name,
                                c.message, c.file_path, c.original_filename, c.status, c.created_at, c.updated_at,
                                c.resolved_by, c.is_chat_only,
                                u.username, u.nama_lengkap, u.email, u.no_whatsapp,
                                NULL as resolved_by_name,
                                NULL as topic_name,
                                (SELECT COUNT(*) FROM complaint_chats ch 
                                 WHERE ch.complaint_id = c.id AND ch.is_admin = 0 AND ch.is_read = 0) as unread_count
                         FROM complaints c 
                         JOIN users u ON c.user_id = u.id
                         ORDER BY c.updated_at DESC, c.created_at DESC";
                $complaints = $this->db->exec($sql2);
                echo json_encode(['data' => $complaints ?: [], 'total' => count($complaints ?: [])]);
            } catch (Exception $e2) {
                error_log('[MitraController::getComplaints] Fallback SQL Error: ' . $e2->getMessage());
                echo json_encode(['data' => [], 'total' => 0, 'error' => $e->getMessage()]);
            }
        }
    }

    public function replyComplaint($f3)
    {
        $this->checkMitra($f3);
        header('Content-Type: application/json');
        
        $complaint_id = $f3->get('POST.complaint_id');
        $reply_text = $f3->get('POST.reply_text');
        $admin_id = $f3->get('SESSION.user_id');

        if (!$complaint_id) {
            echo json_encode(['success' => false, 'message' => 'ID Keluhan tidak ditemukan']);
            return;
        }

        $file_path = null;
        $original_filename = null;
        if (isset($_FILES['chat_file']) && $_FILES['chat_file']['error'] === UPLOAD_ERR_OK) {
            $file = $_FILES['chat_file'];
            $allowedMimes = [
                'application/pdf', 
                'application/msword', 
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'application/vnd.ms-excel',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'application/vnd.ms-powerpoint',
                'application/vnd.openxmlformats-officedocument.presentationml.presentation',
                'text/plain',
                'image/jpeg',
                'image/png'
            ];
            $allowedExts = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'jpg', 'jpeg', 'png'];
            
            $validation = $this->validateFile($file, $allowedMimes, $allowedExts, 10 * 1024 * 1024);
            if (!$validation['success']) {
                echo json_encode(['success' => false, 'message' => $validation['message']]);
                return;
            }

            $uploadDir = 'public/uploads/complaints/chats/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0777, true);
            }
            
            $original_filename = $_FILES['chat_file']['name'];
            $fileExtension = $this->getFileExtension($original_filename);
            $fileName = uniqid('chat_') . '.' . $fileExtension;
            $targetPath = $uploadDir . $fileName;

            if (move_uploaded_file($_FILES['chat_file']['tmp_name'], $targetPath)) {
                $file_path = $fileName;
            }
        }

        try {
            $today = date('Y-m-d');
            $shouldSendWa = true;
            $lastAdminChatToday = $this->db->exec(
                "SELECT id FROM complaint_chats WHERE complaint_id = ? AND is_admin = 1 AND DATE(created_at) = ? LIMIT 1",
                [$complaint_id, $today]
            );
            if (!empty($lastAdminChatToday)) {
                $shouldSendWa = false;
            }

            // Insert the message
            $chat = new DB\SQL\Mapper($this->db, 'complaint_chats');
            $chat->complaint_id = $complaint_id;
            $chat->user_id = $admin_id;
            $chat->message = $reply_text;
            $chat->file_path = $file_path;
            $chat->original_filename = $original_filename;
            $chat->is_admin = 1;
            $chat->is_read = 0; // Customer hasn't read this yet
            $chat->created_at = date('Y-m-d H:i:s');
            $chat->save();

            // Update the parent complaint status to 'In Progress' if it was 'Pending'
            // And set updated_at
            $complaint = new DB\SQL\Mapper($this->db, 'complaints');
            $complaint->load(['id = ?', $complaint_id]);
            if (!$complaint->dry()) {
                if ($complaint->status === 'Pending') {
                    $complaint->status = 'In Progress';
                }
                $complaint->updated_at = date('Y-m-d H:i:s');
                $complaint->save();
                
                if ($this->notifikasiService && $shouldSendWa) {
                    $customerData = $this->db->exec("SELECT no_whatsapp FROM users WHERE id=?", $complaint->user_id);
                    if (!empty($customerData) && !empty($customerData[0]['no_whatsapp'])) {
                        $waMsg = "Keluhan Anda ({$complaint->no_pengaduan}) telah direspon oleh layanan mitra. Silahkan cek aplikasi untuk melihat balasan.\n\nMohon tidak membalas pesan ini.";
                        $this->notifikasiService->sendWhatsApp($customerData[0]['no_whatsapp'], $waMsg);
                    }
                }
            }

            echo json_encode(['success' => true, 'message' => 'Balasan terkirim']);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => 'Gagal mengirim balasan: ' . $e->getMessage()]);
        }
    }

    public function deleteComplaint($f3)
    {
        $this->checkMitra($f3);
        header('Content-Type: application/json');

        $complaint_id = $f3->get('POST.complaint_id');

        if (!$complaint_id) {
            echo json_encode(['success' => false, 'message' => 'ID Keluhan tidak ditemukan']);
            return;
        }

        try {
            $this->db->exec("DELETE FROM complaint_chats WHERE complaint_id=?", $complaint_id);
            
            $this->db->exec("DELETE FROM complaints WHERE id=?", $complaint_id);

            echo json_encode(['success' => true, 'message' => 'Keluhan berhasil dihapus']);
        } catch (\Exception $e) {
            echo json_encode(['success' => false, 'message' => 'Gagal menghapus keluhan: ' . $e->getMessage()]);
        }
    }

    public function resolveComplaint($f3)
    {
        $this->checkMitra($f3);
        header('Content-Type: application/json');

        $complaint_id = $f3->get('POST.complaint_id');
        $mitra_id = $f3->get('SESSION.user_id');

        if (!$complaint_id) {
            echo json_encode(['success' => false, 'message' => 'ID Keluhan tidak valid']);
            return;
        }

        try {
            $this->db->exec(
                "UPDATE complaints SET status='Resolved', updated_at=NOW(), resolved_by=? WHERE id=?", 
                [$mitra_id, $complaint_id]
            );

            $complaint = $this->db->exec("SELECT user_id, no_pengaduan FROM complaints WHERE id=?", $complaint_id);
            if ($complaint && $this->notifikasiService) {
                $this->notifikasiService->create(
                    $complaint[0]['user_id'],
                    'Keluhan Selesai',
                    'Keluhan Anda telah ditandai selesai oleh tim kami.',
                    'success',
                    'dashboard/section/keluhan'
                );
                
                $customerData = $this->db->exec("SELECT no_whatsapp FROM users WHERE id=?", $complaint[0]['user_id']);
                if (!empty($customerData) && !empty($customerData[0]['no_whatsapp'])) {
                    $waMsg = "Pemberitahuan: Keluhan Anda (" . $complaint[0]['no_pengaduan'] . ") telah ditandai selesai oleh tim kami.";
                    $this->notifikasiService->sendWhatsApp($customerData[0]['no_whatsapp'], $waMsg);
                }
            }

            echo json_encode(['success' => true, 'message' => 'Keluhan berhasil diselesaikan']);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => 'Terjadi kesalahan: ' . $e->getMessage()]);
        }
    }

    public function clearMessages($f3)
    {
        $this->checkMitra($f3);
        header('Content-Type: application/json');

        $complaint_id = $f3->get('POST.complaint_id');

        if (!$complaint_id) {
            echo json_encode(['success' => false, 'message' => 'ID Keluhan tidak valid']);
            return;
        }

        try {
            $this->db->exec('DELETE FROM complaint_chats WHERE complaint_id = ?', $complaint_id);

            echo json_encode(['success' => true, 'message' => 'Chat berhasil dibersihkan']);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => 'Gagal membersihkan chat: ' . $e->getMessage()]);
        }
    }
    public function exportCustomers($f3)
    {
        $this->checkMitra($f3);
        
        $format = $f3->get('GET.format');
        $status = $f3->get('GET.status') ?: 'pending';
        $search = $f3->get('GET.search');
        $startDate = $f3->get('GET.start_date');
        $endDate = $f3->get('GET.end_date');

        $where = "WHERE u.role='customer' AND u.status = ?";
        $params = [$status];

        if ($search) {
            $where .= " AND (u.username LIKE ? OR u.nama_lengkap LIKE ? OR u.email LIKE ? OR c.nama_perusahaan LIKE ?)";
            $term = "%$search%";
            $params[] = $term; $params[] = $term; $params[] = $term; $params[] = $term;
        }

        if ($startDate) {
            $where .= " AND u.created_at >= ?";
            $params[] = $startDate . " 00:00:00";
        }
        if ($endDate) {
            $where .= " AND u.created_at <= ?";
            $params[] = $endDate . " 23:59:59";
        }

        $sql = "SELECT u.created_at, u.nama_lengkap, u.email, u.no_whatsapp, u.kategori_pelanggan, c.nama_perusahaan, u.status
                FROM users u 
                LEFT JOIN companies c ON u.company_id = c.id 
                $where 
                ORDER BY u.created_at DESC";
        
        $data = $this->db->exec($sql, $params);

        if ($format === 'excel') {
            $this->exportExcel($data, $status);
        } elseif ($format === 'pdf') {
            $this->exportPdf($data, $status);
        } else {
            $this->exportCsv($data, $status);
        }
    }

    private function exportCsv($data, $status)
    {
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="Laporan_Pelanggan_' . $status . '_' . date('Ymd_His') . '.csv"');
        
        $output = fopen('php://output', 'w');
        fputcsv($output, ['Tanggal Daftar', 'Nama Lengkap', 'Email', 'WhatsApp', 'Kategori', 'Perusahaan', 'Status']);
        
        foreach ($data as $row) {
            fputcsv($output, [
                $row['created_at'],
                $row['nama_lengkap'],
                $row['email'],
                $row['no_whatsapp'],
                $row['kategori_pelanggan'],
                $row['nama_perusahaan'] ?: 'Perorangan',
                strtoupper($row['status'])
            ]);
        }
        fclose($output);
        exit;
    }

    private function exportExcel($data, $status)
    {
        $vendorPath = 'vendor/autoload.php';
        if (file_exists($vendorPath)) {
            require_once $vendorPath;
        }

        if (!class_exists('\PhpOffice\PhpSpreadsheet\Spreadsheet')) {
            return $this->exportExcelLegacy($data, $status);
        }

        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        
        $headers = ['Tanggal Daftar', 'Nama Lengkap', 'Email', 'WhatsApp', 'Kategori', 'Perusahaan', 'Status'];
        foreach ($headers as $i => $header) {
            $sheet->setCellValueByColumnAndRow($i + 1, 1, $header);
        }

        foreach ($data as $rowIdx => $row) {
            $sheet->setCellValueByColumnAndRow(1, $rowIdx + 2, $row['created_at']);
            $sheet->setCellValueByColumnAndRow(2, $rowIdx + 2, $row['nama_lengkap']);
            $sheet->setCellValueByColumnAndRow(3, $rowIdx + 2, $row['email']);
            $sheet->setCellValueByColumnAndRow(4, $rowIdx + 2, $row['no_whatsapp']);
            $sheet->setCellValueByColumnAndRow(5, $rowIdx + 2, $row['kategori_pelanggan']);
            $sheet->setCellValueByColumnAndRow(6, $rowIdx + 2, $row['nama_perusahaan'] ?: 'Perorangan');
            $sheet->setCellValueByColumnAndRow(7, $rowIdx + 2, strtoupper($row['status']));
        }

        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment;filename="Laporan_Pelanggan_' . $status . '_' . date('Ymd_His') . '.xlsx"');
        
        $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
        $writer->save('php://output');
        exit;
    }

    private function exportPdf($data, $status)
    {
        $vendorPath = 'vendor/autoload.php';
        if (file_exists($vendorPath)) {
            require_once $vendorPath;
        }

        if (!class_exists('\TCPDF')) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Library PDF belum terinstall di server Mitra. Silakan hubungi admin untuk menjalankan: composer require tecnickcom/tcpdf']);
            exit;
        }

        $pdf = new \TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
        $pdf->SetCreator('SELPORT');
        $pdf->SetTitle('Laporan Pelanggan - ' . $status);
        $pdf->setPrintHeader(false);
        $pdf->AddPage('L');
        
        $html = '<h1>Laporan Pelanggan - ' . strtoupper($status) . '</h1>';
        $html .= '<p>Tanggal Cetak: ' . date('d-m-Y H:i:s') . '</p>';
        $html .= '<table border="1" cellpadding="5">
                    <tr style="background-color: #f2f2f2; font-weight: bold;">
                        <th>Tanggal</th>
                        <th>Nama</th>
                        <th>Email</th>
                        <th>Kategori</th>
                        <th>Perusahaan</th>
                        <th>Status</th>
                    </tr>';
        
        foreach ($data as $row) {
            $html .= '<tr>
                        <td>' . date('d/m/y', strtotime($row['created_at'])) . '</td>
                        <td>' . $row['nama_lengkap'] . '</td>
                        <td>' . $row['email'] . '</td>
                        <td>' . $row['kategori_pelanggan'] . '</td>
                        <td>' . ($row['nama_perusahaan'] ?: 'Perorangan') . '</td>
                        <td>' . strtoupper($row['status']) . '</td>
                      </tr>';
        }
        $html .= '</table>';
        
        $pdf->writeHTML($html, true, false, true, false, '');
        $pdf->Output('Laporan_Pelanggan_' . $status . '_' . date('Ymd_His') . '.pdf', 'D');
        exit;
    }

    private function exportExcelLegacy($data, $status)
    {
        $statusLabel = strtoupper($status);
        $filename = 'Laporan_Pelanggan_' . $status . '_' . date('Ymd_His') . '.xls';

        header('Content-Type: application/vnd.ms-excel');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: max-age=0');

        echo '<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns="http://www.w3.org/TR/REC-html40">';
        echo '<head><meta http-equiv="Content-Type" content="text/html; charset=utf-8"></head><body>';
        echo "<h2>LAPORAN DATA PELANGGAN - $statusLabel</h2>";
        echo '<p><b>Tanggal Export:</b> ' . date('d M Y H:i') . '</p>';
        echo '<style>td, th { border: 1px solid #000000; padding: 10px; vertical-align: middle; } th { background-color: #f2f2f2; text-align: center; font-weight: bold; }</style>';
        echo '<table border="1" style="border-collapse: collapse; width: 100%;">';
        echo '<thead>';
        echo '<tr>';
        echo '<th>Tanggal Daftar</th>';
        echo '<th>Nama Lengkap</th>';
        echo '<th>Email</th>';
        echo '<th>WhatsApp</th>';
        echo '<th>Kategori</th>';
        echo '<th>Perusahaan</th>';
        echo '<th>Status</th>';
        echo '</tr>';
        echo '</thead>';
        echo '<tbody>';

        foreach ($data as $row) {
            echo '<tr>';
            echo '<td>' . $row['created_at'] . '</td>';
            echo '<td>' . $row['nama_lengkap'] . '</td>';
            echo '<td>' . $row['email'] . '</td>';
            echo '<td>' . $row['no_whatsapp'] . '</td>';
            echo '<td>' . $row['kategori_pelanggan'] . '</td>';
            echo '<td>' . ($row['nama_perusahaan'] ?: 'Perorangan') . '</td>';
            echo '<td style="font-weight: bold; text-align: center;">' . strtoupper($row['status']) . '</td>';
            echo '</tr>';
        }
        echo '</table></body></html>';
        exit;
    }

    // GET template aktif
    private function ensureKesanggupanColumns()
    {
        static $done = false;
        if ($done) return;
        $done = true;

        try {
            $this->db->exec("ALTER TABLE kesanggupan_uploads ADD COLUMN keterangan TEXT NULL");
        } catch (\Exception $e) {
        }

        try {
            $this->db->exec("ALTER TABLE kesanggupan_uploads ADD COLUMN note TEXT NULL");
        } catch (\Exception $e) {
        }
    }

    public function getTemplateKesanggupan($f3) {
        $this->checkMitra($f3);
        header('Content-Type: application/json');
        try {
            $row = $this->db->exec(
                'SELECT tf.*, u.nama_lengkap as uploaded_by_name
                 FROM template_files tf
                 LEFT JOIN users u ON u.id = tf.uploaded_by
                 WHERE tf.jenis = ? AND tf.is_active = 1
                 ORDER BY tf.created_at DESC LIMIT 1',
                ['kesanggupan']
            );
            $data = $row ? $row[0] : null;
            if ($data) {
                $timestamp = strtotime($data['created_at'] ?? '');
                $data['uploaded_at'] = $timestamp
                    ? (function_exists('formatTanggalIndo') ? formatTanggalIndo($data['created_at']) : date('d M Y', $timestamp))
                    : ($data['created_at'] ?? '-');
                $data['created_at_formatted'] = $timestamp
                    ? date('d M Y, H.i', $timestamp) . ' WIB'
                    : ($data['created_at'] ?? '-');
                $data['file_name'] = $data['nama_asli'] ?? $data['nama_file'] ?? '-';
            }
            echo json_encode(['success' => true, 'data' => $data]);
        } catch (\Exception $e) {
            echo json_encode(['success' => false, 'data' => null, 'message' => 'Tabel template belum tersedia.']);
        }
    }

    // POST upload template baru
    public function uploadTemplateKesanggupan($f3) {
        $this->checkMitra($f3);
        $mitraId = $f3->get('SESSION.user_id');

        if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            echo json_encode(['success'=>false, 'message'=>'File tidak valid.']); return;
        }

        $file = $_FILES['file'];
        $validation = $this->validateFile($file, [
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
        ], ['doc', 'docx'], 10 * 1024 * 1024);

        if (!$validation['success']) {
            echo json_encode(['success' => false, 'message' => $validation['message']]);
            return;
        }
        if ($file['size'] > 10 * 1024 * 1024) {
            echo json_encode(['success'=>false,
                'message'=>'Ukuran file maksimal 10MB.']); return;
        }

        // Arsipkan template lama
        $this->db->exec(
            "UPDATE template_files SET is_active = 0
             WHERE jenis = 'kesanggupan' AND is_active = 1"
        );

        // Simpan file baru
        $namaAsli  = $file['name'];
        $ext       = strtolower(pathinfo($namaAsli, PATHINFO_EXTENSION));
        $namaFile  = 'template_kesanggupan_' . time() . '.' . $ext;
        $uploadDir = __DIR__ . '/../../public/uploads/templates/';
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
        move_uploaded_file($file['tmp_name'], $uploadDir . $namaFile);

        // Insert ke DB
        $this->db->exec(
            'INSERT INTO template_files (jenis, nama_file, nama_asli, uploaded_by, is_active)
             VALUES (?, ?, ?, ?, 1)',
            ['kesanggupan', $namaFile, $namaAsli, $mitraId]
        );

        echo json_encode(['success' => true,
            'message' => 'Template kesanggupan berhasil diupload.']);
    }

    // ─── Template Permohonan Alat ─────────────────────────────────────────────

    public function getTemplatePermohonanAlat($f3) {
        $this->checkMitra($f3);
        header('Content-Type: application/json');
        try {
            $row = $this->db->exec(
                'SELECT tf.*, u.nama_lengkap as joined_uploader_name
                 FROM template_files tf
                 LEFT JOIN users u ON u.id = tf.uploaded_by
                 WHERE tf.jenis = ? AND tf.is_active = 1
                 ORDER BY tf.created_at DESC LIMIT 1',
                ['permohonan-alat']
            );
            $data = $row ? $row[0] : null;
            if ($data) {
                $timestamp = strtotime($data['created_at'] ?? '');
                $data['uploaded_at'] = $timestamp
                    ? date('d M Y', $timestamp)
                    : ($data['created_at'] ?? '-');
                $data['created_at_formatted'] = $timestamp
                    ? date('d M Y, H.i', $timestamp) . ' WIB'
                    : ($data['created_at'] ?? '-');
                $data['file_name'] = $data['nama_asli'] ?? $data['nama_file'] ?? '-';
                // Prioritaskan nama yang tersimpan langsung, fallback ke JOIN dari tabel users
                $data['uploaded_by_name'] = $data['uploaded_by_name'] ?? $data['joined_uploader_name'] ?? '-';
            }
            echo json_encode(['success' => true, 'data' => $data]);
        } catch (\Exception $e) {
            echo json_encode(['success' => false, 'data' => null, 'message' => 'Tabel template belum tersedia.']);
        }
    }

    public function uploadTemplatePermohonanAlat($f3) {
        $this->checkMitra($f3);
        header('Content-Type: application/json');
        $mitraId   = $f3->get('SESSION.user_id');
        $mitraNama = $f3->get('SESSION.nama_lengkap') ?: $f3->get('SESSION.username') ?: 'Mitra';

        if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            echo json_encode(['success' => false, 'message' => 'File tidak valid.']);
            return;
        }

        $file = $_FILES['file'];
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $allowedExts = ['xlsx', 'xls', 'doc', 'docx', 'pdf'];

        $allowedMimes = [
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'xls'  => 'application/vnd.ms-excel',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'doc'  => 'application/msword',
            'pdf'  => 'application/pdf',
        ];

        if (!in_array($ext, $allowedExts)) {
            echo json_encode(['success' => false, 'message' => 'Format file tidak diizinkan. Gunakan: .xlsx, .xls, .doc, .docx, atau .pdf']);
            return;
        }
        if ($file['size'] > 10 * 1024 * 1024) {
            echo json_encode(['success' => false, 'message' => 'Ukuran file maksimal 10MB.']);
            return;
        }

        // Lazy migration: tambahkan kolom uploaded_by_name jika belum ada
        try {
            $this->db->exec("ALTER TABLE template_files ADD COLUMN uploaded_by_name VARCHAR(255) NULL");
        } catch (\Exception $e) { /* Kolom sudah ada, abaikan */ }

        // Arsipkan template lama
        $this->db->exec(
            "UPDATE template_files SET is_active = 0
             WHERE jenis = 'permohonan-alat' AND is_active = 1"
        );

        // Simpan file baru
        $namaAsli  = $file['name'];
        $namaFile  = 'template_permohonan_alat_' . time() . '.' . $ext;
        $uploadDir = __DIR__ . '/../../public/uploads/templates/';
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
        move_uploaded_file($file['tmp_name'], $uploadDir . $namaFile);

        // Insert ke DB — simpan nama pengunggah langsung karena mitra dari SIL DB
        $this->db->exec(
            'INSERT INTO template_files (jenis, nama_file, nama_asli, uploaded_by, uploaded_by_name, is_active)
             VALUES (?, ?, ?, ?, ?, 1)',
            ['permohonan-alat', $namaFile, $namaAsli, $mitraId, $mitraNama]
        );

        echo json_encode(['success' => true, 'message' => 'Template permohonan alat berhasil diupload.']);
    }

    // Serve file untuk preview/download
    public function downloadTemplate($f3) {
        $jenis = $f3->get('PARAMS.jenis') ?? 'kesanggupan';
        $row   = $this->db->exec(
            'SELECT * FROM template_files WHERE jenis = ? AND is_active = 1
             ORDER BY created_at DESC LIMIT 1',
            [$jenis]
        );
        if (!$row) { $f3->error(404); return; }

        $file = __DIR__ . '/../../public/uploads/templates/' . $row[0]['nama_file'];
        if (!file_exists($file)) { $f3->error(404); return; }

        $ext = strtolower(pathinfo($row[0]['nama_file'], PATHINFO_EXTENSION));

        // Tentukan MIME type berdasarkan ekstensi file yang sebenarnya
        $mimeMap = [
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'xls'  => 'application/vnd.ms-excel',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'doc'  => 'application/msword',
            'pdf'  => 'application/pdf',
        ];
        $mime = $mimeMap[$ext] ?? 'application/octet-stream';

        $namaAsli = $row[0]['nama_asli'] ?? $row[0]['nama_file'];

        header('Content-Type: ' . $mime);
        header('Content-Disposition: attachment; filename="' . $namaAsli . '"');
        header('Content-Length: ' . filesize($file));
        header('Cache-Control: no-cache, must-revalidate');
        readfile($file);
        exit;
    }

    public function getKesanggupanUploads($f3) {
        $this->checkMitra($f3);
        header('Content-Type: application/json');
        $this->ensureKesanggupanColumns();

        $status = $f3->get('GET.status') ?? 'all';
        $search = trim((string)($f3->get('GET.search') ?? ''));
        $where = "1=1";
        $params = [];

        if ($status !== 'all') {
            $dbStatus = $status;
            if ($status === 'Disetujui') $dbStatus = 'approved';
            if ($status === 'Perbaiki')  $dbStatus = 'rejected';
            if ($status === 'Pending')   $dbStatus = 'pending';
            $where .= " AND ku.status = ?";
            $params[] = $dbStatus;
        }

        if ($search !== '') {
            $where .= " AND (c.nama_perusahaan LIKE ? OR u.nama_lengkap LIKE ?)";
            $term = "%{$search}%";
            $params[] = $term;
            $params[] = $term;
        }

        try {
            $rows = $this->db->exec(
                "SELECT ku.*, u.nama_lengkap,
                        COALESCE(c.nama_perusahaan, u.nama_lengkap, '-') AS nama_perusahaan,
                        COALESCE(admin.username, '-') AS dikelola_oleh
                 FROM kesanggupan_uploads ku
                 JOIN users u ON ku.user_id = u.id
                 LEFT JOIN companies c ON u.company_id = c.id
                 LEFT JOIN users admin ON admin.id = ku.verified_by
                 WHERE $where ORDER BY ku.created_at DESC",
                $params
            );
            foreach ($rows as &$row) {
                $timestamp = strtotime($row['created_at'] ?? '');
                $row['created_at_formatted'] = $timestamp
                    ? (function_exists('formatTanggalIndo') ? formatTanggalIndo($row['created_at']) : date('d M Y', $timestamp))
                    : ($row['created_at'] ?? '-');
                if ($row['status'] === 'approved') $row['ui_status'] = 'Disetujui';
                elseif ($row['status'] === 'rejected') $row['ui_status'] = 'Perbaiki';
                else $row['ui_status'] = 'Pending';
            }
            echo json_encode(['success' => true, 'data' => $rows]);
        } catch (\Exception $e) {
            echo json_encode(['success' => false, 'data' => [], 'message' => 'Tabel kesanggupan belum tersedia: ' . $e->getMessage()]);
        }
    }

    public function validateKesanggupan($f3) {
        $this->checkMitra($f3);
        $this->ensureKesanggupanColumns();

        header('Content-Type: application/json');
        $id = $f3->get('POST.id');
        $status = $f3->get('POST.status');
        $note = $f3->get('POST.note');

        if (!$id || !$status) {
            echo json_encode(['success' => false, 'message' => 'Data tidak lengkap.']); return;
        }

        $dbStatus = $status;
        if ($status === 'Disetujui') $dbStatus = 'approved';
        if ($status === 'Perbaiki')  $dbStatus = 'rejected';
        if ($status === 'Pending')   $dbStatus = 'pending';

        $adminId = $f3->get('SESSION.user_id');

        try {
            $this->db->exec(
                'UPDATE kesanggupan_uploads SET status = ?, note = ?, verified_by = ?, verified_at = NOW() WHERE id = ?',
                [$dbStatus, $note, $adminId, $id]
            );
        } catch (\Exception $e) {
            echo json_encode(['success' => false, 'message' => 'Gagal memperbarui status kesanggupan: ' . $e->getMessage()]);
            return;
        }
        echo json_encode(['success' => true, 'message' => 'Status kesanggupan berhasil diperbarui.']);
    }

    public function downloadCustomerKesanggupan($f3) {
        $this->checkMitra($f3);
        $id = $f3->get('PARAMS.id');
        $row = $this->db->exec('SELECT * FROM kesanggupan_uploads WHERE id = ?', [$id]);
        if (!$row) { $f3->error(404); return; }

        $file = __DIR__ . '/../../public/uploads/kesanggupan/' . $row[0]['file_name'];
        if (!file_exists($file)) { $f3->error(404); return; }

        $ext = strtolower(pathinfo($row[0]['file_name'], PATHINFO_EXTENSION));
        $mime = 'application/octet-stream';
        if ($ext === 'pdf') $mime = 'application/pdf';
        elseif ($ext === 'docx') $mime = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
        elseif ($ext === 'doc') $mime = 'application/msword';

        header('Content-Type: ' . $mime);
        header('Content-Disposition: attachment; filename="' . $row[0]['original_name'] . '"');
        header('Content-Length: ' . filesize($file));
        readfile($file);
        exit;
    }

    public function viewCustomerKesanggupan($f3) {
        $this->checkMitra($f3);
        $id = $f3->get('PARAMS.id');
        $row = $this->db->exec('SELECT * FROM kesanggupan_uploads WHERE id = ?', [$id]);
        if (!$row) { $f3->error(404); return; }

        $file = __DIR__ . '/../../public/uploads/kesanggupan/' . $row[0]['file_name'];
        if (!file_exists($file)) { $f3->error(404); return; }

        $ext = strtolower(pathinfo($row[0]['file_name'], PATHINFO_EXTENSION));
        $mime = 'application/octet-stream';
        if ($ext === 'pdf') $mime = 'application/pdf';
        elseif ($ext === 'docx') $mime = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
        elseif ($ext === 'doc') $mime = 'application/msword';
        elseif (in_array($ext, ['jpg', 'jpeg', 'png'])) $mime = 'image/' . ($ext === 'jpg' ? 'jpeg' : $ext);

        header('Content-Type: ' . $mime);
        header('Content-Disposition: inline; filename="' . $row[0]['original_name'] . '"');
        header('Content-Length: ' . filesize($file));
        readfile($file);
        exit;
    }

    public function getKuesionerResults($f3)
    {
        $this->checkMitra($f3);
        header('Content-Type: application/json');

        // Lazy migration: Ensure tables exist

        $this->ensureKuesionerConfigsTable();
        $this->ensureDataRespondenTable();

        $search = $f3->get('GET.search');
        $where = "1=1";
        $params = [];
        if ($search) {
            $where .= " AND (dr.order_id LIKE ? OR dr.nama_lengkap LIKE ? OR dr.instansi LIKE ? OR u.id_customer LIKE ?)";
            $term = "%$search%";
            $params = [$term, $term, $term, $term];
        }

        $results = $this->db->exec(
            "SELECT dr.id, dr.user_id, u.id_customer, dr.order_id, dr.nama_lengkap, dr.instansi, dr.UR1, dr.created_at 
             FROM data_responden dr
             LEFT JOIN users u ON u.id = dr.user_id
             WHERE $where 
             ORDER BY dr.created_at DESC",
            $params
        );

        foreach ($results as &$r) {
            $r['created_at_formatted'] = function_exists('formatTanggalIndo') ? formatTanggalIndo($r['created_at']) : date('d M Y', strtotime($r['created_at']));
            // Buat signed token untuk PDF: payload = base64(id.exp) + HMAC
            $secret  = $f3->get('DOC_SECRET') ?: 'selport_kuesioner_secret';
            $payload = base64_encode(json_encode(['id' => $r['id'], 'exp' => time() + 3600]));
            $payload = strtr($payload, '+/=', '-_~');
            $sig     = hash_hmac('sha256', $payload, $secret);
            $r['pdf_token'] = $payload . '.' . $sig;
        }

        // Stats for the whole table (unfiltered)
        $stats = $this->db->exec("SELECT COUNT(*) as total FROM data_responden");

        echo json_encode([
            'success' => true, 
            'data' => $results,
            'stats' => [
                'total' => $stats[0]['total'] ?? 0
            ]
        ]);
    }
    
    public function downloadKuesionerExcel($f3)
    {
        $this->checkMitra($f3);

        // Verifikasi token
        $token  = $f3->get('PARAMS.token');
        $secret = $f3->get('DOC_SECRET') ?: 'selport_kuesioner_secret';

        $parts = explode('.', $token, 2);
        if (count($parts) !== 2) {
            $f3->error(403, 'Token tidak valid');
            return;
        }
        [$payload, $sig] = $parts;
        $expected = hash_hmac('sha256', $payload, $secret);
        if (!hash_equals($expected, $sig)) {
            $f3->error(403, 'Token tidak valid');
            return;
        }
        $data = json_decode(base64_decode(strtr($payload, '-_~', '+/=')), true);
        if (!$data || time() > ($data['exp'] ?? 0)) {
            $f3->error(403, 'Token sudah kedaluwarsa');
            return;
        }

        $id     = (int) $data['id'];
        $result = $this->db->exec("SELECT * FROM data_responden WHERE id = ?", [$id]);

        if (empty($result)) {
            $f3->error(404, 'Data kuesioner tidak ditemukan');
            return;
        }
        
        $item = $result[0];
        $date = date('d F Y', strtotime($item['created_at']));
        
        $filename = 'Kuesioner_' . $item['order_id'] . '.xls';
        header("Content-Type: application/vnd.ms-excel");
        header("Content-Disposition: attachment; filename=\"$filename\"");
        header("Pragma: no-cache");
        header("Expires: 0");

        $html = "
        <table border=\"1\" cellpadding=\"4\">
        <br>
            <tr>
                <th colspan=\"4\" style=\"font-size: 16px; font-weight: bold; text-align: center;\">HASIL KUESIONER PELANGGAN</th>
            </tr>
            <tr><td colspan=\"4\"></td></tr>
            <tr>
                <td colspan=\"2\"><b>Nama Responden</b></td>
                <td colspan=\"2\">: {$item['nama_lengkap']}</td>
            </tr>
            <tr>
                <td colspan=\"2\"><b>Instansi/Perusahaan</b></td>
                <td colspan=\"2\">: " . ($item['instansi'] ? $item['instansi'] : '-') . "</td>
            </tr>
            <tr>
                <td colspan=\"2\"><b>Jenis Pelayanan</b></td>
                <td colspan=\"2\">: " . ($item['jenis_pelayanan'] ? $item['jenis_pelayanan'] : '-') . "</td>
            </tr>
            <tr>
                <td colspan=\"2\"><b>Tanggal Pengisian</b></td>
                <td colspan=\"2\">: {$date}</td>
            </tr>
            <tr><td colspan=\"4\"></td></tr>
            <tr><td colspan=\"4\"><b>Rincian Jawaban:</b></td></tr>
            <tr style=\"background-color:#d1d5db; font-weight:bold;\">
                <th width=\"5%\" align=\"center\">No</th>
                <th width=\"10%\" align=\"center\">Kode</th>
                <th width=\"45%\">Pertanyaan</th>
                <th width=\"40%\">Jawaban</th>
            </tr>";
            
        $questionMap = [
            'U1' => 'Bagaimana menurut Saudara tentang persyaratan pelayanan yang harus dipenuhi, apakah telah sesuai dengan informasi yang diperoleh ?',
            'U2' => 'Bagaimana menurut Saudara tentang kemudahan prosedur pelayanan di Unit ini ?',
            'T1' => 'Melalui media apa Saudara memperoleh informasi prosedur dan persyaratan pelayanan Unit ini ?',
            'T2' => 'Bagaimana pendapat Saudara tentang kejelasan informasi prosedur dan persyaratan pelayanan Unit ini ?',
            'T3' => 'Alasan memilih Tidak Jelas/Kurang Jelas pada T2 (abaikan jika memilih jelas/sangat jelas)',
            'U3' => 'Bagaimana pendapat Saudara tentang ketepatan waktu penyelesaian pelayanan di Unit ini ?',
            'U4' => 'Bagaimana pendapat saudara tentang kesesuaian biaya pelayanan di unit ini ?',
            'T4' => 'Jika tidak sesuai dengan ketentuan, biaya apa yang dibebankan kepada saudara ?',
            'T5' => 'Jika tidak sesuai dengan ketentuan, berapa besar biaya yang saudara bayarkan ?',
            'U5' => 'Bagaimana pendapat Saudara tentang kesesuaian produk pelayanan antara ketentuan dengan hasil yang diberikan oleh Unit ini ?',
            'U6' => 'Bagaimana pendapat Saudara tentang kemampuan petugas pelayanan dalam memberikan pelayanan ?',
            'U7' => 'Bagaimana pendapat Saudara tentang sikap atau perilaku petugas pelayanan dalam memberikan pelayanan ?',
            'U8' => 'Bagaimana menurut Saudara mengenai kemudahan akses layanan keluhan di Unit ini ?',
            'T6' => 'Apakah Saudara pernah mengajukan keluhan di Unit ini ?',
            'T7' => 'Jika Iya, Berkaitan dengan apa isi keluhan yang saudara sampaikan?',
            'T8' => 'Jika Iya, Uraikan isi keluhan yang pernah saudara sampaikan',
            'T9' => 'Jika Iya, Bagaimana pendapat saudara tentang ketanggapan petugas pelayanan terhadap keluhan yang disampaikan ?',
            'T10' => 'Alasan memilih Tidak Tanggap/Kurang Tanggap pada nomor T9 ?',
            'T11' => 'Jika Iya, apakah tindak lanjut atas keluhan yang disampaikan telah sesuai dengan yang saudara harapkan ?',
            'T12' => 'Alasan memilih Tidak Sesuai pada nomor T11 ?',
            'U9_1' => 'Bagaimana pendapat saudara tentang kondisi ruang tunggu unit pelayanan kami ?',
            'U9_2' => 'Bagaimana pendapat Saudara tentang kondisi toilet unit pelayanan kami ?',
            'U9_3' => 'Bagaimana pendapat Saudara tentang kondisi fasilitas untuk disabilitas unit pelayanan kami ?',
            'U9_4' => 'Bagaimana pendapat saudara tentang kondisi tempat parkir di Unit pelayanan kami?',
            'U9_5' => 'Bagaimana pendapat saudara tentang kondisi sarana keamanan (pemeriksaan masuk gedung, penitipan barang, dll) di unit pelayanan kami ?',
            'U9_6' => 'Bagaimana pendapat saudara tentang kondisi kantin di unit pelayanan kami ?',
            'T13' => 'Jika ada, inovasi pelayanan apa yang saudara harapkan dari Unit pelayanan kami?',
            'T14' => 'Jelaskan alasan dari inovasi pelayanan yang Saudara harapkan ?',
            'opini' => 'Saran/ opini Anda Untuk Meningkatkan Kualitas Pelayanan Jasa di BBSPJIS :',
            'UK1' => 'Bagaimana menurut saudara tentang prosedur pelayanan yang ditetapkan, apakah sudah memadai dan bersih dari korupsi, kolusi, dan nepotisme (KKN) ?',
            'UK2' => 'Bagaimana menurut saudara tentang petugas pelayanan, apakah petugas pelayanan bersih tidak memberikan pelayanan di luar prosedur yang telah ditetapkan dengan imbalan uang/barang ?',
            'UK3' => 'Bagaimana menurut saudara tentang praktik percaloan, apakah Unit ini bersih tidak terdapat praktik percaloan / perantara yang tidak resmi ?',
            'UK4' => 'Bagaimana menurut saudara tentang petugas pelayanan, apakah Petugas pelayanan bersikap adil dan tidak diskriminatif ?',
            'UK5' => 'Bagaimana menurut saudara tentang pungutan liar, apakah Unit ini bersih tidak terdapat pungutan liar ?',
            'UK6' => 'Bagaimana menurut saudara tentang petugas pelayanan, apakah petugas pelayanan tidak meminta / menuntut imbalan uang / barang terkait pelayanan yang diberikan ?',
            'UK7' => 'Bagaimana menurut saudara tentang petugas pelayanan, apakah petugas pelayanan bersih menolak uang / barang terkait pelayanan yang diberikan ?',
            'UK8' => 'Bagaimana menurut saudara tentang penanganan keluhan di unit ini, apakah penanganan keluhan dilakukan dengan adil tidak ada diskriminasi ?',
            'UR1' => 'Seberapa besar kemungkinan saudara merekomendasikan produk pelayanan BBSPJIS kepada mitra atau kolega saudara ?'
        ];
        
        $no = 1;
        foreach ($item as $key => $val) {
            if (preg_match('/^(U\d+|T\d+|UK\d+|UR\d+|opini)$/', $key) && $val !== null && $val !== '') {
                $qText = $questionMap[$key] ?? 'Teks pertanyaan untuk ' . $key;
                $html .= "
                <tr>
                    <td align=\"center\">{$no}</td>
                    <td align=\"center\">{$key}</td>
                    <td>{$qText}</td>
                    <td>{$val}</td>
                </tr>";
                $no++;
            }
        }
        
        $html .= "</table>";
        
        echo $html;
        exit;
    }

    public function downloadKuesionerRekap($f3)
    {
        $this->checkMitra($f3);
        $this->ensureDataRespondenTable();

        $search = trim((string)($f3->get('GET.search') ?? ''));
        $tahun  = trim((string)($f3->get('GET.tahun')  ?? ''));

        $where  = "1=1";
        $params = [];

        if ($search !== '') {
            $where .= " AND (dr.instansi LIKE ? OR dr.nama_lengkap LIKE ? OR dr.order_id LIKE ?)";
            $term = "%{$search}%";
            $params[] = $term;
            $params[] = $term;
            $params[] = $term;
        }
        if ($tahun !== '') {
            $where .= " AND YEAR(dr.created_at) = ?";
            $params[] = (int)$tahun;
        }

        $results = $this->db->exec(
            "SELECT * FROM data_responden dr WHERE $where ORDER BY dr.created_at DESC",
            $params
        );

        $filterLabel = '';
        if ($tahun)  $filterLabel .= ' | Tahun: ' . $tahun;
        if ($search) $filterLabel .= ' | Kata kunci: ' . $search;

        // Bersihkan output buffer sebelum kirim header
        if (ob_get_level()) ob_end_clean();

        $filename = 'Rekap_Kuesioner_' . date('Ymd_His') . '.xls';
        header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Pragma: no-cache');
        header('Expires: 0');

        // Kolom header
        $columns = [
            'No'                    => '5%',
            'Tanggal Pengisian'     => '10%',
            'Nama Responden'        => '14%',
            'Instansi / Perusahaan' => '14%',
            'Jenis Pelayanan'       => '12%',
            'Usia'                  => '5%',
            'Jenis Kelamin'         => '7%',
            'Pendidikan'            => '8%',
            'U1' => '6%', 'U2' => '6%',
            'T1' => '6%', 'T2' => '6%', 'T3' => '10%',
            'U3' => '6%', 'U4' => '6%',
            'T4' => '10%', 'T5' => '10%',
            'U5' => '6%', 'U6' => '6%', 'U7' => '6%', 'U8' => '6%',
            'T6' => '6%', 'T7' => '10%', 'T8' => '14%',
            'T9' => '6%', 'T10' => '10%', 'T11' => '6%', 'T12' => '10%',
            'U9.1' => '6%', 'U9.2' => '6%', 'U9.3' => '6%',
            'U9.4' => '6%', 'U9.5' => '6%', 'U9.6' => '6%',
            'T13' => '14%', 'T14' => '14%',
            'Saran / Opini'          => '18%',
            'UK1' => '6%', 'UK2' => '6%', 'UK3' => '6%', 'UK4' => '6%',
            'UK5' => '6%', 'UK6' => '6%', 'UK7' => '6%', 'UK8' => '6%',
            'UR1 (Rekomendasi 1-10)' => '8%',
        ];

        $esc = function($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); };

        echo '<html><head><meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
        <style>
            body { font-family: Arial, sans-serif; font-size: 10pt; }
            table { border-collapse: collapse; width: 100%; }
            td, th { border: 1px solid #b0b0b0; padding: 5px 7px; vertical-align: top; }
            .title-row td { background: #1e3a5f; color: #ffffff; font-size: 14pt; font-weight: bold; text-align: center; padding: 12px; border: 1px solid #1e3a5f; }
            .meta-row td { background: #e8f0fe; color: #1e3a5f; font-size: 9pt; padding: 4px 7px; }
            .header-row th { background: #2c5282; color: #ffffff; font-weight: bold; text-align: center; font-size: 9pt; word-wrap: break-word; }
            .data-row-even td { background: #f7faff; }
            .data-row-odd  td { background: #ffffff; }
            .center { text-align: center; }
            .bold { font-weight: bold; }
            .wrap { word-wrap: break-word; white-space: pre-wrap; }
            .no-col { text-align: center; width: 30px; }
        </style>
        </head><body>';

        echo '<table>';

        // Judul
        $colCount = count($columns);
        echo '<tr class="title-row"><td colspan="' . $colCount . '">REKAPAN KUESIONER PELANGGAN</td></tr>';

        // Meta info
        echo '<tr class="meta-row">';
        echo '<td colspan="' . $colCount . '">';
        echo 'Tanggal Cetak: <b>' . date('d F Y H:i') . ' WIB</b>';
        echo ' &nbsp;|&nbsp; Total Responden: <b>' . count($results) . '</b>';
        if ($filterLabel) echo ' &nbsp;|&nbsp; Filter: <b>' . $esc(ltrim($filterLabel, ' | ')) . '</b>';
        echo '</td></tr>';

        // Baris kosong pemisah
        echo '<tr><td colspan="' . $colCount . '" style="border:none;height:6px;background:#fff;"></td></tr>';

        // Header kolom
        echo '<tr class="header-row">';
        foreach ($columns as $label => $width) {
            echo '<th style="width:' . $width . '">' . $esc($label) . '</th>';
        }
        echo '</tr>';

        // Baris data
        if (empty($results)) {
            echo '<tr><td colspan="' . $colCount . '" class="center" style="color:#888;padding:16px;">Tidak ada data.</td></tr>';
        } else {
            foreach ($results as $idx => $item) {
                $rowClass = ($idx % 2 === 0) ? 'data-row-even' : 'data-row-odd';
                echo '<tr class="' . $rowClass . '">';

                $cells = [
                    '<td class="center">' . ($idx + 1) . '</td>',
                    '<td class="center">' . $esc(date('d-m-Y', strtotime($item['created_at'] ?? ''))) . '</td>',
                    '<td>' . $esc($item['nama_lengkap']    ?? '') . '</td>',
                    '<td>' . $esc($item['instansi']        ?? '') . '</td>',
                    '<td>' . $esc($item['jenis_pelayanan'] ?? '') . '</td>',
                    '<td class="center">' . $esc($item['usia']          ?? '') . '</td>',
                    '<td class="center">' . $esc($item['jenis_kelamin'] ?? '') . '</td>',
                    '<td class="center">' . $esc($item['pendidikan']    ?? '') . '</td>',
                    '<td class="center">' . $esc($item['U1']  ?? '') . '</td>',
                    '<td class="center">' . $esc($item['U2']  ?? '') . '</td>',
                    '<td class="wrap">'   . $esc($item['T1']  ?? '') . '</td>',
                    '<td class="center">' . $esc($item['T2']  ?? '') . '</td>',
                    '<td class="wrap">'   . $esc($item['T3']  ?? '') . '</td>',
                    '<td class="center">' . $esc($item['U3']  ?? '') . '</td>',
                    '<td class="center">' . $esc($item['U4']  ?? '') . '</td>',
                    '<td class="wrap">'   . $esc($item['T4']  ?? '') . '</td>',
                    '<td class="wrap">'   . $esc($item['T5']  ?? '') . '</td>',
                    '<td class="center">' . $esc($item['U5']  ?? '') . '</td>',
                    '<td class="center">' . $esc($item['U6']  ?? '') . '</td>',
                    '<td class="center">' . $esc($item['U7']  ?? '') . '</td>',
                    '<td class="center">' . $esc($item['U8']  ?? '') . '</td>',
                    '<td class="center">' . $esc($item['T6']  ?? '') . '</td>',
                    '<td class="wrap">'   . $esc($item['T7']  ?? '') . '</td>',
                    '<td class="wrap">'   . $esc($item['T8']  ?? '') . '</td>',
                    '<td class="center">' . $esc($item['T9']  ?? '') . '</td>',
                    '<td class="wrap">'   . $esc($item['T10'] ?? '') . '</td>',
                    '<td class="center">' . $esc($item['T11'] ?? '') . '</td>',
                    '<td class="wrap">'   . $esc($item['T12'] ?? '') . '</td>',
                    '<td class="center">' . $esc($item['U9_1'] ?? '') . '</td>',
                    '<td class="center">' . $esc($item['U9_2'] ?? '') . '</td>',
                    '<td class="center">' . $esc($item['U9_3'] ?? '') . '</td>',
                    '<td class="center">' . $esc($item['U9_4'] ?? '') . '</td>',
                    '<td class="center">' . $esc($item['U9_5'] ?? '') . '</td>',
                    '<td class="center">' . $esc($item['U9_6'] ?? '') . '</td>',
                    '<td class="wrap">'   . $esc($item['T13']  ?? '') . '</td>',
                    '<td class="wrap">'   . $esc($item['T14']  ?? '') . '</td>',
                    '<td class="wrap">'   . $esc($item['opini'] ?? '') . '</td>',
                    '<td class="center">' . $esc($item['UK1'] ?? '') . '</td>',
                    '<td class="center">' . $esc($item['UK2'] ?? '') . '</td>',
                    '<td class="center">' . $esc($item['UK3'] ?? '') . '</td>',
                    '<td class="center">' . $esc($item['UK4'] ?? '') . '</td>',
                    '<td class="center">' . $esc($item['UK5'] ?? '') . '</td>',
                    '<td class="center">' . $esc($item['UK6'] ?? '') . '</td>',
                    '<td class="center">' . $esc($item['UK7'] ?? '') . '</td>',
                    '<td class="center">' . $esc($item['UK8'] ?? '') . '</td>',
                    '<td class="center bold">' . $esc($item['UR1'] ?? '') . '</td>',
                ];

                echo implode('', $cells);
                echo '</tr>';
            }
        }

        echo '</table></body></html>';
        exit;
    }

    public function getKuesionerDetail($f3)
    {
        $this->checkMitra($f3);
        header('Content-Type: application/json');

        $id = $f3->get('PARAMS.id');
        $result = $this->db->exec("SELECT * FROM data_responden WHERE id = ?", [$id]);

        if (empty($result)) {
            echo json_encode(['success' => false, 'message' => 'Data tidak ditemukan']);
            return;
        }

        echo json_encode(['success' => true, 'data' => $result[0]]);
    }



    public function getKuesionerSettings($f3)
    {
        $this->checkMitra($f3);
        header('Content-Type: application/json');
        $this->ensureKuesionerConfigsTable();

        $configs = $this->db->exec("SELECT * FROM konfigurasi_kuesioner");
        $data = [];
        foreach ($configs as $cfg) {
            $data[$cfg['config_key']] = $cfg['config_value'];
        }

        echo json_encode(['success' => true, 'data' => $data]);
    }

    public function updateKuesionerSettings($f3)
    {
        $this->checkMitra($f3);
        header('Content-Type: application/json');

        $this->ensureKuesionerConfigsTable();

        $intervalValue = $f3->get('POST.kuesioner_interval_value');
        $intervalUnit = $f3->get('POST.kuesioner_interval_unit');
        
        try {
            $this->db->begin();
            $this->db->exec(
                "UPDATE konfigurasi_kuesioner SET config_value = ? WHERE config_key = 'kuesioner_interval_value'",
                [$intervalValue]
            );
            $this->db->exec(
                "UPDATE konfigurasi_kuesioner SET config_value = ? WHERE config_key = 'kuesioner_interval_unit'",
                [$intervalUnit]
            );
            $this->db->commit();
            echo json_encode(['success' => true, 'message' => 'Pengaturan berhasil diperbarui']);
        } catch (\Exception $e) {
            $this->db->rollback();
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    private function ensureKuesionerConfigsTable()
    {
        $this->db->exec("CREATE TABLE IF NOT EXISTS konfigurasi_kuesioner (
            id INT AUTO_INCREMENT PRIMARY KEY,
            config_key VARCHAR(50) UNIQUE NOT NULL,
            config_value TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )");

        $defaults = [
            'kuesioner_interval_value' => '3',
            'kuesioner_interval_unit' => 'bulan'
        ];

        foreach ($defaults as $key => $val) {
            $check = $this->db->exec("SELECT id FROM konfigurasi_kuesioner WHERE config_key = ?", [$key]);
            if (empty($check)) {
                $this->db->exec("INSERT INTO konfigurasi_kuesioner (config_key, config_value) VALUES (?, ?)", [$key, $val]);
            }
        }
    }

    private function ensureDataRespondenTable()
    {
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
            U1 VARCHAR(255),
            U2 VARCHAR(255),
            T1 VARCHAR(255),
            T2 VARCHAR(255),
            T3 TEXT,
            U3 VARCHAR(255),
            U4 VARCHAR(255),
            T4 TEXT,
            T5 TEXT,
            U5 VARCHAR(255),
            U6 VARCHAR(255),
            U7 VARCHAR(255),
            U8 VARCHAR(255),
            T6 VARCHAR(255),
            T7 VARCHAR(255),
            T8 TEXT,
            T9 VARCHAR(255),
            T10 TEXT,
            T11 VARCHAR(255),
            T12 TEXT,
            U9_1 VARCHAR(255),
            U9_2 VARCHAR(255),
            U9_3 VARCHAR(255),
            U9_4 VARCHAR(255),
            U9_5 VARCHAR(255),
            U9_6 VARCHAR(255),
            T13 TEXT,
            T14 TEXT,
            opini TEXT,
            UK1 VARCHAR(255),
            UK2 VARCHAR(255),
            UK3 VARCHAR(255),
            UK4 VARCHAR(255),
            UK5 VARCHAR(255),
            UK6 VARCHAR(255),
            UK7 VARCHAR(255),
            UK8 VARCHAR(255),
            UR1 INT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )");
    }


}
