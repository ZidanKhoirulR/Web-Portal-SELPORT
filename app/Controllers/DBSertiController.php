<?php

class DBSertiController
{
    private $db;

    public function __construct($dbSerti)
    {
        $this->db = $dbSerti;
    }

    /**
     * Ambil riwayat order sertifikasi berdasarkan id_customer.
     * Filter status: 'active' = belum terbit sertifikat, 'completed' = sudah terbit.
     */
    public function getOrderHistory($customerId, $filterStatus = 'active', $filterTahun = '', $limit = 0)
    {
        if (!$this->db || !$customerId) return [];

        $queryParams = [$customerId];

        $whereClause = "WHERE a.id_customer = ?";

        if ($filterStatus === 'completed') {
            // Selesai: sertifikat sudah terbit (katim_tanggal_kirim_untuk_rapat_komite terisi)
            $whereClause .= " AND t.katim_tanggal_kirim_untuk_rapat_komite IS NOT NULL
                              AND t.katim_tanggal_kirim_untuk_rapat_komite > '1000-01-01'";
        } else {
            // Berjalan: sertifikat belum terbit
            $whereClause .= " AND (t.katim_tanggal_kirim_untuk_rapat_komite IS NULL
                              OR t.katim_tanggal_kirim_untuk_rapat_komite <= '1000-01-01')";
        }

        if ($filterTahun) {
            $whereClause .= " AND RIGHT(TRIM(SUBSTRING_INDEX(a.no_id_order, '/', -1)), 4) = ?";
            $queryParams[] = $filterTahun;
        }

        try {
            $rows = $this->db->exec(
                "SELECT
                    a.id_arsip_sertifikasi,
                    a.no_id_order,
                    DATE_FORMAT(a.tanggal_pendaftaran, '%d-%m-%Y') AS tanggal_pendaftaran,
                    a.id_jenis_sertifikasi,
                    COALESCE(j.nama_jenis_sertifikasi, '-') AS nama_jenis_sertifikasi,
                    COALESCE(j.singkatan, '')               AS singkatan,
                    COALESCE(a.jumlah_biaya_akomodasi, 0)   AS jumlah_biaya_akomodasi,
                    COALESCE(a.jumlah_biaya_sertifikasi, 0) AS jumlah_biaya_sertifikasi,
                    COALESCE(a.jumlah_biaya_transportasi, 0) AS jumlah_biaya_transportasi,
                    COALESCE(a.jumlah_biaya_uji, 0)         AS jumlah_biaya_uji,
                    t.keuangan_tanggal_bayar,
                    t.katim_tanggal_kirim_ke_tim_auditor,
                    t.lead_auditor_kirim_hasil_audit_ke_pelanggan,
                    t.tanggal_validasi_vtp,
                    t.katim_tanggal_kirim_untuk_rapat_komite
                FROM tb_arsip_sertifikasi a
                LEFT JOIN mst_jenis_sertifikasi j ON a.id_jenis_sertifikasi = j.id_jenis_sertifikasi
                LEFT JOIN tb_arsip_sertifikasi_tracking t ON a.id_arsip_sertifikasi = t.id_arsip_sertifikasi
                $whereClause
                ORDER BY a.tanggal_pendaftaran DESC" . ($limit > 0 ? " LIMIT " . (int)$limit : ""),
                $queryParams
            );
            return $rows ?: [];
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Tentukan label status layanan sertifikasi berdasarkan field tracking.
     * Mengambil tahapan paling akhir yang sudah terisi.
     */
    public function getStatusLabel($row)
    {
        if (!empty($row['katim_tanggal_kirim_untuk_rapat_komite'])
            && $row['katim_tanggal_kirim_untuk_rapat_komite'] > '1000-01-01') {
            return 'Sertifikat Terbit';
        }
        if (!empty($row['tanggal_validasi_vtp'])
            && $row['tanggal_validasi_vtp'] > '1000-01-01') {
            return 'Hasil Uji dan Verifikasi Tindakan Perbaikan';
        }
        if (!empty($row['lead_auditor_kirim_hasil_audit_ke_pelanggan'])
            && $row['lead_auditor_kirim_hasil_audit_ke_pelanggan'] > '1000-01-01') {
            return 'Laporan Audit';
        }
        if (!empty($row['katim_tanggal_kirim_ke_tim_auditor'])
            && $row['katim_tanggal_kirim_ke_tim_auditor'] > '1000-01-01') {
            return 'Penugasan';
        }
        if (!empty($row['keuangan_tanggal_bayar'])
            && $row['keuangan_tanggal_bayar'] > '1000-01-01') {
            return 'Pembayaran';
        }
        return 'Pendaftaran';
    }

    /**
     * Ambil detail 1 order sertifikasi berdasarkan id_arsip_sertifikasi.
     */
    public function getOrderDetail($id)
    {
        if (!$this->db || !$id) return null;
        try {
            $rows = $this->db->exec(
                "SELECT
                    a.id_arsip_sertifikasi,
                    a.no_id_order,
                    DATE_FORMAT(a.tanggal_pendaftaran, '%d-%m-%Y') AS tanggal_pendaftaran,
                    COALESCE(j.nama_jenis_sertifikasi, '-') AS nama_jenis_sertifikasi,
                    COALESCE(j.singkatan, '')               AS singkatan,
                    COALESCE(a.jumlah_biaya_sertifikasi, 0) AS jumlah_biaya_sertifikasi,
                    COALESCE(a.jumlah_biaya_uji, 0)         AS jumlah_biaya_uji,
                    COALESCE(a.jumlah_biaya_transportasi, 0) AS jumlah_biaya_transportasi,
                    COALESCE(a.jumlah_biaya_akomodasi, 0)   AS jumlah_biaya_akomodasi,
                    t.keuangan_tanggal_bayar,
                    t.katim_tanggal_kirim_ke_tim_auditor,
                    t.lead_auditor_kirim_hasil_audit_ke_pelanggan,
                    t.tanggal_validasi_vtp,
                    t.katim_tanggal_kirim_untuk_rapat_komite
                FROM tb_arsip_sertifikasi a
                LEFT JOIN mst_jenis_sertifikasi j ON a.id_jenis_sertifikasi = j.id_jenis_sertifikasi
                LEFT JOIN tb_arsip_sertifikasi_tracking t ON a.id_arsip_sertifikasi = t.id_arsip_sertifikasi
                WHERE a.id_arsip_sertifikasi = ?
                LIMIT 1",
                [$id]
            );
            return !empty($rows) ? $rows[0] : null;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Bangun data tracking stepper untuk detail order sertifikasi.
     * Returns array steps dengan label, status (completed/active/pending), date.
     */
    public function getOrderTracking($row)
    {
        $steps = [
            1 => [
                'label'  => 'Pembayaran',
                'field'  => 'keuangan_tanggal_bayar',
            ],
            2 => [
                'label'  => 'Penugasan',
                'field'  => 'katim_tanggal_kirim_ke_tim_auditor',
            ],
            3 => [
                'label'  => 'Laporan Audit',
                'field'  => 'lead_auditor_kirim_hasil_audit_ke_pelanggan',
            ],
            4 => [
                'label'  => 'Hasil Uji & Verifikasi<br>Tindakan Perbaikan',
                'field'  => 'tanggal_validasi_vtp',
            ],
            5 => [
                'label'  => 'Keputusan',
                'field'  => null, // belum ada field spesifik
            ],
            6 => [
                'label'  => 'Sertifikat Terbit',
                'field'  => 'katim_tanggal_kirim_untuk_rapat_komite',
            ],
        ];

        $lastCompleted = 0;
        foreach ($steps as $i => &$step) {
            $field = $step['field'];
            $date  = ($field && !empty($row[$field]) && $row[$field] > '1000-01-01')
                     ? $row[$field] : null;

            if ($date) {
                // Format date to d-m-Y if it's Y-m-d
                if (preg_match('/^\d{4}-\d{2}-\d{2}/', $date)) {
                    $d = date_create($date);
                    $date = $d ? date_format($d, 'd-m-Y') : $date;
                }
                $step['status'] = 'completed';
                $step['date']   = $date;
                $lastCompleted  = $i;
            } else {
                $step['status'] = 'pending';
                $step['date']   = '-';
            }
        }
        unset($step);

        // Set step setelah lastCompleted menjadi 'active'
        $currentStep = $lastCompleted + 1;
        if ($currentStep <= 6) {
            $steps[$currentStep]['status'] = 'active';
        }

        return [
            'current_step' => $currentStep,
            'steps'        => $steps,
        ];
    }

    /**
     * Hitung total order sertifikasi untuk card statistik beranda.
     */

    public function getOrderCount($customerId)
    {
        if (!$this->db || !$customerId) return 0;
        try {
            $res = $this->db->exec(
                "SELECT COUNT(*) AS total FROM tb_arsip_sertifikasi WHERE id_customer = ?",
                [$customerId]
            );
            return (int)($res[0]['total'] ?? 0);
        } catch (\Exception $e) {
            return 0;
        }
    }

    /**
     * Ambil tahun yang tersedia untuk filter dropdown.
     */
    public function getAvailableYears($customerId)
    {
        if (!$this->db || !$customerId) return [];
        try {
            return $this->db->exec(
                "SELECT DISTINCT RIGHT(TRIM(SUBSTRING_INDEX(no_id_order, '/', -1)), 4) AS thn
                 FROM tb_arsip_sertifikasi
                 WHERE id_customer = ? AND no_id_order IS NOT NULL AND no_id_order != ''
                 ORDER BY thn DESC",
                [$customerId]
            ) ?: [];
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Cek apakah id_arsip_sertifikasi milik customer ini.
     */
    public function checkArsipOwnership($idArsipSertifikasi, $idCustomer)
    {
        if (!$this->db || !$idArsipSertifikasi || !$idCustomer) return false;
        try {
            $rows = $this->db->exec(
                "SELECT id_arsip_sertifikasi FROM tb_arsip_sertifikasi WHERE id_arsip_sertifikasi = ? AND id_customer = ? LIMIT 1",
                [$idArsipSertifikasi, $idCustomer]
            );
            return !empty($rows);
        } catch (\Exception $e) {
            return false;
        }
    }
}
