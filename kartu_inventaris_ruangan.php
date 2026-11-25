<?php
session_start();
include "koneksi.php";

// 1. Cek Login
if(!isset($_SESSION['id_sekolah'])){
    die("Sesi berakhir, silakan login kembali.");
}

$id_sekolah = $_SESSION['id_sekolah'];
$current_file = $_SERVER['PHP_SELF'];

// --- LOGIKA AJAX ---
if (isset($_GET['ajax_ruangan'])) {
    $ruangan = mysqli_real_escape_string($conn, $_GET['ajax_ruangan']);
    
    $qp = mysqli_query($conn, "SELECT DISTINCT nama_penerima 
                               FROM penyaluran 
                               WHERE id_sekolah = '$id_sekolah' 
                               AND lokasi = '$ruangan' 
                               AND nama_penerima != '' 
                               ORDER BY nama_penerima ASC");
    
    $options = '<option value="">-- Pilih Penanggung Jawab (Opsional) --</option>';
    if(mysqli_num_rows($qp) > 0){
        while($p = mysqli_fetch_array($qp)){
            $nama = htmlspecialchars($p['nama_penerima']);
            $options .= "<option value='$nama'>$nama</option>";
        }
    } else {
        $options .= "<option value=''>Data PJ tidak ditemukan di database</option>";
    }
    
    ob_clean();
    echo $options;
    exit; 
}

// 2. Ambil Data Nama Pejabat & Info Sekolah
$qPejabat = mysqli_query($conn, "SELECT nama_kepala_sekolah, nama_wakasek_sarpras, nama_pengurus_barang FROM kop_surat WHERE id_sekolah = '$id_sekolah' LIMIT 1");
$pejabat  = mysqli_fetch_assoc($qPejabat);

$nama_ks   = $pejabat['nama_kepala_sekolah'] ?? "";
$nama_wks  = $pejabat['nama_wakasek_sarpras'] ?? "";
$nama_pb   = $pejabat['nama_pengurus_barang'] ?? "";
$tanggal_sekarang = date('Y-m-d');
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cetak KIR - SINVENTARIS</title>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">

    <style>
        :root {
            --navy-deep: #063168;
            --teal-mid: #0a6884;
            --cyan-bright: #12b1bd;
            --text-dark: #0f172a;
            --border-color: #cbd5e1;
        }

        body { 
            background: linear-gradient(180deg, #f2f8fd 0%, #e4f1fb 100%);
            font-family: 'Plus Jakarta Sans', sans-serif; 
            color: var(--text-dark);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 16px;
            margin: 0;
        }

        .card-custom {
            width: 100%;
            max-width: 860px;
            border: 1px solid #dbe7f4;
            border-radius: 20px; 
            box-shadow: 0 18px 40px -10px rgba(6, 49, 104, 0.14);
            background: #ffffff;
            overflow: hidden;
            margin: auto;
        }

        /* ================= HEADER GRADASI & WAVE AKSEN ================= */
        .header-section {
            position: relative;
            background: linear-gradient(95deg, #07336b 0%, #095880 55%, #0c899c 85%, #16b5be 100%);
            padding: 20px 28px;
            color: #ffffff;
            display: flex;
            align-items: center;
            overflow: hidden;
        }

        /* Wave kuning-emas kiri bawah */
        .header-section::before {
            content: '';
            position: absolute;
            left: -2%;
            bottom: -16px;
            width: 34%;
            height: 26px;
            background: linear-gradient(90deg, #facc15 0%, #38bdf8 100%);
            border-radius: 0 50% 0 0;
            transform: rotate(3deg);
            pointer-events: none;
        }

        /* Wave cyan-putih kanan bawah */
        .header-section::after {
            content: '';
            position: absolute;
            right: -6%;
            bottom: -24px;
            width: 48%;
            height: 58px;
            background: linear-gradient(135deg, rgba(45, 212, 191, 0.55) 0%, rgba(255, 255, 255, 0.25) 100%);
            border-radius: 50% 50% 0 0;
            transform: rotate(-5deg);
            pointer-events: none;
        }

        .header-icon-box {
            width: 48px;
            height: 52px;
            background: #ffffff;
            border-radius: 11px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #07336b;
            font-size: 1.55rem;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
            margin-right: 18px;
            flex-shrink: 0;
            position: relative;
            z-index: 2;
        }

        .header-title-wrap {
            position: relative;
            z-index: 2;
        }
        .header-section h4 {
            font-weight: 800;
            margin-bottom: 2px;
            font-size: 1.4rem;
            letter-spacing: -0.2px;
        }
        .header-section p {
            color: rgba(255, 255, 255, 0.92);
            font-size: 0.9rem;
            font-weight: 500;
            margin-bottom: 0;
        }

        .header-watermark-right {
            position: absolute;
            right: 45px;
            top: 50%;
            transform: translateY(-50%) rotate(8deg);
            font-size: 4.5rem;
            color: rgba(255, 255, 255, 0.18);
            pointer-events: none;
            z-index: 1;
        }

        .card-body-content {
            padding: 24px 28px 26px;
        }

        /* ================= LABEL & INPUT ATAS ================= */
        .field-label-top {
            font-weight: 800;
            color: #0f172a;
            font-size: 0.8rem;
            text-transform: uppercase;
            letter-spacing: 0.4px;
            margin-bottom: 7px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .field-label-top i {
            color: #0ea5e9;
            font-size: 1rem;
        }
        
        .input-group-custom {
            background: #ffffff;
            border: 1.5px solid #cbd5e1;
            border-radius: 11px;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            margin-bottom: 16px;
            box-shadow: 0 2px 6px rgba(15, 23, 42, 0.03);
        }

        .input-group-custom:focus-within {
            border-color: #0284c7;
            box-shadow: 0 0 0 3px rgba(2, 132, 199, 0.12);
        }

        .input-group-icon {
            padding: 10px 12px 10px 14px;
            color: #1d4ed8;
            background: transparent;
            border-right: none;
            font-size: 1.05rem;
        }
        
        .form-select-custom, .form-input-custom {
            border: none;
            background: transparent;
            padding: 10px 14px 10px 4px;
            flex: 1;
            outline: none;
            font-weight: 600;
            font-size: 0.9rem;
            color: #0f172a;
            font-family: inherit;
        }

        /* ================= DIVIDER PENANDA TANGAN ================= */
        .divider-label {
            display: flex;
            align-items: center;
            margin: 6px 0 14px;
            font-size: 0.82rem;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.4px;
            color: #0f172a;
            gap: 8px;
        }
        .divider-label i {
            color: #0284c7;
            font-size: 1.05rem;
        }

        .counter-badge {
            background: #0284c7;
            color: #ffffff;
            padding: 3px 11px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 800;
            margin-left: 2px;
        }

        /* ================= KONTAINER PEJABAT (DASHED) ================= */
        .pejabat-box {
            background: #f8fafc;
            border-radius: 14px;
            padding: 16px;
            border: 1.5px dashed #cbd5e1;
        }

        .pejabat-item {
            background: #ffffff;
            padding: 12px 14px;
            border-radius: 12px;
            border: 1.5px solid #e2e8f0;
            transition: all 0.2s ease;
            display: flex;
            align-items: center;
            justify-content: space-between;
            height: 100%;
            box-shadow: 0 2px 6px rgba(15, 23, 42, 0.02);
        }

        .pejabat-item.active { 
            border-color: #16a34a; 
            background: #f0fdf4;
        }

        .pejabat-avatar {
            width: 36px;
            height: 36px;
            border-radius: 9px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.1rem;
            flex-shrink: 0;
            margin-right: 11px;
        }
        .avatar-ks  { background: #e0f2fe; color: #0369a1; }
        .avatar-wks { background: #dcfce7; color: #15803d; }
        .avatar-pb  { background: #fef3c7; color: #b45309; }

        .pejabat-item .title {
            font-size: 0.65rem;
            text-transform: uppercase;
            font-weight: 700;
            color: #64748b;
            margin-bottom: 2px;
        }
        .pejabat-item .name {
            font-size: 0.84rem;
            font-weight: 800;
            color: #0f172a;
            margin-bottom: 0;
            line-height: 1.25;
        }

        /* Custom Toggle Switch Hijau Saat Aktif */
        .form-switch .form-check-input {
            width: 2.5em;
            height: 1.3em;
            cursor: pointer;
            margin-top: 0;
            background-color: #cbd5e1;
            border-color: #cbd5e1;
        }
        .form-switch .form-check-input:checked {
            background-color: #16a34a;
            border-color: #16a34a;
        }

        /* ================= BOX PJ RUANGAN (OPSIONAL) ================= */
        .pj-select-box {
            background: #ffffff;
            padding: 14px 16px;
            border-radius: 12px;
            border: 1.5px solid #e2e8f0;
            margin-top: 4px;
        }

        .pj-title-wrap {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 0.85rem;
            font-weight: 800;
            color: #1d4ed8;
        }
        .pj-title-wrap i {
            font-size: 1rem;
        }

        .pj-select-input-wrap {
            position: relative;
            display: flex;
            align-items: center;
            background: #e2e8f0;
            border: 1px solid #cbd5e1;
            border-radius: 9px;
            padding-left: 12px;
            transition: 0.2s;
        }
        .pj-select-input-wrap.enabled {
            background: #ffffff;
            border-color: #94a3b8;
        }
        .pj-select-input-wrap i {
            color: #1d4ed8;
            font-size: 1rem;
            margin-right: 6px;
        }
        .pj-select-input-wrap select {
            border: none !important;
            background: transparent !important;
            box-shadow: none !important;
            font-size: 0.85rem;
            font-weight: 600;
            color: #334155;
            padding: 8px 12px 8px 4px;
        }

        /* ================= ALERT VALIDASI KUNING LEMBUT ================= */
        #validation_msg {
            border-radius: 10px !important;
            border: 1px solid #fde68a !important;
            background: #fef9c3 !important;
            color: #854d0e !important;
            font-size: 0.85rem !important;
            font-weight: 600 !important;
            padding: 10px 14px !important;
            margin-top: 14px !important;
            display: flex;
            align-items: center;
        }
        #validation_msg i {
            color: #eab308;
            font-size: 1.05rem;
        }

        /* ================= TOMBOL GENERATE PDF ================= */
        .btn-generate {
            background: linear-gradient(95deg, #07336b 0%, #095b84 50%, #109fb0 100%);
            border: none;
            border-radius: 12px;
            padding: 13px 20px;
            color: #ffffff;
            font-weight: 800;
            font-size: 1rem;
            letter-spacing: 0.3px;
            transition: all 0.22s ease;
            margin-top: 14px;
            width: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            box-shadow: 0 8px 20px -5px rgba(7, 51, 107, 0.35);
        }

        .btn-generate:hover:not(:disabled) {
            transform: translateY(-2px);
            box-shadow: 0 12px 24px -5px rgba(7, 51, 107, 0.45);
            color: #ffffff;
        }

        .btn-generate:disabled {
            background: linear-gradient(95deg, #07336b 0%, #095b84 50%, #109fb0 100%);
            opacity: 0.88;
            color: #ffffff;
            cursor: not-allowed;
        }
    </style>
</head>

<body>

<div class="modal fade" id="limitModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-sm">
    <div class="modal-content" style="border-radius: 12px; border: none;">
      <div class="modal-body text-center p-4">
        <div class="mb-3">
            <i class="bi bi-exclamation-circle-fill text-danger" style="font-size: 3rem;"></i>
        </div>
        <h5 class="fw-bold">Batas Maksimum</h5>
        <p class="text-muted small">Anda hanya dapat memilih maksimal <b>3 penanda tangan</b> untuk laporan ini.</p>
        <button type="button" class="btn btn-secondary w-100 py-2 mt-2" data-bs-dismiss="modal" style="border-radius: 8px; font-weight: 600;">Mengerti</button>
      </div>
    </div>
  </div>
</div>

<div class="container">
    <div class="card-custom">
        <div class="header-section">
            <div class="header-icon-box">
                <i class="bi bi-file-earmark-bar-graph-fill"></i>
            </div>
            <div class="header-title-wrap">
                <h4>Cetak Laporan KIR</h4>
                <p>Kartu Inventaris Ruangan</p>
            </div>
            <div class="header-watermark-right d-none d-sm-block">
                <i class="bi bi-file-earmark-text-fill"></i>
            </div>
        </div>

        <div class="card-body-content">
            <form action="cetak_kir.php" method="GET" target="_blank" id="form_kir">
                
                <div class="row g-3">
                    <div class="col-md-7">
                        <label class="field-label-top"><i class="bi bi-geo-alt-fill"></i> Lokasi Ruangan</label>
                        <div class="input-group-custom">
                            <div class="input-group-icon"><i class="bi bi-geo-alt-fill"></i></div>
                            <select name="ruangan" id="select_ruangan" class="form-select-custom" required>
                                <option value="">-- Pilih Ruangan --</option>
                                <?php
                                $q = mysqli_query($conn, "SELECT DISTINCT lokasi FROM penyaluran WHERE id_sekolah = '$id_sekolah' AND lokasi != '' ORDER BY lokasi ASC");
                                while($d = mysqli_fetch_array($q)){
                                    echo "<option value='".htmlspecialchars($d['lokasi'])."'>".$d['lokasi']."</option>";
                                }
                                ?>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-5">
                        <label class="field-label-top"><i class="bi bi-calendar-check-fill"></i> Tanggal Cetak</label>
                        <div class="input-group-custom">
                            <div class="input-group-icon"><i class="bi bi-calendar-check-fill"></i></div>
                            <input type="date" name="tanggal_cetak" class="form-input-custom" value="<?= $tanggal_sekarang; ?>" required>
                        </div>
                    </div>
                </div>

                <div class="divider-label">
                    <i class="bi bi-people-fill"></i>
                    <span>Penanda Tangan</span>
                    <span class="counter-badge" id="count_display">0 / 3</span>
                </div>

                <div class="pejabat-box">
                    <div class="row g-2">
                        <div class="col-md-4">
                            <div class="pejabat-item">
                                <div class="d-flex align-items-center">
                                    <div class="pejabat-avatar avatar-ks">
                                        <i class="bi bi-person-fill"></i>
                                    </div>
                                    <div>
                                        <div class="title">Kepala Sekolah</div>
                                        <div class="name"><?= $nama_ks; ?></div>
                                    </div>
                                </div>
                                <div class="form-check form-switch ms-2">
                                    <input class="form-check-input check-ttd" type="checkbox" name="show_ks" id="show_ks" value="1">
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="pejabat-item">
                                <div class="d-flex align-items-center">
                                    <div class="pejabat-avatar avatar-wks">
                                        <i class="bi bi-person-fill"></i>
                                    </div>
                                    <div>
                                        <div class="title">Wakasek Sarpras</div>
                                        <div class="name"><?= $nama_wks; ?></div>
                                    </div>
                                </div>
                                <div class="form-check form-switch ms-2">
                                    <input class="form-check-input check-ttd" type="checkbox" name="show_wks" id="show_wks" value="1">
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="pejabat-item">
                                <div class="d-flex align-items-center">
                                    <div class="pejabat-avatar avatar-pb">
                                        <i class="bi bi-person-fill"></i>
                                    </div>
                                    <div>
                                        <div class="title">Pengurus Barang</div>
                                        <div class="name"><?= $nama_pb; ?></div>
                                    </div>
                                </div>
                                <div class="form-check form-switch ms-2">
                                    <input class="form-check-input check-ttd" type="checkbox" name="show_pb" id="show_pb" value="1">
                                </div>
                            </div>
                        </div>
                        <div class="col-md-12 mt-2">
                            <div class="pj-select-box">
                                <div class="d-flex align-items-center justify-content-between mb-2">
                                    <div class="pj-title-wrap"><i class="bi bi-check-square-fill"></i> PJ Ruangan (Opsional)</div>
                                    <div class="form-check form-switch">
                                        <input class="form-check-input check-ttd" type="checkbox" name="show_pj" id="show_pj" value="1">
                                    </div>
                                </div>
                                <div class="pj-select-input-wrap" id="pj_select_wrapper">
                                    <i class="bi bi-layers-fill"></i>
                                    <select name="penanggung_jawab" id="select_pj" class="form-select form-select-sm" disabled>
                                        <option value="">-- Pilih Ruangan Terlebih Dahulu --</option>
                                    </select>
                                </div>
                                <div id="pj_status" class="form-text text-danger mt-2" style="font-size: 0.78rem; font-weight: 600;">
                                    <i class="bi bi-info-circle-fill me-1"></i> Data akan dimuat otomatis.
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div id="validation_msg" class="alert alert-warning mt-2 d-none"></div>

                <button type="submit" id="btn_generate" class="btn btn-generate" disabled>
                    <i class="bi bi-file-earmark-pdf-fill me-1"></i> GENERATE PDF
                </button>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
const selectRuangan = document.getElementById('select_ruangan');
const selectPJ = document.getElementById('select_pj');
const pjSelectWrapper = document.getElementById('pj_select_wrapper');
const checkboxes = document.querySelectorAll('.check-ttd');
const btnGenerate = document.getElementById('btn_generate');
const validationMsg = document.getElementById('validation_msg');
const pjStatus = document.getElementById('pj_status');
const countDisplay = document.getElementById('count_display');
const limitModal = new bootstrap.Modal(document.getElementById('limitModal'));

// Validasi Utama (MINIMAL 2 TANDA TANGAN)
function validateForm() {
    const isRuanganSelected = selectRuangan.value !== "";
    const checkedCount = Array.from(checkboxes).filter(c => c.checked).length;
    
    countDisplay.innerText = `${checkedCount} / 3`;

    checkboxes.forEach(c => {
        if(c.checked) c.closest('.pejabat-item')?.classList.add('active');
        else c.closest('.pejabat-item')?.classList.remove('active');
    });

    // Syarat Generate: Ruangan dipilih & jumlah centang >= 2 dan <= 3
    if (isRuanganSelected && checkedCount >= 2 && checkedCount <= 3) {
        btnGenerate.disabled = false;
        validationMsg.classList.add('d-none');
    } else {
        btnGenerate.disabled = true;
        validationMsg.classList.remove('d-none');
        if (!isRuanganSelected) {
            validationMsg.innerHTML = '<i class="bi bi-lightbulb-fill me-2"></i> Silakan pilih lokasi ruangan terlebih dahulu.';
        } else if (checkedCount < 2) {
            validationMsg.innerHTML = '<i class="bi bi-check2-square me-2"></i> Pilih minimal 2 penanda tangan untuk laporan ini.';
        }
    }
}

// Event Listener Checkboxes (Batas Maksimal 3)
checkboxes.forEach(chk => {
    chk.addEventListener('change', function() {
        const checkedCount = Array.from(checkboxes).filter(c => c.checked).length;
        if (checkedCount > 3) {
            this.checked = false; 
            limitModal.show();
        }
        validateForm();
    });
});

// Event Listener Ruangan (Logic AJAX)
selectRuangan.addEventListener('change', function() {
    const lokasi = this.value;
    if (lokasi === "") {
        selectPJ.disabled = true;
        if (pjSelectWrapper) pjSelectWrapper.classList.remove('enabled');
        selectPJ.innerHTML = '<option value="">-- Pilih Lokasi Dahulu --</option>';
        pjStatus.innerHTML = '<i class="bi bi-exclamation-triangle me-1"></i> Pilih Ruangan untuk memuat data PJ.';
        pjStatus.className = 'form-text text-danger mt-2';
        validateForm();
        return;
    }

    selectPJ.innerHTML = '<option value="">Memuat data...</option>';
    fetch('<?= $current_file; ?>?ajax_ruangan=' + encodeURIComponent(lokasi))
        .then(response => response.text())
        .then(data => {
            selectPJ.innerHTML = data;
            selectPJ.disabled = false;
            if (pjSelectWrapper) pjSelectWrapper.classList.add('enabled');
            pjStatus.innerHTML = '<i class="bi bi-check-circle-fill me-1"></i> Data penanggung jawab tersedia.';
            pjStatus.className = 'form-text text-success mt-2';
            validateForm();
        })
        .catch(err => {
            pjStatus.innerHTML = 'Gagal memuat data PJ.';
        });
});

// Inisialisasi awal saat load
validateForm();
</script>

</body>
</html>