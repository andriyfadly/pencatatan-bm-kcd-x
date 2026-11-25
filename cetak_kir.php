<?php 
ob_start();
error_reporting(0); // Mencegah blank jika terjadi kendala minor/notice server
require_once('tcpdf/tcpdf.php');
include "koneksi.php";

session_start();
// Ambil id_sekolah dari session login
$id_sekolah_login = $_SESSION['id_sekolah'] ?? '';

// Jika tidak ada session id_sekolah, hentikan script
if(empty($id_sekolah_login)){
    die("Sesi berakhir, silakan login kembali.");
}

// ================= FUNGSI UNTUK MERAPIKAN NAMA DAN GELAR =================
function formatNamaGelar($nama) {
    if (empty($nama) || trim($nama) === '-') return '-';
    $parts = explode(',', $nama);
    $namaUtama = strtoupper(trim(array_shift($parts)));
    
    if (!empty($parts)) {
        $gelar = implode(',', $parts);
        $gelar = ucwords(strtolower(trim($gelar)));
        $search  = ['S.pd', 'M.pd', 'S.p', 'S.kom', 'S.t', 'M.m', 'S.e', 'S.h', 'S.sos', 'S.si', 'M.si', 'S.ip', 'M.ip', 'S.psi', 'S.ked', 'Dr.', 'Drs.', 'Dra.'];
        $replace = ['S.Pd', 'M.Pd', 'S.P', 'S.Kom', 'S.T', 'M.M', 'S.E', 'S.H', 'S.Sos', 'S.Si', 'M.Si', 'S.IP', 'M.IP', 'S.Psi', 'S.Ked', 'Dr.', 'Drs.', 'Dra.'];
        $gelarFormatted = str_replace($search, $replace, $gelar);
        return $namaUtama . ', ' . $gelarFormatted;
    }
    return $namaUtama;
}

// Ambil parameter dari URL
$ruangan = $_GET['ruangan'] ?? '';
$nama_pj_input_raw = $_GET['penanggung_jawab'] ?? '';
$tgl_input = $_GET['tanggal_cetak'] ?? date('Y-m-d');

/**
 * FUNGSI KONVERSI TANGGAL KE INDONESIA
 */
function tgl_indo($tanggal){
    $bulan = array (
        1 =>   'Januari',
        'Februari',
        'Maret',
        'April',
        'Mei',
        'Juni',
        'Juli',
        'Agustus',
        'September',
        'Oktober',
        'November',
        'Desember'
    );
    $pecahkan = explode('-', $tanggal);
    if(count($pecahkan) < 3) return $tanggal;
    return $pecahkan[2] . ' ' . $bulan[ (int)$pecahkan[1] ] . ' ' . $pecahkan[0];
}

$tanggal_indo = tgl_indo($tgl_input);

// Ambil Opsi Checkbox untuk Tanda Tangan (1 = tampil, 0 = tidak)
$show_ks  = $_GET['show_ks'] ?? 0;
$show_wks = $_GET['show_wks'] ?? 0;
$show_pb  = $_GET['show_pb'] ?? 0;
$show_pj  = $_GET['show_pj'] ?? 0;

/* ================= AMBIL DATA KOP & PEJABAT ================= */
$qkop = mysqli_query($conn, "SELECT * FROM kop_surat WHERE id_sekolah = '$id_sekolah_login' LIMIT 1");
$kop = mysqli_fetch_assoc($qkop);

if(!$kop) {
    die("Data profil sekolah (KOP) belum diatur.");
}

// MENGAMBIL NAMA KOTA DARI DATABASE & FORMAT HURUF AWAL BESAR SISANYA KECIL
$kota_raw = $kop['kota_kab'] ?? $kop['kota'] ?? '...'; 
$kota_input = ucwords(strtolower($kota_raw));

$logo_path = 'upload_kop/logo.jpg'; 

// Format nama-nama pejabat agar gelarnya rapi
$nama_ks   = formatNamaGelar($kop['nama_kepala_sekolah'] ?? '-');
$nip_ks    = $kop['nip_kepala_sekolah'] ?? '-';
$nama_wks  = formatNamaGelar($kop['nama_wakasek_sarpras'] ?? '-');
$nip_wks   = $kop['nip_wakasek_sarpras'] ?? '-';
$nama_pb   = formatNamaGelar($kop['nama_pengurus_barang'] ?? '-');
$nip_pb    = $kop['nip_kepala_sekolah'] ?? '-'; 
$nama_pj_input = formatNamaGelar($nama_pj_input_raw); 

/* ================= AMBIL NIP PENANGGUNG JAWAB ================= */
$nip_pj = '-';
if(!empty($nama_pj_input_raw)){
    $nama_pj_esc = mysqli_real_escape_string($conn, $nama_pj_input_raw);
    $q_pj = mysqli_query($conn, "SELECT nip FROM pegawai WHERE nama_lengkap = '$nama_pj_esc' AND id_sekolah = '$id_sekolah_login' LIMIT 1");
    $data_pj = mysqli_fetch_assoc($q_pj);
    if($data_pj) $nip_pj = $data_pj['nip'];
}

// Persiapkan Logo
$logoHTML = '';
if (!empty($kop['logo']) && file_exists($logo_path)) {
    $logoHTML = '<img src="' . $logo_path . '" width="85">';
}

/* ================= QUERY DATA BARANG ================= */
$ruangan_esc = mysqli_real_escape_string($conn, $ruangan);
$query_kir = "
    SELECT 
        p.*, 
        d.bahan, 
        d.ukuran_konstruksi, 
        cbd.kondisi AS keadaan_barang,
        p.merk_tipe AS penyaluran_merk
    FROM penyaluran p
    LEFT JOIN data_barang d ON p.kode_barang = d.kode_barang 
        AND p.merk_tipe = d.merek_type 
        AND p.tgl_pengadaan = d.tanggal_perolehan
        AND p.bln_pengadaan = d.bulan_perolehan
        AND p.thn_pengadaan = d.tahun_perolehan
        AND d.id_sekolah = '$id_sekolah_login'
    LEFT JOIN kondisi_barang_detail cbd ON d.id = cbd.id_barang 
        AND p.no_reg = cbd.no_reg
    WHERE p.id_sekolah = '$id_sekolah_login' 
    AND p.lokasi = '$ruangan_esc'
    ORDER BY p.nama_barang ASC, p.no_reg ASC
";

$q = mysqli_query($conn, $query_kir);

/* ================= KONFIGURASI PDF (UKURAN KERTAS F4) ================= */
$pdf = new TCPDF('L', 'mm', array(215, 330), true, 'UTF-8', false);
$pdf->setPrintHeader(false);
$pdf->setPrintFooter(false);
$pdf->SetMargins(10, 10, 10);
$pdf->SetAutoPageBreak(true, 15);
$pdf->SetTitle('KIR - ' . $ruangan);
$pdf->AddPage();

/* ================= 1. KOP SURAT ================= */
$kop_html = '
<table width="100%" border="0" cellpadding="0" cellspacing="0">
    <tr>
        <td width="15%" align="center" valign="middle">'.$logoHTML.'</td>
        <td width="70%" align="center" valign="middle" style="line-height:1.15;">
            <span style="font-size:14pt;">'.strtoupper($kop['nama_provinsi'] ?? '').'</span><br>
            <span style="font-size:14pt;">'.strtoupper($kop['nama_dinas'] ?? '').'</span><br>
            <span style="font-size:14pt;">CABANG DINAS PENDIDIKAN WILAYAH X</span><br>
            <b style="font-size:16pt;">'.strtoupper($kop['nama_sekolah'] ?? '').'</b><br>
            <span style="font-size:10pt;">'.($kop['alamat'] ?? '').' Email: '.($kop['email'] ?? '').'</span><br>
            <b style="font-size:10pt;">'.strtoupper($kota_input).' - '.($kop['kode_pos'] ?? '').'</b>
        </td>
        <td width="15%"></td>
    </tr>
</table>';
$pdf->writeHTML($kop_html, true, false, true, false, '');

$y_now = $pdf->GetY();
$pdf->SetLineWidth(0.8);
$pdf->Line(10, $y_now + 1, 320, $y_now + 1); 
$pdf->SetLineWidth(0.3);
$pdf->Line(10, $y_now + 2.2, 320, $y_now + 2.2); 
$pdf->SetY($y_now + 6);

/* ================= 2. JUDUL DAN INFORMASI ================= */
$pdf->SetFont('times', 'B', 13);
$pdf->Cell(0, 7, 'KARTU INVENTARIS RUANGAN (KIR)', 0, 1, 'C');
$pdf->Ln(2);

$pdf->SetFont('times', '', 10);
$html_info = '
<table border="0" cellpadding="2">
    <tr>
        <td width="12%">Unit Kerja</td>
        <td width="2%">:</td>
        <td width="51%">'.ucwords(strtolower($kop['nama_sekolah'] ?? '')).'</td>
        <td width="10%">Ruangan</td>
        <td width="2%">:</td>
        <td width="23%">'.ucwords(strtolower($ruangan)).'</td>
    </tr>
</table>';
$pdf->writeHTML($html_info, true, false, true, false, '');

/* ================= 3. TABEL DATA BARANG ================= */
$w_no = '4%'; $w_nama = '13%'; $w_merk = '13%'; $w_seri = '10%'; $w_uk = '8%'; 
$w_bh = '8%'; $w_th = '7%'; $w_kd = '11%'; $w_jml = '7%'; $w_kond = '4%'; $w_ket = '7%';

$html_isi = '
<style>
    table.main { border-collapse:collapse; width:100%; font-size:9px; }
    th { border:1px solid black; font-weight:bold; text-align:center; vertical-align:middle; line-height:1.2; }
    td { border:1px solid black; vertical-align:middle; }
</style>
<table class="main" cellpadding="5">
    <thead>
        <tr>
            <th rowspan="2" width="'.$w_no.'" valign="middle"><br><br>NO</th>
            <th rowspan="2" width="'.$w_nama.'" valign="middle"><br><br>Jenis Barang / Nama Barang</th>
            <th rowspan="2" width="'.$w_merk.'" valign="middle"><br><br>Merk / Model</th>
            <th rowspan="2" width="'.$w_seri.'" valign="middle"><br><br>No. Seri Pabrik</th>
            <th rowspan="2" width="'.$w_uk.'" valign="middle"><br><br>Ukuran</th>
            <th rowspan="2" width="'.$w_bh.'" valign="middle"><br><br>Bahan</th>
            <th rowspan="2" width="'.$w_th.'" valign="middle"><br><br>Pengadaan</th>
            <th rowspan="2" width="'.$w_kd.'" valign="middle"><br><br>Kode Barang</th>
            <th rowspan="2" width="'.$w_jml.'" valign="middle"><br><br>Jumlah</th>
            <th colspan="3" width="12%" valign="middle">Keadaan Barang</th>
            <th rowspan="2" width="'.$w_ket.'" valign="middle"><br><br>Ket</th>
        </tr>
        <tr>
            <th width="'.$w_kond.'" valign="middle">Baik</th>
            <th width="'.$w_kond.'" valign="middle">RR</th>
            <th width="'.$w_kond.'" valign="middle">RB</th>
        </tr>
    </thead>
    <tbody>';

$no = 1;
$grouped_data = [];

if($q && mysqli_num_rows($q) > 0) {
    while($d = mysqli_fetch_assoc($q)){
        $nama_barang = $d['nama_barang'];
        
        $raw_merk = !empty($d['penyaluran_merk']) ? $d['penyaluran_merk'] : ($d['merek_type'] ?? '-');
        $display_merk = ($raw_merk !== '-') ? ucwords(strtolower($raw_merk)) : '-';
        
        $tahun_pengadaan = $d['thn_pengadaan'];
        
        $key = $nama_barang . '|' . $display_merk . '|' . $tahun_pengadaan;
        
        if(!isset($grouped_data[$key])) {
            $grouped_data[$key] = $d;
            $grouped_data[$key]['merk_final'] = $display_merk;
            $grouped_data[$key]['total_jumlah'] = 0;
            $grouped_data[$key]['count_B'] = 0;
            $grouped_data[$key]['count_RR'] = 0;
            $grouped_data[$key]['count_RB'] = 0;
        }
        
        $vol = (int)$d['vol_penyaluran'];
        $grouped_data[$key]['total_jumlah'] += $vol;
        
        $kondisi = strtoupper(trim($d['keadaan_barang'] ?? 'B')); 
        if($kondisi == "RB") {
            $grouped_data[$key]['count_RB'] += $vol;
        } elseif($kondisi == "KB" || $kondisi == "RR") {
            $grouped_data[$key]['count_RR'] += $vol;
        } else {
            $grouped_data[$key]['count_B'] += $vol;
        }
    }
    
    foreach($grouped_data as $key => $d) {
        $ukuran = !empty($d['ukuran_konstruksi']) ? $d['ukuran_konstruksi'] : '-';
        $bahan  = !empty($d['bahan']) ? $d['bahan'] : '-';
        $ket    = !empty($d['keterangan']) ? $d['keterangan'] : '';

        $val_B  = ($d['count_B'] > 0) ? $d['count_B'] : '';
        $val_RR = ($d['count_RR'] > 0) ? $d['count_RR'] : '';
        $val_RB = ($d['count_RB'] > 0) ? $d['count_RB'] : '';

        // Hitung estimasi jumlah baris per sel berdasarkan kapasitas karakter kolom
        $l_nama = max(1, (int)ceil(strlen(trim($d['nama_barang'])) / 22));
        $l_merk = max(1, (int)ceil(strlen(trim($d['merk_final'])) / 20));
        $l_uk   = max(1, (int)ceil(strlen(trim($ukuran)) / 13));
        $l_bh   = max(1, (int)ceil(strlen(trim($bahan)) / 13));
        $l_kd   = max(1, (int)ceil(strlen(trim($d['kode_barang'])) / 18));
        $l_ket  = max(1, (int)ceil(strlen(trim($ket)) / 12));

        // Tentukan jumlah baris maksimum dalam satu row
        $max_b = max($l_nama, $l_merk, $l_uk, $l_bh, $l_kd, $l_ket, 1);

        // Penyesuaian line-height dinamis agar teks 1 baris berada tepat di tengah vertikal
        $get_lh = function($cell_lines) use ($max_b) {
            if ($max_b <= 1) return 'line-height:1.25;';
            if ($cell_lines == 1) {
                $lh = 1.25 + ($max_b - 1) * 1.15;
                return 'line-height:' . sprintf("%.2f", $lh) . ';';
            }
            return 'line-height:1.25;';
        };

        $st_no   = $get_lh(1);
        $st_nama = $get_lh($l_nama);
        $st_merk = $get_lh($l_merk);
        $st_seri = $get_lh(1);
        $st_uk   = $get_lh($l_uk);
        $st_bh   = $get_lh($l_bh);
        $st_th   = $get_lh(1);
        $st_kd   = $get_lh($l_kd);
        $st_jml  = $get_lh(1);
        $st_b    = $get_lh(1);
        $st_rr   = $get_lh(1);
        $st_rb   = $get_lh(1);
        $st_ket  = $get_lh($l_ket);

        $html_isi .= '
        <tr>
            <td width="'.$w_no.'" align="center" valign="middle" style="'.$st_no.'">'.$no++.'</td>
            <td width="'.$w_nama.'" align="left" valign="middle" style="'.$st_nama.'">'.$d['nama_barang'].'</td>
            <td width="'.$w_merk.'" align="left" valign="middle" style="'.$st_merk.'">'.$d['merk_final'].'</td>
            <td width="'.$w_seri.'" align="center" valign="middle" style="'.$st_seri.'">-</td>
            <td width="'.$w_uk.'" align="center" valign="middle" style="'.$st_uk.'">'.$ukuran.'</td>
            <td width="'.$w_bh.'" align="center" valign="middle" style="'.$st_bh.'">'.$bahan.'</td>
            <td width="'.$w_th.'" align="center" valign="middle" style="'.$st_th.'">'.$d['thn_pengadaan'].'</td>
            <td width="'.$w_kd.'" align="center" valign="middle" style="'.$st_kd.'">'.$d['kode_barang'].'</td>
            <td width="'.$w_jml.'" align="center" valign="middle" style="'.$st_jml.'">'.$d['total_jumlah'].'</td>
            <td width="'.$w_kond.'" align="center" valign="middle" style="'.$st_b.'">'.$val_B.'</td>
            <td width="'.$w_kond.'" align="center" valign="middle" style="'.$st_rr.'">'.$val_RR.'</td>
            <td width="'.$w_kond.'" align="center" valign="middle" style="'.$st_rb.'">'.$val_RB.'</td>
            <td width="'.$w_ket.'" align="left" valign="middle" style="'.$st_ket.'">'.$ket.'</td>
        </tr>';
    }
} else {
    $html_isi .= '<tr><td colspan="13" align="center" valign="middle">Data barang tidak ditemukan.</td></tr>';
}
$html_isi .= '</tbody></table>';
$pdf->writeHTML($html_isi, true, false, true, false, '');

/* ================= 4. AREA TANDA TANGAN ================= */
$pdf->Ln(5);

$pejabat = [];
if($show_ks)  $pejabat[] = ['label' => 'Kepala Sekolah', 'nama' => $nama_ks, 'nip' => $nip_ks, 'ks' => true];
if($show_wks) $pejabat[] = ['label' => 'Wakasek Sarpras', 'nama' => $nama_wks, 'nip' => $nip_wks, 'ks' => false];
if($show_pb)  $pejabat[] = ['label' => 'Pengurus Barang Pembantu', 'nama' => $nama_pb, 'nip' => $nip_pb, 'ks' => false];

$pj_item = ($show_pj) ? ['label' => 'Penanggung Jawab Ruangan', 'nama' => $nama_pj_input, 'nip' => $nip_pj, 'ks' => false] : null;

$count_p = count($pejabat);
$total = $count_p + ($pj_item ? 1 : 0);

if ($total == 3) {
    $p1 = $pejabat[0]; 
    $p2 = $pejabat[1]; 
    $p3 = ($pj_item) ? $pj_item : $pejabat[2];
} elseif ($total == 2) {
    $p1 = $pejabat[0]; 
    $p2 = null; 
    $p3 = ($pj_item) ? $pj_item : $pejabat[1];
} else {
    $p1 = null; $p2 = null;
    $p3 = ($pj_item) ? $pj_item : ($pejabat[0] ?? null);
}

$html_ttd = '<table border="0" width="100%" style="text-align:center; font-size:10px;">';

$html_ttd .= '<tr>';
$html_ttd .= '<td width="33%">'.(($p1 && isset($p1['ks']) && $p1['ks']) ? 'Mengetahui,' : '').'</td>';
$html_ttd .= '<td width="33%">'.(($p2 && isset($p2['ks']) && $p2['ks']) ? 'Mengetahui,' : '').'</td>';
$html_ttd .= '<td width="33%">'.$kota_input.', '.$tanggal_indo.'</td>';
$html_ttd .= '</tr>';

$html_ttd .= '<tr>';
$html_ttd .= '<td width="33%">'.($p1 ? $p1['label'] : '').'</td>';
$html_ttd .= '<td width="33%">'.($p2 ? $p2['label'] : '').'</td>';
$html_ttd .= '<td width="33%">'.($p3 ? $p3['label'] : '').'</td>';
$html_ttd .= '</tr>';

$html_ttd .= '<tr><td height="50px" colspan="3"></td></tr>';

$html_ttd .= '<tr>';
$html_ttd .= '<td width="33%">'.($p1 ? '<b>'.$p1['nama'].'</b><br>NIP. '.$p1['nip'] : '').'</td>';
$html_ttd .= '<td width="33%">'.($p2 ? '<b>'.$p2['nama'].'</b><br>NIP. '.$p2['nip'] : '').'</td>';
$html_ttd .= '<td width="33%">'.($p3 ? '<b>'.$p3['nama'].'</b><br>NIP. '.$p3['nip'] : '').'</td>';
$html_ttd .= '</tr>';

$html_ttd .= '</table>';

$pdf->writeHTML($html_ttd, true, false, true, false, '');

if (ob_get_length()) ob_end_clean();
$pdf->Output('KIR_'.str_replace(' ', '_', $ruangan).'.pdf', 'I');
?>