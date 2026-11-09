<?php

class SertifikasiController
{
    private $db;
    private $dbSerti;
    private $dbSil;
    private $notifikasiService;

    public function __construct($db, $notifikasiService = null, $dbSerti = null, $dbSil = null)
    {
        $this->db = $db;
        $this->dbSerti = $dbSerti;
        $this->dbSil = $dbSil;
        $this->notifikasiService = $notifikasiService;
    }

    private function getIdsWithPendaftaranFiles()
    {
        if (!$this->db) return [];
        try {
            $rows = $this->db->exec("SELECT id_arsip_sertifikasi FROM tb_arsip_sertifikasi_berkas WHERE 
                NULLIF(b7104_nama_berkas_surat_permohonan, '') IS NOT NULL OR
                NULLIF(b7104_nama_berkas_permohonan_sertifikasi_produk, '') IS NOT NULL OR
                NULLIF(b7104_nama_berkas_informasi_sertifikasi_produk, '') IS NOT NULL OR
                NULLIF(b7104_nama_berkas_akte_perusahaan, '') IS NOT NULL OR
                NULLIF(b7104_nama_berkas_izin_usaha_industri, '') IS NOT NULL OR
                NULLIF(b7104_nama_berkas_sertifikat_merek, '') IS NOT NULL OR
                NULLIF(b7104_nama_berkas_sertifikat_merek_2, '') IS NOT NULL OR
                NULLIF(b7104_nama_berkas_alur_proses_produksi, '') IS NOT NULL OR
                NULLIF(b7104_nama_berkas_ilustrasi_cara_pembubuhan_tanda_sni, '') IS NOT NULL OR
                NULLIF(b7104_nama_berkas_struktur_organisasi_perusahaan, '') IS NOT NULL OR
                NULLIF(b7104_nama_berkas_daftar_peralatan_pengujian, '') IS NOT NULL OR
                NULLIF(b7104_nama_berkas_kopi_laporan_sertifikat, '') IS NOT NULL OR
                NULLIF(b7104_nama_berkas_pedoman_mutu, '') IS NOT NULL OR
                NULLIF(b7104_nama_berkas_daftar_dokumentasi_smm, '') IS NOT NULL OR
                NULLIF(b7104_nama_berkas_kopi_sertifikat_sistem_manajemen_mutu, '') IS NOT NULL OR
                NULLIF(b7104_nama_berkas_sipa_surat_keterangan_kerjasama, '') IS NOT NULL OR
                NULLIF(b7104_nama_berkas_sertifikat_hasil_uji_air_baku, '') IS NOT NULL
            ");
            return array_column($rows ?: [], 'id_arsip_sertifikasi');
        } catch (\Exception $e) {
            return [];
        }
    }

    private function getIdsWithVerifikasiFiles()
    {
        if (!$this->db) return [];
        try {
            $rows = $this->db->exec("SELECT id_arsip_sertifikasi FROM tb_arsip_sertifikasi_berkas WHERE 
                NULLIF(formulir_verifikasi_tindakan_perbaikan, '') IS NOT NULL OR
                NULLIF(formulir_verifikasi_tindakan_perbaikan_2, '') IS NOT NULL OR
                NULLIF(formulir_verifikasi_tindakan_perbaikan_3, '') IS NOT NULL
            ");
            return array_column($rows ?: [], 'id_arsip_sertifikasi');
        } catch (\Exception $e) {
            return [];
        }
    }

    private function checkRole($f3)
    {
        $role = $f3->get('SESSION.role');
        if ($role !== 'sertifikasi') {
            $f3->reroute('/login');
            exit;
        }
    }

    public function index($f3, $section = 'ringkasan')
    {
        $this->checkRole($f3);
        $f3->set('SECTION', $section);
        echo \Template::instance()->render('sertifikasi/dashboard.html');
    }

    public function loadSection($f3, $file)
    {
        $this->checkRole($f3);
        $path = "sertifikasi/{$file}.html";
        if (file_exists("ui/" . $path)) {
            $html = file_get_contents("ui/" . $path);
            $html = str_replace('{{ @BASE }}', $f3->get('BASE'), $html);
            echo $html;
        } else {
            echo "Section not found: {$file}";
        }
    }

    public function getStats($f3)
    {
        $this->checkRole($f3);
        header('Content-Type: application/json');

        $totalPendaftaran     = 0;
        $pendingPendaftaran   = 0;
        $disetujuiPendaftaran = 0;
        $totalVerifikasi      = 0;
        $pendingVerifikasi    = 0;
        $disetujuiVerifikasi  = 0;

        if ($this->dbSerti) {
            try {
                $username   = $f3->get('SESSION.username') ?? '';
                $picIdUser  = $this->getPicIdUser($username);
                $customerIds = $picIdUser ? $this->getCustomerIdsByPic($picIdUser) : [];

                $whereCust = "";
                $paramsCust = [];
                if (!empty($customerIds)) {
                    $placeholders = implode(',', array_fill(0, count($customerIds), '?'));
                    $whereCust = " WHERE a.id_customer IN ($placeholders)";
                    $paramsCust = $customerIds;
                } elseif ($picIdUser) {
                    $whereCust = " WHERE 1=0";
                }

                $idsP = $this->getIdsWithPendaftaranFiles();
                $inP = !empty($idsP) ? implode(',', $idsP) : "0";

                $condPendaftaran = "(a.id_arsip_sertifikasi IN ($inP) OR EXISTS (SELECT 1 FROM tb_arsip_sertifikasi_berkas b2 WHERE b2.id_arsip_sertifikasi = a.id_arsip_sertifikasi AND (
                    NULLIF(b2.b7104_nama_berkas_surat_permohonan, '') IS NOT NULL OR
                    NULLIF(b2.b7104_nama_berkas_permohonan_sertifikasi_produk, '') IS NOT NULL OR
                    NULLIF(b2.b7104_nama_berkas_informasi_sertifikasi_produk, '') IS NOT NULL OR
                    NULLIF(b2.b7104_nama_berkas_akte_perusahaan, '') IS NOT NULL OR
                    NULLIF(b2.b7104_nama_berkas_izin_usaha_industri, '') IS NOT NULL OR
                    NULLIF(b2.b7104_nama_berkas_sertifikat_merek, '') IS NOT NULL OR
                    NULLIF(b2.b7104_nama_berkas_sertifikat_merek_2, '') IS NOT NULL OR
                    NULLIF(b2.b7104_nama_berkas_alur_proses_produksi, '') IS NOT NULL OR
                    NULLIF(b2.b7104_nama_berkas_ilustrasi_cara_pembubuhan_tanda_sni, '') IS NOT NULL OR
                    NULLIF(b2.b7104_nama_berkas_struktur_organisasi_perusahaan, '') IS NOT NULL OR
                    NULLIF(b2.b7104_nama_berkas_daftar_peralatan_pengujian, '') IS NOT NULL OR
                    NULLIF(b2.b7104_nama_berkas_kopi_laporan_sertifikat, '') IS NOT NULL OR
                    NULLIF(b2.b7104_nama_berkas_pedoman_mutu, '') IS NOT NULL OR
                    NULLIF(b2.b7104_nama_berkas_daftar_dokumentasi_smm, '') IS NOT NULL OR
                    NULLIF(b2.b7104_nama_berkas_kopi_sertifikat_sistem_manajemen_mutu, '') IS NOT NULL OR
                    NULLIF(b2.b7104_nama_berkas_sipa_surat_keterangan_kerjasama, '') IS NOT NULL OR
                    NULLIF(b2.b7104_nama_berkas_sertifikat_hasil_uji_air_baku, '') IS NOT NULL
                )))";

                $idsV = $this->getIdsWithVerifikasiFiles();
                $inV = !empty($idsV) ? implode(',', $idsV) : "0";

                $condVTP = "(
                    (t.lead_auditor_kirim_hasil_audit_ke_pelanggan IS NOT NULL AND t.lead_auditor_kirim_hasil_audit_ke_pelanggan > '1000-01-01')
                    OR a.id_arsip_sertifikasi IN ($inV) 
                    OR EXISTS (SELECT 1 FROM tb_arsip_sertifikasi_berkas b2 WHERE b2.id_arsip_sertifikasi = a.id_arsip_sertifikasi AND (
                        NULLIF(b2.formulir_verifikasi_tindakan_perbaikan, '') IS NOT NULL OR
                        NULLIF(b2.formulir_verifikasi_tindakan_perbaikan_2, '') IS NOT NULL OR
                        NULLIF(b2.formulir_verifikasi_tindakan_perbaikan_3, '') IS NOT NULL
                    ))
                )";

                // Ambil daftar id yang disetujui dari portal_selport (karena status_berkas tidak ada di serti2020)
                $idsDisetujui = [];
                if ($this->db) {
                    $this->ensureStatusTable();
                    $statusRows = $this->db->exec("SELECT id_arsip_sertifikasi FROM sertifikasi_berkas_status WHERE status = 'diverifikasi'");
                    $idsDisetujui = array_column($statusRows ?: [], 'id_arsip_sertifikasi');
                }
                $inDisetujui = !empty($idsDisetujui) ? implode(',', $idsDisetujui) : "0";

                // Total Berkas Pendaftaran
                $wpTot = $whereCust ? $whereCust . " AND " . $condPendaftaran : " WHERE " . $condPendaftaran;
                $res = $this->dbSerti->exec("SELECT COUNT(*) AS total FROM tb_arsip_sertifikasi a" . $wpTot, $paramsCust);
                $totalPendaftaran = (int)($res[0]['total'] ?? 0);

                // Pending Berkas Pendaftaran (status_berkas bukan diverifikasi)
                $wpPend = $whereCust ? $whereCust . " AND " : " WHERE ";
                $wpPend .= "a.id_arsip_sertifikasi NOT IN ($inDisetujui) AND " . $condPendaftaran;
                $res = $this->dbSerti->exec("SELECT COUNT(*) AS total FROM tb_arsip_sertifikasi a" . $wpPend, $paramsCust);
                $pendingPendaftaran = (int)($res[0]['total'] ?? 0);

                // Disetujui Berkas Pendaftaran (status_berkas = 'diverifikasi')
                $wdPend = $whereCust ? $whereCust . " AND " : " WHERE ";
                $wdPend .= "a.id_arsip_sertifikasi IN ($inDisetujui) AND " . $condPendaftaran;
                $res = $this->dbSerti->exec("SELECT COUNT(*) AS total FROM tb_arsip_sertifikasi a" . $wdPend, $paramsCust);
                $disetujuiPendaftaran = (int)($res[0]['total'] ?? 0);

                // Total Berkas Verifikasi Perbaikan
                $wVerif = $whereCust ? $whereCust . " AND " : " WHERE ";
                $wVerif .= $condVTP;
                $res = $this->dbSerti->exec(
                    "SELECT COUNT(*) AS total FROM tb_arsip_sertifikasi a
                     LEFT JOIN tb_arsip_sertifikasi_tracking t ON a.id_arsip_sertifikasi = t.id_arsip_sertifikasi
                     " . $wVerif,
                     $paramsCust
                );
                $totalVerifikasi = (int)($res[0]['total'] ?? 0);

                // Pending Verifikasi Perbaikan (belum divalidasi VTP)
                $wpVerif = $whereCust ? $whereCust . " AND " : " WHERE ";
                $wpVerif .= "(t.tanggal_validasi_vtp IS NULL OR t.tanggal_validasi_vtp <= '1000-01-01') AND " . $condVTP;
                $res = $this->dbSerti->exec(
                    "SELECT COUNT(*) AS total FROM tb_arsip_sertifikasi a
                     LEFT JOIN tb_arsip_sertifikasi_tracking t ON a.id_arsip_sertifikasi = t.id_arsip_sertifikasi
                     " . $wpVerif,
                     $paramsCust
                );
                $pendingVerifikasi = (int)($res[0]['total'] ?? 0);

                // Disetujui Verifikasi Perbaikan (sudah divalidasi VTP)
                $wdVerif = $whereCust ? $whereCust . " AND " : " WHERE ";
                $wdVerif .= "(t.tanggal_validasi_vtp IS NOT NULL AND t.tanggal_validasi_vtp > '1000-01-01') AND " . $condVTP;
                $res = $this->dbSerti->exec(
                    "SELECT COUNT(*) AS total FROM tb_arsip_sertifikasi a
                     LEFT JOIN tb_arsip_sertifikasi_tracking t ON a.id_arsip_sertifikasi = t.id_arsip_sertifikasi
                     " . $wdVerif,
                     $paramsCust
                );
                $disetujuiVerifikasi = (int)($res[0]['total'] ?? 0);
            } catch (\Exception $e) {
                error_log('[getStats Error] ' . $e->getMessage());
            }
        }

        echo json_encode([
            'total_pendaftaran'      => $totalPendaftaran,
            'pending_pendaftaran'    => $pendingPendaftaran,
            'disetujui_pendaftaran'  => $disetujuiPendaftaran,
            'total_verifikasi'       => $totalVerifikasi,
            'pending_verifikasi'     => $pendingVerifikasi,
            'disetujui_verifikasi'   => $disetujuiVerifikasi,
        ]);
    }

    /**
     * Dapatkan id_user PIC sertifikasi dari sil2020 berdasarkan username login portal
     * Chain: portal username -> sil2020.tb_arsipuser.login -> id_user
     */
    private function getPicIdUser($username)
    {
        if (!$this->dbSil || empty($username)) return null;
        try {
            $rows = $this->dbSil->exec(
                "SELECT id_user FROM tb_arsipuser WHERE login = ? LIMIT 1",
                [$username]
            );
            return !empty($rows) ? (int)$rows[0]['id_user'] : null;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Dapatkan daftar id_customer yang ditangani oleh PIC tertentu
     * Chain: id_user -> sil2020.tb_customer.id_user_sertifikasi_penerima_order -> id_customer[]
     */
    private function getCustomerIdsByPic($picIdUser)
    {
        if (!$this->dbSil || !$picIdUser) return [];
        try {
            $rows = $this->dbSil->exec(
                "SELECT id_customer FROM tb_customer
                 WHERE id_user_sertifikasi_penerima_order = ?",
                [$picIdUser]
            );
            return array_column($rows ?: [], 'id_customer');
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Ambil daftar berkas pendaftaran sertifikasi dari DB Serti,
     * difilter berdasarkan PIC (pegawai yang menangani customer tersebut)
     * Chain: login_username -> tb_arsipuser.id_user -> tb_customer.id_user_sertifikasi_penerima_order
     *        -> tb_arsip_sertifikasi.id_customer
     */
    public function getBerkasPendaftaran($f3)
    {
        $this->checkRole($f3);
        header('Content-Type: application/json');

        if (!$this->dbSerti) {
            echo json_encode(['success' => false, 'data' => [], 'message' => 'Database sertifikasi tidak tersedia']);
            return;
        }

        $search      = trim((string)($f3->get('GET.search') ?? ''));
        $filterTahun = trim((string)($f3->get('GET.tahun') ?? ''));

        // ── Tentukan filter PIC ──────────────────────────────────────────
        // Ambil username login saat ini, lalu telusuri ke id_user sil2020
        $username   = $f3->get('SESSION.username') ?? '';
        $picIdUser  = $this->getPicIdUser($username);
        $customerIds = $picIdUser ? $this->getCustomerIdsByPic($picIdUser) : [];

        $where  = "WHERE 1=1";
        $params = [];

        // Filter berdasarkan customer yang ditangani PIC ini
        if (!empty($customerIds)) {
            $placeholders = implode(',', array_fill(0, count($customerIds), '?'));
            $where  .= " AND a.id_customer IN ($placeholders)";
            $params  = array_merge($params, $customerIds);
        } elseif ($picIdUser) {
            // PIC ditemukan di sil2020 tapi tidak ada customer → tidak ada data
            $where .= " AND 1=0";
        }
        // Jika picIdUser null (user belum terhubung ke sil2020), tampilkan semua

        if ($search !== '') {
            $where  .= " AND (a.no_id_order LIKE ? OR COALESCE(j.nama_jenis_sertifikasi,'') LIKE ?)";
            $term    = "%{$search}%";
            $params[] = $term;
            $params[] = $term;
        }

        if ($filterTahun !== '') {
            $where  .= " AND YEAR(a.tanggal_pendaftaran) = ?";
            $params[] = $filterTahun;
        }

        // Ambil ID dari portal_selport (tempat pelanggan upload berkas)
        $idsP = $this->getIdsWithPendaftaranFiles();
        $inP = !empty($idsP) ? implode(',', $idsP) : "0";

        // Filter hanya order yang sudah ada berkas di portal_selport
        $where .= " AND a.id_arsip_sertifikasi IN ($inP)";



        try {
            $rows = $this->dbSerti->exec(
                "SELECT
                    a.id_arsip_sertifikasi,
                    a.no_id_order,
                    DATE_FORMAT(a.tanggal_pendaftaran,'%d-%m-%Y') AS tanggal_pendaftaran,
                    COALESCE(j.nama_jenis_sertifikasi,'-') AS nama_jenis_sertifikasi,
                    COALESCE(j.singkatan,'') AS singkatan,
                    COALESCE(a.jumlah_biaya_sertifikasi,0) AS jumlah_biaya_sertifikasi,
                    COALESCE(a.jumlah_biaya_uji,0) AS jumlah_biaya_uji,
                    COALESCE(a.jumlah_biaya_transportasi,0) AS jumlah_biaya_transportasi,
                    COALESCE(a.jumlah_biaya_akomodasi,0) AS jumlah_biaya_akomodasi,
                    t.keuangan_tanggal_bayar,
                    t.katim_tanggal_kirim_ke_tim_auditor,
                    t.lead_auditor_kirim_hasil_audit_ke_pelanggan,
                    t.tanggal_validasi_vtp,
                    t.katim_tanggal_kirim_untuk_rapat_komite,
                    a.id_customer
                FROM tb_arsip_sertifikasi a
                LEFT JOIN mst_jenis_sertifikasi j ON a.id_jenis_sertifikasi = j.id_jenis_sertifikasi
                LEFT JOIN tb_arsip_sertifikasi_tracking t ON a.id_arsip_sertifikasi = t.id_arsip_sertifikasi
                $where
                ORDER BY a.tanggal_pendaftaran DESC
                LIMIT 200",
                $params
            );

            // Lampirkan nama perusahaan (dari registrasi akun pelanggan - portal_selport) dan status berkas
            $rowsData = $rows ?: [];
            if (!empty($rowsData) && $this->db) {
                // Kumpulkan semua id_customer unik
                $idCusts = array_unique(array_column($rowsData, 'id_customer'));
                $custMap = [];
                if (!empty($idCusts)) {
                    $ph = implode(',', array_fill(0, count($idCusts), '?'));
                    try {
                        $custRows = $this->db->exec(
                            "SELECT u.id_customer, c.nama_perusahaan, u.nama_lengkap, u.username 
                             FROM users u 
                             LEFT JOIN companies c ON u.company_id = c.id 
                             WHERE u.id_customer IN ($ph)",
                            $idCusts
                        );
                        foreach ($custRows ?: [] as $cr) {
                            $custMap[(int)$cr['id_customer']] = $cr['nama_perusahaan'] ?: ($cr['nama_lengkap'] ?: $cr['username']);
                        }
                    } catch (\Exception $e) {}
                }
                foreach ($rowsData as &$r) {
                    $r['nama_perusahaan'] = $custMap[(int)$r['id_customer']] ?? '-';
                }
                unset($r);
            }

            // Lampirkan jumlah file yang sudah diupload dari portal_selport
            // Sekaligus ambil tanggal_upload_berkas sebagai Tanggal Upload yang akurat
            if (!empty($rowsData)) {
                $arsipIds = array_column($rowsData, 'id_arsip_sertifikasi');
                $berkasMap = [];
                if (!empty($arsipIds)) {
                    $ph = implode(',', array_fill(0, count($arsipIds), '?'));
                    try {
                        $berkasRows = $this->db->exec(
                            "SELECT id_arsip_sertifikasi,
                                tanggal_upload_berkas,
                                (
                                    (CASE WHEN b7104_nama_berkas_surat_permohonan IS NOT NULL AND b7104_nama_berkas_surat_permohonan != '' THEN 1 ELSE 0 END) +
                                    (CASE WHEN b7104_nama_berkas_permohonan_sertifikasi_produk IS NOT NULL AND b7104_nama_berkas_permohonan_sertifikasi_produk != '' THEN 1 ELSE 0 END) +
                                    (CASE WHEN b7104_nama_berkas_informasi_sertifikasi_produk IS NOT NULL AND b7104_nama_berkas_informasi_sertifikasi_produk != '' THEN 1 ELSE 0 END) +
                                    (CASE WHEN b7104_nama_berkas_akte_perusahaan IS NOT NULL AND b7104_nama_berkas_akte_perusahaan != '' THEN 1 ELSE 0 END) +
                                    (CASE WHEN b7104_nama_berkas_izin_usaha_industri IS NOT NULL AND b7104_nama_berkas_izin_usaha_industri != '' THEN 1 ELSE 0 END) +
                                    (CASE WHEN b7104_nama_berkas_sertifikat_merek IS NOT NULL AND b7104_nama_berkas_sertifikat_merek != '' THEN 1 ELSE 0 END) +
                                    (CASE WHEN b7104_nama_berkas_sertifikat_merek_2 IS NOT NULL AND b7104_nama_berkas_sertifikat_merek_2 != '' THEN 1 ELSE 0 END) +
                                    (CASE WHEN b7104_nama_berkas_alur_proses_produksi IS NOT NULL AND b7104_nama_berkas_alur_proses_produksi != '' THEN 1 ELSE 0 END) +
                                    (CASE WHEN b7104_nama_berkas_ilustrasi_cara_pembubuhan_tanda_sni IS NOT NULL AND b7104_nama_berkas_ilustrasi_cara_pembubuhan_tanda_sni != '' THEN 1 ELSE 0 END) +
                                    (CASE WHEN b7104_nama_berkas_struktur_organisasi_perusahaan IS NOT NULL AND b7104_nama_berkas_struktur_organisasi_perusahaan != '' THEN 1 ELSE 0 END) +
                                    (CASE WHEN b7104_nama_berkas_daftar_peralatan_pengujian IS NOT NULL AND b7104_nama_berkas_daftar_peralatan_pengujian != '' THEN 1 ELSE 0 END) +
                                    (CASE WHEN b7104_nama_berkas_kopi_laporan_sertifikat IS NOT NULL AND b7104_nama_berkas_kopi_laporan_sertifikat != '' THEN 1 ELSE 0 END) +
                                    (CASE WHEN b7104_nama_berkas_pedoman_mutu IS NOT NULL AND b7104_nama_berkas_pedoman_mutu != '' THEN 1 ELSE 0 END) +
                                    (CASE WHEN b7104_nama_berkas_daftar_dokumentasi_smm IS NOT NULL AND b7104_nama_berkas_daftar_dokumentasi_smm != '' THEN 1 ELSE 0 END) +
                                    (CASE WHEN b7104_nama_berkas_kopi_sertifikat_sistem_manajemen_mutu IS NOT NULL AND b7104_nama_berkas_kopi_sertifikat_sistem_manajemen_mutu != '' THEN 1 ELSE 0 END) +
                                    (CASE WHEN b7104_nama_berkas_sipa_surat_keterangan_kerjasama IS NOT NULL AND b7104_nama_berkas_sipa_surat_keterangan_kerjasama != '' THEN 1 ELSE 0 END) +
                                    (CASE WHEN b7104_nama_berkas_sertifikat_hasil_uji_air_baku IS NOT NULL AND b7104_nama_berkas_sertifikat_hasil_uji_air_baku != '' THEN 1 ELSE 0 END)
                                ) AS jumlah_berkas_upload
                            FROM tb_arsip_sertifikasi_berkas
                            WHERE id_arsip_sertifikasi IN ($ph)",
                            $arsipIds
                        );
                        foreach ($berkasRows ?: [] as $br) {
                            $berkasMap[(int)$br['id_arsip_sertifikasi']] = [
                                'jumlah'         => (int)$br['jumlah_berkas_upload'],
                                'tanggal_upload' => $br['tanggal_upload_berkas'] ?? null,
                            ];
                        }
                    } catch (\Exception $e) {}
                }
                foreach ($rowsData as &$r) {
                    $bInfo = $berkasMap[(int)$r['id_arsip_sertifikasi']] ?? [];
                    $r['jumlah_berkas_upload'] = $bInfo['jumlah'] ?? 0;

                    // Gunakan tanggal_upload_berkas dari portal sebagai Tanggal Upload yang akurat.
                    // Jika belum ada (data lama), fallback ke tanggal_pendaftaran dari serti2020.
                    $tglUpload = $bInfo['tanggal_upload'] ?? null;
                    if (!empty($tglUpload) && $tglUpload !== '0000-00-00 00:00:00') {
                        $r['tanggal_pendaftaran'] = date('d-m-Y', strtotime($tglUpload));
                    }
                    // else: biarkan tanggal_pendaftaran dari serti2020 sebagai fallback
                }
                unset($r);
            }

            // Lampirkan status_berkas dari tabel lokal portal
            if (!empty($rowsData)) {
                $this->ensureStatusTable();
                $arsipIds = array_column($rowsData, 'id_arsip_sertifikasi');
                $statusMap = [];
                if (!empty($arsipIds)) {
                    $ph = implode(',', array_fill(0, count($arsipIds), '?'));
                    try {
                        $statusRows = $this->db->exec(
                            "SELECT id_arsip_sertifikasi, status FROM sertifikasi_berkas_status WHERE id_arsip_sertifikasi IN ($ph)",
                            $arsipIds
                        );
                        foreach ($statusRows ?: [] as $sr) {
                            $statusMap[(int)$sr['id_arsip_sertifikasi']] = $sr['status'];
                        }
                    } catch (\Exception $e) {}
                }
                foreach ($rowsData as &$r) {
                    $r['status_berkas'] = $statusMap[(int)$r['id_arsip_sertifikasi']] ?? 'pending';
                }
                unset($r);
            }

            echo json_encode(['success' => true, 'data' => $rowsData, 'pic_id_user' => $picIdUser]);
        } catch (\Exception $e) {
            echo json_encode(['success' => false, 'data' => [], 'message' => $e->getMessage()]);
        }
    }

    /**
     * Ambil detail berkas yang sudah diupload pelanggan untuk order sertifikasi tertentu
     * Digunakan oleh pegawai sertifikasi untuk melihat dokumen yang dikirim pelanggan
     */
    public function getBerkasFiles($f3)
    {
        $this->checkRole($f3);
        header('Content-Type: application/json');

        $idArsip = trim((string)($f3->get('GET.id_arsip_sertifikasi') ?? ''));
        if (!$idArsip || !is_numeric($idArsip)) {
            echo json_encode(['success' => false, 'message' => 'ID arsip tidak valid']);
            return;
        }

        // Verifikasi bahwa arsip ini memang milik customer yang ditangani PIC ini
        $username   = $f3->get('SESSION.username') ?? '';
        $picIdUser  = $this->getPicIdUser($username);
        if ($picIdUser) {
            $customerIds = $this->getCustomerIdsByPic($picIdUser);
            if (!empty($customerIds) && $this->dbSerti) {
                try {
                    $ph  = implode(',', array_fill(0, count($customerIds), '?'));
                    $chk = $this->dbSerti->exec(
                        "SELECT id_arsip_sertifikasi FROM tb_arsip_sertifikasi
                         WHERE id_arsip_sertifikasi = ? AND id_customer IN ($ph) LIMIT 1",
                        array_merge([(int)$idArsip], $customerIds)
                    );
                    if (empty($chk)) {
                        echo json_encode(['success' => false, 'message' => 'Akses ditolak: arsip tidak ditangani oleh Anda']);
                        return;
                    }
                } catch (\Exception $e) {
                    // Jika error verifikasi, lanjut saja
                }
            }
        }

        try {
            // Baca dari portal_selport (tempat pelanggan upload)
            $rows = $this->db->exec(
                "SELECT * FROM tb_arsip_sertifikasi_berkas WHERE id_arsip_sertifikasi = ? LIMIT 1",
                [(int)$idArsip]
            );

            // Baca dari serti2020 (berkas yang sudah diverifikasi admin)
            $rowsSerti = [];
            if ($this->dbSerti) {
                $rowsSerti = $this->dbSerti->exec(
                    "SELECT * FROM tb_arsip_sertifikasi_berkas WHERE id_arsip_sertifikasi = ? LIMIT 1",
                    [(int)$idArsip]
                );
            }

            if (empty($rows) && empty($rowsSerti)) {
                echo json_encode(['success' => true, 'data' => null, 'has_berkas' => false]);
                return;
            }

            // Jika pelanggan belum upload tapi admin sudah ada datanya, gunakan admin
            $berkas = !empty($rows) ? $rows[0] : (!empty($rowsSerti) ? $rowsSerti[0] : []);
            $berkasSerti = !empty($rowsSerti) ? $rowsSerti[0] : [];

            // Definisi label untuk setiap field berkas
            $fieldLabels = [
                'b7104_nama_berkas_surat_permohonan'                    => 'Surat Permohonan SPPT SNI',
                'b7104_nama_berkas_permohonan_sertifikasi_produk'       => '2.1 Permohonan Sertifikasi Produk (F.7.1.0.1)',
                'b7104_nama_berkas_informasi_sertifikasi_produk'        => '2.2 Informasi Sertifikasi Produk (F.7.1.0.2)',
                'b7104_nama_berkas_akte_perusahaan'                     => '2.3 Akte Perusahaan',
                'b7104_nama_berkas_izin_usaha_industri'                 => '2.4 Izin Usaha Industri',
                'b7104_nama_berkas_sertifikat_merek'                    => '2.5 Sertifikat Merek/Surat Pendaftaran Merek dari Dirjen HAKI atau',
                'b7104_nama_berkas_sertifikat_merek_2'                  => 'Surat Pelimpahan Merek antara pemilik merek dengan pihak-pihak lain yang terkait terhadap penggunaan merek tersebut (Produsen atau Importir)',
                'b7104_nama_berkas_alur_proses_produksi'                => '2.6 Alur Proses Produksi',
                'b7104_nama_berkas_ilustrasi_cara_pembubuhan_tanda_sni' => '2.7 Ilustrasi dan Cara Pembubuhan Tanda SNI',
                'b7104_nama_berkas_struktur_organisasi_perusahaan'      => '2.8 Struktur Organisasi Perusahaan',
                'b7104_nama_berkas_daftar_peralatan_pengujian'          => '2.9 Daftar Peralatan Inspeksi/Pengujian',
                'b7104_nama_berkas_kopi_laporan_sertifikat'             => '2.10 Kopi Laporan/Sertifikat Kalibrasi Peralatan',
                'b7104_nama_berkas_pedoman_mutu'                        => '3.1 Pedoman Mutu',
                'b7104_nama_berkas_daftar_dokumentasi_smm'              => '3.2 Daftar Dokumentasi SMM',
                'b7104_nama_berkas_kopi_sertifikat_sistem_manajemen_mutu' => '3.3 Kopi Sertifikat Sistem Manajemen Mutu',
                'b7104_nama_berkas_sipa_surat_keterangan_kerjasama'     => '4.1 SIPA atau Surat Keterangan Kerjasama (AMDK)',
                'b7104_nama_berkas_sertifikat_hasil_uji_air_baku'       => '4.2 Sertifikat Hasil Uji Air Baku (AMDK)',
            ];

            $files = [];
            foreach ($fieldLabels as $field => $label) {
                $fileName = $berkas[$field] ?? '';
                $fileNameSerti = $berkasSerti[$field] ?? '';
                
                $status = 'belum';
                if (!empty($fileName)) {
                    if ($fileName === $fileNameSerti || empty($fileNameSerti)) {
                        $status = 'diverifikasi';
                    } else {
                        $status = 'reupload';
                    }
                }
                
                $fileUrl = '';
                if (!empty($fileName)) {
                    $token = doc_make_token($fileName, 'serti-pendaftaran');
                    $fileUrl = '/dokumen/view/' . $token;
                }

                $files[] = [
                    'label'       => $label,
                    'field'       => $field,
                    'file_name'   => $fileName ?: null,
                    'uploaded'    => !empty($fileName),
                    'file_status' => $status,
                    'file_url'    => $fileUrl
                ];
            }

            $jumlahUpload = count(array_filter($files, function($f) { return $f['uploaded']; }));

            echo json_encode([
                'success'       => true,
                'has_berkas'    => true,
                'jumlah_upload' => $jumlahUpload,
                'total_berkas'  => count($files),
                'files'         => $files,
            ]);
        } catch (\Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    /**
     * Ambil daftar berkas verifikasi tindakan perbaikan
     */
    public function getBerkasVerifikasi($f3)
    {
        $this->checkRole($f3);
        header('Content-Type: application/json');

        if (!$this->dbSerti) {
            echo json_encode(['success' => false, 'data' => [], 'message' => 'Database sertifikasi tidak tersedia']);
            return;
        }

        $search      = trim((string)($f3->get('GET.search') ?? ''));
        $filterTahun = trim((string)($f3->get('GET.tahun') ?? ''));

        // ── Filter PIC ──
        $username    = $f3->get('SESSION.username') ?? '';
        $picIdUser   = $this->getPicIdUser($username);
        $customerIds = $picIdUser ? $this->getCustomerIdsByPic($picIdUser) : [];

        // Ambil ID dari portal_selport (tempat pelanggan upload berkas VTP)
        $idsV = $this->getIdsWithVerifikasiFiles();
        $inV = !empty($idsV) ? implode(',', $idsV) : "0";

        // Filter hanya order yang sudah ada berkas VTP di portal_selport
        $where  = "WHERE a.id_arsip_sertifikasi IN ($inV)";
        $params = [];

        if (!empty($customerIds)) {
            $placeholders = implode(',', array_fill(0, count($customerIds), '?'));
            $where  .= " AND a.id_customer IN ($placeholders)";
            $params  = array_merge($params, $customerIds);
        } elseif ($picIdUser) {
            $where .= " AND 1=0";
        }

        if ($search !== '') {
            $where  .= " AND (a.no_id_order LIKE ? OR COALESCE(j.nama_jenis_sertifikasi,'') LIKE ?)";
            $term    = "%{$search}%";
            $params[] = $term;
            $params[] = $term;
        }

        if ($filterTahun !== '') {
            $where  .= " AND YEAR(a.tanggal_pendaftaran) = ?";
            $params[] = $filterTahun;
        }


        try {
            $rows = $this->dbSerti->exec(
                "SELECT
                    a.id_arsip_sertifikasi,
                    a.no_id_order,
                    DATE_FORMAT(a.tanggal_pendaftaran,'%d-%m-%Y') AS tanggal_pendaftaran,
                    COALESCE(j.nama_jenis_sertifikasi,'-') AS nama_jenis_sertifikasi,
                    COALESCE(j.singkatan,'') AS singkatan,
                    DATE_FORMAT(t.lead_auditor_kirim_hasil_audit_ke_pelanggan,'%d-%m-%Y') AS tgl_laporan_audit,
                    DATE_FORMAT(t.tanggal_validasi_vtp,'%d-%m-%Y') AS tgl_validasi_vtp,
                    t.tanggal_validasi_vtp,
                    a.id_customer
                FROM tb_arsip_sertifikasi a
                LEFT JOIN mst_jenis_sertifikasi j ON a.id_jenis_sertifikasi = j.id_jenis_sertifikasi
                INNER JOIN tb_arsip_sertifikasi_tracking t ON a.id_arsip_sertifikasi = t.id_arsip_sertifikasi
                $where
                ORDER BY a.tanggal_pendaftaran DESC
                LIMIT 200",
                $params
            );

            // Lampirkan nama perusahaan (dari registrasi akun pelanggan - portal_selport)
            $rowsData = $rows ?: [];
            if (!empty($rowsData) && $this->db) {
                $idCusts = array_unique(array_column($rowsData, 'id_customer'));
                $custMap = [];
                if (!empty($idCusts)) {
                    $ph = implode(',', array_fill(0, count($idCusts), '?'));
                    try {
                        $custRows = $this->db->exec(
                            "SELECT u.id_customer, c.nama_perusahaan, u.nama_lengkap, u.username 
                             FROM users u 
                             LEFT JOIN companies c ON u.company_id = c.id 
                             WHERE u.id_customer IN ($ph)",
                            $idCusts
                        );
                        foreach ($custRows ?: [] as $cr) {
                            $custMap[(int)$cr['id_customer']] = $cr['nama_perusahaan'] ?: ($cr['nama_lengkap'] ?: $cr['username']);
                        }
                    } catch (\Exception $e) {}
                }
                foreach ($rowsData as &$r) {
                    $r['nama_perusahaan'] = $custMap[(int)$r['id_customer']] ?? '-';
                }
                unset($r);
            }
            if (!empty($rowsData)) {
                $this->ensureStatusTable();
                $arsipIds = array_column($rowsData, 'id_arsip_sertifikasi');
                $statusMap = [];
                if (!empty($arsipIds)) {
                    $ph = implode(',', array_fill(0, count($arsipIds), '?'));
                    try {
                        $statusRows = $this->db->exec(
                            "SELECT id_arsip_sertifikasi, status_vtp, status_vtp_1, status_vtp_2, status_vtp_3, updated_at FROM sertifikasi_berkas_status WHERE id_arsip_sertifikasi IN ($ph)",
                            $arsipIds
                        );
                        foreach ($statusRows ?: [] as $sr) {
                            $statusMap[(int)$sr['id_arsip_sertifikasi']] = [
                                'status_vtp'    => $sr['status_vtp'],
                                'status_vtp_1'  => $sr['status_vtp_1'] ?? 'pending',
                                'status_vtp_2'  => $sr['status_vtp_2'] ?? 'pending',
                                'status_vtp_3'  => $sr['status_vtp_3'] ?? 'pending',
                                'tgl_upload_vtp'=> $sr['updated_at'],
                            ];
                        }
                    } catch (\Exception $e) {}
                }
                foreach ($rowsData as &$r) {
                    $map = $statusMap[(int)$r['id_arsip_sertifikasi']] ?? [];
                    $r['status_vtp']     = $map['status_vtp']     ?? 'pending';
                    $r['status_vtp_1']   = $map['status_vtp_1']   ?? 'pending';
                    $r['status_vtp_2']   = $map['status_vtp_2']   ?? 'pending';
                    $r['status_vtp_3']   = $map['status_vtp_3']   ?? 'pending';
                    $r['tgl_upload_vtp'] = $map['tgl_upload_vtp'] ?? null;
                }
                unset($r);
            }

            echo json_encode(['success' => true, 'data' => $rowsData]);
        } catch (\Exception $e) {
            echo json_encode(['success' => false, 'data' => [], 'message' => $e->getMessage()]);
        }
    }

    public function getBerkasVerifikasiFiles($f3)
    {
        $this->checkRole($f3);
        header('Content-Type: application/json');

        $idArsip = trim((string)($f3->get('GET.id_arsip_sertifikasi') ?? ''));
        if (!$idArsip) {
            echo json_encode(['success' => false, 'message' => 'ID arsip tidak valid']);
            return;
        }

        try {
            // Baca dari portal_selport (tempat pelanggan upload)
            $rows = $this->db->exec(
                "SELECT * FROM tb_arsip_sertifikasi_berkas WHERE id_arsip_sertifikasi = ? LIMIT 1",
                [$idArsip]
            );

            if (empty($rows) && $this->dbSerti) {
                $rows = $this->dbSerti->exec(
                    "SELECT * FROM tb_arsip_sertifikasi_berkas WHERE id_arsip_sertifikasi = ? LIMIT 1",
                    [$idArsip]
                );
            }

            if (empty($rows)) {
                echo json_encode(['success' => true, 'data' => null, 'has_berkas' => false]);
                return;
            }

            $berkas = $rows[0];

            // Definisi label untuk VTP
            $fieldLabels = [
                'formulir_verifikasi_tindakan_perbaikan'   => 'Verifikasi Tindakan Perbaikan 1',
                'formulir_verifikasi_tindakan_perbaikan_2' => 'Verifikasi Tindakan Perbaikan 2',
                'formulir_verifikasi_tindakan_perbaikan_3' => 'Verifikasi Tindakan Perbaikan 3',
            ];

            $files = [];
            
            // Ambil status per slot dari tabel status
            $statusRow = [];
            try {
                $statusRes = $this->db->exec(
                    "SELECT status_vtp_1, status_vtp_2, status_vtp_3 FROM sertifikasi_berkas_status WHERE id_arsip_sertifikasi = ? LIMIT 1",
                    [$idArsip]
                );
                if (!empty($statusRes)) {
                    $statusRow = $statusRes[0];
                }
            } catch (\Exception $e) {}

            $idx = 1;
            foreach ($fieldLabels as $field => $label) {
                $fileName = $berkas[$field] ?? '';
                
                $fileUrl = '';
                if (!empty($fileName)) {
                    $token = doc_make_token($fileName, 'serti-verifikasi-pelanggan');
                    $fileUrl = '/dokumen/view/' . $token;
                }

                $statusSlot = $statusRow["status_vtp_$idx"] ?? 'pending';

                $files[] = [
                    'label'     => $label,
                    'field'     => $field,
                    'file_name' => $fileName ?: null,
                    'uploaded'  => !empty($fileName),
                    'file_url'  => $fileUrl,
                    'status'    => $statusSlot
                ];
                $idx++;
            }

            $jumlahUpload = count(array_filter($files, function($f) { return $f['uploaded']; }));

            echo json_encode([
                'success'       => true,
                'has_berkas'    => true,
                'jumlah_upload' => $jumlahUpload,
                'total_berkas'  => count($files),
                'files'         => $files,
            ]);
        } catch (\Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    public function updateStatusVerifikasi($f3)
    {
        $this->checkRole($f3);
        header('Content-Type: application/json');

        if (!$this->dbSerti) {
            echo json_encode(['success' => false, 'message' => 'Database sertifikasi tidak tersedia']);
            return;
        }

        $idArsip = trim((string)($f3->get('POST.id_arsip_sertifikasi') ?? ''));

        if (!$idArsip || !is_numeric($idArsip)) {
            echo json_encode(['success' => false, 'message' => 'ID arsip tidak valid']);
            return;
        }

        $valid = ['pending', 'belum_memenuhi', 'diverifikasi'];
        $s1 = trim((string)($f3->get('POST.status_vtp_1') ?? 'pending'));
        $s2 = trim((string)($f3->get('POST.status_vtp_2') ?? 'pending'));
        $s3 = trim((string)($f3->get('POST.status_vtp_3') ?? 'pending'));
        if (!in_array($s1, $valid)) $s1 = 'pending';
        if (!in_array($s2, $valid)) $s2 = 'pending';
        if (!in_array($s3, $valid)) $s3 = 'pending';

        try {
            $this->ensureStatusTable();

            // Cek slot mana yang benar-benar ada file-nya
            $berkasRows = [];
            try {
                $berkasRows = $this->db->exec(
                    "SELECT formulir_verifikasi_tindakan_perbaikan AS f1,
                            formulir_verifikasi_tindakan_perbaikan_2 AS f2,
                            formulir_verifikasi_tindakan_perbaikan_3 AS f3
                     FROM tb_arsip_sertifikasi_berkas WHERE id_arsip_sertifikasi = ? LIMIT 1",
                    [(int)$idArsip]
                ) ?: [];
            } catch (\Exception $e) {}

            $hasFile1 = !empty($berkasRows[0]['f1'] ?? '');
            $hasFile2 = !empty($berkasRows[0]['f2'] ?? '');
            $hasFile3 = !empty($berkasRows[0]['f3'] ?? '');

            // Hitung status utama hanya dari slot yang ada file-nya
            $uploadedStatuses = [];
            if ($hasFile1) $uploadedStatuses[] = $s1;
            if ($hasFile2) $uploadedStatuses[] = $s2;
            if ($hasFile3) $uploadedStatuses[] = $s3;

            if (empty($uploadedStatuses)) {
                $mainStatus = 'pending';
            } elseif (in_array('belum_memenuhi', $uploadedStatuses)) {
                $mainStatus = 'belum_memenuhi';
            } elseif (in_array('pending', $uploadedStatuses)) {
                $mainStatus = 'pending';
            } else {
                $mainStatus = 'diverifikasi';
            }

            // Upsert ke sertifikasi_berkas_status
            $existing = $this->db->exec(
                "SELECT id FROM sertifikasi_berkas_status WHERE id_arsip_sertifikasi = ? LIMIT 1",
                [(int)$idArsip]
            );
            if (!empty($existing)) {
                $this->db->exec(
                    "UPDATE sertifikasi_berkas_status SET status_vtp = ?, status_vtp_1 = ?, status_vtp_2 = ?, status_vtp_3 = ? WHERE id_arsip_sertifikasi = ?",
                    [$mainStatus, $s1, $s2, $s3, (int)$idArsip]
                );
            } else {
                $this->db->exec(
                    "INSERT INTO sertifikasi_berkas_status (id_arsip_sertifikasi, status_vtp, status_vtp_1, status_vtp_2, status_vtp_3) VALUES (?, ?, ?, ?, ?)",
                    [(int)$idArsip, $mainStatus, $s1, $s2, $s3]
                );
            }
            if ($mainStatus === 'diverifikasi') {
                $this->dbSerti->exec(
                    "UPDATE tb_arsip_sertifikasi_tracking SET tanggal_validasi_vtp = CURDATE() WHERE id_arsip_sertifikasi = ?",
                    [$idArsip]
                );

                // Copy/forward data dari portal_selport ke serti2020
                try {
                    $portalBerkas = $this->db->exec(
                        "SELECT * FROM tb_arsip_sertifikasi_berkas WHERE id_arsip_sertifikasi = ? LIMIT 1",
                        [(int)$idArsip]
                    ) ?: [];

                    $sertiExisting = $this->dbSerti->exec(
                        "SELECT * FROM tb_arsip_sertifikasi_berkas WHERE id_arsip_sertifikasi = ? LIMIT 1",
                        [(int)$idArsip]
                    ) ?: [];

                    if (!empty($portalBerkas)) {
                        $portalRow = $portalBerkas[0];
                        $updates = [];
                        foreach ($portalRow as $colName => $colVal) {
                            if ($colName === 'id_berkas' || $colName === 'id_arsip_sertifikasi') continue;
                            if ($colVal !== null && $colVal !== '') {
                                $updates[$colName] = $colVal;
                            }
                        }

                        if (!empty($sertiExisting)) {
                            if (!empty($updates)) {
                                $set = []; $params = [];
                                foreach ($updates as $f => $v) { $set[] = "`$f` = ?"; $params[] = $v; }
                                $params[] = (int)$idArsip;
                                $this->dbSerti->exec(
                                    "UPDATE tb_arsip_sertifikasi_berkas SET " . implode(', ', $set) . " WHERE id_arsip_sertifikasi = ?",
                                    $params
                                );
                            }
                        } else {
                            $fields = ['id_arsip_sertifikasi']; $ph = ['?']; $params = [(int)$idArsip];
                            foreach ($updates as $f => $v) { $fields[] = "`$f`"; $ph[] = '?'; $params[] = $v; }
                            $this->dbSerti->exec(
                                "INSERT INTO tb_arsip_sertifikasi_berkas (" . implode(',', $fields) . ") VALUES (" . implode(',', $ph) . ")",
                                $params
                            );
                        }
                    } elseif (empty($sertiExisting)) {
                        $this->dbSerti->exec(
                            "INSERT INTO tb_arsip_sertifikasi_berkas (id_arsip_sertifikasi) VALUES (?)",
                            [(int)$idArsip]
                        );
                    }
                } catch (\Exception $eTransfer) {
                    error_log("[SERTI_TRANSFER_VTP] Gagal transfer data VTP: " . $eTransfer->getMessage());
                }

                // Kirim notifikasi ke pelanggan
                if ($this->notifikasiService) {
                    try {
                        $arsip = $this->dbSerti->exec("SELECT id_customer, nomor_order FROM tb_arsip_sertifikasi WHERE id_arsip_sertifikasi = ? LIMIT 1", [(int)$idArsip]);
                        if (!empty($arsip) && !empty($arsip[0]['id_customer'])) {
                            $users = $this->db->exec("SELECT id FROM users WHERE id_customer = ?", [$arsip[0]['id_customer']]);
                            if (!empty($users)) {
                                $noOrder = $arsip[0]['nomor_order'] ?? '-';
                                foreach ($users as $u) {
                                    $this->notifikasiService->create(
                                        $u['id'],
                                        'Tindakan Perbaikan Disetujui',
                                        "Dokumen tindakan perbaikan untuk order <b>{$noOrder}</b> telah disetujui oleh tim sertifikasi.",
                                        'success',
                                        '/pelanggan/dashboard/sertifikasi/detail?id=' . $idArsip
                                    );
                                }
                            }
                        }
                    } catch (\Exception $eNotif) {
                        error_log("Gagal mengirim notif VTP disetujui: " . $eNotif->getMessage());
                    }
                }
            } else {
                try {
                    $this->dbSerti->exec(
                        "UPDATE tb_arsip_sertifikasi_tracking SET tanggal_validasi_vtp = '0000-00-00' WHERE id_arsip_sertifikasi = ?",
                        [$idArsip]
                    );
                } catch (\Exception $e) {}
            }
            echo json_encode(['success' => true, 'message' => 'Status verifikasi berhasil diperbarui']);
        } catch (\Exception $e) {
            echo json_encode(['success' => false, 'message' => 'Kesalahan database: ' . $e->getMessage()]);
        }
    }

    /**
     * Batalkan (clear) file VTP tertentu agar pelanggan bisa upload ulang
     */
    public function cancelBerkasVTP($f3)
    {
        $this->checkRole($f3);
        header('Content-Type: application/json');

        $idArsip = trim((string)($f3->get('POST.id_arsip_sertifikasi') ?? ''));
        $field   = trim((string)($f3->get('POST.field') ?? ''));

        if (!$idArsip || !is_numeric($idArsip)) {
            echo json_encode(['success' => false, 'message' => 'ID arsip tidak valid']);
            return;
        }

        // Whitelist: hanya field VTP yang boleh di-cancel
        $allowedFields = [
            'formulir_verifikasi_tindakan_perbaikan',
            'formulir_verifikasi_tindakan_perbaikan_2',
            'formulir_verifikasi_tindakan_perbaikan_3',
        ];

        if (!in_array($field, $allowedFields)) {
            echo json_encode(['success' => false, 'message' => 'Field tidak valid']);
            return;
        }

        try {
            // Ambil data file sebelum dihapus dari database
            $oldData = $this->db->exec(
                "SELECT `{$field}` as nama_file FROM tb_arsip_sertifikasi_berkas WHERE id_arsip_sertifikasi = ? LIMIT 1",
                [(int)$idArsip]
            );
            $fileName = !empty($oldData) ? $oldData[0]['nama_file'] : null;

            // Clear field di portal_selport
            $this->db->exec(
                "UPDATE tb_arsip_sertifikasi_berkas SET `{$field}` = '' WHERE id_arsip_sertifikasi = ?",
                [(int)$idArsip]
            );

            // Juga clear di serti2020 jika tersedia
            if ($this->dbSerti) {
                try {
                    $this->dbSerti->exec(
                        "UPDATE tb_arsip_sertifikasi_berkas SET `{$field}` = '' WHERE id_arsip_sertifikasi = ?",
                        [(int)$idArsip]
                    );

                    // If VTP is canceled, ensure tanggal_validasi_vtp is also reset if needed
                    // tapi biarkan tracking tanggal_validasi_vtp tetap 0000-00-00 karena diclear.
                } catch (\Exception $e) {
                    // Tidak gagalkan proses jika serti2020 error
                }
            }

            // Hapus file fisik dari direktori agar tidak memenuhi storage
            if ($fileName) {
                $baseUploadDir = realpath(__DIR__ . '/../../public/uploads/sertifikasi') . DIRECTORY_SEPARATOR;
                $dirVtp = $baseUploadDir . 'berkas-verifikasi-perbaikan' . DIRECTORY_SEPARATOR;
                $filePath = $dirVtp . $fileName;
                if (file_exists($filePath)) {
                    @unlink($filePath);
                }
            }

            // Kirim notifikasi ke pelanggan bahwa VTP dibatalkan/perlu perbaikan
            if ($this->notifikasiService && $this->dbSerti) {
                try {
                    $arsip = $this->dbSerti->exec("SELECT id_customer, nomor_order FROM tb_arsip_sertifikasi WHERE id_arsip_sertifikasi = ? LIMIT 1", [(int)$idArsip]);
                    if (!empty($arsip) && !empty($arsip[0]['id_customer'])) {
                        $users = $this->db->exec("SELECT id FROM users WHERE id_customer = ?", [$arsip[0]['id_customer']]);
                        if (!empty($users)) {
                            $noOrder = $arsip[0]['nomor_order'] ?? '-';
                            foreach ($users as $u) {
                                $this->notifikasiService->create(
                                    $u['id'],
                                    'Perbaikan Berkas Tindakan Perbaikan',
                                    "Berkas tindakan perbaikan untuk order <b>{$noOrder}</b> telah dibatalkan atau memerlukan perbaikan. Silakan upload ulang.",
                                    'warning',
                                    '/pelanggan/dashboard/sertifikasi/detail?id=' . $idArsip
                                );
                            }
                        }
                    }
                } catch (\Exception $eNotif) {
                    error_log("Gagal mengirim notif VTP dibatalkan: " . $eNotif->getMessage());
                }
            }

            echo json_encode(['success' => true, 'message' => 'File berhasil dibatalkan. Pelanggan dapat mengupload ulang.']);
        } catch (\Exception $e) {
            echo json_encode(['success' => false, 'message' => 'Gagal membatalkan file: ' . $e->getMessage()]);
        }
    }

    /**
     * Update profil akun sertifikasi
     */
    public function updateAccount($f3)
    {
        $this->checkRole($f3);
        header('Content-Type: application/json');

        $userId      = $f3->get('SESSION.user_id');
        $namaLengkap = $f3->get('POST.nama_lengkap');
        $password    = $f3->get('POST.password');

        $user = $this->db->exec('SELECT * FROM users WHERE id = ?', [$userId]);
        if (empty($user)) {
            echo json_encode(['success' => false, 'message' => 'User tidak ditemukan']);
            return;
        }

        $avatarName = $user[0]['nama_avatar'] ?? 'avatar.png';

        // Handle avatar upload
        $files = $f3->get('FILES');
        if (!empty($files['avatar']['name']) && $files['avatar']['error'] === UPLOAD_ERR_OK) {
            $ext     = strtolower(pathinfo($files['avatar']['name'], PATHINFO_EXTENSION));
            $allowed = ['jpg', 'jpeg', 'png', 'gif'];

            if (!in_array($ext, $allowed)) {
                echo json_encode(['success' => false, 'message' => 'Format file tidak didukung']);
                return;
            }
            if ($files['avatar']['size'] > 2 * 1024 * 1024) {
                echo json_encode(['success' => false, 'message' => 'Ukuran file terlalu besar (maks 2MB)']);
                return;
            }

            $sanitizedName = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $user[0]['username'] ?? 'user'));
            $avatarName    = 'sertifikasi_' . $userId . '_' . $sanitizedName . '_' . time() . '.' . $ext;
            $destination   = 'public/avatar/' . $avatarName;

            if (move_uploaded_file($files['avatar']['tmp_name'], $destination)) {
                $this->db->exec('UPDATE users SET nama_avatar = ? WHERE id = ?', [$avatarName, $userId]);
                $f3->set('SESSION.nama_avatar', $avatarName);
            }
        }

        if ($namaLengkap && $namaLengkap !== $user[0]['nama_lengkap']) {
            $this->db->exec('UPDATE users SET nama_lengkap = ? WHERE id = ?', [$namaLengkap, $userId]);
            $f3->set('SESSION.nama_lengkap', $namaLengkap);
        }

        if ($password && strlen($password) >= 8) {
            $this->db->exec(
                'UPDATE users SET password = ? WHERE id = ?',
                [password_hash($password, PASSWORD_BCRYPT), $userId]
            );
        }

        echo json_encode([
            'success' => true,
            'message' => 'Profil berhasil diperbarui',
            'avatar'  => $avatarName,
            'nama'    => $namaLengkap ?: $user[0]['nama_lengkap'],
        ]);
    }

    /**
     * Pastikan tabel sertifikasi_berkas_status ada di database portal lokal
     */
    private function ensureStatusTable()
    {
        try {
            $this->db->exec(
                "CREATE TABLE IF NOT EXISTS sertifikasi_berkas_status (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    id_arsip_sertifikasi INT NOT NULL UNIQUE,
                    status VARCHAR(20) NOT NULL DEFAULT 'pending',
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
            );
            try { $this->db->exec("ALTER TABLE sertifikasi_berkas_status ADD COLUMN status_vtp VARCHAR(20) NOT NULL DEFAULT 'pending' AFTER status"); } catch (\Exception $e) {}
            try { $this->db->exec("ALTER TABLE sertifikasi_berkas_status ADD COLUMN status_vtp_1 VARCHAR(20) NOT NULL DEFAULT 'pending' AFTER status_vtp"); } catch (\Exception $e) {}
            try { $this->db->exec("ALTER TABLE sertifikasi_berkas_status ADD COLUMN status_vtp_2 VARCHAR(20) NOT NULL DEFAULT 'pending' AFTER status_vtp_1"); } catch (\Exception $e) {}
            try { $this->db->exec("ALTER TABLE sertifikasi_berkas_status ADD COLUMN status_vtp_3 VARCHAR(20) NOT NULL DEFAULT 'pending' AFTER status_vtp_2"); } catch (\Exception $e) {}
        } catch (\Exception $e) {}
    }

    /**
     * Update status berkas pendaftaran (pending / diverifikasi)
     */
    public function updateStatusBerkas($f3)
    {
        $this->checkRole($f3);
        header('Content-Type: application/json');

        $idArsip = trim((string)($f3->get('POST.id_arsip_sertifikasi') ?? ''));
        $status  = trim((string)($f3->get('POST.status') ?? ''));

        if (!$idArsip || !is_numeric($idArsip)) {
            echo json_encode(['success' => false, 'message' => 'ID arsip tidak valid']);
            return;
        }

        if (!in_array($status, ['pending', 'diverifikasi'])) {
            echo json_encode(['success' => false, 'message' => 'Status tidak valid']);
            return;
        }

        try {
            $this->ensureStatusTable();

            // Upsert: insert or update
            $existing = $this->db->exec(
                "SELECT id FROM sertifikasi_berkas_status WHERE id_arsip_sertifikasi = ? LIMIT 1",
                [(int)$idArsip]
            );

            if (!empty($existing)) {
                $this->db->exec(
                    "UPDATE sertifikasi_berkas_status SET status = ? WHERE id_arsip_sertifikasi = ?",
                    [$status, (int)$idArsip]
                );
            } else {
                $this->db->exec(
                    "INSERT INTO sertifikasi_berkas_status (id_arsip_sertifikasi, status) VALUES (?, ?)",
                    [(int)$idArsip, $status]
                );
            }

            // Copy/forward dari tb_arsip_sertifikasi_berkas di selport ke tb_arsip_sertifikasi_berkas di serti2020
            if ($status === 'diverifikasi' && $this->dbSerti) {
                try {
                    // Ambil data dari portal lokal
                    $portalBerkas = [];
                    try {
                        $portalBerkas = $this->db->exec(
                            "SELECT * FROM tb_arsip_sertifikasi_berkas WHERE id_arsip_sertifikasi = ? LIMIT 1",
                            [(int)$idArsip]
                        ) ?: [];
                    } catch (\Exception $ePortal) {
                        error_log("[SERTI_TRANSFER] Gagal baca portal berkas: " . $ePortal->getMessage());
                    }

                    // Cek apakah sudah ada di serti2020
                    $sertiExisting = $this->dbSerti->exec(
                        "SELECT * FROM tb_arsip_sertifikasi_berkas WHERE id_arsip_sertifikasi = ? LIMIT 1",
                        [(int)$idArsip]
                    ) ?: [];

                    if (!empty($portalBerkas)) {
                        // Data ada di portal, merge ke serti2020
                        $portalRow = $portalBerkas[0];

                        // Kumpulkan kolom yang akan di-set (selain id_berkas dan id_arsip_sertifikasi)
                        $updates = [];
                        foreach ($portalRow as $colName => $colVal) {
                            if ($colName === 'id_berkas') continue;
                            if ($colName === 'id_arsip_sertifikasi') continue;
                            // Hanya update kolom yang tidak null/kosong dari portal
                            if ($colVal !== null && $colVal !== '') {
                                $updates[$colName] = $colVal;
                            }
                        }

                        if (!empty($sertiExisting)) {
                            // UPDATE – hanya timpa kolom yang terisi dari portal
                            if (!empty($updates)) {
                                $set = []; $params = [];
                                foreach ($updates as $f => $v) { $set[] = "`$f` = ?"; $params[] = $v; }
                                $params[] = (int)$idArsip;
                                $this->dbSerti->exec(
                                    "UPDATE tb_arsip_sertifikasi_berkas SET " . implode(', ', $set) . " WHERE id_arsip_sertifikasi = ?",
                                    $params
                                );
                            }
                        } else {
                            // INSERT baru ke serti2020
                            $fields = ['id_arsip_sertifikasi']; $ph = ['?']; $params = [(int)$idArsip];
                            foreach ($updates as $f => $v) { $fields[] = "`$f`"; $ph[] = '?'; $params[] = $v; }
                            $this->dbSerti->exec(
                                "INSERT INTO tb_arsip_sertifikasi_berkas (" . implode(',', $fields) . ") VALUES (" . implode(',', $ph) . ")",
                                $params
                            );
                        }
                        error_log("[SERTI_TRANSFER] Berkas id_arsip=" . $idArsip . " berhasil di-transfer ke serti2020.");
                    } elseif (empty($sertiExisting)) {
                        // Tidak ada data di portal maupun serti, buat record kosong agar row tersedia
                        $this->dbSerti->exec(
                            "INSERT INTO tb_arsip_sertifikasi_berkas (id_arsip_sertifikasi) VALUES (?)",
                            [(int)$idArsip]
                        );
                        error_log("[SERTI_TRANSFER] Dibuat record kosong di serti2020 untuk id_arsip=" . $idArsip);
                    } else {
                        error_log("[SERTI_TRANSFER] Portal kosong tapi serti2020 sudah ada data untuk id_arsip=" . $idArsip . ", skip.");
                    }
                } catch (\Exception $eTransfer) {
                    // Jangan gagalkan proses validasi, tapi log error-nya
                    error_log("[SERTI_TRANSFER] Gagal transfer berkas ke serti2020: " . $eTransfer->getMessage());
                }
            }


            echo json_encode(['success' => true, 'message' => 'Status berkas berhasil diperbarui menjadi ' . ucfirst($status)]);
        } catch (\Exception $e) {
            echo json_encode(['success' => false, 'message' => 'Gagal menyimpan status: ' . $e->getMessage()]);
        }
    }

    // =========================================================================
    // PEMBERITAHUAN
    // =========================================================================

    private function ensurePemberitahuanTable()
    {
        try {
            $this->db->exec(
                "CREATE TABLE IF NOT EXISTS serti_pemberitahuan (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    judul VARCHAR(255) NOT NULL DEFAULT 'Pemberitahuan',
                    isi TEXT NOT NULL,
                    aktif TINYINT(1) NOT NULL DEFAULT 1,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
            );
        } catch (\Exception $e) {
            error_log("Gagal membuat tabel serti_pemberitahuan: " . $e->getMessage());
        }
    }

    /**
     * GET /sertifikasi/api/pemberitahuan
     * Bisa diakses oleh role sertifikasi (admin) maupun pelanggan (public GET)
     */
    public function getPemberitahuan($f3)
    {
        header('Content-Type: application/json');
        try {
            $this->ensurePemberitahuanTable();
            $rows = $this->db->exec("SELECT * FROM serti_pemberitahuan ORDER BY id DESC LIMIT 1");
            $data = !empty($rows) ? $rows[0] : [
                'id'         => null,
                'judul'      => '',
                'isi'        => '',
                'aktif'      => 1,
                'updated_at' => null,
            ];
            echo json_encode(['success' => true, 'data' => $data]);
        } catch (\Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    /**
     * POST /sertifikasi/api/pemberitahuan/save
     * Hanya role sertifikasi
     */
    public function savePemberitahuan($f3)
    {
        $this->checkRole($f3);
        header('Content-Type: application/json');

        $judul = trim((string)($f3->get('POST.judul') ?? ''));
        $isi   = trim((string)($f3->get('POST.isi')   ?? ''));
        $aktif = (int)($f3->get('POST.aktif') ?? 1);

        if ($judul === '' || $isi === '') {
            echo json_encode(['success' => false, 'message' => 'Judul dan isi tidak boleh kosong']);
            return;
        }

        try {
            $this->ensurePemberitahuanTable();
            $existing = $this->db->exec("SELECT id FROM serti_pemberitahuan ORDER BY id DESC LIMIT 1");
            if (!empty($existing)) {
                $this->db->exec(
                    "UPDATE serti_pemberitahuan SET judul = ?, isi = ?, aktif = ? WHERE id = ?",
                    [$judul, $isi, $aktif, $existing[0]['id']]
                );
            } else {
                $this->db->exec(
                    "INSERT INTO serti_pemberitahuan (judul, isi, aktif) VALUES (?, ?, ?)",
                    [$judul, $isi, $aktif]
                );
            }
            echo json_encode(['success' => true, 'message' => 'Pemberitahuan berhasil disimpan']);
        } catch (\Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
    }
}
