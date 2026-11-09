<?php

class AdminController
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

    private function checkAdmin($f3)
    {
        $role = $f3->get('SESSION.role');
        if ($role !== 'admin' && $role !== 'superadmin') {
            $f3->reroute('/login');
            exit;
        }
    }

    public function index($f3, $section = 'ringkasan')
    {
        $this->checkAdmin($f3);
        
        $html = file_get_contents('ui/admin/dashboard.html');
        $html = str_replace('{{ @BASE }}', $f3->get('BASE'), $html);
        $html = str_replace('{{ @SESSION.nama_lengkap }}', $f3->get('SESSION.nama_lengkap') ?? 'Admin', $html);
        $html = str_replace('{{ @SECTION }}', $section, $html);
        $isSuper = true; // All admins have full access
        if (!$isSuper) {
            $html = preg_replace('/<!-- ADMIN_MANAGEMENT_MENU -->.*?<!-- \/ADMIN_MANAGEMENT_MENU -->/s', '', $html);
        } else {
            $html = str_replace(['<!-- ADMIN_MANAGEMENT_MENU -->', '<!-- /ADMIN_MANAGEMENT_MENU -->'], '', $html);
        }

        $roleLabel = strtoupper($f3->get('SESSION.role') ?? 'ADMIN');
        $html = str_replace('{{ @SESSION.role }}', $roleLabel, $html);
        
        $avatar = $f3->get('SESSION.nama_avatar') ?? '';
        $html = str_replace('{{ @SESSION.nama_avatar }}', $avatar, $html);
        $html = str_replace('{{ @SESSION.username }}', $f3->get('SESSION.username') ?? 'admin', $html);

        echo $html;
    }

    public function getStats($f3)
    {
        $this->checkAdmin($f3);
        
        $totalUsers = $this->db->exec('SELECT COUNT(*) as c FROM users WHERE role="customer" AND status="active"')[0]['c'];
        $blockedIPs = $this->db->exec('SELECT COUNT(*) as c FROM blocked_ips WHERE is_active=1')[0]['c'];
        $totalAdmins = 0;
        $totalMitra = 0;
        $totalKeuangan = 0;
        $totalSertifikasi = 0;
        if ($this->dbSil) {
            $totalAdmins = $this->dbSil->exec('SELECT COUNT(*) as c FROM tb_arsipuser WHERE si_selport="admin"')[0]['c'];
            $totalMitra = $this->dbSil->exec('SELECT COUNT(*) as c FROM tb_arsipuser WHERE si_selport="mitra"')[0]['c'];
            $totalKeuangan = $this->dbSil->exec('SELECT COUNT(*) as c FROM tb_arsipuser WHERE si_selport="keuangan"')[0]['c'];
            $totalSertifikasi = $this->dbSil->exec('SELECT COUNT(*) as c FROM tb_arsipuser WHERE si_selport="sertifikasi"')[0]['c'];
        }
        
        echo json_encode([
            'users' => $totalUsers,
            'admins' => $totalAdmins,
            'blocked' => $blockedIPs,
            'mitra' => $totalMitra,
            'keuangan' => $totalKeuangan,
            'sertifikasi' => $totalSertifikasi
        ]);
    }

    public function trackingHistory($f3)
    {
        $this->checkAdmin($f3);
        
        $page = $f3->get('GET.page') ?: 1;
        $search = $f3->get('GET.search');
        $limit = 20;
        $offset = ($page - 1) * $limit;
        
        $where = "WHERE u.role IS NULL OR u.role = 'customer'";
        $params = [];

        if ($search) {
            $where .= " AND (u.username LIKE ? OR al.action LIKE ? OR al.ip_address LIKE ?)";
            $term = "%$search%";
            $params = [$term, $term, $term];
        }
        
        $sql = "SELECT al.*, u.username, u.nama_lengkap 
                FROM activity_logs al 
                LEFT JOIN users u ON al.user_id = u.id 
                $where
                ORDER BY al.created_at DESC LIMIT $limit OFFSET $offset";
        
        $logs = $this->db->exec($sql, $params);
        echo json_encode(['data' => $logs]);
    }

    public function deleteTrackingHistory($f3)
    {
        $this->checkAdmin($f3);
        $range = $f3->get('POST.range'); // hour, day, all
        
        if (!$range) {
            echo json_encode(['success' => false, 'message' => 'Invalid range']);
            return;
        }

        $sql = "";
        $params = [];

        $baseWhere = "user_id IN (SELECT id FROM users WHERE role='customer') OR user_id IS NULL";

        switch ($range) {
            case 'hour':
                $sql = "DELETE FROM activity_logs WHERE ($baseWhere) AND created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)";
                break;
            case 'day':
                $sql = "DELETE FROM activity_logs WHERE ($baseWhere) AND created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)";
                break;
            case 'all':
                $sql = "DELETE FROM activity_logs WHERE ($baseWhere)";
                break;
            default:
                echo json_encode(['success' => false, 'message' => 'Unknown range']);
                return;
        }

        $this->db->exec($sql);
        echo json_encode(['success' => true]);
    }

    public function getBlockedIPs($f3)
    {
        $this->checkAdmin($f3);
        $search = $f3->get('GET.search');
        
        $where = "WHERE is_active=1";
        $params = [];

        if ($search) {
            $where .= " AND (ip_address LIKE ? OR reason LIKE ? OR blocked_by_username LIKE ?)";
            $term = "%$search%";
            $params = [$term, $term, $term];
        }

        $result = $this->db->exec(
            "SELECT id, ip_address, reason, blocked_by_username AS blocked_by, blocked_at
             FROM blocked_ips
             $where
             ORDER BY blocked_at DESC",
             $params
        );
        echo json_encode(['data' => $result]);
    }


    public function blockIP($f3)
    {
        $this->checkAdmin($f3);
        header('Content-Type: application/json');

        $ip       = trim($f3->get('POST.ip_address') ?? '');
        $reason   = trim($f3->get('POST.reason') ?? '');
        $username = $f3->get('SESSION.username') ?? 'Admin';

        if (!$ip || !$reason) {
            echo json_encode(['success' => false, 'message' => 'IP Address dan alasan wajib diisi']);
            return;
        }

        // Validasi format IP
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            echo json_encode(['success' => false, 'message' => 'Format IP Address tidak valid']);
            return;
        }

        // Cek apakah IP sudah diblokir
        $existing = $this->db->exec(
            'SELECT id FROM blocked_ips WHERE ip_address = ? AND is_active = 1 LIMIT 1',
            [$ip]
        );
        if (!empty($existing)) {
            echo json_encode(['success' => false, 'message' => 'IP ' . $ip . ' sudah diblokir sebelumnya']);
            return;
        }

        $this->tracking->blockIP($ip, $reason, $username);
        echo json_encode(['success' => true, 'message' => 'IP berhasil diblokir']);
    }

    public function unblockIP($f3)
    {
        $this->checkAdmin($f3);
        header('Content-Type: application/json');

        $id = $f3->get('POST.id');
        if (!$id) {
            echo json_encode(['success' => false, 'message' => 'ID tidak valid']);
            return;
        }

        $this->tracking->unblockIP($id);
        echo json_encode(['success' => true]);
    }

    public function getProfileChanges($f3)
    {
        $this->checkAdmin($f3);
        $search = $f3->get('GET.search');
        
        $where = "";
        $params = [];
        
        if ($search) {
            $where = "WHERE u.username LIKE ? OR u.nama_lengkap LIKE ? OR pl.field_name LIKE ? OR pl.old_value LIKE ? OR pl.new_value LIKE ?";
            $term = "%$search%";
            $params = [$term, $term, $term, $term, $term];
        }

        $sql = "SELECT pl.*, u.username, u.nama_lengkap 
                FROM profile_logs pl 
                JOIN users u ON pl.user_id = u.id 
                $where
                ORDER BY pl.created_at DESC LIMIT 50";
        $logs = $this->db->exec($sql, $params);
        echo json_encode(['data' => $logs]);
    }

    public function deleteProfileChangesHistory($f3)
    {
        $this->checkAdmin($f3);
        $range = $f3->get('POST.range'); // hour, day, all
        
        if (!$range) {
            echo json_encode(['success' => false, 'message' => 'Invalid range']);
            return;
        }

        $sql = "";
        $baseWhere = "user_id IN (SELECT id FROM users WHERE role='customer')";

        switch ($range) {
            case 'hour':
                $sql = "DELETE FROM profile_logs WHERE ($baseWhere) AND created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)";
                break;
            case 'day':
                $sql = "DELETE FROM profile_logs WHERE ($baseWhere) AND created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)";
                break;
            case 'all':
                $sql = "DELETE FROM profile_logs WHERE ($baseWhere)";
                break;
            default:
                echo json_encode(['success' => false, 'message' => 'Unknown range']);
                return;
        }

        $this->db->exec($sql);
        echo json_encode(['success' => true]);
    }

    public function getCustomers($f3)
    {
        $this->checkAdmin($f3);
        $search = $f3->get('GET.search');

        $where = "WHERE role='customer'";
        $params = [];

        if ($search) {
            $where .= " AND (u.username LIKE ? OR u.nama_lengkap LIKE ? OR u.email LIKE ? OR c.nama_perusahaan LIKE ? OR u.no_whatsapp LIKE ?)";
            $term = "%$search%";
            $params = [$term, $term, $term, $term, $term];
        }

        $sql = "SELECT 
                    u.id, 
                    u.username, 
                    u.nama_lengkap, 
                    u.email, 
                    u.no_whatsapp, 
                    u.kategori_pelanggan AS kategori, 
                    c.nama_perusahaan, 
                    DATE_FORMAT(u.created_at, '%d-%m-%Y') as tanggal_daftar
                FROM users u 
                LEFT JOIN companies c ON u.company_id = c.id 
                $where 
                ORDER BY u.created_at DESC";
        
        $customers = $this->db->exec($sql, $params);
        echo json_encode(['data' => $customers]);
    }
    public function getComplaints($f3)
    {
        $this->checkAdmin($f3);
        header('Content-Type: application/json');

        $id = $f3->get('GET.id');
        $search = $f3->get('GET.search');
        
        if ($id) {
            $complaint = $this->db->exec(
                "SELECT c.*, u.username, u.nama_lengkap, u.email, u.no_whatsapp, r.nama_lengkap as resolved_by_name, ct.name as topic_name
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
                "SELECT cc.*, u.nama_lengkap as nama_pengirim FROM complaint_chats cc LEFT JOIN users u ON cc.user_id = u.id WHERE cc.complaint_id = ? ORDER BY cc.created_at ASC",
                $id
            );

            $complaint[0]['messages'] = $messages;
            echo json_encode(['success' => true, 'data' => $complaint]);
            return;
        }

        $where = "";
        $params = [];

        if ($search) {
            $where = "WHERE (c.topic_id LIKE ? OR u.username LIKE ? OR u.nama_lengkap LIKE ?)";
            $term = "%$search%";
            $params = [$term, $term, $term];
        }

        $sql = "SELECT c.*, u.username, u.nama_lengkap, u.email, u.no_whatsapp, r.nama_lengkap as resolved_by_name, ct.name as topic_name
                FROM complaints c 
                JOIN users u ON c.user_id = u.id 
                LEFT JOIN users r ON c.resolved_by = r.id
                LEFT JOIN complaint_topics ct ON c.topic_id = ct.id
                $where
                ORDER BY c.created_at DESC";
                
        $complaints = $this->db->exec($sql, $params);
        echo json_encode(['data' => $complaints]);
    }

    public function replyComplaint($f3)
    {
        $this->checkAdmin($f3);
        header('Content-Type: application/json');
        
        $complaint_id = $f3->get('POST.complaint_id');
        $reply_text = $f3->get('POST.reply_text');
        $admin_id = $f3->get('SESSION.user_id');

        if (!$complaint_id || !$reply_text) {
            echo json_encode(['success' => false, 'message' => 'Data tidak lengkap']);
            return;
        }

        $message = new DB\SQL\Mapper($this->db, 'complaint_chats');
        $message->complaint_id = $complaint_id;
        $message->user_id = $admin_id;
        $message->message = $reply_text;
        $message->is_admin = 1;
        $message->is_read = 0;
        $message->save();

        $this->db->exec("UPDATE complaints SET status='In Progress' WHERE id=?", $complaint_id);

        $complaint = $this->db->exec("SELECT user_id, no_pengaduan FROM complaints WHERE id=?", $complaint_id);
        if ($complaint && $this->notifikasiService) {
            $this->notifikasiService->create(
                $complaint[0]['user_id'],
                'Balasan Pengaduan',
                'Ada balasan baru untuk pengaduan Anda.',
                'info',
                'dashboard/section/pengaduan'
            );
            
            $customerData = $this->db->exec("SELECT no_whatsapp FROM users WHERE id=?", $complaint[0]['user_id']);
            if (!empty($customerData) && !empty($customerData[0]['no_whatsapp'])) {
                $waMsg = "Pemberitahuan: Pengaduan Anda (" . $complaint[0]['no_pengaduan'] . ") telah direspon oleh admin kami. Silakan cek aplikasi untuk melihat balasan.";
                $this->notifikasiService->sendWhatsApp($customerData[0]['no_whatsapp'], $waMsg);
            }
        }

        echo json_encode(['success' => true]);
    }

    public function loadSection($f3, $file)
    {
        $this->checkAdmin($f3);
        $path = "admin/{$file}.html";
        if (file_exists("ui/".$path)) {
            echo \Template::instance()->render($path);
        } else {
            echo json_encode(['success' => false, 'message' => 'ID tidak valid']);
        }
    }

    public function triggerDemoNotification($f3)
    {
        $this->checkAdmin($f3);
        header('Content-Type: application/json');

        $type = $f3->get('POST.type');
        $target_user_id = $f3->get('POST.user_id');

        if (!$target_user_id) {
            $lastUser = $this->db->exec("SELECT id FROM users WHERE role='customer' ORDER BY created_at DESC LIMIT 1");
            if ($lastUser) {
                $target_user_id = $lastUser[0]['id'];
            } else {
                echo json_encode(['success' => false, 'message' => 'No customers found']);
                return;
            }
        }

        if (!$this->notifikasiService) {
            echo json_encode(['success' => false, 'message' => 'Service not loaded']);
            return;
        }

        switch ($type) {
            case 'service':
                $this->notifikasiService->create(
                    $target_user_id,
                    'Status Layanan',
                    'Layanan pengujian Anda telah selesai diproses.',
                    'info',
                    'dashboard/section/services'
                );
                break;
            default:
                echo json_encode(['success' => false, 'message' => 'Unknown type']);
                return;
        }

        echo json_encode(['success' => true, 'message' => "Notification '$type' sent to User ID $target_user_id"]);
    }

    public function updateAccount($f3)
    {
        $this->checkAdmin($f3);
        header('Content-Type: application/json');

        $userId   = $f3->get('SESSION.user_id');
        $username = $f3->get('POST.username');
        $password = $f3->get('POST.password');

        if (!$username) {
            echo json_encode(['success' => false, 'message' => 'Username tidak boleh kosong']);
            return;
        }

        $existing = $this->db->exec("SELECT id FROM users WHERE username = ? AND id != ?", [$username, $userId]);
        if (!empty($existing)) {
            echo json_encode(['success' => false, 'message' => 'Username sudah digunakan']);
            return;
        }

        $user = new DB\SQL\Mapper($this->db, 'users');
        $user->load(['id = ?', $userId]);

        if ($user->dry()) {
            echo json_encode(['success' => false, 'message' => 'User tidak ditemukan']);
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

        $user->save();
        $f3->set('SESSION.username', $username);

        echo json_encode(['success' => true, 'message' => 'Akun berhasil diperbarui']);
    }

    public function getStaffList($f3)
    {
        $this->checkAdmin($f3);
        header('Content-Type: application/json');

        if (!$this->dbSil) {
            echo json_encode(['data' => []]);
            return;
        }

        $search = $f3->get('GET.search');
        $role = $f3->get('GET.role');

        $where = "WHERE 1=1";
        $params = [];

        if ($search) {
            $where .= " AND (login LIKE ? OR nama_user LIKE ?)";
            $term = "%$search%";
            $params[] = $term;
            $params[] = $term;
        }

        if ($role === 'none') {
            $where .= " AND (si_selport IS NULL OR si_selport = '')";
        } elseif ($role) {
            $validFilters = ['admin', 'superadmin', 'mitra', 'supermitra', 'keuangan', 'superkeuangan', 'sertifikasi', 'kalibrasi'];
            if (in_array($role, $validFilters)) {
                $where .= " AND si_selport = ?";
                $params[] = $role;
            }
        }

        $sql = "SELECT id_user, login, nama_user, si_selport FROM tb_arsipuser $where ORDER BY nama_user ASC";
        $staff = $this->dbSil->exec($sql, $params);

        echo json_encode(['data' => $staff]);
    }

    public function updateStaffRole($f3)
    {
        $this->checkAdmin($f3);
        header('Content-Type: application/json');

        if (!$this->dbSil) {
            echo json_encode(['success' => false, 'message' => 'Database SIL tidak tersedia']);
            return;
        }

        $id = $f3->get('POST.id_user');
        $role = $f3->get('POST.role');

        if (!$id) {
            echo json_encode(['success' => false, 'message' => 'ID user tidak valid']);
            return;
        }

        $validRoles = ['admin', 'superadmin', 'mitra', 'supermitra', 'keuangan', 'superkeuangan', 'sertifikasi', 'kalibrasi', ''];
        if (!in_array($role, $validRoles)) {
            echo json_encode(['success' => false, 'message' => 'Role tidak valid']);
            return;
        }

        $newRole = $role === '' ? null : $role;

        $this->dbSil->exec(
            "UPDATE tb_arsipuser SET si_selport = ? WHERE id_user = ?",
            [$newRole, $id]
        );

        echo json_encode(['success' => true, 'message' => 'Role berhasil diperbarui']);
    }

    public function updateProfile($f3)
    {
        $this->checkAdmin($f3);
        header('Content-Type: application/json');

        if (!$this->dbSil) {
            echo json_encode(['success' => false, 'message' => 'Database SIL tidak tersedia']);
            return;
        }

        $userId = $f3->get('SESSION.user_id');
        $namaLengkap = $f3->get('POST.nama_lengkap');
        $password = $f3->get('POST.password');
        
        // Get current user data
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

            $avatarName = strtolower(str_replace(' ', '', $user[0]['nama_user'])) . '.' . $ext;
            $destination = 'public/avatar/' . $avatarName;
            move_uploaded_file($files['avatar']['tmp_name'], $destination);

            $this->dbSil->exec(
                'UPDATE tb_arsipuser SET nama_avatar = ? WHERE id_user = ?',
                [$avatarName, $userId]
            );
            $f3->set('SESSION.nama_avatar', $avatarName);
        }

        // Update name
        if ($namaLengkap && $namaLengkap !== $user[0]['nama_user']) {
            $this->dbSil->exec(
                'UPDATE tb_arsipuser SET nama_user = ? WHERE id_user = ?',
                [$namaLengkap, $userId]
            );
            $f3->set('SESSION.nama_lengkap', $namaLengkap);
        }

        // Update password
        if ($password && strlen($password) >= 4) {
            $hashedPassword = md5($password);
            $this->dbSil->exec(
                'UPDATE tb_arsipuser SET password = ? WHERE id_user = ?',
                [$hashedPassword, $userId]
            );
        }

        echo json_encode([
            'success' => true,
            'avatar' => $avatarName,
            'nama' => $namaLengkap ?: $user[0]['nama_user']
        ]);
    }
}
