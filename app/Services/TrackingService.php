<?php

class TrackingService
{
    private $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    public function checkIPBlock($ip)
    {
        $blocked = new DB\SQL\Mapper($this->db, 'blocked_ips');
        $blocked->load(['ip_address = ? AND is_active = 1', $ip]);
        
        if (!$blocked->dry()) {
            return $blocked->reason;
        }
        return false;
    }

    public function logActivity($f3, $action)
    {
        $user_id = $f3->get('SESSION.user_id');
        $auth_source = $f3->get('SESSION.auth_source');

        // Only track activities for customers (portal users), skip for staff
        if ($auth_source === 'sil') {
            return;
        }

        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $device_info = $this->parseUserAgent($user_agent);

        $log = new DB\SQL\Mapper($this->db, 'activity_logs');
        $log->user_id = $user_id;
        
        $log->ip_address = $this->getClientIP();
        $log->user_agent = $user_agent;
        $log->device_type = $device_info['device'];
        $log->browser = $device_info['browser'];
        $log->os = $device_info['os'];
        $log->action = $action;
        $log->url = $_SERVER['REQUEST_URI'];
        $log->created_at = date('Y-m-d H:i:s');
        $log->save();
    }

    public function logProfileChange($user_id, $field, $old, $new)
    {
        if ($old == $new) return;

        $log = new DB\SQL\Mapper($this->db, 'profile_logs');
        $log->user_id = $user_id;
        $log->field_name = $field;
        $log->old_value = $old;
        $log->new_value = $new;
        $log->ip_address = $this->getClientIP();
        $log->created_at = date('Y-m-d H:i:s');
        $log->save();
    }

    public function blockIP($ip, $reason, $blockedByUsername)
    {
        $block = new DB\SQL\Mapper($this->db, 'blocked_ips');
        $block->ip_address          = $ip;
        $block->reason              = $reason;
        $block->blocked_by_user_id  = null;
        $block->blocked_by_username = $blockedByUsername;
        $block->blocked_at          = date('Y-m-d H:i:s');
        $block->is_active           = 1;
        $block->save();
    }

    public function unblockIP($id)
    {
        $block = new DB\SQL\Mapper($this->db, 'blocked_ips');
        $block->load(['id=?', $id]);
        if (!$block->dry()) {
            $block->erase();
        }
    }

    private function getClientIP()
    {
        if (!empty($_SERVER['HTTP_CLIENT_IP'])) return $_SERVER['HTTP_CLIENT_IP'];
        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) return $_SERVER['HTTP_X_FORWARDED_FOR'];
        return $_SERVER['REMOTE_ADDR'];
    }

    private function parseUserAgent($user_agent)
    {
        $device = 'Desktop';
        if (preg_match('/mobile|android|iphone|ipad|ipod/i', $user_agent)) {
            $device = preg_match('/ipad|tablet/i', $user_agent) ? 'Tablet' : 'Mobile';
        }

        $browser = 'Unknown';
        if (preg_match('/Edge\/([0-9.]+)/i', $user_agent, $matches)) $browser = 'Edge ' . $matches[1];
        elseif (preg_match('/Chrome\/([0-9.]+)/i', $user_agent, $matches)) $browser = 'Chrome ' . $matches[1];
        elseif (preg_match('/Firefox\/([0-9.]+)/i', $user_agent, $matches)) $browser = 'Firefox ' . $matches[1];
        elseif (preg_match('/Safari\/([0-9.]+)/i', $user_agent, $matches)) $browser = 'Safari ' . $matches[1];

        $os = 'Unknown';
        if (preg_match('/Windows NT ([0-9.]+)/i', $user_agent, $matches)) $os = 'Windows ' . $matches[1];
        elseif (preg_match('/Mac OS X ([0-9_]+)/i', $user_agent, $matches)) $os = 'macOS ' . str_replace('_', '.', $matches[1]);
        elseif (preg_match('/Android ([0-9.]+)/i', $user_agent, $matches)) $os = 'Android ' . $matches[1];
        elseif (preg_match('/iPhone OS ([0-9_]+)/i', $user_agent, $matches)) $os = 'iOS ' . str_replace('_', '.', $matches[1]);

        return ['device' => $device, 'browser' => $browser, 'os' => $os];
    }
}