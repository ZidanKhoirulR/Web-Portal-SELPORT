<?php
date_default_timezone_set('Asia/Jakarta');

if (php_sapi_name() == 'cli-server') {
    $_SERVER['SCRIPT_NAME'] = '/index.php';
    $path = parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH);
    if (file_exists(__DIR__ . $path) && is_file(__DIR__ . $path)) {
        return false;
    }
}

// Load environment variables from .env file if available
if (file_exists(__DIR__ . '/.env')) {
    $envLines = file(__DIR__ . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($envLines as $line) {
        $line = trim($line);
        if ($line === '' || strpos($line, '#') === 0) continue;
        if (strpos($line, '=') !== false) {
            list($key, $val) = explode('=', $line, 2);
            $key = trim($key);
            $val = trim($val, " \t\n\r\0\x0B\"'");
            putenv("{$key}={$val}");
            $_ENV[$key] = $val;
            $_SERVER[$key] = $val;
        }
    }
}

session_start();

require 'vendor/autoload.php';
require 'app/Services/TrackingService.php';
require 'app/Services/NotifikasiService.php';
require 'app/Controllers/OtentikasiController.php';
require 'app/Controllers/DashboardPelangganController.php';
require 'app/Controllers/LandingController.php';
require 'app/Controllers/PengaduanController.php';
require 'app/Controllers/AdminController.php';
require 'app/Controllers/MitraController.php';
require 'app/Controllers/KeuanganController.php';
require 'app/Controllers/NotifikasiController.php';
require 'app/Controllers/PembayaranController.php';
require 'app/Controllers/ProfileController.php';
require 'app/Controllers/PermohonanPengecualianController.php';
require 'app/Controllers/KesanggupanPembayaranController.php';
require 'app/Controllers/KeluhanController.php';
require 'app/Controllers/LayananController.php';
require 'app/Controllers/SertifikasiController.php';
require 'app/Controllers/PermohonanAlatController.php';


$f3 = \Base::instance();
$f3->config('config.cfg');
if ($envDocSecret = getenv('DOC_SECRET')) {
    $f3->set('DOC_SECRET', $envDocSecret);
}
$f3->set('DEBUG', 0);

$f3->set('BASE', (isset($_SERVER['BASE']) ? $_SERVER['BASE'] : (($pos = strpos($_SERVER['SCRIPT_NAME'], '/index.php')) !== false ? substr($_SERVER['SCRIPT_NAME'], 0, $pos) : '')));

$dbHost = getenv('DB_HOST') ?: 'localhost';
$dbPort = getenv('DB_PORT') ?: '';
$dbName = getenv('DB_NAME') ?: '';
$dbUser = getenv('DB_USER') ?: '';
$dbPass = getenv('DB_PASS') !== false ? getenv('DB_PASS') : '';

$f3->set('DB', new DB\SQL(
    "mysql:host={$dbHost};port={$dbPort};dbname={$dbName};charset=utf8mb4",
    $dbUser,
    $dbPass
));

$dbSilHost = getenv('DB_SIL_HOST') ?: 'localhost';
$dbSilPort = getenv('DB_SIL_PORT') ?: '';
$dbSilName = getenv('DB_SIL_NAME') ?: '';
$dbSilUser = getenv('DB_SIL_USER') ?: '';
$dbSilPass = getenv('DB_SIL_PASS') !== false ? getenv('DB_SIL_PASS') : '';

$f3->set('DB_SIL', new DB\SQL(
    "mysql:host={$dbSilHost};port={$dbSilPort};dbname={$dbSilName};charset=utf8mb4",
    $dbSilUser,
    $dbSilPass
));

$f3->set('UI', 'ui/');

$db    = $f3->get('DB');
$dbSil = $f3->get('DB_SIL');

$dbSerti = null;
try {
    $dbSertiHost = getenv('DB_SERTI_HOST') ?: 'localhost';
    $dbSertiPort = getenv('DB_SERTI_PORT') ?: '';
    $dbSertiName = getenv('DB_SERTI_NAME') ?: '';
    $dbSertiUser = getenv('DB_SERTI_USER') ?: '';
    $dbSertiPass = getenv('DB_SERTI_PASS') !== false ? getenv('DB_SERTI_PASS') : '';

    $f3->set('DB_SERTI', new DB\SQL(
        "mysql:host={$dbSertiHost};port={$dbSertiPort};dbname={$dbSertiName};charset=utf8mb4",
        $dbSertiUser,
        $dbSertiPass
    ));
    $dbSerti = $f3->get('DB_SERTI');
} catch (\Exception $e) {
    // Database serti tidak tersedia, fitur sertifikasi dinonaktifkan
    $dbSerti = null;
}

$dbKalib = null;
try {
    $dbKalibHost = getenv('DB_KALIB_HOST') ?: 'localhost';
    $dbKalibPort = getenv('DB_KALIB_PORT') ?: '';
    $dbKalibName = getenv('DB_KALIB_NAME') ?: '';
    $dbKalibUser = getenv('DB_KALIB_USER') ?: '';
    $dbKalibPass = getenv('DB_KALIB_PASS') !== false ? getenv('DB_KALIB_PASS') : '';

    $f3->set('DB_KALIB', new DB\SQL(
        "mysql:host={$dbKalibHost};port={$dbKalibPort};dbname={$dbKalibName};charset=utf8mb4",
        $dbKalibUser,
        $dbKalibPass
    ));
    $dbKalib = $f3->get('DB_KALIB');
} catch (\Exception $e) {
    // Database kalibrasi tidak tersedia, fitur kalibrasi dari sikalib2024 dinonaktifkan
    $dbKalib = null;
}

// Set Timezone Database
$db->exec("SET time_zone = '+07:00';");

$trackingService = new TrackingService($db);
$notifikasiService = new NotifikasiService($db, $dbSil);

$authController         = new OtentikasiController($db, $dbSil, $notifikasiService);
$dashboardPelangganController = new DashboardPelangganController($db, $notifikasiService, $dbSil, $dbSerti, $dbKalib);
$landingController      = new LandingController($db);
$pengaduanController    = new PengaduanController($db, $notifikasiService);
$adminController        = new AdminController($db, $notifikasiService, $dbSil);
$mitraController        = new MitraController($db, $notifikasiService, $dbSil);
$keuanganController     = new KeuanganController($db, $notifikasiService, $dbSil);
$notifikasiController   = new NotifikasiController($db, $notifikasiService);
$pembayaranController   = new PembayaranController($db, $notifikasiService);
$profileController      = new ProfileController($db, $trackingService);
$permohonanPengecualianController = new PermohonanPengecualianController($db, $notifikasiService);
$kesanggupanPembayaranController  = new KesanggupanPembayaranController($db, $notifikasiService);
$keluhanController      = new KeluhanController($db, $notifikasiService, $dbSil);
$layananController         = new LayananController($db, $notifikasiService, $dbSil);
$sertifikasiController     = new SertifikasiController($db, $notifikasiService, $dbSerti, $dbSil);
$permohonanAlatController = new PermohonanAlatController($db, $notifikasiService);


$currPath = $f3->get('PATH');
if (strpos($currPath, '/css/') === false && strpos($currPath, '/js/') === false && strpos($currPath, '/images/') === false) {
    $clientIP = $trackingService->checkIPBlock($_SERVER['REMOTE_ADDR']);
    if ($clientIP) {
        header('HTTP/1.1 403 Forbidden');
        echo '<div style="font-family:sans-serif; text-align:center; padding:50px;">
            <h1 style="color:red;">Akses Ditolak</h1>
            <p>IP Anda telah diblokir.</p>
            <p>Alasan: ' . htmlspecialchars($clientIP) . '</p>
        </div>';
        exit;
    }
}

$f3->route('GET /admin', function ($f3) {
    $f3->reroute('/admin/dashboard');
});
$f3->route('GET /admin/dashboard', function ($f3) use ($adminController) {
    $adminController->index($f3, 'ringkasan');
});
$f3->route('GET /admin/trackinghistory', function ($f3) use ($adminController) {
    $adminController->index($f3, 'riwayat-tracking');
});
$f3->route('GET /admin/ipblocking', function ($f3) use ($adminController) {
    $adminController->index($f3, 'blokir-ip');
});
$f3->route('GET /admin/customerdata', function ($f3) use ($adminController) {
    $adminController->index($f3, 'data-pelanggan');
});
$f3->route('GET /admin/profilechanges', function ($f3) use ($adminController) {
    $adminController->index($f3, 'perubahan-profil');
});
$f3->route('GET /admin/kelolapegawai', function ($f3) use ($adminController) {
    $adminController->index($f3, 'kelola-pegawai');
});

$f3->route('GET /admin/load/@section', function ($f3, $params) use ($adminController) {
    $adminController->loadSection($f3, $params['section']);
});

$f3->route('POST /admin/tracking/delete', function ($f3) use ($adminController) {
    $adminController->deleteTrackingHistory($f3);
});

$f3->route('POST /admin/profile-changes/delete', function ($f3) use ($adminController) {
    $adminController->deleteProfileChangesHistory($f3);
});

$f3->route('GET /css/*', function ($f3, $params) {
    $file = isset($params['*']) ? $params['*'] : '';
    $file = ltrim($file, '/');

    if (strpos($file, '..') !== false) {
        header('HTTP/1.0 403 Forbidden');
        exit;
    }

    $filePath = __DIR__ . '/public/css/' . $file;

    if (file_exists($filePath) && is_file($filePath)) {
        header('Content-Type: text/css; charset=utf-8');
        header('Cache-Control: public, max-age=3600');
        readfile($filePath);
        exit;
    }

    header('HTTP/1.0 404 Not Found');
    exit;
});

$f3->route('GET /js/@file', function ($f3, $params) {
    $file = $params['file'];
    $filePath = __DIR__ . '/public/js/' . $file;
    if (file_exists($filePath) && is_file($filePath)) {
        header('Content-Type: application/javascript; charset=utf-8');
        header('Cache-Control: public, max-age=3600');
        readfile($filePath);
        exit;
    }
    header('HTTP/1.0 404 Not Found');
    exit;
});

$f3->route('GET /images/@file', function ($f3, $params) {
    $file = $params['file'];
    $filePath = __DIR__ . '/public/images/' . $file;
    if (file_exists($filePath) && is_file($filePath)) {
        $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        $contentTypes = [
            'png' => 'image/png',
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'svg' => 'image/svg+xml',
            'webp' => 'image/webp',
            'ico' => 'image/x-icon'
        ];
        if (isset($contentTypes[$ext])) {
            header('Content-Type: ' . $contentTypes[$ext]);
        }
        header('Cache-Control: public, max-age=86400');
        readfile($filePath);
        exit;
    }
    header('HTTP/1.0 404 Not Found');
    exit;
});

$f3->route('GET /', function ($f3) use ($landingController) {
    // Redirect ke dashboard jika sudah login
    if ($f3->get('SESSION.user_id')) {
        $role = $f3->get('SESSION.role');
        if ($role === 'admin') { $f3->reroute('/admin/dashboard'); }
        elseif ($role === 'mitra') { $f3->reroute('/mitra/dashboard'); }
        elseif ($role === 'keuangan') { $f3->reroute('/keuangan/dashboard'); }
        elseif ($role === 'sertifikasi') { $f3->reroute('/sertifikasi/dashboard'); }
        else { $f3->reroute('/beranda'); }
    }
    $landingController->index($f3);
});

$f3->route('GET /auth', function ($f3) use ($authController) {
    // Redirect ke dashboard jika sudah login
    if ($f3->get('SESSION.user_id')) {
        $role = $f3->get('SESSION.role');
        if ($role === 'admin') { $f3->reroute('/admin/dashboard'); }
        elseif ($role === 'mitra') { $f3->reroute('/mitra/dashboard'); }
        elseif ($role === 'keuangan') { $f3->reroute('/keuangan/dashboard'); }
        elseif ($role === 'sertifikasi') { $f3->reroute('/sertifikasi/dashboard'); }
        else { $f3->reroute('/beranda'); }
    }
    if ($f3->get('GET.success')) {
        $f3->set('success', $f3->get('GET.success'));
    }

    $html = file_get_contents('ui/otentikasi.html');

    if ($f3->get('SESSION.flash_success')) {
        $msg = $f3->get('SESSION.flash_success');
        $successHtml = '
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                ' . $msg . '
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close" onclick="this.parentElement.style.display=\'none\';">&#0215;</button>
            </div>';
        $html = str_replace('<!-- LOGIN_ERROR -->', $successHtml, $html);
        $f3->clear('SESSION.flash_success');
    }

    if ($f3->get('SESSION.flash_register_error')) {
        $msg = $f3->get('SESSION.flash_register_error');
        $errorHtml = '<div class="alert alert-error" style="position:relative;padding-right:2.5rem;">'
            . $msg
            . '<button type="button" onclick="this.parentElement.style.display=\'none\';" '
            . 'style="position:absolute;top:50%;right:0.75rem;transform:translateY(-50%);background:none;border:none;cursor:pointer;font-size:1.1rem;line-height:1;color:inherit;opacity:0.8;" '
            . 'title="Tutup">&times;</button>'
            . '</div>';
        $html = str_replace('<!-- REGISTER_ERROR -->', $errorHtml, $html);
        // Tampilkan panel register agar error langsung terlihat
        $html = str_replace('class="container" id="container"', 'class="container right-panel-active" id="container"', $html);
        $f3->clear('SESSION.flash_register_error');
    }

    if ($f3->get('SESSION.show_forgot_password')) {
        $script = '<script>document.addEventListener("DOMContentLoaded", function() { showForgotPassword(null); });</script>';
        $html = str_replace('</body>', $script . '</body>', $html);

        if ($f3->get('SESSION.forgot_message')) {
            $msg = $f3->get('SESSION.forgot_message'); // msg should contain full alert HTML
            $html = str_replace('<!-- ERROR_PLACEHOLDER -->', $msg, $html);
            $f3->clear('SESSION.forgot_message');
        }

        $f3->clear('SESSION.show_forgot_password');
    }

    echo $authController->renderPageWithCsrf($html);
});

$f3->route('GET /login', function ($f3) use ($authController) {
    // Redirect ke dashboard jika sudah login
    if ($f3->get('SESSION.user_id')) {
        $role = $f3->get('SESSION.role');
        if ($role === 'admin') { $f3->reroute('/admin/dashboard'); }
        elseif ($role === 'mitra') { $f3->reroute('/mitra/dashboard'); }
        elseif ($role === 'keuangan') { $f3->reroute('/keuangan/dashboard'); }
        elseif ($role === 'sertifikasi') { $f3->reroute('/sertifikasi/dashboard'); }
        else { $f3->reroute('/beranda'); }
    }
    if ($f3->get('GET.success')) {
        $f3->set('success', $f3->get('GET.success'));
    }

    $html = file_get_contents('ui/otentikasi.html');
    if ($f3->get('success')) {
        $successHtml = '<div class="alert alert-success">' . $f3->get('success') . '</div>';
        $html = str_replace('<!-- LOGIN_ERROR -->', $successHtml, $html);
    }

    echo $authController->renderPageWithCsrf($html);
});

$f3->route('GET /register', function ($f3) {
    $f3->reroute('/auth');
});

$f3->route('POST /register', function ($f3) use ($authController) {
    $authController->processRegister($f3);
});

$f3->route('POST /check-username', function ($f3) use ($authController) {
    $authController->checkUsername($f3);
});

$f3->route('POST /check-phone', function ($f3) use ($authController) {
    $authController->checkPhone($f3);
});

$f3->route('POST /check-email', function ($f3) use ($authController) {
    $authController->checkEmail($f3);
});

$f3->route('GET /activate', function ($f3) use ($authController) {
    $authController->showActivationPage($f3);
});

$f3->route('POST /activate/verify', function ($f3) use ($authController) {
    $authController->verifyActivationOTP($f3);
});

$f3->route('POST /activate/resend', function ($f3) use ($authController) {
    $authController->resendActivationOTP($f3);
});

$f3->route('POST /login', function ($f3) use ($authController) {
    $authController->processLogin($f3);
});

$f3->route('GET|POST /forgot-password', function ($f3) use ($authController) {
    $authController->processForgotPassword($f3);
});

$f3->route('GET /reset-password', function ($f3) use ($authController) {
    $authController->showResetPassword($f3);
});

$f3->route('POST /reset-password', function ($f3) use ($authController) {
    $authController->processResetPassword($f3);
});

$f3->route('GET /logout', function ($f3) use ($authController) {
    $authController->logout($f3);
});

$f3->route('POST /validate-login', function ($f3) use ($authController) {
    $authController->validateLogin($f3);
});

$f3->route('POST /api/send-otp', function ($f3) use ($authController) {
    $authController->sendLoginOtp($f3);
});

$f3->route('POST /api/send-otp-email', function ($f3) use ($authController) {
    $authController->sendLoginOtpEmail($f3);
});

$f3->route('POST /api/verify-otp', function ($f3) use ($authController) {
    $authController->verifyLoginOtp($f3);
});

$f3->route('GET /dashboard', function ($f3) use ($dashboardPelangganController) {
    header('Location: ' . $f3->get('BASE') . '/beranda');
    exit;
});

$f3->route('GET /dashboard/', function ($f3) use ($dashboardPelangganController) {
    header('Location: ' . $f3->get('BASE') . '/beranda');
    exit;
});

$f3->route('GET /beranda', function ($f3) use ($dashboardPelangganController) {
    $dashboardPelangganController->index($f3, 'beranda');
});

$f3->route('GET /home', function ($f3) {
    $f3->reroute('/beranda');
});

$f3->route('GET /services', function ($f3) use ($dashboardPelangganController) {
    $dashboardPelangganController->index($f3, 'services');
});

$f3->route('GET /kuesioner', function ($f3) use ($dashboardPelangganController) {
    $dashboardPelangganController->index($f3, 'kuesioner');
});

$f3->route('POST /dashboard/kuesioner/submit', function ($f3) use ($dashboardPelangganController) {
    $dashboardPelangganController->submitKuesioner($f3);
});

$f3->route('GET /detailorder', function ($f3) use ($dashboardPelangganController) {
    $dashboardPelangganController->index($f3, 'detailorder');
});

$f3->route('GET /detailorder-kalibrasi', function ($f3) use ($dashboardPelangganController) {
    $dashboardPelangganController->index($f3, 'detailorder-kalibrasi');
});

$f3->route('GET /order_detail', function ($f3) {
    $f3->reroute('/detailorder');
});

$f3->route('GET /api/sertifikasi/berkas', function ($f3) use ($dashboardPelangganController) {
    $dashboardPelangganController->getBerkasSertifikasi($f3);
});

$f3->route('GET /api/sertifikasi/download-berkas', function ($f3) use ($dashboardPelangganController) {
    $dashboardPelangganController->getDownloadBerkas($f3);
});

$f3->route('POST /dashboard/sertifikasi/berkas/upload', function ($f3) use ($dashboardPelangganController) {
    $dashboardPelangganController->uploadBerkasSertifikasi($f3);
});

$f3->route('GET /detailorderserti', function ($f3) use ($dashboardPelangganController) {
    $dashboardPelangganController->index($f3, 'detailorderserti');
});

$f3->route('GET /sertifikasi-layanan', function ($f3) use ($dashboardPelangganController) {
    $dashboardPelangganController->index($f3, 'sertifikasi');
});


$f3->route('GET /permohonanpengecualian', function ($f3) use ($dashboardPelangganController) {
    $dashboardPelangganController->index($f3, 'permohonanpengecualian');
});

$f3->route('GET /permohonanalat', function ($f3) use ($dashboardPelangganController) {
    $dashboardPelangganController->index($f3, 'permohonanalat');
});

// =========================================================
// ALAT ROUTES - Customer
// =========================================================
$f3->route('GET /dashboard/alat/list', function ($f3) use ($permohonanAlatController) {
    $permohonanAlatController->getPermohonanList($f3);
});

$f3->route('GET /dashboard/alat/cetak', function ($f3) use ($permohonanAlatController) {
    $permohonanAlatController->cetakPermohonan($f3);
});

$f3->route('POST /dashboard/alat/order/save', function ($f3) use ($permohonanAlatController) {
    $permohonanAlatController->savePermohonanOrder($f3);
});

$f3->route('POST /dashboard/alat/order/delete', function ($f3) use ($permohonanAlatController) {
    $permohonanAlatController->deletePermohonanOrder($f3);
});

$f3->route('POST /dashboard/alat/item/save', function ($f3) use ($permohonanAlatController) {
    $permohonanAlatController->savePermohonanItem($f3);
});

$f3->route('POST /dashboard/alat/item/delete', function ($f3) use ($permohonanAlatController) {
    $permohonanAlatController->deletePermohonanItem($f3);
});

$f3->route('POST /dashboard/alat/dokumen/upload', function ($f3) use ($permohonanAlatController) {
    $permohonanAlatController->uploadDokumenPermohonan($f3);
});

$f3->route('GET /dashboard/alat/dokumen/list', function ($f3) use ($permohonanAlatController) {
    $permohonanAlatController->getDokumenList($f3);
});

$f3->route('POST /dashboard/alat/dokumen/reupload', function ($f3) use ($permohonanAlatController) {
    $permohonanAlatController->reuploadDokumen($f3);
});

$f3->route('POST /dashboard/alat/upload-langsung', function ($f3) use ($permohonanAlatController) {
    $permohonanAlatController->uploadLangsung($f3);
});

$f3->route('GET /pembayaran', function ($f3) use ($dashboardPelangganController) {
    $dashboardPelangganController->index($f3, 'pembayaran');
});

$f3->route('POST /dashboard/pembayaran/upload', function ($f3) use ($pembayaranController) {
    $pembayaranController->uploadPembayaran($f3);
});

$f3->route('GET /api/pembayaran/riwayat', function ($f3) use ($pembayaranController) {
    $pembayaranController->getRiwayat($f3);
});

$f3->route('POST /api/pembayaran/delete', function ($f3) use ($pembayaranController) {
    $pembayaranController->deletePembayaran($f3);
});

$f3->route('GET /kesanggupan', function ($f3) use ($dashboardPelangganController) {
    $dashboardPelangganController->index($f3, 'kesanggupan');
});

$f3->route('GET /keluhan', function ($f3) use ($dashboardPelangganController) {
    $dashboardPelangganController->index($f3, 'keluhan');
});


$f3->route('POST /dashboard/api/kesanggupan/upload', function ($f3) use ($kesanggupanPembayaranController) {
    $kesanggupanPembayaranController->uploadKesanggupan($f3);
});

$f3->route('GET /dashboard/api/kesanggupan/riwayat', function ($f3) use ($kesanggupanPembayaranController) {
    $kesanggupanPembayaranController->getRiwayatKesanggupan($f3);
});

$f3->route('POST /dashboard/api/kesanggupan/delete', function ($f3) use ($kesanggupanPembayaranController) {
    $kesanggupanPembayaranController->deleteKesanggupan($f3);
});

$f3->route('GET /dashboard/kesanggupan/download-template', function ($f3) use ($kesanggupanPembayaranController) {
    $kesanggupanPembayaranController->downloadTemplateKesanggupan($f3);
});

$f3->route('GET /dashboard/api/kesanggupan/status', function ($f3) use ($kesanggupanPembayaranController) {
    $kesanggupanPembayaranController->getKesanggupanStatus($f3);
});

$f3->route('GET /dashboard/api/kesanggupan-pembayaran', function ($f3) use ($kesanggupanPembayaranController) {
    $kesanggupanPembayaranController->getTemplateInfo($f3);
});

// =========================================================
// KELUHAN ROUTES - Customer
// =========================================================
$f3->route('POST /dashboard/keluhan/submit', function ($f3) use ($keluhanController) {
    $keluhanController->submit($f3);
});

$f3->route('GET /dashboard/keluhan/list', function ($f3) use ($keluhanController) {
    $keluhanController->getChatList($f3);
});

$f3->route('GET /dashboard/keluhan/chat/@id', function ($f3) use ($keluhanController) {
    $keluhanController->getMessages($f3);
});

$f3->route('POST /dashboard/keluhan/chat/send', function ($f3) use ($keluhanController) {
    $keluhanController->sendMessage($f3);
});

$f3->route('POST /dashboard/keluhan/chat/clear', function ($f3) use ($keluhanController) {
    $keluhanController->clearMessages($f3);
});

$f3->route('GET /dashboard/keluhan/orders', function ($f3) use ($keluhanController) {
    $keluhanController->getOrders($f3);
});

$f3->route('DELETE /dashboard/keluhan/delete/@id', function ($f3) use ($keluhanController) {
    $keluhanController->delete($f3);
});

$f3->route('GET /dashboard/keluhan/history', function ($f3) use ($keluhanController) {
    $keluhanController->getList($f3);
});

// =========================================================
// LAYANAN ROUTES - Customer
// =========================================================
$f3->route('POST /dashboard/layanan/submit', function ($f3) use ($layananController) {
    $layananController->submit($f3);
});

$f3->route('GET /dashboard/layanan/list', function ($f3) use ($layananController) {
    $layananController->getList($f3);
});

$f3->route('GET /dashboard/layanan/chat/@id', function ($f3) use ($layananController) {
    $layananController->getMessages($f3);
});

$f3->route('POST /dashboard/layanan/chat/send', function ($f3) use ($layananController) {
    $layananController->sendMessage($f3);
});

$f3->route('POST /dashboard/layanan/chat/clear', function ($f3) use ($layananController) {
    $layananController->clearMessages($f3);
});

$f3->route('GET /dashboard/layanan/categories', function ($f3) use ($layananController) {
    $layananController->getCategories($f3);
});

// =========================================================
// KELUHAN / LAYANAN ROUTES - Mitra
// =========================================================
$f3->route('GET /mitra/api/keluhan', function ($f3) use ($keluhanController) {
    $keluhanController->getComplaints($f3);
});

$f3->route('POST /mitra/api/keluhan/reply', function ($f3) use ($keluhanController) {
    $keluhanController->sendMessage($f3);
});

$f3->route('POST /mitra/api/keluhan/resolve', function ($f3) {
    header('Content-Type: application/json');
    $db = $f3->get('DB');
    $id = $f3->get('POST.complaint_id');
    $mitra_id = $f3->get('SESSION.user_id');
    if (!$id) { echo json_encode(['success' => false, 'message' => 'ID tidak valid']); return; }
    try {
        $db->exec("UPDATE complaints SET status='Resolved', updated_at=NOW() WHERE id=?", $id);
        echo json_encode(['success' => true]);
    } catch (Exception $e) { echo json_encode(['success' => false, 'message' => $e->getMessage()]); }
});

$f3->route('POST /mitra/api/keluhan/delete', function ($f3) {
    header('Content-Type: application/json');
    $db = $f3->get('DB');
    $id = $f3->get('POST.complaint_id');
    if (!$id) { echo json_encode(['success' => false, 'message' => 'ID tidak valid']); return; }
    try {
        $db->exec("DELETE FROM complaint_chats WHERE complaint_id=?", $id);
        $db->exec("DELETE FROM complaints WHERE id=?", $id);
        echo json_encode(['success' => true]);
    } catch (Exception $e) { echo json_encode(['success' => false, 'message' => $e->getMessage()]); }
});

$f3->route('POST /mitra/api/keluhan/clear', function ($f3) {
    header('Content-Type: application/json');
    $db = $f3->get('DB');
    $id = $f3->get('POST.complaint_id');
    if (!$id) { echo json_encode(['success' => false, 'message' => 'ID tidak valid']); return; }
    try {
        $db->exec("DELETE FROM complaint_chats WHERE complaint_id=?", $id);
        echo json_encode(['success' => true]);
    } catch (Exception $e) { echo json_encode(['success' => false, 'message' => $e->getMessage()]); }
});

// Layanan API - Mitra
$f3->route('GET /mitra/api/layanan', function ($f3) use ($layananController) {
    $layananController->getServices($f3);
});

$f3->route('POST /mitra/api/layanan/reply', function ($f3) use ($layananController) {
    $layananController->replyService($f3);
});

$f3->route('POST /mitra/api/layanan/resolve', function ($f3) use ($layananController) {
    $layananController->resolveService($f3);
});

$f3->route('POST /mitra/api/layanan/delete', function ($f3) use ($layananController) {
    $layananController->deleteService($f3);
});

$f3->route('POST /mitra/api/layanan/clear', function ($f3) use ($layananController) {
    $layananController->clearMessagesMitra($f3);
});

$f3->route('GET /exemption', function ($f3) {
    $f3->reroute('/permohonanpengecualian');
});

$f3->route('GET /dashboard/section/@section', function ($f3) use ($dashboardPelangganController) {
    $dashboardPelangganController->loadSection($f3);
});

$f3->route('GET /image/profile/@id', function ($f3) use ($profileController) {
    $profileController->getProfilePhoto($f3);
});

$f3->route('GET /dashboard/invoice/@filename', function ($f3) use ($dashboardPelangganController) {
    $dashboardPelangganController->getInvoice($f3);
});

$f3->route('GET|POST /dashboard/profile', function ($f3) use ($profileController) {
    $profileController->profile($f3);
});

$f3->route('GET /myprofile', function ($f3) use ($dashboardPelangganController) {
    $dashboardPelangganController->index($f3, 'profile');
});

$f3->route('GET /perusahaan', function ($f3) use ($dashboardPelangganController) {
    $dashboardPelangganController->index($f3, 'perusahaan');
});

$f3->route('POST /dashboard/pengaduan/submit', function ($f3) use ($pengaduanController) {
    $pengaduanController->submit($f3);
});

$f3->route('GET /dashboard/pengaduan/list', function ($f3) use ($pengaduanController) {
    $pengaduanController->getList($f3);
});

$f3->route('GET /dashboard/pengaduan/chat/@id', function ($f3) use ($pengaduanController) {
    $pengaduanController->getMessages($f3);
});

$f3->route('POST /dashboard/pengaduan/chat/send', function ($f3) use ($pengaduanController) {
    $pengaduanController->sendMessage($f3);
});

$f3->route('POST /dashboard/pengaduan/chat/clear', function ($f3) use ($pengaduanController) {
    $pengaduanController->clearMessages($f3);
});

$f3->route('GET /dashboard/pengaduan/topics', function ($f3) use ($pengaduanController) {
    $pengaduanController->getTopics($f3);
});

$f3->route('GET /dashboard/pengaduan/orders', function ($f3) use ($pengaduanController) {
    $pengaduanController->getOrders($f3);
});

$f3->route('POST /dashboard/pengaduan/update', function ($f3) use ($pengaduanController) {
    $pengaduanController->update($f3);
});

$f3->route('DELETE /dashboard/pengaduan/delete/@id', function ($f3) use ($pengaduanController) {
    $pengaduanController->delete($f3);
});

$f3->route('GET /dashboard/pengaduan/history', function ($f3) use ($pengaduanController) {
    $pengaduanController->getHistory($f3);
});

$f3->route('GET /dashboard/pengecualian/hs-code', function ($f3) use ($permohonanPengecualianController) {
    $permohonanPengecualianController->getHsCodes($f3);
});

$f3->route('GET /dashboard/pengecualian/list', function ($f3) use ($permohonanPengecualianController) {
    $permohonanPengecualianController->getPengecualianList($f3);
});

$f3->route('POST /dashboard/pengecualian/order/save', function ($f3) use ($permohonanPengecualianController) {
    $permohonanPengecualianController->savePengecualianOrder($f3);
});

$f3->route('POST /dashboard/pengecualian/order/delete', function ($f3) use ($permohonanPengecualianController) {
    $permohonanPengecualianController->deletePengecualianOrder($f3);
});

$f3->route('POST /dashboard/pengecualian/item/save', function ($f3) use ($permohonanPengecualianController) {
    $permohonanPengecualianController->savePengecualianItem($f3);
});

$f3->route('POST /dashboard/pengecualian/item/delete', function ($f3) use ($permohonanPengecualianController) {
    $permohonanPengecualianController->deletePengecualianItem($f3);
});

$f3->route('GET /dashboard/pengecualian/cetak-pdf', function ($f3) use ($permohonanPengecualianController) {
    $permohonanPengecualianController->cetakPdfPengecualian($f3);
});

$f3->route('POST /dashboard/pengecualian/dokumen/upload', function ($f3) use ($permohonanPengecualianController) {
    $permohonanPengecualianController->uploadPengecualianDokumen($f3);
});

$f3->route('GET /dashboard/pengecualian/dokumen/list', function ($f3) use ($permohonanPengecualianController) {
    $permohonanPengecualianController->getPengecualianDokumenList($f3);
});

$f3->route('POST /dashboard/pengecualian/dokumen/reupload', function ($f3) use ($permohonanPengecualianController) {
    $permohonanPengecualianController->reuploadPengecualianDokumen($f3);
});

$f3->route('GET /api/wilayah/provinsi', function ($f3) use ($profileController) {
    $profileController->getWilayahProvinsi($f3);
});

$f3->route('GET /api/wilayah/kabupaten', function ($f3) use ($profileController) {
    $profileController->getWilayahKabupaten($f3);
});

$f3->route('GET /api/wilayah/kecamatan', function ($f3) use ($profileController) {
    $profileController->getWilayahKecamatan($f3);
});

$f3->route('GET /dashboard/notifikasi/belum-dibaca', function ($f3) use ($notifikasiController) {
    $notifikasiController->getUnreadNotifications($f3);
});

$f3->route('POST /dashboard/notifikasi/tandai-dibaca', function ($f3) use ($notifikasiController) {
    $notifikasiController->markNotificationRead($f3);
});

$f3->route('GET /admin/api/stats', function ($f3) use ($adminController) {
    $adminController->getStats($f3);
});

$f3->route('GET /admin/api/tracking', function ($f3) use ($adminController) {
    $adminController->trackingHistory($f3);
});

$f3->route('GET /admin/api/blocked-ips', function ($f3) use ($adminController) {
    $adminController->getBlockedIPs($f3);
});

$f3->route('POST /admin/ip/block', function ($f3) use ($adminController) {
    $adminController->blockIP($f3);
});

$f3->route('POST /admin/ip/unblock', function ($f3) use ($adminController) {
    $adminController->unblockIP($f3);
});

$f3->route('GET /admin/api/profile-changes', function ($f3) use ($adminController) {
    $adminController->getProfileChanges($f3);
});

$f3->route('POST /admin/profile/update', function ($f3) use ($adminController) {
    $adminController->updateProfile($f3);
});

$f3->route('GET /admin/api/customers', function ($f3) use ($adminController) {
    $adminController->getCustomers($f3);
});

$f3->route('GET /admin/api/complaints', function ($f3) use ($adminController) {
    $adminController->getComplaints($f3);
});

$f3->route('POST /admin/api/complaints/reply', function ($f3) use ($adminController) {
    $adminController->replyComplaint($f3);
});

$f3->route('GET /admin/api/pegawai', function ($f3) use ($adminController) {
    $adminController->getStaffList($f3);
});

$f3->route('POST /admin/api/pegawai/update-role', function ($f3) use ($adminController) {
    $adminController->updateStaffRole($f3);
});

$f3->route('GET /mitra', function ($f3) {
    $f3->reroute('/mitra/dashboard');
});
$f3->route('GET /mitra/dashboard', function ($f3) use ($mitraController) {
    $mitraController->index($f3, 'ringkasan');
});
$f3->route('GET /mitra/customervalidation', function ($f3) use ($mitraController) {
    $mitraController->index($f3, 'validasi-pelanggan');
});

$f3->route('GET /mitra/exemption-docs', function ($f3) use ($mitraController) {
    $mitraController->index($f3, 'pengecualian-dokumen');
});
$f3->route('GET /mitra/announcements', function ($f3) use ($mitraController) {
    $mitraController->index($f3, 'pemberitahuan');
});
$f3->route('GET /mitra/permohonan-alat', function ($f3) use ($mitraController) {
    $mitraController->index($f3, 'permohonan-alat');
});


$f3->route('GET /mitra/kuesioner-pelanggan', function ($f3) use ($mitraController) {
    $mitraController->index($f3, 'kuesioner-pelanggan');
});
$f3->route('GET /mitra/layanan-pelanggan', function ($f3) use ($mitraController) {
    $mitraController->index($f3, 'layanan-pelanggan');
});
$f3->route('GET /mitra/kelola-pembayaran', function ($f3) use ($mitraController) {
    $mitraController->index($f3, 'kelola-pembayaran');
});
$f3->route('GET /mitra/kesanggupan-pembayaran', function ($f3) use ($mitraController) {
    $mitraController->index($f3, 'kesanggupan-pembayaran');
});

$f3->route('GET /mitra/api/stats', function ($f3) use ($mitraController) {
    $mitraController->getStats($f3);
});

$f3->route('GET /mitra/load/@section', function ($f3, $params) use ($mitraController) {
    $mitraController->loadSection($f3, $params['section']);
});

$f3->route('GET /mitra/api/permohonan-alat/all', function ($f3) use ($mitraController) {
    $mitraController->getAllPermohonan($f3);
});

$f3->route('GET /mitra/api/permohonan-alat/details/@id', function ($f3) use ($mitraController) {
    $mitraController->getPermohonanDetails($f3);
});

$f3->route('POST /mitra/api/permohonan-alat/update', function ($f3) use ($mitraController) {
    $mitraController->updatePermohonanStatus($f3);
});

$f3->route('GET /keuangan', function ($f3) {
    $f3->reroute('/keuangan/dashboard');
});

// Specific keuangan routes must come BEFORE the generic /keuangan/@section route
$f3->route('GET /keuangan/api/stats', function ($f3) use ($keuanganController) {
    $keuanganController->getStats($f3);
});

$f3->route('GET /keuangan/api/payments', function ($f3) use ($keuanganController) {
    $keuanganController->getPayments($f3);
});

$f3->route('GET /keuangan/api/payments/download/@id', function ($f3) use ($keuanganController) {
    $keuanganController->downloadPayment($f3);
});

$f3->route('POST /keuangan/api/payments/verify', function ($f3) use ($keuanganController) {
    $keuanganController->verifyPayment($f3);
});

$f3->route('POST /keuangan/api/payments/cancel-verify', function ($f3) use ($keuanganController) {
    $keuanganController->cancelVerifyPayment($f3);
});

$f3->route('POST /keuangan/api/payments/reject', function ($f3) use ($keuanganController) {
    $keuanganController->rejectPayment($f3);
});

$f3->route('POST /keuangan/api/payments/update', function ($f3) use ($keuanganController) {
    $keuanganController->updatePayment($f3);
});

$f3->route('POST /keuangan/api/update-account', function ($f3) use ($keuanganController) {
    $keuanganController->updateAccount($f3);
});

$f3->route('GET /keuangan/load/@section', function ($f3, $params) use ($keuanganController) {
    $keuanganController->loadSection($f3, $params['section']);
});

$f3->route('GET /keuangan/api/users', function ($f3) use ($keuanganController) {
    $keuanganController->getKeuanganUsers($f3);
});

$f3->route('POST /keuangan/api/users/add', function ($f3) use ($keuanganController) {
    $keuanganController->addKeuanganUser($f3);
});

$f3->route('POST /keuangan/api/users/delete', function ($f3) use ($keuanganController) {
    $keuanganController->deleteKeuanganUser($f3);
});

$f3->route('GET /keuangan/api/users/@id', function ($f3) use ($keuanganController) {
    $keuanganController->getKeuanganUser($f3);
});

$f3->route('POST /keuangan/api/users/update', function ($f3) use ($keuanganController) {
    $keuanganController->updateKeuanganUser($f3);
});


// Keuangan Kesanggupan Pembayaran Routes
$f3->route('GET /keuangan/api/kesanggupan-pembayaran', function ($f3) use ($keuanganController) {
    $keuanganController->getTemplateKesanggupan($f3);
});

$f3->route('POST /keuangan/api/kesanggupan-pembayaran/upload', function ($f3) use ($keuanganController) {
    $keuanganController->uploadTemplateKesanggupan($f3);
});

$f3->route('GET /keuangan/api/kesanggupan/uploads', function ($f3) use ($keuanganController) {
    $keuanganController->getKesanggupanUploads($f3);
});

$f3->route('POST /keuangan/api/kesanggupan/validate', function ($f3) use ($keuanganController) {
    $keuanganController->validateKesanggupan($f3);
});

$f3->route('GET /keuangan/api/kesanggupan/download/@id', function ($f3) use ($keuanganController) {
    $keuanganController->downloadCustomerKesanggupan($f3);
});

$f3->route('GET /keuangan/api/kesanggupan/view/@id', function ($f3) use ($keuanganController) {
    $keuanganController->viewCustomerKesanggupan($f3);
});

$f3->route('GET /keuangan/template/@jenis', function ($f3) use ($keuanganController) {
    $keuanganController->downloadTemplate($f3);
});

// Generic keuangan section route - must be LAST among keuangan routes
$f3->route('GET /keuangan/dashboard', function ($f3) use ($keuanganController) {
    $keuanganController->index($f3, 'ringkasan');
});
$f3->route('GET /keuangan/@section', function ($f3, $params) use ($keuanganController) {
    $keuanganController->index($f3, $params['section']);
});

$f3->route('GET /mitra/api/pengecualian/dokumen/all', function ($f3) use ($mitraController) {
    $mitraController->getPengecualianDokumenAll($f3);
});

$f3->route('GET /mitra/api/pengecualian/dokumen/download/@id', function ($f3) use ($mitraController) {
    $mitraController->downloadPengecualianDokumen($f3);
});

$f3->route('POST /mitra/api/pengecualian/dokumen/update', function ($f3) use ($mitraController) {
    $mitraController->updatePengecualianDokumenStatus($f3);
});

$f3->route('GET /mitra/api/pengecualian/user-details/@user_id', function ($f3) use ($mitraController) {
    $mitraController->getUserPengecualianDetails($f3);
});

$f3->route('GET /mitra/api/pending-customers', function ($f3) use ($mitraController) {
    $mitraController->getPendingCustomers($f3);
});

$f3->route('POST /mitra/api/validate-customer', function ($f3) use ($mitraController) {
    $mitraController->validateCustomer($f3);
});

$f3->route('GET /mitra/api/sil-customers', function ($f3) use ($mitraController) {
    $mitraController->getSilCustomers($f3);
});



$f3->route('GET /mitra/api/announcements', function ($f3) use ($mitraController) {
    $mitraController->getAnnouncements($f3);
});

$f3->route('POST /mitra/api/announcements/save', function ($f3) use ($mitraController) {
    $mitraController->saveAnnouncement($f3);
});

$f3->route('POST /mitra/api/announcements/delete', function ($f3) use ($mitraController) {
    $mitraController->deleteAnnouncement($f3);
});

$f3->route('POST /mitra/api/announcements/toggle', function ($f3) use ($mitraController) {
    $mitraController->toggleAnnouncement($f3);
});

$f3->route('GET /mitra/api/complaints', function ($f3) use ($mitraController) {
    $mitraController->getComplaints($f3);
});

$f3->route('POST /mitra/api/complaints/reply', function ($f3) use ($mitraController) {
    $mitraController->replyComplaint($f3);
});

$f3->route('POST /mitra/api/complaints/resolve', function ($f3) use ($mitraController) {
    $mitraController->resolveComplaint($f3);
});

$f3->route('POST /mitra/api/complaints/delete', function ($f3) use ($mitraController) {
    $mitraController->deleteComplaint($f3);
});

$f3->route('POST /mitra/api/complaints/clear', function ($f3) use ($mitraController) {
    $mitraController->clearMessages($f3);
});

$f3->route('POST /mitra/api/update-account', function ($f3) use ($mitraController) {
    $mitraController->updateAccount($f3);
});

$f3->route('GET /admin/@file', function ($f3, $params) use ($adminController) {
    $adminController->loadSection($f3, $params['file']);
});

// Routes API Mitra: Kelola Pembayaran & Template Kesanggupan
$f3->route('GET /mitra/api/pembayaran', function ($f3) use ($mitraController) {
    $mitraController->getKelolaPembayaran($f3);
});

$f3->route('GET /mitra/api/kesanggupan-pembayaran', function ($f3) use ($mitraController) {
    $mitraController->getTemplateKesanggupan($f3);
});

$f3->route('POST /mitra/api/kesanggupan-pembayaran/upload', function ($f3) use ($mitraController) {
    $mitraController->uploadTemplateKesanggupan($f3);
});

$f3->route('GET /mitra/api/kesanggupan-pembayaran/download', function ($f3) use ($mitraController) {
    $mitraController->downloadTemplateKesanggupan($f3);
});

$f3->route('GET /mitra/template/@jenis', function ($f3) use ($mitraController) {
    $mitraController->downloadTemplate($f3);
});

// Kesanggupan Uploads Routes (Mitra mengelola upload dari pelanggan)
$f3->route('GET /mitra/api/kesanggupan/uploads', function ($f3) use ($mitraController) {
    $mitraController->getKesanggupanUploads($f3);
});

// Template Permohonan Alat
$f3->route('GET /mitra/api/template-permohonan-alat', function ($f3) use ($mitraController) {
    $mitraController->getTemplatePermohonanAlat($f3);
});
$f3->route('POST /mitra/api/template-permohonan-alat/upload', function ($f3) use ($mitraController) {
    $mitraController->uploadTemplatePermohonanAlat($f3);
});

$f3->route('POST /mitra/api/kesanggupan/validate', function ($f3) use ($mitraController) {
    $mitraController->validateKesanggupan($f3);
});

$f3->route('GET /mitra/api/kesanggupan/download/@id', function ($f3) use ($mitraController) {
    $mitraController->downloadCustomerKesanggupan($f3);
});

$f3->route('GET /mitra/api/kesanggupan/view/@id', function ($f3) use ($mitraController) {
    $mitraController->viewCustomerKesanggupan($f3);
});

$f3->route('GET /mitra/@file', function ($f3, $params) use ($mitraController) {
    $mitraController->loadSection($f3, $params['file']);
});

// PELANGGAN
$f3->route('POST /dashboard/keluhan/submit', function ($f3) use ($keluhanController) { $keluhanController->submit($f3); });
$f3->route('GET /dashboard/keluhan/list', function ($f3) use ($keluhanController) { $keluhanController->getChatList($f3); });
$f3->route('GET /dashboard/keluhan/messages/@id', function ($f3) use ($keluhanController) { $keluhanController->getMessages($f3); });
$f3->route('GET /dashboard/keluhan/chat/@id', function ($f3) use ($keluhanController) { $keluhanController->getMessages($f3); });
$f3->route('POST /dashboard/keluhan/chat/send', function ($f3) use ($keluhanController) { $keluhanController->sendMessage($f3); });
$f3->route('GET /dashboard/keluhan/chat/general', function ($f3) use ($keluhanController) { $keluhanController->getGeneralChat($f3); });
$f3->route('GET /dashboard/keluhan/chat/unread', function ($f3) use ($keluhanController) { $keluhanController->getUnreadCount($f3); });
$f3->route('POST /dashboard/keluhan/chat/clear', function ($f3) use ($keluhanController) { $keluhanController->clearMessages($f3); });
$f3->route('GET /dashboard/keluhan/topics', function ($f3) use ($keluhanController) { $keluhanController->getTopics($f3); });
$f3->route('GET /dashboard/keluhan/orders', function ($f3) use ($keluhanController) { $keluhanController->getOrders($f3); });
$f3->route('POST /dashboard/keluhan/update', function ($f3) use ($keluhanController) { $keluhanController->update($f3); });
$f3->route('DELETE /dashboard/keluhan/delete/@id', function ($f3) use ($keluhanController) { $keluhanController->delete($f3); });
$f3->route('GET /dashboard/keluhan/history', function ($f3) use ($keluhanController) { $keluhanController->getList($f3); });

$f3->route('GET /layanan', function ($f3) use ($dashboardPelangganController) { header('Location: ' . $f3->get('BASE') . '/keluhan?new=layanan'); exit; });
$f3->route('POST /dashboard/layanan/submit', function ($f3) use ($layananController) { $layananController->submit($f3); });
$f3->route('GET /dashboard/layanan/list', function ($f3) use ($layananController) { $layananController->getList($f3); });
$f3->route('GET /dashboard/layanan/chat/@id', function ($f3) use ($layananController) { $layananController->getMessages($f3); });
$f3->route('POST /dashboard/layanan/chat/send', function ($f3) use ($layananController) { $layananController->sendMessage($f3); });
$f3->route('POST /dashboard/layanan/chat/clear', function ($f3) use ($layananController) { $layananController->clearMessages($f3); });
$f3->route('GET /dashboard/layanan/categories', function ($f3) use ($layananController) { $layananController->getCategories($f3); });

// ADMIN/MITRA
$f3->route('GET /mitra/keluhan-pelanggan', function ($f3) { $f3->reroute('/mitra/layanan-pelanggan'); });
$f3->route('GET /mitra/layanan-pelanggan', function ($f3) use ($mitraController) { $mitraController->index($f3, 'layanan-pelanggan'); });

$f3->route('GET /mitra/api/kuesioner/settings', function ($f3) use ($mitraController) { $mitraController->getKuesionerSettings($f3); });
$f3->route('POST /mitra/api/kuesioner/settings/update', function ($f3) use ($mitraController) { $mitraController->updateKuesionerSettings($f3); });
$f3->route('GET /mitra/api/kuesioner', function ($f3) use ($mitraController) { $mitraController->getKuesionerResults($f3); });
$f3->route('GET /mitra/api/kuesioner/@id', function ($f3) use ($mitraController) { $mitraController->getKuesionerDetail($f3); });
$f3->route('GET /mitra/kuesioner/excel/@token', function ($f3) use ($mitraController) { $mitraController->downloadKuesionerExcel($f3); });
$f3->route('GET /mitra/kuesioner/rekap/excel', function ($f3) use ($mitraController) { $mitraController->downloadKuesionerRekap($f3); });

$f3->route('GET /mitra/api/keluhan', function ($f3) use ($mitraController) { $mitraController->getComplaints($f3); });
$f3->route('POST /mitra/api/keluhan/reply', function ($f3) use ($mitraController) { $mitraController->replyComplaint($f3); });
$f3->route('POST /mitra/api/keluhan/resolve', function ($f3) use ($mitraController) { $mitraController->resolveComplaint($f3); });
$f3->route('POST /mitra/api/keluhan/delete', function ($f3) use ($mitraController) { $mitraController->deleteComplaint($f3); });
$f3->route('POST /mitra/api/keluhan/clear', function ($f3) use ($mitraController) { $mitraController->clearMessages($f3); });

$f3->route('GET /mitra/api/layanan', function ($f3) use ($layananController) { $layananController->getServices($f3); });
$f3->route('POST /mitra/api/layanan/reply', function ($f3) use ($layananController) { $layananController->replyService($f3); });
$f3->route('POST /mitra/api/layanan/resolve', function ($f3) use ($layananController) { $layananController->resolveService($f3); });
$f3->route('POST /mitra/api/layanan/delete', function ($f3) use ($layananController) { $layananController->deleteService($f3); });
$f3->route('POST /mitra/api/layanan/clear', function ($f3) use ($layananController) { $layananController->clearMessagesMitra($f3); });


// =========================================================
// SERTIFIKASI
// =========================================================
$f3->route('GET /sertifikasi', function ($f3) {
    $f3->reroute('/sertifikasi/dashboard');
});

$f3->route('GET /sertifikasi/dashboard', function ($f3) use ($sertifikasiController) {
    $sertifikasiController->index($f3, 'ringkasan');
});

$f3->route('GET /sertifikasi/api/stats', function ($f3) use ($sertifikasiController) {
    $sertifikasiController->getStats($f3);
});

$f3->route('GET /sertifikasi/api/berkas-pendaftaran/files', function ($f3) use ($sertifikasiController) {
    $sertifikasiController->getBerkasFiles($f3);
});

$f3->route('GET /sertifikasi/api/berkas-pendaftaran', function ($f3) use ($sertifikasiController) {
    $sertifikasiController->getBerkasPendaftaran($f3);
});

$f3->route('GET /sertifikasi/api/berkas-verifikasi', function ($f3) use ($sertifikasiController) {
    $sertifikasiController->getBerkasVerifikasi($f3);
});

$f3->route('GET /sertifikasi/api/berkas-verifikasi/files', function ($f3) use ($sertifikasiController) {
    $sertifikasiController->getBerkasVerifikasiFiles($f3);
});


$f3->route('POST /sertifikasi/api/berkas-verifikasi/update-status', function ($f3) use ($sertifikasiController) {
    $sertifikasiController->updateStatusVerifikasi($f3);
});

$f3->route('POST /sertifikasi/api/berkas-verifikasi/cancel-file', function ($f3) use ($sertifikasiController) {
    $sertifikasiController->cancelBerkasVTP($f3);
});

$f3->route('POST /sertifikasi/api/update-account', function ($f3) use ($sertifikasiController) {
    $sertifikasiController->updateAccount($f3);
});

$f3->route('POST /sertifikasi/api/berkas-pendaftaran/update-status', function ($f3) use ($sertifikasiController) {
    $sertifikasiController->updateStatusBerkas($f3);
});

$f3->route('GET /sertifikasi/api/pemberitahuan', function ($f3) use ($sertifikasiController) {
    $sertifikasiController->getPemberitahuan($f3);
});

$f3->route('POST /sertifikasi/api/pemberitahuan/save', function ($f3) use ($sertifikasiController) {
    $sertifikasiController->savePemberitahuan($f3);
});

// Public API — dapat diakses pelanggan tanpa role sertifikasi
$f3->route('GET /api/serti-pemberitahuan', function ($f3) use ($sertifikasiController) {
    $sertifikasiController->getPemberitahuan($f3);
});

$f3->route('GET /sertifikasi/load/@section', function ($f3, $params) use ($sertifikasiController) {
    $sertifikasiController->loadSection($f3, $params['section']);
});

$f3->route('GET /sertifikasi/@section', function ($f3, $params) use ($sertifikasiController) {
    $sertifikasiController->index($f3, $params['section']);
});

// =========================================================================
// HELPER
// =========================================================================
function doc_make_token(string $filename, string $type, int $ttl = 3600): string {
    $f3     = \Base::instance();
    $secret = $f3->get('DOC_SECRET');
    $payload = base64_encode(json_encode([
        'f'   => $filename,   // nama file
        't'   => $type,       // tipe dokumen
        'exp' => time() + $ttl,
    ]));
    // Ganti karakter base64 yang tidak aman untuk URL
    $payload  = strtr($payload, '+/=', '-_~');
    $sig      = hash_hmac('sha256', $payload, $secret);
    return $payload . '.' . $sig;
}

function doc_verify_token(string $token): ?array {
    $f3     = \Base::instance();
    $secret = $f3->get('DOC_SECRET');

    $parts = explode('.', $token, 2);
    if (count($parts) !== 2) return null;

    [$payload, $sig] = $parts;

    // Cek tanda tangan (signature) — cegah manipulasi token
    $expected = hash_hmac('sha256', $payload, $secret);
    if (!hash_equals($expected, $sig)) return null;

    $data = json_decode(base64_decode(strtr($payload, '-_~', '+/=')), true);
    if (!$data) return null;

    // Cek apakah token sudah kedaluwarsa
    if (time() > ($data['exp'] ?? 0)) return null;

    return $data;
}

function doc_resolve_folder(string $type): ?string {
    $f3 = \Base::instance();
    $map = [
        // ── PENGUJIAN ──────────────────────────────────────────────────────
        'bapc'             => $f3->get('SIPPISAN_BAPC_URL'),
        'lhu'              => $f3->get('SIPPISAN_LHU_URL'),
        'invoice'          => $f3->get('SIPPISAN_INVOICE_URL'),
        'surat-penawaran'  => $f3->get('SURAT_PENAWARAN_DIR'),
        'kalib-invoice'    => $f3->get('SIKALIB_INVOICE_URL'),
        'kalib-lhu-pertama'  => rtrim($f3->get('SIKALIB_LEMBAR_HASIL_URL') ?? '', '/') . '/pertama/',
        'kalib-lhu-amandemen'=> rtrim($f3->get('SIKALIB_LEMBAR_HASIL_URL') ?? '', '/') . '/amandemen/',

        // ── PENGUJIAN PENGECUALIAN ─────────────────────────────────────────
        'pengecualian-bapc'            => $f3->get('PENGECUALIAN_BAPC_URL'),
        'pengecualian-lhu'             => $f3->get('PENGECUALIAN_LHU_URL'),
        'pengecualian-invoice'         => $f3->get('PENGECUALIAN_INVOICE_URL'),
        'pengecualian-surat-penawaran' => $f3->get('PENGECUALIAN_SURAT_PENAWARAN_DIR'),
        'pengecualian-dokumen'         => $f3->get('PENGECUALIAN_DOKUMEN_URL'),
        'pengecualian-dokumen-lokal'   => 'public/uploads/pengecualian/',
        'pengecualian-berkas'          => $f3->get('PENGECUALIAN_BERKAS_URL') ?: 'public/uploads/pengecualian/',
        'pengecualian-mitra-sp'        => 'public/uploads/pengecualian/mitra/',
        'pengecualian-mitra-inv'       => 'public/uploads/pengecualian/mitra/',
        'payment-upload'               => 'public/uploads/payments/',
        'kesanggupan-upload'           => 'public/uploads/kesanggupan/',

        // ── SERTIFIKASI ────────────────────────────────────────────────────
        'serti-surat-penawaran'              => $f3->get('SERTI_SURAT_PENAWARAN_URL'),
        'serti-tanda-terima'                 => $f3->get('SERTI_TANDA_TERIMA_PERMOHONAN_URL'),
        'serti-laporan-ketidaksesuaian'      => $f3->get('SERTI_LAPORAN_KETIDAKSESUAIAN_URL'),
        'serti-laporan-audit'                => $f3->get('SERTI_LAPORAN_AUDIT_URL'),
        'serti-verifikasi-perbaikan-1'       => $f3->get('SERTI_VERIFIKASI_TINDAKAN_PERBAIKAN_1_URL'),
        'serti-verifikasi-perbaikan-2'       => $f3->get('SERTI_VERIFIKASI_TINDAKAN_PERBAIKAN_2_URL'),
        'serti-verifikasi-perbaikan-3'       => $f3->get('SERTI_VERIFIKASI_TINDAKAN_PERBAIKAN_3_URL'),
        'serti-sertifikat'                   => $f3->get('SERTI_SERTIFIKAT_URL'),
        'serti-lhu'                          => $f3->get('SERTI_LHU_URL'),
        'serti-pendaftaran'                  => 'public/uploads/sertifikasi/berkas-pendaftaran/',
        'serti-verifikasi-pelanggan'         => 'public/uploads/sertifikasi/berkas-verifikasi-perbaikan/',
        
        // ── ALAT PENGUJIAN ─────────────────────────────────────────────────
        'permohonan-alat'                    => 'public/uploads/permohonan-alat/dokumen/',

        // ── KALIBRASI ──────────────────────────────────────────────────────
        'kalib-permohonan-alat'              => $f3->get('KALIB_PERMOHONAN_ALAT_URL') ?: 'public/uploads/permohonan-alat/dokumen/',
    ];
    return isset($map[$type]) ? rtrim($map[$type], '/') . '/' : null;
}

$f3->route('GET /dokumen/view/@token', function ($f3, $params) {
    $token = $params['token'];
    $data  = doc_verify_token($token);

    if (!$data) {
        header('HTTP/1.1 403 Forbidden');
        echo '<div style="font-family:sans-serif;text-align:center;padding:60px">
                <h2 style="color:#e11d48">Akses Ditolak</h2>
                <p>Token tidak valid atau sudah kedaluwarsa.</p>
              </div>';
        exit;
    }

    $folder   = doc_resolve_folder($data['t']);
    $filename = basename($data['f']); // cegah path traversal

    if (!$folder || empty($filename)) {
        header('HTTP/1.1 404 Not Found');
        echo 'Dokumen tidak ditemukan.';
        exit;
    }

    // Tentukan apakah folder adalah URL eksternal atau path lokal
    if (strpos($folder, 'http') === 0) {
        $fileUrl = $folder . rawurlencode($filename);
        
        // Gunakan stream context agar bisa membaca dari HTTPS walau ada masalah sertifikat (opsional)
        $context = stream_context_create([
            'http' => ['method' => 'GET'],
            'ssl'  => ['verify_peer' => false, 'verify_peer_name' => false]
        ]);

        $headers = @get_headers($fileUrl, 1, $context);
        if (!$headers || strpos($headers[0], '200') === false) {
            header('HTTP/1.1 404 Not Found');
            echo 'Dokumen di server tidak ditemukan.';
            exit;
        }

        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $mimeMap = [
            'pdf'  => 'application/pdf',
            'png'  => 'image/png',
            'jpg'  => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'zip'  => 'application/zip',
        ];
        $mime = $mimeMap[$ext] ?? 'application/octet-stream';

        while (ob_get_level()) ob_end_clean();
        header('Content-Type: ' . $mime);
        
        // Jika ada Content-Length dari server asal, teruskan
        $contentLength = is_array($headers['Content-Length'] ?? null) ? $headers['Content-Length'][0] : ($headers['Content-Length'] ?? null);
        if ($contentLength) {
            header('Content-Length: ' . $contentLength);
        }
        
        // Sembunyikan nama file 
        header('Content-Disposition: inline; filename="dokumen.' . $ext . '"'); 
        
        // Stream file ke browser (true proxy)
        @readfile($fileUrl, false, $context);
        exit;
    }

    // File ada secara lokal — baca dan stream
    $filepath = __DIR__ . '/' . ltrim($folder, '/') . $filename;

    if (!is_file($filepath)) {
        header('HTTP/1.1 404 Not Found');
        echo 'Dokumen tidak ditemukan.';
        exit;
    }

    $ext = strtolower(pathinfo($filepath, PATHINFO_EXTENSION));
    $mimeMap = [
        'pdf'  => 'application/pdf',
        'png'  => 'image/png',
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'zip'  => 'application/zip',
    ];
    $mime = $mimeMap[$ext] ?? 'application/octet-stream';

    while (ob_get_level()) ob_end_clean();
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . filesize($filepath));
    header('Content-Disposition: inline; filename="dokumen.' . $ext . '"'); // sembunyikan nama asli
    readfile($filepath);
    exit;
});

// =========================================================================
// Download Sertifikat Kalibrasi (Data Alat)
// =========================================================================
$f3->route('GET /api/kalibrasi/sertifikat', function ($f3) {
    $token = $f3->get('GET.t');
    if (!$token) {
        header('HTTP/1.1 403 Forbidden');
        echo "Akses Ditolak. Token tidak ditemukan.";
        exit;
    }

    $data = doc_verify_token($token);
    if (!$data || $data['t'] !== 'kalib_sertifikat') {
        header('HTTP/1.1 403 Forbidden');
        echo "Akses Ditolak. Token tidak valid.";
        exit;
    }

    // payload = id_arsip - id_penawaran - id_tarif
    $parts = explode('-', $data['f']);
    if (count($parts) !== 3) {
        header('HTTP/1.1 400 Bad Request');
        echo "Data tidak lengkap.";
        exit;
    }

    $idArsip = $parts[0];
    $idPenawaran = $parts[1];
    $idTarif = $parts[2];

    // Opsi B: Ambil nama file dari database sikalib2024 secara mandiri
    $dbKalib = $f3->get('DB_KALIB');
    if (!$dbKalib) {
        header('HTTP/1.1 500 Internal Server Error');
        echo "Database kalibrasi tidak terhubung.";
        exit;
    }

    try {
        $result = $dbKalib->exec('SELECT alat_sertifikat FROM tb_penawaran_alat WHERE id_penawaran_alat = ? LIMIT 1', [$idPenawaran]);
    } catch (\Exception $e) {
        header('HTTP/1.1 500 Internal Server Error');
        echo "Gagal query ke tabel alat kalibrasi.";
        exit;
    }

    if (empty($result) || empty($result[0]['alat_sertifikat'])) {
        header('HTTP/1.1 404 Not Found');
        echo "Dokumen sertifikat belum tersedia (belum diunggah) untuk alat ini.";
        exit;
    }

    $filename = $result[0]['alat_sertifikat'];
    
    $baseUrl = $f3->get('SIKALIB_SERTIFIKAT_URL') ?: '';
    
    // Tentukan apakah folder adalah URL eksternal atau path lokal
    if (strpos($baseUrl, 'http') === 0) {
        $fileUrl = rtrim($baseUrl, '/') . '/' . rawurlencode($filename);
        
        $context = stream_context_create([
            'http' => ['method' => 'GET'],
            'ssl'  => ['verify_peer' => false, 'verify_peer_name' => false]
        ]);

        $headers = @get_headers($fileUrl, 1, $context);
        if (!$headers || strpos($headers[0], '200') === false) {
            header('HTTP/1.1 404 Not Found');
            echo 'Dokumen fisik di server Sikalib tidak ditemukan: ' . htmlspecialchars($filename);
            exit;
        }

        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $mimeMap = [
            'pdf'  => 'application/pdf',
            'png'  => 'image/png',
            'jpg'  => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'zip'  => 'application/zip',
        ];
        $mime = $mimeMap[$ext] ?? 'application/octet-stream';

        while (ob_get_level()) ob_end_clean();
        header('Content-Type: ' . $mime);
        
        $contentLength = is_array($headers['Content-Length'] ?? null) ? $headers['Content-Length'][0] : ($headers['Content-Length'] ?? null);
        if ($contentLength) {
            header('Content-Length: ' . $contentLength);
        }
        
        header('Content-Disposition: inline; filename="sertifikat-kalibrasi.' . $ext . '"'); 
        @readfile($fileUrl, false, $context);
        exit;
    } else {
        // Fallback jika baseUrl berupa folder lokal (contoh: public/uploads/sertifikat/)
        $filepath = __DIR__ . '/' . ltrim($baseUrl, '/') . $filename;

        if (!is_file($filepath)) {
            header('HTTP/1.1 404 Not Found');
            echo 'Dokumen tidak ditemukan di folder lokal.';
            exit;
        }

        $ext = strtolower(pathinfo($filepath, PATHINFO_EXTENSION));
        $mimeMap = [
            'pdf'  => 'application/pdf',
        ];
        $mime = $mimeMap[$ext] ?? 'application/octet-stream';

        while (ob_get_level()) ob_end_clean();
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . filesize($filepath));
        header('Content-Disposition: inline; filename="sertifikat-kalibrasi.' . $ext . '"');
        readfile($filepath);
        exit;
    }
});

function order_make_token($orderId, string $section, string $source = 'sil', string $sample = '', int $ttl = 7200): string {
    $f3     = \Base::instance();
    $secret = $f3->get('DOC_SECRET');
    $payload = base64_encode(json_encode([
        'id'  => (string) $orderId,
        'sec' => $section,
        'src' => $source,
        'smp' => $sample,
        'exp' => time() + $ttl,
    ]));
    $payload = strtr($payload, '+/=', '-_~');
    $sig     = hash_hmac('sha256', $payload, $secret);
    return $payload . '.' . $sig;
}

function order_verify_token(string $token): ?array {
    $f3     = \Base::instance();
    $secret = $f3->get('DOC_SECRET');

    $parts = explode('.', $token, 2);
    if (count($parts) !== 2) return null;

    [$payload, $sig] = $parts;

    $expected = hash_hmac('sha256', $payload, $secret);
    if (!hash_equals($expected, $sig)) return null;

    $data = json_decode(base64_decode(strtr($payload, '-_~', '+/=')), true);
    if (!$data) return null;

    if (time() > ($data['exp'] ?? 0)) return null;

    return $data;
}

// =========================================================================
// ROUTE: Terbitkan order
// =========================================================================
$f3->route('GET /api/order/token', function ($f3) use ($db) {
    header('Content-Type: application/json; charset=utf-8');

    $userId = $f3->get('SESSION.user_id');
    if (!$userId) {
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        return;
    }

    $orderId = trim($f3->get('GET.id') ?? '');
    $section = trim($f3->get('GET.section') ?? 'detailorder');
    $source  = trim($f3->get('GET.src') ?? 'sil');
    $sample  = trim($f3->get('GET.sample') ?? '');

    if (empty($orderId)) {
        echo json_encode(['success' => false, 'message' => 'ID tidak valid']);
        return;
    }

    // Validasi
    try {
        $userRow    = $db->exec('SELECT id_customer FROM users WHERE id = ? LIMIT 1', [$userId]);
        $idCustomer = (int) ($userRow[0]['id_customer'] ?? 0);

        if ($idCustomer > 0) {
            if ($source === 'kalib') {
                // Cek kepemilikan di sikalib2024 → tb_arsip_kalibrasi
                $dbKalib = $f3->get('DB_KALIB');
                if ($dbKalib) {
                    $check = $dbKalib->exec(
                        "SELECT id_arsip_kalibrasi FROM tb_arsip_kalibrasi
                         WHERE id_arsip_kalibrasi = ? AND id_customer = ? AND showhide = 'show' LIMIT 1",
                        [$orderId, $idCustomer]
                    );
                    if (empty($check)) {
                        echo json_encode(['success' => false, 'message' => 'Order tidak ditemukan']);
                        return;
                    }
                }
            } else {
                // Cek kepemilikan di sil2020 → tb_arsip_detail
                $dbSil = $f3->get('DB_SIL');
                if ($dbSil) {
                    $check = $dbSil->exec(
                        'SELECT id_arsip FROM tb_arsip_detail WHERE id_arsip = ? AND id_customer = ? LIMIT 1',
                        [$orderId, $idCustomer]
                    );
                    if (empty($check)) {
                        echo json_encode(['success' => false, 'message' => 'Order tidak ditemukan']);
                        return;
                    }
                }
            }
        }
        // Jika id_customer = 0, izinkan tapi tidak ada data yang tampil
    } catch (\Exception $e) {
        // Jika tabel tidak ada atau query gagal, tetap terbitkan token (fallback)
    }

    $token = order_make_token($orderId, $section, $source, $sample);
    echo json_encode(['success' => true, 'token' => $token]);
});

// =========================================================================
// ROUTE: Resolve  order
// =========================================================================

$f3->route('GET /api/order/resolve', function ($f3) {
    header('Content-Type: application/json; charset=utf-8');

    $userId = $f3->get('SESSION.user_id');
    if (!$userId) {
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        return;
    }

    $token = trim($f3->get('GET.t') ?? '');
    $data  = order_verify_token($token);

    if (!$data) {
        echo json_encode(['success' => false, 'message' => 'Token tidak valid atau sudah kedaluwarsa']);
        return;
    }

    echo json_encode([
        'success' => true,
        'id'      => $data['id'],
        'section' => $data['sec'],
        'source'  => $data['src'],
        'sample'  => $data['smp'],
    ]);
});

function formatTanggalIndo($tanggal) {
    if (empty($tanggal)) return '-';
    $waktu = strtotime($tanggal);
    if (!$waktu) return '-';
    $bulan = [
        1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
        'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'
    ];
    return date('d', $waktu) . ' ' . $bulan[(int)date('m', $waktu)] . ' ' . date('Y', $waktu);
}


// =========================================================
// NOTIFIKASI
// =========================================================
$f3->route('GET /dashboard/notifikasi/belum-dibaca', function ($f3) use ($notifikasiController) {
    $notifikasiController->getUnreadNotifications($f3);
});
$f3->route('POST /dashboard/notifikasi/tandai-dibaca', function ($f3) use ($notifikasiController) {
    $notifikasiController->markNotificationRead($f3);
});
$f3->route('POST /api/notifikasi/trigger', function ($f3) use ($notifikasiController) {
    $notifikasiController->triggerNotification($f3);
});

$f3->run();
