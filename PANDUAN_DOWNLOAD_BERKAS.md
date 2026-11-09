# Panduan Modifikasi Fitur Download Berkas Sertifikasi

Dokumen ini digunakan untuk mempermudah proses modifikasi sumber data (tabel, field, atau URL) pada fitur "Download Berkas" di halaman Detail Order Sertifikasi Pelanggan.

---

## 1. Merubah URL Server / Folder (Akses Eksternal)

Seluruh URL dasar (base URL) untuk masing-masing dokumen disimpan secara terpusat agar mudah diganti tanpa perlu menyentuh kode PHP. 

**File yang harus diubah:**
📄 `config.cfg` (berada di root folder project)

**Cara mengubah:**
Buka file tersebut dan cari bagian `[globals]` atau komentar `SERIFIKASI`. Anda akan menemukan konstanta-konstanta berikut:
```ini
SERTI_SURAT_PENAWARAN_URL="https://sippisan-bbs.kemenperin.go.id/..."
SERTI_INVOICE_AUDIT_URL="https://sippisan-bbs.kemenperin.go.id/..."
SERTI_TANDA_TERIMA_PERMOHONAN_URL="https://sippisan-bbs.kemenperin.go.id/..."
SERTI_LAPORAN_KETIDAKSESUAIAN_URL="https://sippisan-bbs.kemenperin.go.id/..."
SERTI_LAPORAN_AUDIT_URL="https://sippisan-bbs.kemenperin.go.id/..."
SERTI_VERIFIKASI_TINDAKAN_PERBAIKAN_1_URL="https://sippisan-bbs.kemenperin.go.id/..."
SERTI_SERTIFIKAT_URL="https://sippisan-bbs.kemenperin.go.id/..."
SERTI_LHU_URL="https://sippisan-bbs.kemenperin.go.id/..."
```
Ubah value di dalam tanda kutip ganda `""` dengan URL server atau folder yang baru. Sistem akan otomatis menggunakan URL ini saat pelanggan menekan tombol "Unduh".

---

## 2. Merubah Sumber Data (Tabel / Field Database)

Saat ini, **semua dokumen** disetel untuk membaca nilai field `nama_berkas_invoice` dari tabel `tb_arsip_sertifikasi_pembayaran` secara sementara agar tombol dapat diuji. Jika Anda ingin mengembalikan atau menyesuaikan tiap dokumen agar membaca dari tabel dan field aslinya di sistem SIPPISAN (database `serti2020`), Anda harus mengubah file controller.

**File yang harus diubah:**
📄 `app/Controllers/DashboardPelangganController.php`

**Lokasi Baris:**
🔍 Cari Method: `getDownloadBerkas()` (Mulai di sekitar baris **1899**)

### Langkah A: Tambahkan Query Baru
Posisikan kursor Anda di sekitar baris **1947 - 1980** (cari komentar `[TODO MENTOR] 1. TAMBAH QUERY DATABASE DI SINI`).

Tambahkan logika query baru untuk menarik nama file dari database `serti2020` (`$this->dbSerti`).
**Contoh untuk Sertifikat:**
```php
$namaSertifikat = null;
try {
    $rowSerti = $this->dbSerti->exec(
        "SELECT nama_file_sertifikat FROM tb_sertifikat_internal WHERE id_arsip_sertifikasi = ? LIMIT 1",
        [(int)$idArsip]
    );
    if (!empty($rowSerti)) $namaSertifikat = $rowSerti[0]['nama_file_sertifikat'];
} catch (\Exception $e) {}
```

### Langkah B: Pasangkan Variabel ke List Dokumen
Setelah membuat query di atas, gulir ke bawah ke sekitar baris **2003 - 2050** (cari komentar `[TODO MENTOR] 2. UBAH VARIABEL PADA ARRAY DI BAWAH INI`).

Cari array `$docs`. Pada bagian dokumen yang relevan, ganti variabel `$namaInvoice` menjadi variabel baru yang Anda buat di Langkah A (misal `$namaSertifikat`).

**Sebelum (Sementara):**
```php
[
    'label' => 'Sertifikat',
    'desc'  => 'Sertifikat kesesuaian produk',
    'file'  => $namaInvoice, // <-- GANTI INI
    'url'   => $namaInvoice ? $urlSertifikat . $namaInvoice : null, // <-- GANTI INI
],
```

**Sesudah (Final):**
```php
[
    'label' => 'Sertifikat',
    'desc'  => 'Sertifikat kesesuaian produk',
    'file'  => $namaSertifikat, // Menggunakan field asli
    'url'   => $namaSertifikat ? $urlSertifikat . $namaSertifikat : null, 
],
```

Dengan mengikuti dua langkah di atas, dokumen tersebut akan berstatus "Tersedia" hanya jika nama file benar-benar ada di database internal terkait.

---
**Catatan Khusus VTP:**
Variabel `$namaVtp1`, `$namaVtp2`, dan `$namaVtp3` sebenarnya sudah di-query dari `tb_arsip_sertifikasi_berkas`. Hanya perlu menukar `$namaInvoice` menjadi variabel-variabel tersebut di array `$docs` jika ingin mengembalikan VTP ke field aslinya.
