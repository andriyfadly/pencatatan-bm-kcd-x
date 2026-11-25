<?php
ob_start();
session_start();
error_reporting(0);

require_once('tcpdf/tcpdf.php');
include "koneksi.php";

$id_sekolah = $_SESSION['id_sekolah'] ?? '';
if(empty($id_sekolah)){
    die("Sesi login berakhir, silakan login kembali.");
}

/* =========================================================================
   AMBIL KODE KLASIFIKASI SURAT DARI TABEL pengaturan_surat (CADANGAN)
   ========================================================================= */
$kode_klasifikasi = '';
$qPengaturan = mysqli_query($conn, "SELECT * FROM pengaturan_surat WHERE id_sekolah = '$id_sekolah' LIMIT 1");
if ($qPengaturan && mysqli_num_rows($qPengaturan) > 0) {
    $dPengaturan = mysqli_fetch_assoc($qPengaturan);
    if (!empty(trim($dPengaturan['kode_pengembalian'] ?? ''))) {
        $kode_klasifikasi = trim($dPengaturan['kode_pengembalian']);
    } elseif (!empty(trim($dPengaturan['kode_ba'] ?? ''))) {
        $kode_klasifikasi = trim($dPengaturan['kode_ba']);
    } elseif (!empty(trim($dPengaturan['kode_bast'] ?? ''))) {
        $kode_klasifikasi = trim($dPengaturan['kode_bast']);
    }
}

/* =========================================================================
   QUERY DATA PENGEMBALIAN: MENGGUNAKAN ID UNIK (ID HISTORY / ID PENYALURAN)
   ========================================================================= */
$barang = null;

if (isset($_GET['id_history'])) {
    // 1. Jika dicetak melalui proses langsung atau tombol "Cetak BA" di Riwayat Lengkap
    $id_history = mysqli_real_escape_string($conn, $_GET['id_history']);
    $q_barang = mysqli_query($conn, "SELECT * FROM history_pengembalian WHERE id_history = '$id_history' AND id_sekolah = '$id_sekolah' LIMIT 1");
    $barang = mysqli_fetch_assoc($q_barang);
    
} elseif (isset($_GET['id_penyaluran'])) {
    // 2. Fallback jika dicetak menggunakan id_penyaluran
    $id_penyaluran = mysqli_real_escape_string($conn, $_GET['id_penyaluran']);
    
    $q_barang = mysqli_query($conn, "SELECT * FROM history_pengembalian WHERE id_penyaluran = '$id_penyaluran' AND id_sekolah = '$id_sekolah' ORDER BY id_history DESC LIMIT 1");
    $barang = mysqli_fetch_assoc($q_barang);
    
    if(!$barang) {
        $q_barang = mysqli_query($conn, "SELECT * FROM penyaluran WHERE id_penyaluran = '$id_penyaluran' AND id_sekolah = '$id_sekolah' LIMIT 1");
        $barang = mysqli_fetch_assoc($q_barang);
    }
    
} else {
    // 3. Cadangan jika parameter nama_barang & no_reg dikirim
    if (isset($_GET['nama_barang']) && isset($_GET['no_reg'])) {
        $nama_barang   = mysqli_real_escape_string($conn, $_GET['nama_barang']);
        $no_reg        = mysqli_real_escape_string($conn, $_GET['no_reg']);
        $nama_penerima = isset($_GET['nama_penerima']) ? mysqli_real_escape_string($conn, $_GET['nama_penerima']) : '';
        
        $where_history_penerima = "";
        if(!empty($nama_penerima)) {
            $where_history_penerima = " AND nama_penerima = '$nama_penerima' ";
        }

        $q_barang = mysqli_query($conn,"
            SELECT * FROM history_pengembalian 
            WHERE nama_barang='$nama_barang'
            AND id_sekolah='$id_sekolah'
            $where_history_penerima
            AND (
                no_reg = '$no_reg'
                OR (
                    no_reg LIKE '%-%'
                    AND CAST('$no_reg' AS UNSIGNED) 
                        BETWEEN 
                        CAST(SUBSTRING_INDEX(no_reg,'-',1) AS UNSIGNED)
                        AND 
                        CAST(SUBSTRING_INDEX(no_reg,'-',-1) AS UNSIGNED)
                )
            )
            ORDER BY id_history DESC
            LIMIT 1
        ");
        $barang = mysqli_fetch_assoc($q_barang);

        if(!$barang) {
            $q_barang_backup = mysqli_query($conn,"
                SELECT * FROM penyaluran 
                WHERE nama_barang='$nama_barang'
                AND id_sekolah='$id_sekolah'
                $where_history_penerima
                AND (
                    no_reg = '$no_reg'
                    OR (
                        no_reg LIKE '%-%'
                        AND CAST('$no_reg' AS UNSIGNED) 
                            BETWEEN 
                            CAST(SUBSTRING_INDEX(no_reg,'-',1) AS UNSIGNED)
                            AND 
                            CAST(SUBSTRING_INDEX(no_reg,'-',-1) AS UNSIGNED)
                    )
                )
                LIMIT 1
            ");
            $barang = mysqli_fetch_assoc($q_barang_backup);
        }
    }
}

if(!$barang && isset($_GET['id_penyaluran'])) {
    $q_track = mysqli_query($conn, "SELECT * FROM history_pengembalian WHERE id_sekolah='$id_sekolah' ORDER BY id_history DESC LIMIT 1");
    $track = mysqli_fetch_assoc($q_track);
    if($track) {
        $barang = $track;
    }
}

if(!$barang){
    die("Data barang pengembalian tidak ditemukan di sistem.");
}

// Set variabel snapshot dari record data yang ditemukan secara spesifik
$nama_barang            = $barang['nama_barang'];
$no_reg                 = $barang['no_reg'];
$nama_penerima_snapshot = $barang['nama_penerima'];

/* =========================================================================
   VARIABEL KETERANGAN
   ========================================================================= */
$keterangan_tampil = '';
if (isset($_GET['keterangan']) && trim($_GET['keterangan']) !== '') {
    $keterangan_tampil = htmlspecialchars(trim($_GET['keterangan']));
} else {
    $keterangan_tampil = !empty($barang['keterangan']) ? htmlspecialchars($barang['keterangan']) : '-';
}

/* =========================
   FORMAT TANGGAL PROSES KEMBALI (SESUAI RIWAYAT / REALTIME)
========================= */
$tanggalFull = !empty($barang['tgl_kembali']) ? date('Y-m-d', strtotime($barang['tgl_kembali'])) : date('Y-m-d');
$hariInggris = date("l", strtotime($tanggalFull));
$tglAngka    = date("d", strtotime($tanggalFull));
$bulanAngka  = date("m", strtotime($tanggalFull));
$bulanInggris= date("F", strtotime($tanggalFull));
$tahunAngka  = date("Y", strtotime($tanggalFull));

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

$tgl   = terbilang((int)$tglAngka);
$tahun = terbilang((int)$tahunAngka);

/* =========================
   NOMOR SURAT PENGEMBALIAN OTOMATIS (001/KLASIFIKASI)
========================= */
$nomor_surat_pengembalian = '';
if (!empty($barang['nomor_surat'])) {
    $nomor_surat_pengembalian = trim($barang['nomor_surat']);
} elseif (!empty($_GET['nomor_surat'])) {
    $nomor_surat_pengembalian = trim($_GET['nomor_surat']);
} else {
    $nomor_surat_pengembalian = ($kode_klasifikasi !== '') ? ('001/' . $kode_klasifikasi) : '001';
}

// Ambil tahun pengadaan dengan aman
$tahun_pengadaan_tampil = (!empty($barang['thn_pengadaan']) && $barang['thn_pengadaan'] != 0) ? $barang['thn_pengadaan'] : '-';

/* =========================
   DATA HISTORY BAST LAMA (SNAPSHOT PENYALURAN)
   Mendukung kecocokan nomor tunggal maupun range paket (misal: 002-004 berada dalam 001-005)
========================= */
$regAwalCek  = (int)explode('-', $no_reg)[0];
$regAkhirCek = (int)explode('-', $no_reg)[count(explode('-', $no_reg)) - 1];

$q_history = mysqli_query($conn,"
    SELECT * FROM history_bast
    WHERE id_sekolah = '$id_sekolah'
    AND nama_barang = '".mysqli_real_escape_string($conn, $nama_barang)."'
    AND nama_penerima = '".mysqli_real_escape_string($conn, $nama_penerima_snapshot)."'
    AND (
        no_reg = '".mysqli_real_escape_string($conn, $no_reg)."'
        OR (
            no_reg LIKE '%-%' 
            AND $regAwalCek >= CAST(SUBSTRING_INDEX(no_reg,'-',1) AS UNSIGNED)
            AND $regAkhirCek <= CAST(SUBSTRING_INDEX(no_reg,'-',-1) AS UNSIGNED)
        )
    )
    ORDER BY id_history DESC
    LIMIT 1
");

$history = mysqli_fetch_assoc($q_history);

if(!$history){
    $nomor_surat_lama = ($kode_klasifikasi !== '') ? ('........./' . $kode_klasifikasi) : '-';
} else {
    $db_nomor_surat = !empty($history['nomor_surat']) ? trim($history['nomor_surat']) : '';
    $db_no_urut     = !empty($history['no_urut_surat']) ? trim($history['no_urut_surat']) : '';

    if (empty($db_nomor_surat)) {
        $nomor_surat_lama = ($kode_klasifikasi !== '') ? ('........./' . $kode_klasifikasi) : '-';
    } else {
        if (!empty($db_no_urut) && (substr(trim($db_nomor_surat), 0, 1) === '/' || strpos($db_nomor_surat, $db_no_urut) === false)) {
            $nomor_surat_lama = $db_no_urut . trim($db_nomor_surat);
        } else {
            $nomor_surat_lama = trim($db_nomor_surat);
        }
    }
}

/* =========================
   DATA PEGAWAI
========================= */
$q_pegawai = mysqli_query($conn,"
    SELECT * FROM pegawai 
    WHERE nama_lengkap='".mysqli_real_escape_string($conn, $nama_penerima_snapshot)."'
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
   AMBIL KONDISI BARANG SECARA DINAMIS
========================= */
$kondisi_singkatan = $_GET['kondisi_kembali'] ?? $barang['kondisi'] ?? $barang['kondisi_kembali'] ?? 'B';
$kondisi_tampil = 'Baik';
if($kondisi_singkatan == 'RR') {
    $kondisi_tampil = 'Rusak Ringan';
} elseif($kondisi_singkatan == 'RB') {
    $kondisi_tampil = 'Rusak Berat';
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
<table width="100%" cellpadding="0" cellspacing="0">
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
    <span style="font-size:13pt;"><b>BERITA ACARA PENGEMBALIAN</b></span><br>
    <span style="font-size:13pt;"><b>BARANG MILIK DAERAH (BMD)</b></span><br>
    Nomor: '.htmlspecialchars($nomor_surat_pengembalian).'
</div>

<div style="margin-top:5px; text-align:justify; line-height:1.2;">
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


<table width="100%" cellpadding="1" style="line-height:1.2;">
<tr>
  <td width="5%">2.</td>
  <td width="28%">Nama</td>
  <td width="2%">:</td>
  <td width="65%" style="padding-left:2px;"><b>'.$nama_format.'</b></td>
</tr>
<tr>
  <td></td>
  <td>NIP</td>
  <td>:</td>
  <td>'.$pegawai['nip'].'</td>
</tr>
<tr>
  <td></td>
  <td>Pangkat/Gol.</td>
  <td>:</td>
  <td>'.$pegawai['pangkat_golongan'].'</td>
</tr>
<tr>
  <td></td>
  <td>Jabatan</td>
  <td>:</td>
  <td>'.ucwords(strtolower($pegawai['jabatan'])).'</td>
</tr>
</table>
<p>
Dalam hal ini bertindak sebagai Pemegang Barang Inventaris pada Pengguna Barang dan selanjutnya disebut sebagai <b>PIHAK KEDUA</b>.
</p>


<p>
Dengan ini menerangkan bahwa <b>PIHAK KESATU</b> telah menerima pengembalian Barang Milik Daerah (BMD) dari <b>PIHAK KEDUA</b> sesuai Berita Acara Penyaluran nomor: '.htmlspecialchars($nomor_surat_lama).' dikarenakan telah berakhirnya masa kerja, masa jabatan, atau berakhirnya waktu penggunaan <b>PIHAK KEDUA</b> yang selanjutnya akan dilakukan pencatatan kembali ke dalam administrasi dengan rincian hasil pemeriksaan <b>PIHAK KESATU</b> sebagai berikut:
</p>


<table width="100%" cellpadding="1" style="line-height:1.2;">
<tr>
  <td width="32%">Nama Barang</td>
  <td width="2%">:</td>
  <td width="66%" style="padding-left:2px;">'.$barang['nama_barang'].'</td>
</tr>
<tr>
  <td>Merk/Spesifikasi</td>
  <td width="2%">:</td>
  <td style="padding-left:2px;">'.$barang['merk_tipe'].'</td>
</tr>
<tr>
  <td>Tahun Pengadaan</td>
  <td width="2%">:</td>
  <td style="padding-left:2px;">'.$tahun_pengadaan_tampil.'</td>
</tr>
<tr>
  <td>Kode Barang</td>
  <td width="2%">:</td>
  <td style="padding-left:2px;">'.($barang['grid_barang'] ?? $barang['kode_barang']).'</td>
</tr>
<tr>
  <td>Register</td>
  <td width="2%">:</td>
  <td style="padding-left:2px;">'.$no_reg.'</td>
</tr>
<tr>
  <td>Kondisi Barang</td>
  <td width="2%">:</td>
  <td style="padding-left:2px;"><b>'.$kondisi_tampil.'</b></td>
</tr>
<tr>
  <td>Keterangan</td>
  <td width="2%">:</td>
  <td style="padding-left:2px;">'.$keterangan_tampil.'</td>
</tr>
</table>


<p>
Apabila terdapat kerusakan, kekurangan, atau kehilangan barang, maka akan ditindaklanjuti sesuai pengelolaan Barang Milik Daerah (BMD) yang berlaku. Dengan ditandatanganinya Berita Acara ini, tanggungjawab atas Barang Milik Daerah (BMD) tersebut beralih dan dibebankan kembali kepada <b>PIHAK KESATU</b> sebagai Pengelola Sarpras untuk selanjutnya dilakukan penatausahaan sesuai ketentuan.
</p>


<p>
Demikian Berita Acara Pengembalian Barang Milik Daerah (BMD) ini dibuat dalam rangkap 2 (dua) untuk digunakan sebagaimana mestinya.
</p>


<table width="100%" style="margin-top: 5px;">
    <tr align="center" nobr="true">
        <td width="45%">
            <b>PIHAK KEDUA</b>
            <br><br><br><br>
            <b>'.$nama_format.'</b><br>
            NIP. '.$pegawai['nip'].'
        </td>

        <td width="10%"></td>

        <td width="40%">
            <b>PIHAK PERTAMA</b>
            <br><br><br><br>
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
$pdf->Output('BAST_Pengembalian_'.$barang['nama_barang'].'_'.$no_reg.'.pdf', 'I');

ob_end_flush();
exit;
?>