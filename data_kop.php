<?php
// ================= PENGAMANAN 1: SECURITY HEADERS =================
header("X-Frame-Options: DENY");
header("X-XSS-Protection: 1; mode=block");
header("X-Content-Type-Options: nosniff");

include "koneksi.php";
session_start();

// ================= PENGAMANAN 2: VALIDASI SESI =================
if(!isset($_SESSION['id_sekolah'])){
    if (isset($_GET['aksi']) && $_GET['aksi'] == 'simpan') {
        header('Content-Type: application/json');
        echo json_encode(['status' => 'error', 'message' => 'Sesi habis, silakan login kembali.']);
        exit;
    }
    header("Location: login.php");
    exit;
}

// Amankan ID Sekolah
$id_sekolah = mysqli_real_escape_string($conn, $_SESSION['id_sekolah']);

// KONFIGURASI BAKU JAWA BARAT
$BAKU_PROV     = "PEMERINTAH DAERAH PROVINSI JAWA BARAT";
$BAKU_DINAS    = "DINAS PENDIDIKAN";
$BAKU_CABDIN   = "CABANG DINAS PENDIDIKAN WILAYAH X";
$BAKU_LOGO_PROV = "logo.jpg"; 

/* ================= PROSES SIMPAN (API) ================= */
if (isset($_GET['aksi']) && $_GET['aksi'] == 'simpan') {
    header('Content-Type: application/json');
    
    $alamat   = mysqli_real_escape_string($conn, $_POST['alamat']);
    $email    = mysqli_real_escape_string($conn, $_POST['email']);
    $kota     = mysqli_real_escape_string($conn, $_POST['kota']);
    $pos      = mysqli_real_escape_string($conn, $_POST['kode_pos']);
    
    // Pejabat Berwenang + Pangkat/Golongan
    $n_kepsek  = mysqli_real_escape_string($conn, $_POST['nama_kepala_sekolah']);
    $p_kepsek  = mysqli_real_escape_string($conn, $_POST['nip_kepala_sekolah']);
    $g_kepsek  = mysqli_real_escape_string($conn, $_POST['pangkat_kepala_sekolah']);
    
    $n_sarpras = mysqli_real_escape_string($conn, $_POST['nama_wakasek_sarpras']);
    $p_sarpras = mysqli_real_escape_string($conn, $_POST['nip_wakasek_sarpras']);
    $g_sarpras = mysqli_real_escape_string($conn, $_POST['pangkat_wakasek_sarpras']);
    
    $n_barang  = mysqli_real_escape_string($conn, $_POST['nama_pengurus_barang']);
    $p_barang  = mysqli_real_escape_string($conn, $_POST['nip_pengurus_barang']);
    $g_barang  = mysqli_real_escape_string($conn, $_POST['pangkat_pengurus_barang']);

    $sekolah_q = mysqli_query($conn, "SELECT * FROM kode_sekolah WHERE id = '$id_sekolah'");
    $ds = mysqli_fetch_assoc($sekolah_q);

    $cek = mysqli_query($conn, "SELECT logo_sekolah FROM kop_surat WHERE id_sekolah = '$id_sekolah'");
    $lama = mysqli_fetch_assoc($cek);
    
    $nama_logo_sekolah = $lama['logo_sekolah'] ?? '';
    $folder = "upload_kop/";
    
    // ================= PENGAMANAN 3: IZIN DIREKTORI AMAN =================
    if(!is_dir($folder)) mkdir($folder, 0755, true); 

    // ================= PENGAMANAN 4: VALIDASI FILE UPLOAD =================
    if (!empty($_FILES['logo_sekolah']['name'])) {
        $allowed_ext = ['jpg', 'jpeg', 'png'];
        $allowed_mime = ['image/jpeg', 'image/png'];
        
        $file_tmp = $_FILES['logo_sekolah']['tmp_name'];
        $file_name = $_FILES['logo_sekolah']['name'];
        $file_size = $_FILES['logo_sekolah']['size'];
        
        // Pengecekan Ekstensi
        $ext2 = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
        
        // Pengecekan MIME Type yang sebenarnya dari konten file
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime_type = finfo_file($finfo, $file_tmp);
        finfo_close($finfo);
        
        if(!in_array($ext2, $allowed_ext) || !in_array($mime_type, $allowed_mime)){
            echo json_encode(['status' => 'error', 'message' => 'Format file tidak valid! Hanya diperbolehkan JPG atau PNG.']);
            exit;
        }
        
        // Batasi ukuran file (Max 2MB)
        if($file_size > 2 * 1024 * 1024){
            echo json_encode(['status' => 'error', 'message' => 'Ukuran gambar maksimal 2MB.']);
            exit;
        }

        $nama_logo_sekolah = "logo_kanan_" . $id_sekolah . "_" . time() . "." . $ext2;
        if(!move_uploaded_file($file_tmp, $folder . $nama_logo_sekolah)){
            echo json_encode(['status' => 'error', 'message' => 'Gagal mengupload logo. Coba lagi.']);
            exit;
        }
    }

    if ($lama) {
        $sql = "UPDATE kop_surat SET 
                nama_provinsi='$BAKU_PROV', nama_dinas='$BAKU_DINAS', 
                alamat='$alamat', email='$email', logo='$BAKU_LOGO_PROV', logo_sekolah='$nama_logo_sekolah', 
                nama_kepala_sekolah='$n_kepsek', nip_kepala_sekolah='$p_kepsek', pangkat_kepala_sekolah='$g_kepsek',
                nama_wakasek_sarpras='$n_sarpras', nip_wakasek_sarpras='$p_sarpras', pangkat_wakasek_sarpras='$g_sarpras',
                nama_pengurus_barang='$n_barang', nip_pengurus_barang='$p_barang', pangkat_pengurus_barang='$g_barang',
                kota='$kota', kode_pos='$pos',
                nama_sekolah='{$ds['nama_sekolah']}', kota_kab='{$ds['kota_kab']}', 
                kode_sub_pengguna='{$ds['kode_sub_pengguna']}', kode_wilayah='{$ds['kode_wilayah']}'
                WHERE id_sekolah='$id_sekolah'";
    } else {
        $sql = "INSERT INTO kop_surat (id_sekolah, nama_provinsi, nama_dinas, alamat, email, logo, logo_sekolah, nama_kepala_sekolah, nip_kepala_sekolah, pangkat_kepala_sekolah, nama_wakasek_sarpras, nip_wakasek_sarpras, pangkat_wakasek_sarpras, nama_pengurus_barang, nip_pengurus_barang, pangkat_pengurus_barang, kota, kode_pos, nama_sekolah, kota_kab, kode_sub_pengguna, kode_wilayah) 
                VALUES ('$id_sekolah', '$BAKU_PROV', '$BAKU_DINAS', '$alamat', '$email', '$BAKU_LOGO_PROV', '$nama_logo_sekolah', '$n_kepsek', '$p_kepsek', '$g_kepsek', '$n_sarpras', '$p_sarpras', '$g_sarpras', '$n_barang', '$p_barang', '$g_barang', '$kota', '$pos', '{$ds['nama_sekolah']}', '{$ds['kota_kab']}', '{$ds['kode_sub_pengguna']}', '{$ds['kode_wilayah']}')";
    }

    if (mysqli_query($conn, $sql)) {
        echo json_encode(['status' => 'success', 'message' => 'Konfigurasi KOP berhasil disimpan!']);
    } else {
        echo json_encode(['status' => 'error', 'message' => mysqli_error($conn)]);
    }
    exit;
}

$sekolah_q = mysqli_query($conn, "SELECT * FROM kode_sekolah WHERE id = '$id_sekolah'");
$data_sekolah = mysqli_fetch_assoc($sekolah_q);
$q_kop = mysqli_query($conn, "SELECT * FROM kop_surat WHERE id_sekolah = '$id_sekolah'");
$d = mysqli_fetch_assoc($q_kop);
?>

<style>
    /* ================= WRAPPER KARTU UTAMA ================= */
    .kop-panel-card {
        background: #ffffff;
        border-radius: 20px;
        border: 1px solid rgba(226, 232, 240, 0.9);
        box-shadow: 0 10px 30px -5px rgba(15, 23, 42, 0.05);
        padding: 24px;
    }

    /* ================= HEADER SECTION GELOMBANG TEAL-BIRU ================= */
    .wave-banner-header {
        position: relative;
        background: linear-gradient(90deg, #06394c 0%, #096985 45%, #11a3b8 85%, #17c1cc 100%);
        border-radius: 14px;
        padding: 12px 18px;
        display: flex;
        align-items: center;
        gap: 14px;
        overflow: hidden;
        margin-bottom: 22px;
        box-shadow: 0 4px 14px rgba(9, 105, 133, 0.18);
    }

    .wave-banner-header::after {
        content: '';
        position: absolute;
        right: -10px;
        bottom: -18px;
        width: 55%;
        height: 45px;
        background: rgba(255, 255, 255, 0.14);
        border-radius: 50% 50% 0 0;
        transform: rotate(-4deg);
        pointer-events: none;
    }

    .wave-banner-icon {
        width: 36px;
        height: 36px;
        background: rgba(255, 255, 255, 0.18);
        border-radius: 10px;
        display: flex;
        align-items: center;
        justify-content: center;
        color: #ffffff;
        font-size: 18px;
        flex-shrink: 0;
        position: relative;
        z-index: 2;
    }

    .wave-banner-title {
        color: #ffffff;
        font-weight: 800;
        font-size: 15px;
        letter-spacing: 0.5px;
        text-transform: uppercase;
        margin: 0;
        position: relative;
        z-index: 2;
    }

    /* ================= 4 KARTU IDENTITAS SEKOLAH ================= */
    .id-info-card {
        background: #f8fafc;
        border: 1px solid #e2e8f0;
        border-radius: 14px;
        padding: 16px;
        height: 100%;
        transition: all 0.2s ease;
    }
    .id-info-card:hover {
        background: #ffffff;
        border-color: #bae6fd;
        box-shadow: 0 6px 16px rgba(15, 23, 42, 0.04);
    }

    .id-info-icon {
        font-size: 20px;
        margin-bottom: 8px;
        display: inline-block;
    }
    .icon-navy { color: #0b5a75; }
    .icon-teal { color: #0d9488; }
    .icon-blue { color: #1677ff; }
    .icon-purple { color: #7c3aed; }

    .id-info-label {
        font-size: 11px;
        font-weight: 700;
        color: #475569;
        text-transform: uppercase;
        letter-spacing: 0.4px;
        margin-bottom: 3px;
    }

    .id-info-value {
        font-size: 14px;
        font-weight: 800;
        color: #0f172a;
        line-height: 1.3;
    }

    /* ================= INPUT FORM DENGAN IKON DALAM KOTAK ================= */
    .custom-label-row {
        display: flex;
        align-items: center;
        gap: 7px;
        font-size: 13px;
        font-weight: 700;
        color: #1e293b;
        margin-bottom: 8px;
    }

    .custom-input-group {
        display: flex;
        align-items: center;
        background: #ffffff;
        border: 1px solid #cbd5e1;
        border-radius: 11px;
        padding: 4px 12px;
        transition: all 0.2s;
        box-shadow: 0 2px 5px rgba(15, 23, 42, 0.02);
    }

    .custom-input-group:focus-within {
        border-color: #11a3b8;
        box-shadow: 0 0 0 3px rgba(17, 163, 184, 0.14);
    }

    .custom-input-group .input-inner-icon {
        font-size: 17px;
        margin-right: 10px;
        flex-shrink: 0;
    }

    .custom-input-group .form-control {
        border: none !important;
        box-shadow: none !important;
        background: transparent !important;
        padding: 9px 4px;
        font-size: 13.5px;
        font-weight: 500;
        color: #0f172a;
    }

    /* ================= CUSTOM FILE UPLOAD ================= */
    .file-upload-box {
        display: flex;
        align-items: center;
        background: #f8fafc;
        border: 1px solid #cbd5e1;
        border-radius: 11px;
        padding: 6px 10px;
        transition: all 0.2s;
    }
    .file-upload-box:hover {
        border-color: #11a3b8;
        background: #ffffff;
    }
    .btn-choose-file {
        background: #ffffff;
        border: 1px solid #cbd5e1;
        color: #0f172a;
        font-size: 13px;
        font-weight: 700;
        padding: 7px 14px;
        border-radius: 8px;
        cursor: pointer;
        display: inline-flex;
        align-items: center;
        gap: 7px;
        transition: all 0.2s;
        box-shadow: 0 1px 3px rgba(0,0,0,0.04);
    }
    .btn-choose-file:hover {
        background: #f1f5f9;
        border-color: #94a3b8;
    }
    .btn-choose-file i {
        color: #1677ff;
        font-size: 15px;
    }
    .file-chosen-text {
        margin-left: 14px;
        font-size: 13px;
        color: #475569;
        font-weight: 500;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    /* ================= KARTU PEJABAT BERWENANG ================= */
    .pejabat-card {
        background: #f8fafc;
        border: 1px solid #e2e8f0;
        border-radius: 14px;
        padding: 18px;
        height: 100%;
        transition: 0.2s;
    }
    .pejabat-card:hover {
        background: #ffffff;
        border-color: #bae6fd;
    }
    .pejabat-card .form-control {
        border-radius: 8px;
        border: 1px solid #cbd5e1;
        font-size: 13px;
        padding: 8px 11px;
    }
    .pejabat-card .form-control:focus {
        border-color: #11a3b8;
        box-shadow: 0 0 0 3px rgba(17, 163, 184, 0.12);
    }

    /* ================= PANEL KANAN: PREVIEW ================= */
    .preview-outer-card {
        background: #ffffff;
        border-radius: 20px;
        border: 1px solid rgba(226, 232, 240, 0.9);
        box-shadow: 0 10px 30px -5px rgba(15, 23, 42, 0.05);
        overflow: hidden;
    }

    .preview-header-wave {
        position: relative;
        background: linear-gradient(100deg, #06394c 0%, #096985 50%, #15b2c4 100%);
        padding: 16px 22px;
        display: flex;
        align-items: center;
        gap: 10px;
        color: #ffffff;
        overflow: hidden;
    }
    .preview-header-wave::after {
        content: '';
        position: absolute;
        bottom: -16px;
        left: 0;
        width: 100%;
        height: 30px;
        background: rgba(255, 255, 255, 0.12);
        border-radius: 50% 50% 0 0;
    }
    .preview-header-wave h5 {
        margin: 0;
        font-weight: 800;
        font-size: 17px;
        position: relative;
        z-index: 2;
    }

    .preview-body-wrap {
        padding: 20px;
        background: linear-gradient(180deg, #f8fafc 0%, #eef7fb 100%);
        position: relative;
    }

    .paper-sheet {
        background: #ffffff;
        border-radius: 14px;
        padding: 22px 18px;
        border: 1px solid #e2e8f0;
        box-shadow: 0 6px 20px rgba(15, 23, 42, 0.05);
        position: relative;
        z-index: 2;
    }

    .hr-kop-surat {
        border-top: 2.5px solid #0f172a;
        border-bottom: 1px solid #0f172a;
        height: 4px;
        margin-top: 10px;
        margin-bottom: 16px;
    }

    .preview-logo-section {
        text-align: center;
        padding-top: 4px;
    }

    .preview-logo-title {
        font-size: 12.5px;
        font-weight: 800;
        color: #0f172a;
        text-transform: uppercase;
        letter-spacing: 0.4px;
        margin-bottom: 12px;
    }

    .preview-logo-box {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        padding: 10px;
        background: #ffffff;
        border: 1.5px solid #cbd5e1;
        border-radius: 12px;
        min-width: 110px;
        min-height: 110px;
        box-shadow: 0 3px 10px rgba(0,0,0,0.03);
    }

    /* ================= TOMBOL SIMPAN DATA ================= */
    .action-bottom-card {
        background: #ffffff;
        border-radius: 18px;
        border: 1px solid rgba(226, 232, 240, 0.9);
        box-shadow: 0 10px 25px -5px rgba(15, 23, 42, 0.05);
        padding: 16px 20px;
        margin-top: 16px;
    }

    .btn-simpan-teal {
        width: 100%;
        background: linear-gradient(90deg, #10a3b1 0%, #15b8b8 100%);
        border: none;
        color: #ffffff;
        font-weight: 700;
        font-size: 14.5px;
        padding: 12px 24px;
        border-radius: 11px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 9px;
        box-shadow: 0 6px 16px rgba(16, 163, 177, 0.28);
        transition: all 0.25s ease;
    }
    .btn-simpan-teal:hover {
        background: linear-gradient(90deg, #0d8c99 0%, #11a1a1 100%);
        color: #ffffff;
        transform: translateY(-2px);
        box-shadow: 0 10px 20px rgba(16, 163, 177, 0.36);
    }

    /* ================= MODAL CHECKMARK ANIMATION ================= */
    .success-checkmark { width: 80px; height: 80px; margin: 0 auto; }
    .check-icon { width: 80px; height: 80px; position: relative; border-radius: 50%; border: 4px solid #16a34a; }
    .icon-line { height: 5px; background-color: #16a34a; display: block; border-radius: 2px; position: absolute; z-index: 10; }
    .line-tip { top: 46px; left: 14px; width: 25px; transform: rotate(45deg); animation: icon-line-tip 0.75s; }
    .line-long { top: 38px; right: 8px; width: 47px; transform: rotate(-45deg); animation: icon-line-long 0.75s; }
    @keyframes icon-line-tip { 0% { width: 0; left: 1px; top: 19px; } 100% { width: 25px; left: 14px; top: 46px; } }
    @keyframes icon-line-long { 0% { width: 0; right: 46px; top: 54px; } 100% { width: 47px; right: 8px; top: 38px; } }
</style>

<div class="container-fluid px-0">
    <form id="formSimpanKop" enctype="multipart/form-data">
        <div class="row g-4 align-items-start">
            
            <!-- ================= KOLOM KIRI: IDENTITAS, DETAIL & PEJABAT ================= -->
            <div class="col-xl-8">
                <div class="kop-panel-card">
                    
                    <!-- BANNER 1: IDENTITAS SEKOLAH -->
                    <div class="wave-banner-header">
                        <div class="wave-banner-icon">
                            <i class="bi bi-building"></i>
                        </div>
                        <h5 class="wave-banner-title">IDENTITAS SEKOLAH</h5>
                    </div>
                    
                    <!-- 4 KARTU INFO SEKOLAH -->
                    <div class="row g-3 mb-4">
                        <div class="col-md-6 col-lg-3">
                            <div class="id-info-card">
                                <i class="bi bi-buildings-fill id-info-icon icon-navy"></i>
                                <div class="id-info-label">NAMA UNIT KERJA</div>
                                <div class="id-info-value text-truncate" title="<?= htmlspecialchars($data_sekolah['nama_sekolah'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                                    <?= htmlspecialchars($data_sekolah['nama_sekolah'] ?? '', ENT_QUOTES, 'UTF-8') ?>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6 col-lg-3">
                            <div class="id-info-card">
                                <i class="bi bi-geo-alt-fill id-info-icon icon-teal"></i>
                                <div class="id-info-label">KOTA / KAB</div>
                                <div class="id-info-value">
                                    <?= htmlspecialchars($data_sekolah['kota_kab'] ?? '', ENT_QUOTES, 'UTF-8') ?>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6 col-lg-3">
                            <div class="id-info-card">
                                <i class="bi bi-people-fill id-info-icon icon-blue"></i>
                                <div class="id-info-label">SUB PENGGUNA</div>
                                <div class="id-info-value">
                                    <?= htmlspecialchars($data_sekolah['kode_sub_pengguna'] ?? '', ENT_QUOTES, 'UTF-8') ?>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6 col-lg-3">
                            <div class="id-info-card">
                                <i class="bi bi-layers-fill id-info-icon icon-purple"></i>
                                <div class="id-info-label">KODE WILAYAH</div>
                                <div class="id-info-value">
                                    <?= htmlspecialchars($data_sekolah['kode_wilayah'] ?? '', ENT_QUOTES, 'UTF-8') ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- BANNER 2: DETAIL -->
                    <div class="wave-banner-header mt-2">
                        <div class="wave-banner-icon">
                            <i class="bi bi-file-earmark-text-fill"></i>
                        </div>
                        <h5 class="wave-banner-title">DETAIL</h5>
                    </div>
                    
                    <div class="row g-3 mb-4">
                        <!-- Alamat & No. Telepon -->
                        <div class="col-md-12">
                            <label class="custom-label-row" for="in_alamat">
                                <i class="bi bi-geo-alt-fill text-info"></i>
                                <span>Alamat & No. Telepon</span>
                            </label>
                            <div class="custom-input-group">
                                <i class="bi bi-building-fill-check input-inner-icon text-info"></i>
                                <input type="text" name="alamat" id="in_alamat" class="form-control" value="<?= htmlspecialchars($d['alamat'] ?? '', ENT_QUOTES, 'UTF-8') ?>" placeholder="Masukkan alamat lengkap dan nomor telepon sekolah..." required>
                            </div>
                        </div>

                        <!-- Email Sekolah -->
                        <div class="col-md-5">
                            <label class="custom-label-row" for="in_email">
                                <i class="bi bi-envelope text-success"></i>
                                <span>Email Sekolah</span>
                            </label>
                            <div class="custom-input-group">
                                <input type="email" name="email" id="in_email" class="form-control" value="<?= htmlspecialchars($d['email'] ?? '', ENT_QUOTES, 'UTF-8') ?>" placeholder="email@sekolah.sch.id">
                            </div>
                        </div>

                        <!-- Kab / Kota -->
                        <div class="col-md-4">
                            <label class="custom-label-row" for="in_kota">
                                <i class="bi bi-map text-info"></i>
                                <span>Kab / Kota</span>
                            </label>
                            <div class="custom-input-group">
                                <input type="text" name="kota" id="in_kota" class="form-control" value="<?= htmlspecialchars($d['kota'] ?? '', ENT_QUOTES, 'UTF-8') ?>" placeholder="Kabupaten / Kota" required>
                            </div>
                        </div>

                        <!-- Kode Pos -->
                        <div class="col-md-3">
                            <label class="custom-label-row" for="in_pos">
                                <i class="bi bi-mailbox text-primary"></i>
                                <span>Kode Pos</span>
                            </label>
                            <div class="custom-input-group">
                                <input type="text" name="kode_pos" id="in_pos" class="form-control" value="<?= htmlspecialchars($d['kode_pos'] ?? '', ENT_QUOTES, 'UTF-8') ?>" placeholder="Kode Pos" required>
                            </div>
                        </div>

                        <!-- Logo Sekolah / Labeling -->
                        <div class="col-md-12 mt-3">
                            <label class="custom-label-row" for="in_logo_sekolah">
                                <i class="bi bi-image text-primary"></i>
                                <span>Logo Sekolah / Labeling</span>
                            </label>
                            <div class="file-upload-box">
                                <label for="in_logo_sekolah" class="btn-choose-file mb-0">
                                    <i class="bi bi-upload"></i> Choose File
                                </label>
                                <input type="file" name="logo_sekolah" id="in_logo_sekolah" class="d-none" accept="image/jpeg, image/png">
                                <span id="fileNameDisplay" class="file-chosen-text">
                                    <?= !empty($d['logo_sekolah']) ? htmlspecialchars($d['logo_sekolah'], ENT_QUOTES, 'UTF-8') : 'No file chosen' ?>
                                </span>
                            </div>
                        </div>
                    </div>

                    <!-- BANNER 3: PEJABAT BERWENANG -->
                    <div class="wave-banner-header mt-2">
                        <div class="wave-banner-icon">
                            <i class="bi bi-person-badge-fill"></i>
                        </div>
                        <h5 class="wave-banner-title">PEJABAT BERWENANG</h5>
                    </div>
                    
                    <div class="row g-3">
                        <div class="col-md-4">
                            <div class="pejabat-card text-center">
                                <label class="d-block mb-3 fw-bold text-dark" style="font-size: 13.5px;">Kepala Sekolah</label>
                                <input type="text" name="nama_kepala_sekolah" class="form-control mb-2" value="<?= htmlspecialchars($d['nama_kepala_sekolah'] ?? '', ENT_QUOTES, 'UTF-8') ?>" placeholder="Nama">
                                <input type="text" name="nip_kepala_sekolah" class="form-control mb-2" value="<?= htmlspecialchars($d['nip_kepala_sekolah'] ?? '', ENT_QUOTES, 'UTF-8') ?>" placeholder="NIP">
                                <input type="text" name="pangkat_kepala_sekolah" class="form-control" value="<?= htmlspecialchars($d['pangkat_kepala_sekolah'] ?? '', ENT_QUOTES, 'UTF-8') ?>" placeholder="Pangkat / Golongan">
                            </div>
                        </div>
                        
                        <div class="col-md-4">
                            <div class="pejabat-card text-center">
                                <label class="d-block mb-3 fw-bold text-dark" style="font-size: 13.5px;">Wakasek Sarpras</label>
                                <input type="text" name="nama_wakasek_sarpras" class="form-control mb-2" value="<?= htmlspecialchars($d['nama_wakasek_sarpras'] ?? '', ENT_QUOTES, 'UTF-8') ?>" placeholder="Nama">
                                <input type="text" name="nip_wakasek_sarpras" class="form-control mb-2" value="<?= htmlspecialchars($d['nip_wakasek_sarpras'] ?? '', ENT_QUOTES, 'UTF-8') ?>" placeholder="NIP">
                                <input type="text" name="pangkat_wakasek_sarpras" class="form-control" value="<?= htmlspecialchars($d['pangkat_wakasek_sarpras'] ?? '', ENT_QUOTES, 'UTF-8') ?>" placeholder="Pangkat / Golongan">
                            </div>
                        </div>
                        
                        <div class="col-md-4">
                            <div class="pejabat-card text-center">
                                <label class="d-block mb-3 fw-bold text-dark" style="font-size: 13.5px;">Pengurus Barang</label>
                                <input type="text" name="nama_pengurus_barang" class="form-control mb-2" value="<?= htmlspecialchars($d['nama_pengurus_barang'] ?? '', ENT_QUOTES, 'UTF-8') ?>" placeholder="Nama">
                                <input type="text" name="nip_pengurus_barang" class="form-control mb-2" value="<?= htmlspecialchars($d['nip_pengurus_barang'] ?? '', ENT_QUOTES, 'UTF-8') ?>" placeholder="NIP">
                                <input type="text" name="pangkat_pengurus_barang" class="form-control" value="<?= htmlspecialchars($d['pangkat_pengurus_barang'] ?? '', ENT_QUOTES, 'UTF-8') ?>" placeholder="Pangkat / Golongan">
                            </div>
                        </div>
                    </div>

                </div>
            </div>

            <!-- ================= KOLOM KANAN: PREVIEW & TOMBOL SIMPAN ================= -->
            <div class="col-xl-4">
                <div class="sticky-top" style="top: 95px;">
                    
                    <!-- KARTU PREVIEW -->
                    <div class="preview-outer-card">
                        <div class="preview-header-wave">
                            <i class="bi bi-eye-fill fs-5" style="position:relative; z-index:2;"></i>
                            <h5>Preview</h5>
                        </div>

                        <div class="preview-body-wrap">
                            <div class="paper-sheet">
                                <!-- KOP SURAT -->
                                <div class="row align-items-center g-2">
                                    <div class="col-3 text-center">
                                        <img src="upload_kop/<?= htmlspecialchars($BAKU_LOGO_PROV, ENT_QUOTES, 'UTF-8') ?>" style="width: 54px; height: 58px; object-fit: contain;" onerror="this.src='https://upload.wikimedia.org/wikipedia/commons/b/b3/Logo_Jawa_Barat.png'">
                                    </div>
                                    <div class="col-9 text-center">
                                        <div style="line-height: 1.25; color: #0f172a;">
                                            <div class="fw-extrabold text-uppercase" style="font-size: 9.5px; font-weight: 800;"><?= htmlspecialchars($BAKU_PROV, ENT_QUOTES, 'UTF-8') ?></div>
                                            <div class="fw-extrabold text-uppercase" style="font-size: 9.5px; font-weight: 800;"><?= htmlspecialchars($BAKU_DINAS, ENT_QUOTES, 'UTF-8') ?></div>
                                            <div class="fw-extrabold text-uppercase" style="font-size: 9.5px; font-weight: 800;"><?= htmlspecialchars($BAKU_CABDIN, ENT_QUOTES, 'UTF-8') ?></div>
                                            <div class="fw-black text-uppercase mt-1" style="font-size: 13px; font-weight: 900;"><?= htmlspecialchars($data_sekolah['nama_sekolah'] ?? '', ENT_QUOTES, 'UTF-8') ?></div>
                                            <div id="pv_alamat" style="font-size: 8.5px; color: #334155; margin-top: 3px;">
                                                <?= htmlspecialchars($d['alamat'] ?: 'Alamat belum diisi...', ENT_QUOTES, 'UTF-8') ?>
                                            </div>
                                            <div style="font-size: 8.5px; color: #334155;">
                                                Email: <span id="pv_email"><?= htmlspecialchars($d['email'] ?: '---', ENT_QUOTES, 'UTF-8') ?></span>
                                            </div>
                                            <div style="font-size: 8.5px; color: #334155;">
                                                Kode Pos: <span id="pv_pos"><?= htmlspecialchars($d['kode_pos'] ?: '---', ENT_QUOTES, 'UTF-8') ?></span>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <div class="hr-kop-surat"></div>

                                <!-- PREVIEW LOGO SEKOLAH LANGSUNG GAMBAR YANG DIIMPORT -->
                                <div class="preview-logo-section">
                                    <div class="preview-logo-title">PREVIEW LOGO SEKOLAH / LABELING</div>
                                    <div id="pv_logo_sekolah_img" class="preview-logo-box">
                                        <?php if(!empty($d['logo_sekolah'])): ?>
                                            <img src="upload_kop/<?= htmlspecialchars($d['logo_sekolah'], ENT_QUOTES, 'UTF-8') ?>" alt="Logo Sekolah" style="width: 90px; height: 90px; object-fit: contain;">
                                        <?php else: ?>
                                            <div class="text-muted d-flex flex-column align-items-center justify-content-center" style="width: 90px; height: 90px;">
                                                <i class="bi bi-image fs-3 mb-1 opacity-50"></i>
                                                <span style="font-size: 10px; font-weight: 700;">BELUM ADA LOGO</span>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- TOMBOL SIMPAN DATA (TANPA TOMBOL RESET) -->
                    <div class="action-bottom-card">
                        <button type="submit" id="btnSimpan" class="btn-simpan-teal">
                            <i class="bi bi-floppy2-fill"></i>
                            <span>Simpan Data</span>
                        </button>
                    </div>

                </div>
            </div>

        </div>
    </form>
</div>

<!-- ================= MODAL BERHASIL ================= -->
<div class="modal fade" id="modalBerhasil" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content border-0 shadow-lg rounded-4 text-center">
            <div class="modal-body p-4">
                <div class="success-checkmark mb-3">
                    <div class="check-icon">
                        <span class="icon-line line-tip"></span>
                        <span class="icon-line line-long"></span>
                    </div>
                </div>
                <h5 class="fw-bold text-dark">Tersimpan!</h5>
                <p class="text-secondary small mb-1">Kop surat berhasil diperbarui.</p>
                <p class="text-muted" style="font-size: 0.75rem;">(Memuat ulang otomatis...)</p>
            </div>
        </div>
    </div>
</div>

<script>
    // Live Preview Teks Alamat, Email, Kode Pos
    const watchFields = {'in_alamat': 'pv_alamat', 'in_email': 'pv_email', 'in_pos': 'pv_pos'};
    Object.keys(watchFields).forEach(id => {
        const input = document.getElementById(id);
        if(input){
            input.addEventListener('input', function() {
                document.getElementById(watchFields[id]).textContent = this.value || '...';
            });
        }
    });

    // Live Preview Gambar Logo Sekolah Langsung Saat File Diimpor
    const inputLogo = document.getElementById('in_logo_sekolah');
    const fileNameDisplay = document.getElementById('fileNameDisplay');
    const previewLogoContainer = document.getElementById('pv_logo_sekolah_img');

    if (inputLogo) {
        inputLogo.addEventListener('change', function() {
            const file = this.files[0];
            if (file) {
                fileNameDisplay.textContent = file.name;
                const reader = new FileReader();
                reader.onload = function(e) {
                    previewLogoContainer.innerHTML = `<img src="${e.target.result}" alt="Preview Logo Sekolah" style="width: 90px; height: 90px; object-fit: contain;">`;
                };
                reader.readAsDataURL(file);
            } else {
                fileNameDisplay.textContent = 'No file chosen';
            }
        });
    }

    const modalBerhasilEl = document.getElementById('modalBerhasil');
    const bModal = new bootstrap.Modal(modalBerhasilEl);

    modalBerhasilEl.addEventListener('hidden.bs.modal', function () {
        location.reload(); 
    });

    document.getElementById('formSimpanKop').addEventListener('submit', function(e) {
        e.preventDefault();
        const btn = document.getElementById('btnSimpan');
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span> Menyimpan...';

        fetch('data_kop.php?aksi=simpan', {
            method: 'POST',
            body: new FormData(this)
        })
        .then(res => res.json())
        .then(data => {
            if(data.status === 'success') {
                bModal.show();
                setTimeout(() => {
                    bModal.hide();
                }, 2000); 
            } else {
                alert('Gagal: ' + data.message);
            }
        })
        .catch(err => alert('Error koneksi'))
        .finally(() => {
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-floppy2-fill"></i> <span>Simpan Data</span>';
        });
    });
</script>