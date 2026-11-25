<?php
session_start();

if(!isset($_SESSION['id_user'])){
    header("Location: login.php");
    exit;
}
include "koneksi.php";
$id_sekolah = $_SESSION['id_sekolah']; // Ambil ID dari session login

// Ambil kode klasifikasi surat BAST dari tabel pengaturan_surat
$kode_klasifikasi_bast = '';
$qPengaturanSurat = mysqli_query($conn, "SELECT kode_bast FROM pengaturan_surat WHERE id_sekolah = '$id_sekolah' LIMIT 1");
if($qPengaturanSurat && mysqli_num_rows($qPengaturanSurat) > 0){
    $dPengaturanSurat = mysqli_fetch_assoc($qPengaturanSurat);
    $kode_klasifikasi_bast = trim($dPengaturanSurat['kode_bast'] ?? '');
}

// Ambil lokasi untuk dropdown (Hanya milik sekolah yang login)
$qRuang = mysqli_query($conn, "SELECT * FROM ruang WHERE id_sekolah = '$id_sekolah' ORDER BY nama_ruang ASC");
$ruang = [];
while($r = mysqli_fetch_assoc($qRuang)){
    $ruang[] = $r;
}

// Ambil pegawai untuk dropdown (Hanya milik sekolah yang login)
$qPeg = mysqli_query($conn, "SELECT * FROM pegawai WHERE id_sekolah = '$id_sekolah' ORDER BY nama_lengkap ASC");
$pegawai = [];
while($p = mysqli_fetch_assoc($qPeg)){
    $pegawai[] = $p;
}

// Ambil asal perolehan + tahun untuk filter terhubung
$qAsal = mysqli_query($conn, "SELECT DISTINCT TRIM(tahun_perolehan) AS thn, asal_perolehan FROM data_barang WHERE id_sekolah = '$id_sekolah' AND asal_perolehan IS NOT NULL AND asal_perolehan != '' ORDER BY asal_perolehan ASC");
$asal_by_tahun = [];
while($a = mysqli_fetch_assoc($qAsal)){
    $asal_by_tahun[$a['asal_perolehan']][] = $a['thn'];
}

// ambil semua kombinasi nama_barang + no_reg yang sudah ada di penyaluran
$existingRegs = [];
$penyaluranGrouped = [];

$qExist = mysqli_query($conn, "SELECT id_penyaluran, id_barang, nama_barang, kode_barang, thn_pengadaan, no_reg, nama_penerima, lokasi, keterangan FROM penyaluran WHERE id_sekolah = '$id_sekolah'");
while($er = mysqli_fetch_assoc($qExist)){
    $keyFull = $er['nama_barang'].'||'.$er['kode_barang'].'||'.$er['thn_pengadaan'].'||'.$er['no_reg'];
    $existingRegs[] = $keyFull;

    $parentKey = $er['nama_barang'].'||'.$er['kode_barang'].'||'.$er['thn_pengadaan'];
    $penyaluranGrouped[$parentKey][] = $er;
}

// Helper SQL kondisi pencocokan no_reg numerik (tunggal maupun range)
function buildRegMatchClause($colName = 'no_reg') {
    return "(
        TRIM($colName) = TRIM(?)
        OR (
            INSTR($colName, '-') > 0
            AND CAST(SUBSTRING_INDEX(?, '-', 1) AS UNSIGNED) >= CAST(SUBSTRING_INDEX($colName, '-', 1) AS UNSIGNED)
            AND CAST(SUBSTRING_INDEX(?, '-', -1) AS UNSIGNED) <= CAST(SUBSTRING_INDEX($colName, '-', -1) AS UNSIGNED)
        )
        OR (
            INSTR(?, '-') > 0
            AND CAST(SUBSTRING_INDEX($colName, '-', 1) AS UNSIGNED) >= CAST(SUBSTRING_INDEX(?, '-', 1) AS UNSIGNED)
            AND CAST(SUBSTRING_INDEX($colName, '-', -1) AS UNSIGNED) <= CAST(SUBSTRING_INDEX(?, '-', -1) AS UNSIGNED)
        )
    )";
}

// ===== SIMPAN HISTORY BAST =====
if(isset($_POST['action']) && $_POST['action'] == 'simpan_history_bast'){
    $nama_barang = $_POST['nama_barang'] ?? '';
    $no_reg      = $_POST['no_reg'] ?? '';
    $nomor       = $_POST['nomor'] ?? '';
    $tanggal     = $_POST['tanggal'] ?? '';
    $kode_barang = $_POST['kode_barang'] ?? '';
    $thn_peng    = $_POST['thn_pengadaan'] ?? '';

    if($nama_barang == '' || $no_reg == '' || $nomor == '' || $tanggal == ''){
        echo "invalid";
        exit;
    }

    // 1. AMBIL ID_BARANG & NAMA PENERIMA DARI TABEL PENYALURAN SECARA PRESISI
    $regClause = buildRegMatchClause('no_reg');
    $sqlAmbil = "SELECT id_barang, nama_barang, nama_penerima, no_reg 
                 FROM penyaluran 
                 WHERE id_sekolah=? AND nama_barang=? 
                   " . ($kode_barang !== '' ? " AND kode_barang=? " : "") . "
                   " . ($thn_peng !== '' ? " AND thn_pengadaan=? " : "") . "
                   AND $regClause
                 LIMIT 1";

    $ambil = mysqli_prepare($conn, $sqlAmbil);
    if ($kode_barang !== '' && $thn_peng !== '') {
        mysqli_stmt_bind_param($ambil, "ssssssssss", $id_sekolah, $nama_barang, $kode_barang, $thn_peng, $no_reg, $no_reg, $no_reg, $no_reg, $no_reg, $no_reg);
    } elseif ($kode_barang !== '') {
        mysqli_stmt_bind_param($ambil, "sssssssss", $id_sekolah, $nama_barang, $kode_barang, $no_reg, $no_reg, $no_reg, $no_reg, $no_reg, $no_reg);
    } else {
        mysqli_stmt_bind_param($ambil, "ssssssss", $id_sekolah, $nama_barang, $no_reg, $no_reg, $no_reg, $no_reg, $no_reg, $no_reg);
    }

    mysqli_stmt_execute($ambil);
    $resAmbil = mysqli_stmt_get_result($ambil);
    $dataAmbil = mysqli_fetch_assoc($resAmbil);

    $id_barang_db      = $dataAmbil['id_barang'] ?? 0;
    $nama_barang_final = $dataAmbil['nama_barang'] ?? $nama_barang;
    $nama_penerima     = $dataAmbil['nama_penerima'] ?? '';
    $no_reg_asli       = $dataAmbil['no_reg'] ?? $no_reg;

    // 2. CEK KONDISI DARI TABEL KONDISI_BARANG_DETAIL
    $kondisi = 'B';
    $no_reg_cek = $no_reg_asli;
    if (strpos($no_reg_asli, '-') !== false) {
        $pecah = explode('-', $no_reg_asli);
        $no_reg_cek = trim($pecah[0]);
    }

    if ($id_barang_db > 0) {
        $ambil_k = mysqli_prepare($conn, 
            "SELECT kondisi 
             FROM kondisi_barang_detail 
             WHERE id_barang = ? 
               AND (TRIM(no_reg) = TRIM(?) OR CAST(no_reg AS UNSIGNED) = CAST(? AS UNSIGNED))
             LIMIT 1"
        );
        mysqli_stmt_bind_param($ambil_k, "iss", $id_barang_db, $no_reg_cek, $no_reg_cek);
    } else {
        $ambil_k = mysqli_prepare($conn, 
            "SELECT kbd.kondisi 
             FROM kondisi_barang_detail kbd
             JOIN data_barang db ON kbd.id_barang = db.id
             WHERE db.id_sekolah = ? 
               AND TRIM(db.nama_jenis_barang) = TRIM(?) 
               AND (TRIM(kbd.no_reg) = TRIM(?) OR CAST(kbd.no_reg AS UNSIGNED) = CAST(? AS UNSIGNED))
             LIMIT 1"
        );
        mysqli_stmt_bind_param($ambil_k, "ssss", $id_sekolah, $nama_barang_final, $no_reg_cek, $no_reg_cek);
    }

    mysqli_stmt_execute($ambil_k);
    $resKondisi = mysqli_stmt_get_result($ambil_k);
    if($dataKondisi = mysqli_fetch_assoc($resKondisi)){
        if(!empty($dataKondisi['kondisi'])){
            $kondisi = $dataKondisi['kondisi'];
        }
    }

    // 3. SIMPAN KE TABLE HISTORY BAST
    $stmt = mysqli_prepare($conn,
        "INSERT INTO history_bast 
        (id_sekolah, nama_barang, no_reg, nomor_surat, tanggal, nama_penerima, kondisi, dibuat_pada)
        VALUES (?, ?, ?, ?, ?, ?, ?, NOW())"
    );

    mysqli_stmt_bind_param($stmt, "sssssss",
        $id_sekolah,
        $nama_barang_final,
        $no_reg_asli,
        $nomor,
        $tanggal,
        $nama_penerima,
        $kondisi
    );

    mysqli_stmt_execute($stmt);
    echo mysqli_stmt_error($stmt) ? "error" : "success";
    exit;
}

// ===== SIMPAN HISTORY FAKTA =====
if(isset($_POST['action']) && $_POST['action'] == 'simpan_history_fakta'){
    $nama_barang = $_POST['nama_barang'] ?? '';
    $no_reg      = $_POST['no_reg'] ?? '';
    $nomor       = trim($_POST['nomor'] ?? '');
    $kode_barang = $_POST['kode_barang'] ?? '';
    $thn_peng    = $_POST['thn_pengadaan'] ?? '';

    if($nama_barang == '' || $no_reg == ''){
        echo "invalid";
        exit;
    }

    if($nomor == ''){
        $lastNumF = 0;
        $qNumF = mysqli_query($conn, "SELECT nomor_surat FROM history_fakta WHERE id_sekolah='$id_sekolah'");
        if($qNumF){
            while($rNumF = mysqli_fetch_assoc($qNumF)){
                $firstPartF = trim(explode('/', $rNumF['nomor_surat'])[0] ?? '');
                if($firstPartF !== '' && ctype_digit($firstPartF)){
                    $valF = (int)$firstPartF;
                    if($valF > $lastNumF){
                        $lastNumF = $valF;
                    }
                }
            }
        }
        $nextNumF = $lastNumF + 1;
        $paddedF  = str_pad($nextNumF, 3, '0', STR_PAD_LEFT);
        $nomor    = $kode_klasifikasi_bast !== '' ? ($paddedF . '/' . $kode_klasifikasi_bast) : $paddedF;
    }

    // 1. AMBIL ID_BARANG & NAMA PENERIMA DARI TABEL PENYALURAN SECARA PRESISI
    $regClause = buildRegMatchClause('no_reg');
    $sqlAmbil = "SELECT id_barang, nama_barang, nama_penerima, no_reg 
                 FROM penyaluran 
                 WHERE id_sekolah=? AND nama_barang=? 
                   " . ($kode_barang !== '' ? " AND kode_barang=? " : "") . "
                   " . ($thn_peng !== '' ? " AND thn_pengadaan=? " : "") . "
                   AND $regClause
                 LIMIT 1";

    $ambil = mysqli_prepare($conn, $sqlAmbil);
    if ($kode_barang !== '' && $thn_peng !== '') {
        mysqli_stmt_bind_param($ambil, "ssssssssss", $id_sekolah, $nama_barang, $kode_barang, $thn_peng, $no_reg, $no_reg, $no_reg, $no_reg, $no_reg, $no_reg);
    } elseif ($kode_barang !== '') {
        mysqli_stmt_bind_param($ambil, "sssssssss", $id_sekolah, $nama_barang, $kode_barang, $no_reg, $no_reg, $no_reg, $no_reg, $no_reg, $no_reg);
    } else {
        mysqli_stmt_bind_param($ambil, "ssssssss", $id_sekolah, $nama_barang, $no_reg, $no_reg, $no_reg, $no_reg, $no_reg, $no_reg);
    }

    mysqli_stmt_execute($ambil);
    $resAmbil = mysqli_stmt_get_result($ambil);
    $dataAmbil = mysqli_fetch_assoc($resAmbil);

    $id_barang_db      = $dataAmbil['id_barang'] ?? 0;
    $nama_barang_final = $dataAmbil['nama_barang'] ?? $nama_barang;
    $nama_penerima     = $dataAmbil['nama_penerima'] ?? '';
    $no_reg_asli       = $dataAmbil['no_reg'] ?? $no_reg;

    // 2. CEK KONDISI DARI TABEL KONDISI_BARANG_DETAIL
    $kondisi = 'B';
    $no_reg_cek = $no_reg_asli;
    if (strpos($no_reg_asli, '-') !== false) {
        $pecah = explode('-', $no_reg_asli);
        $no_reg_cek = trim($pecah[0]);
    }

    if ($id_barang_db > 0) {
        $ambil_k = mysqli_prepare($conn, 
            "SELECT kondisi 
             FROM kondisi_barang_detail 
             WHERE id_barang = ? 
               AND (TRIM(no_reg) = TRIM(?) OR CAST(no_reg AS UNSIGNED) = CAST(? AS UNSIGNED))
             LIMIT 1"
        );
        mysqli_stmt_bind_param($ambil_k, "iss", $id_barang_db, $no_reg_cek, $no_reg_cek);
    } else {
        $ambil_k = mysqli_prepare($conn, 
            "SELECT kbd.kondisi 
             FROM kondisi_barang_detail kbd
             JOIN data_barang db ON kbd.id_barang = db.id
             WHERE db.id_sekolah = ? 
               AND TRIM(db.nama_jenis_barang) = TRIM(?) 
               AND (TRIM(kbd.no_reg) = TRIM(?) OR CAST(kbd.no_reg AS UNSIGNED) = CAST(? AS UNSIGNED))
             LIMIT 1"
        );
        mysqli_stmt_bind_param($ambil_k, "ssss", $id_sekolah, $nama_barang_final, $no_reg_cek, $no_reg_cek);
    }

    mysqli_stmt_execute($ambil_k);
    $resKondisi = mysqli_stmt_get_result($ambil_k);
    if($dataKondisi = mysqli_fetch_assoc($resKondisi)){
        if(!empty($dataKondisi['kondisi'])){
            $kondisi = $dataKondisi['kondisi'];
        }
    }

    // 3. SIMPAN KE TABLE HISTORY FAKTA
    $stmt = mysqli_prepare($conn,
        "INSERT INTO history_fakta
        (id_sekolah, nama_barang, no_reg, nomor_surat, tanggal, nama_penerima, kondisi, dibuat_pada)
        VALUES (?, ?, ?, ?, CURDATE(), ?, ?, NOW())"
    );

    mysqli_stmt_bind_param($stmt, "ssssss",
        $id_sekolah,
        $nama_barang_final,
        $no_reg_asli,
        $nomor,
        $nama_penerima,
        $kondisi
    );

    mysqli_stmt_execute($stmt);
    if(mysqli_stmt_error($stmt)){
        echo "error";
    } else {
        echo "success||" . $nomor;
    }
    exit;
}

// ===== AMBIL HISTORY PER NO REG (NUMERIK AKURAT) =====
if(isset($_POST['action']) && $_POST['action'] == 'get_history_bast'){
    $nama_barang = $_POST['nama_barang'] ?? '';
    $no_reg      = $_POST['no_reg'] ?? '';

    if($nama_barang == '' || $no_reg == ''){
        echo json_encode([]);
        exit;
    }

    $regClause = buildRegMatchClause('no_reg');
    $stmt = mysqli_prepare($conn,
        "SELECT id_history, nama_barang, no_reg, nomor_surat, tanggal, dibuat_pada, nama_penerima 
         FROM history_bast
         WHERE id_sekolah=? 
           AND nama_barang=? 
           AND $regClause
         ORDER BY id_history DESC"
    );

    mysqli_stmt_bind_param($stmt, "ssssssss", $id_sekolah, $nama_barang, $no_reg, $no_reg, $no_reg, $no_reg, $no_reg, $no_reg);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);

    $data = [];
    while($row = mysqli_fetch_assoc($result)){
        $data[] = $row;
    }

    echo json_encode($data);
    exit;
}

// ===== AMBIL NOMOR SURAT BAST TERAKHIR =====
if(isset($_POST['action']) && $_POST['action'] == 'get_nomor_bast'){
    $lastNum = 0;
    $q = mysqli_query($conn, "SELECT nomor_surat FROM history_bast WHERE id_sekolah='$id_sekolah'");
    if($q){
        while($r = mysqli_fetch_assoc($q)){
            $firstPart = trim(explode('/', $r['nomor_surat'])[0] ?? '');
            if($firstPart !== '' && ctype_digit($firstPart)){
                $val = (int)$firstPart;
                if($val > $lastNum){
                    $lastNum = $val;
                }
            }
        }
    }

    echo $lastNum;
    exit;
}

// ===== AMBIL NOMOR SURAT FAKTA TERAKHIR =====
if(isset($_POST['action']) && $_POST['action'] == 'get_nomor_fakta'){
    $lastNum = 0;
    $q = mysqli_query($conn, "SELECT nomor_surat FROM history_fakta WHERE id_sekolah='$id_sekolah'");
    if($q){
        while($r = mysqli_fetch_assoc($q)){
            $firstPart = trim(explode('/', $r['nomor_surat'])[0] ?? '');
            if($firstPart !== '' && ctype_digit($firstPart)){
                $val = (int)$firstPart;
                if($val > $lastNum){
                    $lastNum = $val;
                }
            }
        }
    }

    echo $lastNum;
    exit;
}

// ===== AMBIL HISTORY FAKTA (NUMERIK AKURAT) =====
if(isset($_POST['action']) && $_POST['action'] == 'get_history_fakta'){
    $nama_barang = $_POST['nama_barang'] ?? '';
    $no_reg      = $_POST['no_reg'] ?? '';

    if($nama_barang == '' || $no_reg == ''){
        echo json_encode([]);
        exit;
    }

    $regClause = buildRegMatchClause('no_reg');
    $stmt = mysqli_prepare($conn,
        "SELECT id_history, nama_barang, no_reg, nomor_surat, tanggal, dibuat_pada, nama_penerima
         FROM history_fakta
         WHERE id_sekolah=? 
           AND nama_barang=? 
           AND $regClause
         ORDER BY id_history DESC"
    );

    mysqli_stmt_bind_param($stmt, "ssssssss", $id_sekolah, $nama_barang, $no_reg, $no_reg, $no_reg, $no_reg, $no_reg, $no_reg);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);

    $data = [];
    while($row = mysqli_fetch_assoc($result)){
        $data[] = $row;
    }

    echo json_encode($data);
    exit;
}

// ===== UPDATE NAMA PENERIMA (SUPPORT RANGE PAKET) =====
if(isset($_POST['action']) && $_POST['action'] == 'update_penerima'){
    $nama_barang = $_POST['nama_barang'] ?? '';
    $no_reg      = $_POST['no_reg'] ?? '';
    $nama_baru   = $_POST['nama_penerima'] ?? '';
    $lokasi      = $_POST['lokasi'] ?? '';

    if($nama_barang == '' || $no_reg == '' || $nama_baru == ''){
        echo "invalid";
        exit;
    }

    $regClause = buildRegMatchClause('no_reg');
    $stmt = mysqli_prepare($conn,
        "UPDATE penyaluran
         SET nama_penerima=?, lokasi=? 
         WHERE id_sekolah=? 
         AND nama_barang=? 
         AND $regClause"
    );

    mysqli_stmt_bind_param($stmt, "ssssssssss",
        $nama_baru,
        $lokasi,
        $id_sekolah,
        $nama_barang,
        $no_reg, $no_reg, $no_reg, $no_reg, $no_reg, $no_reg
    );

    mysqli_stmt_execute($stmt);

    if(mysqli_stmt_affected_rows($stmt) > 0){
        echo "success";
    } else {
        echo "not_found";
    }
    exit;
}

if(isset($_POST['restart_penyaluran'])){
    $nama_barang = $_POST['nama_barang'];
    $no_reg = $_POST['no_reg'];
    $id_sekolah = $_SESSION['id_sekolah'];

    $stmt = mysqli_prepare($conn,
        "DELETE FROM penyaluran
         WHERE id_sekolah=?
         AND nama_barang=?
         AND no_reg=?");

    mysqli_stmt_bind_param(
        $stmt,
        "sss",
        $id_sekolah,
        $nama_barang,
        $no_reg
    );

    mysqli_stmt_execute($stmt);
    echo "success";
    exit;
}

// ===== SIMPAN DATA AJAX =====
if(isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST'){
    $inputJSON = file_get_contents('php://input');
    $input = json_decode($inputJSON, true);

    if(!$input || !isset($input['action']) || $input['action'] != 'simpan_data_ajax' || !isset($input['data'])){
        echo "no_data";
        exit;
    }

    $rows = $input['data'];
    if(count($rows) == 0){
        echo "no_data";
        exit;
    }

    $duplicateRows = [];

    foreach($rows as $row){
        $id_barang       = $row['id_barang'] ?? 0;
        $nama_barang     = $row['nama_barang'] ?? '';
        $merk_tipe       = $row['merk_tipe'] ?? '';
        $kode_barang     = $row['kode_barang'] ?? '';
        $tgl_pengadaan   = $row['tgl'] ?? '';
        $bln_pengadaan   = $row['bln'] ?? '';
        $thn_pengadaan   = $row['thn'] ?? '';
        $vol_pengadaan   = $row['vol_pengadaan'] ?? '';
        $sat_pengadaan   = $row['sat_pengadaan'] ?? '';
        $harga_satuan    = $row['harga_satuan'] ?? 0;
        $lokasi          = $row['lokasi'] ?? '';
        $vol_penyaluran  = $row['vol_penyaluran'] ?? 0;
        $nama_penerima   = $row['penerima'] ?? '';
        $no_reg_raw      = $row['no_reg'] ?? '';
        $keterangan      = $row['ket'] ?? '';

        if($lokasi != "" && $nama_penerima != "" && $vol_penyaluran > 0){
            $cek = mysqli_prepare($conn,
                "SELECT id_penyaluran FROM penyaluran 
                 WHERE nama_barang=? AND no_reg=? AND id_sekolah=? LIMIT 1"
            );
            mysqli_stmt_bind_param($cek,"sss",$nama_barang,$no_reg_raw, $id_sekolah);
            mysqli_stmt_execute($cek);
            mysqli_stmt_store_result($cek);

            if(mysqli_stmt_num_rows($cek) > 0){
                $duplicateRows[] = $nama_barang.'||'.$no_reg_raw;
                continue;
            }

            $stmt = mysqli_prepare($conn,
                "INSERT INTO penyaluran
                (id_sekolah, id_barang, nama_barang, merk_tipe, kode_barang, 
                 tgl_pengadaan, bln_pengadaan, thn_pengadaan,
                 vol_pengadaan, sat_pengadaan, harga_satuan,
                 lokasi, vol_penyaluran, nama_penerima,
                 no_reg, keterangan)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)"
            );

            mysqli_stmt_bind_param(
                $stmt,
                "ssssssssssssssss",
                $id_sekolah,
                $id_barang,
                $nama_barang,
                $merk_tipe,
                $kode_barang,
                $tgl_pengadaan,
                $bln_pengadaan,
                $thn_pengadaan,
                $vol_pengadaan,
                $sat_pengadaan,
                $harga_satuan,
                $lokasi,
                $vol_penyaluran,
                $nama_penerima,
                $no_reg_raw,
                $keterangan
            );

            mysqli_stmt_execute($stmt);
            if(mysqli_stmt_error($stmt)){
                echo "error_insert";
                exit;
            }
        }
    }

    if(!empty($duplicateRows)){
        echo "duplicate||" . implode(",", $duplicateRows);
    } else {
        echo "success";
    }
    exit;
}

// ambil daftar bulan dan tahun unik
$bulan_list = [];
$tahun_list = [];

$qTahun = mysqli_query($conn, "SELECT DISTINCT TRIM(tahun_perolehan) AS thn FROM data_barang WHERE id_sekolah = '$id_sekolah' AND tahun_perolehan IS NOT NULL AND tahun_perolehan != '' AND tahun_perolehan != '0' ORDER BY tahun_perolehan DESC");
while($t = mysqli_fetch_assoc($qTahun)){
    if(!empty($t['thn']) && !in_array($t['thn'], $tahun_list)){
        $tahun_list[] = $t['thn'];
    }
}

$qBulan = mysqli_query($conn, "SELECT DISTINCT TRIM(bulan_perolehan) AS bln FROM data_barang WHERE id_sekolah = '$id_sekolah' AND bulan_perolehan IS NOT NULL AND bulan_perolehan != '' ORDER BY bulan_perolehan ASC");
while($b = mysqli_fetch_assoc($qBulan)){
    if(!empty($b['bln']) && !in_array($b['bln'], $bulan_list)){
        $bulan_list[] = $b['bln'];
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<title>Form Penyaluran Barang</title>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
<style>
    body {
        background: #f2f6fb;
        font-family: 'Plus Jakarta Sans', 'Segoe UI', sans-serif;
        padding: 0 0 84px 0 !important;
        color: #0f172a;
        overflow-x: hidden;
    }

    /* ================= HEADER BANNER UTAMA ================= */
    .salur-hero-header {
        position: relative;
        background: linear-gradient(95deg, #052c56 0%, #09587a 52%, #119498 100%);
        padding: 12px 24px 20px 24px;
        color: #ffffff;
        box-shadow: 0 6px 20px rgba(5, 44, 86, 0.16);
        overflow: hidden;
        margin-bottom: 14px;
    }

    .hero-left-swoosh {
        position: absolute;
        top: 0;
        left: 0;
        width: 340px;
        height: 100%;
        pointer-events: none;
        z-index: 1;
    }

    .salur-hero-wave {
        position: absolute;
        left: 0;
        right: 0;
        bottom: 0;
        width: 100%;
        height: 14px;
        pointer-events: none;
        z-index: 1;
    }

    .salur-hero-inner {
        position: relative;
        z-index: 2;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 14px;
    }

    .hero-left-brand {
        display: flex;
        align-items: center;
        gap: 10px;
        flex-shrink: 0;
        min-width: 280px;
    }
    .hero-left-brand img.logo-mark {
        height: 52px;
        width: auto;
        object-fit: contain;
        filter: drop-shadow(0 2px 4px rgba(0,0,0,0.08));
    }
    .hero-left-brand img.logo-text {
        height: 46px;
        width: auto;
        object-fit: contain;
    }

    .hero-vertical-line {
        width: 1.5px;
        height: 44px;
        background: rgba(255, 255, 255, 0.45);
        margin-left: 16px;
    }

    .hero-center-title {
        display: flex;
        align-items: flex-start;
        justify-content: center;
        gap: 10px;
        flex: 1;
    }

    .hero-cube-icon {
        font-size: 26px;
        color: #bae6fd;
        margin-top: -2px;
    }

    .hero-title-stack {
        text-align: center;
    }

    .judul {
        font-size: 18.5px;
        font-weight: 800;
        color: #ffffff !important;
        letter-spacing: 0.4px;
        margin: 0 0 4px 0;
        text-shadow: 0 1px 3px rgba(0,0,0,0.18);
    }

    .hero-sub-pill {
        display: inline-block;
        background: #ffffff;
        color: #062f59;
        font-size: 11.5px;
        font-weight: 800;
        padding: 2px 24px;
        border-radius: 999px;
        letter-spacing: 0.4px;
        margin-bottom: 4px;
        box-shadow: 0 2px 6px rgba(0,0,0,0.12);
    }

    .hero-school-name {
        font-size: 16.5px;
        font-weight: 800;
        color: #ffffff;
        letter-spacing: 0.3px;
        margin: 0;
    }

    .btn-dashboard-glass {
        background: rgba(255, 255, 255, 0.14);
        border: 1px solid rgba(255, 255, 255, 0.36);
        color: #ffffff !important;
        border-radius: 10px;
        padding: 8px 18px;
        font-size: 13.5px;
        font-weight: 700;
        display: inline-flex;
        align-items: center;
        gap: 8px;
        text-decoration: none;
        backdrop-filter: blur(6px);
        transition: all 0.2s ease;
        flex-shrink: 0;
    }
    .btn-dashboard-glass:hover {
        background: rgba(255, 255, 255, 0.26);
        transform: translateY(-1px);
    }

    /* ================= FILTER BAR & TOMBOL ATAS ================= */
    .content-container-salur {
        padding: 0 18px;
    }

    .filter-search-box {
        position: relative;
        background: #ffffff;
        border: 1.5px solid #cbd5e1;
        border-radius: 12px;
        display: flex;
        align-items: center;
        box-shadow: 0 2px 6px rgba(15, 23, 42, 0.03);
    }
    .filter-search-box i {
        color: #475569;
        font-size: 15px;
        margin-left: 14px;
    }
    .filter-search-box input {
        border: none !important;
        background: transparent !important;
        box-shadow: none !important;
        padding: 9px 14px 9px 10px !important;
        font-size: 13px !important;
        font-weight: 600 !important;
        color: #1e293b !important;
        width: 100%;
        margin: 0 !important;
    }

    .filter-select-box {
        background: #ffffff;
        border: 1.5px solid #cbd5e1;
        border-radius: 12px;
        display: flex;
        align-items: center;
        overflow: hidden;
        box-shadow: 0 2px 6px rgba(15, 23, 42, 0.03);
    }
    .filter-select-box .icon-addon {
        padding: 9px 12px;
        border-right: 1px solid #e2e8f0;
        color: #034ea2;
        font-size: 15px;
        display: flex;
        align-items: center;
        justify-content: center;
        background: #f8fafc;
    }
    .filter-select-box select {
        border: none !important;
        background-color: #ffffff !important;
        box-shadow: none !important;
        padding: 9px 30px 9px 12px !important;
        font-size: 13px !important;
        font-weight: 700 !important;
        color: #0f172a !important;
        width: 100%;
        margin: 0 !important;
        cursor: pointer;
    }

    .btn-pilih-yellow {
        background: linear-gradient(135deg, #facc15 0%, #eab308 100%) !important;
        border: none !important;
        color: #0f172a !important;
        font-weight: 800 !important;
        font-size: 13px !important;
        border-radius: 10px !important;
        padding: 8px 22px !important;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
        box-shadow: 0 4px 12px rgba(234, 179, 8, 0.25);
        transition: 0.2s;
    }
    .btn-pilih-yellow:hover {
        background: linear-gradient(135deg, #eab308 0%, #ca8a04 100%) !important;
        transform: translateY(-1px);
    }

    .btn-paket-teal {
        background: linear-gradient(135deg, #0d9488 0%, #0f766e 100%) !important;
        border: none !important;
        color: #ffffff !important;
        font-weight: 700 !important;
        font-size: 13px !important;
        border-radius: 10px !important;
        padding: 8px 18px !important;
        box-shadow: 0 4px 12px rgba(13, 148, 136, 0.25);
    }

    .btn-refresh-blue {
        background: linear-gradient(135deg, #0077ff 0%, #005ce6 100%) !important;
        border: none !important;
        color: #ffffff !important;
        font-weight: 700 !important;
        font-size: 13px !important;
        border-radius: 10px !important;
        padding: 8px 20px !important;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
        box-shadow: 0 4px 12px rgba(0, 119, 255, 0.25);
        transition: 0.2s;
    }
    .btn-refresh-blue:hover {
        background: linear-gradient(135deg, #0066eb 0%, #004bb8 100%) !important;
        transform: translateY(-1px);
    }

    /* ================= CARD & TABEL INVENTARIS ================= */
    .table-card-wrapper {
        background: #ffffff;
        border: 1px solid #dbe7f4;
        border-radius: 18px;
        padding: 10px;
        box-shadow: 0 10px 28px -6px rgba(6, 47, 89, 0.08);
        position: relative;
    }

    .table-responsive {
        max-height: 520px;
        overflow-y: auto;
        overflow-x: auto;
        border-radius: 12px;
        position: relative;
    }

    #tableInventaris {
        border-collapse: collapse;
        margin-bottom: 0;
        width: 100%;
    }

    #tableInventaris thead th {
        position: sticky;
        z-index: 10;
        text-align: center;
        vertical-align: middle;
        font-size: 12px;
        font-weight: 900 !important;
        letter-spacing: 0.3px;
        border: 1px solid #d5e3f0 !important;
        padding: 9px 6px !important;
        text-shadow: 0 0 0.25px currentColor;
    }

    #tableInventaris thead th strong {
        font-weight: 900 !important;
    }

    #tableInventaris thead tr:first-child th {
        top: 0;
        background: #eaf3fa !important;
        color: #06213a !important;
    }

    #tableInventaris thead tr:first-child th.th-group-teal {
        background: linear-gradient(95deg, #0e8a8d 0%, #096880 55%, #074f6e 100%) !important;
        color: #ffffff !important;
        border-top-left-radius: 12px;
        border-top-right-radius: 12px;
        font-size: 13px;
        font-weight: 900 !important;
        letter-spacing: 0.6px;
    }

    #tableInventaris thead tr:nth-child(2) th {
        top: 39px;
        background: #eef6fc !important;
        color: #06213a !important;
        font-weight: 900 !important;
    }

    #tableInventaris tbody tr {
        background-color: #ffffff;
        transition: background-color 0.12s ease;
    }
    #tableInventaris tbody tr:nth-child(even):not(.row-pembatas-tahun) {
        background-color: #f7fbff;
    }
    #tableInventaris tbody tr:hover:not(.row-pembatas-tahun) {
        background-color: #ebf5fe;
    }

    #tableInventaris tbody td {
        text-align: center;
        vertical-align: middle;
        font-size: 12px;
        font-weight: 600;
        color: #1e293b;
        border: 1px solid #e2ecf5;
        padding: 8px 6px !important;
    }

    #tableInventaris tbody td:nth-child(2),
    #tableInventaris tbody td:nth-child(3),
    #tableInventaris tbody td:nth-child(11),
    #tableInventaris tbody td:nth-child(12) {
        text-align: left !important;
    }

    #tableInventaris tbody td:nth-child(2) {
        font-weight: 800;
        color: #0f172a;
        min-width: 145px;
    }

    #tableInventaris tbody td:nth-child(11),
    #tableInventaris tbody td:nth-child(12) {
        min-width: 170px;
    }

    #tableInventaris select.lokasi,
    #tableInventaris select.penerima {
        border: 1.5px solid #cbd5e1 !important;
        border-radius: 9px !important;
        height: 33px;
        font-size: 12px !important;
        font-weight: 600;
        color: #1e293b;
        background-color: #ffffff;
        padding: 4px 26px 4px 10px;
        margin-bottom: 0;
        width: 100%;
    }

    select.select2-hidden-accessible {
        display: none !important;
        visibility: hidden !important;
        position: absolute !important;
        width: 0 !important;
        height: 0 !important;
        pointer-events: none !important;
    }

    #tableInventaris .select2-container {
        width: 100% !important;
        display: block !important;
    }
    #tableInventaris .select2-container--default .select2-selection--single {
        height: 33px !important;
        border: 1.5px solid #cbd5e1 !important;
        border-radius: 9px !important;
        display: flex;
        align-items: center;
        background-color: #ffffff;
    }
    #tableInventaris .select2-container--default .select2-selection--single .select2-selection__rendered {
        font-size: 12px !important;
        font-weight: 600 !important;
        color: #1e293b !important;
        padding-left: 10px !important;
        padding-right: 26px !important;
        line-height: 31px !important;
    }
    #tableInventaris .select2-container--default .select2-selection--single .select2-selection__arrow {
        height: 31px !important;
    }
    #tableInventaris .select2-container--default.select2-container--disabled .select2-selection--single {
        background-color: #cbd5e1 !important;
        cursor: not-allowed;
    }

    .table-card-wrapper .select2-dropdown {
        z-index: 9999 !important;
        border: 1.5px solid #0284c7 !important;
        border-radius: 10px !important;
        box-shadow: 0 12px 28px rgba(15, 23, 42, 0.2) !important;
        background: #ffffff !important;
        overflow: hidden;
    }
    .table-card-wrapper .select2-search--dropdown {
        padding: 6px !important;
        background: #f8fafc !important;
        border-bottom: 1px solid #e2e8f0 !important;
    }
    .table-card-wrapper .select2-search--dropdown .select2-search__field {
        border: 1.5px solid #93c5fd !important;
        border-radius: 7px !important;
        padding: 5px 9px !important;
        font-size: 12px !important;
        font-weight: 600 !important;
        outline: none !important;
    }
    .table-card-wrapper .select2-results__option {
        font-size: 12px !important;
        font-weight: 600 !important;
        padding: 6px 10px !important;
    }

    #tableInventaris .vol_penyaluran {
        border: 1.5px solid #cbd5e1 !important;
        border-radius: 9px !important;
        height: 33px;
        font-size: 12px !important;
        font-weight: 700;
        margin-bottom: 0;
        background: #ffffff;
    }

    #tableInventaris input[name="ket[]"] {
        border: 1.5px solid #cbd5e1 !important;
        border-radius: 6px !important;
        height: 28px;
        width: 44px;
        min-width: 44px;
        font-size: 11.5px !important;
        font-weight: 600;
        text-align: center;
        padding: 2px 4px;
        margin: 0 auto;
        background: #ffffff;
        transition: all 0.15s ease;
    }
    #tableInventaris input[name="ket[]"]:focus {
        width: 110px;
        text-align: left;
        padding: 2px 8px;
        border-color: #0284c7 !important;
    }

    /* ===== KOLOM & MENU AKSI MENGAMBANG ===== */
    .btn-aksi {
        font-size: 11px;
        font-weight: 700;
        padding: 5px 11px;
        margin: 0;
        display: inline-flex;
        align-items: center;
        gap: 4px;
        border-radius: 7px;
        white-space: nowrap;
        border: none;
    }
    .col-aksi {
        width: 58px;
        min-width: 58px;
        max-width: 58px;
        text-align: center;
    }
    td.aksi-cell {
        width: 58px;
        min-width: 58px;
        max-width: 58px;
        text-align: center;
        white-space: nowrap;
        padding: 5px !important;
        position: relative;
    }

    .btn-plus-aksi {
        border-radius: 50%;
        width: 31px;
        height: 31px;
        padding: 0;
        display: flex;
        align-items: center;
        justify-content: center;
        background: linear-gradient(135deg, #0077ff 0%, #005ce6 100%);
        color: #ffffff;
        border: none;
        transition: all 0.25s ease;
        margin: 0 auto;
        box-shadow: 0 3px 8px rgba(0, 119, 255, 0.28);
    }
    .btn-plus-aksi:hover {
        background: linear-gradient(135deg, #0066eb 0%, #004bb8 100%);
        transform: scale(1.05);
    }
    .btn-plus-aksi.active {
        transform: rotate(45deg);
        background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
        box-shadow: 0 3px 8px rgba(220, 38, 38, 0.32);
    }

    .aksi-mengambang {
        display: none;
        position: absolute;
        right: 48px;
        top: 50%;
        transform: translateY(-50%);
        background: #ffffff;
        padding: 6px 8px;
        border-radius: 12px;
        box-shadow: 0 10px 28px rgba(15, 23, 42, 0.18);
        border: 1.5px solid #cbd5e1;
        z-index: 1055;
        flex-direction: row;
        gap: 6px;
    }
    .aksi-mengambang.show {
        display: flex !important;
    }

    .btn-bast-custom {
        background-color: #15803d !important;
        color: #ffffff !important;
    }
    .btn-fakta-custom {
        background-color: #facc15 !important;
        color: #0f172a !important;
    }
    .btn-edit-custom {
        background-color: #0077ff !important;
        color: #ffffff !important;
    }
    .btnRestart {
        background-color: #071e3d !important;
        border-color: #071e3d !important;
        color: #ffffff !important;
    }
    .btnRestart:hover {
        background-color: #031124 !important;
    }

    /* ================= FOOTER PAGINATION & FLOATING BUTTONS ================= */
    .bottom-pagination-bar {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        padding: 16px 22px 10px 22px;
    }

    .page-size-control {
        display: flex;
        align-items: center;
        gap: 8px;
        font-size: 13px;
        font-weight: 700;
        color: #0f172a;
    }
    .page-size-control select {
        border: 1.5px solid #cbd5e1;
        border-radius: 9px;
        padding: 4px 10px;
        font-weight: 700;
        font-size: 13px;
        background: #ffffff;
        color: #0f172a;
        cursor: pointer;
    }

    .pagination-nav-pills {
        display: inline-flex;
        align-items: center;
        gap: 4px;
        background: #ffffff;
        padding: 4px 8px;
        border-radius: 12px;
        border: 1px solid #dbe7f4;
        box-shadow: 0 2px 8px rgba(15, 23, 42, 0.04);
    }
    .pagination-nav-pills button {
        border: none;
        background: transparent;
        color: #0284c7;
        font-weight: 700;
        font-size: 13px;
        min-width: 30px;
        height: 30px;
        border-radius: 8px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        transition: 0.15s;
    }
    .pagination-nav-pills button:hover:not(:disabled) {
        background: #e0f2fe;
    }
    .pagination-nav-pills button.active {
        background: #0077ff;
        color: #ffffff;
        box-shadow: 0 2px 6px rgba(0, 119, 255, 0.3);
    }
    .pagination-nav-pills button:disabled {
        color: #94a3b8;
        cursor: not-allowed;
    }

    .actions {
        position: fixed;
        bottom: 16px;
        right: 22px;
        z-index: 1050;
        display: flex;
        flex-direction: row;
        align-items: center;
        gap: 10px;
        background: rgba(255, 255, 255, 0.96);
        padding: 6px 12px;
        border-radius: 999px;
        box-shadow: 0 10px 28px rgba(6, 47, 89, 0.16);
        border: 1px solid #dbe7f4;
        backdrop-filter: blur(6px);
    }

    .btn-download-floating {
        background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
        color: #ffffff;
        border: none;
        padding: 9px 18px;
        border-radius: 999px;
        font-weight: 800;
        font-size: 12.5px;
        display: inline-flex;
        align-items: center;
        gap: 8px;
        text-decoration: none;
        box-shadow: 0 4px 12px rgba(220, 38, 38, 0.30);
        transition: 0.25s;
    }
    .btn-download-floating:hover {
        background: linear-gradient(135deg, #dc2626 0%, #b91c1c 100%);
        transform: translateY(-1.5px);
        color: #ffffff;
    }

    .btn-save-floating {
        background: linear-gradient(135deg, #0284c7 0%, #0369a1 100%);
        color: #ffffff;
        border: none;
        padding: 9px 20px;
        border-radius: 999px;
        font-weight: 800;
        font-size: 12.5px;
        display: inline-flex;
        align-items: center;
        gap: 8px;
        text-decoration: none;
        box-shadow: 0 4px 12px rgba(2, 132, 199, 0.28);
        transition: 0.25s;
    }
    .btn-save-floating:hover {
        background: linear-gradient(135deg, #0369a1 0%, #075985 100%);
        transform: translateY(-1.5px);
        color: #ffffff;
    }

    /* ================= GARIS PEMBATAS HIJAU TEBAL ================= */
    .btn-paket { display:none; }
    .border-bottom-paket td {
        border-bottom: 4px solid #0d6efd !important;
        box-shadow: inset 0 -3px 0 #0d6efd !important;
    }
    #tableInventaris tbody tr.border-bottom-barang td {
        border-bottom: 4px solid #28a745 !important;
        box-shadow: inset 0 -3px 0 #28a745 !important;
    }
    #tableInventaris tbody tr.row-pembatas-tahun td {
        height: 6px !important;
        padding: 0 !important;
        background-color: #28a745 !important;
        border-top: 2px solid #28a745 !important;
        border-bottom: 2px solid #28a745 !important;
    }
    .cekRowCol { display:none; }
    .disable-row, .disable-row td { 
        background-color: #e2e8f0 !important;
        color: #334155 !important;
    }
    .disable-row .form-select:disabled,
    .disable-row .form-control:disabled {
        background-color: #cbd5e1 !important;
        color: #1e293b !important;
    }

    /* ================= CUSTOM MODAL UI ================= */
    .modal-custom-modern .modal-content {
        border: none;
        border-radius: 18px;
        overflow: hidden;
        box-shadow: 0 20px 50px rgba(15, 23, 42, 0.25);
        background: #ffffff;
    }
    .modal-custom-modern .modal-body {
        padding: 20px 24px;
        background: #ffffff;
    }
    .modal-custom-modern .modal-footer {
        background: #f8fafc;
        border-top: 1px solid #e2e8f0;
        padding: 14px 24px;
        gap: 10px;
    }

    .header-bast-modern {
        position: relative;
        background: linear-gradient(95deg, #045b33 0%, #0b875b 55%, #16a37a 100%);
        padding: 16px 24px;
        color: #ffffff;
        display: flex;
        align-items: center;
        justify-content: space-between;
        overflow: hidden;
    }
    .header-bast-modern .header-content {
        position: relative;
        z-index: 2;
        display: flex;
        align-items: center;
        gap: 14px;
    }
    .header-bast-modern .header-icon-box {
        width: 42px;
        height: 42px;
        border-radius: 10px;
        background: rgba(255, 255, 255, 0.16);
        border: 1.5px solid rgba(255, 255, 255, 0.45);
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 22px;
        color: #ffffff;
    }

    .header-fakta-modern {
        position: relative;
        background: linear-gradient(95deg, #ffd000 0%, #ffbb00 55%, #f59e0b 100%);
        padding: 16px 24px;
        color: #0f172a;
        display: flex;
        align-items: center;
        justify-content: space-between;
        overflow: hidden;
    }
    .header-fakta-modern .header-content {
        position: relative;
        z-index: 2;
        display: flex;
        align-items: center;
        gap: 12px;
    }

    .header-edit-modern {
        position: relative;
        background: linear-gradient(95deg, #07386b 0%, #0969a8 52%, #00b4d8 100%);
        padding: 16px 24px;
        color: #ffffff;
        display: flex;
        align-items: center;
        justify-content: space-between;
        overflow: hidden;
    }
    .header-edit-modern .header-content {
        position: relative;
        z-index: 2;
        display: flex;
        align-items: center;
        gap: 14px;
    }
    .header-edit-divider {
        width: 1.5px;
        height: 36px;
        background: rgba(255, 255, 255, 0.5);
    }

    .header-filter-blue {
        position: relative;
        background: linear-gradient(95deg, #052c56 0%, #0353a4 55%, #0077ff 100%);
        padding: 16px 24px;
        color: #ffffff;
        display: flex;
        align-items: center;
        justify-content: space-between;
        overflow: hidden;
    }
    .header-filter-blue .header-content {
        position: relative;
        z-index: 2;
        display: flex;
        align-items: center;
        gap: 12px;
    }
    .header-filter-blue .header-icon-circle {
        width: 40px;
        height: 40px;
        border-radius: 50%;
        background: rgba(255, 255, 255, 0.2);
        border: 1px solid rgba(255, 255, 255, 0.4);
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 19px;
        color: #ffffff;
    }
    #modalFilterCetak .form-select,
    #modalFilterCetak .asal-check-box,
    #modalFilterCetak .asal-droplist-trigger {
        border: 1.5px solid #bfdbfe !important;
        border-radius: 11px !important;
        transition: border-color 0.2s, box-shadow 0.2s;
    }
    #modalFilterCetak .form-select:focus,
    #modalFilterCetak .asal-droplist-trigger:hover {
        border-color: #0077ff !important;
        box-shadow: 0 0 0 3px rgba(0, 119, 255, 0.14) !important;
    }
    #modalFilterCetak .form-check-input:checked {
        background-color: #0077ff;
        border-color: #0077ff;
    }

    .modal-split-card {
        border: 1px solid #e2e8f0;
        border-radius: 14px;
        background: #ffffff;
        overflow: hidden;
        height: 100%;
        box-shadow: 0 4px 12px rgba(15, 23, 42, 0.03);
    }
    .modal-split-card-top-green {
        background: linear-gradient(90deg, #dcfce7 0%, #ecfdf5 100%);
        padding: 14px 16px;
        border-bottom: 1px solid #d1fae5;
    }
    .modal-split-card-top-blue {
        background: linear-gradient(90deg, #e0f2fe 0%, #f0f9ff 100%);
        padding: 14px 16px;
        border-bottom: 1px solid #bae6fd;
    }
    .modal-split-card-top-yellow {
        background: linear-gradient(90deg, #fef3c7 0%, #fffbeb 100%);
        padding: 12px 16px;
        border-bottom: 1px solid #fde68a;
    }

    .input-icon-wrapper {
        display: flex;
        align-items: center;
        border: 1.5px solid #93c5fd;
        border-radius: 11px;
        background: #ffffff;
        padding: 4px 12px;
        transition: border-color 0.2s, box-shadow 0.2s;
    }
    .input-icon-wrapper:focus-within {
        border-color: #2563eb;
        box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.12);
    }
    .input-icon-wrapper .field-icon {
        color: #1d4ed8;
        font-size: 18px;
        margin-right: 10px;
        display: flex;
        align-items: center;
    }
    .input-icon-wrapper input {
        border: none !important;
        box-shadow: none !important;
        background: transparent !important;
        font-size: 14px !important;
        font-weight: 600 !important;
        color: #0f172a !important;
        padding: 6px 4px !important;
        margin: 0 !important;
        width: 100%;
    }

    .table-history-modern {
        width: 100%;
        border-collapse: collapse;
        font-size: 12.5px;
    }
    .table-history-modern thead th {
        background: #f8fafc;
        color: #0f172a;
        font-weight: 800;
        padding: 9px 10px;
        border-bottom: 1.5px solid #e2e8f0;
        text-align: left;
    }
    .table-history-modern tbody td {
        padding: 9px 10px;
        border-bottom: 1px solid #f1f5f9;
        color: #1e293b;
        font-weight: 600;
        vertical-align: middle;
    }
    .btn-eye-history {
        background: #e0f2fe;
        color: #0284c7;
        border: 1px solid #bae6fd;
        border-radius: 8px;
        padding: 3px 10px;
        font-size: 13px;
        transition: 0.15s;
    }
    .btn-eye-history:hover {
        background: #0284c7;
        color: #ffffff;
    }

    .btn-modal-tutup {
        background: #64748b !important;
        border: none !important;
        color: #ffffff !important;
        font-weight: 700 !important;
        font-size: 13.5px !important;
        padding: 9px 22px !important;
        border-radius: 10px !important;
        display: inline-flex;
        align-items: center;
        gap: 7px;
    }
    .btn-modal-tutup:hover {
        background: #475569 !important;
    }
    .btn-modal-bast-submit {
        background: linear-gradient(135deg, #0d9458 0%, #067a46 100%) !important;
        border: none !important;
        color: #ffffff !important;
        font-weight: 700 !important;
        font-size: 13.5px !important;
        padding: 9px 22px !important;
        border-radius: 10px !important;
        display: inline-flex;
        align-items: center;
        gap: 8px;
        box-shadow: 0 4px 12px rgba(13, 148, 88, 0.28);
    }
    .btn-modal-bast-submit:hover {
        background: linear-gradient(135deg, #067a46 0%, #055e36 100%) !important;
    }
    .btn-modal-fakta-submit {
        background: linear-gradient(135deg, #ffd000 0%, #f5b800 100%) !important;
        border: none !important;
        color: #0f172a !important;
        font-weight: 800 !important;
        font-size: 13.5px !important;
        padding: 9px 22px !important;
        border-radius: 10px !important;
        display: inline-flex;
        align-items: center;
        gap: 8px;
        box-shadow: 0 4px 12px rgba(245, 184, 0, 0.3);
    }
    .btn-modal-fakta-submit:hover {
        background: linear-gradient(135deg, #f5b800 0%, #e0a800 100%) !important;
    }
    .btn-modal-edit-submit {
        background: linear-gradient(135deg, #0077ff 0%, #005ce6 100%) !important;
        border: none !important;
        color: #ffffff !important;
        font-weight: 700 !important;
        font-size: 13.5px !important;
        padding: 9px 24px !important;
        border-radius: 10px !important;
        display: inline-flex;
        align-items: center;
        gap: 8px;
        box-shadow: 0 4px 12px rgba(0, 119, 255, 0.28);
    }
    .btn-modal-edit-submit:hover {
        background: linear-gradient(135deg, #0066eb 0%, #004bb8 100%) !important;
    }

    #modalEditPenerima .select2-container--default .select2-selection--single,
    #modalEditPenerima .form-select {
        height: 42px !important;
        border: 1.5px solid #93c5fd !important;
        border-radius: 10px !important;
        display: flex;
        align-items: center;
        font-size: 14px !important;
        font-weight: 600 !important;
        color: #0f172a !important;
        background-color: #ffffff !important;
    }
    #modalEditPenerima .select2-container--default .select2-selection--single .select2-selection__rendered {
        line-height: 40px !important;
        padding-left: 14px !important;
        color: #0f172a !important;
    }
    #modalEditPenerima .select2-container--default .select2-selection--single .select2-selection__arrow {
        height: 40px !important;
        right: 8px !important;
    }
</style>
</head>
<body>

<?php
$id_sekolah_user = $_SESSION['id_sekolah']; 
$kop_q = mysqli_query($conn, "SELECT nama_sekolah FROM kop_surat WHERE id_sekolah = '$id_sekolah_user' LIMIT 1");
$kop   = mysqli_fetch_assoc($kop_q);

if ($kop) {
    $nama_sekolah = $kop['nama_sekolah'];
} else {
    $nama_sekolah = "SEKOLAH (ID: " . $id_sekolah_user . ")";
}
?>

<!-- HEADER BANNER UTAMA -->
<div class="salur-hero-header">
    <svg class="hero-left-swoosh" viewBox="0 0 360 110" preserveAspectRatio="none" aria-hidden="true">
        <path d="M0,0 L315,0 C295,68 255,102 185,110 L0,110 Z" fill="#0252a8"></path>
        <path d="M0,0 L306,0 C286,65 247,99 178,110 L0,110 Z" fill="#facc15"></path>
        <path d="M0,0 L296,0 C276,62 238,96 170,110 L0,110 Z" fill="#ffffff"></path>
    </svg>

    <div class="salur-hero-inner">
        <div class="hero-left-brand">
            <img src="logolog.JPEG" alt="Logo" class="logo-mark">
            <img src="dipta.JPEG" alt="SI DIPTA Beu!" class="logo-text">
            <div class="hero-vertical-line d-none d-lg-block"></div>
        </div>

        <div class="hero-center-title">
            <i class="bi bi-box-seam hero-cube-icon d-none d-md-inline-block"></i>
            <div class="hero-title-stack">
                <h3 class="judul">BUKU PENYALURAN BELANJA MODAL</h3>
                <div><span class="hero-sub-pill">PERALATAN DAN MESIN</span></div>
                <h6 class="hero-school-name"><?php echo strtoupper($nama_sekolah); ?></h6>
            </div>
        </div>

        <div>
            <a href="index.php" class="btn-dashboard-glass">
                <i class="bi bi-house-door-fill"></i> Dashboard
            </a>
        </div>
    </div>

    <svg class="salur-hero-wave" viewBox="0 0 1440 24" preserveAspectRatio="none" aria-hidden="true">
        <path d="M0,12 C380,26 760,-2 1140,15 C1290,21 1380,17 1440,12 L1440,24 L0,24 Z" fill="rgba(255,255,255,0.22)"></path>
        <path d="M0,16 C420,28 840,4 1240,18 C1350,22 1405,20 1440,16 L1440,24 L0,24 Z" fill="#f2f6fb"></path>
    </svg>
</div>

<div class="content-container-salur">
    <!-- BARIS FILTER PENCARIAN, BULAN, TAHUN -->
    <div class="row mb-3 g-2">
      <div class="col-md-6">
        <div class="filter-search-box">
            <i class="bi bi-search"></i>
            <input type="text" class="form-control" id="filterBarang" 
                   placeholder="Cari nama barang, merk/tipe, atau kode barang..." 
                   value="<?= isset($_GET['q']) ? htmlspecialchars($_GET['q']) : '' ?>">
        </div>
      </div>

      <div class="col-md-3">
        <div class="filter-select-box">
            <span class="icon-addon"><i class="bi bi-calendar2-week"></i></span>
            <select class="form-select" id="filterBulan">
              <option value="">Semua Bulan</option>
              <?php foreach($bulan_list as $bln){ ?>
                <option value="<?= $bln ?>" <?= (isset($_GET['bln']) && $_GET['bln'] == $bln) ? 'selected' : '' ?>><?= $bln ?></option>
              <?php } ?>
            </select>
        </div>
      </div>

      <div class="col-md-3">
        <div class="filter-select-box">
            <span class="icon-addon"><i class="bi bi-calendar2-week"></i></span>
            <select class="form-select" id="filterTahun">
              <option value="">Semua Tahun</option>
              <?php foreach($tahun_list as $thn){ ?>
                <option value="<?= $thn ?>" <?= (isset($_GET['thn']) && $_GET['thn'] == $thn) ? 'selected' : '' ?>><?= $thn ?></option>
              <?php } ?>
            </select>
        </div>
      </div>
    </div>

    <!-- BARIS TOMBOL PILIH DATA, PAKET, REFRESH FILTER -->
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
      <div class="d-flex align-items-center gap-2">
        <button type="button" id="btnPilihBaris" class="btn btn-pilih-yellow">
            <i class="bi bi-check-square"></i> Pilih Data
        </button>
        <button type="button" id="buatPaket" class="btn btn-paket-teal btn-paket">
            <i class="bi bi-box-seam"></i> Paket
        </button>
      </div>
      <div>
        <button type="button" onclick="window.location.href='buku_salur.php'" class="btn btn-refresh-blue">
            <i class="bi bi-arrow-counterclockwise"></i> Refresh Filter
        </button>
      </div>
    </div>

    <!-- KARTU TABEL UTAMA -->
    <div class="table-card-wrapper">
      <div class="table-responsive" style="padding-bottom: 20px;">
        <table class="table table-bordered table-hover" id="tableInventaris">
          <thead>
            <tr class="text-center align-middle">
                <th rowspan="2"><strong>NO</strong></th>
                <th rowspan="2"><strong>NAMA BARANG</strong></th>
                <th rowspan="2"><strong>MERK/TYPE</strong></th>
                <th rowspan="2"><strong>KODE BARANG</strong></th>
                <th colspan="6" class="th-group-teal"><i class="bi bi-box-seam me-1"></i> <strong>PENGADAAN</strong></th>
                <th colspan="3" class="th-group-teal"><i class="bi bi-arrow-left-right me-1"></i> <strong>PENYALURAN</strong></th>
                <th rowspan="2" class="cekRowCol"><input type="checkbox" id="checkAll"></th>
                <th rowspan="2"><strong>NO.REG</strong></th>
                <th rowspan="2"><strong>KET</strong></th>
                <th rowspan="2" class="col-aksi"><strong>AKSI</strong></th>
            </tr>
            <tr class="text-center align-middle">
                <th><strong>TGL</strong></th>
                <th><strong>BLN</strong></th>
                <th><strong>THN</strong></th>
                <th><strong>VOL</strong></th>
                <th><strong>SAT</strong></th>
                <th><strong>HARGA SATUAN</strong></th>
                <th><strong>NAMA PENERIMA</strong></th>
                <th><strong>RUANG</strong></th>
                <th style="width: 65px;"><strong>VOL</strong></th>
            </tr>
          </thead>
          <tbody>
<?php
$f_thn  = isset($_GET['thn']) ? mysqli_real_escape_string($conn, $_GET['thn']) : '';
$f_bln  = isset($_GET['bln']) ? mysqli_real_escape_string($conn, $_GET['bln']) : '';
$f_cari = isset($_GET['q'])   ? mysqli_real_escape_string($conn, $_GET['q'])   : '';

$where_filter = "WHERE id_sekolah = '$id_sekolah'";

if($f_thn != '') {
    $where_filter .= " AND TRIM(tahun_perolehan) = '$f_thn'";
}
if($f_bln != '') {
    $where_filter .= " AND TRIM(bulan_perolehan) = '$f_bln'";
}
if($f_cari != '') {
    $where_filter .= " AND (nama_jenis_barang LIKE '%$f_cari%' OR merek_type LIKE '%$f_cari%' OR kode_barang LIKE '%$f_cari%')"; 
}

$qInv = mysqli_query($conn, "SELECT * FROM data_barang 
    $where_filter 
    ORDER BY 
    tahun_perolehan DESC, 
    bulan_perolehan DESC, 
    CAST(reg AS UNSIGNED) ASC");

$ruangNames = [];
foreach($ruang as $rg){
    $ruangNames[] = $rg['nama_ruang'];
}
$pegawaiList = [];
foreach($pegawai as $pg){
    $pegawaiList[] = [
        'nama' => strtoupper($pg['nama_lengkap']),
        'ruang' => $pg['ruang']
    ];
}

$backupRows = [];
$no_urut_tampil = 1;

$prev_nama = '';
$prev_merk = '';
$prev_kode = '';
$prev_harga = '';
$prev_tahun = '';

while($row = mysqli_fetch_assoc($qInv)){
    if ($prev_nama !== '') {
        if ($row['nama_jenis_barang'] == $prev_nama && 
            $row['merek_type'] == $prev_merk && 
            $row['kode_barang'] == $prev_kode && 
            $row['harga_satuan'] == $prev_harga && 
            $row['tahun_perolehan'] != $prev_tahun) {
            
            echo '<tr class="row-pembatas-tahun"><td colspan="17"></td></tr>';
        }
    }
    
    $prev_nama = $row['nama_jenis_barang'];
    $prev_merk = $row['merek_type'];
    $prev_kode = $row['kode_barang'];
    $prev_harga = $row['harga_satuan'];
    $prev_tahun = $row['tahun_perolehan'];

    $reg = $row['reg'];
    $vol_barang = $row['volume'];
    
    if(strpos($reg,'-') !== false){
        $pecah = explode('-',$reg);
        $awal = (int)$pecah[0]; $akhir = (int)$pecah[1];
    } else {
        $awal = (int)$reg; 
        $akhir = (int)$reg; 
    }
    
    for($i = $awal; $i <= $akhir; $i++){
        $no_reg = str_pad($i,3,"0",STR_PAD_LEFT);
        
        $no_reg_tampil = $no_reg; 
        $vol_paket = 1;
        $is_skip = false; 
        $tampilAksi = false;

        $prefill = ['nama_penerima'=>'','lokasi'=>'','keterangan'=>''];
        $parentKey = $row['nama_jenis_barang'].'||'.$row['kode_barang'].'||'.$row['tahun_perolehan'];

        if (isset($penyaluranGrouped[$parentKey])) {
            foreach($penyaluranGrouped[$parentKey] as $exist) {
                $regDb = $exist['no_reg'];
                
                if(strpos($regDb,'-') !== false){
                    list($start,$end) = explode('-',$regDb);
                    $startInt = (int)$start;
                    $endInt = (int)$end;
                    $current = (int)$no_reg;

                    if($current >= $startInt && $current <= $endInt){
                        if($current == $startInt){
                            $no_reg_tampil = $regDb; 
                            $vol_paket = ($endInt - $startInt) + 1;
                            $prefill = ['nama_penerima' => $exist['nama_penerima'], 'lokasi' => $exist['lokasi'], 'keterangan' => $exist['keterangan']];
                        } else {
                            $is_skip = true; 
                        }
                    }
                    if($no_reg_tampil == $regDb) $tampilAksi = true;
                } else {
                    if($regDb == $no_reg){
                        $prefill = ['nama_penerima' => $exist['nama_penerima'], 'lokasi' => $exist['lokasi'], 'keterangan' => $exist['keterangan']];
                        $tampilAksi = true;
                    }
                }
            }
        }

        if($is_skip) continue;
        
        $backupRows[] = [
            'nama_barang'=>$row['nama_jenis_barang'],
            'merek'=>$row['merek_type'],
            'kode'=>$row['kode_barang'],
            'tgl'=>$row['tanggal_perolehan'],
            'bln'=>$row['bulan_perolehan'],
            'thn'=>$row['tahun_perolehan'],
            'vol'=>$row['volume'],
            'sat'=>$row['satuan'],
            'harga_satuan'=>$row['harga_satuan'],
            'ket'=>$prefill['keterangan'],
            'no_reg'=>$no_reg_tampil,
            'lokasi'=>$prefill['lokasi'],
            'penerima'=>$prefill['nama_penerima']
        ];
?>
        <tr class="data-row-item"
            data-barang="<?= htmlspecialchars($row['nama_jenis_barang'], ENT_QUOTES, 'UTF-8') ?>"
            data-merk="<?= htmlspecialchars($row['merek_type'], ENT_QUOTES, 'UTF-8') ?>"
            data-kode="<?= htmlspecialchars($row['kode_barang'], ENT_QUOTES, 'UTF-8') ?>"
            data-vol="<?= $vol_barang ?>"
            data-no_reg="<?= htmlspecialchars($no_reg_tampil, ENT_QUOTES, 'UTF-8') ?>"
            data-bln="<?= htmlspecialchars($row['bulan_perolehan'], ENT_QUOTES, 'UTF-8') ?>"
            data-thn="<?= htmlspecialchars($row['tahun_perolehan'], ENT_QUOTES, 'UTF-8') ?>"
            data-harga="<?= $row['harga_satuan'] ?>">
            
            <td><?= $no_urut_tampil++ ?></td>
            
            <td>
                <input type="hidden" name="id_barang[]" value="<?= $row['id'] ?>">
                <input type="hidden" name="nama_barang[]" value="<?= htmlspecialchars($row['nama_jenis_barang'], ENT_QUOTES, 'UTF-8') ?>">
                <?= htmlspecialchars($row['nama_jenis_barang']) ?>
            </td>
            
            <td><input type="hidden" name="merk_tipe[]" value="<?= htmlspecialchars($row['merek_type'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($row['merek_type']) ?></td>
            <td><input type="hidden" name="kode_barang[]" value="<?= htmlspecialchars($row['kode_barang'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($row['kode_barang']) ?></td>
            <td><input type="hidden" name="tgl[]" value="<?= htmlspecialchars($row['tanggal_perolehan'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($row['tanggal_perolehan']) ?></td>
            <td><input type="hidden" name="bln[]" value="<?= htmlspecialchars($row['bulan_perolehan'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($row['bulan_perolehan']) ?></td>
            <td><input type="hidden" name="thn[]" value="<?= htmlspecialchars($row['tahun_perolehan'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($row['tahun_perolehan']) ?></td>
            <td><input type="hidden" name="vol_pengadaan[]" value="<?= htmlspecialchars($row['volume'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($row['volume']) ?></td>
            <td><input type="hidden" name="sat_pengadaan[]" value="<?= htmlspecialchars($row['satuan'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($row['satuan']) ?></td>
            <td><input type="hidden" name="harga_satuan[]" value="<?= htmlspecialchars($row['harga_satuan'], ENT_QUOTES, 'UTF-8') ?>"><?= number_format((float)$row['harga_satuan'],0,",",".") ?></td>
            
            <!-- KOLOM NAMA PENERIMA -->
            <td>
                <select class="form-select form-select-sm penerima" name="penerima[]" required>
                    <option value="">-- Pilih Penerima --</option>
                    <?php foreach($pegawaiList as $pgItem){ 
                        $selected = ($pgItem['nama'] == $prefill['nama_penerima']) ? 'selected' : '';
                    ?>
                        <option value="<?= htmlspecialchars($pgItem['nama'], ENT_QUOTES, 'UTF-8') ?>" data-ruang="<?= htmlspecialchars($pgItem['ruang'], ENT_QUOTES, 'UTF-8') ?>" <?= $selected ?>>
                            <?= htmlspecialchars($pgItem['nama']) ?>
                        </option>
                    <?php } ?>
                </select>
            </td>

            <!-- KOLOM NAMA RUANG -->
            <td>
                <select class="form-select form-select-sm lokasi" name="lokasi[]" required>
                    <option value="">-- Pilih Lokasi --</option>
                    <?php foreach($ruangNames as $namaRuang){ 
                        $selected = ($namaRuang == $prefill['lokasi']) ? 'selected' : '';
                    ?>
                        <option value="<?= htmlspecialchars($namaRuang, ENT_QUOTES, 'UTF-8') ?>" <?= $selected ?>>
                            <?= htmlspecialchars($namaRuang) ?>
                        </option>
                    <?php } ?>
                </select>
            </td>

            <td style="width: 65px; text-align: center; vertical-align: middle;">
                <input type="number" class="form-control form-control-sm vol_penyaluran text-center" name="vol_penyaluran[]" value="<?= $vol_paket ?>" readonly style="width: 50px; margin: 0 auto;">
            </td>

            <td class="cekRowCol"><input type="checkbox" class="cekRow"></td>

            <td><input type="hidden" name="no_reg[]" value="<?= htmlspecialchars($no_reg_tampil, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($no_reg_tampil) ?></td>

            <td>
                <input type="text" name="ket[]" class="form-control form-control-sm" value="<?= htmlspecialchars($prefill['keterangan'], ENT_QUOTES, 'UTF-8') ?>">
            </td>

            <td class="aksi-cell">
                <button type="button" class="btn-plus-aksi shadow-sm"><i class="bi bi-plus-lg"></i></button>
                <div class="aksi-mengambang">
                    <?php $status_saved = $tampilAksi ? '1' : '0'; ?>
                    
                    <button type="button"
                        class="btn btn-bast-custom btn-sm btn-aksi btn-open-bast"
                        data-saved="<?= $status_saved ?>">
                        <i class="bi bi-file-earmark-text"></i> BAST
                    </button>

                    <button type="button"
                        class="btn btn-fakta-custom btn-sm btn-aksi btn-open-fakta"
                        data-saved="<?= $status_saved ?>">
                        <i class="bi bi-file-earmark-check"></i> FAKTA
                    </button>
                    
                    <button type="button"
                        class="btn btn-edit-custom btn-sm btn-aksi btn-open-edit"
                        data-saved="<?= $status_saved ?>">
                        <i class="bi bi-pencil-square"></i> Edit
                    </button>

                    <button type="button"
                        class="btn btn-danger btn-sm btn-aksi btnRestart">
                        <i class="bi bi-arrow-counterclockwise"></i> Restart
                    </button>
                </div>
            </td>
        </tr>
<?php 
    } // Tutup For
} // Tutup While
?>
          </tbody>
        </table>
      </div>
    </div>
</div>

<!-- BARIS PAGINATION BAWAH -->
<div class="bottom-pagination-bar">
    <div class="page-size-control">
        <span>Tampilkan</span>
        <select id="pageSizeSelect">
            <option value="10">10</option>
            <option value="25" selected>25</option>
            <option value="50">50</option>
            <option value="100">100</option>
            <option value="all">Semua</option>
        </select>
        <span>data per halaman</span>
    </div>

    <div class="pagination-nav-pills" id="paginationControls"></div>

    <div style="width: 260px;" class="d-none d-xl-block"></div>
</div>

<!-- FLOATING BUTTONS KANAN BAWAH -->
<div class="actions">
    <button type="button" data-bs-toggle="modal" data-bs-target="#modalFilterCetak" class="btn-download-floating">
        <i class="bi bi-file-earmark-pdf-fill"></i>
        <span>REKAP PENYALURAN PDF</span>
    </button>

    <button type="button" id="btnSave" class="btn-save-floating">
        <i class="bi bi-save-fill"></i>
        <span>SIMPAN DATA</span>
    </button>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script>
// variabel state modal
let active_bast_saved = 1;
let active_fakta_saved = 1;
let bastNextNumberPadded = '001';
const kodeKlasifikasiBast = <?php echo json_encode($kode_klasifikasi_bast); ?>;

function formatNomorBast(paddedNum, bulan, tahun) {
    let suffix = kodeKlasifikasiBast ? ("/" + kodeKlasifikasiBast) : "";
    return paddedNum + suffix;
}

// simpan backup & existing DB
let backupRows = <?php echo json_encode($backupRows); ?>;
let existingRegs = <?php echo json_encode($existingRegs); ?>;

const existingMap = {};
for (let i = 0; i < existingRegs.length; i++) {
    let parts = existingRegs[i].split('||');
    let groupKey = parts[0] + '||' + parts[1] + '||' + parts[2];
    if (!existingMap[groupKey]) existingMap[groupKey] = [];
    existingMap[groupKey].push(parts[3]);
}

function disableDuplicateRows(){
    $("#tableInventaris tbody tr.data-row-item").each(function(){
        let nama_barang = $(this).attr("data-barang");
        let kode_barang = $(this).attr("data-kode");
        let tahun       = $(this).attr("data-thn");
        let no_reg      = $(this).attr("data-no_reg");

        let regs = existingMap[nama_barang + '||' + kode_barang + '||' + tahun];
        if (!regs) return;

        for(let i = 0; i < regs.length; i++){
            let regDb = regs[i];
            if(regDb.includes('-')){
                let range = regDb.split('-');
                let start = parseInt(range[0],10);
                let end = parseInt(range[1],10);
                let val = parseInt(no_reg,10);
                if(val>=start && val<=end){
                    $(this).find(".cekRow, .penerima, .vol_penyaluran, .lokasi, [name='ket[]']").prop("disabled", true);
                    $(this).addClass("disable-row");
                    break;
                }
            } else {
                if(no_reg === regDb){
                    $(this).find(".cekRow, .penerima, .vol_penyaluran, .lokasi, [name='ket[]']").prop("disabled", true);
                    $(this).addClass("disable-row");
                    break;
                }
            }
        }
    });
}

$("#btnPilihBaris").click(function(){
    $(".cekRowCol").slideToggle(200);
    $(".btn-paket").fadeToggle(200);
});

$("#buatPaket").click(function(){
    let rows = $("#tableInventaris tbody tr.data-row-item").filter(function(){
        return $(this).find(".cekRow").is(":checked") && !$(this).hasClass("disable-row");
    });

    if(rows.length < 2){ 
        alert("Pilih minimal 2 baris untuk digabung!"); 
        return; 
    }

    let first = rows.first();
    let refNama  = $.trim(first.attr("data-barang"));
    let refMerk  = $.trim(first.attr("data-merk"));
    let refKode  = $.trim(first.attr("data-kode"));
    let refHarga = String(first.attr("data-harga"));

    let bedaBarang = false;
    rows.each(function(){
        if(
            $.trim($(this).attr("data-barang")) != refNama ||
            $.trim($(this).attr("data-merk")) != refMerk ||
            $.trim($(this).attr("data-kode")) != refKode ||
            String($(this).attr("data-harga")) != refHarga
        ){
            bedaBarang = true;
            return false;
        }
    });

    if(bedaBarang){
        alert(
            "❌ Paket tidak dapat dibuat!\n\n" +
            "Nama Barang, Merk/Tipe, Kode Barang, dan Harga Satuan harus sama."
        );
        return;
    }

    let firstRowRef = rows.first();
    let refPenerima = firstRowRef.find(".penerima").val();
    let refLokasi = firstRowRef.find(".lokasi").val();
    let isDataConflict = false;

    rows.each(function() {
        let currentPenerima = $(this).find(".penerima").val();
        let currentLokasi = $(this).find(".lokasi").val();
        if (currentPenerima !== refPenerima || currentLokasi !== refLokasi) {
            isDataConflict = true;
            return false; 
        }
    });

    if (isDataConflict) {
        alert("❌ GAGAL!\nNama Penerima atau Lokasi tidak sama. Samakan dulu sebelum buat paket.");
        return; 
    }

    let no_regs = rows.map(function(){ 
        return parseInt($(this).find('input[name="no_reg[]"]').val(), 10); 
    }).get();
    no_regs.sort((a,b)=>a-b);
    
    let startReg = String(no_regs[0]).padStart(3,'0');
    let endReg = String(no_regs[no_regs.length-1]).padStart(3,'0');
    let paketRange = startReg + '-' + endReg;

    let firstRow = rows.first();
    firstRow.attr("data-no_reg", paketRange);
    let tdReg = firstRow.find('input[name="no_reg[]"]').closest('td');
    
    tdReg.html('<input type="hidden" name="no_reg[]" value="' + paketRange + '">' + paketRange);
    firstRow.find(".vol_penyaluran").val(rows.length);
    firstRow.addClass("table-info border-bottom-paket");
    firstRow.find(".cekRow").prop("checked", false);

    rows.not(firstRow).each(function(){
        let rowLain = $(this);
        rowLain.addClass("d-none is-paket-hidden");
        rowLain.find("input, select, textarea").each(function(){
            $(this).prop("disabled", true);
            $(this).attr("disabled", "disabled");
        });
        rowLain.addClass("disable-row");
    });

    reorderTableNumbers();
    renderPagination();
    saveDraftToLocal();

    alert("✅ Paket " + paketRange + " Berhasil Dibuat.");
});

function reorderTableNumbers() {
    let counter = 1;
    $("#tableInventaris tbody tr.data-row-item:not(.is-paket-hidden)").each(function() {
        $(this).find("td:first").text(counter++);
    });
}

function setGarisHijau(){
    let lastKey = "";
    let lastRow = null;

    $("#tableInventaris tbody tr.data-row-item:not(.is-paket-hidden):not(.filtered-out)").each(function(){
        let key =
            $.trim($(this).attr("data-barang") || "") + "|" +
            $.trim($(this).attr("data-merk") || "") + "|" +
            $.trim($(this).attr("data-harga") || "");

        $(this).removeClass("border-bottom-barang");

        if(lastKey !== "" && lastKey !== key && lastRow){
            lastRow.addClass("border-bottom-barang");
        }

        lastKey = key;
        lastRow = $(this);
    });
}

function initSelect2VisibleRows($rows) {
    let $parentWrapper = $('.table-card-wrapper');
    $rows.find('select.penerima, select.lokasi').each(function() {
        if (!$(this).hasClass('select2-hidden-accessible')) {
            $(this).select2({
                width: '100%',
                dropdownParent: $parentWrapper
            });
        }
    });
}

let currentPage = 1;
function renderPagination() {
    let pageSizeVal = $("#pageSizeSelect").val();
    let allRows = $("#tableInventaris tbody tr.data-row-item:not(.is-paket-hidden):not(.filtered-out)");
    let totalRows = allRows.length;

    setGarisHijau();

    if (pageSizeVal === "all") {
        allRows.show();
        initSelect2VisibleRows(allRows);
        $("#tableInventaris tbody tr.row-pembatas-tahun").show();
        $("#paginationControls").html('<button type="button" class="active">1</button>');
        return;
    }

    let pageSize = parseInt(pageSizeVal, 10) || 25;
    let totalPages = Math.max(1, Math.ceil(totalRows / pageSize));
    if (currentPage > totalPages) currentPage = totalPages;

    let startIdx = (currentPage - 1) * pageSize;
    let endIdx = startIdx + pageSize;

    allRows.hide();
    let visibleSlice = allRows.slice(startIdx, endIdx);
    visibleSlice.show();
    initSelect2VisibleRows(visibleSlice);

    $("#tableInventaris tbody tr.row-pembatas-tahun").each(function(){
        let nextDataRow = $(this).nextAll("tr.data-row-item").first();
        $(this).toggle(nextDataRow.length > 0 && nextDataRow.is(":visible"));
    });

    let navHtml = '';
    navHtml += `<button type="button" onclick="goToPage(1)" ${currentPage === 1 ? 'disabled' : ''}>&laquo;</button>`;
    navHtml += `<button type="button" onclick="goToPage(${currentPage - 1})" ${currentPage === 1 ? 'disabled' : ''}>&lsaquo;</button>`;

    let startPage = Math.max(1, currentPage - 2);
    let endPage = Math.min(totalPages, startPage + 4);
    if (endPage - startPage < 4) startPage = Math.max(1, endPage - 4);

    for (let p = startPage; p <= endPage; p++) {
        navHtml += `<button type="button" class="${p === currentPage ? 'active' : ''}" onclick="goToPage(${p})">${p}</button>`;
    }

    navHtml += `<button type="button" onclick="goToPage(${currentPage + 1})" ${currentPage === totalPages ? 'disabled' : ''}>&rsaquo;</button>`;
    navHtml += `<button type="button" onclick="goToPage(${totalPages})" ${currentPage === totalPages ? 'disabled' : ''}>&raquo;</button>`;

    $("#paginationControls").html(navHtml);
}

window.goToPage = function(p) {
    currentPage = p;
    renderPagination();
    $(".table-responsive").scrollTop(0);
};

$("#pageSizeSelect").change(function(){
    currentPage = 1;
    renderPagination();
});

function filterTable(){
    let barang = $.trim($("#filterBarang").val()).toLowerCase();
    let bln = $.trim($("#filterBulan").val());
    let thn = $.trim($("#filterTahun").val());

    $("#tableInventaris tbody tr.data-row-item").each(function(){
        let nama_barang = $.trim($(this).attr("data-barang") || "").toLowerCase();
        let merk_barang = $.trim($(this).attr("data-merk") || "").toLowerCase();
        let kode_barang = $.trim($(this).attr("data-kode") || "").toLowerCase();
        let rowBln = String($(this).attr("data-bln") || "").trim();
        let rowThn = String($(this).attr("data-thn") || "").trim();
        let show = true;

        if(barang && !nama_barang.includes(barang) && !merk_barang.includes(barang) && !kode_barang.includes(barang)) {
            show = false;
        }
        
        if(bln && rowBln !== bln) show = false;
        if(thn && rowThn !== thn) show = false;

        if(show) $(this).removeClass("filtered-out");
        else $(this).addClass("filtered-out");
    });

    currentPage = 1;
    renderPagination();
}

$("#filterTahun, #filterBulan").change(function(){
    let thn = $("#filterTahun").val();
    let bln = $("#filterBulan").val();
    
    let url = new URL(window.location.href);
    if(thn) url.searchParams.set('thn', thn); 
    else url.searchParams.delete('thn');
    
    if(bln) url.searchParams.set('bln', bln); 
    else url.searchParams.delete('bln');
    
    url.searchParams.set('page', 1);
    window.location.href = url.href;
});

$("#checkAll").change(function(){
    let checked = $(this).is(":checked");
    $("#tableInventaris tbody tr.data-row-item").each(function(){
        if(!$(this).hasClass("disable-row") && !$(this).hasClass("is-paket-hidden")){
            $(this).find(".cekRow").prop("checked", checked);
        }
    });
});

function saveDraftToLocal() {
    let drafts = {};
    $("#tableInventaris tbody tr.data-row-item").each(function() {
        if (!$(this).hasClass('disable-row')) {
            let key = $(this).attr("data-barang") + "_" + $(this).attr("data-no_reg") + "_" + $(this).attr("data-kode") + "_" + $(this).attr("data-thn");
            let loc = $(this).find(".lokasi").val();
            let pen = $(this).find(".penerima").val();
            let vol = $(this).find(".vol_penyaluran").val();
            let ket = $(this).find("input[name='ket[]']").val();
            
            if (loc || pen || ket) {
                drafts[key] = { lokasi: loc, penerima: pen, vol: vol, ket: ket };
            }
        }
    });
    localStorage.setItem('penyaluranDrafts', JSON.stringify(drafts));
}

function loadDraftFromLocal() {
    let raw = localStorage.getItem('penyaluranDrafts');
    if (!raw || raw === '{}') return;
    let drafts = JSON.parse(raw);
    $("#tableInventaris tbody tr.data-row-item").each(function() {
        if (!$(this).hasClass('disable-row')) {
            let key = $(this).attr("data-barang") + "_" + $(this).attr("data-no_reg") + "_" + $(this).attr("data-kode") + "_" + $(this).attr("data-thn");
            if (drafts[key]) {
                if (drafts[key].lokasi) $(this).find(".lokasi").val(drafts[key].lokasi);
                if (drafts[key].penerima) $(this).find(".penerima").val(drafts[key].penerima);
                if (drafts[key].vol) $(this).find(".vol_penyaluran").val(drafts[key].vol);
                if (drafts[key].ket) $(this).find("input[name='ket[]']").val(drafts[key].ket);
            }
        }
    });
}

$(document).on('change', '.lokasi, .penerima, .vol_penyaluran, input[name="ket[]"]', function() {
    saveDraftToLocal();
});

$(document).on('select2:opening select2:open select2:close', '#tableInventaris select.lokasi, #tableInventaris select.penerima', function() {
    let winScrollY = window.scrollY;
    let tblScrollTop = $('.table-responsive').scrollTop();
    setTimeout(function() {
        window.scrollTo(window.scrollX, winScrollY);
        $('.table-responsive').scrollTop(tblScrollTop);
    }, 0);
});

$("#btnSave").click(function(){
    let dataToSend = [];

    $("#tableInventaris tbody tr.data-row-item").each(function(){
        if(!$(this).hasClass("disable-row")){
            let rowData = {};
            $(this).find(":input").each(function(){
                let name = $(this).attr("name");
                if(name){
                    rowData[name.replace("[]","")] = $(this).val();
                }
            });
            dataToSend.push(rowData);
        }
    });

    if(dataToSend.length === 0){
        alert("Tidak ada data untuk disimpan!");
        return;
    }

    $.ajax({
        url: window.location.href,
        type: 'POST',
        contentType: 'application/json',
        data: JSON.stringify({ action: 'simpan_data_ajax', data: dataToSend }),
        success: function(res){
            res = res.trim();
            if(res === "success"){
                localStorage.removeItem('penyaluranDrafts');
                alert("Data berhasil disimpan!");
                location.reload();
            } else if(res.startsWith("duplicate")){
                let items = res.split("||")[1].split(",");
                alert("Baris berikut sudah ada di DB dan tidak disimpan:\n" + items.join("\n"));
                disableDuplicateRows();
            } else {
                alert("Server Response: " + res);
            }
        }
    });
});

// MENGELOLA FLOATING MENU AKSI
$(document).on('click', '.btn-plus-aksi', function(e) {
    e.stopPropagation();
    $('.btn-plus-aksi').not(this).removeClass('active');
    $('.aksi-mengambang').not($(this).siblings('.aksi-mengambang')).removeClass('show');
    
    $(this).toggleClass('active');
    $(this).siblings('.aksi-mengambang').toggleClass('show');
});

$(document).click(function() {
    $('.btn-plus-aksi').removeClass('active');
    $('.aksi-mengambang').removeClass('show');
});

// EVENT DELEGATION AMAN UNTUK TOMBOL BAST, FAKTA, EDIT, RESTART (ANTI KARAKTER KUTIP RUSAK)
$(document).on('click', '.btn-open-bast', function() {
    let $tr = $(this).closest('tr.data-row-item');
    let nama = $tr.attr('data-barang');
    let reg  = $tr.attr('data-no_reg');
    let kode = $tr.attr('data-kode');
    let thn  = $tr.attr('data-thn');
    let is_saved = parseInt($(this).attr('data-saved'), 10) || 0;
    setBastData(nama, reg, is_saved, kode, thn);
});

$(document).on('click', '.btn-open-fakta', function() {
    let $tr = $(this).closest('tr.data-row-item');
    let nama = $tr.attr('data-barang');
    let reg  = $tr.attr('data-no_reg');
    let kode = $tr.attr('data-kode');
    let thn  = $tr.attr('data-thn');
    let is_saved = parseInt($(this).attr('data-saved'), 10) || 0;
    setFaktaData(nama, reg, is_saved, kode, thn);
});

$(document).on('click', '.btn-open-edit', function() {
    let $tr = $(this).closest('tr.data-row-item');
    let nama = $tr.attr('data-barang');
    let reg  = $tr.attr('data-no_reg');
    let is_saved = parseInt($(this).attr('data-saved'), 10) || 0;
    setEditDataSafe(nama, reg, is_saved);
});

$(document).ready(function(){
    disableDuplicateRows();
    loadDraftFromLocal();
    renderPagination();

    $("#filterBarang").on("keyup", filterTable);

    $('#modalEditPenerima').on('shown.bs.modal', function () {
        $('#edit_penerima, #edit_lokasi').select2({
            dropdownParent: $('#modalEditPenerima'),
            width: '100%'
        });
    });

    $(document).on("click",".btnRestart",function(){
        if(!confirm("Restart penyaluran ini?")) return;

        let $tr = $(this).closest('tr.data-row-item');
        let barang = $tr.attr("data-barang");
        let reg    = $tr.attr("data-no_reg");

        $.ajax({
            url:"",
            type:"POST",
            data:{
                restart_penyaluran:1,
                nama_barang:barang,
                no_reg:reg
            },
            success:function(r){
                if(r.trim() =="success"){
                    alert("Penyaluran berhasil direstart.");
                    location.reload();
                }else{
                    alert(r);
                }
            }
        });
    });

    window.updatePenerima = function(){
        let nama_barang = $("#edit_nama_barang").val();
        let no_reg      = $("#edit_no_reg").val();
        let penerima    = $("#edit_penerima").val();
        let lokasi      = $("#edit_lokasi").val();
        if(!penerima){
            alert("Pilih penerima dulu!");
            return;
        }
        $.ajax({
            url: window.location.href,
            type: "POST",
            data: {
                action: "update_penerima",
                nama_barang: nama_barang,
                no_reg: no_reg,
                nama_penerima: penerima,
                lokasi: lokasi
            },
            success: function(res){
                if(res.trim() == "success"){
                    alert("Berhasil diupdate!");
                    location.reload();
                } else {
                    alert("Gagal update!");
                }
            }
        });
    }
});

function formatTanggalIndo(tglStr){
    if(!tglStr) return '-';
    let p = tglStr.split('-');
    if(p.length === 3) return p[2] + '/' + p[1] + '/' + p[0];
    return tglStr;
}

function escapeHtmlAttr(str) {
    return String(str || '')
        .replace(/&/g, '&amp;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;');
}

function setBastData(nama, reg, is_saved, kode = '', thn = ''){
    active_bast_saved = is_saved;

    $("#modal_nama").val(nama);
    $("#modal_reg").val(reg);
    $("#modal_kode").val(kode);
    $("#modal_thn").val(thn);

    $("#historyContainer").html('<div class="p-3 text-muted small">Loading...</div>');
    $("#modalBast input[name='nomor']").val(''); 

    $.ajax({
        url: window.location.href,
        type: "POST",
        data: {
            action: "get_history_bast",
            nama_barang: nama,
            no_reg: reg
        },
        success: function(res){
            let data = JSON.parse(res);
            let html = "";

            $.ajax({
                url: window.location.href,
                type: "POST",
                data: {
                    action: "get_nomor_bast"
                },
                success: function(lastNumber){
                    let nextNum = (parseInt(lastNumber, 10) || 0) + 1;
                    bastNextNumberPadded = String(nextNum).padStart(3, '0');

                    let tglInput = $("#modalBast input[name='tanggal']").val();
                    let bulan, tahun;
                    if (tglInput && tglInput.includes('-')) {
                        let pecah = tglInput.split('-');
                        tahun = pecah[0];
                        bulan = pecah[1];
                    } else {
                        let now = new Date();
                        bulan = String(now.getMonth()+1).padStart(2,'0');
                        tahun = now.getFullYear();
                    }

                    $("#modalBast input[name='nomor']").val(
                        formatNomorBast(bastNextNumberPadded, bulan, tahun)
                    );
                }
            });

            if(data.length == 0){
                html = "<div class='p-3 text-muted small'>Belum ada history BAST.</div>";
            } else {
                html += `<table class="table-history-modern">
                            <thead>
                                <tr>
                                    <th style="width:42px;">No.</th>
                                    <th>Nomor Surat</th>
                                    <th>Nama Penerima</th>
                                    <th>Tanggal</th>
                                    <th style="width:55px; text-align:center;">Aksi</th>
                                </tr>
                            </thead>
                            <tbody>`;

                data.forEach(function(row, idx){
                    let rowBarang = row.nama_barang || nama;
                    let rowReg    = row.no_reg || reg;
                    html += `
                        <tr>
                            <td>${idx + 1}</td>
                            <td class="text-break">${escapeHtmlAttr(row.nomor_surat)}</td>
                            <td class="text-break">${escapeHtmlAttr(row.nama_penerima || '-')}</td>
                            <td class="text-nowrap">${formatTanggalIndo(row.tanggal)}</td>
                            <td class="text-center">
                                <button 
                                    type="button"
                                    class="btn-eye-history btn-download-history-bast"
                                    title="Lihat / Download PDF"
                                    data-id="${escapeHtmlAttr(row.id_history)}"
                                    data-nama="${escapeHtmlAttr(rowBarang)}"
                                    data-reg="${escapeHtmlAttr(rowReg)}"
                                    data-nomor="${escapeHtmlAttr(row.nomor_surat)}"
                                    data-tanggal="${escapeHtmlAttr(row.tanggal)}">
                                    <i class="bi bi-eye-fill"></i>
                                </button>
                            </td>
                        </tr>
                    `;
                });

                html += `</tbody></table>`;
            }

            $("#historyContainer").html(html);
        }
    });

    $("#modalBast").modal('show');
}

$(document).on('click', '.btn-download-history-bast', function() {
    downloadHistoryPdf(
        $(this).attr('data-id'),
        $(this).attr('data-nama'),
        $(this).attr('data-reg'),
        $(this).attr('data-nomor'),
        $(this).attr('data-tanggal')
    );
});

$(document).on("change","#modalBast input[name='tanggal']",function(){
    let tgl = $(this).val();
    if(!tgl) return;

    let pecah = tgl.split("-");
    let tahun = pecah[0];
    let bulan = pecah[1];

    $("#modalBast input[name='nomor']").val(
        formatNomorBast(bastNextNumberPadded, bulan, tahun)
    );
});

function setFaktaData(nama_barang, no_reg, is_saved, kode = '', thn = ''){
    active_fakta_saved = is_saved;
    $("#fakta_nama").val(nama_barang);
    $("#fakta_reg").val(no_reg);
    $("#fakta_kode").val(kode);
    $("#fakta_thn").val(thn);

    loadHistoryFakta(nama_barang, no_reg);
    $("#modalFakta").modal('show');
}

function loadHistoryFakta(nama, reg){
    $("#fakta_nama").val(nama);
    $("#fakta_reg").val(reg);

    $("#historyFaktaContainer").html('<div class="p-3 text-muted small">Loading...</div>');
    $("#fakta_nomor").val('');

    $.ajax({
        url: window.location.href,
        type: "POST",
        data: {
            action: "get_history_fakta",
            nama_barang: nama,
            no_reg: reg
        },
        success: function(res){
            let data = JSON.parse(res);
            let html = "";

            $.ajax({
                url: window.location.href,
                type: "POST",
                data: {
                    action: "get_nomor_fakta"
                },
                success: function(lastNumber){
                    let nextNumber = (parseInt(lastNumber, 10) || 0) + 1;
                    let paddedNumber = String(nextNumber).padStart(3,'0');
                    let now = new Date();
                    let bulan = String(now.getMonth()+1).padStart(2,'0');
                    let tahun = now.getFullYear();

                    $("#fakta_nomor").val(
                        formatNomorBast(paddedNumber, bulan, tahun)
                    );
                }
            });

            if(data.length == 0){
                html = "<div class='p-3 text-muted' style='font-size:13.5px;'>Belum ada history.</div>";
            } else {
                html += `<table class="table-history-modern">
                            <thead>
                                <tr>
                                    <th style="width:42px;">No.</th>
                                    <th>Nomor Surat</th>
                                    <th>Nama Penerima</th>
                                    <th>Tanggal</th>
                                    <th style="width:55px; text-align:center;">Aksi</th>
                                </tr>
                            </thead>
                            <tbody>`;

                data.forEach(function(row, idx){
                    let rowBarang = row.nama_barang || nama;
                    let rowReg    = row.no_reg || reg;
                    html += `
                        <tr>
                            <td>${idx + 1}</td>
                            <td class="text-break">${escapeHtmlAttr(row.nomor_surat)}</td>
                            <td class="text-break">${escapeHtmlAttr(row.nama_penerima || '-')}</td>
                            <td class="text-nowrap">${formatTanggalIndo(row.tanggal)}</td>
                            <td class="text-center">
                                <button 
                                    type="button"
                                    class="btn-eye-history btn-download-history-fakta"
                                    title="Lihat / Download PDF"
                                    data-id="${escapeHtmlAttr(row.id_history)}"
                                    data-nama="${escapeHtmlAttr(rowBarang)}"
                                    data-reg="${escapeHtmlAttr(rowReg)}"
                                    data-nomor="${escapeHtmlAttr(row.nomor_surat)}"
                                    data-tanggal="${escapeHtmlAttr(row.tanggal)}">
                                    <i class="bi bi-eye-fill"></i>
                                </button>
                            </td>
                        </tr>
                    `;
                });

                html += `</tbody></table>`;
            }

            $("#historyFaktaContainer").html(html);
        }
    });
}

$(document).on('click', '.btn-download-history-fakta', function() {
    downloadHistoryFaktaPdf(
        $(this).attr('data-id'),
        $(this).attr('data-nama'),
        $(this).attr('data-reg'),
        $(this).attr('data-nomor'),
        $(this).attr('data-tanggal')
    );
});

function downloadHistoryFaktaPdf(id_history, nama, reg, nomor, tanggal){
    let url = "fakta_pdf.php?id_history=" + encodeURIComponent(id_history)
        + "&nama_barang=" + encodeURIComponent(nama)
        + "&no_reg=" + encodeURIComponent(reg)
        + "&nomor=" + encodeURIComponent(nomor)
        + "&tanggal=" + encodeURIComponent(tanggal);
    window.open(url,"_blank");
}

function generateFakta(){
    if (active_fakta_saved == 0) {
        $("#modalNotifBelumSalur").modal('show');
        return;
    }

    let nama = $.trim($("#fakta_nama").val());
    let reg  = $.trim($("#fakta_reg").val());

    let nama_penerima_tabel = "";
    $("#tableInventaris tbody tr.data-row-item").each(function(){
        let rowBarang = $.trim($(this).attr("data-barang"));
        let rowReg    = $.trim($(this).attr("data-no_reg"));

        if(rowBarang === nama && rowReg === reg){
            nama_penerima_tabel = $.trim($(this).find(".penerima").val());
        }
    });

    $.ajax({
        url: window.location.href,
        type: "POST",
        data: {
            action: "get_history_bast",
            nama_barang: nama,
            no_reg: reg
        },
        success: function(resBast) {
            let dataBast;
            try {
                dataBast = JSON.parse(resBast.trim());
            } catch(e) {
                dataBast = []; 
            }

            if (!dataBast || dataBast.length === 0) {
                $("#pesanError").text("Kamu harus men-generate BAST terlebih dahulu sebelum bisa Generate FAKTA!");
                $("#modalError").modal('show');
                return; 
            }

            let penerimaSkrgUpper = nama_penerima_tabel.toUpperCase();

            $.ajax({
                url: window.location.href,
                type: "POST",
                data: {
                    action: "get_history_fakta",
                    nama_barang: nama,
                    no_reg: reg
                },
                success: function(resFakta) {
                    let dataFakta;
                    try {
                        dataFakta = JSON.parse(resFakta.trim());
                    } catch(e) {
                        dataFakta = []; 
                    }

                    let countBast = 0;
                    for (let i = 0; i < dataBast.length; i++) {
                        let pBast = $.trim(dataBast[i].nama_penerima || "").toUpperCase();
                        if (pBast === penerimaSkrgUpper || penerimaSkrgUpper === "") countBast++;
                    }

                    let countFakta = 0;
                    for (let i = 0; i < dataFakta.length; i++) {
                        let pFakta = $.trim(dataFakta[i].nama_penerima || "").toUpperCase();
                        if (pFakta === penerimaSkrgUpper || penerimaSkrgUpper === "") countFakta++;
                    }

                    if (countBast === 0) {
                        $("#pesanError").text("BAST untuk penerima '" + nama_penerima_tabel + "' belum dibuat. Silahkan generate BAST untuk penerima tersebut terlebih dahulu!");
                        $("#modalError").modal('show');
                        return;
                    }

                    if (countBast <= countFakta) {
                        $("#pesanError").text("Kamu harus men-generate BAST TERBARU untuk '" + nama_penerima_tabel + "' terlebih dahulu! (BAST sebelumnya sudah pernah digunakan).");
                        $("#modalError").modal('show');
                        return;
                    }

                    let form = $("#formFakta").serialize();
                    $.post("", form + "&action=simpan_history_fakta", function(resSave){
                        resSave = resSave.trim();
                        if(resSave.startsWith("success")){
                            let nomor = resSave.split("||")[1] || $("#fakta_nomor").val();

                            window.open(
                                "fakta_pdf.php?nama_barang="+encodeURIComponent(nama)+
                                "&no_reg="+encodeURIComponent(reg)+
                                "&nomor="+encodeURIComponent(nomor),
                                "_blank"
                            );
                            loadHistoryFakta(nama,reg);
                        }else{
                            $("#pesanError").text("Gagal menyimpan history fakta.");
                            $("#modalError").modal('show');
                        }
                    });
                }
            });
        }
    });
}

function generateBast(){
    if (active_bast_saved == 0) {
        $("#modalNotifBelumSalur").modal('show');
        return;
    }

    let nama   = $("#modal_nama").val();
    let reg    = $("#modal_reg").val();
    let kode   = $("#modal_kode").val();
    let thn    = $("#modal_thn").val();
    let nomor  = $("#modalBast input[name='nomor']").val();
    let tanggal= $("#modalBast input[name='tanggal']").val();

    if(!nomor || !tanggal){
        alert("Nomor dan Tanggal wajib diisi!");
        return;
    }

    $.ajax({
        url: window.location.href,
        type: "POST",
        data: {
            action: "simpan_history_bast",
            nama_barang: nama,
            no_reg: reg,
            kode_barang: kode,
            thn_pengadaan: thn,
            nomor: nomor,
            tanggal: tanggal
        },
        success: function(res){
            if(res.trim() == "success"){
                window.open(
                    "bast_pdf.php?nama_barang=" + encodeURIComponent(nama) +
                    "&no_reg=" + encodeURIComponent(reg) +
                    "&nomor=" + encodeURIComponent(nomor) +
                    "&tanggal=" + encodeURIComponent(tanggal),
                    "_blank"
                );
                $("#modalBast").modal('hide');
            } else {
                alert("Gagal simpan history!");
            }
        }
    });
}

function downloadHistoryPdf(id_history, nama, reg, nomor, tanggal){
    window.open(
        "bast_pdf.php?id_history=" + encodeURIComponent(id_history) +
        "&nama_barang=" + encodeURIComponent(nama) +
        "&no_reg=" + encodeURIComponent(reg) +
        "&nomor=" + encodeURIComponent(nomor) +
        "&tanggal=" + encodeURIComponent(tanggal),
        "_blank"
    );
}

function setEditDataSafe(nama, reg, is_saved){
    if (is_saved == 0) {
        $("#modalNotifBelumSalur").modal('show');
        return;
    }
    setEditData(nama, reg);
    $("#modalEditPenerima").modal('show');
}

function setEditData(nama, reg){
    $("#edit_nama_barang").val(nama);
    $("#edit_no_reg").val(reg);
    $("#tableInventaris tbody tr.data-row-item").each(function(){
        if($(this).attr("data-barang") == nama && $(this).attr("data-no_reg") == reg){
            let penerima = $(this).find(".penerima").val();
            let lokasi   = $(this).find(".lokasi").val();

            $("#edit_penerima").val(penerima).trigger('change');
            $("#edit_lokasi").val(lokasi).trigger('change');
        }
    });
}

// --- LOGIKA MODAL FILTER CETAK PDF ---
function syncBatasanTahunAkhir() {
    let thnDari = $("#valFilterTahunDari").val();
    let semDari = $("#valFilterSemesterDari").val();
    let thnDariInt = parseInt(thnDari, 10);
    let $selectSampai = $("#valFilterTahunSampai");

    $selectSampai.find("option").each(function(){
        let val = $(this).val();
        if (!val) {
            $(this).prop("disabled", false).removeClass("d-none");
            return;
        }
        let optThn = parseInt(val, 10);
        if (!isNaN(thnDariInt) && optThn < thnDariInt) {
            $(this).prop("disabled", true).addClass("d-none");
        } else {
            $(this).prop("disabled", false).removeClass("d-none");
        }
    });

    // Reset jika nilai tahun akhir yang terpilih sebelumnya lebih kecil dari tahun awal
    let curSampai = parseInt($selectSampai.val(), 10);
    if (!isNaN(thnDariInt) && !isNaN(curSampai) && curSampai < thnDariInt) {
        $selectSampai.val("");
    }

    // Jika tahun awal & tahun akhir sama dan semester awal = 2, semester akhir tidak bisa pilih semester 1
    let thnSampaiVal = $selectSampai.val();
    let $semSampai = $("#valFilterSemesterSampai");
    if (thnDari && thnSampaiVal && thnDari === thnSampaiVal && semDari === "2") {
        $semSampai.find("option[value='1']").prop("disabled", true).addClass("d-none");
        if ($semSampai.val() === "1") {
            $semSampai.val("");
        }
    } else {
        $semSampai.find("option").prop("disabled", false).removeClass("d-none");
    }
}

function updateLabelAsalDroplist() {
    let $visibleItems = $(".item-asal:not(.d-none) .check-asal-item");
    let $checkedItems = $(".item-asal:not(.d-none) .check-asal-item:checked");
    let totalVisible = $visibleItems.length;
    let totalChecked = $checkedItems.length;

    $("#checkSemuaAsal").prop("checked", totalVisible > 0 && totalChecked === totalVisible);

    if (totalChecked === 0) {
        $("#labelAsalTerpilih").text("-- Pilih Sumber Perolehan --").removeClass("text-primary").addClass("text-dark");
    } else if (totalChecked === totalVisible) {
        $("#labelAsalTerpilih").text("Semua Sumber Perolehan (" + totalChecked + " dipilih)").removeClass("text-dark").addClass("text-primary");
    } else if (totalChecked === 1) {
        $("#labelAsalTerpilih").text($checkedItems.first().val()).removeClass("text-dark").addClass("text-primary");
    } else {
        $("#labelAsalTerpilih").text(totalChecked + " Sumber Perolehan dipilih").removeClass("text-dark").addClass("text-primary");
    }
}

function filterSumberPerolehanByRentang() {
    let thnDari = $("#valFilterTahunDari").val();
    let isRentang = $("#checkRentangWaktu").is(":checked");
    let thnSampai = isRentang ? $("#valFilterTahunSampai").val() : thnDari;

    $(".item-asal").each(function(){
        let listTahun = ($(this).attr("data-tahun") || "").split(",").map(s => s.trim()).filter(Boolean);
        
        if (!thnDari) {
            $(this).removeClass("d-none");
            return;
        }

        let minThn = parseInt(thnDari, 10);
        let maxThn = thnSampai ? parseInt(thnSampai, 10) : minThn;
        if (maxThn < minThn) {
            maxThn = minThn;
        }

        let cocok = listTahun.some(t => {
            let tInt = parseInt(t, 10);
            return tInt >= minThn && tInt <= maxThn;
        });

        if (cocok) {
            $(this).removeClass("d-none");
        } else {
            $(this).addClass("d-none");
            $(this).find(".check-asal-item").prop("checked", false);
        }
    });

    updateLabelAsalDroplist();
}

$(document).ready(function(){
    // Toggle aktif/nonaktif rentang tahun & semester
    $("#checkRentangWaktu").change(function(){
        if ($(this).is(":checked")) {
            $("#wrapRentangSampai").removeClass("d-none");
            $("#labelPeriodeDari").html('<i class="bi bi-calendar-event me-1"></i> Dari Tahun & Semester');
            syncBatasanTahunAkhir();
        } else {
            $("#wrapRentangSampai").addClass("d-none");
            $("#valFilterTahunSampai").val("");
            $("#valFilterSemesterSampai").val("");
            $("#labelPeriodeDari").html('<i class="bi bi-calendar-event me-1"></i> Tahun & Semester');
        }
        filterSumberPerolehanByRentang();
    });

    $("#valFilterTahunDari, #valFilterSemesterDari, #valFilterTahunSampai, #valFilterSemesterSampai").change(function(){
        syncBatasanTahunAkhir();
        filterSumberPerolehanByRentang();
    });

    // Toggle buka/tutup droplist Sumber Perolehan
    $("#btnToggleAsalDropdown").click(function(e){
        e.stopPropagation();
        $("#panelAsalDropdown").toggleClass("d-none");
        $("#iconAsalDropdown").toggleClass("bi-chevron-down bi-chevron-up");
    });

    $("#panelAsalDropdown").click(function(e){
        e.stopPropagation();
    });

    $(document).on("click", function(e){
        if (!$(e.target).closest("#wrapFilterAsalCheck").length) {
            $("#panelAsalDropdown").addClass("d-none");
            $("#iconAsalDropdown").removeClass("bi-chevron-up").addClass("bi-chevron-down");
        }
    });

    $("#checkSemuaAsal").change(function(){
        let isChecked = $(this).is(":checked");
        $(".item-asal:not(.d-none) .check-asal-item").prop("checked", isChecked);
        updateLabelAsalDroplist();
    });

    $(".check-asal-item").change(function(){
        updateLabelAsalDroplist();
    });

    // Inisialisasi awal filter modal
    syncBatasanTahunAkhir();
    filterSumberPerolehanByRentang();
});

function prosesCetakPDF(){
    let id_sekolah = "<?= $id_sekolah ?>";
    let url = "buku_salur_pdf.php?id_sekolah=" + encodeURIComponent(id_sekolah);

    let thnDari     = $("#valFilterTahunDari").val();
    let semDari     = $("#valFilterSemesterDari").val();
    let isRentang   = $("#checkRentangWaktu").is(":checked");
    let thnSampai   = isRentang ? $("#valFilterTahunSampai").val() : thnDari;
    let semSampai   = isRentang ? $("#valFilterSemesterSampai").val() : semDari;

    if (isRentang) {
        if (!thnDari || !thnSampai) {
            alert("Harap pilih Tahun Awal dan Tahun Akhir rentang terlebih dahulu!");
            return;
        }
        if (parseInt(thnSampai, 10) < parseInt(thnDari, 10)) {
            alert("Tahun Akhir tidak boleh lebih kecil dari Tahun Awal!");
            return;
        }
        let startScore = (parseInt(thnDari, 10) * 10) + (semDari === "2" ? 2 : 1);
        let endScore   = (parseInt(thnSampai, 10) * 10) + (semSampai === "1" ? 1 : 2);
        if (endScore < startScore) {
            alert("Rentang Tahun/Semester Akhir tidak boleh lebih kecil dari Tahun/Semester Awal!");
            return;
        }
    }

    let selectedAsal = [];
    $(".item-asal:not(.d-none) .check-asal-item:checked").each(function(){
        selectedAsal.push($(this).val());
    });

    let isBerdasarkanRuang = $("#checkBerdasarkanRuang").is(":checked");
    let opsiRuang = isBerdasarkanRuang ? "ya" : "tidak";
    let valRuang  = "";

    let gabunganAsal = selectedAsal.join(",");

    let filterMode = "semua";
    if (isBerdasarkanRuang && thnDari === "" && selectedAsal.length === 0) {
        filterMode = "ruang";
    } else if (thnDari !== "" || selectedAsal.length > 0 || isBerdasarkanRuang) {
        filterMode = "tahun_sumber";
    }

    url += "&filter=" + encodeURIComponent(filterMode)
        + "&rentang=" + (isRentang ? "1" : "0")
        + "&tahun=" + encodeURIComponent(thnDari)
        + "&semester=" + encodeURIComponent(semDari)
        + "&tahun_dari=" + encodeURIComponent(thnDari)
        + "&semester_dari=" + encodeURIComponent(semDari)
        + "&tahun_sampai=" + encodeURIComponent(thnSampai)
        + "&semester_sampai=" + encodeURIComponent(semSampai)
        + "&asal=" + encodeURIComponent(gabunganAsal)
        + "&berdasarkan_ruang=" + encodeURIComponent(opsiRuang)
        + "&ruang=" + encodeURIComponent(valRuang);
    
    window.open(url, "_blank");
    $("#modalFilterCetak").modal('hide');
}

let searchTimer;
$("#filterBarang").on("input", function(){
    clearTimeout(searchTimer);
    filterTable();
    let q = $.trim($(this).val());
    searchTimer = setTimeout(function(){
        let url = new URL(window.location.href);
        if(q) url.searchParams.set('q', q);
        else url.searchParams.delete('q');
        window.history.replaceState({}, '', url.href);
    }, 300);
});
</script>

<!-- ================= MODAL GENERATE BAST ================= -->
<div class="modal fade modal-custom-modern" id="modalBast" tabindex="-1">
<div class="modal-dialog modal-lg modal-dialog-centered">
<div class="modal-content">

<form id="formBast">

<div class="header-bast-modern">
    <svg style="position:absolute; right:0; top:0; height:100%; width:260px; pointer-events:none; z-index:1;" viewBox="0 0 260 90" preserveAspectRatio="none">
        <path d="M90,0 C140,45 190,15 260,65 L260,0 Z" fill="rgba(255,255,255,0.12)"></path>
        <path d="M0,90 C90,85 160,30 260,15 L260,90 Z" fill="rgba(4,78,44,0.35)"></path>
    </svg>
    <div class="header-content">
        <div class="header-icon-box">
            <i class="bi bi-file-earmark-text"></i>
        </div>
        <div>
            <h5 class="mb-0 fw-bold" style="font-size: 19px;">Generate BAST</h5>
            <small style="color: rgba(255,255,255,0.88); font-size: 12.5px;">Buat Berita Acara Serah Terima (BAST)</small>
        </div>
    </div>
    <button type="button" class="btn-close btn-close-white position-relative" style="z-index:2;" data-bs-dismiss="modal"></button>
</div>

<div class="modal-body">
<div class="row g-3">

    <!-- Kolom Kiri: History BAST -->
    <div class="col-md-6">
        <div class="modal-split-card">
            <div class="modal-split-card-top-green">
                <div class="d-flex align-items-center gap-2 fw-bold" style="color: #065f46; font-size: 15px;">
                    <i class="bi bi-clock-history"></i>
                    <span>History BAST</span>
                </div>
                <div class="text-muted" style="font-size: 12px; margin-top: 2px;">Daftar BAST yang sudah dibuat sebelumnya.</div>
            </div>
            <div id="historyContainer" style="max-height: 230px; overflow-y: auto;">
                <div class="p-3 text-muted small">Belum ada history BAST.</div>
            </div>
        </div>
    </div>

    <!-- Kolom Kanan: Data BAST -->
    <div class="col-md-6">
        <div class="modal-split-card">
            <div class="modal-split-card-top-blue">
                <div class="d-flex align-items-center gap-2 fw-bold" style="color: #072f5f; font-size: 15px;">
                    <i class="bi bi-file-earmark-text text-primary"></i>
                    <span>Data BAST</span>
                </div>
            </div>
            <div class="p-3">
                <input type="hidden" name="nama_barang" id="modal_nama">
                <input type="hidden" name="no_reg" id="modal_reg">
                <input type="hidden" name="kode_barang" id="modal_kode">
                <input type="hidden" name="thn_pengadaan" id="modal_thn">

                <div class="mb-3">
                    <label class="form-label fw-bold text-dark" style="font-size: 13px;">Nomor Surat <span class="text-danger">*</span></label>
                    <div class="input-icon-wrapper">
                        <span class="field-icon"><i class="bi bi-file-earmark-text"></i></span>
                        <input type="text" name="nomor" class="form-control" readonly required>
                    </div>
                </div>

                <div class="mb-1">
                    <label class="form-label fw-bold text-dark" style="font-size: 13px;">Tanggal <span class="text-danger">*</span></label>
                    <div class="input-icon-wrapper">
                        <span class="field-icon"><i class="bi bi-calendar-event"></i></span>
                        <input type="date" name="tanggal" class="form-control" required>
                    </div>
                </div>
            </div>
        </div>
    </div>

</div>
</div>

<div class="modal-footer">
    <button type="button" class="btn btn-modal-tutup" data-bs-dismiss="modal">
        <i class="bi bi-x-lg"></i> Tutup
    </button>

    <button type="button" onclick="generateBast()" class="btn btn-modal-bast-submit">
        <i class="bi bi-file-earmark-pdf"></i> Generate BAST
    </button>
</div>

</form>

</div>
</div>
</div>

<!-- ================= MODAL GENERATE FAKTA INTEGRITAS ================= -->
<div class="modal fade modal-custom-modern" id="modalFakta" tabindex="-1">
<div class="modal-dialog modal-dialog-centered">
<div class="modal-content">

<form id="formFakta">

<div class="header-fakta-modern">
    <svg style="position:absolute; right:0; top:0; height:100%; width:320px; pointer-events:none; z-index:1;" viewBox="0 0 320 75" preserveAspectRatio="none">
        <path d="M60,75 C140,65 200,15 320,10 L320,75 Z" fill="rgba(245, 158, 11, 0.35)"></path>
    </svg>
    <div class="header-content">
        <i class="bi bi-file-earmark-text" style="font-size: 24px; color: #0f172a;"></i>
        <h5 class="mb-0 fw-bold" style="font-size: 19px; color: #0f172a;">Generate FAKTA INTEGRITAS</h5>
    </div>
    <button type="button" class="btn-close position-relative" style="z-index:2;" data-bs-dismiss="modal"></button>
</div>

<div class="modal-body">
    <input type="hidden" name="nama_barang" id="fakta_nama">
    <input type="hidden" name="no_reg" id="fakta_reg">
    <input type="hidden" name="kode_barang" id="fakta_kode">
    <input type="hidden" name="thn_pengadaan" id="fakta_thn">
    <input type="hidden" name="nomor" id="fakta_nomor">

    <div class="modal-split-card">
        <div class="modal-split-card-top-yellow">
            <div class="d-flex align-items-center gap-2 fw-bold" style="color: #0f172a; font-size: 15px;">
                <i class="bi bi-clock text-warning"></i>
                <span>History FAKTA</span>
            </div>
        </div>
        <div id="historyFaktaContainer" style="max-height: 240px; overflow-y: auto; min-height: 110px;">
            <div class="p-3 text-muted" style="font-size: 13.5px;">Belum ada history.</div>
        </div>
    </div>
</div>

<div class="modal-footer">
    <button type="button" class="btn btn-modal-tutup" data-bs-dismiss="modal">
        <i class="bi bi-x-lg"></i> Tutup
    </button>

    <button type="button" onclick="generateFakta()" class="btn btn-modal-fakta-submit">
        <i class="bi bi-file-earmark-pdf"></i> Generate PDF
    </button>
</div>

</form>

</div>
</div>
</div>

<!-- ================= MODAL EDIT NAMA PENERIMA ================= -->
<div class="modal fade modal-custom-modern" id="modalEditPenerima" tabindex="-1">
<div class="modal-dialog modal-dialog-centered">
<div class="modal-content">

<div class="header-edit-modern">
    <svg style="position:absolute; right:0; top:0; height:100%; width:240px; pointer-events:none; z-index:1;" viewBox="0 0 240 85" preserveAspectRatio="none">
        <path d="M20,85 C90,70 140,25 240,20 L240,85 Z" fill="rgba(56, 189, 248, 0.35)"></path>
    </svg>
    <div class="header-content">
        <i class="bi bi-pencil-square" style="font-size: 28px; color: #ffffff;"></i>
        <div class="header-edit-divider"></div>
        <div>
            <h5 class="mb-0 fw-bold" style="font-size: 19px;">Edit Nama Penerima</h5>
            <small style="color: rgba(255,255,255,0.9); font-size: 12.5px;">Ubah data penerima dan lokasi penyaluran.</small>
        </div>
    </div>
    <button type="button" class="btn-close btn-close-white position-relative" style="z-index:2;" data-bs-dismiss="modal"></button>
</div>

<div class="modal-body">

    <input type="hidden" id="edit_nama_barang">
    <input type="hidden" id="edit_no_reg">

    <div class="mb-3">
        <label class="form-label fw-bold text-dark d-flex align-items-center gap-2" style="font-size: 14px;">
            <i class="bi bi-people-fill text-primary"></i>
            <span>Nama Penerima <span class="text-danger">*</span></span>
        </label>
        <select id="edit_penerima" class="form-select">
            <option value="">-- Pilih Penerima --</option>
            <?php foreach($pegawai as $pg){ ?>
                <option value="<?= htmlspecialchars(strtoupper($pg['nama_lengkap']), ENT_QUOTES, 'UTF-8') ?>">
                    <?= htmlspecialchars(strtoupper($pg['nama_lengkap'])) ?>
                </option>
            <?php } ?>
        </select>
    </div>

    <div class="mb-2">
        <label class="form-label fw-bold text-dark d-flex align-items-center gap-2" style="font-size: 14px;">
            <i class="bi bi-geo-alt-fill text-primary"></i>
            <span>Ruangan <span class="text-danger">*</span></span>
        </label>
        <select id="edit_lokasi" class="form-select">
            <option value="">-- Pilih Ruangan --</option>
            <?php foreach($ruang as $rg){ ?>
                <option value="<?= htmlspecialchars($rg['nama_ruang'], ENT_QUOTES, 'UTF-8') ?>">
                    <?= htmlspecialchars($rg['nama_ruang']) ?>
                </option>
            <?php } ?>
        </select>
    </div>

</div>

<div class="modal-footer">
    <button type="button" class="btn btn-modal-tutup" data-bs-dismiss="modal">
        <i class="bi bi-x-lg"></i> Batal
    </button>

    <button type="button" onclick="updatePenerima()" class="btn btn-modal-edit-submit">
        <i class="bi bi-save"></i> Simpan Perubahan
    </button>
</div>

</div>
</div>
</div>

<!-- MODAL NOTIFIKASI BELUM DISALURKAN -->
<div class="modal fade" id="modalNotifBelumSalur" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content border-0 shadow">
            <div class="modal-body text-center p-4">
                <i class="bi bi-exclamation-triangle-fill text-warning mb-3" style="font-size: 3rem;"></i>
                <h6 class="fw-bold">Belum Disalurkan!</h6>
                <p class="mb-0 text-muted" style="font-size: 13px;">Silahkan lakukan penyaluran dan "SIMPAN DATA" terlebih dahulu.</p>
            </div>
            <div class="modal-footer justify-content-center border-0 pt-0">
                <button type="button" class="btn btn-secondary btn-sm w-100" data-bs-dismiss="modal">Mengerti</button>
            </div>
        </div>
    </div>
</div>

<!-- MODAL NOTIFIKASI ERROR/GAGAL -->
<div class="modal fade" id="modalError" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content border-0 shadow">
            <div class="modal-body text-center p-4">
                <i class="bi bi-x-circle-fill text-danger mb-3" style="font-size: 3rem;"></i>
                <h6 class="fw-bold">GAGAL!</h6>
                <p class="mb-0 text-muted" id="pesanError" style="font-size: 13px;">Pesan error akan muncul di sini.</p>
            </div>
            <div class="modal-footer justify-content-center border-0 pt-0">
                <button type="button" class="btn btn-secondary btn-sm w-100" data-bs-dismiss="modal">Tutup</button>
            </div>
        </div>
    </div>
</div>

<!-- MODAL FILTER CETAK PDF -->
<div class="modal fade modal-custom-modern" id="modalFilterCetak" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            
            <div class="header-filter-blue">
                <svg style="position:absolute; right:0; top:0; height:100%; width:240px; pointer-events:none; z-index:1;" viewBox="0 0 240 85" preserveAspectRatio="none">
                    <path d="M40,0 C110,45 170,15 240,60 L240,0 Z" fill="rgba(255,255,255,0.12)"></path>
                    <path d="M10,85 C90,70 150,20 240,15 L240,85 Z" fill="rgba(56, 189, 248, 0.25)"></path>
                </svg>
                <div class="header-content">
                    <div class="header-icon-circle">
                        <i class="bi bi-printer-fill"></i>
                    </div>
                    <div>
                        <h5 class="modal-title fw-bold mb-0" style="font-size: 17px;">Filter Cetak PDF</h5>
                        <small style="color: rgba(255,255,255,0.85); font-size: 12px;">Rekap Penyaluran Belanja Modal</small>
                    </div>
                </div>
                <button type="button" class="btn-close btn-close-white position-relative" style="z-index:2;" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            
            <div class="modal-body p-4">
                <!-- 1. PERIODE TAHUN & SEMESTER + CHECKBOX AKTIF RENTANG -->
                <div class="mb-3">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <label id="labelPeriodeDari" class="form-label text-uppercase text-primary fw-bold small mb-0">
                            <i class="bi bi-calendar-event me-1"></i> Tahun & Semester
                        </label>
                        <div class="form-check mb-0">
                            <input class="form-check-input" type="checkbox" id="checkRentangWaktu">
                            <label class="form-check-label fw-bold text-primary small" style="cursor:pointer;" for="checkRentangWaktu">
                                Aktifkan Rentang Waktu
                            </label>
                        </div>
                    </div>

                    <div class="row g-2">
                        <div class="col-md-6">
                            <select id="valFilterTahunDari" class="form-select py-2 shadow-none fw-semibold">
                                <option value="">Semua Tahun</option>
                                <?php foreach($tahun_list as $thn): ?>
                                    <option value="<?= htmlspecialchars($thn) ?>"><?= htmlspecialchars($thn) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <select id="valFilterSemesterDari" class="form-select py-2 shadow-none fw-semibold">
                                <option value="">Semua Semester (Jan–Des)</option>
                                <option value="1">Semester 1 (Jan–Jun)</option>
                                <option value="2">Semester 2 (Jul–Des)</option>
                            </select>
                        </div>
                    </div>
                </div>

                <!-- RENTANG AKHIR (MUNCUL JIKA CHECKBOX RENTANG WAKTU DI-CEKLIS) -->
                <div class="mb-3 d-none" id="wrapRentangSampai">
                    <label class="form-label text-uppercase text-primary fw-bold small mb-2">
                        <i class="bi bi-calendar-range me-1"></i> Sampai Tahun & Semester
                    </label>
                    <div class="row g-2">
                        <div class="col-md-6">
                            <select id="valFilterTahunSampai" class="form-select py-2 shadow-none fw-semibold">
                                <option value="">-- Pilih Tahun Akhir --</option>
                                <?php foreach($tahun_list as $thn): ?>
                                    <option value="<?= htmlspecialchars($thn) ?>"><?= htmlspecialchars($thn) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <select id="valFilterSemesterSampai" class="form-select py-2 shadow-none fw-semibold">
                                <option value="">Semua Semester (Jan–Des)</option>
                                <option value="1">Semester 1 (Jan–Jun)</option>
                                <option value="2">Semester 2 (Jul–Des)</option>
                            </select>
                        </div>
                    </div>
                </div>
                
                <!-- 2. SUMBER PEROLEHAN (DROPLIST -> KLIK MUNCUL CHECKBOX) -->
                <div class="mb-3 position-relative" id="wrapFilterAsalCheck">
                    <label class="form-label text-uppercase text-primary fw-bold small mb-2">
                        <i class="bi bi-box-seam me-1"></i> Sumber Perolehan
                    </label>
                    <div id="btnToggleAsalDropdown" class="asal-droplist-trigger d-flex justify-content-between align-items-center px-3 py-2 bg-white" style="cursor:pointer;">
                        <span id="labelAsalTerpilih" class="fw-semibold text-dark" style="font-size: 14px;">-- Pilih Sumber Perolehan --</span>
                        <i class="bi bi-chevron-down text-secondary" id="iconAsalDropdown"></i>
                    </div>
                    <div id="panelAsalDropdown" class="asal-check-box p-3 shadow-sm mt-1 d-none" style="max-height: 165px; overflow-y: auto; background: #f0f7ff;">
                        <div class="form-check mb-2 pb-2 border-bottom border-primary-subtle">
                            <input class="form-check-input" type="checkbox" id="checkSemuaAsal" value="semua">
                            <label class="form-check-label fw-bold text-dark" style="cursor:pointer;" for="checkSemuaAsal">Pilih Semua</label>
                        </div>
                        <?php foreach($asal_by_tahun as $asal => $tahun_arr): 
                            $list_tahun = implode(',', $tahun_arr);
                        ?>
                            <div class="form-check mb-1 item-asal" data-tahun="<?= htmlspecialchars($list_tahun) ?>">
                                <input class="form-check-input check-asal-item" type="checkbox" id="asal_<?= md5($asal) ?>" value="<?= htmlspecialchars($asal) ?>">
                                <label class="form-check-label text-dark fw-medium" style="cursor:pointer;" for="asal_<?= md5($asal) ?>">
                                    <?= htmlspecialchars($asal) ?>
                                </label>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- 3. BERDASARKAN RUANG ATAU TIDAK (CUKUP CEKLIS SAJA) -->
                <div class="mb-1">
                    <label class="form-label text-uppercase text-primary fw-bold small mb-2">
                        <i class="bi bi-geo-alt-fill me-1"></i> Pengelompokan / Filter Ruang
                    </label>
                    <div class="asal-check-box p-3 shadow-sm" style="background: #f8fbff;">
                        <div class="form-check mb-0">
                            <input class="form-check-input" type="checkbox" id="checkBerdasarkanRuang" value="ya">
                            <label class="form-check-label fw-semibold text-dark" style="cursor:pointer;" for="checkBerdasarkanRuang">
                                Berdasarkan Ruang
                            </label>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="modal-footer px-4 py-3">
                <button type="button" class="btn btn-light border px-3 py-2 rounded-3 text-secondary fw-semibold small" data-bs-dismiss="modal">
                    Batal
                </button>
                <button type="button" class="btn btn-modal-edit-submit px-4 py-2 small" onclick="prosesCetakPDF()">
                    <i class="bi bi-file-earmark-pdf-fill"></i>
                    <span>Generate PDF</span>
                </button>
            </div>

        </div>
    </div>
</div>
</body>
</html>