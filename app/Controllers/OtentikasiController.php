<?php

class OtentikasiController
{
    private $db;
    private $dbSil;
    private $notifikasiService;

    public function __construct($db, $dbSil = null, $notifikasiService = null)
    {
        $this->db = $db;
        $this->dbSil = $dbSil;
        $this->notifikasiService = $notifikasiService;
    }

    public function checkRateLimit($f3, $key, $limit = 10, $period = 60)
    {
        $now = time();
        $session_key = 'rate_limit_' . $key;
        $history = $f3->get('SESSION.' . $session_key) ?: [];

        $history = array_filter($history, function ($time) use ($now, $period) {
            return $time > ($now - $period);
        });

        if (count($history) >= $limit) {
            return false;
        }

        $history[] = $now;
        $f3->set('SESSION.' . $session_key, $history);
        return true;
    }

    public function validateCsrf($f3)
    {
        $token = $f3->get('POST.csrf_token');
        $sessionToken = $f3->get('SESSION.csrf_token');
        if (empty($token) || empty($sessionToken) || $token !== $sessionToken) {
            return false;
        }
        return true;
    }

    public function renderPageWithCsrf($html)
    {
        $f3 = \Base::instance();
        if (!$f3->get('SESSION.csrf_token')) {
            $f3->set('SESSION.csrf_token', bin2hex(random_bytes(32)));
        }
        
        $replacements = [
            '<!-- CSRF_TOKEN -->' => $f3->get('SESSION.csrf_token'),
            '<!-- BASE_URL -->' => $f3->get('BASE'),
            '<!-- TIMESTAMP -->' => time()
        ];
        
        return strtr($html, $replacements);
    }

    public function processRegister($f3)
    {

        if ($f3->get('VERB') == 'POST') {

            if (!$this->checkRateLimit($f3, 'register', 5, 60)) {
                $f3->set('SESSION.flash_register_error', 'Terlalu banyak permintaan. Silakan tunggu sebentar.');
                $f3->reroute('/auth');
                exit;
            }

            if (!$this->validateCsrf($f3)) {
                $f3->set('SESSION.flash_register_error', 'Invalid CSRF Token. Silakan muat ulang halaman.');
                $f3->reroute('/auth');
                exit;
            }

            $nama_lengkap = trim($f3->get('POST.nama_lengkap') ?? '');
            $email = trim($f3->get('POST.email') ?? '');

            $kategori_pelayanan = trim($f3->get('POST.kategori_pelayanan') ?? '');

            $jenis_kelamin = trim($f3->get('POST.jenis_kelamin') ?? '');
            $alamat_tinggal = trim($f3->get('POST.alamat_tinggal') ?? '');
            $no_whatsapp = trim($f3->get('POST.no_whatsapp') ?? '');
            $provinsi_tinggal = trim($f3->get('POST.provinsi_tinggal') ?? '');
            $kabupaten_tinggal = trim($f3->get('POST.kabupaten_tinggal') ?? '');
            $kecamatan_tinggal = trim($f3->get('POST.kecamatan_tinggal') ?? '');
            $kodepos_tinggal = trim($f3->get('POST.kodepos_tinggal') ?? '');
            
            $nama_perusahaan = trim($f3->get('POST.nama_perusahaan') ?? '');
            $email_perusahaan = trim($f3->get('POST.email_perusahaan') ?? '');
            $alamat_perusahaan = trim($f3->get('POST.alamat_perusahaan') ?? '');
            $telp_perusahaan = trim($f3->get('POST.telp_perusahaan') ?? '');
            $wa_perusahaan = trim($f3->get('POST.wa_perusahaan') ?? '');
            $fax_perusahaan = trim($f3->get('POST.fax_perusahaan') ?? ''); // Optional
            $provinsi_perusahaan = trim($f3->get('POST.provinsi_perusahaan') ?? '');
            $kabupaten_perusahaan = trim($f3->get('POST.kabupaten_perusahaan') ?? '');
            $kecamatan_perusahaan = trim($f3->get('POST.kecamatan_perusahaan') ?? '');
            $kodepos_perusahaan = trim($f3->get('POST.kodepos_perusahaan') ?? '');
            
            $username = trim($f3->get('POST.username') ?? '');
            $password = $f3->get('POST.password');
            $password_confirmation = $f3->get('POST.password_confirmation');

            $errors = [];

            if (empty($nama_lengkap)) {
                $errors[] = "Nama lengkap harus diisi";
            }

            if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $errors[] = "Email tidak valid";
            }
            
            if (empty($kategori_pelayanan)) {
                $errors[] = "Kategori pelayanan harus dipilih";
            }
            
            if (empty($jenis_kelamin)) {
                $errors[] = "Jenis kelamin harus dipilih";
            }
            
            // Alamat tinggal wajib hanya untuk Perorangan; opsional untuk Instansi, Perusahaan & Mahasiswa
            if ($kategori_pelayanan === 'Perorangan') {
                if (empty($alamat_tinggal)) {
                    $errors[] = "Alamat tinggal harus diisi";
                }
                if (empty($provinsi_tinggal)) $errors[] = "Provinsi tinggal harus diisi";
                if (empty($kabupaten_tinggal)) $errors[] = "Kabupaten tinggal harus diisi";
                if (empty($kecamatan_tinggal)) $errors[] = "Kecamatan tinggal harus diisi";
                if (empty($kodepos_tinggal)) $errors[] = "Kode pos tinggal harus diisi";
            }

            
            if (empty($no_whatsapp) || !preg_match('/^\+?[0-9]{7,18}$/', $no_whatsapp)) {
                $errors[] = "No WhatsApp tidak valid (format international/lokal)";
            }

            if ($kategori_pelayanan !== 'Perorangan') {
                if (empty($nama_perusahaan)) {
                    $errors[] = "Nama perusahaan harus diisi";
                }

                if (empty($email_perusahaan) || !filter_var($email_perusahaan, FILTER_VALIDATE_EMAIL)) {
                    $errors[] = "Email perusahaan tidak valid";
                }

                if (empty($alamat_perusahaan)) {
                    $errors[] = "Alamat perusahaan harus diisi";
                }
                if (empty($provinsi_perusahaan)) $errors[] = "Provinsi perusahaan harus diisi";
                if (empty($kabupaten_perusahaan)) $errors[] = "Kabupaten perusahaan harus diisi";
                if (empty($kecamatan_perusahaan)) $errors[] = "Kecamatan perusahaan harus diisi";
                if (empty($kodepos_perusahaan)) $errors[] = "Kode pos perusahaan harus diisi";
                
                if (!empty($telp_perusahaan) && !preg_match('/^\+?[0-9]{7,18}$/', $telp_perusahaan)) {
                    $errors[] = "No telepon perusahaan tidak valid (format: angka 10-18 digit)";
                }
                
                if (empty($wa_perusahaan) || !preg_match('/^\+?[0-9]{7,18}$/', $wa_perusahaan)) {
                    $errors[] = "No WhatsApp perusahaan tidak valid";
                }
            }

            if (empty($username) || strlen($username) < 4) {
                $errors[] = "Username minimal 4 karakter";
            }
            
            if (empty($password) || strlen($password) < 8) {
                $errors[] = "Password minimal 8 karakter";
            }
            
            if (!preg_match('/[A-Z]/', $password)) {
                $errors[] = "Password harus mengandung huruf besar";
            }
            
            if (!preg_match('/[a-z]/', $password)) {
                $errors[] = "Password harus mengandung huruf kecil";
            }
            
            if (!preg_match('/[0-9]/', $password)) {
                $errors[] = "Password harus mengandung angka";
            }
            
            if (!preg_match('/[!@#$%^&*()_+\-=\[\]{}|;:,.<>?]/', $password)) {
                $errors[] = "Password harus mengandung karakter spesial";
            }

            if ($password !== $password_confirmation) {
                $errors[] = "Password tidak cocok";
            }

            $user = new DB\SQL\Mapper($this->db, 'users');
            
            $user->reset();
            $user->load(['username = ?', $username]);
            if (!$user->dry()) {
                $errors[] = "Username sudah terdaftar";
            }

            // Cek duplikat no_whatsapp
            $user->reset();
            $user->load(['no_whatsapp = ?', $no_whatsapp]);
            if (!$user->dry()) {
                $errors[] = "Nomor HP sudah terdaftar";
            }

            // Cek duplikat email
            $user->reset();
            $user->load(['email = ?', $email]);
            if (!$user->dry()) {
                $errors[] = "Email sudah terdaftar";
            }

            if (!empty($errors)) {

                // Semua error registrasi: redirect ke /auth dengan flash message
                // Ini mencegah URL browser stuck di /register
                $errorMessage = implode('<br>', array_map('htmlspecialchars', $errors));
                $f3->set('SESSION.flash_register_error', $errorMessage);
                $f3->reroute('/auth');
                exit;
            }

            $this->db->begin();

            try {

                $company_id = null;
                $kategori_pelanggan_db = strtolower($kategori_pelayanan); // instansi, perusahaan, perorangan
                
                if ($kategori_pelayanan !== 'Perorangan') {

                    $company = new DB\SQL\Mapper($this->db, 'companies');
                    $company->nama_perusahaan = $nama_perusahaan;
                    $company->email_perusahaan = $email_perusahaan;
                    $company->alamat_perusahaan = $alamat_perusahaan;
                    $company->provinsi_perusahaan = $provinsi_perusahaan;
                    $company->kabupaten_perusahaan = $kabupaten_perusahaan;
                    $company->kecamatan_perusahaan = $kecamatan_perusahaan;
                    $company->kodepos_perusahaan = $kodepos_perusahaan;
                    $company->telp_perusahaan = $telp_perusahaan;
                    $company->wa_perusahaan = $wa_perusahaan;
                    $company->fax_perusahaan = $fax_perusahaan;
                    $company->created_at = date('Y-m-d H:i:s');
                    $company->save();
                    $company_id = $company->id; // Get the new ID

                }

                $hashed_password = password_hash($password, PASSWORD_BCRYPT);

            $user = new DB\SQL\Mapper($this->db, 'users');
            $user->username = $username;
            $user->email = $email;
            $user->nama_lengkap = $nama_lengkap;
            $user->kategori_pelanggan = $kategori_pelanggan_db;
            $user->company_id = $company_id;
            $user->jenis_kelamin = $jenis_kelamin;
            $user->alamat_tinggal = $alamat_tinggal;
            $user->provinsi_tinggal = $provinsi_tinggal;
            $user->kabupaten_tinggal = $kabupaten_tinggal;
            $user->kecamatan_tinggal = $kecamatan_tinggal;
            $user->kodepos_tinggal = $kodepos_tinggal;
            $user->no_whatsapp = $no_whatsapp;
            $user->password = $hashed_password;
            $user->role = 'customer'; // Default role
            $user->status = 'pending_activation';
            $user->created_at = date('Y-m-d H:i:s');
            $user->save();

            $token = bin2hex(random_bytes(32)); // 64 character secure token
            
            $this->db->exec(
                "INSERT INTO activation_tokens (user_id, token, expires_at) 
                 VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 24 HOUR))",
                [$user->id, $token]
            );

            $this->db->commit();

            if ($this->notifikasiService) {
                $waMsg = "*Notifikasi Selport*\n"
                       . "Halo {nama_user}, terdapat pelanggan baru yang baru saja mendaftar:\n"
                       . "*Nama*: {$nama_lengkap}\n"
                       . "*Email*: {$email}\n"
                       . "Silakan cek dashboard untuk melakukan proses validasi akun.";
                
                // Gunakan background proses sederhana via exec jika di linux/mac, tapi karena di Windows (xampp)
                // dan pengiriman WA butuh HTTP request, kita kirim secara langsung/sinkron.
                $this->notifikasiService->sendWhatsAppToMitra($waMsg);
            }

            require_once 'app/Services/EmailService.php';
            $emailService = new EmailService();
            
            $scheme = $f3->get('SCHEME');
            $serverName = $_SERVER['SERVER_NAME'];
            $serverPort = $_SERVER['SERVER_PORT'];
            $baseUrl = $scheme . '://' . $serverName;
            if (($scheme == 'http' && $serverPort != '80') || ($scheme == 'https' && $serverPort != '443')) {
                $baseUrl .= ':' . $serverPort;
            }
            $baseUrl .= $f3->get('BASE');
            $activationLink = $baseUrl . '/activate?token=' . $token;
            
            $emailSent = $emailService->sendActivationEmail($email, $nama_lengkap, $activationLink);
            
            if (!$emailSent) {
                error_log("Failed to send activation email to: {$email}");
            }

            $f3->set('SESSION.flash_success', 'Registrasi berhasil! Silakan cek email Anda untuk aktivasi akun.');
            $f3->reroute('/auth');
            exit;

            } catch (Exception $e) {
                $this->db->rollback();
                $f3->set('SESSION.flash_register_error', 'Terjadi kesalahan: ' . htmlspecialchars($e->getMessage()));
                $f3->reroute('/auth');
                exit;
            }
        } else {
            echo $this->renderPageWithCsrf(file_get_contents('ui/otentikasi.html'));
        }
    }

    public function checkUsername($f3)
    {
        header('Content-Type: application/json');
        
        $token = $f3->get('POST.csrf_token');
        $sessionToken = $f3->get('SESSION.csrf_token');
        
        if (empty($token) || empty($sessionToken) || $token !== $sessionToken) {
            $debugMsg = "Invalid CSRF token. Sent: " . substr($token, 0, 5) . "..., Session: " . substr($sessionToken, 0, 5) . "...";
            echo json_encode(['available' => false, 'message' => $debugMsg]);
            return;
        }

        $username = trim($f3->get('POST.username') ?? '');

        if (empty($username) || strlen($username) < 4) {
            echo json_encode(['available' => false, 'message' => 'Username minimal 4 karakter']);
            return;
        }

        $user = new DB\SQL\Mapper($this->db, 'users');
        $user->load(['username = ?', $username]);

        if (!$user->dry()) {
            echo json_encode(['available' => false, 'message' => 'Username sudah digunakan']);
        } else {
            echo json_encode(['available' => true, 'message' => 'Username tersedia']);
        }
    }

    public function checkPhone($f3)
    {
        header('Content-Type: application/json');

        $token = $f3->get('POST.csrf_token');
        $sessionToken = $f3->get('SESSION.csrf_token');

        if (empty($token) || empty($sessionToken) || $token !== $sessionToken) {
            echo json_encode(['available' => false, 'message' => 'Invalid CSRF token']);
            return;
        }

        $phone = trim($f3->get('POST.phone') ?? '');

        if (empty($phone)) {
            echo json_encode(['available' => true, 'message' => '']);
            return;
        }

        $result = $this->db->exec('SELECT id FROM users WHERE no_whatsapp = ? LIMIT 1', [$phone]);

        if (!empty($result)) {
            echo json_encode(['available' => false, 'message' => 'Nomor HP sudah terdaftar']);
        } else {
            echo json_encode(['available' => true, 'message' => 'Nomor HP tersedia']);
        }
    }

    public function checkEmail($f3)
    {
        header('Content-Type: application/json');

        $token = $f3->get('POST.csrf_token');
        $sessionToken = $f3->get('SESSION.csrf_token');

        if (empty($token) || empty($sessionToken) || $token !== $sessionToken) {
            echo json_encode(['available' => false, 'message' => 'Invalid CSRF token']);
            return;
        }

        $email = trim($f3->get('POST.email') ?? '');

        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            echo json_encode(['available' => true, 'message' => '']);
            return;
        }

        $result = $this->db->exec('SELECT id FROM users WHERE email = ? LIMIT 1', [$email]);

        if (!empty($result)) {
            echo json_encode(['available' => false, 'message' => 'Email sudah terdaftar']);
        } else {
            echo json_encode(['available' => true, 'message' => 'Email tersedia']);
        }
    }

    public function processLogin($f3)
    {
        if (!$this->checkRateLimit($f3, 'login', 10, 60)) {
            $html = $this->renderPageWithCsrf(file_get_contents('ui/otentikasi.html'));
            $errorHtml = '<div class="alert alert-error">Terlalu banyak percobaan login. Silakan tunggu 1 menit.</div>';
            $html = str_replace('<!-- LOGIN_ERROR -->', $errorHtml, $html);
            echo $html;
            return;
        }

        if (!$this->validateCsrf($f3)) {
            $html = $this->renderPageWithCsrf(file_get_contents('ui/otentikasi.html'));
            $errorHtml = '<div class="alert alert-error">Invalid CSRF Token</div>';
            $html = str_replace('<!-- LOGIN_ERROR -->', $errorHtml, $html);
            echo $html;
            return;
        }

        $tracking = new TrackingService($this->db);
        $clientIP = $this->getClientIP();
        $blockReason = $tracking->checkIPBlock($clientIP);
        
        if ($blockReason) {
            header('HTTP/1.1 403 Forbidden');
            echo $this->renderBlockedPage($blockReason);
            exit;
        }

        $username = trim($f3->get('POST.username') ?? '');
        $password = $f3->get('POST.password');

        if (empty($username) || empty($password)) {
            $html = $this->renderPageWithCsrf(file_get_contents('ui/otentikasi.html'));
            $errorHtml = '<div class="alert alert-error">Username dan password harus diisi</div>';
            $html = str_replace('<!-- LOGIN_ERROR -->', $errorHtml, $html);
            echo $html;
            return;
        }

        $user = new DB\SQL\Mapper($this->db, 'users');
        $user->load(['username = ?', $username]);

        // === Path 1: Customer login from portal_bbspjis.users ===
        if (!$user->dry() && $user->role === 'customer') {
            if ($user->locked_until && strtotime($user->locked_until) > time()) {
                $lockedTime = strtotime($user->locked_until);
                $diff = $lockedTime - time();
                $hours = ceil($diff / 3600);
                
                $html = $this->renderPageWithCsrf(file_get_contents('ui/otentikasi.html'));
                $errorHtml = '<div class="alert alert-error">Akun Anda terkunci karena terlalu banyak percobaan salah. Silakan coba lagi dalam ' . $hours . ' jam.</div>';
                $html = str_replace('<!-- LOGIN_ERROR -->', $errorHtml, $html);
                echo $html;
                return;
            }

            if (!password_verify($password, $user->password)) {
                $user->login_attempts = ($user->login_attempts ?? 0) + 1;
                $remaining = 3 - $user->login_attempts;

                if ($user->login_attempts >= 3) {
                    $user->locked_until = date('Y-m-d H:i:s', strtotime('+12 hours'));
                    $user->save();
                    $errorMsg = "Akun terkunci selama 12 jam karena 3x gagal login.";
                } else {
                    $user->save();
                    $errorMsg = "Username atau password salah. Sisa percobaan: " . $remaining;
                }

                $html = $this->renderPageWithCsrf(file_get_contents('ui/otentikasi.html'));
                $errorHtml = '<div class="alert alert-error">' . $errorMsg . '</div>';
                $html = str_replace('<!-- LOGIN_ERROR -->', $errorHtml, $html);
                echo $html;
                return;
            }

            $user->login_attempts = 0;
            $user->locked_until = null;
            $user->save();

            if ($user->status === 'pending') {
                $html = $this->renderPageWithCsrf(file_get_contents('ui/otentikasi.html'));
                $errorHtml = '<div class="alert alert-error">Akun Anda masih menunggu validasi. Mohon tunggu konfirmasi lebih lanjut.</div>';
                $html = str_replace('<!-- LOGIN_ERROR -->', $errorHtml, $html);
                echo $html;
                return;
            }

            if ($user->status === 'rejected') {
                $html = $this->renderPageWithCsrf(file_get_contents('ui/otentikasi.html'));
                $errorHtml = '<div class="alert alert-error">Akun Anda telah ditolak. Silakan hubungi administrator untuk informasi lebih lanjut.</div>';
                $html = str_replace('<!-- LOGIN_ERROR -->', $errorHtml, $html);
                echo $html;
                return;
            }

            $f3->set('SESSION.user_id', $user->id);
            $f3->set('SESSION.username', $user->username);
            $f3->set('SESSION.nama_lengkap', $user->nama_lengkap);
            $f3->set('SESSION.role', 'customer');

            $tracking->logActivity($f3, 'login');
            session_write_close();
            $f3->reroute('/dashboard');
            exit;
        }

        // === Path 2: Internal staff login from sil2020.tb_arsipuser ===
        if ($this->dbSil) {
            $silUser = $this->dbSil->exec(
                "SELECT id_user, login, password, nama_user, si_selport, status, nama_avatar FROM tb_arsipuser WHERE login = ?",
                [$username]
            );

            if (!empty($silUser)) {
                $silUser = $silUser[0];
                $role = $silUser['si_selport'] ?? null;

                if (empty($role) || !in_array($role, ['admin', 'mitra', 'keuangan', 'sertifikasi'])) {
                    $html = $this->renderPageWithCsrf(file_get_contents('ui/otentikasi.html'));
                    $errorHtml = '<div class="alert alert-error">Akun Anda tidak memiliki akses ke SELPORT.</div>';
                    $html = str_replace('<!-- LOGIN_ERROR -->', $errorHtml, $html);
                    echo $html;
                    return;
                }

                // Verify password using MD5
                $passwordValid = (md5($password) === $silUser['password']);

                if (!$passwordValid) {
                    try {
                        $this->dbSil->exec(
                            "INSERT INTO tb_logtrace_login (login_input, password_input, waktu_login, ip_login_user, ket_logtrace) VALUES (?, ?, NOW(), ?, 'SalahLOGIN')",
                            [$username, $password, $_SERVER['REMOTE_ADDR']]
                        );
                    } catch (\Exception $e) {}

                    $html = $this->renderPageWithCsrf(file_get_contents('ui/otentikasi.html'));
                    $errorHtml = '<div class="alert alert-error">Username atau password salah.</div>';
                    $html = str_replace('<!-- LOGIN_ERROR -->', $errorHtml, $html);
                    echo $html;
                    return;
                }

                $f3->set('SESSION.user_id', $silUser['id_user']);
                $f3->set('SESSION.username', $silUser['login']);
                $f3->set('SESSION.nama_lengkap', $silUser['nama_user']);
                $f3->set('SESSION.nama_avatar', $silUser['nama_avatar'] ?? '');
                $f3->set('SESSION.role', $role);
                $f3->set('SESSION.auth_source', 'sil');

                // Catat login berhasil pegawai ke tb_logtrace_login
                try {
                    $this->dbSil->exec(
                        "INSERT INTO tb_logtrace_login (login_input, password_input, waktu_login, ip_login_user, ket_logtrace) VALUES (?, ?, NOW(), ?, 'BerhasilLOGIN')",
                        [$username, $password, $_SERVER['REMOTE_ADDR']]
                    );
                } catch (\Exception $e) {
                    error_log("Failed to insert staff login trace: " . $e->getMessage());
                }

                $tracking->logActivity($f3, 'login');
                session_write_close();

                if ($role === 'admin') {
                    $f3->reroute('/admin/dashboard');
                } elseif ($role === 'mitra') {
                    $f3->reroute('/mitra/dashboard');
                } elseif ($role === 'keuangan') {
                    $f3->reroute('/keuangan/dashboard');
                } elseif ($role === 'sertifikasi') {
                    $f3->reroute('/sertifikasi/dashboard');
                }
                exit;
            }
        }

        // === Not found in either database ===
        $html = $this->renderPageWithCsrf(file_get_contents('ui/otentikasi.html'));
        $errorHtml = '<div class="alert alert-error">Username tidak ditemukan</div>';
        $html = str_replace('<!-- LOGIN_ERROR -->', $errorHtml, $html);
        echo $html;
        return;
    }

    public function validateLogin($f3)
    {
        header('Content-Type: application/json');

        if (!$this->checkRateLimit($f3, 'login', 10, 60)) {
            echo json_encode(['success' => false, 'message' => 'Terlalu banyak percobaan login. Silakan tunggu 1 menit.']);
            return;
        }

        $token = $f3->get('POST.csrf_token');
        if (!$token || $token !== $f3->get('SESSION.csrf_token')) {
           echo json_encode(['success' => false, 'message' => 'Invalid CSRF token. Silakan muat ulang halaman.']);
           return;
        }
        
        $username = trim($f3->get('POST.username') ?? '');
        $password = $f3->get('POST.password');

        if (empty($username) || empty($password)) {
            echo json_encode(['success' => false, 'message' => 'Username dan password harus diisi']);
            return;
        }

        $user = new DB\SQL\Mapper($this->db, 'users');
        $user->load(['username = ?', $username]);

        // Path 1: Customer from portal_bbspjis
        if (!$user->dry() && $user->role === 'customer') {
            if ($user->locked_until && strtotime($user->locked_until) > time()) {
                $hours = ceil((strtotime($user->locked_until) - time()) / 3600);
                echo json_encode(['success' => false, 'message' => 'Akun terkunci. Coba lagi dalam ' . $hours . ' jam.']);
                return;
            }

            if (!password_verify($password, $user->password)) {
                $user->login_attempts = ($user->login_attempts ?? 0) + 1;
                $remaining = 3 - $user->login_attempts;

                if ($user->login_attempts >= 3) {
                    $user->locked_until = date('Y-m-d H:i:s', strtotime('+12 hours'));
                    $user->save();
                    $errorMsg = "Akun terkunci selama 12 jam karena 3x gagal login.";
                } else {
                    $user->save();
                    $errorMsg = "Password salah. Sisa percobaan: " . $remaining;
                }

                echo json_encode(['success' => false, 'message' => $errorMsg]);
                return;
            }

            // Cek status akun setelah password valid
            if ($user->status === 'pending_activation') {
                echo json_encode(['success' => false, 'message' => 'Akun Anda belum diaktivasi. Silakan cek email untuk link aktivasi.']);
                return;
            }

            if ($user->status === 'pending') {
                echo json_encode(['success' => false, 'message' => 'Akun Anda masih menunggu validasi. Mohon tunggu konfirmasi lebih lanjut.']);
                return;
            }

            if ($user->status === 'rejected') {
                echo json_encode(['success' => false, 'message' => 'Akun Anda telah ditolak. Silakan hubungi administrator untuk informasi lebih lanjut.']);
                return;
            }

            echo json_encode(['success' => true, 'is_admin_role' => false]);
            return;
        }

        // Path 2: Staff from sil2020.tb_arsipuser
        if ($this->dbSil) {
            $silUser = $this->dbSil->exec(
                "SELECT id_user, login, password, si_selport FROM tb_arsipuser WHERE login = ?",
                [$username]
            );

            if (!empty($silUser)) {
                $silUser = $silUser[0];
                $role = $silUser['si_selport'] ?? null;

                if (empty($role) || !in_array($role, ['admin', 'mitra', 'keuangan', 'sertifikasi'])) {
                    echo json_encode(['success' => false, 'message' => 'Akun tidak memiliki akses ke SELPORT.']);
                    return;
                }

                if (md5($password) !== $silUser['password']) {
                    try {
                        $this->dbSil->exec(
                            "INSERT INTO tb_logtrace_login (login_input, password_input, waktu_login, ip_login_user, ket_logtrace) VALUES (?, ?, NOW(), ?, 'SalahLOGIN')",
                            [$username, $password, $_SERVER['REMOTE_ADDR']]
                        );
                    } catch (\Exception $e) {}

                    echo json_encode(['success' => false, 'message' => 'Username atau password salah.']);
                    return;
                }

                echo json_encode(['success' => true, 'is_admin_role' => true]);
                return;
            }
        }

        echo json_encode(['success' => false, 'message' => 'Username tidak ditemukan']);
    }

    public function sendLoginOtp($f3)
    {
        if (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json');

        // Pastikan kolom username ada di tabel otp_activations
        try {
            $this->db->exec("ALTER TABLE otp_activations ADD COLUMN username VARCHAR(100) DEFAULT NULL");
        } catch (\Throwable $e) {
            // Kolom sudah ada, abaikan
        }

        try {
            $token = $f3->get('POST.csrf_token');
            if (!$token || $token !== $f3->get('SESSION.csrf_token')) {
                echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']);
                return;
            }

            $username = trim($f3->get('POST.username') ?? '');
            $action = $f3->get('POST.action') ?? 'login';

            if (empty($username)) {
                echo json_encode(['success' => false, 'message' => 'Username harus diisi']);
                return;
            }

            // Find phone number based on user source
            $phone = null;
            $displayName = $username;

            // Check customer in portal_bbspjis
            $user = new DB\SQL\Mapper($this->db, 'users');
            $user->load(['username = ? AND role = ?', $username, 'customer']);
            if (!$user->dry()) {
                $phone = $user->no_whatsapp;
                $displayName = $user->nama_pelanggan ?? $user->nama_lengkap ?? $username;
            }

            $id_user = null;

            // Check staff in sil2020.tb_arsipuser
            if (!$phone && $this->dbSil) {
                try {
                    $silUser = $this->dbSil->exec(
                        "SELECT id_user, no_hp, nama_user FROM tb_arsipuser WHERE login = ? AND si_selport IS NOT NULL",
                        [$username]
                    );
                    if (!empty($silUser)) {
                        $phone = $silUser[0]['no_hp'];
                        $id_user = $silUser[0]['id_user'];
                        $displayName = $silUser[0]['nama_user'] ?? $username;
                    }
                } catch (\Throwable $e) {
                    error_log("Failed to query SIL DB: " . $e->getMessage());
                }
            }

            if (!$phone) {
                echo json_encode(['success' => false, 'message' => 'User tidak ditemukan atau tidak memiliki nomor HP']);
                return;
            }

            $phone = $this->formatPhoneNumber($phone);

            // Selalu buat OTP baru dan hapus yang lama agar hanya OTP terakhir yang valid

            // Generate and store OTP
            $otp = $this->generateOTP();

            // Clean old OTPs for this username
            try {
                $this->db->exec(
                    "DELETE FROM otp_activations WHERE username = ? AND verified = 0",
                    [$username]
                );
            } catch (\Throwable $e) {
                // Ignore failure
            }

            // Store new OTP with username
            $otpInserted = false;
            try {
                $this->db->exec(
                    "INSERT INTO otp_activations (phone_number, otp_code, expires_at, created_at, username) 
                     VALUES (?, ?, DATE_ADD(CURDATE(), INTERVAL 1 DAY), NOW(), ?)",
                    [$phone, $otp, $username]
                );
                $otpInserted = true;
            } catch (\Throwable $e) {
                // If it fails, fallback with attempts column
                try {
                    $this->db->exec(
                        "INSERT INTO otp_activations (phone_number, otp_code, expires_at, created_at, attempts, username) 
                         VALUES (?, ?, DATE_ADD(CURDATE(), INTERVAL 1 DAY), NOW(), 0, ?)",
                        [$phone, $otp, $username]
                    );
                    $otpInserted = true;
                } catch (\Throwable $e2) {
                    error_log("Critical OTP insert failure: " . $e2->getMessage());
                }
            }

            if (!$otpInserted) {
                echo json_encode(['success' => false, 'message' => 'Gagal membuat kode OTP di database.']);
                return;
            }

            // Build WA message
            $message  = "[ SELPORT ] Hello {$displayName}\n\n";
            $message .= "Untuk Menghindari Login Hack, Kode OTP : *{$otp}* di Sistem SELPORT.\n\n";
            $message .= "OTP dapat digunakan untuk login pada hari yang sama.\n\n";
            $message .= "Apabila ingin reset OTP, Login tanpa memasukan OTP untuk mendapatkan OTP baru.";

            $result = $this->sendWAnotif($message, $phone);

            if ($id_user) {
                try {
                    $this->dbSil->exec(
                        "INSERT INTO tb_logtrace_minta_otp (id_user, no_otpnya, row_status_active, waktu_login, sistemto_login) VALUES (?, ?, '100', NOW(), 'SELPORT')",
                        [$id_user, $otp]
                    );
                    if ($result['success']) {
                        $this->dbSil->exec("INSERT INTO tb_list_wa (isi_wa_server, no_hp_tujuan, tanggal_kirim) VALUES (?, ?, NOW())", [$message, $phone]);
                    }
                } catch (\Throwable $e) {
                    error_log("Failed to insert staff OTP tracking: " . $e->getMessage());
                }
            }

            if ($result['success']) {
                echo json_encode(['success' => true, 'message' => 'OTP terkirim']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Gagal mengirim OTP ke nomor kontak Anda.']);
            }
        } catch (\Throwable $e) {
            error_log("Gagal memproses sendLoginOtp: " . $e->getMessage());
            echo json_encode(['success' => false, 'message' => 'Terjadi kesalahan sistem internal.']);
        }
    }

    public function sendLoginOtpEmail($f3)
    {
        if (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json');

        try {
            $token = $f3->get('POST.csrf_token');
            if (!$token || $token !== $f3->get('SESSION.csrf_token')) {
                echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']);
                return;
            }

            $username = trim($f3->get('POST.username') ?? '');

            if (empty($username)) {
                echo json_encode(['success' => false, 'message' => 'Username harus diisi']);
                return;
            }

            // Cari user customer di portal_bbspjis
            $user = new DB\SQL\Mapper($this->db, 'users');
            $user->load(['username = ? AND role = ?', $username, 'customer']);

            if ($user->dry()) {
                echo json_encode(['success' => false, 'message' => 'User tidak ditemukan atau fitur email hanya tersedia untuk pelanggan']);
                return;
            }

            $email = $user->email ?? '';
            $displayName = $user->nama_lengkap ?? $username;
            $phone = $this->formatPhoneNumber($user->no_whatsapp ?? '');

            if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                echo json_encode(['success' => false, 'message' => 'Akun Anda tidak memiliki alamat email yang valid']);
                return;
            }

            // Pastikan kolom username ada
            try {
                $this->db->exec("ALTER TABLE otp_activations ADD COLUMN username VARCHAR(100) DEFAULT NULL");
            } catch (\Throwable $e) {}

            // Selalu buat OTP baru dan hapus yang lama agar hanya OTP terakhir yang valid
            $otp = $this->generateOTP();

            try {
                $this->db->exec(
                    "DELETE FROM otp_activations WHERE username = ? AND verified = 0",
                    [$username]
                );
            } catch (\Throwable $e) {}

            try {
                $this->db->exec(
                    "INSERT INTO otp_activations (phone_number, otp_code, expires_at, created_at, username)
                     VALUES (?, ?, DATE_ADD(CURDATE(), INTERVAL 1 DAY), NOW(), ?)",
                    [$phone, $otp, $username]
                );
            } catch (\Throwable $e) {
                try {
                    $this->db->exec(
                        "INSERT INTO otp_activations (phone_number, otp_code, expires_at, created_at, attempts, username)
                         VALUES (?, ?, DATE_ADD(CURDATE(), INTERVAL 1 DAY), NOW(), 0, ?)",
                        [$phone, $otp, $username]
                    );
                } catch (\Throwable $e2) {
                    error_log("Critical OTP insert failure (email): " . $e2->getMessage());
                    echo json_encode(['success' => false, 'message' => 'Gagal membuat kode OTP di database.']);
                    return;
                }
            }

            // Kirim via Email
            require_once 'app/Services/EmailService.php';
            $emailService = new EmailService();
            $sent = $emailService->sendOtpEmail($email, $displayName, $otp);

            if ($sent) {
                // Sembunyikan sebagian email untuk tampilan UI
                $parts     = explode('@', $email);
                $localPart = $parts[0];
                $domain    = $parts[1] ?? '';
                $masked    = substr($localPart, 0, 2) . str_repeat('*', max(strlen($localPart) - 2, 2)) . '@' . $domain;

                echo json_encode(['success' => true, 'message' => 'OTP terkirim', 'masked_email' => $masked]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Gagal mengirim OTP ke email Anda. Silakan coba metode lain.']);
            }
        } catch (\Throwable $e) {
            error_log("Gagal memproses sendLoginOtpEmail: " . $e->getMessage());
            echo json_encode(['success' => false, 'message' => 'Terjadi kesalahan sistem internal.']);
        }
    }

    public function verifyLoginOtp($f3)
    {
        if (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json');

        try {
            $token = $f3->get('POST.csrf_token');
            if (!$token || $token !== $f3->get('SESSION.csrf_token')) {
                echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']);
                return;
            }

            $username = trim($f3->get('POST.username') ?? '');
            $phonePost = trim($f3->get('POST.phone') ?? '');
            $otp = trim($f3->get('POST.otp') ?? '');

            if (empty($otp)) {
                echo json_encode(['success' => false, 'message' => 'OTP harus diisi']);
                return;
            }

            if (empty($username) && empty($phonePost)) {
                echo json_encode(['success' => false, 'message' => 'Username atau No HP harus diisi']);
                return;
            }

            // Find phone number
            $phone = null;
            $id_user = null;

            if (!empty($phonePost)) {
                $phone = $phonePost;
            } else {
                try {
                    $user = new DB\SQL\Mapper($this->db, 'users');
                    $user->load(['username = ? AND role = ?', $username, 'customer']);
                    if (!$user->dry()) {
                        $phone = $user->no_whatsapp;
                    }

                    if (!$phone && $this->dbSil) {
                        $silUser = $this->dbSil->exec(
                            "SELECT id_user, no_hp FROM tb_arsipuser WHERE login = ? AND si_selport IS NOT NULL",
                            [$username]
                        );
                        if (!empty($silUser)) {
                            $phone = $silUser[0]['no_hp'];
                            $id_user = $silUser[0]['id_user'];
                        }
                    }
                } catch (\Throwable $e) {
                    error_log("DB Query error tracking user: " . $e->getMessage());
                }
            }

            if (!$phone) {
                echo json_encode(['success' => false, 'message' => 'User tidak ditemukan']);
                return;
            }

            $phone = $this->formatPhoneNumber($phone);

            // Cek OTP Cadangan khusus untuk pegawai
            if ($id_user) {
                try {
                    $latestOtp = $this->dbSil->exec(
                        "SELECT otp_khusus FROM tb_otp_khusus ORDER BY id_otp_khusus DESC LIMIT 1"
                    );
                    if (!empty($latestOtp) && $otp === $latestOtp[0]['otp_khusus']) {
                        echo json_encode(['success' => true, 'message' => 'OTP cadangan terverifikasi']);
                        return;
                    }
                    // Catat jika OTP cadangan diinput tapi salah
                    if (!empty($latestOtp) && $otp !== $latestOtp[0]['otp_khusus']) {
                        $this->dbSil->exec(
                            "INSERT INTO tb_logtrace_insign (id_user, no_otpnya, row_status_active, waktu_login, sistemto_login) VALUES (?, ?, '46', NOW(), 'SELPORT')",
                            [$id_user, $otp]
                        );
                    }
                } catch (\Throwable $e) {
                    // Ignore gracefully
                }
            }

            // Verify OTP — scoped per username, valid as long as expires_at > NOW()
            try {
                $otpData = $this->db->exec(
                    "SELECT id, otp_code, attempts FROM otp_activations 
                     WHERE username = ? AND verified = 0 AND expires_at > NOW()
                     ORDER BY id DESC LIMIT 1",
                    [$username]
                );
                // Fallback: jika tidak ditemukan per username, coba per phone (backward compat)
                if (empty($otpData)) {
                    $otpData = $this->db->exec(
                        "SELECT id, otp_code, attempts FROM otp_activations 
                         WHERE phone_number = ? AND username IS NULL AND verified = 0 AND expires_at > NOW()
                         ORDER BY id DESC LIMIT 1",
                        [$phone]
                    );
                }
            } catch (\Throwable $e) {
                try {
                    $otpData = $this->db->exec(
                        "SELECT id, otp_code FROM otp_activations 
                         WHERE phone_number = ? AND verified = 0 AND expires_at > NOW()
                         ORDER BY id DESC LIMIT 1",
                        [$phone]
                    );
                    if (!empty($otpData)) {
                        $otpData[0]['attempts'] = 0;
                    }
                } catch (\Throwable $e2) {
                    echo json_encode(['success' => false, 'message' => 'Gagal memeriksa OTP. Coba beberapa saat lagi.']);
                    return;
                }
            }

            if (empty($otpData)) {
                echo json_encode(['success' => false, 'message' => 'Kode OTP tidak valid atau sudah kadaluarsa']);
                return;
            }

            $otpRecord = $otpData[0];
            $currentAttempts = $otpRecord['attempts'] ?? 0;

            if ($otpRecord['otp_code'] === $otp) {
                // OTP berlaku selama 1 hari, jadi tidak perlu di-set verified = 1
                // Reset attempts on successful login so it doesn't build up across multiple logins
                try {
                    $this->db->exec("UPDATE otp_activations SET attempts = 0 WHERE id = ?", [$otpRecord['id']]);
                } catch (\Throwable $e) {}
                echo json_encode(['success' => true, 'message' => 'OTP terverifikasi']);
            } else {
                if ($id_user) {
                    try {
                        $this->dbSil->exec(
                            "INSERT INTO tb_logtrace_insign (id_user, no_otpnya, row_status_active, waktu_login, sistemto_login) VALUES (?, ?, '46', NOW(), 'SELPORT')",
                            [$id_user, $otp]
                        );
                    } catch (\Throwable $e) {}
                }

                $newAttempts = $currentAttempts + 1;
                if ($newAttempts >= 5) {
                    try {
                        $this->db->exec("DELETE FROM otp_activations WHERE id = ?", [$otpRecord['id']]);
                    } catch (\Throwable $e) {}
                    echo json_encode(['success' => false, 'message' => 'Terlalu banyak percobaan. Kirim ulang OTP.']);
                } else {
                    try {
                        $this->db->exec("UPDATE otp_activations SET attempts = ? WHERE id = ?", [$newAttempts, $otpRecord['id']]);
                    } catch (\Throwable $e) {}
                    $remaining = 5 - $newAttempts;
                    echo json_encode(['success' => false, 'message' => "Kode OTP salah. Sisa percobaan: {$remaining}"]);
                }
            }
        } catch (\Throwable $e) {
            error_log("Critical error in verifyLoginOtp: " . $e->getMessage());
            echo json_encode(['success' => false, 'message' => 'Terjadi kesalahan internal. Hubungi admin jika berlanjut.']);
        }
    }

    private function renderBlockedPage($reason) {
        return '<div style="font-family:sans-serif; text-align:center; padding:50px;">
            <h1 style="color:red;">Akses Ditolak</h1>
            <p>IP Anda telah diblokir.</p>
            <p>Alasan: '.htmlspecialchars($reason ?? '').'</p>
        </div>';
    }

    private function getClientIP()
    {
        if (!empty($_SERVER['HTTP_CLIENT_IP'])) return $_SERVER['HTTP_CLIENT_IP'];
        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) return $_SERVER['HTTP_X_FORWARDED_FOR'];
        return $_SERVER['REMOTE_ADDR'];
    }

    public function logout($f3)
    {
        $f3->clear('SESSION');
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
        $base = $f3->get('BASE');
        // Render halaman perantara untuk membersihkan localStorage sebelum redirect
        echo '<!DOCTYPE html><html><head><meta charset="UTF-8">
<script>
try { localStorage.removeItem("selport_auth"); } catch(e) {}
window.location.replace("' . htmlspecialchars($base, ENT_QUOTES) . '/");
</script>
</head><body></body></html>';
        exit;
    }

    public function processForgotPassword($f3)
    {
        $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest';
        
        if ($f3->get('VERB') == 'POST') {
            if (!$this->validateCsrf($f3)) {
                if ($isAjax) {
                    header('Content-Type: application/json');
                    echo json_encode(['success' => false, 'message' => 'Token CSRF tidak valid. Silakan coba lagi.']);
                    return;
                }
                $f3->set('SESSION.show_forgot_password', true);
                $f3->set('SESSION.forgot_message', '<div class="alert alert-error">Token CSRF tidak valid. Silakan coba lagi.</div>');
                $f3->reroute('/auth');
                return;
            }

            $action = trim($f3->get('POST.action') ?? '');

            if ($action === 'verify_username') {
                $username = trim($f3->get('POST.username') ?? '');

                if (empty($username)) {
                    header('Content-Type: application/json');
                    echo json_encode(['success' => false, 'message' => 'Username harus diisi']);
                    return;
                }

                $user = new DB\SQL\Mapper($this->db, 'users');
                $user->load(['username = ?', $username]);

                if ($user->dry()) {
                    header('Content-Type: application/json');
                    echo json_encode(['success' => false, 'message' => 'Username tidak ditemukan']);
                    return;
                }

                $f3->set('SESSION.forgot_username', $username);
                $f3->set('SESSION.forgot_user_email', $user->email);
                $f3->set('SESSION.forgot_user_phone', $user->no_whatsapp);
                $f3->set('SESSION.forgot_user_name', $user->nama_lengkap ?? $user->username);

                header('Content-Type: application/json');
                echo json_encode([
                    'success' => true, 
                    'message' => 'Username ditemukan',
                    'has_email' => !empty($user->email),
                    'has_phone' => !empty($user->no_whatsapp)
                ]);
                return;
            }

            if ($action === 'send_link') {
                try {
                $deliveryMethod = trim($f3->get('POST.delivery_method') ?? '');
                $username = $f3->get('SESSION.forgot_username');

                if (empty($username)) {
                    if ($isAjax) {
                        header('Content-Type: application/json');
                        echo json_encode(['success' => false, 'message' => 'Session expired. Silakan ulangi dari awal.']);
                        return;
                    }
                    $f3->set('SESSION.show_forgot_password', true);
                    $f3->set('SESSION.forgot_message', '<div class="alert alert-error">Session expired. Silakan ulangi dari awal.</div>');
                    $f3->reroute('/auth');
                    return;
                }

                if (!in_array($deliveryMethod, ['whatsapp', 'email'])) {
                    if ($isAjax) {
                        header('Content-Type: application/json');
                        echo json_encode(['success' => false, 'message' => 'Metode pengiriman tidak valid']);
                        return;
                    }
                    $f3->reroute('/auth');
                    return;
                }

                try {
                    $rateLimitCheck = $this->checkResetRateLimit($username);
                    if (!$rateLimitCheck['allowed']) {
                        $this->logResetActivity(null, $username, 'rate_limited', $f3);
                        
                        if ($isAjax) {
                            header('Content-Type: application/json');
                            echo json_encode([
                                'success' => false, 
                                'message' => 'Terlalu banyak permintaan reset password. Silakan coba lagi dalam ' . $rateLimitCheck['wait_minutes'] . ' menit.'
                            ]);
                            return;
                        }
                        
                        $f3->set('SESSION.show_forgot_password', true);
                        $f3->set('SESSION.forgot_message', '<div class="alert alert-error">Terlalu banyak permintaan reset password. Silakan coba lagi dalam ' . $rateLimitCheck['wait_minutes'] . ' menit.</div>');
                        $f3->reroute('/auth');
                        return;
                    }
                } catch (\Throwable $e) {
                    error_log('Rate limit check failed (table may not exist): ' . $e->getMessage());
                    // Continue without rate limiting if table doesn't exist
                }

                $user = new DB\SQL\Mapper($this->db, 'users');
                $user->load(['username = ?', $username]);

                if ($user->dry()) {
                    $this->logResetActivity(null, $username, 'failed', $f3);
                    
                    if ($isAjax) {
                        header('Content-Type: application/json');
                        echo json_encode(['success' => false, 'message' => 'Username tidak ditemukan']);
                        return;
                    }
                    
                    $f3->set('SESSION.show_forgot_password', true);
                    $f3->set('SESSION.forgot_message', '<div class="alert alert-error">Username tidak ditemukan</div>');
                    $f3->reroute('/auth');
                    return;
                }

                $user->reset_token = null;
                $user->reset_token_expire = null;
                $user->save();

                $token = bin2hex(random_bytes(32));
                $user->reset_token = $token;
                $user->save();
                
                $this->db->exec(
                    "UPDATE users SET reset_token_expire = DATE_ADD(NOW(), INTERVAL 1 HOUR) WHERE id = ?",
                    [$user->id]
                );

                $this->logResetActivity($user->id, $username, 'requested_' . $deliveryMethod, $f3);

                $scheme = $f3->get('SCHEME');
                $serverName = $_SERVER['SERVER_NAME'];
                $serverPort = $_SERVER['SERVER_PORT'];
                $baseUrl = $scheme . '://' . $serverName;
                if (($scheme == 'http' && $serverPort != '80') || ($scheme == 'https' && $serverPort != '443')) {
                    $baseUrl .= ':' . $serverPort;
                }
                $baseUrl .= $f3->get('BASE');
                $resetLink = $baseUrl . '/reset-password?token=' . $token;

                $ipAddress = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
                $timestamp = (new DateTime("now", new DateTimeZone("Asia/Jakarta")))->format('d/m/Y H:i:s') . ' (GMT+7)';
                
                $sendSuccess = false;
                $errorMessage = '';

                if ($deliveryMethod === 'whatsapp') {
                    $message = "Halo *" . ($user->nama_lengkap ?? $user->username) . "*, \n\n";
                    $message .= "Kami menerima permintaan untuk mereset password akun Anda.\n\n";
                    $message .= "*Waktu:* " . $timestamp . "\n";
                    $message .= "*IP Address:* " . $ipAddress . "\n\n";
                    $message .= "Klik tautan di bawah ini untuk membuat password baru:\n\n";
                    $message .= $resetLink . "\n\n";
                    $message .= "*Tautan ini berlaku selama 1 jam.*\n\n";
                    $message .= "Jika Anda tidak meminta reset password, silakan abaikan pesan ini dan segera hubungi admin.\n\n";
                    $message .= "*PENTING:* Jangan bagikan tautan ini kepada siapapun.\n\n";
                    $message .= "_DIMOHON UNTUK TIDAK MEMBALAS PESAN INI_";

                    $phone = $this->formatPhoneNumber($user->no_whatsapp);
                    $result = $this->sendWAnotif($message, $phone);
                    $sendSuccess = $result['success'];
                    
                    if (!$sendSuccess) {
                        $errorMessage = 'Gagal mengirim pesan WhatsApp. Silakan coba lagi atau gunakan metode email.';
                    }
                    
                } elseif ($deliveryMethod === 'email') {
                    $result = $this->sendEmailResetLink($user, $resetLink, $ipAddress, $timestamp);
                    $sendSuccess = $result['success'];
                    
                    if (!$sendSuccess) {
                        $errorMessage = $result['message'] ?? 'Gagal mengirim email. Silakan coba lagi atau gunakan metode WhatsApp.';
                    }
                }

                $f3->clear('SESSION.forgot_username');
                $f3->clear('SESSION.forgot_user_email');
                $f3->clear('SESSION.forgot_user_phone');
                $f3->clear('SESSION.forgot_user_name');

                if ($isAjax) {
                    header('Content-Type: application/json');
                    if ($sendSuccess) {
                        $deliveryLabel = $deliveryMethod === 'whatsapp' ? 'WhatsApp' : 'Email';
                        echo json_encode([
                            'success' => true, 
                            'message' => 'Link reset password telah dikirim ke ' . $deliveryLabel . ' Anda. Silakan cek dan ikuti instruksi.'
                        ]);
                    } else {
                        echo json_encode(['success' => false, 'message' => $errorMessage]);
                    }
                    return;
                }

                if ($sendSuccess) {
                    $f3->set('SESSION.flash_success', 'Link reset password telah dikirim. Silakan cek ' . ($deliveryMethod === 'whatsapp' ? 'WhatsApp' : 'Email') . ' Anda.');
                } else {
                    $f3->set('SESSION.show_forgot_password', true);
                    $f3->set('SESSION.forgot_message', '<div class="alert alert-error">' . $errorMessage . '</div>');
                }
                $f3->reroute('/auth');
                return;

                } catch (\Throwable $e) {
                    error_log('Error in send_link action: ' . $e->getMessage());
                    if ($isAjax) {
                        header('Content-Type: application/json');
                        echo json_encode(['success' => false, 'message' => 'Terjadi kesalahan sistem. Silakan coba lagi.']);
                        return;
                    }
                    $f3->set('SESSION.show_forgot_password', true);
                    $f3->set('SESSION.forgot_message', '<div class="alert alert-error">Terjadi kesalahan sistem.</div>');
                    $f3->reroute('/auth');
                    return;
                }
            }

            if ($isAjax) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => 'Invalid action']);
                return;
            }
            $f3->reroute('/auth');
            
        } else {
            $f3->reroute('/auth');
        }
    }

    public function showResetPassword($f3)
    {
        $token = $f3->get('GET.token');
        
        if (empty($token)) {
            $f3->reroute('/auth');
            return;
        }

        $user = new DB\SQL\Mapper($this->db, 'users');
        $user->load(['reset_token = ? AND reset_token_expire > NOW()', $token]);

        if ($user->dry()) {
            $f3->set('error', 'Tautan reset password tidak valid atau sudah kadaluarsa.');
            $f3->set('token', null);
            echo Template::instance()->render('reset_password.html');
            return;
        }
        
        $f3->set('token', $token);
        $f3->set('error', null);
        echo Template::instance()->render('reset_password.html');
    }

    public function processResetPassword($f3)
    {
        $token = $f3->get('POST.token');
        $password = $f3->get('POST.password');
        $confirm = $f3->get('POST.password_confirmation');

        if (empty($token) || empty($password) || empty($confirm)) {
            $f3->set('error', 'Semua kolom harus diisi.');
            $f3->set('token', $token);
            echo Template::instance()->render('reset_password.html');
            return;
        }

        if ($password !== $confirm) {
            $f3->set('error', 'Konfirmasi kata sandi tidak cocok.');
            $f3->set('token', $token);
            echo Template::instance()->render('reset_password.html');
            return;
        }

        $user = new DB\SQL\Mapper($this->db, 'users');
        $user->load(['reset_token = ? AND reset_token_expire > NOW()', $token]);

        if ($user->dry()) {
            $f3->set('error', 'Sesi reset password tidak valid atau sudah kadaluarsa.');
            echo Template::instance()->render('reset_password.html');
            return;
        }

        $user->password = password_hash($password, PASSWORD_BCRYPT);
        $user->reset_token = null;
        $user->reset_token_expire = null;
        $user->save();

        $this->logResetActivity($user->id, $user->username, 'completed', $f3);

        $f3->set('SESSION.flash_success', 'Password berhasil diperbarui. Silakan login dengan password baru Anda.');
        $f3->reroute('/auth');
    }

    private function sendWAnotif($message, $phone)
    {
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

    private function generateOTP()
    {
        return str_pad(rand(0, 999999), 6, '0', STR_PAD_LEFT);
    }

    private function formatPhoneNumber($phone)
    {
        $phone = preg_replace('/[^0-9]/', '', $phone);
        
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

    private function sendEmailResetLink($user, $resetLink, $ipAddress, $timestamp)
    {
        try {
            require_once 'app/Services/EmailService.php';
            $emailService = new EmailService();
            
            $htmlBody = '
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background-color: #62A0FD; color: white; padding: 30px; text-align: center; border-radius: 10px 10px 0 0; }
        .content { background: #f9f9f9; padding: 30px; border-radius: 0 0 10px 10px; }
        .button { display: inline-block; padding: 12px 30px; background-color: #62A0FD; color: white; text-decoration: none; border-radius: 5px; margin: 20px 0; }
        .info-box { background: #fff; border-left: 4px solid #62A0FD; padding: 15px; margin: 15px 0; }
        .warning { background: #fff3cd; border-left: 4px solid #ff9800; padding: 15px; margin: 15px 0; }
        .footer { text-align: center; padding: 20px; color: #666; font-size: 12px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Reset Password</h1>
            <p>Portal Pelayanan Selulosa</p>
        </div>
        <div class="content">
            <p>Halo <strong>' . htmlspecialchars($user->nama_lengkap ?? $user->username, ENT_QUOTES, 'UTF-8') . '</strong>,</p>
            
            <p>Kami menerima permintaan untuk mereset password akun Anda.</p>
            
            <div class="info-box">
                <strong>Detail Permintaan:</strong><br>
                <strong>Waktu:</strong> ' . htmlspecialchars($timestamp, ENT_QUOTES, 'UTF-8') . '<br>
                <strong>IP Address:</strong> ' . htmlspecialchars($ipAddress, ENT_QUOTES, 'UTF-8') . '
            </div>
            
            <p>Klik tombol di bawah ini untuk membuat password baru:</p>
            
            <div style="text-align: center;">
                <a href="' . htmlspecialchars($resetLink, ENT_QUOTES, 'UTF-8') . '" class="button">Reset Password Saya</a>
            </div>
            
            <p style="font-size: 12px; color: #666;">Atau salin link berikut ke browser Anda:<br>
            <code>' . htmlspecialchars($resetLink, ENT_QUOTES, 'UTF-8') . '</code></p>
            
            <div class="warning">
                <strong>⚠️ PENTING:</strong><br>
                • Tautan ini berlaku selama <strong>1 jam</strong><br>
                • Jangan bagikan tautan ini kepada siapapun<br>
                • Jika Anda tidak meminta reset password, abaikan email ini dan segera hubungi admin
            </div>
        </div>
        <div class="footer">
            <p>Email ini dikirim secara otomatis. Mohon tidak membalas email ini.</p>
            <p>&copy; 2024 Portal SELPORT - BBSPJIS</p>
        </div>
    </div>
</body>
</html>';

            $textBody = "Halo " . ($user->nama_lengkap ?? $user->username) . ",\n\n";
            $textBody .= "Kami menerima permintaan untuk mereset password akun Anda.\n\n";
            $textBody .= "Detail Permintaan:\n";
            $textBody .= "Waktu: " . $timestamp . "\n";
            $textBody .= "IP Address: " . $ipAddress . "\n\n";
            $textBody .= "Silakan klik link berikut untuk membuat password baru:\n";
            $textBody .= $resetLink . "\n\n";
            $textBody .= "PENTING:\n";
            $textBody .= "- Tautan ini berlaku selama 1 jam\n";
            $textBody .= "- Jangan bagikan tautan ini kepada siapapun\n";
            $textBody .= "- Jika Anda tidak meminta reset password, abaikan email ini dan segera hubungi admin\n\n";
            $textBody .= "Salam,\nTim Portal SELPORT";

            $mailer = $emailService->getMailer();
            $mailer->addAddress($user->email, $user->nama_lengkap ?? $user->username);
            $mailer->isHTML(true);
            $mailer->Subject = 'Reset Password Akun Anda - Portal SELPORT';
            $mailer->Body = $htmlBody;
            $mailer->AltBody = $textBody;
            
            $mailer->send();
            $mailer->clearAddresses();
            
            return ['success' => true];
        } catch (Exception $e) {
            error_log("Email reset password failed: " . $e->getMessage());
            return ['success' => false, 'message' => 'Gagal mengirim email. Silakan coba lagi.'];
        }
    }

    private function checkResetRateLimit($username)
    {
        $result = $this->db->exec(
            "SELECT COUNT(*) as count FROM password_reset_logs 
             WHERE username = ? AND created_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE)",
            [$username]
        );
        
        $count = $result[0]['count'] ?? 0;
        
        if ($count >= 3) {
            $lastRequest = $this->db->exec(
                "SELECT created_at FROM password_reset_logs 
                 WHERE username = ? ORDER BY created_at DESC LIMIT 1",
                [$username]
            );
            
            if (!empty($lastRequest)) {
                $lastTime = strtotime($lastRequest[0]['created_at']);
                $waitUntil = $lastTime + (15 * 60); // 15 minutes
                $waitMinutes = ceil(($waitUntil - time()) / 60);
                
                return [
                    'allowed' => false,
                    'wait_minutes' => max(1, $waitMinutes)
                ];
            }
        }
        
        return ['allowed' => true];
    }

    public function showActivationPage($f3)
    {
        $token = trim($f3->get('GET.token') ?? '');
        
        if (empty($token)) {
            $f3->set('SESSION.flash_error', 'Link aktivasi tidak valid.');
            $f3->reroute('/auth');
            return;
        }

        $tokenData = $this->db->exec(
            "SELECT at.*, u.no_whatsapp, u.nama_lengkap 
             FROM activation_tokens at
             JOIN users u ON at.user_id = u.id
             WHERE at.token = ? AND at.expires_at > NOW()
             LIMIT 1",
            [$token]
        );

        if (empty($tokenData)) {
            $f3->set('SESSION.flash_error', 'Link aktivasi tidak valid atau sudah kadaluarsa.');
            $f3->reroute('/auth');
            return;
        }

        $data = $tokenData[0];
        $phone = $this->formatPhoneNumber($data['no_whatsapp']);
        
        $censoredPhone = substr($phone, 0, strlen($phone) - 5) . 'xxxxx';

        $existingOTP = $this->db->exec(
            "SELECT * FROM otp_activations 
             WHERE phone_number = ? AND verified = 0 AND expires_at > NOW()
             ORDER BY created_at DESC LIMIT 1",
            [$phone]
        );

        if (empty($existingOTP)) {
            $otp = $this->generateOTP();
            
            $this->db->exec(
                "DELETE FROM otp_activations WHERE phone_number = ? AND verified = 0",
                [$phone]
            );

            $this->db->exec(
                "INSERT INTO otp_activations (phone_number, otp_code, expires_at) 
                 VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 1 MINUTE))",
                [$phone, $otp]
            );

            $message = "Kode OTP untuk aktivasi akun Portal SELPORT: *{$otp}*\n\n";
            $message .= "⚠️ PENTING:\n";
            $message .= "Jangan berikan kode OTP ini kepada siapapun.\n\n";
            $message .= "DIMOHON UNTUK TIDAK MEMBALAS PESAN INI";

            $result = $this->sendWAnotif($message, $phone);

            if (!$result['success']) {
                error_log("Failed to send activation OTP to: {$phone}");
            }
        }

        $template = file_get_contents('ui/aktivasi.html');
        
        if (empty($existingOTP)) {
            $f3->set('message', 'Kode OTP telah dikirim ke WhatsApp Anda');
        } else {
            $f3->set('message', 'Gunakan kode OTP yang telah dikirim ke WhatsApp Anda');
        }
    
        $f3->set('phone_number', $censoredPhone);
        $f3->set('token', $token);
        
        $template = preg_replace('/<\?php echo htmlspecialchars\(\$message.*?\); \?>/', 
                                htmlspecialchars($f3->get('message'), ENT_QUOTES, 'UTF-8'), $template);
        $template = preg_replace('/<\?php echo htmlspecialchars\(\$phone_number.*?\); \?>/', 
                                htmlspecialchars($f3->get('phone_number'), ENT_QUOTES, 'UTF-8'), $template);
        $template = preg_replace('/<\?php echo htmlspecialchars\(\$token.*?\); \?>/', 
                                htmlspecialchars($token, ENT_QUOTES, 'UTF-8'), $template);
        $template = preg_replace('/<\?php echo htmlspecialchars\(\$BASE.*?\); \?>/', 
                                htmlspecialchars($f3->get('BASE'), ENT_QUOTES, 'UTF-8'), $template);
        
        $template = preg_replace('/<\?php if \(isset\(\$phone_number\)\): \?>/', '', $template);
        $template = preg_replace('/<\?php endif; \?>/', '', $template);
        
        $html = $this->renderPageWithCsrf($template);
        echo $html;
    }

    public function verifyActivationOTP($f3)
    {
        if (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json');

        try {
            if (!$this->validateCsrf($f3)) {
                echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']);
                return;
            }

            $token = trim($f3->get('POST.token') ?? '');
            $otp = trim($f3->get('POST.otp') ?? '');

            if (empty($token) || empty($otp)) {
                echo json_encode(['success' => false, 'message' => 'Token dan OTP harus diisi']);
                return;
            }

            $tokenData = $this->db->exec(
                "SELECT at.*, u.no_whatsapp, u.id as user_id 
                 FROM activation_tokens at
                 JOIN users u ON at.user_id = u.id
                 WHERE at.token = ? AND at.expires_at > NOW()
                 LIMIT 1",
                [$token]
            );

            if (empty($tokenData)) {
                echo json_encode(['success' => false, 'message' => 'Link aktivasi tidak valid atau sudah kadaluarsa']);
                return;
            }

            $data = $tokenData[0];
            $phone = $this->formatPhoneNumber($data['no_whatsapp']);
            $userId = $data['user_id'];

            $blockCheck = [];
            try {
                $blockCheck = $this->db->exec(
                    "SELECT blocked_until FROM otp_activations 
                     WHERE phone_number = ? AND blocked_until > NOW() 
                     ORDER BY blocked_until DESC LIMIT 1",
                    [$phone]
                );
            } catch (\Throwable $e) {
                // Ignore if column doesn't exist on deployment DB
            }

            if (!empty($blockCheck)) {
                echo json_encode([
                    'success' => false, 
                    'message' => 'Anda telah melebihi batas percobaan. Silakan coba lagi dalam 30 menit.',
                    'attemptsLeft' => 0
                ]);
                return;
            }

            try {
                $otpData = $this->db->exec(
                    "SELECT id, otp_code, attempts 
                     FROM otp_activations 
                     WHERE phone_number = ? AND verified = 0 AND expires_at > NOW()
                     ORDER BY created_at DESC LIMIT 1",
                    [$phone]
                );
            } catch (\Throwable $e) {
                // Missing 'attempts' column on deployment
                $otpData = $this->db->exec(
                    "SELECT id, otp_code 
                     FROM otp_activations 
                     WHERE phone_number = ? AND verified = 0 AND expires_at > NOW()
                     ORDER BY created_at DESC LIMIT 1",
                    [$phone]
                );
                if (!empty($otpData)) {
                    $otpData[0]['attempts'] = 0;
                }
            }

            if (empty($otpData)) {
                echo json_encode(['success' => false, 'message' => 'Kode OTP tidak valid atau sudah kadaluarsa. Silakan kirim ulang.']);
                return;
            }

            $otpRecord = $otpData[0];
            $currentAttempts = $otpRecord['attempts'] ?? 0;

            if ($otpRecord['otp_code'] === $otp) {
                $this->db->begin();
                
                try {
                    $this->db->exec(
                        "UPDATE otp_activations SET verified = 1 WHERE id = ?",
                        [$otpRecord['id']]
                    );

                    $this->db->exec(
                        "UPDATE users SET status = 'pending' WHERE id = ?",
                        [$userId]
                    );

                    $this->db->exec(
                        "DELETE FROM activation_tokens WHERE token = ?",
                        [$token]
                    );

                    $this->db->commit();

                    echo json_encode([
                        'success' => true,
                        'message' => 'Aktivasi berhasil! Akun Anda menunggu di verifikasi.'
                    ]);
                } catch (\Throwable $e) {
                    $this->db->rollback();
                    error_log("Activation error: " . $e->getMessage());
                    echo json_encode(['success' => false, 'message' => 'Terjadi kesalahan saat menyimpan aktivasi akun.']);
                }
            } else {
                $newAttempts = $currentAttempts + 1;
                
                if ($newAttempts >= 5) {
                    try {
                        $this->db->exec(
                            "UPDATE otp_activations 
                             SET attempts = ?, blocked_until = DATE_ADD(NOW(), INTERVAL 30 MINUTE) 
                             WHERE id = ?",
                            [$newAttempts, $otpRecord['id']]
                        );
                    } catch (\Throwable $e) {
                        try {
                            $this->db->exec(
                                "UPDATE otp_activations SET attempts = ? WHERE id = ?",
                                [$newAttempts, $otpRecord['id']]
                            );
                        } catch (\Throwable $e2) {
                            // Missing attempts column
                        }
                    }
                    
                    echo json_encode([
                        'success' => false,
                        'message' => 'Anda telah melebihi batas percobaan. Silakan coba lagi dalam 30 menit.',
                        'attemptsLeft' => 0
                    ]);
                } else {
                    try {
                        $this->db->exec(
                            "UPDATE otp_activations SET attempts = ? WHERE id = ?",
                            [$newAttempts, $otpRecord['id']]
                        );
                    } catch (\Throwable $e) {
                        // Missing attempts column
                    }
                    
                    $attemptsLeft = 5 - $newAttempts;
                    echo json_encode([
                        'success' => false,
                        'message' => "Kode OTP salah. Sisa percobaan: {$attemptsLeft}",
                        'attemptsLeft' => $attemptsLeft
                    ]);
                }
            }
        } catch (\Throwable $e) {
            error_log("Fatal error in verifyActivationOTP: " . $e->getMessage());
            echo json_encode([
                'success' => false, 
                'message' => 'Terjadi kesalahan sistem internal: ' . $e->getMessage()
            ]);
        }
    }

    public function resendActivationOTP($f3)
    {
        if (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json');

        try {
            if (!$this->validateCsrf($f3)) {
                throw new \Exception('Invalid CSRF token');
            }

            if (!$this->checkRateLimit($f3, 'resend_activation_otp', 3, 60)) {
                throw new \Exception('Terlalu banyak permintaan. Silakan coba lagi dalam 1 menit.');
            }

            $token = trim($f3->get('POST.token') ?? '');
            if (empty($token)) {
                throw new \Exception('Token tidak valid');
            }

            $tokenData = $this->db->exec(
                "SELECT at.*, u.no_whatsapp 
                 FROM activation_tokens at
                 JOIN users u ON at.user_id = u.id
                 WHERE at.token = ? AND at.expires_at > NOW()
                 LIMIT 1",
                [$token]
            );

            if (empty($tokenData)) {
                throw new \Exception('Link aktivasi tidak valid atau sudah kadaluarsa');
            }

            $data = $tokenData[0];
            $phone = $this->formatPhoneNumber($data['no_whatsapp']);
            $userId = $data['user_id'];
            $resendCount = ($data['resend_count'] ?? 0) + 1;

            try {
                $this->db->exec("UPDATE activation_tokens SET resend_count = ? WHERE token = ?", [$resendCount, $token]);
            } catch (\Throwable $e) {
                // Ignore if the deployed database doesn't have the resend_count column yet
            }

            $otp = $this->generateOTP();
            $this->db->exec("DELETE FROM otp_activations WHERE phone_number = ? AND verified = 0", [$phone]);
            try {
                $this->db->exec(
                    "INSERT INTO otp_activations (phone_number, otp_code, expires_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 1 MINUTE))", 
                    [$phone, $otp]
                );
            } catch (\Throwable $e) {
                try {
                    $this->db->exec(
                        "INSERT INTO otp_activations (phone_number, otp_code, expires_at, attempts) 
                         VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 1 MINUTE), 0)",
                        [$phone, $otp]
                    );
                } catch (\Throwable $e2) {
                    error_log("Critical OTP insert failure in resend: " . $e2->getMessage());
                    echo json_encode(['success' => false, 'message' => 'Gagal membuat kode OTP baru.']);
                    return;
                }
            }

            $deliveryMethod = 'WhatsApp';
            $success = false;
            $message_text = "";
            $waFailed = false;

            if ($resendCount <= 3) {
                $message = "Kode OTP untuk aktivasi akun Portal SELPORT: *{$otp}*\n\n";
                $message .= "Berlaku selama 1 menit.\n\n";
                $message .= "⚠️ PENTING:\n";
                $message .= "Jangan berikan kode OTP ini kepada siapapun.\n\n";
                $message .= "DIMOHON UNTUK TIDAK MEMBALAS PESAN INI";

                $waResult = $this->sendWAnotif($message, $phone);
                
                if ($waResult['success']) {
                    $success = true;
                    $message_text = 'Kode OTP telah dikirim ulang ke WhatsApp Anda';
                } else {
                    $waFailed = true;
                }
            }

            if ($resendCount > 3 || $waFailed) {
                $deliveryMethod = 'Email';
                
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
                    $userMapper = new \DB\SQL\Mapper($this->db, 'users');
                    $userMapper->load(['id = ?', $userId]);

                    if (!$userMapper->dry()) {
                        try {
                            $emailService = new EmailService();
                            $mailer = $emailService->getMailer();
                            
                            $mailer->addAddress($userMapper->email, $userMapper->nama_lengkap);
                            $mailer->isHTML(true);
                            $mailer->Subject = "Kode OTP Aktivasi Portal SELPORT";
                            $mailer->Body = "<h2>Aktivasi Akun Portal SELPORT</h2>" .
                                          "<p>Halo " . htmlspecialchars($userMapper->nama_lengkap) . ",</p>" .
                                          "<p>Gunakan kode OTP berikut: <b style='font-size: 24px; color: #62A0FD;'>{$otp}</b></p>";
                            
                            $mailer->send();
                            $success = true;
                            
                            if ($waFailed) {
                                $message_text = 'Gagal mengirim ke WhatsApp. Kode OTP telah dikirim ke Email Anda: ' . substr($userMapper->email, 0, 3) . '***' . strstr($userMapper->email, '@');
                            } else {
                                $message_text = 'Maksimum resend WA tercapai. Kode OTP telah dikirim ke Email Anda.';
                            }
                            
                            $mailer->clearAddresses();
                        } catch (\Exception $e) {
                            $success = false;
                        }
                    }
                }
            }

            if (ob_get_level()) ob_end_clean();
            header('Content-Type: application/json');

            if ($success) {
                echo json_encode(['success' => true, 'message' => $message_text, 'resendCount' => $resendCount]);
            } else {
                $errMsg = $waFailed ? 'Gagal mengirim OTP ke WhatsApp dan Email. Silakan hubungi admin.' : 'Gagal mengirim kode OTP.';
                echo json_encode(['success' => false, 'message' => $errMsg]);
            }

        } catch (\Throwable $e) {
            error_log("OTP Resend Fatal Error: " . $e->getMessage() . " in " . $e->getFile() . " on line " . $e->getLine());
            echo json_encode(['success' => false, 'message' => 'Terjadi kesalahan sistem (Server Error): ' . $e->getMessage()]);
        }
    }

    private function logResetActivity($userId, $username, $status, $f3)
    {
        try {
            $ipAddress = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
            $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
            
            $this->db->exec(
                "INSERT INTO password_reset_logs (user_id, username, ip_address, user_agent, status) 
                 VALUES (?, ?, ?, ?, ?)",
                [$userId, $username, $ipAddress, $userAgent, $status]
            );
        } catch (\Throwable $e) {
            error_log('logResetActivity failed (table may not exist): ' . $e->getMessage());
        }
    }
}