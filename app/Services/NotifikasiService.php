<?php

class NotifikasiService
{
    private $db;
    private $dbSil;

    public function __construct($db, $dbSil = null)
    {
        $this->db = $db;
        $this->dbSil = $dbSil;
        $this->ensureTableExists();
    }

    private function ensureTableExists()
    {
        try {
            $this->db->exec("
                CREATE TABLE IF NOT EXISTS notifikasi (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    user_id INT NOT NULL,
                    title VARCHAR(255),
                    message TEXT,
                    type VARCHAR(50) DEFAULT 'order',
                    link VARCHAR(255) NULL,
                    is_read TINYINT(1) DEFAULT 0,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
            
            // Coba update struktur kalau tabelnya sudah ada dan pakai ENUM/VARCHAR yang lama
            try {
                $this->db->exec("ALTER TABLE notifikasi MODIFY COLUMN type VARCHAR(50) DEFAULT 'order'");
            } catch (Exception $e) {}
        } catch (Exception $e) {
            error_log("Failed to create notifikasi table: " . $e->getMessage());
        }
    }

    public function create($userId, $title, $message, $type = 'info', $link = null)
    {
        try {
            $notif = new DB\SQL\Mapper($this->db, 'notifikasi');
            $notif->user_id = $userId;
            $notif->title = $title;
            $notif->message = $message;
            $notif->type = $type;
            $notif->link = $link;
            $notif->save();
            return true;
        } catch (Exception $e) {
            error_log("Failed to create notification: " . $e->getMessage());
            return false;
        }
    }

    public function getUnread($userId, $limit = 10)
    {
        return $this->db->exec(
            'SELECT * FROM notifikasi WHERE user_id = ? AND is_read = 0 ORDER BY created_at DESC LIMIT ?',
            [$userId, $limit]
        );
    }

    public function getAll($userId, $limit = 20)
    {
        return $this->db->exec(
            'SELECT * FROM notifikasi WHERE user_id = ? ORDER BY created_at DESC LIMIT ?',
            [$userId, $limit]
        );
    }

    public function markAsRead($id, $userId)
    {
        try {
            $this->db->exec(
                'UPDATE notifikasi SET is_read = 1 WHERE id = ? AND user_id = ?',
                [$id, $userId]
            );
            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    public function markAllAsRead($userId)
    {
        try {
            $this->db->exec(
                'UPDATE notifikasi SET is_read = 1 WHERE user_id = ?',
                [$userId]
            );
            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    public function formatPhoneNumber($phone)
    {
        $phone = preg_replace('/[^0-9]/', '', $phone ?? '');
        
        $awal_hp = substr($phone, 0, 2);
        $akhir_hp = substr($phone, 2);
        
        if ($awal_hp == "08") {
            return "628" . $akhir_hp;
        }
        
        if (substr($phone, 0, 2) == "62") {
            return $phone;
        }
        
        if (substr($phone, 0, 3) == "+62") {
            return substr($phone, 1);
        }
        
        return $phone;
    }

    public function sendWhatsApp($phone, $message)
    {
        if (empty($phone)) return ['success' => false, 'response' => 'No phone number provided'];
        
        $phone = $this->formatPhoneNumber($phone);
        
        $apikey = getenv('WANOTIF_APIKEY') ?: '';
        $url = 'https://api.wanotif.id/v1/send';
        
        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, $url);
        curl_setopt($curl, CURLOPT_HEADER, 0);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, 0);
        curl_setopt($curl, CURLOPT_TIMEOUT, 30);
        curl_setopt($curl, CURLOPT_POST, 1);
        curl_setopt($curl, CURLOPT_POSTFIELDS, array(
            'Apikey'    => $apikey,
            'Phone'     => $phone,
            'Message'   => $message,
        ));
        $response = curl_exec($curl);
        $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);
        
        return ['success' => ($httpCode == 200), 'response' => $response];
    }

    public function sendToAdminsByRole($roles, $title, $message, $type = 'info', $link = null, $waMessage = null)
    {
        if (is_string($roles)) {
            $roles = [$roles];
        }
        
        if (empty($roles)) return false;
        
        $rolesStr = "'" . implode("','", $roles) . "'";
        $sql = "SELECT id, no_whatsapp FROM users WHERE role IN ($rolesStr) AND status = 'active'";
        try {
            $admins = $this->db->exec($sql);
            
            foreach ($admins as $admin) {
                $this->create($admin['id'], $title, $message, $type, $link);
                
                if ($waMessage && !empty($admin['no_whatsapp'])) {
                    $this->sendWhatsApp($admin['no_whatsapp'], $waMessage);
                }
            }
            return true;
        } catch (Exception $e) {
            error_log("Failed to send to admins: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Kirim WhatsApp ke semua Mitra yang terdaftar di tb_arsipuser (sil2020).
     * Pesan dikirim secara personal dengan menyapa nama masing-masing.
     *
     * @param string $messageTemplate Template pesan. Gunakan {nama_user} sebagai placeholder nama.
     * @return int Jumlah pesan yang berhasil terkirim.
     */
    public function sendWhatsAppToMitra($messageTemplate)
    {
        if (!$this->dbSil) {
            error_log('[WA Mitra] dbSil tidak tersedia, skip pengiriman.');
            return 0;
        }

        try {
            $mitraUsers = $this->dbSil->exec(
                "SELECT nama_user, no_hp FROM tb_arsipuser WHERE si_selport = 'mitra' AND no_hp IS NOT NULL AND no_hp != ''"
            );
        } catch (\Exception $e) {
            error_log('[WA Mitra] Gagal query tb_arsipuser: ' . $e->getMessage());
            return 0;
        }

        if (empty($mitraUsers)) {
            error_log('[WA Mitra] Tidak ada user mitra dengan no_hp yang valid.');
            return 0;
        }

        $sent = 0;
        foreach ($mitraUsers as $mitra) {
            $namaUser = $mitra['nama_user'] ?? 'Mitra';
            $personalMessage = str_replace('{nama_user}', $namaUser, $messageTemplate);

            try {
                $result = $this->sendWhatsApp($mitra['no_hp'], $personalMessage);
                if ($result['success']) {
                    $sent++;
                }

                // Log pengiriman ke tb_list_wa
                try {
                    $this->dbSil->exec(
                        "INSERT INTO tb_list_wa (isi_wa_server, no_hp_tujuan, tanggal_kirim) VALUES (?, ?, NOW())",
                        [$personalMessage, $this->formatPhoneNumber($mitra['no_hp'])]
                    );
                } catch (\Exception $logErr) {
                    // Abaikan error log
                }
            } catch (\Exception $e) {
                error_log('[WA Mitra] Gagal kirim ke ' . $mitra['no_hp'] . ': ' . $e->getMessage());
            }
        }

        return $sent;
    }

    /**
     * Kirim WhatsApp ke semua user Keuangan yang terdaftar di tb_arsipuser (sil2020).
     * Pesan dikirim secara personal dengan menyapa nama masing-masing.
     *
     * @param string $messageTemplate Template pesan. Gunakan {nama_user} sebagai placeholder nama.
     * @return int Jumlah pesan yang berhasil terkirim.
     */
    public function sendWhatsAppToKeuangan($messageTemplate)
    {
        if (!$this->dbSil) {
            error_log('[WA Keuangan] dbSil tidak tersedia, skip pengiriman.');
            return 0;
        }

        try {
            $keuanganUsers = $this->dbSil->exec(
                "SELECT nama_user, no_hp FROM tb_arsipuser WHERE si_selport = 'keuangan' AND no_hp IS NOT NULL AND no_hp != ''"
            );
        } catch (\Exception $e) {
            error_log('[WA Keuangan] Gagal query tb_arsipuser: ' . $e->getMessage());
            return 0;
        }

        if (empty($keuanganUsers)) {
            error_log('[WA Keuangan] Tidak ada user keuangan dengan no_hp yang valid.');
            return 0;
        }

        $sent = 0;
        foreach ($keuanganUsers as $keuangan) {
            $namaUser = $keuangan['nama_user'] ?? 'Bagian Keuangan';
            $personalMessage = str_replace('{nama_user}', $namaUser, $messageTemplate);

            try {
                $result = $this->sendWhatsApp($keuangan['no_hp'], $personalMessage);
                if ($result['success']) {
                    $sent++;
                }

                // Log pengiriman ke tb_list_wa
                try {
                    $this->dbSil->exec(
                        "INSERT INTO tb_list_wa (isi_wa_server, no_hp_tujuan, tanggal_kirim) VALUES (?, ?, NOW())",
                        [$personalMessage, $this->formatPhoneNumber($keuangan['no_hp'])]
                    );
                } catch (\Exception $logErr) {
                    // Abaikan error log
                }
            } catch (\Exception $e) {
                error_log('[WA Keuangan] Gagal kirim ke ' . $keuangan['no_hp'] . ': ' . $e->getMessage());
            }
        }

        return $sent;
    }

    /**
     * Kirim WhatsApp ke user Sertifikasi (PIC) yang menangani customer terkait (sil2020).
     * Pesan dikirim secara personal dengan menyapa nama masing-masing.
     * Jika tidak ada PIC spesifik, sebagai fallback akan dikirim ke semua role sertifikasi.
     *
     * @param string $messageTemplate Template pesan. Gunakan {nama_user} sebagai placeholder nama.
     * @param int|null $idCustomer ID Customer di tb_customer (sil2020)
     * @return int Jumlah pesan yang berhasil terkirim.
     */
    public function sendWhatsAppToSertifikasi($messageTemplate, $idCustomer = null)
    {
        if (!$this->dbSil) {
            error_log('[WA Sertifikasi] dbSil tidak tersedia, skip pengiriman.');
            return 0;
        }

        try {
            $sertiUsers = [];

            // Jika idCustomer disertakan, cari PIC (id_user_sertifikasi_penerima_order) di tb_customer
            if ($idCustomer) {
                $custRow = $this->dbSil->exec("SELECT id_user_sertifikasi_penerima_order FROM tb_customer WHERE id_customer = ? LIMIT 1", [$idCustomer]);
                if (!empty($custRow) && $custRow[0]['id_user_sertifikasi_penerima_order']) {
                    $idUserPic = $custRow[0]['id_user_sertifikasi_penerima_order'];
                    $sertiUsers = $this->dbSil->exec(
                        "SELECT nama_user, no_hp FROM tb_arsipuser WHERE id_user = ? AND no_hp IS NOT NULL AND no_hp != '' LIMIT 1",
                        [$idUserPic]
                    );
                }
            }

            // Fallback: Jika tidak ditemukan PIC untuk perusahaan ini, kirim ke semua tim sertifikasi
            if (empty($sertiUsers)) {
                $sertiUsers = $this->dbSil->exec(
                    "SELECT nama_user, no_hp FROM tb_arsipuser WHERE si_selport = 'sertifikasi' AND no_hp IS NOT NULL AND no_hp != ''"
                );
            }
        } catch (\Exception $e) {
            error_log('[WA Sertifikasi] Gagal query tb_arsipuser: ' . $e->getMessage());
            return 0;
        }

        if (empty($sertiUsers)) {
            error_log('[WA Sertifikasi] Tidak ada user sertifikasi dengan no_hp yang valid.');
            return 0;
        }

        $sent = 0;
        foreach ($sertiUsers as $serti) {
            $namaUser = $serti['nama_user'] ?? 'Bagian Sertifikasi';
            $personalMessage = str_replace('{nama_user}', $namaUser, $messageTemplate);

            try {
                $result = $this->sendWhatsApp($serti['no_hp'], $personalMessage);
                if ($result['success']) {
                    $sent++;
                }

                // Log pengiriman ke tb_list_wa
                try {
                    $this->dbSil->exec(
                        "INSERT INTO tb_list_wa (isi_wa_server, no_hp_tujuan, tanggal_kirim) VALUES (?, ?, NOW())",
                        [$personalMessage, $this->formatPhoneNumber($serti['no_hp'])]
                    );
                } catch (\Exception $logErr) {
                    // Abaikan error log
                }
            } catch (\Exception $e) {
                error_log('[WA Sertifikasi] Gagal kirim ke ' . $serti['no_hp'] . ': ' . $e->getMessage());
            }
        }

        return $sent;
    }

    /**
     * Kirim WhatsApp ke semua user Kalibrasi yang terdaftar di tb_arsipuser (sil2020).
     * Pesan dikirim secara personal dengan menyapa nama masing-masing.
     *
     * @param string $messageTemplate Template pesan. Gunakan {nama_user} sebagai placeholder nama.
     * @return int Jumlah pesan yang berhasil terkirim.
     */
    public function sendWhatsAppToKalibrasi($messageTemplate)
    {
        if (!$this->dbSil) {
            error_log('[WA Kalibrasi] dbSil tidak tersedia, skip pengiriman.');
            return 0;
        }

        try {
            $kalibUsers = $this->dbSil->exec(
                "SELECT nama_user, no_hp FROM tb_arsipuser WHERE si_selport = 'kalibrasi' AND no_hp IS NOT NULL AND no_hp != ''"
            );
        } catch (\Exception $e) {
            error_log('[WA Kalibrasi] Gagal query tb_arsipuser: ' . $e->getMessage());
            return 0;
        }

        if (empty($kalibUsers)) {
            error_log('[WA Kalibrasi] Tidak ada user kalibrasi dengan no_hp yang valid.');
            return 0;
        }

        $sent = 0;
        foreach ($kalibUsers as $kalib) {
            $namaUser = $kalib['nama_user'] ?? 'Bagian Kalibrasi';
            $personalMessage = str_replace('{nama_user}', $namaUser, $messageTemplate);

            try {
                $result = $this->sendWhatsApp($kalib['no_hp'], $personalMessage);
                if ($result['success']) {
                    $sent++;
                }

                // Log pengiriman ke tb_list_wa
                try {
                    $this->dbSil->exec(
                        "INSERT INTO tb_list_wa (isi_wa_server, no_hp_tujuan, tanggal_kirim) VALUES (?, ?, NOW())",
                        [$personalMessage, $this->formatPhoneNumber($kalib['no_hp'])]
                    );
                } catch (\Exception $logErr) {
                    // Abaikan error log
                }
            } catch (\Exception $e) {
                error_log('[WA Kalibrasi] Gagal kirim ke ' . $kalib['no_hp'] . ': ' . $e->getMessage());
            }
        }

        return $sent;
    }
}
