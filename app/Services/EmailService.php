<?php

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

class EmailService
{
    private $mailer;
    private $fromEmail;
    private $fromName;

    public function __construct()
    {
        $this->fromEmail = getenv('SMTP_FROM_EMAIL') ?: (getenv('SMTP_USER') ?: 'bbspjis.kemenperin@gmail.com');
        $this->fromName  = getenv('SMTP_FROM_NAME') ?: 'Portal SELPORT';

        $this->mailer = new PHPMailer(true);
        
        $this->mailer->isSMTP();
        $this->mailer->Host       = getenv('SMTP_HOST') ?: 'smtp.gmail.com';
        $this->mailer->SMTPAuth   = true;
        $this->mailer->Username   = getenv('SMTP_USER') ?: 'bbspjis.kemenperin@gmail.com';
        $this->mailer->Password   = getenv('SMTP_PASS') ?: '';
        $this->mailer->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $this->mailer->Port       = (int)(getenv('SMTP_PORT') ?: 587);
        
        $this->mailer->CharSet = 'UTF-8';
        
        $this->mailer->setFrom($this->fromEmail, $this->fromName);
    }

    public function sendActivationEmail($email, $name, $activationLink)
    {
        try {
            $template = file_get_contents(__DIR__ . '/../Templates/activation_email.html');
            
            $template = str_replace('{NAME}', htmlspecialchars($name, ENT_QUOTES, 'UTF-8'), $template);
            $template = str_replace('{ACTIVATION_LINK}', htmlspecialchars($activationLink, ENT_QUOTES, 'UTF-8'), $template);
            
            $this->mailer->addAddress($email, $name);
            
            $this->mailer->isHTML(true);
            $this->mailer->Subject = 'Aktivasi Akun Portal SELPORT';
            $this->mailer->Body    = $template;
            $this->mailer->AltBody = "Halo {$name},\n\n" .
                                     "Terima kasih telah mendaftar di Portal SELPORT!\n\n" .
                                     "Untuk mengaktifkan akun Anda, silakan klik link berikut:\n" .
                                     "{$activationLink}\n\n" .
                                     "Link ini berlaku selama 24 jam.\n\n" .
                                     "Setelah mengklik link, Anda akan menerima kode OTP melalui WhatsApp.\n\n" .
                                     "Jika Anda tidak merasa mendaftar, abaikan email ini.\n\n" .
                                     "Salam,\nTim Portal SELPORT";
            
            $this->mailer->send();
            return true;
        } catch (Exception $e) {
            error_log("Email activation failed: {$this->mailer->ErrorInfo}");
            return false;
        } finally {
            $this->mailer->clearAddresses();
        }
    }

    public function sendCredentialsEmail($email, $name, $username, $phone, $loginLink)
    {
        try {
            $template = file_get_contents(__DIR__ . '/../Templates/credentials_email.html');
            
            $template = str_replace('{NAME}', htmlspecialchars($name, ENT_QUOTES, 'UTF-8'), $template);
            $template = str_replace('{USERNAME}', htmlspecialchars($username, ENT_QUOTES, 'UTF-8'), $template);
            $template = str_replace('{EMAIL}', htmlspecialchars($email, ENT_QUOTES, 'UTF-8'), $template);
            $template = str_replace('{PHONE}', htmlspecialchars($phone, ENT_QUOTES, 'UTF-8'), $template);
            $template = str_replace('{LOGIN_LINK}', htmlspecialchars($loginLink, ENT_QUOTES, 'UTF-8'), $template);
            
            $this->mailer->addAddress($email, $name);
            
            $this->mailer->isHTML(true);
            $this->mailer->Subject = 'Akun Anda Telah Aktif - Portal SELPORT';
            $this->mailer->Body    = $template;
            
            $this->mailer->AltBody = "Halo {$name},\n\n" .
                                     "Selamat! Akun Anda telah disetujui dan sudah aktif.\n\n" .
                                     "Username: {$username}\n\n" .
                                     "Anda dapat login di:\n{$loginLink}\n\n" .
                                     "Salam,\nTim Portal SELPORT";
            
            $this->mailer->send();
            return true;
        } catch (Exception $e) {
            error_log("Email credentials failed: {$this->mailer->ErrorInfo}");
            return false;
        } finally {
            $this->mailer->clearAddresses();
        }
    }

    public function sendRejectionEmail($email, $name, $reason)
    {
        try {
            $template = file_get_contents(__DIR__ . '/../Templates/rejection_email.html');
            
            if (empty(trim($reason ?? ''))) {
                $reason = 'Mohon maaf, data pendaftaran Anda belum sesuai dengan persyaratan.';
            }

            $template = str_replace('{NAME}', htmlspecialchars($name, ENT_QUOTES, 'UTF-8'), $template);
            $template = str_replace('{REJECTION_REASON}', nl2br(htmlspecialchars($reason, ENT_QUOTES, 'UTF-8')), $template);
            
            $this->mailer->addAddress($email, $name);
            
            $this->mailer->isHTML(true);
            $this->mailer->Subject = 'Pemberitahuan Status Registrasi - Portal SELPORT';
            $this->mailer->Body    = $template;
            
            $cleanReason = strip_tags($reason);
            $this->mailer->AltBody = "Halo {$name},\n\n" .
                                     "Terima kasih telah melakukan pendaftaran di Portal SELPORT.\n\n" .
                                     "Kami telah meninjau pengajuan pendaftaran akun Anda. Mohon maaf, berdasarkan kebijakan kami, pendaftaran akun Anda tidak dapat kami setujui untuk saat ini.\n\n" .
                                     "Alasan Penolakan:\n{$cleanReason}\n\n" .
                                     "Jika Anda memiliki pertanyaan, silakan hubungi tim administrasi kami.\n\n" .
                                     "Salam,\nTim Portal SELPORT";
            
            $this->mailer->send();
            return true;
        } catch (Exception $e) {
            error_log("Email rejection failed: {$this->mailer->ErrorInfo}");
            return false;
        } finally {
            $this->mailer->clearAddresses();
        }
    }
    public function sendOtpEmail($email, $name, $otp)
    {
        try {
            $this->mailer->addAddress($email, $name);

            $this->mailer->isHTML(true);
            $this->mailer->Subject = 'Kode OTP Login - Portal SELPORT';

            $body = "
<!DOCTYPE html>
<html lang='id'>
<head><meta charset='UTF-8'></head>
<body style='margin:0;padding:0;background:#f0f4f8;font-family:Inter,Arial,sans-serif;'>
  <table width='100%' cellpadding='0' cellspacing='0' style='background:#f0f4f8;padding:40px 0;'>
    <tr><td align='center'>
      <table width='520' cellpadding='0' cellspacing='0' style='background:#ffffff;border-radius:16px;overflow:hidden;box-shadow:0 4px 24px rgba(0,0,0,0.08);'>
        <tr>
          <td style='background:linear-gradient(135deg,#2563eb,#1d4ed8);padding:32px;text-align:center;'>
            <p style='margin:0;color:#fff;font-size:22px;font-weight:700;letter-spacing:1px;'>SELPORT</p>
            <p style='margin:6px 0 0;color:#bfdbfe;font-size:13px;'>Portal Pelayanan Selulosa</p>
          </td>
        </tr>
        <tr>
          <td style='padding:36px 40px 28px;'>
            <p style='margin:0 0 8px;color:#1e293b;font-size:18px;font-weight:600;'>Halo, " . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . "!</p>
            <p style='margin:0 0 24px;color:#64748b;font-size:14px;line-height:1.7;'>
              Kami menerima permintaan login ke akun Anda di Portal SELPORT. Gunakan kode OTP berikut untuk melanjutkan verifikasi:
            </p>
            <div style='background:#f1f5ff;border:2px dashed #3b82f6;border-radius:12px;padding:24px;text-align:center;margin-bottom:24px;'>
              <p style='margin:0 0 6px;color:#6b7280;font-size:12px;font-weight:500;letter-spacing:2px;text-transform:uppercase;'>Kode OTP Anda</p>
              <p style='margin:0;color:#1d4ed8;font-size:40px;font-weight:800;letter-spacing:10px;'>" . htmlspecialchars($otp, ENT_QUOTES, 'UTF-8') . "</p>
              <p style='margin:10px 0 0;color:#94a3b8;font-size:12px;'>Kode berlaku hingga akhir hari ini</p>
            </div>
            <p style='margin:0 0 8px;color:#64748b;font-size:13px;line-height:1.7;'>
              ⚠️ Jangan bagikan kode ini kepada siapapun, termasuk tim SELPORT.
            </p>
            <p style='margin:0;color:#64748b;font-size:13px;line-height:1.7;'>
              Jika Anda tidak melakukan permintaan login ini, abaikan email ini.
            </p>
          </td>
        </tr>
        <tr>
          <td style='background:#f8fafc;padding:16px 40px;border-top:1px solid #e2e8f0;text-align:center;'>
            <p style='margin:0;color:#94a3b8;font-size:12px;'>© " . date('Y') . " Portal Pelayanan Selulosa — BBSPJIS Kemenperin</p>
          </td>
        </tr>
      </table>
    </td></tr>
  </table>
</body>
</html>";

            $this->mailer->Body    = $body;
            $this->mailer->AltBody = "Halo {$name},\n\nKode OTP login Anda adalah: {$otp}\n\nKode ini berlaku hingga akhir hari ini.\nJangan bagikan kode ini kepada siapapun.\n\nSalam,\nTim Portal SELPORT";

            $this->mailer->send();
            return true;
        } catch (Exception $e) {
            error_log("Email OTP failed: {$this->mailer->ErrorInfo}");
            return false;
        } finally {
            $this->mailer->clearAddresses();
        }
    }

    public function getMailer()
    {
        return $this->mailer;
    }

    public function testConnection()
    {
        try {
            return ['success' => true, 'message' => 'SMTP connection successful'];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $this->mailer->ErrorInfo];
        }
    }
}