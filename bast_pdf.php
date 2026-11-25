<?php
ob_start();
session_start();
error_reporting(0);

require_once('tcpdf/tcpdf.php');
include "koneksi.php";

if(!isset($_GET['nama_barang']) && !isset($_GET['id_history'])){
    die("Data tidak lengkap");
}

$id_sekolah  = $_SESSION['id_sekolah'] ?? '';
if(empty($id_sekolah)){
    die("Sesi login berakhir, silakan login kembali.");
}

$id_history  = mysqli_real_escape_string($conn, $_GET['id_history'] ?? '');
$nama_barang = mysqli_real_escape_string($conn, $_GET['nama_barang'] ?? '');
$no_reg      = mysqli_real_escape_string($conn, $_GET['no_reg'] ?? '');

// Ambil kode klasifikasi surat BAST dari tabel pengaturan_surat
$kode_klasifikasi_bast = '';
$qPengaturanSurat = mysqli_query($conn, "SELECT kode_bast FROM pengaturan_surat WHERE id_sekolah = '$id_sekolah' LIMIT 1");
if($qPengaturanSurat && mysqli_num_rows($qPengaturanSurat) > 0){
    $dPengaturanSurat = mysqli_fetch_assoc($qPengaturanSurat);
    $kode_klasifikasi_bast = trim($dPengaturanSurat['kode_bast'] ?? '');
}

$raw_nomor = trim($_GET['nomor'] ?? '');
if (!empty($raw_nomor) && strpos($raw_nomor, '/') === false && !empty($kode_klasifikasi_bast)) {
    $nomor = $raw_nomor . '/' . $kode_klasifikasi_bast;
} else {
    $nomor = $raw_nomor;
}

$nomor_esc   = mysqli_real_escape_string($conn, $nomor);
$tanggalFull = mysqli_real_escape_string($conn, $_GET['tanggal'] ?? date('Y-m-d'));

/* =========================
   DATA HISTORY (SNAPSHOT)
========================= */
if (!empty($id_history)) {
    $q_history = mysqli_query($conn,"
        SELECT * FROM history_bast
        WHERE id_history='$id_history'
          AND id_sekolah='$id_sekolah'
        LIMIT 1
    ");
} else {
    $q_history = mysqli_query($conn,"
        SELECT * FROM history_bast
        WHERE id_sekolah='$id_sekolah'
        AND nama_barang='$nama_barang'
        AND nomor_surat='$nomor_esc'
        AND tanggal='$tanggalFull'
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

if(!$history){
    die("Data history tidak ditemukan");
}

if(!empty($history['nomor_surat'])){
    $nomor = $history['nomor_surat'];
}
if(!empty($history['nama_barang'])){
    $nama_barang = mysqli_real_escape_string($conn, $history['nama_barang']);
}
if(!empty($history['no_reg'])){
    $no_reg = mysqli_real_escape_string($conn, $history['no_reg']);
}
if(!empty($history['tanggal'])){
    $tanggalFull = $history['tanggal'];
}

$nama_penerima_snapshot = $history['nama_penerima'];
$nama_penerima_esc      = mysqli_real_escape_string($conn, $nama_penerima_snapshot);

/* =========================
   FUNGSI TERBILANG
========================= */
function penyebut($nilai) {
    $nilai = abs($nilai);
    $huruf = array("", "Satu", "Dua", "Tiga", "Empat", "Lima", "Enam", "Tujuh", "Delapan", "Sembilan", "Sepuluh", "Sebelas");
    $temp = "";
    if ($nilai < 12) {
        $temp = " " . $huruf[$nilai];
    } else if ($nilai < 20) {
        $temp = penyebut($nilai - 10) . " Belas";
    } else if ($nilai < 100) {
        $temp = penyebut($nilai / 10) . " Puluh" . penyebut($nilai % 10);
    } else if ($nilai < 200) {
        $temp = " Seratus" . penyebut($nilai - 100);
    } else if ($nilai < 1000) {
        $temp = penyebut($nilai / 100) . " Ratus" . penyebut($nilai % 100);
    } else if ($nilai < 2000) {
        $temp = " Seribu" . penyebut($nilai - 1000);
    } else if ($nilai < 1000000) {
        $temp = penyebut($nilai / 1000) . " Ribu" . penyebut($nilai % 1000);
    } else if ($nilai < 1000000000) {
        $temp = penyebut($nilai / 1000000) . " Juta" . penyebut($nilai % 1000000);
    }
    return $temp;
}

function terbilang($nilai) {
    return trim(penyebut($nilai));
}

/* =========================
   FORMAT TANGGAL & HARI
========================= */
$hariInggris  = date("l", strtotime($tanggalFull));
$tglAngka     = date("d", strtotime($tanggalFull));
$bulanInggris = date("F", strtotime($tanggalFull));
$tahunAngka   = date("Y", strtotime($tanggalFull));

$hariIndo = [
    "Sunday" => "Minggu", "Monday" => "Senin", "Tuesday" => "Selasa",
    "Wednesday" => "Rabu", "Thursday" => "Kamis", "Friday" => "Jumat", "Saturday" => "Sabtu"
];

$bulanIndo = [
    "January"=>"Januari","February"=>"Februari","March"=>"Maret",
    "April"=>"April","May"=>"Mei","June"=>"Juni",
    "July"=>"Juli","August"=>"Agustus","September"=>"September",
    "October"=>"Oktober","November"=>"November","December"=>"Desember"
];

$hari  = $hariIndo[$hariInggris];
$bulan = $bulanIndo[$bulanInggris];

$tgl   = terbilang((int)$tglAngka);
$tahun = terbilang((int)$tahunAngka);

/* =========================
   DATA BARANG (AKTIF & SUDAH DIKEMBALIKAN)
========================= */
// 1. Cek terlebih dahulu di tabel penyaluran (jika barang masih disalurkan)
$q_barang = mysqli_query($conn,"
    SELECT * FROM penyaluran 
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
    ORDER BY (nama_penerima = '$nama_penerima_esc') DESC, id_penyaluran DESC
    LIMIT 1
");
$barang = mysqli_fetch_assoc($q_barang);

// 2. Jika sudah dikembalikan (hilang dari penyaluran), ambil dari history_pengembalian
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

// 4. Fallback terakhir jika register berupa teks non-range
if(!$barang){
    $q_barang = mysqli_query($conn,"
        SELECT 
            id,
            id AS id_barang,
            nama_jenis_barang AS nama_barang,
            merek_type AS merk_tipe,
            tahun_perolehan AS thn_pengadaan,
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
    die("Data barang tidak ditemukan");
}

/* ============================================================================
   KONDISI BARANG (PRIORITAS UTAMA SNAPSHOT HISTORY AGAR TIDAK BERUBAH HARIAN)
============================================================================ */
$kondisi_barang = '<b>Baik</b>';
$status_terdeteksi = '';

if (isset($history['kondisi']) && !empty($history['kondisi'])) {
    $status_terdeteksi = $history['kondisi'];
}

if (empty($status_terdeteksi)) {
    $id_barang_aktif = $barang['id_barang'] ?? $barang['id'] ?? '';
    $no_reg_cek = strpos($no_reg, '-') !== false ? trim(explode('-', $no_reg)[0]) : trim($no_reg);
    $q_detail = mysqli_query($conn, "
        SELECT kondisi FROM kondisi_barang_detail 
        WHERE (TRIM(no_reg) = '$no_reg_cek' OR CAST(no_reg AS UNSIGNED) = CAST('$no_reg_cek' AS UNSIGNED))
        AND (id_barang = '$id_barang_aktif' OR '$id_barang_aktif' = '')
        LIMIT 1
    ");
    if ($q_detail && mysqli_num_rows($q_detail) > 0) {
        $row_detail = mysqli_fetch_assoc($q_detail);
        if (!empty($row_detail['kondisi'])) {
            $status_terdeteksi = $row_detail['kondisi'];
        }
    }
}

if (empty($status_terdeteksi)) {
    $q_kondisi = mysqli_query($conn, "SELECT kondisi FROM history_pengembalian WHERE id_sekolah='$id_sekolah' AND nama_barang='$nama_barang' AND no_reg='$no_reg' ORDER BY tgl_kembali DESC, id_history DESC LIMIT 1");
    if ($q_kondisi && mysqli_num_rows($q_kondisi) > 0) {
        $row_kondisi = mysqli_fetch_assoc($q_kondisi);
        if(!empty($row_kondisi['kondisi'])){
            $status_terdeteksi = $row_kondisi['kondisi'];
        }
    }
}

if (!empty($status_terdeteksi)) {
    $cek_status = strtoupper(trim($status_terdeteksi));
    if ($cek_status == 'RB') {
        $kondisi_barang = '<b>Rusak Berat</b>';
    } elseif ($cek_status == 'RR') {
        $kondisi_barang = '<b>Rusak Ringan</b>';
    } elseif ($cek_status == 'B') {
        $kondisi_barang = '<b>Baik</b>';
    } else {
        $kondisi_barang = '<b>' . htmlspecialchars($status_terdeteksi) . '</b>';
    }
}

/* =========================
   DATA PEGAWAI
========================= */
$q_pegawai = mysqli_query($conn,"
    SELECT * FROM pegawai 
    WHERE id_sekolah='$id_sekolah'
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
   PROSES NAMA PIHAK KEDUA (KAPITAL NAMA, GELAR TETAP)
========================= */
$nama_asli = $pegawai['nama_lengkap'];
if (strpos($nama_asli, ',') !== false) {
    $x = explode(',', $nama_asli);
    $nama_format = strtoupper($x[0]) . ',' . $x[1];
} elseif (strpos($nama_asli, '.') !== false) {
    $pos_titik = strpos($nama_asli, '.');
    $nama_format = strtoupper(substr($nama_asli, 0, $pos_titik)) . substr($nama_asli, $pos_titik);
} else {
    $nama_format = strtoupper($nama_asli);
}

/* =========================
   DATA KOP & PEJABAT KESATU
========================= */
$q_kop = mysqli_query($conn,"SELECT * FROM kop_surat WHERE id_sekolah = '$id_sekolah' LIMIT 1");
$kop = mysqli_fetch_assoc($q_kop);

if(!$kop){
    $kop = [
        'logo' => '',
        'nama_provinsi' => 'PEMERINTAH PROVINSI',
        'nama_dinas' => 'DINAS PENDIDIKAN',
        'nama_sekolah' => '-',
        'alamat' => '-',
        'email' => '-',
        'kota' => '-',
        'kode_pos' => '-',
        'nama_wakasek_sarpras' => '-',
        'nip_wakasek_sarpras' => '-',
        'pangkat_wakasek_sarpras' => '-'
    ];
}

/* =========================
   SETTING PDF
========================= */
$pdf = new TCPDF('P', 'mm', array(215,330), true, 'UTF-8', false);
$pdf->setPrintHeader(false);
$pdf->setPrintFooter(false);
$pdf->SetMargins(22, 10, 22);
$pdf->SetAutoPageBreak(TRUE, 20);
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
';

/* =========================
   ISI PDF
========================= */
$html = '
<style>
table { line-height:1.1; }
p { text-align:justify; line-height:1.4; margin:0; }
</style>

<div style="text-align:center; line-height:1.2;">
    <span style="font-size:13pt;"><b>BERITA ACARA PENYALURAN</b></span><br>
    <span style="font-size:13pt;"><b>BARANG MILIK DAERAH (BMD)</b></span><br>
    Nomor: '.htmlspecialchars($nomor).'
</div>

<div style="margin-top:5px; text-align:justify; line-height:1.4;">
Pada hari ini <b>'.$hari.'</b> tanggal <b>'.$tgl.'</b> bulan <b>'.$bulan.'</b> tahun <b>'.$tahun.'</b> bertempat di '.$kop['nama_sekolah'].', yang bertandatangan dibawah ini:
</div>

<br>

<table width="100%" cellpadding="1" style="line-height:1.2;">
<tr>
  <td width="5%">1.</td>
  <td width="28%">Nama</td>
  <td width="2%">:</td>
  <td width="65%" style="padding-left:2px;"><b>'.strtoupper($kop['nama_wakasek_sarpras']).'</b></td>
</tr>
<tr>
  <td></td>
  <td>NIP</td>
  <td>:</td>
  <td>'.$kop['nip_wakasek_sarpras'].'</td>
</tr>
<tr>
  <td></td>
  <td>Pangkat/Gol.</td>
  <td>:</td>
  <td>'.$kop['pangkat_wakasek_sarpras'].'</td>
</tr>
<tr>
  <td></td>
  <td>Jabatan</td>
  <td>:</td>
  <td>Wakasek Sarpras</td>
</tr>
</table>
<p>
Dalam hal ini bertindak sebagai Pengelola Sarpras dan selanjutnya disebut sebagai <b>PIHAK KESATU</b>.
</p>

<br>

<table width="100%" cellpadding="1" style="line-height:1.2;">
<tr>
  <td width="5%">2.</td>
  <td width="28%">Nama</td>
  <td width="2%">:</td>
  <td width="65%" style="padding-left:2px;"><b>'.htmlspecialchars($nama_format).'</b></td>
</tr>
<tr>
  <td></td>
  <td>NIP</td>
  <td>:</td>
  <td>'.htmlspecialchars($pegawai['nip']).'</td>
</tr>
<tr>
  <td></td>
  <td>Pangkat/Gol.</td>
  <td>:</td>
  <td>'.htmlspecialchars($pegawai['pangkat_golongan']).'</td>
</tr>
<tr>
  <td></td>
  <td>Jabatan</td>
  <td>:</td>
  <td>'.htmlspecialchars(ucwords(strtolower($pegawai['jabatan']))).'</td>
</tr>
</table>
<p>
Dalam hal ini bertindak sebagai Pemegang Barang Inventaris pada Pengguna Barang dan selanjutnya disebut sebagai <b>PIHAK KEDUA</b>.
</p>
<p>
Dengan ini menerangkan bahwa <b>PIHAK KESATU</b> telah menyalurkan Barang Milik Daerah (BMD) yang tercatat pada '.$kop['nama_sekolah'].' Provinsi Jawa Barat kepada <b>PIHAK KEDUA</b> untuk menunjang pelaksanaan tugas pokok dan fungsi <b>PIHAK KEDUA</b> dengan rincian Barang Milik Daerah (BMD) sebagai berikut:
</p>
<table width="100%" cellpadding="1" style="line-height:1.2;">
<tr>
  <td width="32%">Nama Barang</td>
  <td width="2%">:</td>
  <td width="66%" style="padding-left:2px;">'.htmlspecialchars($barang['nama_barang']).'</td>
</tr>
<tr>
  <td>Merk/Spesifikasi</td>
  <td width="2%">:</td>
  <td style="padding-left:2px;">'.htmlspecialchars($barang['merk_tipe']).'</td>
</tr>
<tr>
  <td>Tahun Pengadaan</td>
  <td width="2%">:</td>
  <td style="padding-left:2px;">'.htmlspecialchars($barang['thn_pengadaan']).'</td>
</tr>
<tr>
  <td>Kode Barang</td>
  <td width="2%">:</td>
  <td style="padding-left:2px;">'.htmlspecialchars($barang['kode_barang']).'</td>
</tr>
<tr>
  <td>Register</td>
  <td width="2%">:</td>
  <td style="padding-left:2px;">'.htmlspecialchars($history['no_reg'] ?: $no_reg).'</td>
</tr>
<tr>
  <td>Kondisi Barang</td>
  <td width="2%">:</td>
  <td style="padding-left:2px;">'.$kondisi_barang.'</td>
</tr>
</table>
<p>
Dengan ditandatanganinya Berita Acara ini, tanggungjawab atas Barang Milik Daerah (BMD) tersebut beralih dan dibebankan kepada <b>PIHAK KEDUA</b> sebagai pengguna barang agar dapat menjaga, merawat, dan tidak memindah tangankan secara sepihak.
</p>

<br>

<p>
Demikian Berita Acara Penyaluran Barang Milik Daerah (BMD) ini dibuat dalam rangkap 2 (dua) untuk digunakan sebagaimana mestinya.
</p>

<br><br>

<table width="100%">
    <tr align="left">
        <td width="50%">
            <b>PIHAK KEDUA</b>
            <br><br><br><br><br>
            <b>'.htmlspecialchars($nama_format).'</b><br>
            NIP. '.htmlspecialchars($pegawai['nip']).'
        </td>

        <td width="10%"></td>

        <td width="40%">
            <b>PIHAK PERTAMA</b>
            <br><br><br><br><br>
            <b>'.strtoupper($kop['nama_wakasek_sarpras']).'</b><br>
            NIP. '.$kop['nip_wakasek_sarpras'].'
        </td>
    </tr>
</table>
';

$pdf->writeHTML($kop_html, true, false, true, false, '');
$y = $pdf->GetY();
$pdf->SetLineWidth(0.8);
$pdf->Line(22, $y - 4, 193, $y - 4);
$pdf->SetLineWidth(0.3);
$pdf->Line(22, $y - 3, 193, $y - 3);
$pdf->Ln(1);
$pdf->writeHTML($html, true, false, true, false, '');

$safeFileName = preg_replace('/[^A-Za-z0-9_\-]/', '_', $barang['nama_barang'] . '_' . ($history['no_reg'] ?: $no_reg));
$pdf->Output('BAST_'.$safeFileName.'.pdf', 'I');

ob_end_flush();
exit;
?>