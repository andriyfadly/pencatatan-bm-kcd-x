<?php
// ================= PENGAMANAN 1: SECURITY HEADERS =================
// Mencegah Clickjacking dan mengamankan MIME types
header("X-Frame-Options: DENY");
header("X-XSS-Protection: 1; mode=block");
header("X-Content-Type-Options: nosniff");

session_start();

if(!isset($_SESSION['id_user'])){
    header("Location: login.php");
    exit;
}

include "koneksi.php";

// ================= PENGAMANAN 2: AMANKAN SESSION ID =================
$id_sekolah = mysqli_real_escape_string($conn, $_SESSION['id_sekolah']);

// ================= PERBAIKAN BUG REFRESH (SAFE CLEANUP) =================
// Membersihkan data kondisi terisolasi (jika barang utamanya sudah dihapus dari DB)
// Menghindari penghapusan otomatis data Rusak Berat / Rusak Ringan saat F5
mysqli_query($conn, "DELETE kd FROM kondisi_barang_detail kd 
                     LEFT JOIN data_barang b ON kd.id_barang = b.id 
                     WHERE b.id IS NULL");

// ================= ENDPOINT REALTIME STATISTIK (AJAX) =================
if(isset($_GET['ajax_stats'])) {
    header('Content-Type: application/json');
    
    // 1. Total Barang
    $qVol = mysqli_query($conn, "SELECT SUM(volume) as total_vol FROM data_barang WHERE id_sekolah='$id_sekolah'");
    $dVol = mysqli_fetch_assoc($qVol);
    $totalBarang = (int)($dVol['total_vol'] ?? 0);

    // 2. Kondisi Detail
    $qKondisi = mysqli_query($conn, "
        SELECT 
            SUM(CASE WHEN kd.kondisi = 'B' THEN 1 ELSE 0 END) as total_b,
            SUM(CASE WHEN kd.kondisi = 'RR' THEN 1 ELSE 0 END) as total_rr,
            SUM(CASE WHEN kd.kondisi = 'RB' THEN 1 ELSE 0 END) as total_rb
        FROM kondisi_barang_detail kd
        JOIN data_barang b ON kd.id_barang = b.id
        WHERE b.id_sekolah='$id_sekolah'
    ");
    $dKondisi = mysqli_fetch_assoc($qKondisi);

    $totalRingan = (int)($dKondisi['total_rr'] ?? 0);
    $totalBerat  = (int)($dKondisi['total_rb'] ?? 0);
    $totalB_detail = (int)($dKondisi['total_b'] ?? 0);

    $totalDetailAda = $totalB_detail + $totalRingan + $totalBerat;
    $sisaVolume = $totalBarang - $totalDetailAda;
    $totalBaik = $totalB_detail + ($sisaVolume > 0 ? $sisaVolume : 0);

    // 3. Barang Hilang
    $qHilang = mysqli_query($conn,"SELECT SUM(volume) as total_h FROM data_barang WHERE id_sekolah='$id_sekolah' AND keadaan_barang='H'");
    $dHilang = mysqli_fetch_assoc($qHilang);
    $totalHilang = (int)($dHilang['total_h'] ?? 0);

    echo json_encode([
        'totalBarang' => number_format($totalBarang),
        'totalBaik'   => number_format($totalBaik),
        'totalRingan' => number_format($totalRingan),
        'totalBerat'  => number_format($totalBerat),
        'totalHilang' => number_format($totalHilang)
    ]);
    exit;
}

// ================= TAMBAHAN FITUR: AJAX FETCH DATA KONDISI BARANG =================
if(isset($_GET['ajax_kondisi'])) {
    $allowed_kondisi = ['B', 'RR', 'RB'];
    $kondisi_input = strtoupper(trim($_GET['ajax_kondisi']));
    
    if(!in_array($kondisi_input, $allowed_kondisi)) {
        die('<div class="alert alert-danger text-center">Akses Ditolak: Parameter Kondisi Tidak Valid!</div>');
    }
    
    $kondisi = mysqli_real_escape_string($conn, $kondisi_input);
    
    $sql = "SELECT 
                b.nama_jenis_barang, 
                b.merek_type, 
                kd.no_reg, 
                (SELECT p.lokasi FROM penyaluran p WHERE p.id_barang = b.id AND p.no_reg = kd.no_reg ORDER BY p.id_penyaluran DESC LIMIT 1) as ruang_penyaluran,
                (SELECT p.nama_penerima FROM penyaluran p WHERE p.id_barang = b.id AND p.no_reg = kd.no_reg ORDER BY p.id_penyaluran DESC LIMIT 1) as nama_penerima
            FROM kondisi_barang_detail kd
            JOIN data_barang b ON kd.id_barang = b.id
            WHERE b.id_sekolah = '$id_sekolah' AND kd.kondisi = '$kondisi'
            ORDER BY b.nama_jenis_barang ASC, kd.no_reg ASC";
            
    $res = mysqli_query($conn, $sql);
    
    if(mysqli_num_rows($res) > 0){
        echo '<table class="table table-bordered table-striped table-hover mb-0" style="font-size: 14px;">';
        echo '<thead class="table-light align-middle text-center">
                <tr>
                    <th width="5%">No</th>
                    <th>Nama Barang</th>
                    <th>Merek/Tipe</th>
                    <th width="10%">No Reg</th>
                    <th>Ruang/Lokasi (Terakhir)</th>
                    <th>Penerima (Terakhir)</th>
                </tr>
              </thead>';
        echo '<tbody>';
        $no = 1;
        while($row = mysqli_fetch_assoc($res)){
            $merek = !empty($row['merek_type']) ? $row['merek_type'] : '-';
            
            if(!empty($row['ruang_penyaluran'])) {
                $ruang = htmlspecialchars($row['ruang_penyaluran']);
            } else {
                $ruang = '<span class="text-muted fst-italic">Belum disalurkan</span>';
            }
            
            $penerima = !empty($row['nama_penerima']) ? $row['nama_penerima'] : '-';
            
            echo '<tr>';
            echo '<td class="text-center">'.$no++.'</td>';
            echo '<td>'.htmlspecialchars($row['nama_jenis_barang']).'</td>';
            echo '<td>'.htmlspecialchars($merek).'</td>';
            echo '<td class="text-center fw-bold">'.htmlspecialchars($row['no_reg']).'</td>';
            echo '<td>'.$ruang.'</td>';
            echo '<td>'.htmlspecialchars($penerima).'</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
    } else {
        echo '<div class="alert alert-secondary text-center mb-0">
                <i class="bi bi-inboxes fs-1 d-block mb-2 text-secondary"></i>
                <h6 class="mb-0 fw-semibold text-secondary">Data Kosong</h6>
                <small>Tidak ada rincian barang untuk kondisi ini.</small>
              </div>';
    }
    exit;
}

// ================= NAMA SEKOLAH =================
$qSekolah = mysqli_query($conn,"SELECT nama_sekolah FROM kode_sekolah WHERE id='$id_sekolah' LIMIT 1");
$dataSekolah = mysqli_fetch_assoc($qSekolah);
$nama_sekolah = $dataSekolah['nama_sekolah'] ?? "SEKOLAH";

// ================= HITUNG DATA INITIAL (SERVER-SIDE) =================
$qVol = mysqli_query($conn, "SELECT SUM(volume) as total_vol FROM data_barang WHERE id_sekolah='$id_sekolah'");
$dVol = mysqli_fetch_assoc($qVol);
$totalBarang = $dVol['total_vol'] ? $dVol['total_vol'] : 0;

$qKondisi = mysqli_query($conn, "
    SELECT 
        SUM(CASE WHEN kd.kondisi = 'B' THEN 1 ELSE 0 END) as total_b,
        SUM(CASE WHEN kd.kondisi = 'RR' THEN 1 ELSE 0 END) as total_rr,
        SUM(CASE WHEN kd.kondisi = 'RB' THEN 1 ELSE 0 END) as total_rb
    FROM kondisi_barang_detail kd
    JOIN data_barang b ON kd.id_barang = b.id
    WHERE b.id_sekolah='$id_sekolah'
");
$dKondisi = mysqli_fetch_assoc($qKondisi);

$totalRingan = $dKondisi['total_rr'] ?: 0;
$totalBerat  = $dKondisi['total_rb'] ?: 0;
$totalB_detail = $dKondisi['total_b'] ?: 0;

$totalDetailAda = $totalB_detail + $totalRingan + $totalBerat;
$sisaVolume = $totalBarang - $totalDetailAda;
$totalBaik = $totalB_detail + ($sisaVolume > 0 ? $sisaVolume : 0);

$qHilang = mysqli_query($conn,"SELECT SUM(volume) as total_h FROM data_barang WHERE id_sekolah='$id_sekolah' AND keadaan_barang='H'");
$dHilang = mysqli_fetch_assoc($qHilang);
$totalHilang = $dHilang['total_h'] ?: 0;
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inventaris Barang | Dashboard</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800;900&family=Caveat:wght@700&display=swap" rel="stylesheet">

    <style>
        :root {
            --primary: #1a8fe3;
            --sidebar-bg: #074759;
            --sidebar-darker: #043240;
            --sidebar-active: #20b4cb;
            --sidebar-width: 265px;
            --sidebar-collapsed: 80px;
            --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            --text-main: #0f172a;
        }

        * { font-family: 'Plus Jakarta Sans', sans-serif; margin: 0; padding: 0; box-sizing: border-box; }
        
        body { 
            background: linear-gradient(160deg, #f6fafd 0%, #ebf5fb 55%, #dcf0fa 100%);
            color: var(--text-main); 
            overflow-x: hidden;
            min-height: 100vh;
            position: relative;
        }

        /* ================= BACKGROUND GELOMBANG HALAMAN ================= */
        .page-bg-waves {
            position: fixed;
            bottom: 0;
            left: 0;
            width: 100%;
            height: 360px;
            z-index: 0;
            pointer-events: none;
            overflow: hidden;
        }
        .page-bg-waves svg {
            width: 100%;
            height: 100%;
            display: block;
        }

        /* ================= SIDEBAR DARK TEAL ================= */
        .sidebar {
            width: var(--sidebar-width); 
            height: 100vh; 
            position: fixed; 
            top: 0; 
            left: 0;
            background: linear-gradient(180deg, #074759 0%, #053948 65%, #042d3a 100%);
            z-index: 1000;
            transition: var(--transition); 
            display: flex; 
            flex-direction: column;
            box-shadow: 4px 0 24px rgba(4, 45, 58, 0.15);
            overflow: hidden;
        }

        /* Wave dekorasi bawah sidebar */
        .sidebar::after {
            content: '';
            position: absolute;
            bottom: 50px;
            left: -20%;
            width: 150%;
            height: 130px;
            background: radial-gradient(ellipse at center, rgba(32, 180, 203, 0.18) 0%, transparent 70%);
            border-radius: 50%;
            pointer-events: none;
        }

        body.collapsed .sidebar { width: var(--sidebar-collapsed); }

        .sidebar .brand { 
            padding: 22px 15px 14px; 
            display: flex; 
            flex-direction: column; 
            align-items: center; 
            justify-content: center;
            text-align: center;
            text-decoration: none;
        }

        .brand-logo-wrap {
            display: flex;
            flex-direction: column;
            align-items: center;
            text-decoration: none;
        }

        .brand-icon-svg {
            width: 68px;
            height: 62px;
            margin-bottom: 4px;
            transition: var(--transition);
        }

        body.collapsed .brand-icon-svg {
            width: 40px;
            height: 38px;
        }

        .brand-title-row {
            display: flex;
            align-items: baseline;
            justify-content: center;
            gap: 5px;
            line-height: 1;
        }

        .brand-si-dipta {
            font-weight: 900;
            font-size: 1.45rem;
            color: #ffffff;
            letter-spacing: 0.3px;
            text-shadow: 0 2px 6px rgba(0,0,0,0.2);
        }

        .brand-beta {
            font-family: 'Caveat', cursive;
            font-size: 1.65rem;
            font-weight: 700;
            color: #f5b027;
            transform: rotate(-6deg);
            display: inline-block;
        }

        .brand-subtitle {
            font-size: 9px;
            font-weight: 500;
            color: rgba(255, 255, 255, 0.78);
            margin-top: 3px;
            letter-spacing: 0.2px;
        }

        body.collapsed .brand-title-row,
        body.collapsed .brand-subtitle,
        body.collapsed .menu-btn span,
        body.collapsed .menu-btn .bi-chevron-down {
            display: none;
        }

        .nav-wrapper { 
            padding: 8px 14px; 
            flex-grow: 1; 
            overflow-y: auto; 
            overflow-x: hidden; 
            position: relative;
            z-index: 2;
        }

        .nav-wrapper::-webkit-scrollbar { width: 4px; }
        .nav-wrapper::-webkit-scrollbar-thumb { background: rgba(255,255,255,0.15); border-radius: 10px; }

        .menu-label { 
            font-size: 10.5px; 
            font-weight: 700; 
            color: rgba(255, 255, 255, 0.72); 
            text-transform: uppercase; 
            letter-spacing: 0.6px;
            padding: 14px 12px 6px; 
        }
        body.collapsed .menu-label { opacity: 0; height: 0; padding: 0; overflow: hidden; }

        .menu-btn {
            width: 100%; 
            border: none; 
            background: transparent; 
            padding: 11px 14px; 
            border-radius: 10px;
            display: flex; 
            align-items: center; 
            gap: 13px; 
            color: rgba(255, 255, 255, 0.88); 
            font-weight: 500;
            font-size: 14px; 
            margin-bottom: 4px; 
            transition: var(--transition); 
            text-decoration: none; 
            white-space: nowrap; 
            cursor: pointer;
        }
        .menu-btn i { font-size: 17px; min-width: 22px; text-align: center; }
        .menu-btn:hover { background: rgba(255, 255, 255, 0.1); color: #ffffff; }
        
        .menu-btn.active { 
            background: linear-gradient(90deg, #22b8cf 0%, #1da1b8 100%);
            color: #ffffff; 
            font-weight: 700;
            box-shadow: 0 4px 12px rgba(32, 180, 203, 0.35);
        }

        body.collapsed .menu-btn { justify-content: center; padding: 12px 0; }

        .submenu-list { 
            padding-left: 24px; 
            border-left: 1.5px solid rgba(255, 255, 255, 0.2); 
            margin-left: 22px; 
            margin-bottom: 8px; 
        }
        body.collapsed .submenu-list { display: none !important; }

        .submenu-btn { 
            padding: 7px 12px; 
            font-size: 13px; 
            color: rgba(255, 255, 255, 0.78); 
            text-decoration: none; 
            display: block; 
            border-radius: 8px; 
            cursor: pointer; 
            transition: 0.2s; 
        }
        .submenu-btn:hover { color: #ffffff; background: rgba(255,255,255,0.08); padding-left: 16px; }
        .submenu-btn.active { color: #38d9f5; font-weight: 700; }

        .sidebar-footer {
            padding: 14px 16px 18px;
            border-top: 1px solid rgba(255, 255, 255, 0.12);
            position: relative;
            z-index: 2;
        }
        .logout-btn {
            width: 100%;
            border: none;
            background: transparent;
            padding: 10px 12px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            gap: 12px;
            color: #ffffff;
            font-weight: 500;
            font-size: 14.5px;
            cursor: pointer;
            transition: 0.2s;
        }
        .logout-btn i {
            color: #ff6b6b;
            font-size: 18px;
        }
        .logout-btn:hover {
            background: rgba(255, 107, 107, 0.15);
        }
        body.collapsed .logout-btn { justify-content: center; }
        body.collapsed .logout-btn span { display: none; }

        /* ================= HEADER TOPBAR ================= */
        .header {
            position: fixed; 
            top: 0; 
            left: var(--sidebar-width); 
            right: 0; 
            height: 76px;
            background: rgba(255, 255, 255, 0.85); 
            backdrop-filter: blur(12px); 
            border-bottom: 1px solid rgba(226, 232, 240, 0.8);
            display: flex; 
            align-items: center; 
            justify-content: space-between; 
            padding: 0 32px;
            z-index: 900; 
            transition: var(--transition);
        }
        body.collapsed .header { left: var(--sidebar-collapsed); }

        .toggle-btn {
            cursor: pointer;
            width: 38px;
            height: 38px;
            background: #f1f5f9;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #334155;
            transition: 0.2s;
        }
        .toggle-btn:hover { background: #e2e8f0; color: #0f172a; }

        .header-title {
            font-size: 17px;
            font-weight: 800;
            color: #0f172a;
            margin-bottom: 1px;
        }
        .header-subtitle {
            font-size: 12px;
            font-weight: 600;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }

        /* ================= MAIN CONTENT ================= */
        .content { 
            margin-left: var(--sidebar-width); 
            padding: 106px 34px 40px; 
            transition: var(--transition); 
            min-height: 100vh; 
            position: relative;
            z-index: 1;
            display: flex;
            flex-direction: column;
        }
        body.collapsed .content { margin-left: var(--sidebar-collapsed); }

        #ajax-container { flex-grow: 1; }

        #loader { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 4px; background: transparent; z-index: 2000; }
        #loader div { width: 0; height: 100%; background: #20b4cb; transition: 0.3s; box-shadow: 0 0 10px #20b4cb; }

        /* ================= STAT CARDS SESUAI GAMBAR ================= */
        .stat-card {
            position: relative;
            background: #ffffff;
            border-radius: 18px;
            padding: 22px 24px 24px;
            box-shadow: 0 10px 25px -5px rgba(15, 23, 42, 0.06), 0 4px 10px -2px rgba(15, 23, 42, 0.03);
            border: 1px solid rgba(255, 255, 255, 0.9);
            border-top: 5px solid transparent;
            overflow: hidden;
            transition: all 0.28s cubic-bezier(0.4, 0, 0.2, 1);
            height: 178px;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
        }

        .stat-card.clickable { cursor: pointer; }
        .stat-card.clickable:hover {
            transform: translateY(-5px);
            box-shadow: 0 18px 32px -8px rgba(15, 23, 42, 0.12);
        }

        /* Aksen Garis Atas & Warna Angka per Kartu */
        .card-blue { border-top-color: #1677ff; }
        .card-blue .stat-icon-box { background: #e6f0ff; color: #1677ff; }
        .card-blue .stat-number { color: #0b4a8f; }

        .card-green { border-top-color: #16a34a; }
        .card-green .stat-icon-box { background: #dcfce7; color: #16a34a; }
        .card-green .stat-number { color: #0f6b32; }

        .card-yellow { border-top-color: #f59e0b; }
        .card-yellow .stat-icon-box { background: #fef3c7; color: #d97706; }
        .card-yellow .stat-number { color: #d97706; }

        .card-red { border-top-color: #dc2626; }
        .card-red .stat-icon-box { background: #fee2e2; color: #dc2626; }
        .card-red .stat-number { color: #dc2626; }

        .card-purple { border-top-color: #7c3aed; }
        .card-purple .stat-icon-box { background: #ede9fe; color: #6d28d9; }
        .card-purple .stat-number { color: #4c1d95; }

        .stat-icon-box {
            width: 42px;
            height: 42px;
            border-radius: 11px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 19px;
            position: relative;
            z-index: 2;
        }

        .stat-info {
            position: relative;
            z-index: 2;
            margin-top: 12px;
        }

        .stat-label {
            font-size: 13.5px;
            font-weight: 700;
            color: #1e293b;
            margin-bottom: 2px;
        }

        .stat-number {
            font-size: 38px;
            font-weight: 800;
            line-height: 1.1;
            letter-spacing: -0.5px;
            margin: 0;
        }

        /* Ilustrasi Watermark & Wave Bawah di Dalam Kartu */
        .card-watermark {
            position: absolute;
            right: 18px;
            bottom: 22px;
            width: 68px;
            height: 68px;
            opacity: 0.14;
            z-index: 1;
            pointer-events: none;
            transition: transform 0.3s ease;
        }
        .stat-card:hover .card-watermark {
            transform: scale(1.06);
        }

        .card-bottom-wave {
            position: absolute;
            bottom: 0;
            right: 0;
            width: 100%;
            height: 28px;
            z-index: 1;
            pointer-events: none;
        }

        .footer {
            margin-top: 30px;
            text-align: center;
            font-size: 13px;
            font-weight: 600;
            color: #334155;
            position: relative;
            z-index: 2;
        }

        /* ================= FIX Z-INDEX MODAL AGAR TIDAK REDUP / TERJEBAK ================= */
        .modal {
            z-index: 1065 !important;
        }
        .modal-backdrop {
            z-index: 1055 !important;
        }

        @media (max-width: 992px) {
            .sidebar { left: -100%; }
            body.mobile-show .sidebar { left: 0; width: var(--sidebar-width); }
            .header, .content { left: 0 !important; margin-left: 0 !important; }
        }
    </style>
</head>
<body class="">

<!-- Background Waves Halaman -->
<div class="page-bg-waves">
    <svg viewBox="0 0 1440 320" preserveAspectRatio="none">
        <path fill="rgba(186, 230, 253, 0.45)" d="M0,224L80,213.3C160,203,320,181,480,186.7C640,192,800,224,960,218.7C1120,213,1280,171,1360,149.3L1440,128L1440,320L1360,320C1280,320,1120,320,960,320C800,320,640,320,480,320C320,320,160,320,80,320L0,320Z"></path>
        <path fill="rgba(125, 211, 252, 0.35)" d="M0,256L120,245.3C240,235,480,213,720,218.7C960,224,1200,256,1320,272L1440,288L1440,320L1320,320C1200,320,960,320,720,320C480,320,240,320,120,320L0,320Z"></path>
    </svg>
</div>

<div id="loader"><div></div></div>

<!-- ================= SIDEBAR ================= -->
<div class="sidebar" id="sidebar">
    <div class="brand">
        <a href="index.php" class="brand-logo-wrap">
            <!-- Ilustrasi Logo SI DIPTA Sesuai Desain -->
            <svg class="brand-icon-svg" viewBox="0 0 100 90" fill="none" xmlns="http://www.w3.org/2000/svg">
                <circle cx="50" cy="42" r="32" fill="#20b4cb" fill-opacity="0.22"/>
                <path d="M22 48L50 28L78 48V74C78 76.2 76.2 78 74 78H26C23.8 78 22 76.2 22 74V48Z" fill="#0d6e8a" stroke="#7dd3fc" stroke-width="2.5"/>
                <rect x="33" y="20" width="34" height="44" rx="4" fill="#ffffff" stroke="#074759" stroke-width="2"/>
                <rect x="39" y="28" width="22" height="3.5" rx="1.5" fill="#1677ff"/>
                <rect x="39" y="35" width="16" height="3.5" rx="1.5" fill="#20b4cb"/>
                <rect x="39" y="42" width="19" height="3.5" rx="1.5" fill="#94a3b8"/>
                <path d="M18 52L50 70L82 52" stroke="#f5b027" stroke-width="4" stroke-linecap="round" stroke-linejoin="round"/>
                <circle cx="71" cy="54" r="11" fill="#16a34a" stroke="#ffffff" stroke-width="2"/>
                <path d="M66.5 54L69.5 57L75.5 51" stroke="#ffffff" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
            <div class="brand-title-row">
                <span class="brand-si-dipta">SI DIPTA</span>
                <span class="brand-beta">Beta!</span>
            </div>
            <div class="brand-subtitle">Sistem Digitalisasi Persediaan Aset</div>
        </a>
    </div>

    <div class="nav-wrapper">
        <div class="menu-label">MAIN MENU</div>
        
        <a href="index.php" class="menu-btn ajax-link active" data-page="dashboard" data-title="Dashboard">
            <i class="bi bi-grid-fill"></i>
            <span>Dashboard</span>
        </a>
        <a href="#master" class="menu-btn" data-bs-toggle="collapse" id="btn-master">
            <i class="bi bi-database-fill"></i>
            <span>Master Data</span>
            <i class="bi bi-chevron-down ms-auto fs-6"></i>
        </a>
        <div class="collapse submenu-list" id="master">
            <a href="data_kop.php" class="submenu-btn ajax-link" data-page="data_kop.php" data-title="Struktur Manajemen">Struktur Manajemen</a>
            <a href="data_pegawai.php" class="submenu-btn ajax-link" data-page="data_pegawai.php" data-title="Data Pegawai">Data Pegawai</a>
            <a href="data_ruang.php" class="submenu-btn ajax-link" data-page="data_ruang.php" data-title="Data Ruang">Data Ruang</a>
            <a href="import_barang.php" class="submenu-btn ajax-link" data-page="import_barang.php" data-title="Data Barang Inventaris">Data Barang Inventaris</a>
        </div>

        <div class="menu-label">REPORTS & TOOLS</div>
        <a href="#penyaluran" class="menu-btn" data-bs-toggle="collapse" id="btn-penyaluran">
            <i class="bi bi-file-earmark-bar-graph-fill"></i>
            <span>Penyaluran</span>
            <i class="bi bi-chevron-down ms-auto fs-6"></i>
        </a>
        <div class="collapse submenu-list" id="penyaluran">
            <a href="kartu_inventaris_ruangan.php" class="submenu-btn ajax-link" data-page="cetak_buku_inventaris.php" data-title="Buku Inventaris">Buku Inventaris</a>
            <a href="buku_salur.php" class="submenu-btn">Buku Penyaluran</a>
            <a href="kartu_inventaris_ruangan.php" class="submenu-btn ajax-link" data-page="kartu_inventaris_ruangan.php" data-title="Kartu Inventaris Ruangan">Kartu Inventaris Ruangan</a>
            <a href="cetak_label.php" class="submenu-btn">Labeling</a>
            <a href="data_pengguna.php" class="submenu-btn ajax-link" data-page="data_pengguna.php" data-title="Data Pengguna">Data Pengguna Barang</a>
        </div>

        <a href="rekapan_ba.php" class="menu-btn ajax-link" data-page="rekapan_ba.php" data-title="Rekapan BA">
            <i class="bi bi-file-earmark-text-fill"></i>
            <span>Rekapan BA</span>
        </a>

        <a href="pengembalian.php" class="menu-btn ajax-link" data-page="pengembalian.php" data-title="Pengembalian Barang">
            <i class="bi bi-arrow-left-right"></i>
            <span>Pengembalian Barang</span>
        </a>

        <a href="pengaturan.php" class="menu-btn ajax-link" data-page="pengaturan.php" data-title="Pengaturan">
            <i class="bi bi-gear-fill"></i>
            <span>Pengaturan</span>
        </a>
    </div>

    <div class="sidebar-footer">
        <button class="logout-btn" data-bs-toggle="modal" data-bs-target="#logoutModal">
            <i class="bi bi-box-arrow-left"></i>
            <span>Logout</span>
        </button>
    </div>
</div>

<!-- ================= TOP HEADER ================= -->
<div class="header">
    <div class="d-flex align-items-center gap-3">
        <div class="toggle-btn" id="sidebarToggle">
            <i class="bi bi-text-indent-left fs-5"></i>
        </div>
        <div>
            <div class="header-title" id="page-title">Dashboard</div>
            <div class="header-subtitle"><?= htmlspecialchars($nama_sekolah); ?></div>
        </div>
    </div>
    <div class="d-flex align-items-center gap-3">
        <div id="time" class="fw-bold d-none d-lg-block me-2 text-dark" style="font-size: 13.5px;"></div>
        <div class="text-white rounded-circle d-flex align-items-center justify-content-center shadow-sm" style="width: 38px; height: 38px; background: #1677ff;">
            <i class="bi bi-person-fill fs-5"></i>
        </div>
    </div>
</div>

<!-- ================= MAIN CONTENT ================= -->
<div class="content" id="main-content">
    <div id="ajax-container">
        <div id="dashboard-content">
            <div class="row g-4 mb-4">
                <!-- KARTU 1: TOTAL BARANG -->
                <div class="col-xl-3 col-md-6">
                    <div class="stat-card card-blue">
                        <div class="stat-icon-box"><i class="bi bi-box-seam-fill"></i></div>
                        <div class="stat-info">
                            <div class="stat-label">Total Barang (Unit)</div>
                            <h2 class="stat-number" id="stat-total-barang"><?= number_format($totalBarang) ?></h2>
                        </div>
                        <!-- Watermark Box -->
                        <svg class="card-watermark" viewBox="0 0 64 64" fill="none">
                            <path d="M32 6L56 19V45L32 58L8 45V19L32 6Z" fill="#1677ff"/>
                            <path d="M32 32L56 19M32 32V58M32 32L8 19" stroke="#ffffff" stroke-width="3"/>
                        </svg>
                        <!-- Wave Bawah Biru -->
                        <svg class="card-bottom-wave" viewBox="0 0 300 35" preserveAspectRatio="none">
                            <path d="M110,35 C160,12 220,28 300,5 L300,35 Z" fill="#1677ff" fill-opacity="0.85"/>
                            <path d="M150,35 C200,18 250,30 300,14 L300,35 Z" fill="#0ea5e9" fill-opacity="0.6"/>
                        </svg>
                    </div>
                </div>

                <!-- KARTU 2: KONDISI BAIK -->
                <div class="col-xl-3 col-md-6">
                    <div class="stat-card card-green clickable" onclick="bukaModalKondisi('B', 'Kondisi Baik')">
                        <div class="stat-icon-box"><i class="bi bi-check-circle-fill"></i></div>
                        <div class="stat-info">
                            <div class="stat-label">Kondisi Baik</div>
                            <h2 class="stat-number" id="stat-total-baik"><?= number_format($totalBaik) ?></h2>
                        </div>
                        <!-- Watermark Check Circle -->
                        <svg class="card-watermark" viewBox="0 0 64 64" fill="none">
                            <circle cx="32" cy="32" r="26" fill="#16a34a"/>
                            <path d="M20 33L28 41L44 24" stroke="#ffffff" stroke-width="5" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                        <!-- Wave Bawah Hijau -->
                        <svg class="card-bottom-wave" viewBox="0 0 300 35" preserveAspectRatio="none">
                            <path d="M110,35 C160,12 220,28 300,5 L300,35 Z" fill="#16a34a" fill-opacity="0.85"/>
                            <path d="M150,35 C200,18 250,30 300,14 L300,35 Z" fill="#22c55e" fill-opacity="0.6"/>
                        </svg>
                    </div>
                </div>

                <!-- KARTU 3: RUSAK RINGAN -->
                <div class="col-xl-3 col-md-6">
                    <div class="stat-card card-yellow clickable" onclick="bukaModalKondisi('RR', 'Rusak Ringan')">
                        <div class="stat-icon-box"><i class="bi bi-tools"></i></div>
                        <div class="stat-info">
                            <div class="stat-label">Rusak Ringan</div>
                            <h2 class="stat-number" id="stat-total-ringan"><?= number_format($totalRingan) ?></h2>
                        </div>
                        <!-- Watermark Gear -->
                        <svg class="card-watermark" viewBox="0 0 64 64" fill="none">
                            <path d="M32 20C25.37 20 20 25.37 20 32C20 38.63 25.37 44 32 44C38.63 44 44 38.63 44 32C44 25.37 38.63 20 32 20ZM56 35.5V28.5L49.4 26.8C48.8 24.8 48 22.9 46.9 21.2L50.4 15.3L45.5 10.4L39.6 13.9C37.9 12.8 36 12 34 11.4L32.3 4.8H25.3L23.6 11.4C21.6 12 19.7 12.8 18 13.9L12.1 10.4L7.2 15.3L10.7 21.2C9.6 22.9 8.8 24.8 8.2 26.8L1.6 28.5V35.5L8.2 37.2C8.8 39.2 9.6 41.1 10.7 42.8L7.2 48.7L12.1 53.6L18 50.1C19.7 51.2 21.6 52 23.6 52.6L25.3 59.2H32.3L34 52.6C36 52 37.9 51.2 39.6 50.1L45.5 53.6L50.4 48.7L46.9 42.8C48 41.1 48.8 39.2 49.4 37.2L56 35.5Z" fill="#f59e0b"/>
                        </svg>
                        <!-- Wave Bawah Kuning -->
                        <svg class="card-bottom-wave" viewBox="0 0 300 35" preserveAspectRatio="none">
                            <path d="M110,35 C160,12 220,28 300,5 L300,35 Z" fill="#f59e0b" fill-opacity="0.88"/>
                            <path d="M150,35 C200,18 250,30 300,14 L300,35 Z" fill="#fbbf24" fill-opacity="0.6"/>
                        </svg>
                    </div>
                </div>

                <!-- KARTU 4: RUSAK BERAT -->
                <div class="col-xl-3 col-md-6">
                    <div class="stat-card card-red clickable" onclick="bukaModalKondisi('RB', 'Rusak Berat')">
                        <div class="stat-icon-box"><i class="bi bi-exclamation-octagon-fill"></i></div>
                        <div class="stat-info">
                            <div class="stat-label">Rusak Berat</div>
                            <h2 class="stat-number" id="stat-total-berat"><?= number_format($totalBerat) ?></h2>
                        </div>
                        <!-- Watermark Warning Triangle -->
                        <svg class="card-watermark" viewBox="0 0 64 64" fill="none">
                            <path d="M32 8L58 54H6L32 8Z" fill="#dc2626"/>
                            <rect x="29.5" y="24" width="5" height="15" rx="2.5" fill="#ffffff"/>
                            <circle cx="32" cy="45" r="3" fill="#ffffff"/>
                        </svg>
                        <!-- Wave Bawah Merah -->
                        <svg class="card-bottom-wave" viewBox="0 0 300 35" preserveAspectRatio="none">
                            <path d="M110,35 C160,12 220,28 300,5 L300,35 Z" fill="#dc2626" fill-opacity="0.85"/>
                            <path d="M150,35 C200,18 250,30 300,14 L300,35 Z" fill="#f87171" fill-opacity="0.6"/>
                        </svg>
                    </div>
                </div>
            </div>

            <div class="row g-4 mb-4">
                <!-- KARTU 5: BARANG HILANG -->
                <div class="col-xl-3 col-md-6">
                    <div class="stat-card card-purple">
                        <div class="stat-icon-box"><i class="bi bi-search"></i></div>
                        <div class="stat-info">
                            <div class="stat-label">Barang Hilang</div>
                            <h2 class="stat-number" id="stat-total-hilang"><?= number_format($totalHilang) ?></h2>
                        </div>
                        <!-- Watermark Box Question -->
                        <svg class="card-watermark" viewBox="0 0 64 64" fill="none">
                            <path d="M32 6L56 19V45L32 58L8 45V19L32 6Z" fill="#7c3aed"/>
                            <path d="M32 32L56 19M32 32V58M32 32L8 19" stroke="#ffffff" stroke-width="2.5"/>
                            <text x="41" y="48" fill="#ffffff" font-size="18" font-weight="bold">?</text>
                        </svg>
                        <!-- Wave Bawah Ungu -->
                        <svg class="card-bottom-wave" viewBox="0 0 300 35" preserveAspectRatio="none">
                            <path d="M110,35 C160,12 220,28 300,5 L300,35 Z" fill="#7c3aed" fill-opacity="0.85"/>
                            <path d="M150,35 C200,18 250,30 300,14 L300,35 Z" fill="#a855f7" fill-opacity="0.6"/>
                        </svg>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="footer">© 2026 SI DIPTA</div>
</div>

<!-- ================= MODAL LOGOUT ================= -->
<div class="modal fade" id="logoutModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow" style="border-radius: 20px;">
            <div class="modal-body p-5 text-center">
                <div class="text-danger mb-4">
                    <i class="bi bi-exclamation-circle-fill" style="font-size: 60px;"></i>
                </div>
                <h4 class="fw-bold mb-2">Konfirmasi Keluar</h4>
                <p class="text-secondary mb-4">Apakah Anda yakin ingin mengakhiri sesi dan keluar dari sistem?</p>
                <div class="d-flex gap-3 justify-content-center">
                    <button type="button" class="btn btn-light px-4 py-2 fw-semibold" data-bs-dismiss="modal" style="border-radius: 12px;">Batal</button>
                    <a href="logout.php" class="btn btn-danger px-4 py-2 fw-semibold" style="border-radius: 12px;">Ya, Keluar</a>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ================= MODAL DETAIL KONDISI BARANG ================= -->
<div class="modal fade" id="detailKondisiModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable modal-dialog-centered">
        <div class="modal-content border-0 shadow" style="border-radius: 20px;">
            <div class="modal-header border-bottom-0 pb-0">
                <div>
                    <h5 class="modal-title fw-bold" id="detailKondisiTitle">Rincian Barang</h5>
                    <small class="text-secondary">Daftar barang beserta no register dan lokasi penyalurannya.</small>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div id="detailKondisiContent" class="table-responsive">
                    <div class="text-center py-5">
                        <div class="spinner-border text-primary" role="status"></div>
                        <p class="mt-2 text-secondary">Memuat data rincian...</p>
                    </div>
                </div>
            </div>
            <div class="modal-footer border-top-0 pt-0">
                <button type="button" class="btn btn-secondary px-4 fw-semibold" style="border-radius: 12px;" data-bs-dismiss="modal">Tutup</button>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<script>
    const mainContent = document.getElementById('ajax-container');
    const dashboardHtml = mainContent.innerHTML; 
    const loader = document.getElementById('loader');
    const loaderBar = loader.querySelector('div');
    const pageTitle = document.getElementById('page-title');

    // ================= GLOBAL FIX MODAL STACKING CONTEXT =================
    // Memindahkan otomatis setiap modal yang dibuka langsung ke <body> agar tidak terjebak z-index .content
    document.addEventListener('show.bs.modal', function (e) {
        const modalEl = e.target;
        modalEl.classList.add('ajax-portaled-modal');
        document.body.appendChild(modalEl);
    });

    function cleanupPortaledModals() {
        document.querySelectorAll('body > .modal.ajax-portaled-modal').forEach(el => {
            if (el.id !== 'logoutModal' && el.id !== 'detailKondisiModal') {
                el.remove();
            }
        });
        document.querySelectorAll('.modal-backdrop').forEach(el => el.remove());
        document.body.classList.remove('modal-open');
        document.body.style.removeProperty('overflow');
        document.body.style.removeProperty('padding-right');
    }

    const checkSidebarState = () => {
        const isCollapsed = localStorage.getItem('sidebarCollapsed') === 'true';
        if (isCollapsed && window.innerWidth > 992) {
            document.body.classList.add('collapsed');
        }
    };
    checkSidebarState();

    document.getElementById('sidebarToggle').addEventListener('click', () => {
        if (window.innerWidth > 992) {
            document.body.classList.toggle('collapsed');
            localStorage.setItem('sidebarCollapsed', document.body.classList.contains('collapsed'));
        } else {
            document.body.classList.toggle('mobile-show');
        }
    });

    // ================= FUNGSI REALTIME FETCH STATISTIK =================
    function updateRealtimeStats() {
        fetch('?ajax_stats=1')
            .then(res => res.json())
            .then(data => {
                const elTotal = document.getElementById('stat-total-barang');
                const elBaik = document.getElementById('stat-total-baik');
                const elRingan = document.getElementById('stat-total-ringan');
                const elBerat = document.getElementById('stat-total-berat');
                const elHilang = document.getElementById('stat-total-hilang');

                if(elTotal) elTotal.innerText = data.totalBarang;
                if(elBaik) elBaik.innerText = data.totalBaik;
                if(elRingan) elRingan.innerText = data.totalRingan;
                if(elBerat) elBerat.innerText = data.totalBerat;
                if(elHilang) elHilang.innerText = data.totalHilang;
            })
            .catch(err => console.log('Realtime sync paused'));
    }

    // Jalankan polling realtime tiap 3 detik
    setInterval(updateRealtimeStats, 3000);

    async function loadPage(pageUrl, title, pushState = true) {
        cleanupPortaledModals();

        let finalTitle = title;
        if (!finalTitle || finalTitle === 'undefined') {
            if (pageUrl.includes('data_pegawai')) finalTitle = "Data Pegawai";
            else if (pageUrl.includes('data_ruang')) finalTitle = "Data Ruang";
            else if (pageUrl.includes('data_kop')) finalTitle = "Struktur Manajemen";
            else if (pageUrl.includes('rekapan_ba')) finalTitle = "Rekapan BA"; 
            else if (pageUrl.includes('data_pengguna')) finalTitle = "Data Pengguna";
            else if (pageUrl.includes('pengembalian')) finalTitle = "Pengembalian Barang";
            else if (pageUrl.includes('pengaturan')) finalTitle = "Pengaturan";
            else finalTitle = "Halaman";
        }

        if (pageUrl === 'dashboard' || pageUrl === 'index.php') {
            mainContent.innerHTML = dashboardHtml;
            pageTitle.innerText = "Dashboard";
            if(pushState) history.pushState({page: 'dashboard', title: 'Dashboard'}, '', 'index.php');
            updateActiveMenu('dashboard');
            updateRealtimeStats();
            return;
        }

        loader.style.display = 'block';
        loaderBar.style.width = '50%';
        
        try {
            const response = await fetch(pageUrl);
            let text = await response.text();

            text = text.replace(/document\.addEventListener\s*\(\s*['"]DOMContentLoaded['"]\s*,/g, 'setTimeout(');

            mainContent.innerHTML = text;
            pageTitle.innerText = finalTitle;
            
            setTimeout(() => {
                const scripts = mainContent.querySelectorAll("script");
                scripts.forEach(s => {
                    // Cegah load ulang library Bootstrap JS biar menu tidak macet/crash
                    if (s.src && (s.src.includes('bootstrap') || s.src.includes('bundle'))) {
                        return; 
                    }
                    
                    const newScript = document.createElement("script");
                    if (s.src) {
                        newScript.src = s.src;
                    } else {
                        newScript.text = s.text;
                    }
                    document.body.appendChild(newScript).parentNode.removeChild(newScript);
                });

                document.dispatchEvent(new Event('DOMContentLoaded'));
            }, 50);

            if(pushState) history.pushState({page: pageUrl, title: finalTitle}, '', 'index.php?p=' + pageUrl);
            updateActiveMenu(pageUrl);
        } catch (e) {
            mainContent.innerHTML = '<div class="alert alert-danger">Gagal memuat halaman.</div>';
        } finally {
            loaderBar.style.width = '100%';
            setTimeout(() => { loader.style.display = 'none'; loaderBar.style.width = '0'; }, 300);
        }
    }

    function updateActiveMenu(page) {
        document.querySelectorAll('.menu-btn, .submenu-btn').forEach(el => el.classList.remove('active'));
        const activeEl = document.querySelector(`[data-page="${page}"]`);
        if (activeEl) {
            activeEl.classList.add('active');
            const parentCollapse = activeEl.closest('.collapse');
            if (parentCollapse) {
                const bsCollapse = bootstrap.Collapse.getOrCreateInstance(parentCollapse, { toggle: false });
                bsCollapse.show();
                const triggerBtn = document.querySelector(`[href="#${parentCollapse.id}"]`);
                if (triggerBtn) triggerBtn.classList.add('active');
            }
        }
    }

    document.addEventListener('click', e => {
        const link = e.target.closest('.ajax-link');
        if (link) {
            e.preventDefault();
            loadPage(link.getAttribute('data-page'), link.getAttribute('data-title'));
            if (window.innerWidth <= 992) {
                document.body.classList.remove('mobile-show');
            }
        }
    });

    window.addEventListener('popstate', e => {
        if (e.state) loadPage(e.state.page, e.state.title, false);
        else loadPage('dashboard', 'Dashboard', false);
    });

    window.addEventListener('DOMContentLoaded', () => {
        const urlParams = new URLSearchParams(window.location.search);
        const pageParam = urlParams.get('p');
        if (pageParam) {
            const link = document.querySelector(`[data-page="${pageParam}"]`);
            const title = link ? link.getAttribute('data-title') : null;
            loadPage(pageParam, title, false);
        } else {
            updateActiveMenu('dashboard');
        }
    });

    function updateTime(){
        const d = new Date();
        const element = document.getElementById('time');
        if(element) {
            element.innerHTML = d.toLocaleDateString('id-ID', {weekday:'long', day:'numeric', month:'long', year:'numeric'}) + " | " + d.getHours().toString().padStart(2, '0') + ":" + d.getMinutes().toString().padStart(2, '0');
        }
    }
    setInterval(updateTime, 1000); updateTime();

    // ================= FUNGSI JS UNTUK MODAL KONDISI =================
    function bukaModalKondisi(kondisi, judul) {
        document.getElementById('detailKondisiTitle').innerText = 'Rincian Barang - ' + judul;
        
        const myModal = new bootstrap.Modal(document.getElementById('detailKondisiModal'));
        myModal.show();
        
        const contentDiv = document.getElementById('detailKondisiContent');
        
        contentDiv.innerHTML = '<div class="text-center py-5"><div class="spinner-border text-primary" role="status"></div><p class="mt-2 text-secondary">Memuat data rincian...</p></div>';
        
        fetch('?ajax_kondisi=' + kondisi)
            .then(response => response.text())
            .then(data => {
                contentDiv.innerHTML = data;
            })
            .catch(error => {
                contentDiv.innerHTML = '<div class="alert alert-danger text-center">Gagal memuat koneksi data rincian.</div>';
            });
    }
</script>
</body>
</html>