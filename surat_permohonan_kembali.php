<?php
ob_start();
session_start();
error_reporting(0);

require_once('tcpdf/tcpdf.php');
include "koneksi.php";

// Fungsi untuk membuat NAMA UTAMA KAPITAL SEMUA, sedangkan gelar di belakang koma tetap rapi (contoh: MUHAMMAD ANDRIY, S.Kom)
function formatNamaGelar($nama) {
    if (empty($nama)) return '';
    if (strpos($nama, ',') === false) {
        return strtoupper(trim($nama));
    }
    $parts = explode(',', $nama);
    $nama_utama = strtoupper(trim(array_shift($parts))); // Nama utama KAPITAL SEMUA
    
    $formatted_gelar = array_map(function($g) {
        $g = trim($g);
        if (empty($g)) return '';
        // Format gelar: huruf awal setelah titik/spasi kapital (misal: s.kom -> S.Kom, s.p. -> S.P., m.m -> M.M)
        return ucwords(strtolower($g), " .");
    }, $parts);
    
    return $nama_utama . ', ' . implode(', ', $formatted_gelar);
}

$id_sekolah = $_SESSION['id_sekolah'] ?? '';
if (empty($id_sekolah)) {
    die("Sesi login berakhir, silakan login kembali.");
}

// Ambil parameter dari GET
$nama_penerima = isset($_GET['nama_penerima']) ? mysqli_real_escape_string($conn, $_GET['nama_penerima']) : '';
$tgl_input     = isset($_GET['tanggal_cetak']) ? $_GET['tanggal_cetak'] : date('Y-m-d');
$nomor_input   = isset($_GET['nomor_surat']) ? $_GET['nomor_surat'] : '';

if (empty($nama_penerima)) {
    die("Parameter nama penerima tidak valid atau kosong.");
}

// Nama penerima khusus untuk tampilan PDF (nama utama kapital semua, gelar rapi)
$nama_penerima_tampil = formatNamaGelar($nama_penerima);

// Ambil data instansi dari kop_surat (SAMA PERSIS LOGIKANYA DENGAN BAST_PDF.PHP)
$q_kop = mysqli_query($conn, "SELECT * FROM kop_surat WHERE id_sekolah = '$id_sekolah' LIMIT 1");
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

$nama_sekolah   = $kop['nama_sekolah']; 
$kota_kab       = ucwords(strtolower($kop['kota'] ?? 'Cirebon'));

// Ambil Data Kepala Sekolah & NIP secara dinamis dari database (tabel kop_surat)
$nama_kepsek_raw = $kop['nama_kepsek'] ?? $kop['nama_kepala_sekolah'] ?? 'IPAN SUPANDI';
$nama_kepsek     = formatNamaGelar($nama_kepsek_raw); // Nama utama kapital semua, gelar rapi
$nip_kepsek      = $kop['nip_kepsek'] ?? $kop['nip_kepala_sekolah'] ?? '198603012022211007';

// Ambil data pegawai/penerima untuk mendapatkan jabatan secara dinamis dari kolom 'jabatan'
$q_pegawai = mysqli_query($conn, "SELECT * FROM pegawai WHERE nama_lengkap = '$nama_penerima' AND id_sekolah = '$id_sekolah' LIMIT 1");
$pegawai   = mysqli_fetch_assoc($q_pegawai);
$jabatan_penerima = !empty($pegawai['jabatan']) ? $pegawai['jabatan'] : 'Staf Kepegawaian';

// Format tanggal Indonesia untuk Titimangsa
function tgl_indo_surat($tanggal) {
    $bulan = array (1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember');
    $pecahkan = explode('-', $tanggal);
    if (count($pecahkan) < 3) return $tanggal;
    return (int)$pecahkan[2] . ' ' . $bulan[(int)$pecahkan[1]] . ' ' . $pecahkan[0];
}
$tanggal_cetak_indo = tgl_indo_surat($tgl_input);

// Ambil daftar barang miliknya
$query_barang = "SELECT * FROM penyaluran WHERE nama_penerima = '$nama_penerima' AND id_sekolah = '$id_sekolah' ORDER BY nama_barang ASC";
$q_barang     = mysqli_query($conn, $query_barang);

// Inisialisasi TCPDF Ukuran F4 Potret (Margin disesuaikan 22mm seperti bast_pdf.php)
$pdf = new TCPDF('P', 'mm', array(215, 330), true, 'UTF-8', false);
$pdf->setPrintHeader(false);
$pdf->setPrintFooter(false);
$pdf->SetMargins(22, 10, 22); 
$pdf->SetAutoPageBreak(true, 20);
$pdf->SetTitle('Surat Permohonan Pengembalian BMD');
$pdf->AddPage();

$pdf->SetFont('times', '', 11);

/* =========================
   KOP SURAT (SAMA PERSIS 100% DENGAN BAST_PDF.PHP)
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

// TAMPILKAN KOP DULU
$pdf->writeHTML($kop_html, true, false, true, false, '');

// AMBIL POSISI Y TERAKHIR SECARA OTOMATIS SEPERTI BAST_PDF.PHP
$y = $pdf->GetY();

// GARIS ATAS (TEBAL) - SAMA PERSIS 100%
$pdf->SetLineWidth(0.8);
$pdf->Line(22, $y - 4, 193, $y - 4);

// GARIS BAWAH (TIPIS) - SAMA PERSIS 100%
$pdf->SetLineWidth(0.3);
$pdf->Line(22, $y - 3, 193, $y - 3);

// Jarak ke bawah pasca garis
$pdf->Ln(4);

// 2. META DATA SURAT & TUJUAN (Menggunakan nomor surat dari input modal)
$nomor_surat = !empty($nomor_input) ? htmlspecialchars($nomor_input) : "..................................................";

// Layout displit 2 kolom terpisah & bagian Yth. memakai sub-kolom agar lanjutan nama panjang tidak masuk ke bawah Yth.
$html_meta_tujuan = '
<table border="0" cellpadding="0" cellspacing="0" width="100%" style="font-family:times; font-size:11pt; line-height:1.5;">
    <tr>
        <td width="50%" valign="top">
            <table border="0" cellpadding="0" cellspacing="0" width="100%">
                <tr>
                    <td width="26%">Nomor</td>
                    <td width="4%">:</td>
                    <td width="70%">'.$nomor_surat.'</td>
                </tr>
                <tr>
                    <td>Sifat</td>
                    <td>:</td>
                    <td>Penting</td>
                </tr>
                <tr>
                    <td>Lampiran</td>
                    <td>:</td>
                    <td>-</td>
                </tr>
                <tr>
                    <td valign="top">Perihal</td>
                    <td valign="top">:</td>
                    <td valign="top" style="text-align:justify;">Permohonan Pengembalian Barang Milik Daerah (BMD)</td>
                </tr>
            </table>
        </td>
        <td width="8%"></td>
        <td width="42%" valign="top">
            Kepada,<br>
            <table border="0" cellpadding="0" cellspacing="0" width="100%">
                <tr>
                    <td width="15%" valign="top">Yth.</td>
                    <td width="85%" valign="top"><b>Bpk./Ibu '.$nama_penerima_tampil.'</b></td>
                </tr>
            </table>
            di<br>
            &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;Tempat
        </td>
    </tr>
</table>';
$pdf->writeHTML($html_meta_tujuan, true, false, true, false, '');

$pdf->Ln(-10);

// 3. PEMBUATAN TABEL BARANG
$html_tabel_barang = '
<table border="1" cellpadding="5" style="border-collapse:collapse; font-family:times; font-size:10pt; width:100%;">
    <thead>
        <tr style="font-weight:bold; text-align:center;">
            <th width="8%">No</th>
            <th width="37%">Nama Barang</th>
            <th width="25%">Kode Barang</th>
            <th width="15%">Register</th>
            <th width="15%">Jumlah</th>
        </tr>
    </thead>
    <tbody>';

$no = 1;
if ($q_barang && mysqli_num_rows($q_barang) > 0) {
    while ($b = mysqli_fetch_assoc($q_barang)) {
        $html_tabel_barang .= '
        <tr>
            <td width="8%" align="center">'.$no++.'</td>
            <td width="37%">'.$b['nama_barang'].'</td>
            <td width="25%" align="center">'.$b['kode_barang'].'</td>
            <td width="15%" align="center">'.$b['no_reg'].'</td>
            <td width="15%" align="center">'.$b['vol_penyaluran'].'</td>
        </tr>';
    }
} else {
    $html_tabel_barang .= '<tr><td colspan="5" align="center">Tidak ada data aset terikat.</td></tr>';
}
$html_tabel_barang .= '</tbody></table>';


// 4. PEMBUNGKUS KONTEN TUBUH SURAT (SEJAJAR KANAN)
$html_konten_sejajar = '
<table border="0" cellpadding="0" cellspacing="0" width="100%" style="font-family:times; font-size:11pt; line-height:1.5;">
    <tr>
        <td width="15%"></td>
        
        <td width="85%" style="text-align:justify;">
            
            <p style="text-indent:35px; margin-top:0; margin-bottom:12px; padding-top:0;">
                Disampaikan dengan hormat, sehubungan dengan telah berakhirnya masa tugas atau jabatan Bapak/Ibu sebagai <b>'.$jabatan_penerima.'</b> di <b>'.$nama_sekolah.'</b>. Berdasarkan hasil inventarisasi Barang Milik Daerah (BMD) yang dilakukan oleh pengelola sarpras, masih terdapat barang Milik Pemerintah Daerah (BMD) yang tercatat berada dalam tanggungjawab Bapak/Ibu. Berdasarkan data pengelola sarpras, rincian barang yang dimaksud adalah sebagai berikut:
            </p>
            
            <br>
            '.$html_tabel_barang.'
            
            <p style="text-indent:35px; margin-top:8px; margin-bottom:12px;">
                Sehubungan dengan hal tersebut, kami mohon kepada Bapak/Ibu agar dapat mengembalikan Barang Milik Daerah (BMD) diatas kepada Pengelola Sarpras <b>'.$nama_sekolah.'</b> paling lambat 7 (tujuh) hari kerja sejak dikeluarkannya surat ini.
            </p>
            
            <p style="text-indent:35px; margin-bottom:12px;">
                Apabila terdapat kendala dalam proses pengembalian, diharapkan Bapak/Ibu segera berkoordinasi dengan Pengelola Sarpras agar dapat ditindaklanjuti sesuai ketentuan yang berlaku.
            </p>
            
            <p style="text-indent:35px; margin-bottom:0;">
                Demikian surat ini disampaikan, atas perhatian dan kerjasamanya diucapkan terima kasih.
            </p>
            
        </td>
    </tr>
</table>';

$pdf->writeHTML($html_konten_sejajar, true, false, true, false, '');
$pdf->Ln(6);

// 5. AREA TANDA TANGAN (POSISI KOLOM KANAN DENGAN PERATAAN CENTER)
$html_ttd = '
<table border="0" cellpadding="0" cellspacing="0" width="100%" style="font-family:times; font-size:11pt; line-height:1.5;">
    <tr>
        <td width="45%"></td>
        
        <td width="55%" align="center">
            <div style="text-align:center; width:100%;">
                '.$kota_kab.', '.$tanggal_cetak_indo.'<br>
                <b>KEPALA SEKOLAH</b><br>
                <b>'.strtoupper($nama_sekolah).'</b>
                <br><br><br><br>
                <b>'.$nama_kepsek.'</b><br>
                NIP. '.$nip_kepsek.'
            </div>
        </td>
    </tr>
</table>';
$pdf->writeHTML($html_ttd, true, false, true, false, '');

if (ob_get_length()) ob_end_clean();
$pdf->Output('Surat_Permohonan_Kembali_'.str_replace(' ', '_', $nama_penerima).'.pdf', 'I');
?>