# Web Portal SELPORT

[![PHP Version](https://img.shields.io/badge/PHP-8.x-blue.svg)](https://www.php.net/)
[![Framework](https://img.shields.io/badge/Framework-Fat--Free%203.9-orange.svg)](https://fatfreeframework.com/)

---

## Overview

**Web Portal SELPORT (Portal Pelayanan Selulosa)** is a centralized digital public service portal developed for **Balai Besar Standardisasi dan Pelayanan Jasa Industri Selulosa (BBSPJIS)**, an echelon-II technical operating agency under the **Ministry of Industry, Republic of Indonesia**. 

BBSPJIS specializes in industrial standardization, research, testing, calibration, and product certification for pulp, paper, and cellulose derivative industries across Indonesia. SELPORT serves as the official digital gateway connecting industrial clients, businesses, and agency stakeholders to critical laboratory and certification services. The platform consolidates diverse operational domains—such as laboratory sample testing, ISO-aligned product certifications, equipment calibration, and fee settlement workflows—into an integrated, transparent, and auditable web environment.

---

## Case Study

### 1. Context and Problem Statement
Prior to the implementation of SELPORT, industrial clients engaging BBSPJIS for technical services navigated fragmented workflows across distinct departments:
- **Disparate Legacy Systems:** Testing operations, certification records, and calibration archives operated on independent, siloed database systems (`sil2020`, `serti2020`, and `sikalib2024`), preventing clients from viewing an aggregated progress report of their service orders.
- **Manual Document Distribution:** Crucial legal and operational documents—including Minutes of Acceptance (BAPC), Certificate of Analysis / Test Result Reports (LHU), calibration certificates, and billing invoices—required in-person retrieval or manual administrative dispatch, resulting in turnaround bottlenecks.
- **Data Privacy & Tampering Risks:** Unprotected direct links to sensitive test reports risked unauthorized enumeration and document exposure without strict cryptographic session binding.
- **Communication Gaps:** Industrial customers lacked real-time visibility into milestone transitions, leading to repetitive inquiries and administrative workload.

### 2. The Architectural Solution
SELPORT was architected as a lightweight, performant, and secure single-window service portal:
- **Multi-Database Federation:** A centralized service layer dynamically bridges multiple MySQL databases (`portal_selport`, `sil2020`, `serti2020`, `sikalib2024`), presenting a consolidated, unified dashboard to authenticated clients while preserving database autonomy.
- **Cryptographic Tokenized Document Access:** Direct document paths are strictly hidden behind HMAC-SHA256 signature tokens with configurable time-to-live (`DOC_SECRET`). Document downloads validate caller identity, order ownership, and signature integrity before streaming, preventing link forgery and unauthorized snooping.
- **Automated Multi-Channel Communication:** Integrated background notifications via PHPMailer (transactional email) and WhatsApp Gateway (instant order notifications and OTPs) ensure clients receive timely alerts at every review milestone.
- **Auditable Administrative Governance:** Granular role-based controls for staff across administration, finance, certification, and calibration units, backed by real-time request tracking, profile change logs, and IP blocking defense.

### 3. Business Impact and Outcomes
- **Operational Efficiency:** Eliminated repetitive status inquiries and reduced customer support touchpoints through intuitive real-time tracking.
- **Accelerated Document Turnaround:** Enabled instant, verifiable digital downloads of official LHUs, invoices, and certificates immediately upon official approval.
- **Enhanced Data Security:** Ensured government-standard document confidentiality, mitigating unauthorized file access through signed one-time retrieval tokens.
- **Higher Institutional Service Ratings:** Elevated client trust and transparency, providing domestic and multinational industrial partners with modern digital governance aligned with national SPBE standards.

---

## Technology Stack

- **Backend:** PHP 8.x
- **Framework:** [Fat-Free Framework (F3) v3.9](https://fatfreeframework.com/)
- **Database:** MySQL / MariaDB (Multi-database support: `portal_selport`, `sil2020`, `serti2020`, `sikalib2024`)
- **PDF Generation:** TCPDF
- **Email Service:** PHPMailer
- **Frontend:** Responsive HTML5 / CSS3 / JavaScript
- **Dependency Management:** Composer
