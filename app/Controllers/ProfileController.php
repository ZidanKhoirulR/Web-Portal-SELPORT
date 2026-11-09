<?php

class ProfileController
{
    private $db;
    private $tracking;

    public function __construct($db, $trackingService = null)
    {
        $this->db       = $db;
        $this->tracking = $trackingService;
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    private function validateCsrf($f3)
    {
        $token        = $f3->get('POST.csrf_token');
        $sessionToken = $f3->get('SESSION.csrf_token');
        if (empty($token) || empty($sessionToken) || $token !== $sessionToken) {
            return false;
        }
        return true;
    }

    private function validatePhoneNumber($phone, $fieldName = "Nomor telepon")
    {
        $phone = trim($phone);
        if (empty($phone)) {
            return ["error" => "$fieldName harus diisi", "cleaned" => null];
        }
        $cleanedPhone = ltrim($phone, '+');
        if (!ctype_digit($cleanedPhone)) {
            return ["error" => "$fieldName hanya boleh berisi angka (dengan atau tanpa awalan +)", "cleaned" => null];
        }
        if (strlen($cleanedPhone) < 10) {
            return ["error" => "$fieldName minimal 10 digit", "cleaned" => null];
        }
        if (strlen($cleanedPhone) > 15) {
            return ["error" => "$fieldName maksimal 15 digit", "cleaned" => null];
        }
        return ["error" => null, "cleaned" => $phone];
    }

    private function logChanges($userId, $oldData, $newData)
    {
        if (!$this->tracking) return;
        foreach ($newData as $key => $value) {
            if (array_key_exists($key, $oldData) && $oldData[$key] != $value) {
                if (in_array($key, ['updated_at', 'created_at', 'reset_token', 'reset_token_expire', 'profile_photo'])) continue;
                $this->tracking->logProfileChange($userId, $key, $oldData[$key], $value);
            }
        }
    }

    // ─── Profile Photo ────────────────────────────────────────────────────────

    public function getProfilePhoto($f3)
    {
        $id   = $f3->get('PARAMS.id');
        $user = new DB\SQL\Mapper($this->db, 'users');
        $user->load(['id = ?', $id]);

        if (!$user->dry() && !empty($user->profile_photo)) {
            $photo      = $user->profile_photo;
            $isFileName = is_string($photo) && strlen($photo) < 255 && strpos($photo, '.') !== false;

            if ($isFileName) {
                $filePath = __DIR__ . '/../../public/uploads/profile/' . $photo;
                if (file_exists($filePath)) {
                    $finfo    = new finfo(FILEINFO_MIME_TYPE);
                    $mimeType = $finfo->file($filePath);
                    header('Content-Type: ' . $mimeType);
                    header('Cache-Control: no-cache, no-store, must-revalidate');
                    header('Pragma: no-cache');
                    header('Expires: 0');
                    readfile($filePath);
                    exit;
                }
            } else {
                $finfo    = new finfo(FILEINFO_MIME_TYPE);
                $mimeType = $finfo->buffer($photo);
                header('Content-Type: ' . $mimeType);
                header('Cache-Control: no-cache, no-store, must-revalidate');
                header('Pragma: no-cache');
                header('Expires: 0');
                echo $photo;
                exit;
            }
        }

        $defaultPath = __DIR__ . '/../../public/images/default-avatar.png';
        if (file_exists($defaultPath)) {
            header('Content-Type: image/png');
            header('Cache-Control: max-age=86400');
            readfile($defaultPath);
        }
        exit;
    }

    // ─── Profile GET/POST ─────────────────────────────────────────────────────

    public function profile($f3)
    {
        if (!$f3->get('SESSION.user_id') || $f3->get('SESSION.role') !== 'customer') {
            echo json_encode(['success' => false, 'message' => 'Sesi tidak valid atau peran telah berubah. Silakan muat ulang halaman.']);
            return;
        }

        $user = new DB\SQL\Mapper($this->db, 'users');
        $user->load(['id = ?', $f3->get('SESSION.user_id')]);
        $oldData = $user->cast();

        if ($f3->get('VERB') == 'POST') {
            if (!$this->validateCsrf($f3)) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['success' => false, 'message' => 'Invalid CSRF Token. Silakan muat ulang halaman.']);
                return;
            }

            $update_type        = $f3->get('POST.update_type');
            $errors             = [];
            $newProfilePhotoName = null;

            try {
                if ($update_type === 'personal') {
                    $nama_lengkap     = trim($f3->get('POST.nama_lengkap'));
                    $email            = trim($f3->get('POST.email'));
                    $alamat_tinggal   = trim($f3->get('POST.alamat_tinggal'));
                    $provinsi_tinggal = trim($f3->get('POST.provinsi_tinggal'));
                    $kabupaten_tinggal = trim($f3->get('POST.kabupaten_tinggal'));
                    $kecamatan_tinggal = trim($f3->get('POST.kecamatan_tinggal'));
                    $kodepos_tinggal  = trim($f3->get('POST.kodepos_tinggal'));
                    $no_whatsapp      = $f3->get('POST.no_whatsapp');
                    $tanggal_lahir    = $f3->get('POST.tanggal_lahir');

                    if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = "Format email tidak valid";

                    if (empty($provinsi_tinggal))  $provinsi_tinggal  = $user->provinsi_tinggal  ?? '';
                    if (empty($kabupaten_tinggal)) $kabupaten_tinggal = $user->kabupaten_tinggal ?? '';
                    if (empty($kecamatan_tinggal)) $kecamatan_tinggal = $user->kecamatan_tinggal ?? '';
                    if (empty($kodepos_tinggal))   $kodepos_tinggal   = $user->kodepos_tinggal   ?? '';

                    if (!empty($no_whatsapp) && $no_whatsapp !== '-') {
                        $whatsappValidation = $this->validatePhoneNumber($no_whatsapp, "No WhatsApp");
                        if ($whatsappValidation['error']) {
                            $errors[] = $whatsappValidation['error'];
                        } else {
                            $no_whatsapp = $whatsappValidation['cleaned'];
                        }
                    }

                    if (isset($_FILES['profile_photo']) && $_FILES['profile_photo']['error'] === UPLOAD_ERR_OK) {
                        $fileExtension     = strtolower(pathinfo($_FILES['profile_photo']['name'], PATHINFO_EXTENSION));
                        $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif'];
                        $maxFileSize       = 2 * 1024 * 1024;

                        if ($_FILES['profile_photo']['size'] > $maxFileSize) {
                            $errors[] = "Ukuran foto terlalu besar. Maksimal 2MB. Silakan kompres foto Anda terlebih dahulu.";
                        } elseif (!in_array($fileExtension, $allowedExtensions)) {
                            $errors[] = "Format foto tidak valid. Hanya JPG, JPEG, PNG, atau GIF yang diperbolehkan.";
                        } else {
                            $targetDir = __DIR__ . '/../../public/uploads/profile/';
                            if (!is_dir($targetDir)) {
                                if (!mkdir($targetDir, 0755, true)) {
                                    $errors[] = "Gagal membuat folder upload. Silakan hubungi administrator.";
                                }
                            }
                            if (empty($errors)) {
                                if (!is_writable($targetDir)) @chmod($targetDir, 0755);
                                if (!is_writable($targetDir)) {
                                    $errors[] = "Folder upload tidak dapat ditulis. Silakan hubungi administrator (path: " . realpath($targetDir) . ")";
                                } else {
                                    $userId    = $f3->get('SESSION.user_id');
                                    $fileName  = 'user_' . $userId . '_' . time() . '.' . $fileExtension;
                                    $targetFile = $targetDir . $fileName;
                                    if (move_uploaded_file($_FILES['profile_photo']['tmp_name'], $targetFile)) {
                                        $newProfilePhotoName = $fileName;
                                        $oldPhoto = $user->profile_photo;
                                        if (!empty($oldPhoto) && is_string($oldPhoto) && strlen($oldPhoto) < 255 && strpos($oldPhoto, '.') !== false) {
                                            $oldFilePath = $targetDir . $oldPhoto;
                                            if (file_exists($oldFilePath)) @unlink($oldFilePath);
                                        }
                                    } else {
                                        $phpErr   = error_get_last();
                                        $errMsg   = $phpErr ? $phpErr['message'] : 'Tidak diketahui';
                                        $errors[] = "Gagal memindahkan file foto. Detail: " . htmlspecialchars($errMsg);
                                    }
                                }
                            }
                        }
                    } elseif (isset($_FILES['profile_photo']) && $_FILES['profile_photo']['error'] !== UPLOAD_ERR_NO_FILE) {
                        $uploadErrors = [
                            UPLOAD_ERR_INI_SIZE   => 'File terlalu besar (melebihi batas upload_max_filesize server)',
                            UPLOAD_ERR_FORM_SIZE  => 'File terlalu besar (melebihi batas MAX_FILE_SIZE form)',
                            UPLOAD_ERR_PARTIAL    => 'File hanya terupload sebagian, coba lagi',
                            UPLOAD_ERR_NO_TMP_DIR => 'Folder temporary server tidak ditemukan',
                            UPLOAD_ERR_CANT_WRITE => 'Gagal menulis file ke disk server',
                            UPLOAD_ERR_EXTENSION  => 'Upload dihentikan oleh ekstensi PHP',
                        ];
                        $uploadErrCode = $_FILES['profile_photo']['error'];
                        $errors[]      = $uploadErrors[$uploadErrCode] ?? "Error upload tidak diketahui (kode: $uploadErrCode)";
                    }

                    if (empty($errors)) {
                        if (!empty($nama_lengkap))   $user->nama_lengkap     = $nama_lengkap;
                        if (!empty($email))           $user->email            = $email;
                        if (!empty($alamat_tinggal))  $user->alamat_tinggal   = $alamat_tinggal;
                        if (!empty($provinsi_tinggal))  $user->provinsi_tinggal  = $provinsi_tinggal;
                        if (!empty($kabupaten_tinggal)) $user->kabupaten_tinggal = $kabupaten_tinggal;
                        if (!empty($kecamatan_tinggal)) $user->kecamatan_tinggal = $kecamatan_tinggal;
                        if (!empty($kodepos_tinggal))   $user->kodepos_tinggal   = $kodepos_tinggal;
                        if (!empty($no_whatsapp))     $user->no_whatsapp      = $no_whatsapp;
                        if (!empty($tanggal_lahir))   $user->tanggal_lahir    = $tanggal_lahir;
                        if (!empty($f3->get('POST.jabatan'))) $user->jabatan  = $f3->get('POST.jabatan');
                        if ($newProfilePhotoName !== null) $user->profile_photo = $newProfilePhotoName;
                        $user->save();
                        if (!empty($nama_lengkap)) $f3->set('SESSION.nama_lengkap', $nama_lengkap);
                        $this->logChanges($f3->get('SESSION.user_id'), $oldData, $user->cast());
                    }

                } elseif ($update_type === 'company') {
                    $nama_perusahaan     = trim($f3->get('POST.nama_perusahaan'));
                    $email_perusahaan    = trim($f3->get('POST.email_perusahaan'));
                    $alamat_perusahaan   = trim($f3->get('POST.alamat_perusahaan'));
                    $telp_perusahaan     = $f3->get('POST.telp_perusahaan');
                    $wa_perusahaan       = $f3->get('POST.wa_perusahaan');
                    $fax_perusahaan      = $f3->get('POST.fax_perusahaan');
                    $provinsi_perusahaan = trim($f3->get('POST.provinsi_perusahaan'));
                    $kabupaten_perusahaan = trim($f3->get('POST.kabupaten_perusahaan'));
                    $kecamatan_perusahaan = trim($f3->get('POST.kecamatan_perusahaan'));
                    $kodepos_perusahaan  = trim($f3->get('POST.kodepos_perusahaan'));

                    if (!empty($email_perusahaan) && !filter_var($email_perusahaan, FILTER_VALIDATE_EMAIL)) {
                        $errors[] = "Email perusahaan tidak valid";
                    }

                    if (!empty($user->company_id)) {
                        $tempCompany = new DB\SQL\Mapper($this->db, 'companies');
                        $tempCompany->load(['id = ?', $user->company_id]);
                        if (!$tempCompany->dry()) {
                            if (empty($provinsi_perusahaan))  $provinsi_perusahaan  = $tempCompany->provinsi_perusahaan  ?? '';
                            if (empty($kabupaten_perusahaan)) $kabupaten_perusahaan = $tempCompany->kabupaten_perusahaan ?? '';
                            if (empty($kecamatan_perusahaan)) $kecamatan_perusahaan = $tempCompany->kecamatan_perusahaan ?? '';
                            if (empty($kodepos_perusahaan))   $kodepos_perusahaan   = $tempCompany->kodepos_perusahaan   ?? '';
                        }
                    }

                    foreach ([[$telp_perusahaan, 'Telp perusahaan'], [$wa_perusahaan, 'WhatsApp perusahaan'], [$fax_perusahaan, 'Nomor fax']] as [$val, $label]) {
                        if (!empty($val) && $val !== '-') {
                            $v = $this->validatePhoneNumber($val, $label);
                            if ($v['error']) {
                                $errors[] = $v['error'];
                            } else {
                                if ($label === 'Telp perusahaan')      $telp_perusahaan = $v['cleaned'];
                                if ($label === 'WhatsApp perusahaan')  $wa_perusahaan   = $v['cleaned'];
                                if ($label === 'Nomor fax')            $fax_perusahaan  = $v['cleaned'];
                            }
                        }
                    }

                    if (empty($errors)) {
                        if (!empty($user->company_id)) {
                            $company = new DB\SQL\Mapper($this->db, 'companies');
                            $company->load(['id = ?', $user->company_id]);
                            if (!$company->dry()) {
                                $company->nama_perusahaan     = $nama_perusahaan;
                                $company->email_perusahaan    = $email_perusahaan;
                                $company->alamat_perusahaan   = $alamat_perusahaan;
                                $company->provinsi_perusahaan = $provinsi_perusahaan;
                                $company->kabupaten_perusahaan = $kabupaten_perusahaan;
                                $company->kecamatan_perusahaan = $kecamatan_perusahaan;
                                $company->kodepos_perusahaan  = $kodepos_perusahaan;
                                $company->telp_perusahaan     = $telp_perusahaan;
                                $company->wa_perusahaan       = $wa_perusahaan;
                                $company->fax_perusahaan      = $fax_perusahaan;
                                $company->save();
                            }
                        }
                        $user->save();
                        $this->logChanges($f3->get('SESSION.user_id'), $oldData, $user->cast());
                    }
                } else {
                    $errors[] = "Tipe update tidak valid";
                }

                if (!empty($errors)) {
                    header('Content-Type: application/json; charset=utf-8');
                    echo json_encode(['success' => false, 'message' => implode('<br>', $errors)]);
                    return;
                }

                header('Content-Type: application/json; charset=utf-8');
                $newPhotoUrl = null;
                if ($newProfilePhotoName !== null) {
                    $newPhotoUrl = $f3->get('BASE') . '/public/uploads/profile/' . $newProfilePhotoName;
                }
                echo json_encode(['success' => true, 'message' => 'Profil berhasil diperbarui', 'profile_photo_url' => $newPhotoUrl]);
                return;

            } catch (Exception $e) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['success' => false, 'message' => 'Terjadi kesalahan: ' . $e->getMessage()]);
            }

        } else {
            // GET — return profile data as JSON
            try {
                $userData = $this->db->exec(
                    'SELECT id, nama_lengkap, email, jenis_kelamin, alamat_tinggal,
                            provinsi_tinggal, kabupaten_tinggal, kecamatan_tinggal, kodepos_tinggal,
                            no_whatsapp, jabatan, company_id, profile_photo,
                            CASE WHEN profile_photo IS NOT NULL AND profile_photo != \'\' THEN 1 ELSE 0 END as has_photo,
                            UNIX_TIMESTAMP(COALESCE(updated_at, NOW())) as photo_updated_at
                     FROM users WHERE id = ?',
                    [$f3->get('SESSION.user_id')]
                );

                if (empty($userData)) throw new Exception('User not found');
                $user = $userData[0];

                $responseData = [
                    'nama_lengkap'     => $user['nama_lengkap'],
                    'email'            => $user['email'],
                    'jenis_kelamin'    => $user['jenis_kelamin'],
                    'alamat_tinggal'   => $user['alamat_tinggal'],
                    'provinsi_tinggal' => $user['provinsi_tinggal'],
                    'kabupaten_tinggal'=> $user['kabupaten_tinggal'],
                    'kecamatan_tinggal'=> $user['kecamatan_tinggal'],
                    'kodepos_tinggal'  => $user['kodepos_tinggal'],
                    'no_whatsapp'      => $user['no_whatsapp'],
                    'jabatan'          => $user['jabatan'] ?? '',
                    'profile_photo_url'=> ($user['has_photo'] == 1 && !empty($user['profile_photo']) && strpos($user['profile_photo'], '.') !== false)
                        ? $f3->get('BASE') . '/public/uploads/profile/' . $user['profile_photo']
                        : (($user['has_photo'] == 1) ? $f3->get('BASE') . '/image/profile/' . $user['id'] : null)
                ];

                if (!empty($user['company_id'])) {
                    $company = $this->db->exec('SELECT * FROM companies WHERE id = ?', [$user['company_id']]);
                    if (!empty($company)) {
                        $comp         = $company[0];
                        $responseData = array_merge($responseData, [
                            'nama_perusahaan'     => $comp['nama_perusahaan'],
                            'email_perusahaan'    => $comp['email_perusahaan'],
                            'alamat_perusahaan'   => $comp['alamat_perusahaan'],
                            'provinsi_perusahaan' => $comp['provinsi_perusahaan'],
                            'kabupaten_perusahaan'=> $comp['kabupaten_perusahaan'],
                            'kecamatan_perusahaan'=> $comp['kecamatan_perusahaan'],
                            'kodepos_perusahaan'  => $comp['kodepos_perusahaan'],
                            'telp_perusahaan'     => $comp['telp_perusahaan'],
                            'wa_perusahaan'       => $comp['wa_perusahaan'],
                            'fax_perusahaan'      => $comp['fax_perusahaan'],
                        ]);
                    }
                }

                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['success' => true, 'data' => $responseData]);

            } catch (Exception $e) {
                header('HTTP/1.1 500 Internal Server Error');
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
            }
        }
    }

    // ─── Wilayah API ──────────────────────────────────────────────────────────

    public function getWilayahProvinsi($f3)
    {
        header('Content-Type: application/json; charset=utf-8');
        try {
            $rows = $this->db->exec("SELECT kode_provinsi, nama AS nama_provinsi FROM wilayah WHERE level = 1 ORDER BY nama ASC");
            echo json_encode(['success' => true, 'data' => $rows ?: []]);
        } catch (\Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage(), 'data' => []]);
        }
    }

    public function getWilayahKabupaten($f3)
    {
        header('Content-Type: application/json; charset=utf-8');
        $kodeProvinsi = trim($f3->get('GET.kode_provinsi') ?? '');
        if (empty($kodeProvinsi)) {
            echo json_encode(['success' => false, 'message' => 'kode_provinsi diperlukan', 'data' => []]);
            return;
        }
        try {
            $rows = $this->db->exec(
                "SELECT kode_kabkota AS kode_kabupaten, nama AS nama_kabupaten FROM wilayah WHERE level = 2 AND kode_provinsi = ? ORDER BY nama ASC",
                [$kodeProvinsi]
            );
            echo json_encode(['success' => true, 'data' => $rows ?: []]);
        } catch (\Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage(), 'data' => []]);
        }
    }

    public function getWilayahKecamatan($f3)
    {
        header('Content-Type: application/json; charset=utf-8');
        $kodeKabupaten = trim($f3->get('GET.kode_kabupaten') ?? '');
        if (empty($kodeKabupaten)) {
            echo json_encode(['success' => false, 'message' => 'kode_kabupaten diperlukan', 'data' => []]);
            return;
        }
        try {
            $rows = $this->db->exec(
                "SELECT w3.kode_kecamatan, w3.nama AS nama_kecamatan,
                        (SELECT w4.kodepos FROM wilayah w4 WHERE w4.level = 4 AND w4.kode_kecamatan = w3.kode_kecamatan AND w4.kodepos IS NOT NULL LIMIT 1) AS kode_pos
                 FROM wilayah w3 WHERE w3.level = 3 AND w3.kode_kabkota = ? ORDER BY w3.nama ASC",
                [$kodeKabupaten]
            );
            echo json_encode(['success' => true, 'data' => $rows ?: []]);
        } catch (\Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage(), 'data' => []]);
        }
    }
}