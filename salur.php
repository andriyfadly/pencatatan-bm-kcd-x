<?php
include "koneksi.php";

/* 1. AMBIL ID DAN REGISTER DARI URL */
$id_url  = $_GET['id_penyaluran'] ?? $_GET['id'] ?? $_GET['id_barang'] ?? 0;
$reg_url = $_GET['reg'] ?? ''; 

/* ================== PROSES PENCARIAN DATA BARANG ================== */
$q = mysqli_query($conn, "SELECT * FROM data_barang WHERE id='$id_url' LIMIT 1");
$d = mysqli_fetch_assoc($q);

if (!$d) {
    die("<center><h2 style='margin-top:50px;'>⚠️ Data Tidak Ditemukan</h2></center>");
}

/* ================== LOGIKA REGISTER 3 DIGIT (001) ================== */
$raw_reg = !empty($reg_url) ? $reg_url : ($d['reg'] ?? '1');
$register_final = str_pad((int)$raw_reg, 3, "0", STR_PAD_LEFT);

/* ================== DATA PENDUKUNG (KOP PER ID_SEKOLAH) ================== */
$id_sekolah_barang = $d['id_sekolah'] ?? 0;
$qKop = mysqli_query($conn, "SELECT * FROM kop_surat WHERE id_sekolah='$id_sekolah_barang' LIMIT 1");
$kop  = mysqli_fetch_assoc($qKop);

if (!$kop) {
    $kop = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM kop_surat LIMIT 1"));
}

$kode_brg = $d['kode_barang'] ?? '';
$qKode = mysqli_query($conn, "SELECT * FROM kode_barang WHERE kode_barang='$kode_brg'");
$kode  = mysqli_fetch_assoc($qKode);

$fotoBarang = $d['foto'] ?? '';
if (empty($fotoBarang)) {
    $nama_esc = mysqli_real_escape_string($conn, $d['nama_jenis_barang'] ?? '');
    $qF = mysqli_query($conn, "SELECT foto FROM data_barang WHERE nama_jenis_barang='$nama_esc' AND foto!='' LIMIT 1");
    $fD = mysqli_fetch_assoc($qF);
    $fotoBarang = $fD['foto'] ?? '';
}
?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Kartu Barang - <?= $register_final; ?></title>
    <style>
        body { font-family: Arial, sans-serif; background: #f0f0f0; padding: 20px; margin: 0; }
        .kartu { width: 850px; margin: auto; border: 2px solid #000; background: #fff; box-shadow: 0 0 10px rgba(0,0,0,0.1); }
        
        /* Style KOP Surat */
        .header-kop { border-bottom: 3px double #000; padding: 15px; }
        
        /* Area Kode & Rekening dengan Logo Sekolah di Kiri */
        .kode-box { 
            border-top: 2px solid #000; 
            border-bottom: 2px solid #000; 
            background: #f9f9f9;
            display: table;
            width: 100%;
        }
        .logo-sekolah-kecil {
            display: table-cell;
            width: 120px;
            vertical-align: middle;
            text-align: center;
            border-right: 1px solid #ccc;
            padding: 10px;
        }
        .info-kode {
            display: table-cell;
            vertical-align: middle;
            padding: 15px;
        }
        .baris { display: flex; justify-content: space-between; font-weight: bold; font-size: 18px; }
        
        .detail { padding: 20px; font-size: 16px; }
        .detail table { width: 100%; border-collapse: collapse; }
        .detail td { padding: 10px; border-bottom: 1px solid #eee; vertical-align: top; }
        .label { width: 220px; font-weight: bold; color: #555; }
        .value { font-weight: bold; font-size: 20px; color: #000; }
        
        .foto { text-align: center; padding: 20px; border-top: 2px solid #000; background: #fafafa; }
        .foto img { max-height: 350px; border: 1px solid #ccc; padding: 5px; background: #fff; }
    </style>
</head>
<body>

<div class="kartu">
    <div class="header-kop">
        <table width="100%" cellpadding="0" cellspacing="0" border="0">
            <tr>
                <td width="20%" align="center" valign="middle">
                    <?php 
                    $logoKiri = 'upload_kop/'.$kop['logo'];
                    if(!empty($kop['logo']) && file_exists($logoKiri)){
                        echo '<img src="'.$logoKiri.'" height="110" style="max-width: 140px; object-fit: contain;">';
                    }
                    ?>
                </td>
                <td width="80%" align="center" valign="middle">
                    <div style="line-height: 1.25; padding-right: 50px;">
                        <span style="font-size: 14pt; display: block;"><?= strtoupper($kop['nama_provinsi']); ?></span>
                        <span style="font-size: 14pt; display: block;"><?= strtoupper($kop['nama_dinas']); ?></span>
                        <b style="font-size: 17pt; display: block; margin-top: 2px;"><?= strtoupper($kop['nama_sekolah']); ?></b>
                        <div style="margin-top: 5px;">
                            <span style="font-size: 10pt; display: block;"><?= $kop['alamat']; ?> | Email: <?= $kop['email']; ?></span>
                            <b style="font-size: 10pt; display: block;"><?= strtoupper($kop['kota']); ?> - <?= $kop['kode_pos']; ?></b>
                        </div>
                    </div>
                </td>
            </tr>
        </table>
    </div>

    <div class="kode-box">
        <div class="logo-sekolah-kecil">
            <?php 
            $logoKanan = 'upload_kop/'.$kop['logo_sekolah'];
            if(!empty($kop['logo_sekolah']) && file_exists($logoKanan)){
                echo '<img src="'.$logoKanan.'" height="80" style="max-width: 100px; object-fit: contain;">';
            } else {
                echo '<small style="color:#ccc">Logo Sekolah</small>';
            }
            ?>
        </div>
        <div class="info-kode">
            <div class="baris">
                <div>KODE BARANG : <?= $d['kode_barang']; ?></div>
                <div style="margin-right: 20px;">REGISTER : <?= $register_final; ?></div>
            </div>
            <div style="font-size: 18px; margin-top: 8px; font-weight: bold; color: #000;">
                REKENING ASET : <?= $kode['jenis_aset'] ?? '-'; ?>
            </div>
        </div>
    </div>

    <div class="detail">
        <table>
            <tr>
                <td class="label">Nama Barang</td>
                <td class="value">: <?= $d['nama_jenis_barang']; ?></td>
            </tr>
            <tr>
                <td class="label">Type / Merk</td>
                <td class="value">: <?= $d['merek_type'] ?? $d['merk'] ?? '-'; ?></td>
            </tr>
            <tr>
                <td class="label">Bulan Perolehan</td>
                <td class="value">: <?= $d['bulan_perolehan']; ?></td>
            </tr>
            <tr>
                <td class="label">Tahun Perolehan</td>
                <td class="value">: <?= $d['tahun_perolehan']; ?></td>
            </tr>
            <tr>
                <td class="label">Umur Ekonomis</td>
                <td class="value">: <?= $kode['umur_ekonomis'] ?? '-'; ?> Tahun</td>
            </tr>
        </table>
    </div>

    <div class="foto">
        <?php if(!empty($fotoBarang) && file_exists("upload/".$fotoBarang)): ?>
            <img src="upload/<?= $fotoBarang; ?>">
        <?php else: ?>
            <p style="color:#999; padding: 20px; border: 1px dashed #ccc;"><i>Foto tidak tersedia</i></p>
        <?php endif; ?>
    </div>
</div>

</body>
</html>