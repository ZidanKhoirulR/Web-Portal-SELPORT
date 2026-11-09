<?php

class PengaduanController
{
    private $db;

    public function __construct($db)
    {
        $this->db = $db;
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
            $fileExtension = strtolower(pathinfo($original_filename, PATHINFO_EXTENSION));
            $fileName = uniqid('comp_') . '.' . $fileExtension;
            $targetPath = $uploadDir . $fileName;

            if (move_uploaded_file($uploadFile['tmp_name'], $targetPath)) {
                $file_path = $fileName;
            }
        }

        try {
            $complaint = new DB\SQL\Mapper($this->db, 'complaints');
            $complaint->user_id = $user_id;
            
            if (is_numeric($topic_id) && $topic_id < 100) {
                $complaint->topic_id = $topic_id;
            } else {
                $complaint->topic_id = 5;
            }
            
            $complaint->order_no = $order_no ?: null;
            $complaint->order_name = $order_name ?: null;
            $complaint->message = $reason;
            $complaint->file_path = $file_path;
            $complaint->original_filename = $original_filename;
            $complaint->status = 'Pending';
            $complaint->save();

            echo json_encode([
                'success' => true, 
                'message' => 'Pengaduan berhasil dikirim',
                'id' => $complaint->get('_id') // F3 typically uses _id or it returns the autoincrement ID
            ]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => 'Gagal mengirim pengaduan: ' . $e->getMessage()]);
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
            'SELECT c.*, t.name as topic_name 
            FROM complaints c 
            LEFT JOIN complaint_topics t ON c.topic_id = t.id 
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
        // Get complaints with topic name, ordered by updated_at (recent activity first)
        $complaints = $this->db->exec(
            'SELECT c.*, t.name as topic_name 
            FROM complaints c 
            LEFT JOIN complaint_topics t ON c.topic_id = t.id 
            WHERE c.user_id = ? 
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
            'SELECT * FROM complaint_chats WHERE complaint_id = ? ORDER BY created_at ASC',
            $chat_id
        );

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
            'SELECT * FROM complaint_chats WHERE complaint_id = ? ORDER BY created_at ASC',
            $complaint_id
        );

        echo json_encode(['success' => true, 'data' => $messages]);
    }

    public function sendMessage($f3)
    {
        if (!$f3->get('SESSION.user_id')) {
            echo json_encode(['success' => false, 'message' => 'Unauthorized']);
            return;
        }

        $complaint_id = $f3->get('POST.complaint_id');
        $message = $f3->get('POST.message');
        $user_id = $f3->get('SESSION.user_id');

        if (!$complaint_id) {
            echo json_encode(['success' => false, 'message' => 'ID Pengaduan tidak ditemukan']);
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
            
            $original_filename = $file['name'];
            $fileExtension = strtolower(pathinfo($original_filename, PATHINFO_EXTENSION));
            $fileName = uniqid('chat_') . '.' . $fileExtension;
            $targetPath = $uploadDir . $fileName;

            if (move_uploaded_file($file['tmp_name'], $targetPath)) {
                $file_path = $fileName;
            }
        }

        if (empty($message) && empty($file_path)) {
            echo json_encode(['success' => false, 'message' => 'Pesan atau file harus diisi']);
            return;
        }

        try {
            $chat = new DB\SQL\Mapper($this->db, 'complaint_chats');
            $chat->complaint_id = $complaint_id;
            $chat->user_id = $user_id;
            $chat->message = $message;
            $chat->file_path = $file_path;
            $chat->original_filename = $original_filename;
            $chat->is_admin = 0;
            $chat->is_read = 0;
            $chat->save();

            // Update status to In Progress and refresh complaint updated_at
        $this->db->exec("UPDATE complaints SET status='In Progress', updated_at=NOW() WHERE id=? AND status != 'Resolved'", $complaint_id);

            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => 'Gagal mengirim pesan: ' . $e->getMessage()]);
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
            
            // Get orders (without year/layanan filters to get everything)
            $activeOrders = $silService->getOrderHistory($sil_id, 'active') ?: [];
            $completedOrders = $silService->getOrderHistory($sil_id, 'completed') ?: [];

            $orders = array_merge($activeOrders, $completedOrders);
            $results = [];

            foreach ($orders as $o) {
                $no = !empty($o['No_order']) ? $o['No_order'] : $o['no_permintaan'];
                if (empty($no)) continue;
                $sample = !empty($o['Uraian_contoh']) ? substr($o['Uraian_contoh'], 0, 50) . (strlen($o['Uraian_contoh']) > 50 ? '...' : '') : '-';
                
                // Determine order status
                $isCompleted = !empty($o['tanggal_terima_lhu']) && $o['tanggal_terima_lhu'] > '1000-01-01';
                $status = $isCompleted ? 'completed' : 'ongoing';
                
                $results[] = [
                    'id' => $o['id_arsip'],
                    'no' => $no,
                    'name' => "$no - $sample",
                    'status' => $status
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
                echo json_encode(['success' => false, 'message' => 'Pengaduan tidak ditemukan']);
                return;
            }

            if ($complaint->status !== 'Pending') {
                echo json_encode(['success' => false, 'message' => 'Hanya pengaduan dengan status Pending yang dapat diubah']);
                return;
            }

            $complaint->topic_id = $topic_id;
            $complaint->message = $reason;
            $complaint->save();

            echo json_encode(['success' => true, 'message' => 'Pengaduan berhasil diperbarui']);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => 'Gagal memperbarui pengaduan: ' . $e->getMessage()]);
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
                echo json_encode(['success' => false, 'message' => 'Pengaduan tidak ditemukan']);
                return;
            }

            if ($complaint->status !== 'Pending') {
                echo json_encode(['success' => false, 'message' => 'Hanya pengaduan dengan status Pending yang dapat dihapus']);
                return;
            }

            $complaint->erase();
            echo json_encode(['success' => true, 'message' => 'Pengaduan berhasil dihapus']);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => 'Gagal menghapus pengaduan: ' . $e->getMessage()]);
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
            echo json_encode(['success' => false, 'message' => 'ID Pengaduan tidak valid']);
            return;
        }

        try {
            // Verify complaint belongs to user
            $complaint = $this->db->exec('SELECT id FROM complaints WHERE id = ? AND user_id = ?', [$complaint_id, $user_id]);
            if (empty($complaint)) {
                echo json_encode(['success' => false, 'message' => 'Pengaduan tidak ditemukan']);
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
