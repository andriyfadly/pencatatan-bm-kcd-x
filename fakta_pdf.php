<?php
ob_start();
session_start();
error_reporting(0);

require_once('tcpdf/tcpdf.php');
include "koneksi.php";

/* =========================
   AMBIL PARAMETER & SESSION
========================= */
$id_sekolah  = $_SESSION['id_sekolah'] ?? '';
$id_history  = mysqli_real_escape_string($conn, $_GET['id_history'] ?? '');
$nama_barang = mysqli_real_escape_string($conn, $_GET['nama_barang'] ?? '');
$no_reg      = mysqli_real_escape_string($conn, $_GET['no_reg'] ?? '');
$nomor       = mysqli_real_escape_string($conn, $_GET['nomor'] ?? '');
$tanggalFull = mysqli_real_escape_string($conn, $_GET['tanggal'] ?? date('Y-m-d'));

if(empty($id_sekolah)){
    die("Sesi login berakhir, silakan login kembali.");
}

if(empty($id_history) && (empty($nama_barang) || empty($no_reg))){
    die("Parameter tidak lengkap.");
}

/* =========================
   AMBIL DATA HISTORY / SNAPSHOT LEBIH DULU
========================= */
if (!empty($id_history)) {
    $q_history = mysqli_query($conn,"
        SELECT * FROM history_fakta
        WHERE id_history='$id_history'
          AND id_sekolah='$id_sekolah'
        LIMIT 1
    ");
} else {
    $q_history = mysqli_query($conn,"
        SELECT * FROM history_fakta
        WHERE id_sekolah = '$id_sekolah'
        AND nama_barang='$nama_barang'
        " . (!empty($nomor) ? " AND nomor_surat='$nomor' " : "") . "
        AND (
            TRIM(no_reg) = TRIM('$no_reg')
            OR (
                INSTR(no_reg, '-') > 0
                AND CAST(SUBSTRING_INDEX('$no_reg','-',1) AS UNSIGNED) >= CAST(SUBSTRING_INDEX(no_reg,'-',1) AS UNSIGNED)
                AND CAST(SUBSTRING_INDEX('$no_reg','-',-1) AS UNSIGNED) <= CAST(SUBSTRING_INDEX(no_reg,'-',-1) AS UNSIGNED)
            )
            OR (
                INSTR('$no_reg', '-') > 0
                AND CAST(SUBSTRING_INDEX(no_reg,'-',1) AS UNSIGNED) >= CAST(SUBSTRING_INDEX('$no_reg','-',1) AS UNSIGNED)
                AND CAST(SUBSTRING_INDEX(no_reg,'-',-1) AS UNSIGNED) <= CAST(SUBSTRING_INDEX('$no_reg','-',-1) AS UNSIGNED)
            )
        )
        ORDER BY id_history DESC
        LIMIT 1
    ");
}

$history = mysqli_fetch_assoc($q_history);

if ($history) {
    if (!empty($history['nama_barang'])) {
        $nama_barang = mysqli_real_escape_string($conn, $history['nama_barang']);
    }
    if (!empty($history['no_reg'])) {
        $no_reg = mysqli_real_escape_string($conn, $history['no_reg']);
    }
}

$nama_penerima_snapshot = $history['nama_penerima'] ?? '';
$nama_penerima_esc      = mysqli_real_escape_string($conn, $nama_penerima_snapshot);

/* ============================================================================
   AMBIL DATA BARANG (AKTIF & SUDAH DIKEMBALIKAN)
========================= */
// 1. Cek dari tabel penyaluran (jika masih disalurkan)
$q_barang = mysqli_query($conn,"
    SELECT * FROM penyaluran 
    WHERE id_sekolah = '$id_sekolah'
    AND nama_barang='$nama_barang'
    AND (
        TRIM(no_reg) = TRIM('$no_reg')
        OR (
            INSTR(no_reg, '-') > 0
            AND CAST(SUBSTRING_INDEX('$no_reg','-',1) AS UNSIGNED) >= CAST(SUBSTRING_INDEX(no_reg,'-',1) AS UNSIGNED)
            AND CAST(SUBSTRING_INDEX('$no_reg','-',-1) AS UNSIGNED) <= CAST(SUBSTRING_INDEX(no_reg,'-',-1) AS UNSIGNED)
        )
        OR (
            INSTR('$no_reg', '-') > 0
            AND CAST(SUBSTRING_INDEX(no_reg,'-',1) AS UNSIGNED) >= CAST(SUBSTRING_INDEX('$no_reg','-',1) AS UNSIGNED)
            AND CAST(SUBSTRING_INDEX(no_reg,'-',-1) AS UNSIGNED) <= CAST(SUBSTRING_INDEX('$no_reg','-',-1) AS UNSIGNED)
        )
    )
    ORDER BY (nama_penerima = '$nama_penerima_esc') DESC, id_penyaluran DESC
    LIMIT 1
");
$barang = mysqli_fetch_assoc($q_barang);

// 2. Jika sudah dikembalikan (hilang dari penyaluran), ambil langsung dari history_pengembalian
//    Kolom asli: id_history, nama_barang, merk_tipe, kode_barang, thn_pengadaan, nama_penerima, no_reg, kondisi, tgl_kembali
if(!$barang){
    $q_kembali = mysqli_query($conn,"
        SELECT * FROM history_pengembalian
        WHERE id_sekolah='$id_sekolah'
        AND nama_barang='$nama_barang'
        AND (
            TRIM(no_reg) = TRIM('$no_reg')
            OR (
                INSTR(no_reg, '-') > 0
                AND CAST(SUBSTRING_INDEX('$no_reg','-',1) AS UNSIGNED) >= CAST(SUBSTRING_INDEX(no_reg,'-',1) AS UNSIGNED)
                AND CAST(SUBSTRING_INDEX('$no_reg','-',-1) AS UNSIGNED) <= CAST(SUBSTRING_INDEX(no_reg,'-',-1) AS UNSIGNED)
            )
            OR (
                INSTR('$no_reg', '-') > 0
                AND CAST(SUBSTRING_INDEX(no_reg,'-',1) AS UNSIGNED) >= CAST(SUBSTRING_INDEX('$no_reg','-',1) AS UNSIGNED)
                AND CAST(SUBSTRING_INDEX(no_reg,'-',-1) AS UNSIGNED) <= CAST(SUBSTRING_INDEX('$no_reg','-',-1) AS UNSIGNED)
            )
        )
        ORDER BY (nama_penerima = '$nama_penerima_esc') DESC, id_history DESC
        LIMIT 1
    ");
    if($q_kembali && mysqli_num_rows($q_kembali) > 0){
        $dKembali = mysqli_fetch_assoc($q_kembali);
        $barang = [
            'id'            => $dKembali['id_barang'] ?? 0,
            'id_barang'     => $dKembali['id_barang'] ?? 0,
            'nama_barang'   => $dKembali['nama_barang'],
            'merk_tipe'     => $dKembali['merk_tipe'] ?? '-',
            'thn_pengadaan' => $dKembali['thn_pengadaan'] ?? '-',
            'no_reg'        => $dKembali['no_reg'] ?? $no_reg,
            'kode_barang'   => $dKembali['kode_barang'] ?? '-'
        ];
    }
}

// 3. Fallback ke master data_barang dengan urutan terbaru (tahun_perolehan DESC, id DESC)
if(!$barang){
    $q_barang = mysqli_query($conn,"
        SELECT 
            id,
            id AS id_barang,
            nama_jenis_barang AS nama_barang,
            merek_type AS merk_tipe,
            tahun_perolehan AS thn_pengadaan,
            reg AS no_reg,
            kode_barang
        FROM data_barang 
        WHERE id_sekolah='$id_sekolah'
        AND nama_jenis_barang='$nama_barang'
        AND (
            TRIM(reg) = TRIM('$no_reg')
            OR (
                INSTR(reg, '-') > 0
                AND CAST(SUBSTRING_INDEX('$no_reg','-',1) AS UNSIGNED) >= CAST(SUBSTRING_INDEX(reg,'-',1) AS UNSIGNED)
                AND CAST(SUBSTRING_INDEX('$no_reg','-',-1) AS UNSIGNED) <= CAST(SUBSTRING_INDEX(reg,'-',-1) AS UNSIGNED)
            )
            OR (
                INSTR('$no_reg', '-') > 0
                AND CAST(SUBSTRING_INDEX(reg,'-',1) AS UNSIGNED) >= CAST(SUBSTRING_INDEX('$no_reg','-',1) AS UNSIGNED)
                AND CAST(SUBSTRING_INDEX(reg,'-',-1) AS UNSIGNED) <= CAST(SUBSTRING_INDEX('$no_reg','-',-1) AS UNSIGNED)
            )
        )
        ORDER BY tahun_perolehan DESC, id DESC
        LIMIT 1
    ");
    $barang = mysqli_fetch_assoc($q_barang);
}

// 4. Fallback terakhir jika no_reg tidak persis sama
if(!$barang){
    $q_barang = mysqli_query($conn,"
        SELECT 
            id,
            id AS id_barang,
            nama_jenis_barang AS nama_barang,
            merek_type AS merk_tipe,
            tahun_perolehan AS thn_pengadaan,
            reg AS no_reg,
            kode_barang
        FROM data_barang 
        WHERE id_sekolah='$id_sekolah'
        AND nama_jenis_barang='$nama_barang'
        ORDER BY tahun_perolehan DESC, id DESC
        LIMIT 1
    ");
    $barang = mysqli_fetch_assoc($q_barang);
}

if(!$barang){
    die("Data barang tidak ditemukan.");
}

$no_reg_tampil = !empty($history['no_reg']) ? $history['no_reg'] : ($_GET['no_reg'] ?? $barang['no_reg']);

if($history){
    $nama_penerima_snapshot = $history['nama_penerima'];
    $kondisi_barang = $history['kondisi'];
}else{
    $nama_penerima_snapshot = $barang['nama_penerima'] ?? '';
    $kondisi_barang = '';
}

/* ============================================================================
   SMART CHECK: JIKA GENERATE BARU / HISTORY KOSONG, AMBIL REAL-TIME
============================================================================ */
if (empty($kondisi_barang)) {
    $id_barang_aktif = $barang['id_barang'] ?? $barang['id'] ?? '';
    $no_reg_cek = strpos($no_reg_tampil, '-') !== false ? trim(explode('-', $no_reg_tampil)[0]) : trim($no_reg_tampil);
    $no_reg_cek_esc = mysqli_real_escape_string($conn, $no_reg_cek);
    
    if (!empty($id_barang_aktif)) {
        $q_detail = mysqli_query($conn, "
            SELECT kondisi FROM kondisi_barang_detail 
            WHERE (TRIM(no_reg) = '$no_reg_cek_esc' OR CAST(no_reg AS UNSIGNED) = CAST('$no_reg_cek_esc' AS UNSIGNED))
            AND id_barang = '$id_barang_aktif'
            LIMIT 1
        ");
        
        if ($q_detail && mysqli_num_rows($q_detail) > 0) {
            $row_detail = mysqli_fetch_assoc($q_detail);
            if (!empty($row_detail['kondisi'])) {
                $kondisi_barang = $row_detail['kondisi'];
            }
        }
    }
}

if (empty($kondisi_barang)) {
    $kondisi_barang = 'B';
}

/* =========================
   KONVERSI KODE KONDISI KE TEKS
========================= */
$kondisi_db = strtoupper(trim($kondisi_barang));
if ($kondisi_db == 'B') {
    $kondisi_teks = 'Baik';
} elseif ($kondisi_db == 'RR') {
    $kondisi_teks = 'Rusak Ringan';
} elseif ($kondisi_db == 'RB') {
    $kondisi_teks = 'Rusak Berat';
} else {
    $kondisi_teks = htmlspecialchars($kondisi_barang); 
}

/* =========================
   AMBIL DATA PEGAWAI (Filter id_sekolah)
========================= */
$nama_penerima_esc = mysqli_real_escape_string($conn, $nama_penerima_snapshot);
$q_pegawai = mysqli_query($conn,"
    SELECT * FROM pegawai 
    WHERE id_sekolah = '$id_sekolah'
    AND nama_lengkap='$nama_penerima_esc'
    LIMIT 1
");
$pegawai = mysqli_fetch_assoc($q_pegawai);
if(!$pegawai){
    $pegawai = [
        'nama_lengkap' => $nama_penerima_snapshot,
        'nip' => '-',
        'pangkat_golongan' => '-',
        'jabatan' => '-'
    ];
}

/* =========================
   AMBIL KOP SURAT (Filter id_sekolah)
========================= */
$q_kop = mysqli_query($conn,"SELECT * FROM kop_surat WHERE id_sekolah = '$id_sekolah' LIMIT 1");
$kop = mysqli_fetch_assoc($q_kop);
if(!$kop){
    die("Kop surat belum diatur untuk sekolah ini.");
}

/* =========================
   FUNGSI PROSES NAMA (KAPITAL NAMA, GELAR TETAP)
========================= */
function formatNamaGelar($nama_asli) {
    if (strpos($nama_asli, ',') !== false) {
        $x = explode(',', $nama_asli);
        return strtoupper($x[0]) . ',' . $x[1];
    } elseif (strpos($nama_asli, '.') !== false) {
        $pos_titik = strpos($nama_asli, '.');
        return strtoupper(substr($nama_asli, 0, $pos_titik)) . substr($nama_asli, $pos_titik);
    } else {
        return strtoupper($nama_asli);
    }
}

$nama_format = formatNamaGelar($pegawai['nama_lengkap']);
$nama_wakasek_format = formatNamaGelar($kop['nama_wakasek_sarpras'] ?? '-');
$nama_kepsek_format = formatNamaGelar($kop['nama_kepala_sekolah'] ?? '-');

/* =========================
   SETTING PDF
========================= */
$pdf = new TCPDF('P', 'mm', array(215,330), true, 'UTF-8', false);
$pdf->setPrintHeader(false);
$pdf->setPrintFooter(false);
$pdf->SetMargins(22, 10, 22);
$pdf->SetAutoPageBreak(TRUE, 10);
$pdf->AddPage();
$pdf->SetFont('times', '', 11);

/* =========================
   KOP SURAT
========================= */
$logoHTML = '';
$logoPath = 'upload_kop/'.$kop['logo'];

if(!empty($kop['logo']) && file_exists($logoPath)){
    $logoHTML = '<img src="'.$logoPath.'" height="85">';
}

$kop_html = '
<table width="100%" cellpadding="0" cellspacing="0" style="">
<tr>
    <td width="15%" align="center" valign="middle">
        '.$logoHTML.'
    </td>
    <td width="85%" align="center" valign="middle" style="line-height:1.15;">
        <span style="font-size:14pt;">'.strtoupper($kop['nama_provinsi']).'</span><br>
        <span style="font-size:14pt;">'.strtoupper($kop['nama_dinas']).'</span><br>
        <span style="font-size:14pt;">CABANG DINAS PENDIDIKAN WILAYAH X</span><br>
        <b style="font-size:14pt;">'.strtoupper($kop['nama_sekolah']).'</b><br>
        <span style="font-size:10pt;">
            '.$kop['alamat'].' 
            Email: '.$kop['email'].'
        </span><br>
        <b style="font-size:10pt;">
            '.$kop['kota'].' - '.$kop['kode_pos'].'
        </b>
    </td>
</tr>
</table>
<br>
';

/* =========================
   ISI PAKTA INTEGRITAS
========================= */
$content_html = '
<div style="text-align:center; line-height:1.2;">
<span style="font-size:14pt;"><b>PAKTA INTEGRITAS</b></span><br>
<span style="font-size:14pt;"><b>PEMANFAATAN INVENTARIS BARANG MILIK DAERAH (BMD)</b></span>
</div>

<div style="line-height:1.1; margin-top:10px;">Saya yang bertandatangan dibawah ini:</div>

<table width="100%" cellpadding="2" style="margin-top:2px;">
<tr>
<td width="30%">Nama</td>
<td width="3%">:</td>
<td width="67%" style="padding-left:2px;"><b>'.htmlspecialchars($nama_format).'</b></td>
</tr>
<tr>
<td>NIP</td>
<td>:</td>
<td style="padding-left:2px;">'.htmlspecialchars($pegawai['nip']).'</td>
</tr>
<tr>
<td>Pangkat/Gol.</td>
<td>:</td>
<td style="padding-left:2px;">'.htmlspecialchars($pegawai['pangkat_golongan']).'</td>
</tr>
<tr>
<td>Sub. Bidang</td>
<td>:</td>
<td style="padding-left:2px;">'.htmlspecialchars(ucwords(strtolower($pegawai['jabatan']))).'</td>
</tr>
</table>

<p>Dengan ini menyatakan bahwa saya:</p>

<table width="100%" cellpadding="1" style="line-height:1.25; text-align:justify;">
<tr>
<td width="4%" valign="top">1.</td>
<td width="96%" valign="top" colspan="3">Akan bertanggungjawab atas Inventaris Barang Milik Daerah (BMD) dengan seluruh resiko yang melekat terhadap Barang Inventaris tersebut, dengan rincian barang sebagai berikut:</td>
</tr>

<tr>
<td width="4%"></td>
<td width="26%">Nama Barang</td>
<td width="3%">:</td>
<td width="67%">'.htmlspecialchars($barang['nama_barang']).'</td>
</tr>

<tr>
<td></td>
<td>Merk/Spesifikasi</td>
<td>:</td>
<td>'.htmlspecialchars($barang['merk_tipe']).'</td>
</tr>

<tr>
<td></td>
<td>Tahun Pengadaan</td>
<td>:</td>
<td>'.htmlspecialchars($barang['thn_pengadaan']).'</td>
</tr>

<tr>
<td></td>
<td>Register</td>
<td>:</td>
<td>'.htmlspecialchars($no_reg_tampil).'</td>
</tr>

<tr>
<td></td>
<td>Kondisi Barang</td>
<td>:</td>
<td><b>'.$kondisi_teks.'</b></td>
</tr>

<tr><td width="4%" valign="top">2.</td><td width="96%" valign="top" colspan="3">Akan memanfaatkan Inventaris BMD sesuai Tugas, Pokok dan Fungsi Jabatan yang melekat;</td></tr>
<tr><td valign="top">3.</td><td valign="top" colspan="3">Akan menjaga dan memelihara Inventaris BMD sesuai umur ekonomis BMD tersebut;</td></tr>
<tr><td valign="top">4.</td><td valign="top" colspan="3">Akan segera mengembalikan Inventaris BMD setelah jangka waktu penggunaan berakhir atau terjadi mutasi, yang dituangkan dalam Berita Acara Pengembalian;</td></tr>
<tr><td valign="top">5.</td><td valign="top" colspan="3">Apabila Inventaris hilang sebagai akibat dari kesalahan atau kelalaian atau penyimpangan dari ketentuan, maka saya sebagai penanggung jawab Inventaris BMD siap dikenakan sanksi sesuai dengan ketentuan peraturan perundang-undangan.</td></tr>
</table>

<p>Demikian Pakta Integritas Pemanfaatan Barang Milik Daerah (BMD) ini dibuat dalam rangkap 2 (dua) untuk dipergunakan sebagaimana mestinya.</p>

<br><br>

<table width="100%">
<tr align="center">
    <td width="50%"></td>
    <td width="50%">Penanggungjawab Inventaris</td>
</tr>
<tr align="center">
    <td width="50%">Wakasek Sarpras</td>
    <td width="50%">Barang Milik Daerah (BMD)</td>
</tr>
<tr align="center">
    <td width="50%">
        <br><br><br><br><br>
        <b>'.htmlspecialchars($nama_wakasek_format).'</b><br>
        NIP. '.htmlspecialchars($kop['nip_wakasek_sarpras'] ?? '-').'
    </td>
    <td width="50%">
        <br><br><br><br><br>
        <b>'.htmlspecialchars($nama_format).'</b><br>
        NIP. '.htmlspecialchars($pegawai['nip']).'
    </td>
</tr>
</table>

<br><br>

<div style="text-align:center;">
Mengetahui,<br>
Kepala Sekolah
<br><br><br><br><br>
<b>'.htmlspecialchars($nama_kepsek_format).'</b><br>
NIP. '.htmlspecialchars($kop['nip_kepala_sekolah'] ?? '-').'
</div>
';

$pdf->writeHTML($kop_html, true, false, true, false, '');
$y = $pdf->GetY();
$pdf->SetLineWidth(0.8);
$pdf->Line(22, $y - 4, 193, $y - 4);
$pdf->SetLineWidth(0.3);
$pdf->Line(22, $y - 3, 193, $y - 3);
$pdf->SetY($y + 1);
$pdf->writeHTML($content_html, true, false, true, false, '');

$safeFileName = preg_replace('/[^A-Za-z0-9_\-]/', '_', $barang['nama_barang'] . '_' . $no_reg_tampil);
$pdf->Output('FAKTA_PDF_'.$safeFileName.'.pdf', 'I');

ob_end_flush();
exit;
?>