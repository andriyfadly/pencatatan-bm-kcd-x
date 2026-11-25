<?php
// ================= PENGAMANAN 1: SECURITY HEADERS =================
header("X-Frame-Options: DENY");
header("X-XSS-Protection: 1; mode=block");
header("X-Content-Type-Options: nosniff");

ob_start();
session_start();

if(!isset($_SESSION['id_user'])){
    header("Location: login.php");
    exit;
}

include 'koneksi.php';
require 'vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

// Amankan Session Variable
$id_sekolah = mysqli_real_escape_string($conn, $_SESSION['id_sekolah']);

// --- FUNGSI HELPER UNTUK MERAPIKAN NAMA & GELAR ---
function formatNamaGelar($nama) {
    $namaRapi = ucwords(strtolower(trim($nama)));
    $search  = ['S.pd', 'M.pd', 'S.kom', 'S.st', 'M.si', 'S.si', 'S.sos', 'S.ag', 'M.ag', 'Ph.d', 'Hj.', 'H.', 'S.t', 'M.t', 'S.h', 'M.h', 'S.e', 'M.m'];
    $replace = ['S.Pd', 'M.Pd', 'S.Kom', 'S.ST', 'M.Si', 'S.Si', 'S.Sos', 'S.Ag', 'M.Ag', 'Ph.D', 'Hj.', 'H.', 'S.T', 'M.T', 'S.H', 'M.H', 'S.E', 'M.M'];
    return str_replace($search, $replace, $namaRapi);
}

// --- FUNGSI HELPER UNTUK BERSIHKAN NIP (STRIP DIANGGAP KOSONG) ---
function cleanNip($nip) {
    $nipClean = trim($nip ?? '');
    return in_array($nipClean, ['-', '–', '—']) ? '' : $nipClean;
}

// ================= DOWNLOAD TEMPLATE =================
if(isset($_GET['template'])){
    ob_end_clean();
    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->fromArray(['nama_lengkap','nip','pangkat_golongan','jabatan'], NULL, 'A1');
    foreach(range('A','D') as $c){ $sheet->getColumnDimension($c)->setAutoSize(true); }

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment;filename="template_pegawai.xlsx"');
    header('Cache-Control: max-age=0');

    $writer = new Xlsx($spreadsheet);
    $writer->save('php://output');
    exit;
}

// ================= HANDLE POST REQUEST (AJAX) =================
if($_SERVER['REQUEST_METHOD'] == 'POST'){
    
    if(isset($_POST['import_excel'])){
        // ================= PENGAMANAN 2: VALIDASI FILE IMPORT EXCEL =================
        if (!isset($_FILES['file_excel']) || $_FILES['file_excel']['error'] !== UPLOAD_ERR_OK) {
            echo "ERROR|Gagal mengunggah file atau file tidak ditemukan"; 
            exit;
        }

        $file_tmp = $_FILES['file_excel']['tmp_name'];
        $file_name = $_FILES['file_excel']['name'];
        $file_size = $_FILES['file_excel']['size'];
        
        // Batasi ukuran (Max 5MB)
        if($file_size > 5 * 1024 * 1024) {
            echo "ERROR|Ukuran file terlalu besar (Maksimal 5MB)"; 
            exit;
        }

        // Cek Ekstensi File
        $ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
        if($ext !== 'xlsx') {
            echo "ERROR|Format file tidak valid. Hanya menerima file .xlsx"; 
            exit;
        }

        // Cek MIME Type Real
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime_type = finfo_file($finfo, $file_tmp);
        finfo_close($finfo);
        
        $allowed_mimes = [
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 
            'application/zip' // Terkadang xlsx terbaca sebagai zip archive
        ];

        if(!in_array($mime_type, $allowed_mimes)) {
            echo "ERROR|Isi file korup atau tidak sesuai format Excel!"; 
            exit;
        }

        try {
            $spreadsheet = IOFactory::load($file_tmp);
            $sheetData = $spreadsheet->getActiveSheet()->toArray();
            $count = 0;
            $count_dup = 0;

            foreach($sheetData as $i => $row){
                if($i == 0 || empty(trim($row[0] ?? ''))) continue; 
                $nama_format = formatNamaGelar($row[0]);
                $nama = mysqli_real_escape_string($conn, $nama_format);
                $nip = mysqli_real_escape_string($conn, cleanNip($row[1] ?? ''));
                $pangkat = mysqli_real_escape_string($conn, $row[2] ?? '');
                $jabatan = mysqli_real_escape_string($conn, $row[3] ?? '');

                // CEK DUPLIKAT DATA PEGAWAI (NIP ATAU NAMA)
                $where_check = "(LOWER(nama_lengkap) = LOWER('$nama'))";
                if(!empty($nip)){
                    $where_check = "(nip = '$nip' OR LOWER(nama_lengkap) = LOWER('$nama'))";
                }

                $check = mysqli_query($conn, "SELECT id FROM pegawai WHERE id_sekolah='$id_sekolah' AND $where_check");
                if(mysqli_num_rows($check) > 0){
                    $count_dup++;
                    continue; // Dilewati jika data sudah ada
                }

                mysqli_query($conn,"INSERT INTO pegawai (id_sekolah, nama_lengkap, nip, pangkat_golongan, jabatan) 
                             VALUES ('$id_sekolah', '$nama', '$nip', '$pangkat', '$jabatan')");
                $count++;
            }

            if($count == 0 && $count_dup > 0){
                echo "ERROR|Gagal Import: Semua data pegawai dalam file Excel sudah pernah diinputkan!";
            } else if($count_dup > 0){
                echo "SUCCESS|$count data pegawai berhasil diimport ($count_dup data dilewati karena sudah ada)";
            } else {
                echo "SUCCESS|$count data pegawai berhasil diimport";
            }
        } catch (Exception $e) { echo "ERROR|Gagal memproses file excel: " . $e->getMessage(); }
        exit;
    }

    // SIMPAN DATA MANUAL (BISA BANYAK)
    if(isset($_POST['simpan'])){
        $namas = $_POST['nama'] ?? [];
        $nips = $_POST['nip'] ?? [];
        $pangkats = $_POST['pangkat'] ?? [];
        $jabatans = $_POST['jabatan'] ?? [];

        if(!is_array($namas)){
            $namas = [$namas];
            $nips = [$nips];
            $pangkats = [$pangkats];
            $jabatans = [$jabatans];
        }

        $count_success = 0;
        $duplicates = [];

        for($i = 0; $i < count($namas); $i++){
            $nama_val = trim($namas[$i] ?? '');
            if(!empty($nama_val)){
                $nama_format = formatNamaGelar($nama_val);
                $nama = mysqli_real_escape_string($conn, $nama_format);
                $nip = mysqli_real_escape_string($conn, cleanNip($nips[$i] ?? ''));
                $pangkat = mysqli_real_escape_string($conn, $pangkats[$i] ?? '');
                $jabatan = mysqli_real_escape_string($conn, $jabatans[$i] ?? '');

                // CEK DUPLIKAT DATA PEGAWAI (NIP ATAU NAMA)
                $where_check = "(LOWER(nama_lengkap) = LOWER('$nama'))";
                if(!empty($nip)){
                    $where_check = "(nip = '$nip' OR LOWER(nama_lengkap) = LOWER('$nama'))";
                }

                $check = mysqli_query($conn, "SELECT id FROM pegawai WHERE id_sekolah='$id_sekolah' AND $where_check");

                if(mysqli_num_rows($check) > 0){
                    $duplicates[] = $nama_format . (!empty($nip) ? " (NIP: $nip)" : "");
                } else {
                    $qIns = "INSERT INTO pegawai (id_sekolah, nama_lengkap, nip, pangkat_golongan, jabatan) VALUES ('$id_sekolah', '$nama', '$nip', '$pangkat', '$jabatan')";
                    if(mysqli_query($conn, $qIns)){
                        $count_success++;
                    }
                }
            }
        }

        if($count_success > 0 && empty($duplicates)){
            echo "SUCCESS|$count_success data pegawai berhasil disimpan";
        } else if($count_success > 0 && !empty($duplicates)){
            $dup_list = implode(', ', $duplicates);
            echo "SUCCESS|$count_success data berhasil disimpan. Catatan: Data ($dup_list) tidak ditambahkan karena sudah pernah diinputkan.";
        } else if($count_success == 0 && !empty($duplicates)){
            $dup_list = implode(', ', $duplicates);
            echo "ERROR|Data gagal disimpan! Pegawai/NIP '$dup_list' sudah pernah diinputkan.";
        } else {
            echo "ERROR|Gagal menyimpan data pegawai. Pastikan input tidak kosong.";
        }
        exit;
    }

    // PROSES UPDATE DAN HAPUS PEGAWAI
    $nama_format = formatNamaGelar($_POST['nama'] ?? '');
    $nama = mysqli_real_escape_string($conn, $nama_format);
    $nip = mysqli_real_escape_string($conn, cleanNip($_POST['nip'] ?? ''));
    $pangkat = mysqli_real_escape_string($conn, $_POST['pangkat'] ?? '');
    $jabatan = mysqli_real_escape_string($conn, $_POST['jabatan'] ?? '');

    if(isset($_POST['update'])){
        // ================= PENGAMANAN 3: CASTING ID KE INTEGER =================
        $id = (int)$_POST['id']; 

        // CEK DUPLIKAT SAAT UPDATE (KECUALI UNTUK ID PEGAWAI SAMA)
        $where_check = "(LOWER(nama_lengkap) = LOWER('$nama'))";
        if(!empty($nip)){
            $where_check = "(nip = '$nip' OR LOWER(nama_lengkap) = LOWER('$nama'))";
        }

        $check = mysqli_query($conn, "SELECT id FROM pegawai WHERE id_sekolah='$id_sekolah' AND $where_check AND id != '$id'");
        if(mysqli_num_rows($check) > 0){
            echo "ERROR|Gagal Update: Pegawai atau NIP '$nama_format' / '$nip' sudah pernah diinputkan!";
            exit;
        }

        $query = "UPDATE pegawai SET nama_lengkap='$nama', nip='$nip', pangkat_golongan='$pangkat', jabatan='$jabatan' WHERE id='$id' AND id_sekolah='$id_sekolah'";
        $msg = "Data pegawai berhasil diupdate";
    } elseif(isset($_POST['hapus_id'])){
        // ================= PENGAMANAN 3: CASTING ID KE INTEGER =================
        $id = (int)$_POST['hapus_id'];
        $query = "DELETE FROM pegawai WHERE id='$id' AND id_sekolah='$id_sekolah'";
        $msg = "Data pegawai berhasil dihapus";
    } elseif(isset($_POST['hapus_semua'])){
        // ================= FITUR BARU: HAPUS SEMUA PEGAWAI =================
        $query = "DELETE FROM pegawai WHERE id_sekolah='$id_sekolah'";
        $msg = "Seluruh data pegawai berhasil dihapus";
    }

    if(isset($query)){
        if(mysqli_query($conn, $query)){ echo "SUCCESS|$msg"; } 
        else { echo "ERROR|" . mysqli_error($conn); }
    }
    exit;
}

$totalPegawai = mysqli_num_rows(mysqli_query($conn, "SELECT id FROM pegawai WHERE id_sekolah='$id_sekolah'"));
$totalJabatan = mysqli_num_rows(mysqli_query($conn, "SELECT DISTINCT jabatan FROM pegawai WHERE id_sekolah='$id_sekolah'"));
?>

<style>
    /* ================= HEADER TITLE PAGE ================= */
    .page-hero-header {
        display: flex;
        align-items: center;
        gap: 15px;
        margin-bottom: 22px;
    }
    .page-hero-icon {
        width: 50px;
        height: 50px;
        border-radius: 14px;
        background: linear-gradient(135deg, #074759 0%, #0b7a8c 100%);
        color: #ffffff;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 24px;
        box-shadow: 0 6px 16px rgba(7, 71, 89, 0.22);
        flex-shrink: 0;
    }
    .page-hero-title {
        font-size: 22px;
        font-weight: 800;
        color: #0b2447;
        margin: 0;
        line-height: 1.2;
    }
    .page-hero-subtitle {
        font-size: 13.5px;
        color: #475569;
        margin: 2px 0 0;
        font-weight: 500;
    }

    /* ================= KARTU STAT & IMPORT ================= */
    .pegawai-top-card {
        background: #ffffff;
        border-radius: 18px;
        border: 1px solid rgba(226, 232, 240, 0.9);
        box-shadow: 0 8px 24px -4px rgba(15, 23, 42, 0.05);
        padding: 18px 20px;
        height: 100%;
    }

    .stat-mini-box {
        display: flex;
        flex-direction: column;
        justify-content: center;
        padding: 2px 8px;
    }
    .stat-mini-top {
        display: flex;
        align-items: center;
        gap: 10px;
    }
    .stat-mini-icon-teal {
        font-size: 26px;
        color: #0d8a9c;
        line-height: 1;
    }
    .stat-mini-icon-blue {
        font-size: 24px;
        color: #1677ff;
        line-height: 1;
    }
    .stat-mini-label {
        font-size: 11.5px;
        font-weight: 700;
        color: #334155;
        line-height: 1.2;
    }
    .stat-mini-val {
        font-size: 24px;
        font-weight: 800;
        color: #0f172a;
        line-height: 1.1;
        margin-top: 2px;
    }

    .badge-pill-soft-green {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 4px;
        background: #dcfce7;
        color: #15803d;
        font-size: 11px;
        font-weight: 700;
        padding: 3px 10px;
        border-radius: 20px;
        margin-top: 8px;
        width: fit-content;
    }

    .badge-pill-soft-blue {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 4px;
        background: #dbeafe;
        color: #1d4ed8;
        font-size: 11px;
        font-weight: 700;
        padding: 3px 10px;
        border-radius: 20px;
        margin-top: 8px;
        width: fit-content;
    }

    .btn-tambah-teal {
        background: linear-gradient(90deg, #06425b 0%, #0d8a9c 55%, #16b8c4 100%);
        border: none;
        color: #ffffff;
        font-weight: 700;
        font-size: 13.5px;
        padding: 10px 16px;
        border-radius: 12px;
        width: 100%;
        margin-top: 14px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 7px;
        box-shadow: 0 5px 14px rgba(13, 138, 156, 0.25);
        transition: all 0.25s ease;
    }
    .btn-tambah-teal:hover {
        opacity: 0.94;
        color: #ffffff;
        transform: translateY(-1px);
        box-shadow: 0 8px 18px rgba(13, 138, 156, 0.35);
    }

    /* ================= KOTAK IMPORT DATA PEGAWAI ================= */
    .import-dashed-inner {
        border: 1.5px dashed #cbd5e1;
        border-radius: 14px;
        padding: 16px 20px;
        height: 100%;
        display: flex;
        align-items: center;
        background: #fcfdfe;
    }

    .excel-badge-icon {
        width: 28px;
        height: 32px;
        background: #15803d;
        color: #ffffff;
        border-radius: 6px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-weight: 800;
        font-size: 13px;
        margin-right: 10px;
        box-shadow: 0 2px 6px rgba(21, 128, 61, 0.25);
    }

    .import-file-group {
        display: flex;
        align-items: center;
        background: #ffffff;
        border: 1px solid #cbd5e1;
        border-radius: 10px;
        padding: 4px;
        width: 100%;
    }
    .btn-choose-excel {
        background: #f1f5f9;
        border: 1px solid #e2e8f0;
        color: #0f172a;
        font-size: 12.5px;
        font-weight: 700;
        padding: 7px 12px;
        border-radius: 7px;
        cursor: pointer;
        display: inline-flex;
        align-items: center;
        gap: 6px;
        white-space: nowrap;
        transition: 0.2s;
    }
    .btn-choose-excel:hover {
        background: #e2e8f0;
    }
    .excel-file-name {
        padding: 0 12px;
        font-size: 12.5px;
        color: #475569;
        font-weight: 500;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .btn-mulai-import {
        background: linear-gradient(135deg, #16a34a 0%, #15803d 100%);
        border: none;
        color: #ffffff;
        font-size: 13px;
        font-weight: 700;
        padding: 10px 18px;
        border-radius: 10px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 7px;
        white-space: nowrap;
        box-shadow: 0 4px 12px rgba(22, 163, 74, 0.25);
        transition: 0.2s;
    }
    .btn-mulai-import:hover {
        background: linear-gradient(135deg, #15803d 0%, #166534 100%);
        color: #ffffff;
        transform: translateY(-1px);
    }

    .btn-download-template {
        background: #e0f2fe;
        border: 1px solid #7dd3fc;
        color: #0369a1;
        font-size: 13px;
        font-weight: 700;
        padding: 9px 22px;
        border-radius: 22px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 7px;
        text-decoration: none;
        transition: all 0.2s;
    }
    .btn-download-template:hover {
        background: #bae6fd;
        color: #0c4a6e;
        transform: translateY(-1px);
    }

    /* ================= BANNER HEADER TABEL DAFTAR PEGAWAI ================= */
    .table-card-wrapper {
        background: #ffffff;
        border-radius: 18px;
        border: 1px solid rgba(226, 232, 240, 0.9);
        box-shadow: 0 10px 28px -5px rgba(15, 23, 42, 0.05);
        overflow: hidden;
    }

    .table-banner-header {
        position: relative;
        background: linear-gradient(90deg, #05394d 0%, #086882 48%, #109db3 88%, #17b8c4 100%);
        padding: 14px 22px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        flex-wrap: wrap;
        gap: 12px;
        overflow: hidden;
    }
    .table-banner-header::after {
        content: '';
        position: absolute;
        right: 12%;
        bottom: -24px;
        width: 38%;
        height: 52px;
        background: rgba(255, 255, 255, 0.12);
        border-radius: 50% 50% 0 0;
        pointer-events: none;
    }

    .table-banner-title {
        display: flex;
        align-items: center;
        gap: 11px;
        color: #ffffff;
        font-weight: 800;
        font-size: 15.5px;
        letter-spacing: 0.4px;
        text-transform: uppercase;
        margin: 0;
        position: relative;
        z-index: 2;
    }
    .table-banner-title i {
        font-size: 21px;
    }

    .search-pill-box {
        background: #ffffff;
        border-radius: 25px;
        padding: 5px 15px;
        display: flex;
        align-items: center;
        gap: 8px;
        width: 270px;
        max-width: 100%;
        box-shadow: 0 3px 10px rgba(0, 0, 0, 0.08);
        position: relative;
        z-index: 2;
    }
    .search-pill-box input {
        border: none;
        outline: none;
        background: transparent;
        font-size: 13px;
        color: #0f172a;
        width: 100%;
    }

    .btn-hapus-semua {
        background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
        border: none;
        color: #ffffff;
        font-size: 12.5px;
        font-weight: 700;
        padding: 8px 16px;
        border-radius: 25px;
        display: inline-flex;
        align-items: center;
        gap: 6px;
        box-shadow: 0 3px 10px rgba(220, 38, 38, 0.28);
        position: relative;
        z-index: 2;
        transition: 0.2s;
    }
    .btn-hapus-semua:hover {
        background: linear-gradient(135deg, #dc2626 0%, #b91c1c 100%);
        color: #ffffff;
        transform: translateY(-1px);
    }

    /* ================= STYLING TABEL & AVATAR BERWARNA ================= */
    .table-freeze-container {
        max-height: 520px;
        overflow-y: auto;
        position: relative;
    }
    #tablePegawai {
        margin-bottom: 0;
        border-collapse: separate;
        border-spacing: 0;
    }
    #tablePegawai thead th {
        position: sticky;
        top: 0;
        background-color: #f1f5f9;
        color: #0f172a;
        font-weight: 800;
        font-size: 13px;
        padding: 14px 16px;
        border-bottom: 1.5px solid #e2e8f0;
        z-index: 10;
        white-space: nowrap;
    }
    #tablePegawai tbody tr {
        transition: background-color 0.15s ease;
    }
    #tablePegawai tbody tr:nth-child(even) {
        background-color: #f4f9fd;
    }
    #tablePegawai tbody tr:hover {
        background-color: #e6f4fc;
    }
    #tablePegawai tbody td {
        padding: 12px 16px;
        border-bottom: 1px solid #e2e8f0;
        font-size: 13.5px;
        color: #1e293b;
        vertical-align: middle;
    }

    /* Avatar Pegawai Variasi Warna */
    .pegawai-avatar {
        width: 34px;
        height: 34px;
        border-radius: 50%;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 16px;
        flex-shrink: 0;
    }
    .av-blue   { background: #dbeafe; color: #1d4ed8; }
    .av-teal   { background: #ccfbf1; color: #0f766e; }
    .av-amber  { background: #fef3c7; color: #d97706; }
    .av-purple { background: #ede9fe; color: #6d28d9; }

    /* Tombol Aksi Edit & Hapus */
    .action-btn {
        width: 32px;
        height: 32px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border-radius: 8px;
        border: none;
        transition: 0.2s;
        font-size: 14px;
    }
    .btn-edit-link {
        background: #e0f2fe;
        color: #0284c7;
    }
    .btn-edit-link:hover {
        background: #bae6fd;
        color: #0369a1;
    }
    .btn-delete-link {
        background: #fee2e2;
        color: #dc2626;
    }
    .btn-delete-link:hover {
        background: #fecaca;
        color: #b91c1c;
    }

    /* Form Tambah Pegawai Panel */
    .form-control-custom { border-radius: 10px; border: 1px solid #cbd5e1; font-size: 13.5px; }
    .form-control-custom:focus { border-color: #0d8a9c; box-shadow: 0 0 0 3px rgba(13, 138, 156, 0.14); }

    .success-checkmark { width: 80px; height: 80px; margin: 0 auto; }
    .check-icon { width: 80px; height: 80px; position: relative; border-radius: 50%; box-sizing: content-box; border: 4px solid #16a34a; }
    .icon-line { height: 5px; background-color: #16a34a; display: block; border-radius: 2px; position: absolute; z-index: 10; }
    .line-tip { top: 46px; left: 14px; width: 25px; transform: rotate(45deg); }
    .line-long { top: 38px; right: 8px; width: 47px; transform: rotate(-45deg); }
</style>

<div class="container-fluid px-0">
    
    <!-- ================= HERO TITLE ================= -->
    <div class="page-hero-header">
        <div class="page-hero-icon">
            <i class="bi bi-people-fill"></i>
        </div>
        <div>
            <h3 class="page-hero-title">Manajemen Pegawai</h3>
            <p class="page-hero-subtitle">Kelola data pegawai dan staf administrasi inventaris</p>
        </div>
    </div>

    <!-- ================= ROW KARTU STATISTIK & IMPORT ================= -->
    <div class="row g-3 mb-4">
        <!-- KARTU KIRI: TOTAL PEGAWAI & UNIT JABATAN -->
        <div class="col-xl-4 col-lg-5">
            <div class="pegawai-top-card">
                <div class="row g-0 align-items-center">
                    <div class="col-6 border-end pe-2">
                        <div class="stat-mini-box">
                            <div class="stat-mini-top">
                                <i class="bi bi-people-fill stat-mini-icon-teal"></i>
                                <div>
                                    <div class="stat-mini-label">Total Pegawai</div>
                                    <div class="stat-mini-val"><?= $totalPegawai ?></div>
                                </div>
                            </div>
                            <span class="badge-pill-soft-green">
                                <i class="bi bi-arrow-up-short"></i> Terinput
                            </span>
                        </div>
                    </div>
                    <div class="col-6 ps-3">
                        <div class="stat-mini-box">
                            <div class="stat-mini-top">
                                <i class="bi bi-buildings-fill stat-mini-icon-blue"></i>
                                <div>
                                    <div class="stat-mini-label">Unit Jabatan</div>
                                    <div class="stat-mini-val"><?= $totalJabatan ?></div>
                                </div>
                            </div>
                            <span class="badge-pill-soft-blue">
                                <i class="bi bi-check2"></i> Tersedia
                            </span>
                        </div>
                    </div>
                </div>
                <button id="btnTambah" class="btn-tambah-teal">
                    <i class="bi bi-plus-lg"></i> Tambah Pegawai
                </button>
            </div>
        </div>

        <!-- KARTU KANAN: IMPORT DATA PEGAWAI & DOWNLOAD TEMPLATE -->
        <div class="col-xl-8 col-lg-7">
            <div class="pegawai-top-card">
                <div class="import-dashed-inner">
                    <div class="row align-items-center w-100 g-3">
                        <div class="col-xl-7 col-md-7">
                            <div class="d-flex align-items-center mb-2">
                                <span class="excel-badge-icon">X</span>
                                <h6 class="fw-bold mb-0 text-dark" style="font-size: 14.5px;">Import Data Pegawai</h6>
                            </div>
                            <form id="importForm" class="d-flex align-items-center gap-2">
                                <input type="hidden" name="import_excel" value="1">
                                <div class="import-file-group">
                                    <label for="inputExcelFile" class="btn-choose-excel mb-0">
                                        <i class="bi bi-upload text-primary"></i> Choose File
                                    </label>
                                    <input type="file" id="inputExcelFile" name="file_excel" class="d-none" required accept=".xlsx">
                                    <span id="excelFileName" class="excel-file-name">No file chosen</span>
                                </div>
                                <button type="submit" class="btn-mulai-import">
                                    <i class="bi bi-upload"></i> Mulai Import
                                </button>
                            </form>
                        </div>

                        <div class="col-xl-5 col-md-5 text-center border-start-md">
                            <div class="d-flex align-items-center justify-content-center gap-2 mb-2">
                                <i class="bi bi-file-earmark-text-fill text-primary fs-5"></i>
                                <span class="small fw-semibold text-dark">Belum punya format template?</span>
                            </div>
                            <a href="data_pegawai.php?template=1" class="btn-download-template">
                                <i class="bi bi-download"></i> Download Template
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ================= FORM TAMBAH PEGAWAI (MULTI-INPUT DINAMIS) ================= -->
    <div id="formTambah" class="table-card-wrapper mb-4" style="display:none;">
        <div class="table-banner-header">
            <h6 class="table-banner-title"><i class="bi bi-person-plus-fill"></i> Tambah Pegawai Baru</h6>
            <button type="button" class="btn-close btn-close-white" style="position:relative; z-index:2;" onclick="document.getElementById('formTambah').style.display='none'"></button>
        </div>
        <div class="p-4">
            <form id="pegawaiForm">
                <input type="hidden" name="simpan" value="1">
                
                <div id="containerRowPegawai">
                    <div class="row g-3 row-pegawai mb-3 align-items-end">
                        <div class="col-md-3">
                            <label class="small fw-bold text-secondary mb-1">Nama Lengkap & Gelar</label>
                            <input name="nama[]" class="form-control form-control-custom" placeholder="Nama & Gelar" required>
                        </div>
                        <div class="col-md-3">
                            <label class="small fw-bold text-secondary mb-1">NIP</label>
                            <input name="nip[]" class="form-control form-control-custom" placeholder="NIP">
                        </div>
                        <div class="col-md-2">
                            <label class="small fw-bold text-secondary mb-1">Golongan / Pangkat</label>
                            <input name="pangkat[]" class="form-control form-control-custom" placeholder="Contoh: IV/a" required>
                        </div>
                        <div class="col-md-3">
                            <label class="small fw-bold text-secondary mb-1">Jabatan</label>
                            <input name="jabatan[]" class="form-control form-control-custom" placeholder="Contoh: Guru IPA" required>
                        </div>
                        <div class="col-md-1 text-center">
                            <button type="button" class="btn btn-outline-danger border-0 action-btn btn-remove-row w-100" style="height:38px;" title="Hapus Baris" disabled>
                                <i class="bi bi-trash3-fill"></i>
                            </button>
                        </div>
                    </div>
                </div>

                <div class="d-flex justify-content-between align-items-center mt-4 pt-3 border-top">
                    <button type="button" id="btnTambahRow" class="btn btn-outline-primary btn-sm rounded-pill fw-bold px-3">
                        <i class="bi bi-plus-lg me-1"></i> Tambah Baris Input
                    </button>
                    <button type="submit" class="btn-tambah-teal w-auto px-4 mt-0">
                        <i class="bi bi-floppy2-fill"></i> Simpan Semua Data Pegawai
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- ================= TABEL DAFTAR PEGAWAI ================= -->
    <div class="table-card-wrapper">
        <div class="table-banner-header">
            <h6 class="table-banner-title">
                <i class="bi bi-people-fill"></i> DAFTAR PEGAWAI
            </h6>
            <div class="d-flex align-items-center gap-2 flex-wrap" style="position: relative; z-index: 2;">
                <div class="search-pill-box">
                    <i class="bi bi-search text-muted"></i>
                    <input type="text" id="searchPegawai" placeholder="Cari Nama Pegawai / NIP...">
                </div>
                <button onclick="hapusSemuaPegawai()" class="btn-hapus-semua" title="Hapus Seluruh Data Pegawai">
                    <i class="bi bi-trash3-fill"></i> Hapus Semua
                </button>
            </div>
        </div>

        <div class="table-responsive table-freeze-container">
            <table class="table align-middle" id="tablePegawai">
                <thead>
                    <tr>
                        <th class="text-center" width="60">No</th>
                        <th><i class="bi bi-people-fill text-primary me-2"></i>Daftar Pegawai</th>
                        <th><i class="bi bi-person-vcard-fill text-primary me-2"></i>NIP</th>
                        <th><i class="bi bi-layers-fill text-primary me-2"></i>Gol./Pangkat</th>
                        <th><i class="bi bi-briefcase-fill text-primary me-2"></i>Jabatan</th>
                        <th class="text-center" width="110"><i class="bi bi-gear-fill text-primary me-1"></i> Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $no = 1;
                    $avatarClasses = ['av-blue', 'av-teal', 'av-amber', 'av-purple'];
                    $q = mysqli_query($conn, "SELECT * FROM pegawai WHERE id_sekolah='$id_sekolah' ORDER BY nama_lengkap ASC");
                    while($r = mysqli_fetch_assoc($q)):
                        $avClass = $avatarClasses[($no - 1) % 4];
                        $nipTampil = !empty(trim($r['nip'] ?? '')) ? htmlspecialchars($r['nip'], ENT_QUOTES, 'UTF-8') : '-';
                        $pangkatTampil = !empty(trim($r['pangkat_golongan'] ?? '')) ? htmlspecialchars($r['pangkat_golongan'], ENT_QUOTES, 'UTF-8') : '-';
                    ?>
                    <tr>
                        <td class="text-center fw-semibold text-secondary"><?= $no++ ?></td>
                        <td>
                            <div class="d-flex align-items-center gap-3">
                                <div class="pegawai-avatar <?= $avClass ?>">
                                    <i class="bi bi-person-fill"></i>
                                </div>
                                <span class="fw-bold text-dark"><?= htmlspecialchars($r['nama_lengkap'], ENT_QUOTES, 'UTF-8') ?></span>
                            </div>
                        </td>
                        <td class="fw-medium text-secondary"><?= $nipTampil ?></td>
                        <td class="fw-medium text-dark"><?= $pangkatTampil ?></td>
                        <td class="fw-medium text-dark"><?= htmlspecialchars($r['jabatan'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td class="text-center">
                            <button class="action-btn btn-edit-link btn-edit-trigger" 
                                data-id="<?= (int)$r['id'] ?>"
                                data-nama="<?= htmlspecialchars($r['nama_lengkap'], ENT_QUOTES, 'UTF-8') ?>"
                                data-nip="<?= htmlspecialchars($r['nip'], ENT_QUOTES, 'UTF-8') ?>"
                                data-pangkat="<?= htmlspecialchars($r['pangkat_golongan'], ENT_QUOTES, 'UTF-8') ?>"
                                data-jabatan="<?= htmlspecialchars($r['jabatan'], ENT_QUOTES, 'UTF-8') ?>"
                                title="Edit Pegawai">
                                <i class="bi bi-pencil-square"></i>
                            </button>
                            <button onclick="hapusPegawai(<?= (int)$r['id'] ?>)" class="action-btn btn-delete-link ms-1" title="Hapus Pegawai">
                                <i class="bi bi-trash3-fill"></i>
                            </button>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- ================= MODAL EDIT PEGAWAI ================= -->
<div class="modal fade" id="editModalPegawai" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <form id="editFormPegawai" class="modal-content border-0 shadow-lg rounded-4 overflow-hidden">
            <input type="hidden" name="update" value="1">
            <div class="table-banner-header">
                <h6 class="table-banner-title"><i class="bi bi-pencil-square"></i> Edit Data Pegawai</h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" style="position:relative; z-index:2;"></button>
            </div>
            <div class="modal-body p-4">
                <input type="hidden" name="id" id="modal-id">
                <div class="mb-3">
                    <label class="small fw-bold mb-1">Nama Lengkap</label>
                    <input type="text" name="nama" id="modal-nama" class="form-control form-control-custom" required>
                </div>
                <div class="mb-3">
                    <label class="small fw-bold mb-1">NIP</label>
                    <input type="text" name="nip" id="modal-nip" class="form-control form-control-custom">
                </div>
                <div class="row g-3">
                    <div class="col-6">
                        <label class="small fw-bold mb-1">Golongan</label>
                        <input type="text" name="pangkat" id="modal-pangkat" class="form-control form-control-custom" required>
                    </div>
                    <div class="col-6">
                        <label class="small fw-bold mb-1">Jabatan</label>
                        <input type="text" name="jabatan" id="modal-jabatan" class="form-control form-control-custom" required>
                    </div>
                </div>
            </div>
            <div class="modal-footer border-0 p-4 pt-0">
                <button type="submit" class="btn-tambah-teal mt-0">
                    <i class="bi bi-check2-circle"></i> Update Perubahan
                </button>
            </div>
        </form>
    </div>
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
                <h5 class="fw-bold">Berhasil!</h5>
                <p class="text-secondary small mb-1" id="msgBerhasil">Data diproses.</p>
                <p class="text-muted" style="font-size: 0.75rem;">(Memuat ulang otomatis...)</p>
            </div>
        </div>
    </div>
</div>

<!-- ================= MODAL KONFIRMASI HAPUS SATUAN ================= -->
<div class="modal fade" id="modalHapus" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content border-0 shadow-lg rounded-4 text-center">
            <div class="modal-body p-4">
                <div class="mb-3 text-danger">
                    <i class="bi bi-exclamation-circle-fill" style="font-size: 4rem;"></i>
                </div>
                <h5 class="fw-bold text-dark">Hapus Data?</h5>
                <p class="text-secondary small mb-4">Data pegawai ini akan dihapus permanen dan tidak dapat dikembalikan.</p>
                <div class="d-flex justify-content-center gap-2">
                    <button type="button" class="btn btn-light btn-sm px-4 rounded-pill fw-bold" data-bs-dismiss="modal">Batal</button>
                    <button type="button" id="btnKonfirmasiHapus" class="btn btn-danger btn-sm px-4 rounded-pill fw-bold shadow-sm">Ya, Hapus</button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ================= MODAL KONFIRMASI HAPUS SEMUA PEGAWAI ================= -->
<div class="modal fade" id="modalHapusSemua" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content border-0 shadow-lg rounded-4 text-center">
            <div class="modal-body p-4">
                <div class="mb-3 text-danger">
                    <i class="bi bi-exclamation-triangle-fill" style="font-size: 4rem;"></i>
                </div>
                <h5 class="fw-bold text-dark">Hapus SEMUA Data?</h5>
                <p class="text-secondary small mb-4">Peringatan! Seluruh data pegawai akan dihapus permanen. Tindakan ini tidak dapat dibatalkan.</p>
                <div class="d-flex justify-content-center gap-2">
                    <button type="button" class="btn btn-light btn-sm px-4 rounded-pill fw-bold" data-bs-dismiss="modal">Batal</button>
                    <button type="button" id="btnKonfirmasiHapusSemua" class="btn btn-danger btn-sm px-4 rounded-pill fw-bold shadow-sm">Ya, Hapus Semua</button>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    var eModPegawai = new bootstrap.Modal(document.getElementById('editModalPegawai'));
    var modBerhasil = new bootstrap.Modal(document.getElementById('modalBerhasil'));
    var modHapus = new bootstrap.Modal(document.getElementById('modalHapus'));
    var modHapusSemua = new bootstrap.Modal(document.getElementById('modalHapusSemua'));
    var idYangAkanDihapus = null;

    // Tampilkan nama file Excel saat dipilih
    const inputExcelFile = document.getElementById('inputExcelFile');
    const excelFileName = document.getElementById('excelFileName');
    if (inputExcelFile) {
        inputExcelFile.addEventListener('change', function() {
            excelFileName.textContent = this.files[0] ? this.files[0].name : 'No file chosen';
        });
    }

    // FITUR CARI NAMA PEGAWAI / NIP REALTIME
    document.getElementById('searchPegawai').addEventListener('input', function() {
        let keyword = this.value.toLowerCase().trim();
        let rows = document.querySelectorAll('#tablePegawai tbody tr');
        let no = 1;

        rows.forEach(row => {
            let nama = row.cells[1] ? row.cells[1].textContent.toLowerCase() : '';
            let nip = row.cells[2] ? row.cells[2].textContent.toLowerCase() : '';
            let jabatan = row.cells[4] ? row.cells[4].textContent.toLowerCase() : '';

            if (nama.includes(keyword) || nip.includes(keyword) || jabatan.includes(keyword)) {
                row.style.display = '';
                if(row.cells[0]) row.cells[0].textContent = no++;
            } else {
                row.style.display = 'none';
            }
        });
    });

    document.getElementById('btnTambah').onclick = function(){
        let f = document.getElementById('formTambah');
        f.style.display = f.style.display === 'none' ? 'block' : 'none';
        if(f.style.display === 'block') f.scrollIntoView({ behavior: 'smooth' });
    };

    // LOGIKA TAMBAH BARIS PEGAWAI DINAMIS
    document.getElementById('btnTambahRow').onclick = function() {
        let container = document.getElementById('containerRowPegawai');
        let firstRow = container.querySelector('.row-pegawai');
        let newRow = firstRow.cloneNode(true);
        
        // Bersihkan nilai input di baris baru
        newRow.querySelectorAll('input').forEach(input => input.value = '');
        
        container.appendChild(newRow);
        updateRemoveButtons();
    };

    function updateRemoveButtons() {
        let rows = document.querySelectorAll('.row-pegawai');
        rows.forEach((row) => {
            let btn = row.querySelector('.btn-remove-row');
            if (rows.length === 1) {
                btn.setAttribute('disabled', 'disabled');
            } else {
                btn.removeAttribute('disabled');
                btn.onclick = function() {
                    row.remove();
                    updateRemoveButtons();
                };
            }
        });
    }

    document.getElementById('pegawaiForm').onsubmit = function(e){
        e.preventDefault();
        submitAjax(new FormData(this));
    };

    document.getElementById('editFormPegawai').onsubmit = function(e){
        e.preventDefault();
        eModPegawai.hide();
        submitAjax(new FormData(this));
    };

    document.getElementById('importForm').onsubmit = function(e){
        e.preventDefault();
        submitAjax(new FormData(this));
    };

    document.querySelectorAll('.btn-edit-trigger').forEach(btn => {
        btn.onclick = function() {
            document.getElementById('modal-id').value = this.dataset.id;
            document.getElementById('modal-nama').value = this.dataset.nama;
            document.getElementById('modal-nip').value = this.dataset.nip;
            document.getElementById('modal-pangkat').value = this.dataset.pangkat;
            document.getElementById('modal-jabatan').value = this.dataset.jabatan;
            eModPegawai.show();
        };
    });

    // FUNGSI TRIGGER MODAL HAPUS SATUAN
    function hapusPegawai(id){
        idYangAkanDihapus = id;
        modHapus.show();
    }

    // FUNGSI TRIGGER MODAL HAPUS SEMUA
    function hapusSemuaPegawai(){
        modHapusSemua.show();
    }

    // EVENT KETIKA TOMBOL "YA, HAPUS" DIKLIK
    document.getElementById('btnKonfirmasiHapus').onclick = function() {
        if(idYangAkanDihapus !== null) {
            let fd = new FormData();
            fd.append('hapus_id', idYangAkanDihapus);
            
            modHapus.hide();
            submitAjax(fd);
            idYangAkanDihapus = null;
        }
    };

    // EVENT KETIKA TOMBOL "YA, HAPUS SEMUA" DIKLIK
    document.getElementById('btnKonfirmasiHapusSemua').onclick = function() {
        let fd = new FormData();
        fd.append('hapus_semua', '1');
        
        modHapusSemua.hide();
        submitAjax(fd);
    };

    async function submitAjax(formData) {
        try {
            const response = await fetch('data_pegawai.php', {
                method: 'POST',
                body: formData
            });
            const text = await response.text();
            const res = text.split('|');

            if(res[0] === 'SUCCESS'){
                document.getElementById('msgBerhasil').innerText = res[1];
                modBerhasil.show();
                
                // AUTO CLOSE MODAL 2 DETIK
                setTimeout(() => {
                    modBerhasil.hide();
                }, 2000);

                // RELOAD KETIKA MODAL SUDAH TERTUTUP
                document.getElementById('modalBerhasil').addEventListener('hidden.bs.modal', function () {
                    if (typeof loadPage === 'function') {
                        loadPage('data_pegawai.php'); 
                    } else {
                        location.reload();
                    }
                }, { once: true });
            } else {
                alert('Gagal: ' + (res[1] || text));
            }
        } catch (err) {
            alert('Kesalahan Koneksi');
        }
    }
</script>