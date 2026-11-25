<?php
session_start();
include "koneksi.php";

// Ambil id_sekolah dari session login
$id_sekolah_login = $_SESSION['id_sekolah'] ?? '';

// Jika tidak ada session id_sekolah, hentikan script atau arahkan ke login
if(empty($id_sekolah_login)){
    die("Sesi berakhir, silakan login kembali.");
}

/* ================== AMBIL DATA PENDUKUNG (FILTERED) ================== */
$kop_q = mysqli_query($conn,"SELECT kode_wilayah, kode_sub_pengguna FROM kop_surat WHERE id_sekolah = '$id_sekolah_login' LIMIT 1");
$kop   = mysqli_fetch_assoc($kop_q);

$logo               = "logo.jpg"; 
$kode_wilayah       = $kop['kode_wilayah'] ?? "00";
$kode_sub_pengguna  = $kop['kode_sub_pengguna'] ?? "000001";

/* ================== DATA ================== */
// Diurutkan berdasarkan nama barang (uraian), kode barang, lalu nomor registrasi (reg) agar berurutan di UI
$q = mysqli_query($conn,"
    SELECT p.*, k.uraian 
    FROM data_barang p
    LEFT JOIN kode_barang k ON p.kode_barang = k.kode_barang
    WHERE p.id_sekolah = '$id_sekolah_login'
    ORDER BY k.uraian ASC, p.kode_barang ASC, CAST(p.reg AS UNSIGNED) ASC
");

// Ambil daftar tahun unik untuk dropdown filter
$list_thn = mysqli_query($conn, "SELECT DISTINCT tahun_perolehan FROM data_barang WHERE id_sekolah = '$id_sekolah_login' ORDER BY tahun_perolehan DESC");
?>

<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Cetak Label Aset - Realtime</title>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Inter:wght@400;600;700&display=swap" rel="stylesheet">

<style>
/* ================= UI CSS (TAMPILAN LUAR SESUAI GAMBAR) ================= */
body { 
    background: linear-gradient(180deg, #eff6fc 0%, #dcecfb 100%);
    font-family: 'Plus Jakarta Sans', 'Inter', sans-serif; 
    min-height: 100vh;
    color: #0f172a;
}

/* Header tetap mengambang di atas saat halaman di-scroll */
.no-print { 
    position: sticky;
    top: 0;
    z-index: 1030;
    padding: 16px 0 12px;
    background: linear-gradient(180deg, rgba(239, 246, 252, 0.96) 75%, rgba(239, 246, 252, 0) 100%);
    backdrop-filter: blur(6px);
}

.card-ui {
    background: #ffffff;
    border: 1px solid #dbe7f4;
    border-radius: 18px;
    box-shadow: 0 12px 30px -6px rgba(5, 57, 77, 0.14);
    overflow: hidden;
}

/* Header Banner Gradasi Teal + Wave */
.card-header-ui {
    position: relative;
    background: linear-gradient(95deg, #043247 0%, #075b75 50%, #0d92a6 84%, #18bcc8 100%);
    padding: 16px 26px;
    color: #ffffff;
    overflow: hidden;
}

.card-header-ui::before {
    content: '';
    position: absolute;
    right: 16%;
    bottom: -28px;
    width: 44%;
    height: 68px;
    background: rgba(255, 255, 255, 0.13);
    border-radius: 50% 50% 0 0;
    transform: rotate(-4deg);
    pointer-events: none;
}

.card-header-ui::after {
    content: '';
    position: absolute;
    right: -4%;
    top: -22px;
    width: 30%;
    height: 72px;
    background: rgba(45, 212, 191, 0.22);
    border-radius: 50%;
    pointer-events: none;
}

.header-logo-circle {
    width: 48px;
    height: 48px;
    background: #ffffff;
    border-radius: 50%;
    padding: 4px;
    display: flex;
    align-items: center;
    justify-content: center;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
    flex-shrink: 0;
    position: relative;
    z-index: 2;
}

.header-title-area {
    position: relative;
    z-index: 2;
}
.header-title-area h5 {
    font-size: 1.25rem;
    font-weight: 800;
    letter-spacing: -0.2px;
}
.header-title-area p {
    font-size: 0.84rem;
    color: rgba(255, 255, 255, 0.88);
}

/* Tombol Kembali di Kanan Atas Header */
.btn-back-header {
    position: relative;
    z-index: 2;
    background: #ffffff;
    border-radius: 12px;
    padding: 9px 18px;
    font-weight: 800;
    font-size: 0.88rem;
    color: #0f172a;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.12);
    transition: all 0.2s ease;
}
.btn-back-header:hover {
    background: #f0f9ff;
    color: #0369a1;
    transform: translateY(-1px);
    box-shadow: 0 6px 16px rgba(0, 0, 0, 0.16);
}
.btn-back-header i {
    color: #0284c7;
    font-size: 1rem;
}

/* Badge Label Terpilih di dekat tombol Cetak Label */
.label-counter-inline {
    background: #f0f9ff;
    border: 1.5px solid #bae6fd;
    border-radius: 10px;
    padding: 8px 16px;
    font-weight: 800;
    font-size: 0.88rem;
    color: #0f172a;
    display: inline-flex;
    align-items: center;
    gap: 8px;
}
.label-counter-inline i {
    color: #0284c7;
    font-size: 0.98rem;
}

/* Filter & Action Area */
.filter-box-new {
    padding: 18px 26px 20px;
    background: #ffffff;
}

.filter-label-icon {
    display: flex;
    align-items: center;
    gap: 7px;
    font-size: 0.82rem;
    font-weight: 700;
    color: #0f172a;
    margin-bottom: 7px;
}
.filter-label-icon i {
    color: #0284c7;
    font-size: 1rem;
}

.search-input-wrap {
    position: relative;
}
.search-input-wrap i {
    position: absolute;
    left: 14px;
    top: 50%;
    transform: translateY(-50%);
    color: #475569;
    font-size: 0.95rem;
    pointer-events: none;
}
.search-input-wrap .form-control {
    padding-left: 38px !important;
}

.form-control, .form-select {
    border-radius: 10px;
    padding: 9px 14px;
    border: 1.5px solid #cbd5e1;
    font-size: 0.88rem;
    font-weight: 500;
    color: #0f172a;
    background-color: #ffffff;
}
.form-control:focus, .form-select:focus {
    border-color: #0284c7;
    box-shadow: 0 0 0 3px rgba(2, 132, 199, 0.12);
}

.btn-refresh-ui {
    background: #f8fafc;
    color: #0f172a;
    border: 1.5px solid #cbd5e1;
    border-radius: 10px;
    padding: 9px 16px;
    font-weight: 700;
    font-size: 0.88rem;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 7px;
    transition: all 0.2s;
}
.btn-refresh-ui:hover {
    background: #f1f5f9;
    border-color: #94a3b8;
    color: #0f172a;
}
.btn-refresh-ui i {
    color: #0284c7;
    font-size: 1rem;
}

.btn-print-action {
    background: linear-gradient(135deg, #16a34a 0%, #15803d 100%);
    border: none;
    color: #ffffff;
    padding: 9px 22px;
    border-radius: 10px;
    font-weight: 700;
    font-size: 0.88rem;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    box-shadow: 0 4px 12px rgba(22, 163, 74, 0.28);
    transition: all 0.2s;
}
.btn-print-action:hover {
    background: linear-gradient(135deg, #15803d 0%, #166534 100%);
    color: #ffffff;
    transform: translateY(-1px);
    box-shadow: 0 6px 16px rgba(22, 163, 74, 0.35);
}

#checkAll.form-check-input {
    width: 2.6em;
    height: 1.35em;
    cursor: pointer;
}
#checkAll.form-check-input:checked {
    background-color: #0284c7;
    border-color: #0284c7;
}

/* Helper Class untuk Filter */
.d-none-filter {
    display: none !important;
}

/* Menghilangkan garis pembatas saat pencarian aktif agar tidak berantakan */
.searching-active .label-row {
    border-bottom: none !important;
}

/* ================= TAMPILAN KARTU LABEL DI LAYAR (SCREEN ONLY) ================= */
@media screen {
    #labelContainer {
        padding-bottom: 40px;
    }

    .label-row {
        display: flex;
        flex-wrap: wrap;
        gap: 16px;
        border-bottom: none !important;
        margin-top: 0 !important;
        margin-bottom: 14px !important;
    }

    .label-item {
        width: calc(50% - 8px) !important;
        background: #ffffff;
        border: 1px solid #d5e3f0 !important;
        border-radius: 14px;
        padding: 12px 14px 12px 46px !important;
        box-shadow: 0 4px 14px rgba(15, 23, 42, 0.04);
        transition: all 0.2s ease;
    }

    .label-item:hover {
        border-color: #38bdf8 !important;
        box-shadow: 0 8px 20px rgba(2, 132, 199, 0.1);
    }

    .label-item.selected {
        border-color: #0284c7 !important;
        background: #f0f9ff;
    }

    /* Checkbox di sisi kiri kartu */
    .checkbox-area {
        position: static !important;
        display: block !important;
        margin-bottom: 6px;
    }

    .checkbox-area .pilih {
        position: absolute;
        left: 14px;
        top: 14px;
        width: 19px !important;
        height: 19px !important;
        border: 2px solid #94a3b8 !important;
        border-radius: 5px;
    }
    .checkbox-area .pilih:checked {
        background-color: #0284c7 !important;
        border-color: #0284c7 !important;
    }

    /* Ribbon Merk biru tua-cyan miring */
    .merk-info {
        position: relative;
        display: inline-flex !important;
        align-items: center;
        background: linear-gradient(90deg, #073b6c 0%, #0b6491 100%) !important;
        color: #ffffff !important;
        font-size: 11px !important;
        font-weight: 700 !important;
        padding: 3px 16px 3px 10px !important;
        border-radius: 5px 0 0 5px !important;
        margin-bottom: 0 !important;
        clip-path: polygon(0 0, calc(100% - 10px) 0, 100% 100%, 0 100%);
    }
    .merk-info::after {
        content: '';
        position: absolute;
        right: 0;
        top: 0;
        bottom: 0;
        width: 14px;
        background: #14b8a6;
        clip-path: polygon(4px 0, 100% 0, 100% 100%, 0 100%);
    }

    .label-box {
        border: 1.5px solid #94a3b8 !important;
        border-radius: 8px;
        height: 82px;
        background: #ffffff;
    }
    .logo {
        border-right: 1px solid #cbd5e1 !important;
    }
    .qr {
        border-left: 1px solid #cbd5e1 !important;
    }
    .garis {
        border-top: 1.5px solid #64748b !important;
    }

    @media (max-width: 768px) {
        .label-item {
            width: 100% !important;
        }
    }
}

/* ================= CSS LABEL ASLI (TIDAK BERUBAH UNTUK PRINT) ================= */
*{ box-sizing:border-box; }

@page{
    size:21.5cm 33cm portrait;
    margin:0.7cm;
}

.label-row{
    display:flex;
    border-bottom:1px dashed #000; 
    margin-bottom:0;                
    padding-bottom:0;               
    margin-top: 35px;
}

.label-item{
    width:50%;
    padding:4px;                    
    border:0;                        
}

.label-item:first-child{
    border-right:1px dashed #000;   
}

.label-box{
    border:2px solid #000;
    display:flex;
    overflow:hidden;
    height:80px;
    background: #fff;
}

.logo, .qr{
    width:68px;
    height:76px;
    display:flex;
    align-items:center;
    justify-content:center;
    flex-shrink:0;
}

.logo{ border-right:2px solid #000; }
.qr{ border-left:2px solid #000; }
.logo img, .qr img{ width:90%; height:90%; object-fit:contain; }

.content{
    flex:1;
    padding:5px;
    display:flex;
    flex-direction:column;
    justify-content:center;
    align-items:center;
    text-align:center;
}

.kode1, .kode2{ font-weight:normal; font-size:12px; margin:0; }
.garis{ border-top:2px solid #000; width:105%; margin:3px 0; }
.uraian{
    font-size: 10px;
    font-weight: normal; 
    white-space: normal;
    word-break: break-word;
    line-height: 1.15;
    width: 100%; 
    margin: auto 0;
    padding-top: 3px;
}

.checkbox-area{ 
    position: absolute; 
    top: -42px; 
    left: 0px; 
    z-index: 10; 
    display: flex;
    flex-direction: column;
    align-items: flex-start;
}

.merk-info {
    font-size: 11px;
    font-weight: 600;
    color: #000000;
    background: rgba(255, 255, 255, 0.9);
    padding: 0px 4px;
    border-radius: 4px;
    margin-bottom: 2px;
    white-space: nowrap;
}

@media print{
    .no-print{ display:none !important; }
    body{ background:white !important; }
    .container-fluid, .container{ max-width:100% !important; padding:0 !important; margin:0 !important; }
    .label-item{ display:none; }
    .label-item.selected{ display:block; }
    .label-row:not(:has(.selected)) { display: none; }
    .label-row{ page-break-inside:avoid; border-bottom:1px dashed #000 !important; margin-top:0 !important; }
}
</style>

<script>
// Fungsi Cari Realtime (Merapatkan susunan agar kolom dikanan tidak kosong)
function filterData() {
    let cari = document.getElementById('inputCari').value.toLowerCase();
    let tahun = document.getElementById('filterTahun').value;
    let items = Array.from(document.querySelectorAll('.label-item'));
    let container = document.getElementById('labelContainer');

    if (cari !== "" || tahun !== "") {
        container.classList.add('searching-active');
    } else {
        container.classList.remove('searching-active');
    }

    let visibleItems = [];

    // Deteksi mana saja item yang lolos filter (uraian / merk)
    items.forEach(item => {
        let textUraian = item.getAttribute('data-uraian').toLowerCase();
        let textMerk = (item.getAttribute('data-merk') || '').toLowerCase();
        let dataTahun = item.getAttribute('data-tahun');
        
        let matchCari = textUraian.includes(cari) || textMerk.includes(cari);
        let matchTahun = tahun === "" || dataTahun === tahun;

        if (matchCari && matchTahun) {
            visibleItems.push(item);
        } else {
            item.classList.add('d-none-filter');
            let cb = item.querySelector('.pilih');
            if(cb) cb.checked = false;
        }
    });

    // Menata ulang layout (Grouping) agar selalu berpasangan dan rapat (tidak bolong)
    let rows = Array.from(container.querySelectorAll('.label-row'));
    let rowIndex = 0;

    for (let i = 0; i < visibleItems.length; i += 2) {
        let row = rows[rowIndex];
        if(!row) break; 
        
        row.classList.remove('d-none-filter');
        row.style.display = 'flex';
        
        row.appendChild(visibleItems[i]);
        visibleItems[i].classList.remove('d-none-filter');
        
        if (visibleItems[i+1]) {
            row.appendChild(visibleItems[i+1]);
            visibleItems[i+1].classList.remove('d-none-filter');
        }
        rowIndex++;
    }

    // Sembunyikan sisa wadah baris yang kosong
    for (let i = rowIndex; i < rows.length; i++) {
        rows[i].classList.add('d-none-filter');
        rows[i].style.display = 'none';
    }
    
    updateSelected();
    
    let alertEmpty = document.getElementById('emptyAlert');
    if(visibleItems.length === 0) {
        alertEmpty.classList.remove('d-none');
    } else {
        alertEmpty.classList.add('d-none');
    }
}

function toggleAll(source){
    document.querySelectorAll('.label-item:not(.d-none-filter) .pilih').forEach(cb=>{ 
        cb.checked = source.checked; 
    });
    updateSelected();
}

function updateSelected(){
    let count = 0;
    document.querySelectorAll('.label-item').forEach(item=>{
        let cb = item.querySelector('.pilih');
        if(cb && cb.checked){ 
            item.classList.add('selected'); 
            count++;
        } else { 
            item.classList.remove('selected'); 
        }
    });
    document.getElementById('countDisplay').innerText = count;
}

function cetakData(){
    updateSelected();
    let selectedItems = Array.from(document.querySelectorAll('.label-item.selected'));
    
    if(selectedItems.length === 0){
        alert('Silakan pilih minimal satu barang untuk dicetak!');
        return;
    }
    
    // Rapikan layout cetak khusus agar yang terpilih berurutan tanpa meninggalkan bolong di kertas
    let container = document.getElementById('labelContainer');
    let rows = Array.from(container.querySelectorAll('.label-row'));
    let rowIndex = 0;
    
    for (let i = 0; i < selectedItems.length; i += 2) {
        let row = rows[rowIndex];
        if(!row) break;
        row.appendChild(selectedItems[i]);
        if (selectedItems[i+1]) {
            row.appendChild(selectedItems[i+1]);
        }
        rowIndex++;
    }
    
    window.print();
    
    // Kembalikan ke layout normal sesuai status filter pencarian setelah print dialog ditutup
    filterData(); 
}
</script>

</head>
<body>

<div class="container-fluid no-print">
    <div class="container">
        <div class="card-ui">
            <div class="card-header-ui d-flex flex-wrap justify-content-between align-items-center gap-3">
                <div class="d-flex align-items-center gap-3">
                    <a href="index.php" class="header-logo-circle">
                        <img src="<?= $logo ?>" width="38" height="38" style="object-fit: contain;">
                    </a>
                    <div class="header-title-area">
                        <h5 class="m-0">Panel Cetak Label Aset</h5>
                        <p class="m-0">Fitur untuk mencetak label barang secara realtime</p>
                    </div>
                </div>
                <a href="index.php" class="btn-back-header">
                    <i class="bi bi-arrow-return-left"></i>
                    <span>Kembali</span>
                </a>
            </div>

            <div class="filter-box-new">
                <div class="row g-3 align-items-end">
                    <div class="col-md-6">
                        <label class="filter-label-icon">
                            <i class="bi bi-upc-scan"></i>
                            <span>Cari Nama Barang / Merk</span>
                        </label>
                        <div class="search-input-wrap">
                            <i class="bi bi-search"></i>
                            <input type="text" id="inputCari" class="form-control" 
                                   placeholder="Ketik uraian atau merk barang..." oninput="filterData()">
                        </div>
                    </div>

                    <div class="col-md-4">
                        <label class="filter-label-icon">
                            <i class="bi bi-calendar-range"></i>
                            <span>Filter Tahun</span>
                        </label>
                        <select id="filterTahun" class="form-select" onchange="filterData()">
                            <option value="">Semua Tahun</option>
                            <?php while($t = mysqli_fetch_assoc($list_thn)): ?>
                                <option value="<?= $t['tahun_perolehan'] ?>"><?= $t['tahun_perolehan'] ?></option>
                            <?php endwhile; ?>
                        </select>
                    </div>

                    <div class="col-md-2">
                        <button type="button" onclick="location.reload()" class="btn-refresh-ui w-100">
                            <i class="bi bi-arrow-clockwise"></i>
                            <span>Refresh</span>
                        </button>
                    </div>
                </div>

                <hr class="my-3" style="border-color: #e2e8f0;">

                <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">
                    <div class="form-check form-switch d-flex align-items-center gap-2 mb-0">
                        <input class="form-check-input mt-0" type="checkbox" id="checkAll" onclick="toggleAll(this)">
                        <label class="form-check-label fw-bold text-dark" style="font-size: 0.9rem;" for="checkAll">Pilih Semua yang Tampil</label>
                    </div>
                    
                    <div class="d-flex flex-wrap align-items-center gap-2">
                        <div class="label-counter-inline">
                            <i class="bi bi-tag-fill"></i>
                            <span><span id="countDisplay">0</span> Label Terpilih</span>
                        </div>
                        <button type="button" onclick="cetakData()" class="btn-print-action">
                            <i class="bi bi-printer-fill"></i>
                            <span>Cetak Label Terpilih</span>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="container mt-2" id="labelContainer">
    <div id="emptyAlert" class="alert alert-info text-center py-5 d-none" style="border-radius:15px;">
        <h5 class="m-0">Data tidak ditemukan dengan kriteria tersebut.</h5>
    </div>

<?php
$printed_counter = 0; 

while($d = mysqli_fetch_assoc($q)){
    $volume = (int)($d['volume'] ?? 1);
    if($volume < 1) $volume = 1;
    $reg_awal = (int)($d['reg'] ?? 1);

    for($i = 0; $i < $volume; $i++){
            $tahun  = $d['tahun_perolehan'];
            $no_reg_sekarang = $reg_awal + $i;
            $no_reg_format = str_pad($no_reg_sekarang, 4, "0", STR_PAD_LEFT);
            $kode_sub_pengguna_fixed = str_pad($kode_sub_pengguna, 6, "0", STR_PAD_LEFT);

            $kode_atas = "11.01.32.".$kode_wilayah.".000001.".$kode_sub_pengguna_fixed.".".$tahun;
            $kode_tengah = $d['kode_barang'].".".$no_reg_format;
            $uraian = $d['uraian'] ?? '';
            $merk   = $d['merek_type'] ?? '';

            if($printed_counter % 2 == 0){ echo '<div class="label-row">'; }
            ?>

            <div class="label-item position-relative" 
                 data-uraian="<?= htmlspecialchars($uraian) ?>" 
                 data-merk="<?= htmlspecialchars($merk) ?>"
                 data-tahun="<?= $tahun ?>">
                
                <div class="checkbox-area no-print">
                    <div class="merk-info">
                        Merk: <?= !empty($merk) ? htmlspecialchars($merk) : '-' ?>
                    </div>
                    <input type="checkbox" class="form-check-input pilih" style="margin: 0; width: 18px; height: 18px; border: 2px solid #4e73df; cursor: pointer;" onchange="updateSelected()">
                </div>

                <div class="label-box">
                    <div class="logo"><img src="<?= $logo ?>"></div>
                    <div class="content">
                        <div class="kode1"><?= $kode_atas ?></div>
                        <div class="garis"></div>
                        <div class="kode2"><?= $kode_tengah ?></div>
                        <div class="garis"></div>
                        <div class="uraian"><?= $uraian ?></div>
                    </div>
                    <div class="qr">
                        <?php $id_aset = !empty($d['id']) ? $d['id'] : ($d['id_barang'] ?? ''); ?>
                        <img src="generate_qr.php?id=<?= $id_aset ?>&amp;reg=<?= $no_reg_format ?>">
                    </div>
                </div>
            </div>

            <?php
            $printed_counter++;
            if($printed_counter % 2 == 0){ echo '</div>'; }
    } 
}

if($printed_counter % 2 != 0){ echo '</div>'; }
?>
</div>

</body>
</html>