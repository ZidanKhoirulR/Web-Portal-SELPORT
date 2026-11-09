<?php

class KeuanganController
{
    private $db;
    private $dbSil;
    private $tracking;
    private $notifikasiService;

    public function __construct($db, $notifikasiService = null, $dbSil = null)
    {
        $this->db = $db;
        $this->dbSil = $dbSil;
        $this->tracking = new TrackingService($db);
        $this->notifikasiService = $notifikasiService;
    }

    private function checkSuperKeuangan($f3)
    {
        $role = $f3->get('SESSION.role');
        if ($role !== 'keuangan') {
            $f3->reroute('/login');
            exit;
        }
    }

    public function index($f3, $section = 'ringkasan')
    {
        $this->checkSuperKeuangan($f3);
        $f3->set('SECTION', $section);
        echo \Template::instance()->render('keuangan/dashboard.html');
    }

    public function loadSection($f3, $file)
    {
        $this->checkSuperKeuangan($f3);
        $path = "keuangan/{$file}.html";
        if (file_exists("ui/".$path)) {
            $html = file_get_contents("ui/".$path);
            $html = str_replace('{{ @BASE }}', $f3->get('BASE'), $html);
            echo $html;
        } else {
            echo "Section not found: {$file}";
        }
    }

    public function getStats($f3)
    {
        $this->checkSuperKeuangan($f3);
        
        // Lazy migration untuk kolom revisi
        $revCols = ['revision_count INT DEFAULT 0','perbaikan_1_at DATETIME DEFAULT NULL','perbaikan_1_file VARCHAR(500) DEFAULT NULL','perbaikan_2_at DATETIME DEFAULT NULL','perbaikan_2_file VARCHAR(500) DEFAULT NULL','perbaikan_3_at DATETIME DEFAULT NULL','perbaikan_3_file VARCHAR(500) DEFAULT NULL'];
        foreach ($revCols as $col) { 
            try { $this->db->exec("ALTER TABLE payment_uploads ADD COLUMN $col"); } catch (\Exception $e) {} 
            try { $this->db->exec("ALTER TABLE kesanggupan_uploads ADD COLUMN $col"); } catch (\Exception $e) {}
        }

        $totalPay = $this->db->exec("SELECT COUNT(*) as c FROM payment_uploads")[0]['c'];
        $pendingPay = $this->db->exec("SELECT COUNT(*) as c FROM payment_uploads WHERE status='Menunggu'")[0]['c'];
        $approvedPay = $this->db->exec("SELECT COUNT(*) as c FROM payment_uploads WHERE status='Diverifikasi'")[0]['c'];
        $rejectedPay = $this->db->exec("SELECT COUNT(*) as c FROM payment_uploads WHERE status='Gagal'")[0]['c'];

        $totalKes = $this->db->exec("SELECT COUNT(*) as c FROM kesanggupan_uploads")[0]['c'];
        $pendingKes = $this->db->exec("SELECT COUNT(*) as c FROM kesanggupan_uploads WHERE status='pending'")[0]['c'];
        $approvedKes = $this->db->exec("SELECT COUNT(*) as c FROM kesanggupan_uploads WHERE status='approved'")[0]['c'];
        $rejectedKes = $this->db->exec("SELECT COUNT(*) as c FROM kesanggupan_uploads WHERE status='rejected'")[0]['c'];
        
        echo json_encode([
            'pay_total' => $totalPay,
            'pay_pending' => $pendingPay,
            'pay_approved' => $approvedPay,
            'pay_rejected' => $rejectedPay,
            'kes_total' => $totalKes,
            'kes_pending' => $pendingKes,
            'kes_approved' => $approvedKes,
            'kes_rejected' => $rejectedKes
        ]);
    }

    public function getPayments($f3)
    {
        $this->checkSuperKeuangan($f3);
        
        $search = $f3->get('GET.search');
        $status = $f3->get('GET.status'); // optional filter
        
        $where = "WHERE 1=1";
        $params = [];

        if ($search) {
            $where .= " AND (c.nama_perusahaan LIKE ?)";
            $term = "%$search%";
            $params = [$term];
        }

        if ($status && $status !== 'all') {
            $where .= " AND p.status = ?";
            $params[] = $status;
        }

        $sql = "SELECT p.*, COALESCE(p.revision_count, 0) as revision_count,
                       u.nama_lengkap, u.username, u.email, u.no_whatsapp, c.nama_perusahaan
                FROM payment_uploads p 
                LEFT JOIN users u ON p.user_id = u.id 
                LEFT JOIN companies c ON u.company_id = c.id
                $where 
                ORDER BY p.created_at DESC";
        
        $payments = $this->db->exec($sql, $params);

        if (!empty($payments)) {
            // Filter hanya order_no yang tidak NULL/kosong untuk query SIL
            $orderNos = array_values(array_filter(array_column($payments, 'order_no'), function($v) {
                return !empty($v);
            }));

            $silMap = [];

            if ($this->dbSil && !empty($orderNos)) {
                $placeholders = str_repeat('?,', count($orderNos) - 1) . '?';
                // Fetch jenis_contoh from SIL
                $silData = $this->dbSil->exec("SELECT No_order as order_no, Ket_contoh as jenis_contoh FROM tb_penerimaan_contoh WHERE No_order IN ($placeholders)", $orderNos);
                foreach ($silData as $sd) {
                    $silMap[$sd['order_no']] = $sd['jenis_contoh'];
                }
            }

            // Fetch admin names for verified_by from tb_arsipuser
            $verifiedBys = array_values(array_filter(array_unique(array_column($payments, 'verified_by'))));
            $adminMap = [];
            if ($this->dbSil && !empty($verifiedBys)) {
                $placeholders = str_repeat('?,', count($verifiedBys) - 1) . '?';
                $adminData = $this->dbSil->exec("SELECT id_user, nama_user FROM tb_arsipuser WHERE id_user IN ($placeholders)", $verifiedBys);
                foreach ($adminData as $ad) {
                    $adminMap[$ad['id_user']] = $ad['nama_user'];
                }
            }

            foreach ($payments as &$p) {
                $p['jenis_contoh'] = $silMap[$p['order_no'] ?? ''] ?? '-';
                $p['verified_by_name'] = !empty($p['verified_by']) && isset($adminMap[$p['verified_by']]) 
                    ? $adminMap[$p['verified_by']] 
                    : null;
                    
                if (!empty($p['file_name'])) {
                    $p['preview_token'] = doc_make_token($p['file_name'], 'payment-upload');
                }

                // Hitung label status untuk UI Keuangan
                $revCount = (int) ($p['revision_count'] ?? 0);
                $dbStatus = $p['status'] ?? '';
                if ($dbStatus === 'Diverifikasi') {
                    $p['ui_status_label'] = 'Disetujui';
                } elseif ($dbStatus === 'Gagal') {
                    $nextRev = $revCount + 1;
                    $p['ui_status_label'] = $nextRev > 3 ? 'Perbaiki (Maks)' : "Perbaiki {$nextRev}";
                } elseif ($dbStatus === 'Menunggu') {
                    $p['ui_status_label'] = $revCount > 0 ? "Re-upload (Perbaikan {$revCount})" : 'Pending';
                } else {
                    $p['ui_status_label'] = 'Pending';
                }
            }
        }
        
        echo json_encode(['data' => $payments]);
    }

    public function downloadPayment($f3)
    {
        $this->checkSuperKeuangan($f3);
        $id = $f3->get('PARAMS.id');

        if (!$id) {
            $f3->error(400, 'ID tidak valid');
            return;
        }

        try {
            $doc = $this->db->exec("SELECT file_path, file_name FROM payment_uploads WHERE id = ?", $id);
            if (empty($doc)) {
                $f3->error(404, 'Dokumen tidak ditemukan');
                return;
            }

            $filePath = $doc[0]['file_path'];

            if (!file_exists($filePath)) {
                $f3->error(404, 'File fisik tidak ditemukan di server.');
                return;
            }

            $originalName = $doc[0]['file_name'];
            $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
            
            $mimeTypes = [
                'pdf' => 'application/pdf',
                'jpg' => 'image/jpeg',
                'jpeg' => 'image/jpeg',
                'png' => 'image/png',
                'gif' => 'image/gif'
            ];
            $contentType = $mimeTypes[$ext] ?? 'application/octet-stream';
            
            header('Content-Type: ' . $contentType);
            header('Content-Disposition: inline; filename="' . $originalName . '"');
            header('Expires: 0');
            header('Cache-Control: must-revalidate');
            header('Pragma: public');
            header('Content-Length: ' . filesize($filePath));
            
            readfile($filePath);
            exit;
        } catch (\Exception $e) {
            $f3->error(500, 'Terjadi kesalahan: ' . $e->getMessage());
        }
    }

    public function verifyPayment($f3)
    {
        $this->checkSuperKeuangan($f3);
        header('Content-Type: application/json');
        
        $id = $f3->get('POST.id');
        $admin_id = $f3->get('SESSION.user_id');

        if (!$id) {
            echo json_encode(['success' => false, 'message' => 'Data tidak lengkap']);
            return;
        }

        $payment = $this->db->exec("SELECT * FROM payment_uploads WHERE id = ?", $id);
        if (empty($payment)) {
            echo json_encode(['success' => false, 'message' => 'Pembayaran tidak ditemukan']);
            return;
        }

        try {
            $this->db->exec("UPDATE payment_uploads SET status = 'Diverifikasi', reason = NULL, verified_by = ?, verified_at = NOW() WHERE id = ?", [$admin_id, $id]);

            if ($this->dbSil) {
                $this->dbSil->exec(
                    "UPDATE tb_arsip_detail 
                     SET pembayaran_tanggal_bayar = DATE(NOW())
                     WHERE id_arsip = ? AND (pembayaran_tanggal_bayar IS NULL OR pembayaran_tanggal_bayar = '0000-00-00')",
                    [$payment[0]['order_id']]
                );
            }

            if ($this->notifikasiService) {
                $this->notifikasiService->create(
                    $payment[0]['user_id'],
                    'Pembayaran Diverifikasi',
                    'Pembayaran untuk order ' . $payment[0]['order_no'] . ' telah diverifikasi.',
                    'success',
                    'dashboard/section/pembayaran'
                );
            }

            echo json_encode(['success' => true]);
        } catch (\Throwable $e) {
            echo json_encode(['success' => false, 'message' => 'SysError: ' . $e->getMessage()]);
        }
    }

    public function cancelVerifyPayment($f3)
    {
        $this->checkSuperKeuangan($f3);
        header('Content-Type: application/json');
        
        $id = $f3->get('POST.id');
        $admin_id = $f3->get('SESSION.user_id');

        if (!$id) {
            echo json_encode(['success' => false, 'message' => 'Data tidak lengkap']);
            return;
        }

        $payment = $this->db->exec("SELECT * FROM payment_uploads WHERE id = ?", $id);
        if (empty($payment)) {
            echo json_encode(['success' => false, 'message' => 'Pembayaran tidak ditemukan']);
            return;
        }

        if ($payment[0]['status'] !== 'Diverifikasi') {
            echo json_encode(['success' => false, 'message' => 'Hanya pembayaran tervalidasi yang dapat dibatalkan']);
            return;
        }

        $this->db->begin();
        try {
            $this->db->exec("UPDATE payment_uploads SET status = 'Menunggu', verified_by = NULL, verified_at = NULL WHERE id = ?", [$id]);

            if ($this->dbSil) {
                $this->dbSil->exec(
                    "UPDATE tb_arsip_detail 
                     SET pembayaran_tanggal_bayar = NULL
                     WHERE id_arsip = ?",
                    [$payment[0]['order_id']]
                );
            }

            if ($this->notifikasiService) {
                $this->notifikasiService->create(
                    $payment[0]['user_id'],
                    'Validasi Pembayaran Dibatalkan',
                    'Validasi pembayaran untuk order ' . $payment[0]['order_no'] . ' ditarik kembali oleh tim Keuangan.',
                    'warning',
                    'dashboard/section/pembayaran'
                );
            }
            $this->db->commit();
            echo json_encode(['success' => true]);
        } catch (\Throwable $e) {
            $this->db->rollback();
            echo json_encode(['success' => false, 'message' => 'SysError: ' . $e->getMessage()]);
        }
    }

    public function rejectPayment($f3)
    {
        $this->checkSuperKeuangan($f3);
        header('Content-Type: application/json');
        
        $id = $f3->get('POST.id');
        $reason = $f3->get('POST.reason');
        $admin_id = $f3->get('SESSION.user_id');

        if (!$id || !$reason) {
            echo json_encode(['success' => false, 'message' => 'ID dan Alasan penolakan wajib diisi']);
            return;
        }

        $payment = $this->db->exec("SELECT * FROM payment_uploads WHERE id = ?", $id);
        if (empty($payment)) {
            echo json_encode(['success' => false, 'message' => 'Pembayaran tidak ditemukan']);
            return;
        }

        try {
            $this->db->exec("UPDATE payment_uploads SET status = 'Gagal', reason = ?, verified_by = ?, verified_at = NOW() WHERE id = ?", [$reason, $admin_id, $id]);

            if ($this->dbSil) {
                $this->dbSil->exec(
                    "UPDATE tb_arsip_detail 
                     SET pembayaran_tanggal_bayar = NULL
                     WHERE id_arsip = ?",
                    [$payment[0]['order_id']]
                );
            }

            if ($this->notifikasiService) {
                $this->notifikasiService->create(
                    $payment[0]['user_id'],
                    'Pembayaran Gagal',
                    'Pembayaran untuk order ' . $payment[0]['order_no'] . ' gagal. Alasan: ' . $reason,
                    'error',
                    'dashboard/section/pembayaran'
                );
            }

            echo json_encode(['success' => true]);
        } catch (\Throwable $e) {
            echo json_encode(['success' => false, 'message' => 'SysError: ' . $e->getMessage()]);
        }
    }

    public function updatePayment($f3)
    {
        $this->checkSuperKeuangan($f3);
        header('Content-Type: application/json');

        $id       = $f3->get('POST.id');
        $status   = $f3->get('POST.status');
        $reason   = trim($f3->get('POST.reason') ?? '');
        $adminId  = $f3->get('SESSION.user_id');

        $allowed = ['Menunggu', 'Diverifikasi', 'Gagal'];
        if (!$id || !in_array($status, $allowed)) {
            echo json_encode(['success' => false, 'message' => 'Data tidak valid.']);
            return;
        }

        $payment = $this->db->exec("SELECT * FROM payment_uploads WHERE id = ?", $id);
        if (empty($payment)) {
            echo json_encode(['success' => false, 'message' => 'Pembayaran tidak ditemukan.']);
            return;
        }

        try {
            $this->db->exec(
                "UPDATE payment_uploads SET status = ?, reason = ?, verified_by = ?, verified_at = NOW(), updated_at = NOW() WHERE id = ?",
                [$status, $reason ?: null, $adminId, $id]
            );

            // Notifikasi ke pelanggan
            if ($this->notifikasiService) {
                $notifMap = [
                    'Diverifikasi' => ['Pembayaran Diverifikasi', 'Pembayaran Anda telah diverifikasi.', 'success'],
                    'Gagal'        => ['Pembayaran Ditolak', 'Pembayaran Anda ditolak. Alasan: ' . $reason, 'error'],
                    'Menunggu'     => ['Status Pembayaran Diperbarui', 'Status pembayaran Anda diperbarui menjadi Menunggu.', 'warning'],
                ];
                $notif = $notifMap[$status] ?? null;
                if ($notif) {
                    $this->notifikasiService->create(
                        $payment[0]['user_id'],
                        $notif[0], $notif[1], $notif[2],
                        'dashboard/section/pembayaran'
                    );
                }
            }

            echo json_encode(['success' => true]);
        } catch (\Throwable $e) {
            echo json_encode(['success' => false, 'message' => 'SysError: ' . $e->getMessage()]);
        }
    }

    public function updateAccount($f3)
    {
        $this->checkSuperKeuangan($f3);
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
            $ext = strtolower(pathinfo($files['avatar']['name'], PATHINFO_EXTENSION));
            $allowed = ['jpg', 'jpeg', 'png', 'gif'];
            
            if (!in_array($ext, $allowed)) {
                echo json_encode(['success' => false, 'message' => 'Format file tidak didukung']);
                return;
            }
            
            if ($files['avatar']['size'] > 2 * 1024 * 1024) {
                echo json_encode(['success' => false, 'message' => 'Ukuran file terlalu besar (maks 2MB)']);
                return;
            }

            // Create a sanitized filename
            $sanitizedName = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $user[0]['nama_user']));
            $avatarName = 'keuangan_' . $userId . '_' . $sanitizedName . '_' . time() . '.' . $ext;
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

    public function getKeuanganUsers($f3)
    {
        $this->checkSuperKeuangan($f3);
        header('Content-Type: application/json');
        
        $search = $f3->get('GET.search');
        $where = "WHERE role = 'keuangan'";
        $params = [];

        if ($search) {
            $where .= " AND (username LIKE ? OR nama_lengkap LIKE ? OR email LIKE ?)";
            $term = "%$search%";
            $params = [$term, $term, $term];
        }

        $users = $this->db->exec("SELECT id, username, nama_lengkap, email, no_whatsapp, created_at FROM users $where ORDER BY created_at DESC", $params);
        echo json_encode(['data' => $users]);
    }

    public function addKeuanganUser($f3)
    {
        $this->checkSuperKeuangan($f3);
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
        $user->role = 'keuangan';
        $user->status = 'active';
        $user->created_at = date('Y-m-d H:i:s');
        $user->save();
        
        echo json_encode(['success' => true, 'message' => 'User Keuangan berhasil ditambahkan']);
    }

    public function deleteKeuanganUser($f3)
    {
        $this->checkSuperKeuangan($f3);
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
        if (empty($target) || $target[0]['role'] !== 'keuangan') {
            echo json_encode(['success' => false, 'message' => 'User bukan admin keuangan']);
            return;
        }

        $this->db->exec("DELETE FROM users WHERE id = ?", $id);
        echo json_encode(['success' => true, 'message' => 'User Keuangan berhasil dihapus']);
    }

    public function getKeuanganUser($f3)
    {
        $this->checkSuperKeuangan($f3);
        header('Content-Type: application/json');
        
        $id = $f3->get('PARAMS.id');
        if (!$id) {
            echo json_encode(['success' => false, 'message' => 'ID tidak valid']);
            return;
        }

        $user = $this->db->exec("SELECT id, username, nama_lengkap, email, no_whatsapp FROM users WHERE id = ? AND role = 'keuangan'", $id);
        if (empty($user)) {
            echo json_encode(['success' => false, 'message' => 'User tidak ditemukan']);
            return;
        }

        echo json_encode(['success' => true, 'data' => $user[0]]);
    }

    public function updateKeuanganUser($f3)
    {
        $this->checkSuperKeuangan($f3);
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
        $user->load(['id = ? AND role = "keuangan"', $id]);
        
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
        
        echo json_encode(['success' => true, 'message' => 'User Keuangan berhasil diperbarui']);
    }


    // ===================== KESANGGUPAN PEMBAYARAN (KEUANGAN) =====================

    public function getTemplateKesanggupan($f3)
    {
        $this->checkSuperKeuangan($f3);
        header('Content-Type: application/json');
        try {
            $row = $this->db->exec(
                "SELECT tf.*
                 FROM template_files tf
                 WHERE tf.jenis = ? AND tf.is_active = 1
                 ORDER BY tf.created_at DESC LIMIT 1",
                ['kesanggupan']
            );
            $data = $row ? $row[0] : null;
            if ($data) {
                $timestamp = strtotime($data['created_at'] ?? '');
                $data['created_at_formatted'] = $timestamp
                    ? date('d M Y, H.i', $timestamp) . ' WIB'
                    : ($data['created_at'] ?? '-');
            }
            echo json_encode(['success' => true, 'data' => $data]);
        } catch (\Exception $e) {
            echo json_encode(['success' => false, 'data' => null, 'message' => $e->getMessage()]);
        }
    }

    public function uploadTemplateKesanggupan($f3)
    {
        $this->checkSuperKeuangan($f3);
        header('Content-Type: application/json');

        try {
            $keuanganId = $f3->get('SESSION.user_id');

            if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
                $errCode = $_FILES['file']['error'] ?? -1;
                echo json_encode(['success' => false, 'message' => 'File tidak valid. Kode error: ' . $errCode]);
                return;
            }

            $file = $_FILES['file'];
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, ['doc', 'docx'])) {
                echo json_encode(['success' => false, 'message' => 'Format harus .doc atau .docx']);
                return;
            }
            if ($file['size'] > 10 * 1024 * 1024) {
                echo json_encode(['success' => false, 'message' => 'Ukuran file maksimal 10MB.']);
                return;
            }

            $uploadDir = __DIR__ . '/../../public/uploads/templates/';
            if (!is_dir($uploadDir)) {
                if (!mkdir($uploadDir, 0755, true)) {
                    echo json_encode(['success' => false, 'message' => 'Gagal membuat direktori upload.']);
                    return;
                }
            }

            $namaAsli = $file['name'];
            $namaFile = 'template_kesanggupan_' . time() . '.' . $ext;

            if (!move_uploaded_file($file['tmp_name'], $uploadDir . $namaFile)) {
                echo json_encode(['success' => false, 'message' => 'Gagal memindahkan file ke server.']);
                return;
            }

            $this->db->exec(
                "UPDATE template_files SET is_active = 0
                 WHERE jenis = 'kesanggupan' AND is_active = 1"
            );

            $this->db->exec(
                'INSERT INTO template_files (jenis, nama_file, nama_asli, is_active)
                 VALUES (?, ?, ?, 1)',
                ['kesanggupan', $namaFile, $namaAsli]
            );

            echo json_encode(['success' => true, 'message' => 'Template kesanggupan berhasil diupload.']);
        } catch (\Throwable $e) {
            echo json_encode(['success' => false, 'message' => 'System Error: ' . $e->getMessage()]);
        }
    }

    public function downloadTemplate($f3)
    {
        $jenis = $f3->get('PARAMS.jenis') ?? 'kesanggupan';
        $row = $this->db->exec(
            'SELECT * FROM template_files WHERE jenis = ? AND is_active = 1
             ORDER BY created_at DESC LIMIT 1',
            [$jenis]
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

    public function getKesanggupanUploads($f3)
    {
        $this->checkSuperKeuangan($f3);
        header('Content-Type: application/json');

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

        // Lazy migration — tambah kolom verified_by & revision tracking jika belum ada
        try { $this->db->exec("ALTER TABLE kesanggupan_uploads ADD COLUMN verified_by INT NULL"); } catch (\Throwable $e) {}

        try {
            $rows = $this->db->exec(
                "SELECT ku.id, ku.user_id, ku.file_name, ku.original_name,
                        ku.keterangan, ku.status, ku.note, ku.created_at,
                        ku.verified_by,
                        COALESCE(ku.revision_count, 0) as revision_count,
                        u.nama_lengkap, c.nama_perusahaan
                 FROM kesanggupan_uploads ku
                 JOIN users u ON ku.user_id = u.id
                 LEFT JOIN companies c ON u.company_id = c.id
                 WHERE $where ORDER BY ku.created_at DESC",
                $params
            );

            // Fetch admin names for verified_by from tb_arsipuser
            $verifiedBys = array_values(array_filter(array_unique(array_column($rows, 'verified_by'))));
            $adminMap = [];
            if ($this->dbSil && !empty($verifiedBys)) {
                $placeholders = str_repeat('?,', count($verifiedBys) - 1) . '?';
                $adminData = $this->dbSil->exec("SELECT id_user, nama_user FROM tb_arsipuser WHERE id_user IN ($placeholders)", $verifiedBys);
                foreach ($adminData as $ad) {
                    $adminMap[$ad['id_user']] = $ad['nama_user'];
                }
            }

            foreach ($rows as &$row) {
                $timestamp = strtotime($row['created_at'] ?? '');
                $row['created_at_formatted'] = $timestamp
                    ? (function_exists('formatTanggalIndo') ? formatTanggalIndo($row['created_at']) : date('d M Y', $timestamp))
                    : ($row['created_at'] ?? '-');

                $revCount = (int) ($row['revision_count'] ?? 0);
                $dbStatus = $row['status'] ?? '';
                if ($dbStatus === 'approved') {
                    $row['ui_status'] = 'Disetujui';
                    $row['ui_status_label'] = 'Disetujui';
                } elseif ($dbStatus === 'rejected') {
                    $row['ui_status'] = 'Perbaiki';
                    $nextRev = $revCount + 1;
                    $row['ui_status_label'] = $nextRev > 3 ? 'Perbaiki (Maks)' : "Perbaiki {$nextRev}";
                } elseif ($revCount > 0) {
                    $row['ui_status'] = 'Pending';
                    $row['ui_status_label'] = "Re-upload (Perbaikan {$revCount})";
                } else {
                    $row['ui_status'] = 'Pending';
                    $row['ui_status_label'] = 'Pending';
                }

                $row['verified_by_name'] = !empty($row['verified_by']) && isset($adminMap[$row['verified_by']])
                    ? $adminMap[$row['verified_by']]
                    : null;

                if (!empty($row['file_name'])) {
                    $row['preview_token'] = doc_make_token($row['file_name'], 'kesanggupan-upload');
                }
            }

            echo json_encode(['success' => true, 'data' => $rows]);
        } catch (\Throwable $e) {
            echo json_encode(['success' => false, 'data' => [], 'message' => $e->getMessage()]);
        }
    }

    public function validateKesanggupan($f3)
    {
        $this->checkSuperKeuangan($f3);
        header('Content-Type: application/json');

        $id     = $f3->get('POST.id');
        $status = $f3->get('POST.status');
        $note   = $f3->get('POST.note');

        if (!$id || !$status) {
            echo json_encode(['success' => false, 'message' => 'Data tidak lengkap.']);
            return;
        }

        $dbStatus = $status;
        if ($status === 'Disetujui') $dbStatus = 'approved';
        if ($status === 'Perbaiki')  $dbStatus = 'rejected';
        if ($status === 'Pending')   $dbStatus = 'pending';

        $adminId = $f3->get('SESSION.user_id');

        try {
            $this->db->exec(
                'UPDATE kesanggupan_uploads SET status = ?, note = ?, verified_by = ? WHERE id = ?',
                [$dbStatus, $note, $adminId, $id]
            );
            echo json_encode(['success' => true, 'message' => 'Status kesanggupan berhasil diperbarui.']);
        } catch (\Throwable $e) {
            echo json_encode(['success' => false, 'message' => 'Gagal: ' . $e->getMessage()]);
        }
    }

    public function downloadCustomerKesanggupan($f3)
    {
        $this->checkSuperKeuangan($f3);
        $id = $f3->get('PARAMS.id');
        $row = $this->db->exec('SELECT * FROM kesanggupan_uploads WHERE id = ?', [$id]);
        if (!$row) { $f3->error(404); return; }

        $file = __DIR__ . '/../../public/uploads/kesanggupan/' . $row[0]['file_name'];
        if (!file_exists($file)) { $f3->error(404); return; }

        $ext = strtolower(pathinfo($row[0]['file_name'], PATHINFO_EXTENSION));
        $mime = 'application/octet-stream';
        if ($ext === 'pdf') $mime = 'application/pdf';

        header('Content-Type: ' . $mime);
        header('Content-Disposition: attachment; filename="' . $row[0]['original_name'] . '"');
        header('Content-Length: ' . filesize($file));
        readfile($file);
        exit;
    }

    public function viewCustomerKesanggupan($f3)
    {
        $this->checkSuperKeuangan($f3);
        $id = $f3->get('PARAMS.id');
        $row = $this->db->exec('SELECT * FROM kesanggupan_uploads WHERE id = ?', [$id]);
        if (!$row) { $f3->error(404); return; }

        $file = __DIR__ . '/../../public/uploads/kesanggupan/' . $row[0]['file_name'];
        if (!file_exists($file)) { $f3->error(404); return; }

        $ext = strtolower(pathinfo($row[0]['file_name'], PATHINFO_EXTENSION));
        $mime = 'application/octet-stream';
        if ($ext === 'pdf') $mime = 'application/pdf';

        header('Content-Type: ' . $mime);
        header('Content-Disposition: inline; filename="' . $row[0]['original_name'] . '"');
        header('Content-Length: ' . filesize($file));
        readfile($file);
        exit;
    }

}