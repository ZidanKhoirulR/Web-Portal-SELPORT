<?php

class KeluhanController
{
    private $db;
    private $dbSil;
    private $notifikasiService;

    public function __construct($db, $notifikasiService = null, $dbSil = null)
    {
        $this->db = $db;
        $this->dbSil = $dbSil;
        $this->notifikasiService = $notifikasiService;
    }

    private function validateCsrf($f3)
    {
        $token = $f3->get('POST.csrf_token');
        $sessionToken = $f3->get('SESSION.csrf_token');
        return !empty($token) && !empty($sessionToken) && $token === $sessionToken;
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

    public function submit($f3)
    {
        if (!$f3->get('SESSION.user_id')) {
            echo json_encode(['success' => false, 'message' => 'Unauthorized']);
            return;
        }

        if (!$this->validateCsrf($f3)) {
            echo json_encode(['success' => false, 'message' => 'Invalid CSRF Token']);
            return;
        }

        $topic_id = $f3->get('POST.topic_id');
        $reason = $f3->get('POST.reason');
        $order_no = $f3->get('POST.order_no');
        $order_name = $f3->get('POST.order_name');
        $user_id = $f3->get('SESSION.user_id');

        if (empty($reason)) {
            echo json_encode(['success' => false, 'message' => 'Deskripsi harus diisi']);
            return;
        }

        $file_path = null;
        $original_filename = null;
        $uploadFile = isset($_FILES['chat_file']) ? $_FILES['chat_file'] : (isset($_FILES['evidence']) ? $_FILES['evidence'] : null);

        if ($uploadFile && $uploadFile['error'] === UPLOAD_ERR_OK) {
            $allowedMimes = [
                'application/pdf', 
                'application/msword', 
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'application/vnd.ms-excel',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'image/jpeg',
                'image/png'
            ];
            $allowedExts = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'jpg', 'jpeg', 'png'];
            
            $validation = $this->validateFile($uploadFile, $allowedMimes, $allowedExts, 10 * 1024 * 1024);
            if (!$validation['success']) {
                echo json_encode(['success' => false, 'message' => $validation['message']]);
                return;
            }

            $uploadDir = 'public/uploads/complaints/chats/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0777, true);
            }
            
            $original_filename = $uploadFile['name'];
            $fileExtension = $this->getFileExtension($original_filename);
            $fileName = uniqid('comp_') . '.' . $fileExtension;
            $targetPath = $uploadDir . $fileName;

            if (move_uploaded_file($uploadFile['tmp_name'], $targetPath)) {
                $file_path = $fileName;
            }
        }

        try {
            // Generate unique no_keluhan based on current date
            $datePrefix = 'KLH-' . date('Ymd') . '-';
            $latest = $this->db->exec('SELECT no_pengaduan FROM complaints WHERE no_pengaduan LIKE ? ORDER BY no_pengaduan DESC LIMIT 1', $datePrefix . '%');
            $nextNum = 1;
            if (!empty($latest) && !empty($latest[0]['no_pengaduan'])) {
                $parts = explode('-', $latest[0]['no_pengaduan']);
                if (count($parts) >= 3) {
                    $nextNum = intval($parts[2]) + 1;
                }
            }
            $no_keluhan = $datePrefix . str_pad($nextNum, 4, '0', STR_PAD_LEFT);

            // Use raw SQL prepared statements to avoid F3 Mapper bug with special
            // characters (slashes) in order_no values being misinterpreted as column names
            $topicIdValue = (is_numeric($topic_id) && $topic_id > 0) ? (int)$topic_id : null;
            $orderNoValue = !empty($order_no) ? $order_no : null;
            $orderNameValue = !empty($order_name) ? $order_name : null;
            $filePathValue = !empty($file_path) ? $file_path : null;
            $originalFilenameValue = !empty($original_filename) ? $original_filename : null;

            $this->db->exec(
                'INSERT INTO complaints 
                 (user_id, no_pengaduan, topic_id, order_no, order_name, message, file_path, original_filename, status, is_chat_only, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, \'Pending\', 0, NOW(), NOW())',
                [
                    $user_id,
                    $no_keluhan,
                    $topicIdValue,
                    $orderNoValue,
                    $orderNameValue,
                    $reason,
                    $filePathValue,
                    $originalFilenameValue
                ]
            );

            // Get the newly inserted ID
            $newId = $this->db->lastInsertId();
            if (!$newId) {
                // Fallback: query by no_keluhan
                $inserted = $this->db->exec('SELECT id FROM complaints WHERE no_pengaduan = ? LIMIT 1', [$no_keluhan]);
                $newId = !empty($inserted) ? $inserted[0]['id'] : null;
            }

            // Also insert the initial message into complaint_chats so mitra inbox shows it
            if ($newId) {
                $this->db->exec(
                    'INSERT INTO complaint_chats (complaint_id, user_id, message, file_path, original_filename, is_admin, is_read, created_at)
                     VALUES (?, ?, ?, ?, ?, 0, 0, NOW())',
                    [
                        $newId,
                        $user_id,
                        $reason,
                        $filePathValue,
                        $originalFilenameValue
                    ]
                );
            }

            if ($this->notifikasiService) {
                // Get customer details for WA message context
                $customerData = $this->db->exec('SELECT nama_lengkap, username FROM users WHERE id = ? LIMIT 1', [$user_id]);
                $customerName = !empty($customerData) ? ($customerData[0]['nama_lengkap'] ?? $customerData[0]['username'] ?? 'Pelanggan') : 'Pelanggan';

                $waMsg = "Pemberitahuan Admin: Keluhan baru ($no_keluhan) telah diajukan oleh *$customerName* dan menunggu untuk diproses.";
                $this->notifikasiService->sendToAdminsByRole(
                    ['mitra', 'supermitra'], 
                    'Keluhan Baru', 
                    "Keluhan baru ($no_keluhan) dari $customerName perlu diproses.", 
                    'info', 
                    '/mitra/layanan-pelanggan',
                    $waMsg
                );

                $waMsgToMitra = "*Notifikasi Selport*\n"
                              . "Halo {nama_user}, terdapat tiket Keluhan baru:\n"
                              . "*ID Tiket*: {$no_keluhan}\n"
                              . "*Pelanggan*: {$customerName}\n"
                              . "*Topik*: " . (!empty($topicIdValue) ? "ID Topik $topicIdValue" : "Keluhan") . "\n"
                              . "Segera balas pesan pelanggan melalui menu Layanan Pelanggan.";
                $this->notifikasiService->sendWhatsAppToMitra($waMsgToMitra);
            }

            echo json_encode([
                'success' => true, 
                'message' => 'Keluhan berhasil dikirim',
                'id' => (int)$newId,
                'no_keluhan' => $no_keluhan
            ]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => 'Gagal mengirim keluhan: ' . $e->getMessage()]);
        }
    }

    public function getList($f3)
    {
        if (!$f3->get('SESSION.user_id')) {
            echo json_encode(['success' => false, 'message' => 'Unauthorized']);
            return;
        }

        $user_id = $f3->get('SESSION.user_id');
        $complaints = $this->db->exec(
            'SELECT c.*, t.name as topic_name, u.nama_lengkap 
            FROM complaints c 
            LEFT JOIN complaint_topics t ON c.topic_id = t.id 
            LEFT JOIN users u ON c.user_id = u.id
            WHERE c.user_id = ? AND c.is_chat_only = 0
            ORDER BY c.created_at DESC',
            $user_id
        );

        echo json_encode(['success' => true, 'data' => $complaints]);
    }

    public function getChatList($f3)
    {
        if (!$f3->get('SESSION.user_id')) {
            echo json_encode(['success' => false, 'message' => 'Unauthorized']);
            return;
        }

        $user_id = $f3->get('SESSION.user_id');
        // Get only actual complaints (is_chat_only = 0), ordered by updated_at (recent activity first)
        $complaints = $this->db->exec(
            'SELECT c.*, t.name as topic_name, u.nama_lengkap 
            FROM complaints c 
            LEFT JOIN complaint_topics t ON c.topic_id = t.id 
            LEFT JOIN users u ON c.user_id = u.id
            WHERE c.user_id = ? AND c.is_chat_only = 0
            ORDER BY c.updated_at DESC',
            $user_id
        );

        echo json_encode(['success' => true, 'data' => $complaints]);
    }

    public function getGeneralChat($f3)
    {
        if (!$f3->get('SESSION.user_id')) {
            echo json_encode(['success' => false, 'message' => 'Unauthorized']);
            return;
        }

        $user_id = $f3->get('SESSION.user_id');

        // Find existing general chat
        $chat = $this->db->exec('SELECT id FROM complaints WHERE user_id = ? AND is_chat_only = 1 LIMIT 1', $user_id);

        if (empty($chat)) {
            // Create new general chat
            $complaint = new DB\SQL\Mapper($this->db, 'complaints');
            $complaint->user_id = $user_id;
            $complaint->topic_id = null;
            $complaint->message = 'Percakapan Langsung dengan Admin';
            $complaint->status = 'In Progress';
            $complaint->is_chat_only = 1;
            $complaint->save();
            $chat_id = $complaint->id;
        } else {
            $chat_id = $chat[0]['id'];
        }

        // Mark admin messages as read
        $this->db->exec('UPDATE complaint_chats SET is_read = 1 WHERE complaint_id = ? AND is_admin = 1 AND is_read = 0', $chat_id);

        // Get messages
        $messages = $this->db->exec(
            'SELECT cc.*, COALESCE(u.nama_lengkap, u.username) as nama_pengirim 
             FROM complaint_chats cc 
             LEFT JOIN users u ON cc.user_id = u.id 
             WHERE cc.complaint_id = ? 
             ORDER BY cc.created_at ASC, cc.id ASC',
            $chat_id
        );

        // Fallback for SIL users (mitra)
        if ($this->dbSil && !empty($messages)) {
            foreach ($messages as &$msg) {
                if ($msg['is_admin'] == 1 && empty($msg['nama_pengirim']) && !empty($msg['user_id'])) {
                    $silUser = $this->dbSil->exec('SELECT nama_user FROM tb_arsipuser WHERE id_user = ?', [$msg['user_id']]);
                    if (!empty($silUser)) {
                        $msg['nama_pengirim'] = $silUser[0]['nama_user'];
                    }
                }
            }
        }

        echo json_encode(['success' => true, 'data' => $messages, 'chat_id' => $chat_id]);
    }

    public function getUnreadCount($f3)
    {
        if (!$f3->get('SESSION.user_id')) {
            echo json_encode(['success' => false, 'count' => 0]);
            return;
        }

        $user_id = $f3->get('SESSION.user_id');
        
        // Count unread messages from admin in user's chats
        // We join complaints to ensure it belongs to user
        $result = $this->db->exec(
            'SELECT COUNT(*) as count 
             FROM complaint_chats cc
             JOIN complaints c ON cc.complaint_id = c.id
             WHERE c.user_id = ? AND cc.is_admin = 1 AND cc.is_read = 0',
            $user_id
        );

        echo json_encode(['success' => true, 'count' => (int)$result[0]['count']]);

    }

    public function getMessages($f3)
    {
        if (!$f3->get('SESSION.user_id')) {
            echo json_encode(['success' => false, 'message' => 'Unauthorized']);
            return;
        }

        $complaint_id = $f3->get('PARAMS.id');
        $user_id = $f3->get('SESSION.user_id');

        // Verify complaint belongs to user
        $complaint = $this->db->exec('SELECT id FROM complaints WHERE id = ? AND user_id = ?', [$complaint_id, $user_id]);
        if (empty($complaint)) {
            echo json_encode(['success' => false, 'message' => 'Complaint not found']);
            return;
        }

        // Mark admin messages as read
        $this->db->exec(
            'UPDATE complaint_chats SET is_read = 1 WHERE complaint_id = ? AND is_admin = 1 AND is_read = 0',
            $complaint_id
        );

        $messages = $this->db->exec(
            'SELECT cc.*, COALESCE(u.nama_lengkap, u.username) as nama_pengirim 
             FROM complaint_chats cc 
             LEFT JOIN users u ON cc.user_id = u.id 
             WHERE cc.complaint_id = ? 
             ORDER BY cc.created_at ASC, cc.id ASC',
            $complaint_id
        );

        // Fallback for SIL users (mitra)
        if ($this->dbSil && !empty($messages)) {
            foreach ($messages as &$msg) {
                if ($msg['is_admin'] == 1 && empty($msg['nama_pengirim']) && !empty($msg['user_id'])) {
                    $silUser = $this->dbSil->exec('SELECT nama_user FROM tb_arsipuser WHERE id_user = ?', [$msg['user_id']]);
                    if (!empty($silUser)) {
                        $msg['nama_pengirim'] = $silUser[0]['nama_user'];
                    }
                }
            }
        }

        // Format timestamps to WIB
        foreach ($messages as &$msg) {
            $dt = new DateTime($msg['created_at']);

            $msg['created_at_formatted'] = $dt->format('d M Y, H.i') . ' WIB';
        }

        echo json_encode(['success' => true, 'data' => $messages]);
    }

    public function sendMessage($f3)
    {
        if (!$f3->get('SESSION.user_id')) {
            echo json_encode(['success' => false, 'message' => 'Unauthorized']);
            return;
        }

        $complaint_id = $f3->get('POST.complaint_id');
        $message = $f3->get('POST.message') ?: $f3->get('POST.reply_text'); // Handle both formats
        $user_id = $f3->get('SESSION.user_id');
        $role = $f3->get('SESSION.role');
        $is_admin = (in_array($role, ['mitra', 'supermitra', 'admin'])) ? 1 : 0;

        if (!$complaint_id) {
            echo json_encode(['success' => false, 'message' => 'ID Keluhan tidak ditemukan']);
            return;
        }

        // Handle File Upload
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
                'image/jpeg',
                'image/png'
            ];
            $allowedExts = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'jpg', 'jpeg', 'png'];
            
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

        if (empty($message) && empty($file_path)) {
            echo json_encode(['success' => false, 'message' => 'Pesan atau file harus diisi']);
            return;
        }

        try {
            // Use raw SQL to avoid F3 Mapper issues with special characters in message content
            $this->db->exec(
                'INSERT INTO complaint_chats (complaint_id, user_id, message, file_path, original_filename, is_admin, is_read, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, 0, NOW())',
                [
                    $complaint_id,
                    $user_id,
                    $message ?: null,
                    $file_path ?: null,
                    $original_filename ?: null,
                    $is_admin
                ]
            );

            // Update status to In Progress and refresh complaint updated_at
            $this->db->exec("UPDATE complaints SET status='In Progress', updated_at=NOW() WHERE id=? AND status != 'Resolved'", $complaint_id);

            // If admin replies, send notification to customer
            if ($is_admin && $this->notifikasiService) {
                $complaint = $this->db->exec("SELECT user_id, no_pengaduan FROM complaints WHERE id = ?", $complaint_id);
                if (!empty($complaint)) {
                    $customerId = $complaint[0]['user_id'];
                    $noPengaduan = $complaint[0]['no_pengaduan'];
                    
                    $this->notifikasiService->create(
                        $customerId,
                        'Balasan Keluhan',
                        "Keluhan Anda ($noPengaduan) telah direspon oleh admin.",
                        'info',
                        '/dashboard/section/keluhan'
                    );

                    $customerData = $this->db->exec("SELECT no_whatsapp FROM users WHERE id=?", $customerId);
                    if (!empty($customerData) && !empty($customerData[0]['no_whatsapp'])) {
                        $waMsg = "Pemberitahuan: Keluhan Anda ($noPengaduan) telah direspon oleh admin. Silakan cek aplikasi untuk melihat balasan.";
                        $this->notifikasiService->sendWhatsApp($customerData[0]['no_whatsapp'], $waMsg);
                    }
                }
            }

            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => 'Gagal mengirim pesan: ' . $e->getMessage()]);
        }
    }

    /**
     * MITRA/ADMIN METHODS
     */
    public function getComplaints($f3)
    {
        $role = $f3->get('SESSION.role');
        if ($role !== 'mitra' && $role !== 'supermitra' && $role !== 'admin') {
            echo json_encode(['success' => false, 'message' => 'Unauthorized']);
            return;
        }

        header('Content-Type: application/json');

        $id = $f3->get('GET.id');
        $search = $f3->get('GET.search');
        $status = $f3->get('GET.status');

        if ($id) {
            try {
                $complaint = $this->db->exec(
                    "SELECT c.*, u.username, u.nama_lengkap, u.email, u.no_whatsapp, t.name as topic_name
                     FROM complaints c 
                     JOIN users u ON c.user_id = u.id 
                     LEFT JOIN complaint_topics t ON c.topic_id = t.id
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
            "SELECT cc.*, COALESCE(u.nama_lengkap, u.username) as nama_pengirim 
             FROM complaint_chats cc 
             LEFT JOIN users u ON cc.user_id = u.id 
             WHERE cc.complaint_id = ? 
             ORDER BY cc.created_at ASC, cc.id ASC",
            $id
        );

        // Fallback for SIL users (mitra)
        if ($this->dbSil) {
            foreach ($messages as &$msg) {
                if ($msg['is_admin'] == 1 && empty($msg['nama_pengirim']) && !empty($msg['user_id'])) {
                    $silUser = $this->dbSil->exec('SELECT nama_user FROM tb_arsipuser WHERE id_user = ?', [$msg['user_id']]);
                    if (!empty($silUser)) {
                        $msg['nama_pengirim'] = $silUser[0]['nama_user'];
                    }
                }
            }
        }

        $complaint[0]['messages'] = $messages ?: [];
                echo json_encode(['success' => true, 'data' => $complaint]);
            } catch (Exception $e) {
                echo json_encode(['success' => false, 'message' => 'Gagal memuat detail']);
            }
            return;
        }

        try {
            $where = "WHERE c.is_chat_only = 0";
            $params = [];

            if ($search) {
                $where .= " AND (c.no_pengaduan LIKE ? OR u.username LIKE ? OR u.nama_lengkap LIKE ? OR c.order_no LIKE ?)";
                $term = "%$search%";
                $params = array_merge($params, [$term, $term, $term, $term]);
            }

            if ($status && in_array($status, ['Pending', 'In Progress', 'Resolved'])) {
                $where .= " AND c.status = ?";
                $params[] = $status;
            }

            $sql = "SELECT c.*, t.name as topic_name, u.nama_lengkap, u.username, u.email,
                           (SELECT COUNT(*) FROM complaint_chats cc 
                            WHERE cc.complaint_id = c.id AND cc.is_admin = 0 AND cc.is_read = 0) as unread_count
                    FROM complaints c 
                    JOIN users u ON c.user_id = u.id 
                    LEFT JOIN complaint_topics t ON c.topic_id = t.id
                    $where
                    ORDER BY c.updated_at DESC, c.created_at DESC";
                    
            $data = $params ? $this->db->exec($sql, $params) : $this->db->exec($sql);
            echo json_encode(['success' => true, 'data' => $data ?: [], 'total' => count($data ?: [])]);
        } catch (Exception $e) {
            echo json_encode(['data' => [], 'total' => 0]);
        }
    }


    public function getTopics($f3)
    {
        $user_id = $f3->get('SESSION.user_id');
        if (!$user_id) {
            echo json_encode(['success' => false, 'data' => []]);
            return;
        }

        // Fetch actual testings/services the user has performed
        // Based on history logic in DashboardPelangganController, these are in complaints table
        $services = $this->db->exec(
            'SELECT c.id, t.name as topic_name 
             FROM complaints c 
             LEFT JOIN complaint_topics t ON c.topic_id = t.id 
             WHERE c.user_id = ? AND c.is_chat_only = 0
             ORDER BY c.created_at DESC',
            $user_id
        );

        $results = [];
        foreach ($services as $s) {
            $orderNo = 'ORD-' . str_pad($s['id'], 6, '0', STR_PAD_LEFT);
            $topicName = $s['topic_name'] ?? 'Pengujian';
            $results[] = [
                'id' => $s['id'],
                'name' => "$topicName ($orderNo)"
            ];
        }

        // If no records found, provide realistic mock data (common test types)
        if (empty($results)) {
            $results = [
                ['id' => 'M1', 'name' => 'Pengujian Kalibrasi (ORD-2025765)'],
                ['id' => 'M2', 'name' => 'Sertifikasi Produk (ORD-2025730)'],
                ['id' => 'M3', 'name' => 'Analisa Laboratorium (ORD-2025812)']
            ];
        }

        echo json_encode(['success' => true, 'data' => $results]);
    }

    public function getOrders($f3)
    {
        $user_id = $f3->get('SESSION.user_id');
        if (!$user_id) {
            echo json_encode(['success' => false, 'data' => []]);
            return;
        }

        try {
            // Get SIL2020 db instance
            $dbSil = $f3->get('DB_SIL');
            if (!$dbSil) {
                echo json_encode(['success' => false, 'data' => []]);
                return;
            }

            // Get user's SIL ID
            $user = new \DB\SQL\Mapper($this->db, 'users');
            $user->load(['id = ?', $user_id]);
            $sil_id = !empty($user->id_customer) ? $user->id_customer : 0;

            if (!$sil_id) {
                echo json_encode(['success' => true, 'data' => []]);
                return;
            }

            // Fetch active and completed orders using DBSilController logic
            require_once __DIR__ . '/DBSilController.php';
            $silService = new DBSilController($dbSil);
            
            $activeOrders = $silService->getOrderHistory($sil_id, 'active') ?: [];
            foreach ($activeOrders as &$o) { $o['_c_status'] = 'ongoing'; }
            
            $completedOrders = $silService->getOrderHistory($sil_id, 'completed') ?: [];
            foreach ($completedOrders as &$o) { $o['_c_status'] = 'completed'; }

            $orders = array_merge($activeOrders, $completedOrders);

            // Fetch Sertifikasi Orders
            $dbSerti = $f3->get('DB_SERTI');
            if ($dbSerti) {
                require_once __DIR__ . '/DBSertiController.php';
                $sertiService = new DBSertiController($dbSerti);
                $activeSerti = $sertiService->getOrderHistory($sil_id, 'active') ?: [];
                foreach ($activeSerti as &$o) { $o['_c_status'] = 'ongoing'; }
                $completedSerti = $sertiService->getOrderHistory($sil_id, 'completed') ?: [];
                foreach ($completedSerti as &$o) { $o['_c_status'] = 'completed'; }
                $orders = array_merge($orders, $activeSerti, $completedSerti);
            }

            // Fetch Kalibrasi Orders
            $dbKalib = $f3->get('DB_KALIB');
            if ($dbKalib) {
                require_once __DIR__ . '/DBKalibrasiController.php';
                $kalibService = new DBKalibrasiController($dbKalib);
                $activeKalib = $kalibService->getOrderHistory($sil_id, 'active') ?: [];
                foreach ($activeKalib as &$o) { $o['_c_status'] = 'ongoing'; }
                $completedKalib = $kalibService->getOrderHistory($sil_id, 'completed') ?: [];
                foreach ($completedKalib as &$o) { $o['_c_status'] = 'completed'; }
                $orders = array_merge($orders, $activeKalib, $completedKalib);
            }

            $results = [];

            foreach ($orders as $o) {
                $no = $o['No_order'] ?? $o['no_permintaan'] ?? $o['no_order'] ?? $o['nomor_order_lengkap'] ?? $o['no_id_order'] ?? '';
                if (empty($no)) continue;
                
                $desc = $o['Uraian_contoh'] ?? $o['nama_produk'] ?? $o['nama_alat'] ?? $o['uraian_order'] ?? $o['nama_jenis_sertifikasi'] ?? '-';
                $sample = substr($desc, 0, 50) . (strlen($desc) > 50 ? '...' : '');
                
                $year = '';
                if (!empty($no)) {
                    $parts = explode('/', $no);
                    $lastPart = trim(end($parts));
                    if (strlen($lastPart) >= 4) {
                        $potentialYear = substr($lastPart, -4);
                        if (is_numeric($potentialYear)) {
                            $year = $potentialYear;
                        }
                    }
                }
                
                if (empty($year)) {
                    if (!empty($o['Tanggal_order'])) {
                         $year = date('Y', strtotime($o['Tanggal_order']));
                    } elseif (!empty($o['tanggal_order'])) {
                         $year = date('Y', strtotime($o['tanggal_order']));
                    } elseif (!empty($o['Tgl_order'])) {
                         $year = date('Y', strtotime($o['Tgl_order']));
                    } elseif (!empty($o['tanggal_pendaftaran'])) {
                         $year = date('Y', strtotime($o['tanggal_pendaftaran']));
                    }
                }
                
                $results[] = [
                    'id' => $o['id_arsip'],
                    'no' => $no,
                    'name' => "$no - $sample",
                    'status' => $o['_c_status'],
                    'year' => $year
                ];
            }

            echo json_encode(['success' => true, 'data' => $results]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'data' => [], 'message' => $e->getMessage()]);
        }
    }

    public function update($f3)
    {
        if (!$f3->get('SESSION.user_id')) {
            echo json_encode(['success' => false, 'message' => 'Unauthorized']);
            return;
        }

        if (!$this->validateCsrf($f3)) {
            echo json_encode(['success' => false, 'message' => 'Invalid CSRF Token']);
            return;
        }

        $complaint_id = $f3->get('POST.complaint_id');
        $topic_id = $f3->get('POST.topic_id');
        $reason = $f3->get('POST.reason');
        $user_id = $f3->get('SESSION.user_id');

        if (empty($complaint_id) || empty($reason)) {
            echo json_encode(['success' => false, 'message' => 'Deskripsi harus diisi']);
            return;
        }

        try {
            // Verify complaint belongs to user and is pending
            $complaint = new DB\SQL\Mapper($this->db, 'complaints');
            $complaint->load(['id = ? AND user_id = ?', $complaint_id, $user_id]);

            if ($complaint->dry()) {
                echo json_encode(['success' => false, 'message' => 'Keluhan tidak ditemukan']);
                return;
            }

            if ($complaint->status !== 'Pending') {
                echo json_encode(['success' => false, 'message' => 'Hanya keluhan dengan status Pending yang dapat diubah']);
                return;
            }

            $complaint->topic_id = $topic_id;
            $complaint->message = $reason;
            $complaint->save();

            echo json_encode(['success' => true, 'message' => 'Keluhan berhasil diperbarui']);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => 'Gagal memperbarui keluhan: ' . $e->getMessage()]);
        }
    }

    public function delete($f3)
    {
        if (!$f3->get('SESSION.user_id')) {
            echo json_encode(['success' => false, 'message' => 'Unauthorized']);
            return;
        }

        $complaint_id = $f3->get('PARAMS.id');
        $user_id = $f3->get('SESSION.user_id');

        try {
            // Verify complaint belongs to user
            $complaint = new DB\SQL\Mapper($this->db, 'complaints');
            $complaint->load(['id = ? AND user_id = ?', $complaint_id, $user_id]);

            if ($complaint->dry()) {
                echo json_encode(['success' => false, 'message' => 'Keluhan tidak ditemukan']);
                return;
            }

            if ($complaint->status !== 'Pending') {
                echo json_encode(['success' => false, 'message' => 'Hanya keluhan dengan status Pending yang dapat dihapus']);
                return;
            }

            $complaint->erase();
            echo json_encode(['success' => true, 'message' => 'Keluhan berhasil dihapus']);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => 'Gagal menghapus keluhan: ' . $e->getMessage()]);
        }
    }

    public function clearMessages($f3)
    {
        if (!$f3->get('SESSION.user_id')) {
            echo json_encode(['success' => false, 'message' => 'Unauthorized']);
            return;
        }

        $complaint_id = $f3->get('POST.complaint_id');
        $user_id = $f3->get('SESSION.user_id');

        if (empty($complaint_id)) {
            echo json_encode(['success' => false, 'message' => 'ID Keluhan tidak valid']);
            return;
        }

        try {
            // Verify complaint belongs to user
            $complaint = $this->db->exec('SELECT id FROM complaints WHERE id = ? AND user_id = ?', [$complaint_id, $user_id]);
            if (empty($complaint)) {
                echo json_encode(['success' => false, 'message' => 'Keluhan tidak ditemukan']);
                return;
            }

            // Delete all chats for this complaint
            $this->db->exec('DELETE FROM complaint_chats WHERE complaint_id = ?', $complaint_id);

            echo json_encode(['success' => true, 'message' => 'Chat berhasil dibersihkan']);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => 'Gagal membersihkan chat: ' . $e->getMessage()]);
        }
    }
}
