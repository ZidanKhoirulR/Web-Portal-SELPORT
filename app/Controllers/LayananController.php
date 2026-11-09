<?php

class LayananController
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

        $category = $f3->get('POST.category');
        $subcategory = $f3->get('POST.subcategory');
        $message = $f3->get('POST.message');
        $order_no = $f3->get('POST.order_no');
        $order_name = $f3->get('POST.order_name');
        $user_id = $f3->get('SESSION.user_id');

        if (empty($message)) {
            echo json_encode(['success' => false, 'message' => 'Pesan harus diisi']);
            return;
        }

        // Validate file type if uploaded
        $file_path = null;
        $original_filename = null;
        $uploadFile = isset($_FILES['chat_file']) ? $_FILES['chat_file'] : null;

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

            $uploadDir = 'public/uploads/services/chats/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0777, true);
            }
            
            $original_filename = $uploadFile['name'];
            $fileExtension = $this->getFileExtension($original_filename);
            $fileName = uniqid('svc_') . '.' . $fileExtension;
            $targetPath = $uploadDir . $fileName;

            if (move_uploaded_file($uploadFile['tmp_name'], $targetPath)) {
                $file_path = $fileName;
            }
        }

        try {
            // Generate unique no_layanan based on current date
            $datePrefix = 'LYN-' . date('Ymd') . '-';
            $latest = $this->db->exec('SELECT no_layanan FROM services WHERE no_layanan LIKE ? ORDER BY no_layanan DESC LIMIT 1', $datePrefix . '%');
            $nextNum = 1;
            if (!empty($latest) && !empty($latest[0]['no_layanan'])) {
                $parts = explode('-', $latest[0]['no_layanan']);
                if (count($parts) >= 3) {
                    $nextNum = intval($parts[2]) + 1;
                }
            }
            $no_layanan = $datePrefix . str_pad($nextNum, 4, '0', STR_PAD_LEFT);

            $categoryValue = !empty($category) ? $category : null;
            $subcategoryValue = !empty($subcategory) ? $subcategory : null;
            $orderNoValue = !empty($order_no) ? $order_no : null;
            $orderNameValue = !empty($order_name) ? $order_name : null;
            $filePathValue = !empty($file_path) ? $file_path : null;
            $originalFilenameValue = !empty($original_filename) ? $original_filename : null;

            $this->db->exec(
                'INSERT INTO services 
                 (user_id, no_layanan, category, subcategory, order_no, order_name, message, file_path, original_filename, status, is_general_chat, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, \'Pending\', 0, NOW(), NOW())',
                [
                    $user_id,
                    $no_layanan,
                    $categoryValue,
                    $subcategoryValue,
                    $orderNoValue,
                    $orderNameValue,
                    $message,
                    $filePathValue,
                    $originalFilenameValue
                ]
            );

            $newId = $this->db->lastInsertId();
            if (!$newId) {
                $inserted = $this->db->exec('SELECT id FROM services WHERE no_layanan = ? LIMIT 1', [$no_layanan]);
                $newId = !empty($inserted) ? $inserted[0]['id'] : null;
            }

            // Also insert into service_chats
            if ($newId) {
                $this->db->exec(
                    'INSERT INTO service_chats (service_id, user_id, message, file_path, original_filename, is_admin, is_read, created_at)
                     VALUES (?, ?, ?, ?, ?, 0, 0, NOW())',
                    [
                        $newId,
                        $user_id,
                        $message,
                        $filePathValue,
                        $originalFilenameValue
                    ]
                );
            }

            if ($this->notifikasiService) {
                $customerData = $this->db->exec('SELECT nama_lengkap, username FROM users WHERE id = ? LIMIT 1', [$user_id]);
                $customerName = !empty($customerData) ? ($customerData[0]['nama_lengkap'] ?? $customerData[0]['username'] ?? 'Pelanggan') : 'Pelanggan';

                $waMsg = "Pemberitahuan Admin: Layanan baru ($no_layanan) telah diajukan oleh *$customerName* dan menunggu untuk diproses.";
                $this->notifikasiService->sendToAdminsByRole(
                    ['mitra', 'supermitra'], 
                    'Layanan Baru', 
                    "Layanan baru ($no_layanan) dari $customerName perlu diproses.", 
                    'info', 
                    '/mitra/layanan-pelanggan',
                    $waMsg
                );

                $waMsgToMitra = "*Notifikasi Selport*\n"
                              . "Halo {nama_user}, terdapat tiket Layanan baru:\n"
                              . "*ID Tiket*: {$no_layanan}\n"
                              . "*Pelanggan*: {$customerName}\n"
                              . "*Topik*: " . (!empty($categoryValue) ? $categoryValue : "Layanan") . "\n"
                              . "Segera balas pesan pelanggan melalui menu Layanan Pelanggan.";
                $this->notifikasiService->sendWhatsAppToMitra($waMsgToMitra);
            }

            echo json_encode([
                'success' => true, 
                'message' => 'Layanan berhasil dikirim',
                'id' => (int)$newId,
                'no_layanan' => $no_layanan
            ]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => 'Gagal mengirim layanan: ' . $e->getMessage()]);
        }
    }

    public function getList($f3)
    {
        if (!$f3->get('SESSION.user_id')) {
            echo json_encode(['success' => false, 'message' => 'Unauthorized']);
            return;
        }

        $user_id = $f3->get('SESSION.user_id');
        $status = $f3->get('GET.status'); // Optional filter by status

        $sql = 'SELECT s.*, u.nama_lengkap 
                FROM services s 
                LEFT JOIN users u ON s.user_id = u.id
                WHERE s.user_id = ? AND s.is_general_chat = 0';
        $params = [$user_id];

        if ($status && in_array($status, ['Pending', 'In Progress', 'Resolved'])) {
            $sql .= ' AND s.status = ?';
            $params[] = $status;
        }

        $sql .= ' ORDER BY s.updated_at DESC';

        $services = $this->db->exec($sql, $params);

        echo json_encode(['success' => true, 'data' => $services]);
    }

    public function getMessages($f3)
    {
        if (!$f3->get('SESSION.user_id')) {
            echo json_encode(['success' => false, 'message' => 'Unauthorized']);
            return;
        }

        $service_id = $f3->get('PARAMS.id');
        $user_id = $f3->get('SESSION.user_id');

        // Verify service belongs to user
        $service = $this->db->exec('SELECT id FROM services WHERE id = ? AND user_id = ?', [$service_id, $user_id]);
        if (empty($service)) {
            echo json_encode(['success' => false, 'message' => 'Layanan not found']);
            return;
        }

        // Mark admin messages as read
        $this->db->exec(
            'UPDATE service_chats SET is_read = 1 WHERE service_id = ? AND is_admin = 1 AND is_read = 0',
            $service_id
        );

        $messages = $this->db->exec(
            'SELECT sc.*, COALESCE(u.nama_lengkap, u.username) as nama_pengirim FROM service_chats sc LEFT JOIN users u ON sc.user_id = u.id WHERE sc.service_id = ? ORDER BY sc.created_at ASC, sc.id ASC',
            $service_id
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

        echo json_encode(['success' => true, 'data' => $messages]);
    }

    public function sendMessage($f3)
    {
        if (!$f3->get('SESSION.user_id')) {
            echo json_encode(['success' => false, 'message' => 'Unauthorized']);
            return;
        }

        $service_id = $f3->get('POST.service_id');
        $message = $f3->get('POST.message');
        $user_id = $f3->get('SESSION.user_id');

        if (!$service_id) {
            echo json_encode(['success' => false, 'message' => 'ID Layanan tidak ditemukan']);
            return;
        }

        // Validate file type if uploaded
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

            $uploadDir = 'public/uploads/services/chats/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0777, true);
            }
            
            $original_filename = $_FILES['chat_file']['name'];
            $fileExtension = $this->getFileExtension($original_filename);
            $fileName = uniqid('svc_') . '.' . $fileExtension;
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
            $this->db->exec(
                'INSERT INTO service_chats (service_id, user_id, message, file_path, original_filename, is_admin, is_read, created_at)
                 VALUES (?, ?, ?, ?, ?, 0, 0, NOW())',
                [
                    $service_id,
                    $user_id,
                    $message ?: null,
                    $file_path ?: null,
                    $original_filename ?: null
                ]
            );

            // Update status to In Progress
            $this->db->exec("UPDATE services SET status='In Progress', updated_at=NOW() WHERE id=? AND status != 'Resolved'", $service_id);

            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => 'Gagal mengirim pesan: ' . $e->getMessage()]);
        }
    }

    public function getCategories($f3)
    {
        $user_id = $f3->get('SESSION.user_id');
        if (!$user_id) {
            echo json_encode(['success' => false, 'data' => []]);
            return;
        }

        // Predefined categories for services
        $categories = [
            ['id' => 'info', 'name' => 'Informasi Umum', 'icon' => 'ri-information-line'],
            ['id' => 'konsultasi', 'name' => 'Konsultasi Teknis', 'icon' => 'ri-customer-service-line'],
            ['id' => 'permintaan', 'name' => 'Permintaan Data', 'icon' => 'ri-file-list-line'],
            ['id' => 'dokumen', 'name' => 'Permintaan Dokumen', 'icon' => 'ri-folder-line'],
            ['id' => 'lainnya', 'name' => 'Lainnya', 'icon' => 'ri-more-line'],
        ];

        echo json_encode(['success' => true, 'data' => $categories]);
    }

    public function clearMessages($f3)
    {
        if (!$f3->get('SESSION.user_id')) {
            echo json_encode(['success' => false, 'message' => 'Unauthorized']);
            return;
        }

        $service_id = $f3->get('POST.service_id');
        $user_id = $f3->get('SESSION.user_id');

        if (empty($service_id)) {
            echo json_encode(['success' => false, 'message' => 'ID Layanan tidak valid']);
            return;
        }

        try {
            // Verify service belongs to user
            $service = $this->db->exec('SELECT id FROM services WHERE id = ? AND user_id = ?', [$service_id, $user_id]);
            if (empty($service)) {
                echo json_encode(['success' => false, 'message' => 'Layanan tidak ditemukan']);
                return;
            }

            // Delete all chats for this service
            $this->db->exec('DELETE FROM service_chats WHERE service_id = ?', $service_id);

            echo json_encode(['success' => true, 'message' => 'Chat berhasil dibersihkan']);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => 'Gagal membersihkan chat: ' . $e->getMessage()]);
        }
    }

    // MITRA/ADMIN METHODS

    public function getServices($f3)
    {
        $this->checkMitra($f3);
        header('Content-Type: application/json');

        $id = $f3->get('GET.id');
        $search = $f3->get('GET.search');
        $status = $f3->get('GET.status'); // Filter by status

        if ($id) {
            try {
                $service = $this->db->exec(
                    "SELECT s.*, u.username, u.nama_lengkap, u.email, u.no_whatsapp, 
                            r.nama_lengkap as resolved_by_name
                     FROM services s 
                     JOIN users u ON s.user_id = u.id 
                     LEFT JOIN users r ON s.resolved_by = r.id
                     WHERE s.id = ?",
                    $id
                );

                if (empty($service)) {
                    echo json_encode(['success' => false, 'message' => 'Not found']);
                    return;
                }

                $this->db->exec(
                    "UPDATE service_chats SET is_read = 1 
                     WHERE service_id = ? AND is_admin = 0 AND is_read = 0",
                    $id
                );

                $messages = $this->db->exec(
                    "SELECT sc.*, COALESCE(u.nama_lengkap, u.username) as nama_pengirim 
                     FROM service_chats sc 
                     LEFT JOIN users u ON sc.user_id = u.id 
                     WHERE sc.service_id = ? 
                     ORDER BY sc.created_at ASC, sc.id ASC",
                    $id
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

                $service[0]['messages'] = $messages ?: [];
                echo json_encode(['success' => true, 'data' => $service]);
            } catch (Exception $e) {
                error_log('[LayananController::getServices] Error: ' . $e->getMessage());
                echo json_encode(['success' => false, 'message' => 'Gagal memuat detail']);
            }
            return;
        }

        try {
            $where = "";
            $params = [];

            if ($search) {
                $where = "WHERE (s.no_layanan LIKE ? OR u.username LIKE ? OR u.nama_lengkap LIKE ? OR s.order_no LIKE ?)";
                $term = "%$search%";
                $params = [$term, $term, $term, $term];
            }

            if ($status && in_array($status, ['Pending', 'In Progress', 'Resolved'])) {
                if ($where) {
                    $where .= " AND s.status = ?";
                } else {
                    $where = "WHERE s.status = ?";
                }
                $params[] = $status;
            }

            $sql = "SELECT s.id, s.no_layanan, s.user_id, s.category, s.subcategory, s.order_no, s.order_name,
                           s.message, s.file_path, s.original_filename, s.status, s.created_at, s.updated_at,
                           s.resolved_by, s.is_general_chat,
                           u.username, u.nama_lengkap, u.email, u.no_whatsapp,
                           r.nama_lengkap as resolved_by_name,
                           (SELECT COUNT(*) FROM service_chats sc 
                            WHERE sc.service_id = s.id AND sc.is_admin = 0 AND sc.is_read = 0) as unread_count
                    FROM services s 
                    JOIN users u ON s.user_id = u.id 
                    LEFT JOIN users r ON s.resolved_by = r.id
                    $where
                    ORDER BY s.updated_at DESC, s.created_at DESC";
                    
            $services = $params ? $this->db->exec($sql, $params) : $this->db->exec($sql);
            
            if ($services === false || $services === null) {
                $services = [];
            }

            echo json_encode(['data' => $services, 'total' => count($services)]);
        } catch (Exception $e) {
            error_log('[LayananController::getServices] Error: ' . $e->getMessage());
            echo json_encode(['data' => [], 'total' => 0]);
        }
    }

    public function replyService($f3)
    {
        $this->checkMitra($f3);
        header('Content-Type: application/json');
        
        $service_id = $f3->get('POST.service_id');
        $reply_text = $f3->get('POST.reply_text');
        $admin_id = $f3->get('SESSION.user_id');

        if (!$service_id) {
            echo json_encode(['success' => false, 'message' => 'ID Layanan tidak ditemukan']);
            return;
        }

        // Validate file type if uploaded
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

            $uploadDir = 'public/uploads/services/chats/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0777, true);
            }
            
            $original_filename = $_FILES['chat_file']['name'];
            $fileExtension = $this->getFileExtension($original_filename);
            $fileName = uniqid('svc_') . '.' . $fileExtension;
            $targetPath = $uploadDir . $fileName;

            if (move_uploaded_file($_FILES['chat_file']['tmp_name'], $targetPath)) {
                $file_path = $fileName;
            }
        }

        try {
            $today = date('Y-m-d');
            $shouldSendWa = true;
            $lastAdminChatToday = $this->db->exec(
                "SELECT id FROM service_chats WHERE service_id = ? AND is_admin = 1 AND DATE(created_at) = ? LIMIT 1",
                [$service_id, $today]
            );
            if (!empty($lastAdminChatToday)) {
                $shouldSendWa = false;
            }

            $chat = new DB\SQL\Mapper($this->db, 'service_chats');
            $chat->service_id = $service_id;
            $chat->user_id = $admin_id;
            $chat->message = $reply_text;
            $chat->file_path = $file_path;
            $chat->original_filename = $original_filename;
            $chat->is_admin = 1;
            $chat->is_read = 0;
            $chat->created_at = date('Y-m-d H:i:s');
            $chat->save();

            // Update service status
            $service = new DB\SQL\Mapper($this->db, 'services');
            $service->load(['id = ?', $service_id]);
            if (!$service->dry()) {
                if ($service->status === 'Pending') {
                    $service->status = 'In Progress';
                }
                $service->updated_at = date('Y-m-d H:i:s');
                $service->save();
                
                if ($this->notifikasiService && $shouldSendWa) {
                    $customerData = $this->db->exec("SELECT no_whatsapp FROM users WHERE id=?", $service->user_id);
                    if (!empty($customerData) && !empty($customerData[0]['no_whatsapp'])) {
                        $waMsg = "Layanan Anda ({$service->no_layanan}) telah direspon oleh layanan mitra. Silahkan cek aplikasi untuk melihat balasan.\n\nMohon tidak membalas pesan ini.";
                        $this->notifikasiService->sendWhatsApp($customerData[0]['no_whatsapp'], $waMsg);
                    }
                }
            }

            echo json_encode(['success' => true, 'message' => 'Balasan terkirim']);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => 'Gagal mengirim balasan']);
        }
    }

    public function resolveService($f3)
    {
        $this->checkMitra($f3);
        header('Content-Type: application/json');

        $service_id = $f3->get('POST.service_id');
        $mitra_id = $f3->get('SESSION.user_id');

        if (!$service_id) {
            echo json_encode(['success' => false, 'message' => 'ID Layanan tidak valid']);
            return;
        }

        try {
            $this->db->exec(
                "UPDATE services SET status='Resolved', updated_at=NOW(), resolved_by=? WHERE id=?", 
                [$mitra_id, $service_id]
            );

            $service = $this->db->exec("SELECT user_id, no_layanan FROM services WHERE id=?", $service_id);
            if ($service && $this->notifikasiService) {
                $this->notifikasiService->create(
                    $service[0]['user_id'],
                    'Layanan Selesai',
                    'Layanan Anda telah ditandai selesai oleh tim kami.',
                    'success',
                    '/dashboard/section/layanan'
                );
                
                $customerData = $this->db->exec("SELECT no_whatsapp FROM users WHERE id=?", $service[0]['user_id']);
                if (!empty($customerData) && !empty($customerData[0]['no_whatsapp'])) {
                    $waMsg = "Pemberitahuan: Layanan Anda (" . $service[0]['no_layanan'] . ") telah ditandai selesai oleh tim kami.";
                    $this->notifikasiService->sendWhatsApp($customerData[0]['no_whatsapp'], $waMsg);
                }
            }

            echo json_encode(['success' => true, 'message' => 'Layanan berhasil diselesaikan']);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => 'Terjadi kesalahan']);
        }
    }

    public function deleteService($f3)
    {
        $this->checkMitra($f3);
        header('Content-Type: application/json');

        $service_id = $f3->get('POST.service_id');

        if (!$service_id) {
            echo json_encode(['success' => false, 'message' => 'ID Layanan tidak ditemukan']);
            return;
        }

        try {
            $this->db->exec("DELETE FROM service_chats WHERE service_id=?", $service_id);
            $this->db->exec("DELETE FROM services WHERE id=?", $service_id);

            echo json_encode(['success' => true, 'message' => 'Layanan berhasil dihapus']);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => 'Gagal menghapus layanan']);
        }
    }

    public function clearMessagesMitra($f3)
    {
        $this->checkMitra($f3);
        header('Content-Type: application/json');

        $service_id = $f3->get('POST.service_id');

        if (!$service_id) {
            echo json_encode(['success' => false, 'message' => 'ID Layanan tidak valid']);
            return;
        }

        try {
            $this->db->exec('DELETE FROM service_chats WHERE service_id = ?', $service_id);
            echo json_encode(['success' => true, 'message' => 'Chat berhasil dibersihkan']);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => 'Gagal membersihkan chat']);
        }
    }

    private function checkMitra($f3)
    {
        $role = $f3->get('SESSION.role');
        if ($role !== 'mitra' && $role !== 'supermitra') {
            $f3->reroute('/login');
            exit;
        }
    }
}
