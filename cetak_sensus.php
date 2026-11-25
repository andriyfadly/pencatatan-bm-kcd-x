<?php
session_start();

if(!isset($_SESSION['id_user'])){
    die("Akses ditolak. Sesi Anda telah berakhir.");
}

include "koneksi.php";

$id_sekolah = $_SESSION['id_sekolah'];

// Ambil data nama sekolah untuk keperluan header & dokumen
$qSekolah = mysqli_query($conn, "SELECT nama_sekolah FROM kode_sekolah WHERE id='$id_sekolah' LIMIT 1");
$dataSekolah = mysqli_fetch_assoc($qSekolah);
$nama_sekolah = $dataSekolah['nama_sekolah'] ?? "SEKOLAH";

// Ambil keyword pencarian jika ada dari URL
$search = isset($_GET['search']) ? mysqli_real_escape_string($conn, $_GET['search']) : '';

// Query ambil data penyaluran
$queryStr = "SELECT * FROM penyaluran WHERE id_sekolah = '$id_sekolah'";
if (!empty($search)) {
    $queryStr .= " AND (nama_penerima LIKE '%$search%' OR nama_barang LIKE '%$search%' OR merk_tipe LIKE '%$search%' OR no_reg LIKE '%$search%')";
}
// SORTING: Urutkan berdasarkan Nama Pegawai, lalu Nama Barang, lalu No Register (Kecil ke Besar)
$queryStr .= " ORDER BY nama_penerima ASC, nama_barang ASC, no_reg ASC";

$qPenyaluran = mysqli_query($conn, $queryStr);

// Proses pengelompokan data di memori PHP
$kelompok_penerima = [];
while ($row = mysqli_fetch_assoc($qPenyaluran)) {
    $penerima = !empty($row['nama_penerima']) ? $row['nama_penerima'] : 'TIDAK TERIDENTIFIKASI';
    $kelompok_penerima[$penerima][] = $row;
}

// Bersihkan buffer mencegah file PDF rusak/corrupt saat di-generate
if (ob_get_contents()) ob_end_clean();

// Memanggil library TCPDF (Sesuaikan dengan lokasi folder tcpdf di server Anda)
if (file_exists('tcpdf/tcpdf.php')) {
    require_once('tcpdf/tcpdf.php');
} else {
    require_once('../tcpdf/tcpdf.php');
}

/**
 * CUSTOM UKURAN KERTAS F4 (FOLIO) LANDSCAPE
 * Standar F4: Lebar 215 mm, Panjang 330 mm. 
 */
$pdf = new TCPDF('L', PDF_UNIT, array(330, 215), true, 'UTF-8', false);
$pdf->SetCreator(PDF_CREATOR);
$pdf->SetAuthor('SI DIPTA');
$pdf->SetTitle('Sensus Barang Milik Daerah');

// Nonaktifkan header & footer bawaan TCPDF agar bisa dikustomisasi penuh
$pdf->setPrintHeader(false);
$pdf->setPrintFooter(false);

// Set margin halaman (Kiri, Atas, Kanan)
$pdf->SetMargins(15, 15, 15);
$pdf->SetAutoPageBreak(TRUE, 15);
$pdf->SetFont('Helvetica', '', 10);
$pdf->AddPage();

// Menyusun struktur dokumen HTML khusus TCPDF
$html = '
<div style="text-align: center; margin-bottom: 20px;">
    <strong style="font-size: 14px; text-transform: uppercase;">SENSUS BARANG MILIK DAERAH PERALATAN DAN MESIN</strong><br>
    <span style="font-size: 12px; text-transform: uppercase;">'.$nama_sekolah.'</span>
</div>
<br><br>
<table border="1" cellpadding="4" cellspacing="0" style="width: 100%; border-collapse: collapse; font-size: 10px;">
    <thead>
        <tr style="background-color: #f1f5f9; font-weight: bold;">
            <th align="center" style="width: 4%; line-height: 22px;">NO</th>
            <th align="center" style="width: 21%; line-height: 22px;">NAMA PEGAWAI</th>
            <th align="center" style="width: 14%; line-height: 22px;">NO. REGISTER</th>
            <th align="center" style="width: 35%; line-height: 22px;">BARANG / ASET</th>
            <th align="center" style="width: 14%; line-height: 22px;">LOKASI</th>
            <th align="center" style="width: 12%; line-height: 22px;">VOL/SATUAN</th>
        </tr>
    </thead>';

if (!empty($kelompok_penerima)) {
    $pdf_no = 1;
    foreach ($kelompok_penerima as $nama_pegawai => $daftar_barang) {
        $row_count = count($daftar_barang);
        $first = true;
        
        $html .= '<tbody>';

        foreach ($daftar_barang as $barang) {
            $html .= '<tr nobr="true">';
            
            // PENGGABUNGAN BARIS (ROWSPAN) untuk NO dan NAMA PEGAWAI
            if ($first) {
                $total_box_height = $row_count * 28; // Total tinggi area gabungan baris
                
                // Deteksi perkiraan jumlah baris nama pegawai (jika panjang > 22 karakter biasanya jadi 2 baris)
                $nama_len = strlen($nama_pegawai);
                $lines_pegawai = ($nama_len > 22) ? 2 : 1;
                $lh_pegawai = $total_box_height / $lines_pegawai;

                $html .= '<td rowspan="'.$row_count.'" align="center" style="width: 4%; line-height: '.$total_box_height.'px;">'.$pdf_no.'</td>';
                $html .= '<td rowspan="'.$row_count.'" align="left" style="width: 21%; font-weight: bold; line-height: '.$lh_pegawai.'px;">'.htmlspecialchars($nama_pegawai).'</td>';
                $pdf_no++;
            }

            // NO REGISTER (Selalu 1 baris -> line-height 28px)
            $html .= '<td align="center" style="width: 14%; color: #000000; font-family: courier; font-weight: bold; line-height: 28px;">'.(!empty($barang['no_reg']) ? htmlspecialchars($barang['no_reg']) : '-').'</td>';
            
            // IDENTITAS BARANG (2 baris teks -> line-height 14px agar totalnya tepat 28px)
            $html .= '<td align="left" style="width: 35%; line-height: 14px;">
                        <span style="font-weight: bold;">'.htmlspecialchars($barang['nama_barang']).'</span><br>
                        <span style="color: #555555; font-size: 9px;">'.htmlspecialchars($barang['merk_tipe']).' ('.$barang['kode_barang'].')</span>
                      </td>';
            
            // LOKASI (Menyesuaikan panjang teks)
            $lokasi_len = strlen($barang['lokasi']);
            $lokasi_lh = ($lokasi_len <= 22) ? '28px' : '14px';
            $html .= '<td align="center" style="width: 14%; line-height: '.$lokasi_lh.';">'.htmlspecialchars($barang['lokasi']).'</td>';
            
            // VOL/SATUAN (Selalu 1 baris -> line-height 28px)
            $html .= '<td align="center" style="width: 12%; line-height: 28px;">'.$barang['vol_penyaluran'].' '.$barang['sat_pengadaan'].'</td>';
            
            $html .= '</tr>';
            $first = false;
        }
        $html .= '</tbody>';
    }
} else {
    $html .= '<tbody><tr><td colspan="6" align="center" style="line-height: 30px;">Tidak ada data penyerahan barang yang ditemukan.</td></tr></tbody>';
}

$html .= '</table>';

// Tulis konten HTML ke dokumen PDF
$pdf->writeHTML($html, true, false, true, false, '');

// Preview PDF di browser tab baru
$pdf->Output('Sensus_Barang_Milik_Daerah_'.date('Ymd').'.pdf', 'I');
exit;
?>