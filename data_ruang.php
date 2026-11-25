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

// ================= DOWNLOAD TEMPLATE =================
if(isset($_GET['template'])){
    ob_end_clean();
    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->fromArray(['nama_ruang'], NULL, 'A1');
    $sheet->getColumnDimension('A')->setAutoSize(true);

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment;filename="template_ruang.xlsx"');
    header('Cache-Control: max-age=0');

    $writer = new Xlsx($spreadsheet);
    $writer->save('php://output');
    exit;
}

// ================= HANDLE AJAX POST =================
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
                if($i == 0 || empty($row[0])) continue;
                // Memformat agar setiap awal kata huruf kapital
                $nama_ruang_format = ucwords(strtolower($row[0]));
                $nama_ruang = mysqli_real_escape_string($conn, $nama_ruang_format);

                // CEK DATA DUPLIKAT DALAM DATABASE
                $check = mysqli_query($conn, "SELECT id FROM ruang WHERE id_sekolah='$id_sekolah' AND LOWER(nama_ruang) = LOWER('$nama_ruang')");
                if(mysqli_num_rows($check) > 0){
                    $count_dup++;
                    continue; // Dilewati jika sudah pernah diinputkan
                }

                mysqli_query($conn,"INSERT INTO ruang (id_sekolah, nama_ruang) VALUES ('$id_sekolah', '$nama_ruang')");
                $count++;
            }

            if($count == 0 && $count_dup > 0){
                echo "ERROR|Gagal Import: Semua data ruangan di dalam file Excel sudah pernah diinputkan!";
            } else if($count_dup > 0){
                echo "SUCCESS|$count data ruang berhasil diimport ($count_dup data dilewati karena sudah ada)";
            } else {
                echo "SUCCESS|$count data ruang berhasil diimport";
            }
        } catch (Exception $e) { echo "ERROR|Gagal memproses file: " . $e->getMessage(); }
        exit;
    }

    // SIMPAN DATA MANUAL (BISA BANYAK RUANGAN)
    if(isset($_POST['simpan'])){
        $nama_ruang_inputs = $_POST['nama_ruang'] ?? [];
        if(!is_array($nama_ruang_inputs)){
            $nama_ruang_inputs = [$nama_ruang_inputs];
        }

        $count_success = 0;
        $duplicates = [];

        foreach($nama_ruang_inputs as $raw_nama){
            $val = trim($raw_nama);
            if(!empty($val)){
                $nama_ruang_format = ucwords(strtolower($val));
                $nama_ruang = mysqli_real_escape_string($conn, $nama_ruang_format);

                // CEK CEK APAKAH NAMA RUANG SUDAH ADA
                $check = mysqli_query($conn, "SELECT id FROM ruang WHERE id_sekolah='$id_sekolah' AND LOWER(nama_ruang) = LOWER('$nama_ruang')");
                if(mysqli_num_rows($check) > 0){
                    $duplicates[] = $nama_ruang_format;
                } else {
                    $qIns = "INSERT INTO ruang (id_sekolah, nama_ruang) VALUES ('$id_sekolah', '$nama_ruang')";
                    if(mysqli_query($conn, $qIns)){
                        $count_success++;
                    }
                }
            }
        }

        if($count_success > 0 && empty($duplicates)){
            echo "SUCCESS|$count_success data ruang berhasil ditambahkan";
        } else if($count_success > 0 && !empty($duplicates)){
            $dup_list = implode(', ', $duplicates);
            echo "SUCCESS|$count_success data berhasil ditambahkan. Catatan: Ruangan ($dup_list) tidak ditambahkan karena sudah pernah diinputkan.";
        } else if($count_success == 0 && !empty($duplicates)){
            $dup_list = implode(', ', $duplicates);
            echo "ERROR|Data gagal disimpan! Nama ruangan '$dup_list' sudah pernah diinputkan.";
        } else {
            echo "ERROR|Gagal menyimpan data ruang. Pastikan input tidak kosong.";
        }
        exit;
    }

    // PROSES UPDATE DAN HAPUS RUANG
    if(isset($_POST['update'])){
        $nama_ruang_input = $_POST['nama_ruang'] ?? '';
        $nama_ruang_format = ucwords(strtolower(is_array($nama_ruang_input) ? $nama_ruang_input[0] : $nama_ruang_input));
        $nama_ruang = mysqli_real_escape_string($conn, $nama_ruang_format);
        
        // ================= PENGAMANAN 3: CASTING ID KE INTEGER =================
        $id = (int)$_POST['id'];

        // CEK DUPLIKAT SAAT UPDATE (KECUALI UNTUK ID SAMA)
        $check = mysqli_query($conn, "SELECT id FROM ruang WHERE id_sekolah='$id_sekolah' AND LOWER(nama_ruang) = LOWER('$nama_ruang') AND id != '$id'");
        if(mysqli_num_rows($check) > 0){
            echo "ERROR|Gagal Update: Nama ruangan '$nama_ruang_format' sudah pernah diinputkan!";
            exit;
        }

        $query = "UPDATE ruang SET nama_ruang='$nama_ruang' WHERE id='$id' AND id_sekolah='$id_sekolah'";
        $msg = "Data ruang berhasil diupdate";
    } elseif(isset($_POST['hapus_id'])){
        // ================= PENGAMANAN 3: CASTING ID KE INTEGER =================
        $id = (int)$_POST['hapus_id'];
        $query = "DELETE FROM ruang WHERE id='$id' AND id_sekolah='$id_sekolah'";
        $msg = "Data ruang berhasil dihapus";
    } elseif(isset($_POST['hapus_semua'])){
        // ================= FITUR BARU: HAPUS SEMUA RUANG =================
        $query = "DELETE FROM ruang WHERE id_sekolah='$id_sekolah'";
        $msg = "Seluruh data ruang berhasil dihapus";
    }

    if(isset($query)){
        if(mysqli_query($conn, $query)){ echo "SUCCESS|$msg"; } 
        else { echo "ERROR|" . mysqli_error($conn); }
    }
    exit;
}

$totalRuang = mysqli_num_rows(mysqli_query($conn, "SELECT id FROM ruang WHERE id_sekolah='$id_sekolah'"));
?>

<style>
    /* ================= HERO TITLE PAGE ================= */
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
    .ruang-top-card {
        background: #ffffff;
        border-radius: 18px;
        border: 1px solid rgba(226, 232, 240, 0.9);
        box-shadow: 0 8px 24px -4px rgba(15, 23, 42, 0.05);
        padding: 18px 22px;
        height: 100%;
    }

    .stat-ruang-row {
        display: flex;
        align-items: center;
        gap: 16px;
        padding: 4px 6px;
    }
    .stat-ruang-icon {
        font-size: 40px;
        color: #164e87;
        line-height: 1;
        flex-shrink: 0;
    }
    .stat-ruang-label {
        font-size: 11px;
        font-weight: 800;
        color: #1e293b;
        text-transform: uppercase;
        letter-spacing: 0.4px;
        margin-bottom: 2px;
    }
    .stat-ruang-val {
        font-size: 28px;
        font-weight: 800;
        color: #0f172a;
        line-height: 1.1;
    }

    .btn-tambah-teal {
        background: linear-gradient(90deg, #06425b 0%, #0d8a9c 55%, #16b8c4 100%);
        border: none;
        color: #ffffff;
        font-weight: 700;
        font-size: 13.5px;
        padding: 10px 18px;
        border-radius: 25px;
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

    /* ================= KOTAK IMPORT DATA RUANG ================= */
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

    /* ================= BANNER HEADER TABEL DAFTAR RUANG ================= */
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

    /* ================= STYLING TABEL & BADGE IKON RUANG ================= */
    .table-freeze-container {
        max-height: 520px;
        overflow-y: auto;
        position: relative;
    }
    #tableRuang {
        margin-bottom: 0;
        border-collapse: separate;
        border-spacing: 0;
    }
    #tableRuang thead th {
        position: sticky;
        top: 0;
        background-color: #f1f5f9;
        color: #0f172a;
        font-weight: 800;
        font-size: 13px;
        padding: 14px 18px;
        border-bottom: 1.5px solid #e2e8f0;
        z-index: 10;
        white-space: nowrap;
    }
    #tableRuang tbody tr {
        transition: background-color 0.15s ease;
    }
    #tableRuang tbody tr:nth-child(even) {
        background-color: #f4f9fd;
    }
    #tableRuang tbody tr:hover {
        background-color: #e6f4fc;
    }
    #tableRuang tbody td {
        padding: 12px 18px;
        border-bottom: 1px solid #e2e8f0;
        font-size: 13.5px;
        color: #1e293b;
        vertical-align: middle;
    }

    /* Ikon Ruangan Variasi Warna */
    .ruang-badge-icon {
        width: 34px;
        height: 34px;
        border-radius: 50%;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 15px;
        flex-shrink: 0;
    }
    .rb-teal  { background: #ccfbf1; color: #0f766e; }
    .rb-blue  { background: #dbeafe; color: #1d4ed8; }
    .rb-green { background: #dcfce7; color: #15803d; }
    .rb-amber { background: #fef3c7; color: #d97706; }

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

    /* Form Tambah Ruang Panel */
    .form-control-custom { border-radius: 10px; border: 1px solid #cbd5e1; padding: 10px; font-size: 13.5px; }
    .form-control-custom:focus { border-color: #0d8a9c; box-shadow: 0 0 0 3px rgba(13, 138, 156, 0.14); }

    .success-checkmark { width: 80px; height: 80px; margin: 0 auto; }
    .check-icon { width: 80px; height: 80px; position: relative; border-radius: 50%; border: 4px solid #16a34a; }
    .icon-line { height: 5px; background-color: #16a34a; display: block; border-radius: 2px; position: absolute; z-index: 10; }
    .line-tip { top: 46px; left: 14px; width: 25px; transform: rotate(45deg); }
    .line-long { top: 38px; right: 8px; width: 47px; transform: rotate(-45deg); }
</style>

<div class="container-fluid px-0">
    
    <!-- ================= HERO TITLE ================= -->
    <div class="page-hero-header">
        <div class="page-hero-icon">
            <i class="bi bi-buildings-fill"></i>
        </div>
        <div>
            <h3 class="page-hero-title">Manajemen Ruang</h3>
            <p class="page-hero-subtitle">Atur daftar ruangan instansi</p>
        </div>
    </div>

    <!-- ================= ROW KARTU STATISTIK & IMPORT ================= -->
    <div class="row g-3 mb-4">
        <!-- KARTU KIRI: TOTAL RUANGAN -->
        <div class="col-xl-3 col-lg-4">
            <div class="ruang-top-card">
                <div class="stat-ruang-row">
                    <i class="bi bi-buildings stat-ruang-icon"></i>
                    <div>
                        <div class="stat-ruang-label">TOTAL RUANGAN</div>
                        <div class="stat-ruang-val"><?= $totalRuang ?></div>
                    </div>
                </div>
                <button id="btnTambah" class="btn-tambah-teal">
                    <i class="bi bi-plus-lg"></i> Tambah Ruang
                </button>
            </div>
        </div>

        <!-- KARTU KANAN: IMPORT DATA RUANG & DOWNLOAD TEMPLATE -->
        <div class="col-xl-9 col-lg-8">
            <div class="ruang-top-card">
                <div class="import-dashed-inner">
                    <div class="row align-items-center w-100 g-3">
                        <div class="col-xl-7 col-md-7">
                            <div class="d-flex align-items-center mb-2">
                                <span class="excel-badge-icon">X</span>
                                <h6 class="fw-bold mb-0 text-dark" style="font-size: 14.5px;">Import Data Ruang</h6>
                            </div>
                            <form id="importFormRuang" class="d-flex align-items-center gap-2">
                                <input type="hidden" name="import_excel" value="1">
                                <div class="import-file-group">
                                    <label for="inputExcelRuang" class="btn-choose-excel mb-0">
                                        <i class="bi bi-upload text-primary"></i> Choose File
                                    </label>
                                    <input type="file" id="inputExcelRuang" name="file_excel" class="d-none" required accept=".xlsx">
                                    <span id="excelRuangFileName" class="excel-file-name">No file chosen</span>
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
                            <a href="data_ruang.php?template=1" class="btn-download-template">
                                <i class="bi bi-download"></i> Download Template
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ================= FORM TAMBAH RUANGAN (MULTI-INPUT DENGAN DINAMIS ROW) ================= -->
    <div id="formTambah" class="table-card-wrapper mb-4" style="display:none;">
        <div class="table-banner-header">
            <h6 class="table-banner-title"><i class="bi bi-door-open-fill"></i> Tambah Ruangan Baru</h6>
            <button type="button" class="btn-close btn-close-white" style="position:relative; z-index:2;" onclick="document.getElementById('formTambah').style.display='none'"></button>
        </div>
        <div class="p-4">
            <form id="ruangForm">
                <input type="hidden" name="simpan" value="1">
                
                <div id="containerRowRuang">
                    <div class="row g-3 row-ruang mb-3 align-items-end">
                        <div class="col-md-11">
                            <label class="small fw-bold text-secondary mb-1">Nama Ruangan</label>
                            <input name="nama_ruang[]" class="form-control form-control-custom" placeholder="Contoh: Ruang Guru / Kelas VII-C" required>
                        </div>
                        <div class="col-md-1 text-center">
                            <button type="button" class="btn btn-outline-danger border-0 action-btn btn-remove-row w-100" style="height:42px;" title="Hapus Baris" disabled>
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
                        <i class="bi bi-floppy2-fill"></i> Simpan Semua Ruangan
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- ================= TABEL DAFTAR RUANG ================= -->
    <div class="table-card-wrapper">
        <div class="table-banner-header">
            <h6 class="table-banner-title">
                <i class="bi bi-buildings-fill"></i> DAFTAR RUANG
            </h6>
            <div class="d-flex align-items-center gap-2 flex-wrap" style="position: relative; z-index: 2;">
                <div class="search-pill-box">
                    <i class="bi bi-search text-muted"></i>
                    <input type="text" id="searchRuang" placeholder="Cari Nama Ruangan...">
                </div>
                <button onclick="hapusSemuaRuang()" class="btn-hapus-semua" title="Hapus Seluruh Data Ruang">
                    <i class="bi bi-trash3-fill"></i> Hapus Semua
                </button>
            </div>
        </div>

        <div class="table-responsive table-freeze-container">
            <table class="table align-middle" id="tableRuang">
                <thead>
                    <tr>
                        <th class="text-center" width="80">No</th>
                        <th><i class="bi bi-people-fill text-primary me-2"></i>Daftar Ruang</th>
                        <th class="text-center" width="150"><i class="bi bi-gear-fill text-primary me-1"></i> Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $no = 1;
                    $ruangStyles = [
                        ['bg' => 'rb-teal',  'icon' => 'bi-people-fill'],
                        ['bg' => 'rb-blue',  'icon' => 'bi-display'],
                        ['bg' => 'rb-green', 'icon' => 'bi-gear-fill'],
                        ['bg' => 'rb-amber', 'icon' => 'bi-folder-fill']
                    ];
                    $q = mysqli_query($conn, "SELECT * FROM ruang WHERE id_sekolah='$id_sekolah' ORDER BY nama_ruang ASC");
                    while($r = mysqli_fetch_assoc($q)):
                        $style = $ruangStyles[($no - 1) % 4];
                    ?>
                    <tr>
                        <td class="text-center fw-semibold text-secondary"><?= $no++ ?></td>
                        <td>
                            <div class="d-flex align-items-center gap-3">
                                <div class="ruang-badge-icon <?= $style['bg'] ?>">
                                    <i class="bi <?= $style['icon'] ?>"></i>
                                </div>
                                <span class="fw-bold text-dark"><?= htmlspecialchars($r['nama_ruang'], ENT_QUOTES, 'UTF-8') ?></span>
                            </div>
                        </td>
                        <td class="text-center">
                            <button class="action-btn btn-edit-link btn-edit-trigger" 
                                data-id="<?= (int)$r['id'] ?>"
                                data-nama="<?= htmlspecialchars($r['nama_ruang'], ENT_QUOTES, 'UTF-8') ?>"
                                title="Edit Ruangan">
                                <i class="bi bi-pencil-square"></i>
                            </button>
                            <button onclick="hapusRuang(<?= (int)$r['id'] ?>)" class="action-btn btn-delete-link ms-1" title="Hapus Ruangan">
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

<!-- ================= MODAL EDIT RUANG ================= -->
<div class="modal fade" id="editModalRuang" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <form id="editFormRuang" class="modal-content border-0 shadow-lg rounded-4 overflow-hidden">
            <input type="hidden" name="update" value="1">
            <div class="table-banner-header">
                <h6 class="table-banner-title"><i class="bi bi-pencil-square"></i> Edit Nama Ruangan</h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" style="position:relative; z-index:2;"></button>
            </div>
            <div class="modal-body p-4">
                <input type="hidden" name="id" id="modal-id">
                <div class="mb-2">
                    <label class="small fw-bold mb-1">Nama Ruangan</label>
                    <input type="text" name="nama_ruang" id="modal-nama" class="form-control form-control-custom" required>
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
<div class="modal fade" id="modalBerhasilRuang" tabindex="-1" data-bs-backdrop="static">
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

<!-- ================= MODAL HAPUS SATUAN ================= -->
<div class="modal fade" id="modalHapus" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content border-0 shadow-lg rounded-4 text-center">
            <div class="modal-body p-4">
                <div class="mb-3 text-danger">
                    <i class="bi bi-exclamation-circle-fill" style="font-size: 4rem;"></i>
                </div>
                <h5 class="fw-bold text-dark">Hapus Data?</h5>
                <p class="text-secondary small mb-4">Data ruangan ini akan dihapus permanen dan tidak dapat dikembalikan.</p>
                <div class="d-flex justify-content-center gap-2">
                    <button type="button" class="btn btn-light btn-sm px-4 rounded-pill fw-bold" data-bs-dismiss="modal">Batal</button>
                    <button type="button" id="btnKonfirmasiHapus" class="btn btn-danger btn-sm px-4 rounded-pill fw-bold shadow-sm">Ya, Hapus</button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ================= MODAL KONFIRMASI HAPUS SEMUA RUANG ================= -->
<div class="modal fade" id="modalHapusSemua" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content border-0 shadow-lg rounded-4 text-center">
            <div class="modal-body p-4">
                <div class="mb-3 text-danger">
                    <i class="bi bi-exclamation-triangle-fill" style="font-size: 4rem;"></i>
                </div>
                <h5 class="fw-bold text-dark">Hapus SEMUA Data?</h5>
                <p class="text-secondary small mb-4">Peringatan! Seluruh data ruang akan dihapus permanen. Tindakan ini tidak dapat dibatalkan.</p>
                <div class="d-flex justify-content-center gap-2">
                    <button type="button" class="btn btn-light btn-sm px-4 rounded-pill fw-bold" data-bs-dismiss="modal">Batal</button>
                    <button type="button" id="btnKonfirmasiHapusSemua" class="btn btn-danger btn-sm px-4 rounded-pill fw-bold shadow-sm">Ya, Hapus Semua</button>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    var eModRuang = new bootstrap.Modal(document.getElementById('editModalRuang'));
    var sModRuang = new bootstrap.Modal(document.getElementById('modalBerhasilRuang'));
    var modHapus = new bootstrap.Modal(document.getElementById('modalHapus'));
    var modHapusSemua = new bootstrap.Modal(document.getElementById('modalHapusSemua'));
    var idYangAkanDihapus = null;

    // Tampilkan nama file Excel saat dipilih
    const inputExcelRuang = document.getElementById('inputExcelRuang');
    const excelRuangFileName = document.getElementById('excelRuangFileName');
    if (inputExcelRuang) {
        inputExcelRuang.addEventListener('change', function() {
            excelRuangFileName.textContent = this.files[0] ? this.files[0].name : 'No file chosen';
        });
    }

    // FITUR CARI NAMA RUANGAN REALTIME
    document.getElementById('searchRuang').addEventListener('input', function() {
        let keyword = this.value.toLowerCase().trim();
        let rows = document.querySelectorAll('#tableRuang tbody tr');
        let no = 1;

        rows.forEach(row => {
            let nama = row.cells[1] ? row.cells[1].textContent.toLowerCase() : '';

            if (nama.includes(keyword)) {
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
        if(f.style.display === 'block') f.querySelector('input').focus();
    };

    // LOGIKA TAMBAH BARIS RUANGAN DINAMIS
    document.getElementById('btnTambahRow').onclick = function() {
        let container = document.getElementById('containerRowRuang');
        let firstRow = container.querySelector('.row-ruang');
        let newRow = firstRow.cloneNode(true);
        
        // Bersihkan nilai input di baris baru
        newRow.querySelectorAll('input').forEach(input => input.value = '');
        
        container.appendChild(newRow);
        updateRemoveButtons();
    };

    function updateRemoveButtons() {
        let rows = document.querySelectorAll('.row-ruang');
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

    document.querySelectorAll('.btn-edit-trigger').forEach(btn => {
        btn.onclick = function() {
            document.getElementById('modal-id').value = this.dataset.id;
            document.getElementById('modal-nama').value = this.dataset.nama;
            eModRuang.show();
        };
    });

    document.getElementById('ruangForm').onsubmit = function(e){
        e.preventDefault();
        submitAjaxRuang(new FormData(this));
    };

    document.getElementById('editFormRuang').onsubmit = function(e){
        e.preventDefault();
        eModRuang.hide();
        submitAjaxRuang(new FormData(this));
    };

    document.getElementById('importFormRuang').onsubmit = function(e){
        e.preventDefault();
        submitAjaxRuang(new FormData(this));
    };

    // FUNGSI TRIGGER MODAL HAPUS SATUAN
    function hapusRuang(id){
        idYangAkanDihapus = id;
        modHapus.show();
    }

    // FUNGSI TRIGGER MODAL HAPUS SEMUA
    function hapusSemuaRuang(){
        modHapusSemua.show();
    }

    // EVENT KETIKA TOMBOL "YA, HAPUS" DIKLIK
    document.getElementById('btnKonfirmasiHapus').onclick = function() {
        if(idYangAkanDihapus !== null) {
            let fd = new FormData();
            fd.append('hapus_id', idYangAkanDihapus);
            
            modHapus.hide();
            submitAjaxRuang(fd);
            idYangAkanDihapus = null;
        }
    };

    // EVENT KETIKA TOMBOL "YA, HAPUS SEMUA" DIKLIK
    document.getElementById('btnKonfirmasiHapusSemua').onclick = function() {
        let fd = new FormData();
        fd.append('hapus_semua', '1');
        
        modHapusSemua.hide();
        submitAjaxRuang(fd);
    };

    async function submitAjaxRuang(formData) {
        try {
            const response = await fetch('data_ruang.php', {
                method: 'POST',
                body: formData
            });
            const text = await response.text();
            const res = text.split('|');

            if(res[0] === 'SUCCESS'){
                document.getElementById('msgBerhasil').innerText = res[1];
                sModRuang.show();
                
                // AUTO CLOSE MODAL 2 SAAT
                setTimeout(() => {
                    sModRuang.hide();
                }, 2000);

                // RELOAD APABILA MODAL TELAH DITUTUP
                document.getElementById('modalBerhasilRuang').addEventListener('hidden.bs.modal', function () {
                    if (typeof loadPage === 'function') {
                        loadPage('data_ruang.php'); 
                    } else {
                        location.reload();
                    }
                }, { once: true });
            } else {
                alert('Gagal: ' + (res[1] || text));
            }
        } catch (err) {
            alert('Kesalahan koneksi');
        }
    }
</script>