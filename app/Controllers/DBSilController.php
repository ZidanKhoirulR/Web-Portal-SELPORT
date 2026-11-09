<?php

class DBSilController
{
    private $db;

    public function __construct($dbSil)
    {
        $this->db = $dbSil;
    }

    public function getServiceCounts($customerId)
    {
        $result = ['pengujian' => 0, 'kalibrasi' => 0, 'sertifikasi' => 0];

        $stats = $this->db->exec(
            "SELECT 
                SUM(CASE WHEN mjc.Uraian_contoh NOT LIKE '%Kalibrasi%' AND mjc.Uraian_contoh NOT LIKE '%Sertifikasi%' THEN 1 ELSE 0 END) as count_pengujian,
                SUM(CASE WHEN mjc.Uraian_contoh LIKE '%Kalibrasi%' THEN 1 ELSE 0 END) as count_kalibrasi,
                SUM(CASE WHEN mjc.Uraian_contoh LIKE '%Sertifikasi%' THEN 1 ELSE 0 END) as count_sertifikasi
             FROM mst_jenis_contoh mjc
             INNER JOIN (tb_arsipsurat 
                INNER JOIN tb_penerimaan_contoh ON tb_arsipsurat.id_arsip = tb_penerimaan_contoh.id_arsip)
             ON mjc.Kd_contoh = tb_arsipsurat.layanan_Kd_contoh
             WHERE tb_arsipsurat.status_bayar != '' 
             AND tb_arsipsurat.id_klasifikasi = 407 
             AND tb_arsipsurat.sie_kerjasama_pc_tanggal_baca IS NOT NULL 
             AND tb_arsipsurat.sie_kerjasama_pc_tanggal_baca > '1000-01-01' 
             AND tb_arsipsurat.inspeksi = 'N' 
             AND tb_arsipsurat.id_customer = ?",
            [$customerId]
        );

        if (!empty($stats)) {
            $result['pengujian'] = $stats[0]['count_pengujian'] ?? 0;
            $result['kalibrasi'] = $stats[0]['count_kalibrasi'] ?? 0;
            $result['sertifikasi'] = $stats[0]['count_sertifikasi'] ?? 0;
        }

        return $result;
    }

    public function getAvailableYears($customerId)
    {
        return $this->db->exec(
            "SELECT DISTINCT RIGHT(TRIM(SUBSTRING_INDEX(COALESCE(NULLIF(tb_penerimaan_contoh.No_order, ''), tb_arsipsurat.no_permintaan), '/', -1)), 4) as thn 
             FROM tb_penerimaan_contoh 
             INNER JOIN tb_arsipsurat ON tb_penerimaan_contoh.id_arsip = tb_arsipsurat.id_arsip
             WHERE tb_arsipsurat.id_customer = ? 
             AND (tb_penerimaan_contoh.No_order != '' OR tb_arsipsurat.no_permintaan != '')
             ORDER BY thn DESC",
            [$customerId]
        );
    }

    public function getOrderHistory($customerId, $filterStatus = 'active', $filterLayanan = '', $filterTahun = '', $limit = 0)
    {
        $queryParams = [$customerId];
        $whereClause = "WHERE tb_arsipsurat.status_bayar!='' 
                AND tb_arsipsurat.id_klasifikasi=407 
                AND tb_arsipsurat.sie_kerjasama_pc_tanggal_baca IS NOT NULL 
                AND tb_arsipsurat.sie_kerjasama_pc_tanggal_baca > '1000-01-01' 
                AND tb_arsipsurat.inspeksi='N' 
                AND tb_arsipsurat.id_customer = ?";

        if ($filterStatus === 'completed') {
            $whereClause .= " AND (tb_penerimaan_contoh.tanggal_terima_lhu IS NOT NULL AND tb_penerimaan_contoh.tanggal_terima_lhu > '1000-01-01')";
        } else {
            $whereClause .= " AND (tb_penerimaan_contoh.tanggal_terima_lhu IS NULL OR tb_penerimaan_contoh.tanggal_terima_lhu < '1000-01-01')";
        }

        if ($filterLayanan) {
            if ($filterLayanan === 'Pengujian') {
                $whereClause .= " AND mjc.Uraian_contoh NOT LIKE '%Kalibrasi%' AND mjc.Uraian_contoh NOT LIKE '%Sertifikasi%' AND (tb_penerimaan_contoh.uji_pengecualian IS NULL OR tb_penerimaan_contoh.uji_pengecualian = 'N')";
            } elseif ($filterLayanan === 'Kalibrasi') {
                $whereClause .= " AND mjc.Uraian_contoh LIKE '%Kalibrasi%'";
            } elseif ($filterLayanan === 'Sertifikasi') {
                $whereClause .= " AND mjc.Uraian_contoh LIKE '%Sertifikasi%'";
            } elseif ($filterLayanan === 'Pengujian Pengecualian') {
                $whereClause .= " AND tb_penerimaan_contoh.uji_pengecualian = 'Y'";
            }
        }

        if ($filterTahun) {
            $whereClause .= " AND RIGHT(TRIM(SUBSTRING_INDEX(COALESCE(NULLIF(tb_penerimaan_contoh.No_order, ''), tb_arsipsurat.no_permintaan), '/', -1)), 4) = ?";
            $queryParams[] = $filterTahun;
        }

        $orders = $this->db->exec(
            "SELECT 
                tb_arsipsurat.id_arsip,
                tb_arsipsurat.no_permintaan,
                tb_arsipsurat.status_bayar,
                tb_arsipsurat.keterangan_proses,
                tb_arsipsurat.sie_pengujian_qas_tanggal_baca,
                tb_arsipsurat.sie_pengujian_penyelia_tanggal_kirim_3_ke_qas,
                tb_arsipsurat.sie_pengujian_penyelia_tanggal_kirim_2_ke_qas,
                tb_arsipsurat.sie_pengujian_penyelia_tanggal_kirim,
                tb_arsipsurat.sie_pengujian_qas_tanggal_validasi,
                tb_penerimaan_contoh.No_order, 
                DATE_FORMAT(tb_penerimaan_contoh.Tanggal_order, '%d-%m-%Y') as Tanggal_order, 
                tb_penerimaan_contoh.Banyak_contoh, 
                tb_penerimaan_contoh.tanggal_terima_lhu,
                tb_penerimaan_contoh.uji_pengecualian,
                mjc.Uraian_contoh
              FROM mst_jenis_contoh mjc
              INNER JOIN (tb_arsipsurat 
                 INNER JOIN tb_penerimaan_contoh ON tb_arsipsurat.id_arsip = tb_penerimaan_contoh.id_arsip)
              ON mjc.Kd_contoh = tb_arsipsurat.layanan_Kd_contoh
              $whereClause
              ORDER BY tb_penerimaan_contoh.Tanggal_order DESC" . ($limit > 0 ? " LIMIT " . (int)$limit : ""),
            $queryParams
        );

        return $orders ?: [];
    }

    public function getOrderBiayaBatch($orderIds)
    {
        if (empty($orderIds)) return [];

        $placeholders = implode(',', array_fill(0, count($orderIds), '?'));
        $batchRows = $this->db->exec(
            "SELECT 
                ad.id_arsip, 
                MIN(ad.pembayaran_tanggal_bayar) as pembayaran_tanggal_bayar,
                SUM(tp.jumlah * mt.tarif) as total_biaya
             FROM tb_arsip_detail ad
             LEFT JOIN tb_penawaran_parameter tp ON ad.id_arsip_sub = tp.id_arsip_sub AND tp.jumlah > 0
             LEFT JOIN mst_tarif_simponi mt ON tp.id_tarif_simponi = mt.id_tarif_simponi
             WHERE ad.id_arsip IN ($placeholders)
             GROUP BY ad.id_arsip",
            $orderIds
        );

        $map = [];
        foreach ($batchRows as $b) {
            $map[$b['id_arsip']] = [
                'pembayaran_tanggal_bayar' => $b['pembayaran_tanggal_bayar'] ?? null,
                'total_biaya' => $b['total_biaya'] ?? 0,
            ];
        }
        return $map;
    }

    public function getSimpleStatusLabel($row)
    {
        if (!empty($row['tanggal_terima_lhu'])) return 'Selesai';
        if (!empty($row['sie_pengujian_qas_tanggal_validasi'])) return 'Validasi Data Hasil Uji';
        if (!empty($row['sie_pengujian_penyelia_tanggal_kirim_3_ke_qas'])
            || !empty($row['sie_pengujian_penyelia_tanggal_kirim_2_ke_qas'])
            || !empty($row['sie_pengujian_penyelia_tanggal_kirim'])) return 'Hasil Uji';
        if (!empty($row['sie_pengujian_qas_tanggal_baca'])) return 'Proses Lab';
        if (!empty($row['pembayaran_tanggal_bayar'])) return 'Pembayaran';
        return 'Pendaftaran';
    }

    public function getOrderDetail($orderId)
    {
        $res = $this->db->exec(
            "SELECT 
                tb_arsipsurat.*,
                tb_penerimaan_contoh.No_order, 
                tb_penerimaan_contoh.Ket_contoh,
                DATE_FORMAT(tb_penerimaan_contoh.Tanggal_order, '%d-%m-%Y') as Tanggal_order, 
                DATE_FORMAT(tb_penerimaan_contoh.Tgl_selesai_LHU, '%d-%m-%Y') as Tgl_selesai_LHU,
                tb_penerimaan_contoh.Tgl_selesai_LHU as Tgl_selesai_LHU_raw, 
                tb_penerimaan_contoh.Banyak_contoh, 
                tb_penerimaan_contoh.indonesia,
                tb_penerimaan_contoh.inggris,
                tb_penerimaan_contoh.sumber_sampel,
                tb_penerimaan_contoh.tanggal_terima_lhu,
                tb_penerimaan_contoh.No_sp,
                tb_penerimaan_contoh.Tanggal_sp,
                tb_penerimaan_contoh.Tgl_selesai_LHU_realisasi,
                tb_penerimaan_contoh.jumlah_lhu,
                mst_jenis_contoh.Uraian_contoh,
                mst_jenis_pengujian.jenis_pengujian,
                (SELECT ad.pembayaran_tanggal_bayar FROM tb_arsip_detail ad WHERE ad.id_arsip = tb_arsipsurat.id_arsip LIMIT 1) as pembayaran_tanggal_bayar
              FROM mst_jenis_contoh 
              INNER JOIN (tb_arsipsurat 
                 INNER JOIN tb_penerimaan_contoh ON tb_arsipsurat.id_arsip = tb_penerimaan_contoh.id_arsip)
              ON mst_jenis_contoh.Kd_contoh = tb_arsipsurat.layanan_Kd_contoh
              LEFT JOIN mst_jenis_pengujian ON tb_penerimaan_contoh.id_jenis_pengujian = mst_jenis_pengujian.id_jenis_pengujian
              WHERE tb_arsipsurat.id_arsip = ?",
            [$orderId]
        );

        return !empty($res) ? $res[0] : null;
    }

    public function getOrderTracking($orderData)
    {
        $currentStep = 0;
        $statusLabel = 'Belum Terdaftar';
        $steps = [
            1 => ['label' => 'Pendaftaran', 'status' => 'pending', 'date' => '-'],
            2 => ['label' => 'Pembayaran', 'status' => 'pending', 'date' => '-'],
            3 => ['label' => 'Proses Lab', 'status' => 'pending', 'date' => '-'],
            4 => ['label' => 'Hasil Uji', 'status' => 'pending', 'date' => '-'],
            5 => ['label' => 'Validasi Data Hasil Uji', 'status' => 'pending', 'date' => '-'],
            6 => ['label' => 'Selesai', 'status' => 'pending', 'date' => '-'],
        ];

        if (!empty($orderData)) {
            $steps[1]['status'] = 'completed';
            $steps[1]['date'] = $orderData['Tanggal_order'] ?? '-';
            $currentStep = 1;
            $statusLabel = 'Pendaftaran';
        }

        if (!empty($orderData['pembayaran_tanggal_bayar'])) {
            $steps[2]['status'] = 'completed';
            $steps[2]['date'] = date('d-m-Y', strtotime($orderData['pembayaran_tanggal_bayar']));
            $currentStep = 2;
            $statusLabel = 'Pembayaran';
        }

        if (!empty($orderData['sie_pengujian_qas_tanggal_baca'])) {
            $steps[3]['status'] = 'completed';
            $steps[3]['date'] = date('d-m-Y', strtotime($orderData['sie_pengujian_qas_tanggal_baca']));
            $currentStep = 3;
            $statusLabel = 'Proses Lab';
        }

        $hasilUjiDate = null;
        if (!empty($orderData['sie_pengujian_penyelia_tanggal_kirim_3_ke_qas'])) {
            $hasilUjiDate = $orderData['sie_pengujian_penyelia_tanggal_kirim_3_ke_qas'];
        } elseif (!empty($orderData['sie_pengujian_penyelia_tanggal_kirim_2_ke_qas'])) {
            $hasilUjiDate = $orderData['sie_pengujian_penyelia_tanggal_kirim_2_ke_qas'];
        } elseif (!empty($orderData['sie_pengujian_penyelia_tanggal_kirim'])) {
            $hasilUjiDate = $orderData['sie_pengujian_penyelia_tanggal_kirim'];
        }
        if ($hasilUjiDate) {
            $steps[4]['status'] = 'completed';
            $steps[4]['date'] = date('d-m-Y', strtotime($hasilUjiDate));
            $currentStep = 4;
            $statusLabel = 'Hasil Uji';
        }

        if (!empty($orderData['sie_pengujian_qas_tanggal_validasi'])) {
            $steps[5]['status'] = 'completed';
            $steps[5]['date'] = date('d-m-Y', strtotime($orderData['sie_pengujian_qas_tanggal_validasi']));
            $currentStep = 5;
            $statusLabel = 'Validasi Data Hasil Uji';
        }

        if (!empty($orderData['tanggal_terima_lhu'])) {
            $steps[6]['status'] = 'completed';
            $steps[6]['date'] = date('d-m-Y', strtotime($orderData['tanggal_terima_lhu']));
            $currentStep = 6;
            $statusLabel = 'Selesai';
        }

        if ($currentStep > 0 && $currentStep < 6) {
            $steps[$currentStep + 1]['status'] = 'active';
        }

        return [
            'current_step' => $currentStep,
            'status_label' => $statusLabel,
            'steps' => $steps
        ];
    }

    public function getRincianContoh($orderId)
    {
        return $this->db->exec(
            "SELECT nomor_contoh, keterangan, hscode, jenis_kertas, varian_gramatur, merk, negara_asal, pabrik_pembuat
             FROM tb_identitas_contoh_dump WHERE id_arsip = ? ORDER BY nomor_contoh ASC",
            [$orderId]
        ) ?: [];
    }

    public function getBapcData($orderId, $noOrder)
    {
        $result = [
            'no_sp'                      => '-',
            'tanggal_sp'                 => '-',
            'petugas'                    => '-',
            'prosedur'                   => '-',
            'nama_berkas_bapc'           => null,
            'nama_berkas_lhu_dengan_hal' => null,
        ];

        if ($noOrder) {
            $pcData = $this->db->exec(
                "SELECT No_sp, Tanggal_sp, nama_berkas_bapc, nama_berkas_lhu_dengan_hal FROM tb_penerimaan_contoh WHERE No_order = ? LIMIT 1",
                [$noOrder]
            );
            if (!empty($pcData)) {
                $result['no_sp'] = !empty($pcData[0]['No_sp']) ? $pcData[0]['No_sp'] : '-';
                $result['tanggal_sp'] = !empty($pcData[0]['Tanggal_sp']) 
                    ? date('d-m-Y', strtotime($pcData[0]['Tanggal_sp'])) 
                    : '-';
                // null jika kosong / belum diisi
                $berkas = $pcData[0]['nama_berkas_bapc'] ?? null;
                $result['nama_berkas_bapc'] = (!empty($berkas) && trim($berkas) !== '') ? trim($berkas) : null;

                $berkasLhu = $pcData[0]['nama_berkas_lhu_dengan_hal'] ?? null;
                $result['nama_berkas_lhu_dengan_hal'] = (!empty($berkasLhu) && trim($berkasLhu) !== '') ? trim($berkasLhu) : null;
            }
        }

        $ppcData = $this->db->exec(
            "SELECT id_ppc_1, id_ppc_2, id_ppc_3 FROM tb_ppc_ambil WHERE id_arsip = ? LIMIT 1",
            [$orderId]
        );
        if (!empty($ppcData)) {
            $ppcIds = array_filter([
                (int)($ppcData[0]['id_ppc_1'] ?? 0),
                (int)($ppcData[0]['id_ppc_2'] ?? 0),
                (int)($ppcData[0]['id_ppc_3'] ?? 0),
            ]);
            if (!empty($ppcIds)) {
                $placeholders = implode(',', array_fill(0, count($ppcIds), '?'));
                $ppcNames = $this->db->exec(
                    "SELECT nama_ppc FROM mst_ppc WHERE id_ppc IN ($placeholders)",
                    array_values($ppcIds)
                );
                if (!empty($ppcNames)) {
                    $names = array_map(function($r) { return $r['nama_ppc']; }, $ppcNames);
                    $result['petugas'] = implode(', ', $names);
                }
            }
        }

        $metodaData = $this->db->exec(
            "SELECT id_metoda_pc_1, id_metoda_pc_2 FROM tb_arsipsurat WHERE id_arsip = ? LIMIT 1",
            [$orderId]
        );
        if (!empty($metodaData)) {
            $metodaIds = array_filter([
                (int)($metodaData[0]['id_metoda_pc_1'] ?? 0),
                (int)($metodaData[0]['id_metoda_pc_2'] ?? 0),
            ]);
            if (!empty($metodaIds)) {
                $placeholders = implode(',', array_fill(0, count($metodaIds), '?'));
                $metodaNames = $this->db->exec(
                    "SELECT nama_metode FROM mst_metoda_pc WHERE id_metoda_pc IN ($placeholders)",
                    array_values($metodaIds)
                );
                if (!empty($metodaNames)) {
                    $methods = array_map(function($r) { return $r['nama_metode']; }, $metodaNames);
                    $result['prosedur'] = implode(', ', $methods);
                }
            }
        }

        return $result;
    }

    public function getBiayaData($orderData)
    {
        // Hitung biaya pengujian dari tabel parameter (sumber kebenaran),
        // bukan dari kolom statis struktural_biaya_kerjasama yang bisa berbeda.
        $orderId = $orderData['id_arsip'] ?? 0;
        $jenisPengujian = $orderData['jenis_pengujian'] ?? '';
        $paramResult = $this->getParameterPengujian($orderId, $jenisPengujian);
        $biayaPengujian   = (float)($paramResult['total'] ?? 0);

        $biayaPetugas     = (float)($orderData['udara_biaya_petugas'] ?? 0);
        $biayaTransport   = (float)($orderData['udara_biaya_transportasi'] ?? 0);
        $biayaSertifikat  = (float)($orderData['udara_biaya_alih_bahasa'] ?? 0);
        $biayaPengiriman  = (float)($orderData['udara_biaya_pengiriman_lhu'] ?? 0);
        $sumberSampel     = $orderData['sumber_sampel'] ?? '';

        $items = [
            ['label' => 'Biaya Pengujian', 'value' => $biayaPengujian],
        ];

        if (stripos($sumberSampel, 'Diambil') !== false) {
            $items[] = ['label' => 'Biaya Petugas Pengambilan Contoh', 'value' => $biayaPetugas];
            $items[] = ['label' => 'Biaya Transport', 'value' => $biayaTransport];
            $items[] = ['label' => 'Biaya Alih Bahasa Sertifikat', 'value' => $biayaSertifikat];
            $items[] = ['label' => 'Biaya Pengiriman LHU', 'value' => $biayaPengiriman];
            $total = $biayaPengujian + $biayaPetugas + $biayaTransport + $biayaSertifikat + $biayaPengiriman;
        } else {
            $items[] = ['label' => 'Biaya Alih Bahasa Sertifikat', 'value' => $biayaSertifikat];
            $items[] = ['label' => 'Biaya Pengiriman LHU', 'value' => $biayaPengiriman];
            $total = $biayaPengujian + $biayaSertifikat + $biayaPengiriman;
        }

        return ['items' => $items, 'total' => $total];
    }

    public function getParameterPengujian($orderId, $jenisPengujian = '')
    {
        $rows = [];
        $total = 0;

        // Tentukan multiplier tarif berdasarkan jenis tarif uji
        $jenisPengujianLower = strtolower(trim($jenisPengujian));
        if (strpos($jenisPengujianLower, 'express') !== false) {
            $multiplier = 1.5;
        } elseif (strpos($jenisPengujianLower, 'flash') !== false) {
            $multiplier = 2.0;
        } else {
            $multiplier = 1.0; // Reguler / default
        }

        $paramData = $this->db->exec(
            "SELECT pp.id_tarif_simponi, pp.jumlah, ms.uraian_parameter, ms.tarif, ad.id_arsip_sub
             FROM tb_arsip_detail ad
             INNER JOIN tb_penawaran_parameter pp ON pp.id_arsip_sub = ad.id_arsip_sub
             INNER JOIN mst_tarif_simponi ms ON ms.id_tarif_simponi = pp.id_tarif_simponi
             WHERE ad.id_arsip = ? AND pp.jumlah > 0",
            [$orderId]
        );

        if (!empty($paramData)) {
            foreach ($paramData as $p) {
                $jumlah      = (int)($p['jumlah'] ?? 0);
                $tarifNormal = (int)($p['tarif'] ?? 0);
                // Tarif setelah dikali multiplier (pembulatan ke atas ke integer terdekat)
                $tarifAdjusted = (int)ceil($tarifNormal * $multiplier);
                $biaya       = $jumlah * $tarifAdjusted;
                $total      += $biaya;
                $idArsipSub  = $p['id_arsip_sub'];
                $idTarif     = $p['id_tarif_simponi'];

                // Fetch sub-parameters for package (paket) parameters
                $subParams = [];
                try {
                    $paketRows = $this->db->exec(
                        "SELECT mt.uraian_parameter
                         FROM tb_penawaran_parameter_paket tpp
                         INNER JOIN mst_tarif_simponi mt ON tpp.id_tarif_simponi = mt.id_tarif_simponi
                         WHERE tpp.id_arsip_sub = ? AND tpp.id_tarif_simponi_paket = ?
                         ORDER BY tpp.id_param ASC",
                        [$idArsipSub, $idTarif]
                    );
                    if (!empty($paketRows)) {
                        foreach ($paketRows as $sp) {
                            $subParams[] = $sp['uraian_parameter'] ?? '-';
                        }
                        // Deduplikasi: jika anak paket yang sama muncul berulang
                        // (terjadi saat jumlah parameter > 1 dan semua contoh punya anak yang sama),
                        // cukup tampilkan satu kali saja.
                        $subParams = array_values(array_unique($subParams));
                    }
                } catch (\Exception $e) {
                    // Table may not exist or no children — silently ignore
                }


                $rows[] = [
                    'uraian_parameter' => $p['uraian_parameter'] ?? '-',
                    'jumlah'           => $jumlah,
                    'tarif_normal'     => $tarifNormal,
                    'tarif'            => $tarifAdjusted,
                    'multiplier'       => $multiplier,
                    'biaya'            => $biaya,
                    'sub_params'       => $subParams,
                ];
            }
        }

        return ['rows' => $rows, 'total' => $total, 'multiplier' => $multiplier];
    }

    public function getLhuData($orderId)
    {
        $result = ['tgl_analisis' => '-', 'tgl_terbit_lhu' => '-', 'spec_name' => '-', 'raw' => null];

        $orderInfo = $this->db->exec("SELECT No_order FROM tb_penerimaan_contoh WHERE id_arsip = ?", [$orderId]);
        if (empty($orderInfo)) {
            $targetArsipId = $orderId;
        } else {
            $noOrder = $orderInfo[0]['No_order'];
            $maxArsip = $this->db->exec("SELECT MAX(id_arsip) as max_id FROM tb_penerimaan_contoh WHERE No_order = ?", [$noOrder]);
            $targetArsipId = !empty($maxArsip[0]['max_id']) ? $maxArsip[0]['max_id'] : $orderId;
        }

        $lhuQuery = $this->db->exec(
            "SELECT l.*, s.uraian_tampil as spec_name 
             FROM tb_lhu l
             LEFT JOIN mst_spesifikasi_bakumutu s ON l.id_spesifikasi = s.id_spesifikasi
             WHERE l.id_arsip = ? 
             ORDER BY l.id_lhu DESC LIMIT 1", 
            [$targetArsipId]
        );

        if (!empty($lhuQuery)) {
            $result['raw'] = $lhuQuery[0];
            $result['id_arsip'] = $targetArsipId;
            $result['spec_name'] = $lhuQuery[0]['spec_name'] ?? '-';
            $result['tgl_analisis'] = $lhuQuery[0]['data_12'] ?? $lhuQuery[0]['data_9'] ?? '-';
            $result['tgl_terbit_lhu'] = !empty($lhuQuery[0]['tanggal_lhu']) ? date('d-m-Y', strtotime($lhuQuery[0]['tanggal_lhu'])) : '-';
        }

        return $result;
    }

    public function getHasilUji($orderId, $noContoh = null)
    {
        $arsipDetail = $this->db->exec(
            "SELECT id_arsip_sub FROM tb_arsip_detail WHERE id_arsip = ?",
            [$orderId]
        );
        $idArsipSub = !empty($arsipDetail) ? $arsipDetail[0]['id_arsip_sub'] : null;

        if (!$idArsipSub) return [];
        
        $whereSql = "WHERE tp.id_arsip_sub = ?";
        $paramsSql = [$idArsipSub];

        if ($noContoh !== null && $noContoh !== '') {
            // Include rows with the specific nomor_contoh AND rows where nomor_contoh IS NULL.
            // Package-type parents (e.g. AMDK bundles) store nomor_contoh = NULL on the parent row;
            // the per-sample distinction is handled on the children (tb_penawaran_parameter_paket).
            $whereSql .= " AND (tp.nomor_contoh = ? OR tp.nomor_contoh IS NULL)";
            $paramsSql[] = $noContoh;
        }

        $params = $this->db->exec(
            "SELECT 
                tp.id_tarif_simponi,
                tp.id_param,
                tp.baku_mutu,
                tp.hasil_uji,
                tp.hasil_uji_n2,
                tp.hasil_uji_n3,
                tp.hasil_uji_n4,
                tp.hasil_uji_n5,
                tp.hasil_uji_emisi_terkoreksi,
                tp.metoda_sni,
                mt.uraian_parameter, 
                mt.satuan_1, 
                mt.metoda_1,
                mt.lab_mikro,
                mt.id_urut,
                mt.no_urut,
                sp.satuan as satuan_from_kd,
                tp.nomor_contoh
            FROM tb_penawaran_parameter tp 
            LEFT JOIN mst_tarif_simponi mt ON tp.id_tarif_simponi = mt.id_tarif_simponi
            LEFT JOIN mst_satuan_parameter sp ON tp.Kd_satuan = sp.Kd_satuan
            $whereSql
            ORDER BY tp.id_param ASC",
            $paramsSql
        );

        if (empty($params)) return [];

        $result = [];
        foreach ($params as $param) {
            $idTarif = $param['id_tarif_simponi'];

            // Build children filter. Use $noContoh (the requested sample number)
            // directly to split 64 combined rows into 32 per sample.
            // tb_penawaran_parameter_paket has nomor_contoh column confirmed.
            $childWhere = "WHERE tpp.id_arsip_sub = ? AND tpp.id_tarif_simponi_paket = ?";
            $childParams = [$idArsipSub, $idTarif];
            if ($noContoh !== null && $noContoh !== '') {
                $childWhere .= " AND tpp.nomor_contoh = ?";
                $childParams[] = $noContoh;
            }

            $children = $this->db->exec(
                "SELECT 
                    tpp.id_param,
                    tpp.hasil_uji,
                    tpp.hasil_uji_n2,
                    tpp.hasil_uji_n3,
                    tpp.hasil_uji_n4,
                    tpp.hasil_uji_n5,
                    tpp.hasil_uji_n2_8079,
                    tpp.hasil_uji_n3_8079,
                    tpp.hasil_uji_n4_8079,
                    tpp.hasil_uji_n5_8079,
                    tpp.hasil_uji_emisi_terkoreksi,
                    tpp.metoda_sni,
                    tpp.baku_mutu,
                    mt.uraian_parameter,
                    mt.satuan_1,
                    mt.metoda_1,
                    mt.lab_mikro,
                    mt.id_urut,
                    mt.no_urut,
                    sp.satuan as satuan_from_kd
                FROM tb_penawaran_parameter_paket tpp
                LEFT JOIN mst_tarif_simponi mt ON tpp.id_tarif_simponi = mt.id_tarif_simponi
                LEFT JOIN mst_satuan_parameter sp ON tpp.Kd_satuan = sp.Kd_satuan
                $childWhere
                ORDER BY tpp.id_param ASC",
                $childParams
            );

            $param['children'] = !empty($children) ? $children : [];
            $result[] = $param;
        }

        return $result;
    }



    public function getHariLibur()
    {
        $hariLibur = [];
        $liburRows = $this->db->exec(
            "SELECT tanggal_libur FROM tb_tanggal_libur WHERE tanggal_libur IS NOT NULL"
        );
        if (!empty($liburRows)) {
            foreach ($liburRows as $lr) {
                if (!empty($lr['tanggal_libur'])) {
                    $hariLibur[] = date('Y-m-d', strtotime($lr['tanggal_libur']));
                }
            }
        }
        return $hariLibur;
    }

    /**
     * Get meteorologi data for Udara (ambient) testing
     * @param int $orderId  The id_arsip
     * @param int|null $noContoh  Optional nomor_contoh filter
     * @return array
     */
    public function getDataMetrologi($orderId, $noContoh = null)
    {
        $sql = "SELECT paramater_metrologi, satuan_metrologi, hasil_pengamatan 
                FROM tb_data_metrologi_ambient 
                WHERE id_arsip = ? AND showhide = 'show'";
        $params = [$orderId];

        if ($noContoh !== null && $noContoh !== '') {
            $sql .= " AND nomor_contoh = ?";
            $params[] = $noContoh;
        }

        $sql .= " ORDER BY id_metrologi ASC";

        return $this->db->exec($sql, $params) ?: [];
    }
}