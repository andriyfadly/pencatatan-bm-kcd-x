<?php
session_start();

if(!isset($_SESSION['id_user'])){
    echo '<div class="alert alert-danger">Sesi habis, silakan login kembali.</div>';
    exit;
}

include "koneksi.php";

$id_sekolah = $_SESSION['id_sekolah'];

// Ambil keyword pencarian jika ada
$search = isset($_GET['search']) ? mysqli_real_escape_string($conn, $_GET['search']) : '';

// QUERY UTAMA: Mengambil data penyaluran
$queryStr = "SELECT p.* FROM penyaluran p WHERE p.id_sekolah = '$id_sekolah'";

if (!empty($search)) {
    $queryStr .= " AND (p.nama_penerima LIKE '%$search%' OR p.nama_barang LIKE '%$search%' OR p.merk_tipe LIKE '%$search%' OR p.no_reg LIKE '%$search%')";
}
$queryStr .= " ORDER BY p.nama_penerima ASC, p.id_penyaluran DESC";

$qPenyaluran = mysqli_query($conn, $queryStr);

// Proses pengelompokan data di memori PHP sambil mengambil tanggal dari history_bast
$kelompok_penerima = [];
while ($row = mysqli_fetch_assoc($qPenyaluran)) {
    $penerima    = !empty($row['nama_penerima']) ? $row['nama_penerima'] : 'TIDAK TERIDENTIFIKASI';
    $nama_barang = mysqli_real_escape_string($conn, $row['nama_barang']);
    $no_reg      = mysqli_real_escape_string($conn, $row['no_reg']);
    
    // Cari tanggal riwayat BAST secara dinamis dari history_bast yang paling cocok
    $q_date = mysqli_query($conn, "
        SELECT tanggal FROM history_bast 
        WHERE id_sekolah = '$id_sekolah' 
        AND nama_penerima = '$penerima'
        AND nama_barang = '$nama_barang'
        ORDER BY id_history DESC LIMIT 1
    ");
    
    $res_date = mysqli_fetch_assoc($q_date);
    // Masukkan ke array snapshot row data
    $row['tgl_surat_bast'] = $res_date['tanggal'] ?? '';
    
    $kelompok_penerima[$penerima][] = $row;
}

/**
 * FUNGSI BANTU UTK FORMAT TANGGAL INDONESIA (Contoh: 15 Jul 2026)
 */
if (!function_exists('tgl_salur_indo')) {
    function tgl_salur_indo($tanggal){
        if(empty($tanggal) || $tanggal == '0000-00-00') return '-';
        $bulan = array (1 => 'Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des');
        $pecahkan = explode('-', date('Y-m-d', strtotime($tanggal)));
        return $pecahkan[2] . ' ' . $bulan[ (int)$pecahkan[1] ] . ' ' . $pecahkan[0];
    }
}
?>

<style>
    /* ================= UI THEME DATA KEPEMILIKAN BARANG PER PEGAWAI ================= */
    .kepemilikan-wrapper-card {
        background: #ffffff;
        border: 1px solid #dbe7f4;
        border-radius: 20px;
        box-shadow: 0 12px 32px -6px rgba(6, 47, 89, 0.08);
        padding: 24px 26px;
    }

    .top-icon-group {
        width: 46px;
        height: 46px;
        border-radius: 12px;
        background: linear-gradient(135deg, #e0f2fe 0%, #bae6fd 100%);
        color: #0284c7;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 24px;
        flex-shrink: 0;
    }

    .top-heading-title {
        font-size: 19px;
        font-weight: 800;
        color: #093158;
        margin-bottom: 2px;
        letter-spacing: -0.2px;
    }

    .top-heading-sub {
        font-size: 13px;
        color: #475569;
        font-weight: 500;
        margin-bottom: 0;
    }

    /* Tombol Merah Cetak PDF Sensus */
    .btn-cetak-sensus-red {
        background: linear-gradient(135deg, #dc2626 0%, #b91c1c 100%) !important;
        border: none !important;
        color: #ffffff !important;
        border-radius: 12px !important;
        padding: 9px 18px !important;
        font-size: 13.5px !important;
        font-weight: 700 !important;
        display: inline-flex;
        align-items: center;
        gap: 8px;
        box-shadow: 0 4px 12px rgba(220, 38, 38, 0.25);
        transition: all 0.2s ease;
    }
    .btn-cetak-sensus-red:hover {
        background: linear-gradient(135deg, #b91c1c 0%, #991b1b 100%) !important;
        transform: translateY(-1px);
    }

    /* Search Pill Input */
    .search-pegawai-pill {
        position: relative;
        width: 280px;
        max-width: 100%;
    }
    .search-pegawai-pill i {
        position: absolute;
        left: 16px;
        top: 50%;
        transform: translateY(-50%);
        color: #475569;
        font-size: 14px;
        z-index: 3;
    }
    .search-pegawai-pill input {
        width: 100%;
        background: #ffffff !important;
        border: 1.5px solid #cbd5e1 !important;
        border-radius: 25px !important;
        padding: 8px 16px 8px 40px !important;
        font-size: 13.5px !important;
        font-weight: 500;
        color: #0f172a !important;
        box-shadow: 0 2px 8px rgba(15, 23, 42, 0.03);
    }
    .search-pegawai-pill input:focus {
        border-color: #0284c7 !important;
        box-shadow: 0 0 0 3px rgba(2, 132, 199, 0.14) !important;
        outline: none;
    }

    /* Kartu Masing-Masing Pegawai */
    .pegawai-item-card {
        background: #ffffff;
        border: 1.5px solid #d5e4f2;
        border-radius: 16px;
        overflow: hidden;
        margin-bottom: 22px;
        box-shadow: 0 6px 18px rgba(6, 47, 89, 0.05);
    }

    .pegawai-card-top {
        background: linear-gradient(90deg, #f0f7fd 0%, #f7fbff 100%);
        padding: 14px 20px;
        border-bottom: 1px solid #dbe8f4;
        display: flex;
        flex-wrap: wrap;
        justify-content: space-between;
        align-items: center;
        gap: 12px;
    }

    .pegawai-avatar-circle {
        width: 44px;
        height: 44px;
        border-radius: 50%;
        background: linear-gradient(135deg, #2563eb 0%, #0284c7 100%);
        color: #ffffff;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 21px;
        flex-shrink: 0;
        box-shadow: 0 4px 10px rgba(2, 132, 199, 0.25);
    }

    .pegawai-name-title {
        font-size: 15.5px;
        font-weight: 800;
        color: #0f172a;
        margin-bottom: 2px;
        text-transform: uppercase;
    }

    .pegawai-role-sub {
        font-size: 12.5px;
        color: #475569;
        font-weight: 500;
        display: flex;
        align-items: center;
        gap: 5px;
    }
    .pegawai-role-sub i {
        color: #093158;
    }

    /* Tombol Action Outline Biru di Header Pegawai */
    .btn-pegawai-outline {
        background: #ffffff !important;
        color: #093158 !important;
        border: 1.5px solid #93c5fd !important;
        border-radius: 10px !important;
        padding: 7px 14px !important;
        font-size: 12.5px !important;
        font-weight: 700 !important;
        display: inline-flex;
        align-items: center;
        gap: 7px;
        transition: all 0.18s ease;
    }
    .btn-pegawai-outline i {
        color: #0284c7;
        font-size: 14px;
    }
    .btn-pegawai-outline:hover {
        background: #eff6ff !important;
        border-color: #2563eb !important;
        color: #1d4ed8 !important;
    }

    .badge-item-aset-pill {
        background: #f0f9ff !important;
        color: #093158 !important;
        border: 1.5px solid #bae6fd !important;
        border-radius: 10px !important;
        padding: 7px 14px !important;
        font-size: 12.5px !important;
        font-weight: 700 !important;
        display: inline-flex;
        align-items: center;
        gap: 7px;
    }
    .badge-item-aset-pill i {
        color: #0284c7;
    }

    /* Tabel Aset Pegawai dengan Header Gradasi Biru Tua - Teal */
    .table-aset-pegawai {
        margin-bottom: 0;
        border-collapse: separate;
        border-spacing: 0;
        font-size: 13.5px;
    }

    .table-aset-pegawai thead tr {
        background: linear-gradient(95deg, #062f59 0%, #09587a 52%, #119498 100%) !important;
    }

    .table-aset-pegawai thead th {
        background: transparent !important;
        color: #ffffff !important;
        font-weight: 700 !important;
        font-size: 12.5px !important;
        text-transform: none !important;
        letter-spacing: 0.1px !important;
        border: none !important;
        padding: 11px 14px !important;
        white-space: nowrap;
    }

    .table-aset-pegawai tbody tr {
        background: #ffffff;
        transition: background-color 0.15s ease;
    }
    .table-aset-pegawai tbody tr:nth-child(even) {
        background: #f4faff;
    }
    .table-aset-pegawai tbody tr:hover {
        background: #eaf5fe;
    }

    .table-aset-pegawai tbody td {
        padding: 14px 14px !important;
        border-bottom: 1px solid #e2ecf5 !important;
        vertical-align: middle;
    }

    /* Badge No. Registrasi Hijau Mint */
    .badge-noreg-mint {
        display: inline-block;
        background: #dcfce7;
        color: #15803d;
        font-weight: 800;
        font-size: 12.5px;
        padding: 4px 14px;
        border-radius: 8px;
        font-family: 'Plus Jakarta Sans', sans-serif;
    }

    /* Kotak Ikon Identitas Barang */
    .aset-icon-box {
        width: 40px;
        height: 40px;
        border-radius: 10px;
        background: #e6eff8;
        color: #1e3a8a;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 19px;
        flex-shrink: 0;
    }

    /* Badge Volume Salur Biru Lembut */
    .badge-vol-softblue {
        display: inline-block;
        background: #dbeafe;
        color: #1e3a8a;
        font-weight: 700;
        font-size: 12.5px;
        padding: 4px 13px;
        border-radius: 8px;
        white-space: nowrap;
    }

    /* Badge Waktu Penyaluran Hijau Mint dengan Ceklis */
    .badge-salur-green {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        background: #dcfce7;
        color: #15803d;
        font-weight: 700;
        font-size: 12.5px;
        padding: 5px 13px;
        border-radius: 20px;
        white-space: nowrap;
    }
    .badge-salur-green i {
        font-size: 13px;
    }

    /* ================= UI MODAL PERMOHONAN PENGEMBALIAN (SESUAI GAMBAR) ================= */
    .modal-surat-kembali .modal-content {
        border-radius: 20px !important;
        border: none !important;
        overflow: hidden;
        box-shadow: 0 25px 50px -12px rgba(6, 47, 89, 0.35) !important;
        background: #ffffff;
    }

    .modal-surat-header {
        position: relative;
        background: linear-gradient(95deg, #062f59 0%, #09587a 55%, #11989b 100%);
        padding: 18px 22px;
        color: #ffffff;
        display: flex;
        align-items: center;
        justify-content: space-between;
        overflow: hidden;
    }

    .modal-surat-header::after {
        content: '';
        position: absolute;
        right: -12%;
        bottom: -22px;
        width: 55%;
        height: 48px;
        background: rgba(255, 255, 255, 0.14);
        border-radius: 50% 50% 0 0;
        transform: rotate(-4deg);
        pointer-events: none;
    }

    .modal-surat-header-left {
        display: flex;
        align-items: center;
        position: relative;
        z-index: 2;
    }

    .modal-surat-header-icon {
        font-size: 23px;
        color: #ffffff;
        display: flex;
        align-items: center;
        justify-content: center;
    }

    .modal-surat-header-divider {
        width: 1.5px;
        height: 28px;
        background: rgba(255, 255, 255, 0.55);
        margin: 0 14px;
    }

    .modal-surat-header-title {
        font-size: 16.5px;
        font-weight: 800;
        color: #ffffff;
        margin: 0;
        letter-spacing: -0.2px;
    }

    .btn-close-surat-white {
        position: relative;
        z-index: 2;
        background: transparent;
        border: none;
        color: #ffffff;
        font-size: 18px;
        width: 32px;
        height: 32px;
        border-radius: 50%;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        transition: 0.2s;
        cursor: pointer;
    }
    .btn-close-surat-white:hover {
        background: rgba(255, 255, 255, 0.18);
    }

    .modal-surat-body {
        padding: 22px 24px 20px !important;
        background: #ffffff;
    }

    .surat-info-banner {
        background: #e9f3fe;
        border: 1px solid #d5e6fb;
        border-radius: 14px;
        padding: 14px 16px;
        display: flex;
        align-items: flex-start;
        gap: 12px;
        margin-bottom: 20px;
    }

    .surat-info-banner-icon {
        width: 24px;
        height: 24px;
        border-radius: 50%;
        border: 2px solid #0284c7;
        color: #0284c7;
        font-weight: 800;
        font-size: 13px;
        display: flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
        margin-top: 2px;
    }

    .surat-info-banner-text {
        font-size: 13.5px;
        color: #334155;
        line-height: 1.48;
        margin: 0;
    }

    .surat-info-banner-text strong {
        color: #0f172a;
        font-weight: 800;
    }

    .surat-field-label {
        display: flex;
        align-items: center;
        gap: 8px;
        font-size: 14px;
        font-weight: 800;
        color: #0f172a;
        margin-bottom: 8px;
    }
    .surat-field-label i {
        color: #2563eb;
        font-size: 17px;
    }
    .surat-field-label .req-star {
        color: #ef4444;
        font-weight: 800;
    }

    .surat-input-icon-wrap {
        position: relative;
    }
    .surat-input-icon-wrap i {
        position: absolute;
        left: 14px;
        top: 50%;
        transform: translateY(-50%);
        color: #475569;
        font-size: 16px;
        pointer-events: none;
    }
    .surat-input-icon-wrap .form-control {
        border: 1.5px solid #cbd5e1 !important;
        border-radius: 12px !important;
        padding: 11px 14px 11px 42px !important;
        font-size: 14px !important;
        font-weight: 500;
        color: #0f172a !important;
        background: #ffffff !important;
        transition: all 0.2s;
    }
    .surat-input-icon-wrap .form-control:focus {
        border-color: #2563eb !important;
        box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.14) !important;
    }

    .surat-date-control {
        border: 1.5px solid #cbd5e1 !important;
        border-radius: 12px !important;
        padding: 11px 14px !important;
        font-size: 14.5px !important;
        font-weight: 600 !important;
        color: #0f172a !important;
        background: #ffffff !important;
    }
    .surat-date-control:focus {
        border-color: #2563eb !important;
        box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.14) !important;
    }

    .modal-surat-footer {
        background: #ffffff;
        border-top: 1px solid #eef2f6 !important;
        padding: 16px 24px 22px !important;
        display: flex;
        gap: 12px;
    }

    .btn-surat-batal {
        flex: 1;
        background: #f1f5f9 !important;
        color: #0f172a !important;
        border: 1px solid #e2e8f0 !important;
        border-radius: 12px !important;
        padding: 11px 16px !important;
        font-size: 14px !important;
        font-weight: 700 !important;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
        transition: 0.2s;
    }
    .btn-surat-batal:hover {
        background: #e2e8f0 !important;
    }

    .btn-surat-cetak {
        flex: 1.25;
        background: linear-gradient(135deg, #0077ff 0%, #0066eb 100%) !important;
        color: #ffffff !important;
        border: none !important;
        border-radius: 12px !important;
        padding: 11px 16px !important;
        font-size: 14px !important;
        font-weight: 700 !important;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
        box-shadow: 0 6px 16px rgba(0, 119, 255, 0.28);
        transition: 0.2s;
    }
    .btn-surat-cetak:hover {
        background: linear-gradient(135deg, #0066eb 0%, #0052cc 100%) !important;
        transform: translateY(-1px);
    }
</style>

<div class="row g-4 mb-4">
    <div class="col-12">
        <div class="kepemilikan-wrapper-card">
            
            <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4">
                <div class="d-flex align-items-center gap-3">
                    <div class="top-icon-group">
                        <i class="bi bi-people-fill"></i>
                    </div>
                    <div>
                        <h5 class="top-heading-title">Data Kepemilikan Barang per Pegawai</h5>
                        <p class="top-heading-sub">Menampilkan daftar aset kelompok pegawai berdasarkan penanggung jawab.</p>
                    </div>
                </div>
                
                <div class="d-flex align-items-center gap-2 flex-wrap">
                    <a href="cetak_sensus.php?search=<?= urlencode($search) ?>" target="_blank" class="btn btn-cetak-sensus-red">
                        <i class="bi bi-file-earmark-pdf-fill"></i> Cetak PDF Sensus
                    </a>

                    <div class="search-pegawai-pill">
                        <i class="bi bi-search"></i>
                        <input type="text" id="search-pengguna" class="form-control" placeholder="Cari nama / barang / no reg..." value="<?= htmlspecialchars($search) ?>">
                    </div>
                </div>
            </div>

            <?php if (empty($kelompok_penerima)): ?>
                <div class="text-center py-5 text-secondary">
                    <i class="bi bi-folder-x text-muted" style="font-size: 40px;"></i>
                    <p class="mt-2 mb-0">Tidak ada data penyerahan barang yang ditemukan.</p>
                </div>
            <?php else: ?>

                <?php 
                $modal_counter = 1; // Untuk ID modal unik tiap nama pegawai
                foreach ($kelompok_penerima as $nama_pegawai => $daftar_barang): 
                    $modal_id = "modalTitimangsa_" . $modal_counter;
                ?>
                    <div class="pegawai-item-card">
                        
                        <div class="pegawai-card-top">
                            <div class="d-flex align-items-center gap-3">
                                <div class="pegawai-avatar-circle">
                                    <i class="bi bi-person-fill"></i>
                                </div>
                                <div>
                                    <h6 class="pegawai-name-title"><?= htmlspecialchars($nama_pegawai); ?></h6>
                                    <small class="pegawai-role-sub"><i class="bi bi-briefcase-fill"></i> Pegawai / Pengguna Aset Sekolah</small>
                                </div>
                            </div>
                            <div class="d-flex flex-wrap align-items-center gap-2">
                                <button type="button" class="btn btn-pegawai-outline" data-bs-toggle="modal" data-bs-target="#<?= $modal_id; ?>">
                                    <i class="bi bi-file-earmark-text"></i> Permohonan Pengembalian
                                </button>
                                
                                <span class="badge-item-aset-pill">
                                    <i class="bi bi-arrow-left-right"></i> Memegang <?= count($daftar_barang); ?> Item Aset
                                </span>
                            </div>
                        </div>

                        <!-- ================= MODAL PERMOHONAN PENGEMBALIAN (UI BARU) ================= -->
                        <div class="modal fade modal-surat-kembali" id="<?= $modal_id; ?>" isset-penerima="<?= htmlspecialchars($nama_pegawai); ?>" tabindex="-1" aria-labelledby="<?= $modal_id; ?>Label" aria-hidden="true">
                            <div class="modal-dialog modal-dialog-centered" style="max-width: 440px;">
                                <div class="modal-content">
                                    <div class="modal-surat-header">
                                        <div class="modal-surat-header-left">
                                            <div class="modal-surat-header-icon">
                                                <i class="bi bi-calendar2-week"></i>
                                            </div>
                                            <div class="modal-surat-header-divider"></div>
                                            <h6 class="modal-surat-header-title" id="<?= $modal_id; ?>Label">Pilih Dokumen & Tanggal Surat</h6>
                                        </div>
                                        <button type="button" class="btn-close-surat-white" data-bs-dismiss="modal" aria-label="Close">
                                            <i class="bi bi-x-lg"></i>
                                        </button>
                                    </div>
                                    <form action="surat_permohonan_kembali.php" method="GET" target="_blank">
                                        <div class="modal-body modal-surat-body">
                                            <div class="surat-info-banner">
                                                <div class="surat-info-banner-icon">i</div>
                                                <p class="surat-info-banner-text">Tentukan nomor surat dan tanggal titimangsa untuk surat permohonan pengembalian atas nama <strong><?= htmlspecialchars($nama_pegawai); ?></strong>.</p>
                                            </div>
                                            
                                            <input type="hidden" name="nama_penerima" value="<?= htmlspecialchars($nama_pegawai); ?>">
                                            
                                            <div class="mb-3">
                                                <label class="surat-field-label"><i class="bi bi-file-earmark-text"></i> Nomor Surat <span class="req-star">*</span></label>
                                                <div class="surat-input-icon-wrap">
                                                    <i class="bi bi-file-earmark-text"></i>
                                                    <input type="text" name="nomor_surat" class="form-control" placeholder="Contoh: 002/AR.03.05-CADISDIK.WIL-X" required>
                                                </div>
                                            </div>

                                            <div class="mb-1">
                                                <label class="surat-field-label"><i class="bi bi-calendar-event"></i> Tanggal Surat <span class="req-star">*</span></label>
                                                <input type="date" name="tanggal_cetak" class="form-control surat-date-control" value="<?= date('Y-m-d'); ?>" required>
                                            </div>
                                        </div>
                                        <div class="modal-footer modal-surat-footer">
                                            <button type="button" class="btn btn-surat-batal" data-bs-dismiss="modal"><i class="bi bi-x-lg"></i> Batal</button>
                                            <button type="submit" class="btn btn-surat-cetak"><i class="bi bi-printer"></i> Cetak Dokumen</button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>

                        <div class="table-responsive">
                            <table class="table table-aset-pegawai align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th class="text-center" style="width: 55px;">No</th>
                                        <th class="text-center" style="width: 130px;">No. Registrasi</th>
                                        <th style="width: 26%;">Identitas Barang / Aset</th>
                                        <th>Lokasi / Ruangan</th>
                                        <th class="text-center">Volume Salur</th>
                                        <th class="text-center">Waktu Pengadaan</th>
                                        <th class="text-center">Waktu Penyaluran</th>
                                        <th class="text-center">Keterangan</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php 
                                    $sub_no = 1;
                                    foreach ($daftar_barang as $barang): 
                                    ?>
                                        <tr>
                                            <td class="text-center fw-semibold text-secondary"><?= $sub_no++; ?></td>
                                            
                                            <td class="text-center">
                                                <?php if(!empty($barang['no_reg'])): ?>
                                                    <span class="badge-noreg-mint">
                                                        <?= htmlspecialchars($barang['no_reg']); ?>
                                                    </span>
                                                <?php else: ?>
                                                    <span class="text-muted small">-</span>
                                                <?php endif; ?>
                                            </td>
                                            
                                            <td>
                                                <div class="d-flex align-items-center gap-3">
                                                    <div class="aset-icon-box">
                                                        <i class="bi bi-box-seam"></i>
                                                    </div>
                                                    <div>
                                                        <span class="d-block text-dark fw-bold" style="font-size: 13.5px;"><?= htmlspecialchars($barang['nama_barang']); ?></span>
                                                        <span class="text-secondary d-block" style="font-size: 12px;"><?= htmlspecialchars($barang['merk_tipe']); ?></span>
                                                        <small class="fw-semibold" style="color: #0284c7; font-size: 11.5px;"><?= htmlspecialchars($barang['kode_barang']); ?></small>
                                                    </div>
                                                </div>
                                            </td>
                                            
                                            <td>
                                                <span class="fw-medium text-dark" style="font-size: 13px;">
                                                    <i class="bi bi-geo-alt-fill text-primary me-1"></i><?= htmlspecialchars($barang['lokasi']); ?>
                                                </span>
                                            </td>
                                            
                                            <td class="text-center">
                                                <span class="badge-vol-softblue">
                                                    <?= number_format($barang['vol_penyaluran']); ?> <?= htmlspecialchars($barang['sat_pengadaan']); ?>
                                                </span>
                                            </td>
                                            
                                            <td class="text-center text-dark fw-medium" style="font-size: 13px;">
                                                <?= $barang['tgl_pengadaan'].' / '.$barang['bln_pengadaan'].' / '.$barang['thn_pengadaan']; ?>
                                            </td>

                                            <td class="text-center">
                                                <span class="badge-salur-green">
                                                    <i class="bi bi-check-circle-fill"></i><?= tgl_salur_indo($barang['tgl_surat_bast'] ?? ''); ?>
                                                </span>
                                            </td>
                                            
                                            <td class="text-center text-secondary small">
                                                <?= !empty($barang['keterangan']) ? htmlspecialchars($barang['keterangan']) : '-'; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                    </div>
                <?php 
                $modal_counter++;
                endforeach; 
                ?>

            <?php endif; ?>

        </div>
    </div>
</div>

<script>
    var searchInput = document.getElementById('search-pengguna');
    if(searchInput) {
        // Otomatis kembalikan focus cursor ke ujung teks setelah data dimuat ulang oleh Ajax (loadPage)
        searchInput.focus();
        var valTemp = searchInput.value;
        searchInput.value = '';
        searchInput.value = valTemp;

        var delayTimer;
        searchInput.addEventListener('input', function() {
            clearTimeout(delayTimer);
            var keyword = this.value;
            // Debounce 400 milidetik agar query tidak dikirim berkali-kali secara brutal saat sedang mengetik cepat
            delayTimer = setTimeout(function() {
                loadPage('data_pengguna.php?search=' + encodeURIComponent(keyword), 'Data Pengguna', false);
            }, 400);
        });
    }
</script>