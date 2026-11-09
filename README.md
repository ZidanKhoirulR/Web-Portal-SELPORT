# Web Portal SELPORT

[![PHP Version](https://img.shields.io/badge/PHP-8.x-blue.svg)](https://www.php.net/)
[![Framework](https://img.shields.io/badge/Framework-Fat--Free%203.9-orange.svg)](https://fatfreeframework.com/)

**Web Portal SELPORT (Portal Pelayanan Selulosa)** is a public service web portal engineered for **Balai Besar Standardisasi dan Pelayanan Jasa Industri Selulosa (BBSPJIS)** under the **Ministry of Industry, Republic of Indonesia**. The platform unifies the delivery of industrial testing, certification, and calibration services, providing transparent tracking, automated document processing, and multi-channel communication.

---

## 🛠️ Technology Stack

- **Backend:** PHP 8.x
- **Framework:** [Fat-Free Framework (F3) v3.9](https://fatfreeframework.com/)
- **Database:** MySQL / MariaDB (Multi-database support: `portal_selport`, `sil2020`, `serti2020`, `sikalib2024`)
- **PDF Generation:** TCPDF
- **Email Service:** PHPMailer
- **Frontend:** Responsive HTML5 / CSS3 / JavaScript
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
