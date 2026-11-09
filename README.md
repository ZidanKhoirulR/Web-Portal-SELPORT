# SELPORT — Public Service Web Portal (BBSPJIS)

[![PHP Version](https://img.shields.io/badge/PHP-8.x-blue.svg)](https://www.php.net/)
[![Framework](https://img.shields.io/badge/Framework-Fat--Free%203.9-orange.svg)](https://fatfreeframework.com/)
[![License](https://img.shields.io/badge/License-Proprietary-red.svg)](#license)

**SELPORT (Portal Pelayanan Selulosa)** is a comprehensive public service portal engineered for **Balai Besar Standardisasi dan Pelayanan Jasa Industri Selulosa (BBSPJIS)** under the **Ministry of Industry, Republic of Indonesia**. The platform unifies and digitizes the delivery of industrial testing, certification, and calibration services, providing customers and administrators with transparent tracking, automated document processing, and multi-channel communication.

---

## 🌟 Key Features

### 1. Unified Service Modules
- **Testing Services (Pengujian):** Online tracking for testing samples, testing reports (LHU), minutes of acceptance (BAPC), and invoices.
- **Certification Services (Sertifikasi):** End-to-end certification workflow including application submissions, audit reports, non-conformance reports, corrective action verifications, and digital certificate downloads.
- **Calibration Services (Kalibrasi):** Calibration service orders, instrument verification, and calibration certificate retrieval.
- **Service Exceptions (Permohonan Pengecualian):** Online submission and review workflow for exception requests.

### 2. Customer Dashboard & Self-Service
- Real-time order progress tracking with visual milestone steppers.
- Secure, token-signed document download links preventing unauthorized direct URL guessing.
- Interactive customer complaint and support chat channels with attachment support.
- User profile management with phone and email OTP verification.

### 3. Administrative Control & Security
- Role-based access control for administrative staff: Administrator, Finance, Certification, and Calibration units.
- User management, profile change audit logs, and transaction monitoring.
- Activity tracking service with IP-based rate limiting and administrative IP blocking.
- Customer satisfaction questionnaires and feedback analytics.

### 4. Multi-Channel Notifications
- **Email Notifications:** Automated transactional emails for account activation, credentials, and OTP verification via PHPMailer.
- **WhatsApp Gateway:** Real-time order status and notification alerts sent directly to registered phone numbers.

---

## 🛠️ Technology Stack

- **Backend:** PHP 8.x
- **Framework:** [Fat-Free Framework (F3) v3.9](https://fatfreeframework.com/)
- **Database:** MySQL / MariaDB (Supports multi-database connections: `portal_selport`, `sil2020`, `serti2020`, `sikalib2024`)
- **PDF Generation:** TCPDF
- **Email Service:** PHPMailer
- **Frontend:** Responsive HTML5 / CSS3 / JavaScript with modern dashboard layouts
- **Dependency Management:** Composer

---

## 📁 Project Structure

```text
selport/
├── app/
│   ├── Controllers/         # Application controllers (Auth, Dashboard, Admin, Services, etc.)
│   ├── Services/            # Business logic (Tracking, Notification, Email services)
│   └── Templates/           # Email HTML templates
├── config.cfg               # Base application endpoints and configuration
├── index.php                # Application bootstrap, routing, and database setup
├── public/
│   ├── avatar/              # Default user avatars
│   ├── css/                 # Stylesheets organized by module (admin, dashboard, mitra, etc.)
│   ├── images/              # Static branding and UI assets
│   ├── js/                  # Client-side JavaScript
│   └── uploads/             # User uploaded files (secured and git-ignored)
├── tmp/                     # Framework template cache and temporary storage
├── ui/                      # Fat-Free Framework template views
├── composer.json            # PHP dependencies
├── .env.example             # Template for environment configuration
└── .gitignore               # Excludes secrets, uploads, caches, and screenshots
```

---

## 🚀 Getting Started

### Prerequisites

Ensure you have the following installed on your server or local environment:
- **PHP >= 8.0** with extensions enabled: `pdo_mysql`, `curl`, `mbstring`, `openssl`, `gd`
- **MySQL / MariaDB >= 10.4**
- **Apache Web Server** with `mod_rewrite` enabled (e.g., XAMPP, WampServer, or native LAMP)
- **Composer** (PHP dependency manager)

### Installation Steps

1. **Clone the Repository:**
   ```bash
   git clone https://github.com/ZidanKhoirulR/selport-web-portal.git
   cd selport-web-portal
   ```

2. **Install Composer Dependencies:**
   ```bash
   composer install --no-dev --optimize-autoloader
   ```

3. **Configure Environment Variables:**
   Copy `.env.example` to create your local `.env` file:
   ```bash
   cp .env.example .env
   ```
   Open `.env` and fill in your database credentials, email SMTP credentials, and API keys:
   ```ini
   DB_HOST=localhost
   DB_PORT=3306
   DB_NAME=portal_selport
   DB_USER=your_db_user
   DB_PASS=your_db_password

   DOC_SECRET=generate_a_secure_64_character_hex_key

   SMTP_HOST=smtp.gmail.com
   SMTP_PORT=587
   SMTP_USER=your_email@domain.com
   SMTP_PASS=your_smtp_app_password

   WANOTIF_APIKEY=your_whatsapp_gateway_key
   ```

4. **Web Server Setup (Apache):**
   - Place the project folder in your web root (e.g., `htdocs/selport` or virtual host directory).
   - Ensure `mod_rewrite` is enabled in `httpd.conf`.
   - Ensure the `.htaccess` file is recognized and permissions allow write access to `tmp/` and `public/uploads/`.

5. **Access the Application:**
   - Open your browser and navigate to: `http://localhost/selport/`
   - Access the administrative portal at: `http://localhost/selport/auth`

---

## 🔒 Security Best Practices

- **Zero-Secret Commits:** All credentials, private tokens, and database passwords are kept outside version control via `.env`.
- **HMAC URL Token Verification:** Documents and order links use cryptographic hash verification (`DOC_SECRET`) to prevent unauthorized enumeration.
- **Upload Isolation:** The `public/uploads/` directory is strictly excluded from version control to protect customer data privacy and comply with data protection regulations.
- **IP Rate Limiting & Blocking:** Integrated tracking engine logs requests and enables immediate blocking of suspicious IP addresses.

---

## 📄 License

This software and its codebase are proprietary and developed for BBSPJIS (Ministry of Industry, Republic of Indonesia). Unauthorized copying, distribution, or public disclosure is prohibited.
