<?php

class DBKalibrasiController
{
    private $db;

    public function __construct($dbKalib)
    {
        $this->db = $dbKalib;
    }

    /**
     * Hitung jumlah order kalibrasi milik customer
     */
    public function getOrderCount($customerId)
    {
        $result = $this->db->exec(
            "SELECT COUNT(*) as total FROM tb_arsip_kalibrasi
             WHERE id_customer = ? AND showhide = 'show'",
            [$customerId]
        );
        return !empty($result) ? (int)($result[0]['total'] ?? 0) : 0;
    }

    /**
     * Ambil daftar tahun tersedia untuk filter dropdown
     */
    public function getAvailableYears($customerId)
    {
        return $this->db->exec(
            "SELECT DISTINCT RIGHT(TRIM(SUBSTRING_INDEX(nomor_order_lengkap, '/', -1)), 4) as thn
             FROM tb_arsip_kalibrasi
             WHERE id_customer = ? AND nomor_order_lengkap IS NOT NULL AND nomor_order_lengkap != '' AND showhide = 'show'
             ORDER BY thn DESC",
            [$customerId]
        ) ?: [];
    }

    /**
     * Ambil riwayat order untuk tabel beranda
     */
    public function getOrderHistory($customerId, $filterStatus = 'active', $filterTahun = '', $limit = 0)
    {
        $params   = [$customerId];
        $where    = "WHERE ak.id_customer = ? AND ak.showhide = 'show'";

        if ($filterStatus === 'completed') {
            $where .= " AND (ak.tanggal_selesai_realisasi IS NOT NULL AND ak.tanggal_selesai_realisasi != '0000-00-00')";
        } else {
            $where .= " AND (ak.tanggal_selesai_realisasi IS NULL OR ak.tanggal_selesai_realisasi = '0000-00-00')";
        }

        if ($filterTahun) {
            $where   .= " AND RIGHT(TRIM(SUBSTRING_INDEX(ak.nomor_order_lengkap, '/', -1)), 4) = ?";
            $params[] = $filterTahun;
        }

        $rows = $this->db->exec(
            "SELECT
                ak.id_arsip_kalibrasi,
                ak.id_customer,
                ak.nomor_order_lengkap,
                DATE_FORMAT(ak.tanggal_order, '%d-%m-%Y') as tanggal_order,
                ak.tanggal_order                          as tanggal_order_raw,
                ak.uraian_order,
                ak.struktural_biaya_kerjasama_total,
                ak.status_order_selesai,
                ak.tanggal_kalibrasi_berangkat,
                ak.tanggal_kalibrasi_pulang,
                ak.tanggal_selesai_realisasi,
                pk.pembayaran_tanggal_bayar
             FROM tb_arsip_kalibrasi ak
             LEFT JOIN tb_pembayaran_kalibrasi pk ON ak.id_arsip_kalibrasi = pk.id_arsip_kalibrasi
             $where
             ORDER BY ak.tanggal_order DESC" . ($limit > 0 ? " LIMIT " . (int)$limit : ""),
            $params
        );

        return $rows ?: [];
    }

    /**
     * Label status singkat untuk kolom tabel beranda
     */
    public function getSimpleStatusLabel($row)
    {
        if (!empty($row['tanggal_selesai_realisasi']) && $row['tanggal_selesai_realisasi'] !== '0000-00-00') {
            return 'Selesai';
        }
        if (!empty($row['tanggal_kalibrasi_pulang']) && $row['tanggal_kalibrasi_pulang'] !== '0000-00-00') {
            return 'Laboratorium Teknis';
        }
        if (!empty($row['tanggal_order']) && $row['tanggal_order_raw'] !== '0000-00-00') {
            return 'Pendaftaran';
        }
        if (!empty($row['pembayaran_tanggal_bayar']) && $row['pembayaran_tanggal_bayar'] !== '0000-00-00') {
            return 'Pembayaran';
        }
        return 'Pendaftaran';
    }

    /**
     * Ambil detail satu order kalibrasi
     */
    public function getOrderDetail($orderId)
    {
        $res = $this->db->exec(
            "SELECT ak.*, pk.pembayaran_tanggal_bayar 
             FROM tb_arsip_kalibrasi ak
             LEFT JOIN tb_pembayaran_kalibrasi pk ON ak.id_arsip_kalibrasi = pk.id_arsip_kalibrasi
             WHERE ak.id_arsip_kalibrasi = ? AND ak.showhide = 'show'
             LIMIT 1",
            [$orderId]
        );
        return !empty($res) ? $res[0] : null;
    }

    /**
     * Ambil tanggal lab teknis paling terakhir dari tb_arsip_kalibrasi_teknis
     */
    public function getTanggalLabTeknis($orderId)
    {
        try {
            $res = $this->db->exec(
                "SELECT MAX(tanggal_baca_teknisi) as tgl_labtek
                 FROM tb_arsip_kalibrasi_teknis
                 WHERE id_arsip_kalibrasi = ?",
                [$orderId]
            );
            return (!empty($res) && !empty($res[0]['tgl_labtek'])) ? $res[0]['tgl_labtek'] : null;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Ambil data alat dari tb_penawaran_alat
     */
    public function getDetailAlat($orderId)
    {
        try {
            $rows = $this->db->exec(
                "SELECT
                    pa.id_penawaran_alat as id,
                    ts.uraian_parameter as nama_alat,
                    pa.lhu_alat_merk_buatan as merk_alat,
                    pa.lhu_alat_type_model as tipe_alat,
                    pa.lhu_alat_no_seri as nomor_seri,
                    pa.id_jenis_pekerjaan,
                    mjp.jenis_pekerjaan,
                    pa.alat_sertifikat as lembar_hasil,
                    ts.tarif,
                    pa.lhu_nomor_order_lengkap_prefix,
                    pa.lhu_nomor_order_lengkap_postfix,
                    pa.lhu_nomor_order_postfix,
                    pa.status_alat_selesai,
                    pa.di_validasi_katim,
                    ts.id_tarif_simponi
                 FROM tb_penawaran_alat pa
                 LEFT JOIN mst_tarif_simponi ts ON pa.id_tarif_simponi = ts.id_tarif_simponi
                 LEFT JOIN mst_jenis_pekerjaan mjp ON pa.id_jenis_pekerjaan = mjp.id_jenis_pekerjaan
                 WHERE pa.id_arsip_kalibrasi = ? AND pa.showhide = 'show'
                 ORDER BY pa.lhu_nomor_order_postfix ASC",
                [$orderId]
            );
            return $rows ?: [];
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Ambil data arsip LHU terbaru per alat dari tb_arsip_lhu.
     * Untuk setiap id_penawaran_alat (milik order ini), pilih baris
     * dengan id_arsip_lhu tertinggi.
     *
     * @param int $orderId  id_arsip_kalibrasi
     * @return array  Map: id_penawaran_alat => ['id_arsip_lhu', 'nama_file_proses', 'proses_lhu']
     */
    public function getArsipLhu($orderId)
    {
        try {
            // Ambil daftar id_penawaran_alat untuk order ini dari tb_penawaran_alat,
            // lalu join ke tb_arsip_lhu dan pilih id_arsip_lhu terbesar per alat.
            $rows = $this->db->exec(
                "SELECT al.id_penawaran_alat,
                        al.id_arsip_lhu,
                        al.nama_file_proses,
                        al.proses_lhu
                 FROM tb_arsip_lhu al
                 INNER JOIN (
                     SELECT id_penawaran_alat, MAX(id_arsip_lhu) AS max_id
                     FROM tb_arsip_lhu
                     WHERE id_penawaran_alat IN (
                         SELECT id_penawaran_alat
                         FROM tb_penawaran_alat
                         WHERE id_arsip_kalibrasi = ?
                           AND showhide = 'show'
                     )
                     GROUP BY id_penawaran_alat
                 ) latest ON al.id_penawaran_alat = latest.id_penawaran_alat
                         AND al.id_arsip_lhu      = latest.max_id",
                [$orderId]
            );
            $result = [];
            foreach ($rows ?: [] as $r) {
                $result[$r['id_penawaran_alat']] = $r;
            }
            return $result;
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Ambil file invoice dari tb_pembayaran_kalibrasi
     */
    public function getInvoiceFiles($orderId)
    {
        try {
            $rows = $this->db->exec(
                "SELECT pembayaran_nama_berkas_pembayaran as invoice
                 FROM tb_pembayaran_kalibrasi
                 WHERE id_arsip_kalibrasi = ?
                 LIMIT 1",
                [$orderId]
            );
            return !empty($rows) ? $rows[0] : null;
        } catch (\Exception $e) {
            return null;
        }
    }
    /**
     * Ambil tanggal upload & validasi terbaru dari tb_log_upload_xls per alat
     */
    public function getLogUploadAlat($orderId)
    {
        try {
            $rows = $this->db->exec(
                "SELECT id_penawaran_alat,
                        MAX(tanggal_upload)   AS tanggal_upload,
                        MAX(tanggal_validasi) AS tanggal_validasi
                 FROM tb_log_upload_xls
                 WHERE id_arsip_kalibrasi = ?
                 GROUP BY id_penawaran_alat",
                [$orderId]
            );
            $result = [];
            foreach ($rows ?: [] as $r) {
                $result[$r['id_penawaran_alat']] = $r;
            }
            return $result;
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Mengambil daftar tanggal libur dari database sil2020
     */
    public function getHariLibur($startDate)
    {
        try {
            // Karena tb_tanggal_libur ada di database sil2020, kita sebutkan nama databasenya langsung
            $rows = $this->db->exec(
                "SELECT tanggal_libur FROM sil2020.tb_tanggal_libur WHERE tanggal_libur >= ?",
                [$startDate]
            );
            $libur = [];
            foreach ($rows ?: [] as $r) {
                $libur[] = $r['tanggal_libur'];
            }
            return $libur;
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Hitung tanggal lab teknis: tanggalPulang + 1 hari kerja
     */
    public function calculateLabTeknisDate($tanggalPulang)
    {
        if (empty($tanggalPulang) || $tanggalPulang === '0000-00-00' || $tanggalPulang === '0000-00-00 00:00:00') {
            return null;
        }

        // Ambil string tanggal saja (Y-m-d) jika format aslinya Y-m-d H:i:s
        $tglAwal = date('Y-m-d', strtotime($tanggalPulang));
        
        $hariLiburArray = $this->getHariLibur($tglAwal);
        
        $tglSkrg = strtotime($tglAwal);
        $hariKerjaDitambah = 0;

        // Loop sampai 1 hari kerja terpenuhi
        while ($hariKerjaDitambah < 1) {
            $tglSkrg = strtotime('+1 day', $tglSkrg);
            $tglFormat = date('Y-m-d', $tglSkrg);
            $hariMingguan = date('N', $tglSkrg); // 1 = Senin, 7 = Minggu

            // Lewati jika hari Sabtu (6) atau Minggu (7)
            if ($hariMingguan >= 6) {
                continue;
            }

            // Lewati jika tanggal ini adalah hari libur nasional
            if (in_array($tglFormat, $hariLiburArray)) {
                continue;
            }

            // Jika lolos dari libur & weekend, berarti ini hari kerja sah
            $hariKerjaDitambah++;
        }

        return date('Y-m-d', $tglSkrg);
    }
}
