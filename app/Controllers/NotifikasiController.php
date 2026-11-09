<?php

class NotifikasiController
{
    private $db;
    private $notifikasiService;

    public function __construct($db, $notifikasiService = null)
    {
        $this->db = $db;
        $this->notifikasiService = $notifikasiService;
    }

    public function getUnreadNotifications($f3)
    {
        if (!$f3->get('SESSION.user_id')) {
            echo json_encode(['success' => false, 'message' => 'Unauthorized']);
            return;
        }

        if (!$this->notifikasiService) {
            echo json_encode(['success' => false, 'message' => 'Service unavailable']);
            return;
        }

        $notifications = $this->notifikasiService->getUnread($f3->get('SESSION.user_id'));

        $data = [];
        foreach ($notifications as $notif) {
            $data[] = [
                'id'      => $notif['id'],
                'title'   => $notif['title'],
                'message' => $notif['message'],
                'time'    => $this->timeElapsedString($notif['created_at']),
                'type'    => $notif['type'],
                'link'    => $notif['link'],
                'read'    => false
            ];
        }

        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => true, 'data' => $data]);
    }

    public function markNotificationRead($f3)
    {
        if (!$f3->get('SESSION.user_id')) {
            echo json_encode(['success' => false]);
            return;
        }

        if (!$this->notifikasiService) {
            echo json_encode(['success' => false]);
            return;
        }

        $id  = $f3->get('POST.id');
        $all = $f3->get('POST.all');

        if ($all) {
            $this->notifikasiService->markAllAsRead($f3->get('SESSION.user_id'));
        } elseif ($id) {
            $this->notifikasiService->markAsRead($id, $f3->get('SESSION.user_id'));
        }

        echo json_encode(['success' => true]);
    }

    // ─── Private helpers ──────────────────────────────────────────────────────


    public function triggerNotification($f3)
    {
        header('Content-Type: application/json; charset=utf-8');
        
        // Sederhana: gunakan secret token untuk keamanan antar server
        $token = $f3->get('POST.api_token') ?? '';
        if ($token !== 'sil2selport_secret_token') {
            echo json_encode(['success' => false, 'message' => 'Unauthorized API request']);
            return;
        }

        if (!$this->notifikasiService) {
            echo json_encode(['success' => false, 'message' => 'Service unavailable']);
            return;
        }

        $userId = $f3->get('POST.user_id');
        $title = $f3->get('POST.title');
        $message = $f3->get('POST.message');
        $type = $f3->get('POST.type') ?: 'order';
        $link = $f3->get('POST.link');

        if (!$userId || !$title || !$message) {
            echo json_encode(['success' => false, 'message' => 'Missing required fields']);
            return;
        }

        $success = $this->notifikasiService->create($userId, $title, $message, $type, $link);
        echo json_encode(['success' => $success]);
    }

    private function timeElapsedString($datetime, $full = false)
    {
        if (function_exists('formatTanggalIndo')) {
            return formatTanggalIndo($datetime);
        }
        return date('d M Y', strtotime($datetime));
    }
}
