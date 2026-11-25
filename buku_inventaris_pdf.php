<?php
// Mencegah output apapun sebelum header PDF dikirim
ob_start();
session_start();

// 1. Cek Login & Session
if(!isset($_SESSION['id_user'])){
    header("Location: login.php");
    exit;
}

include "koneksi.php"; 

$id_sekolah = $_SESSION['id_sekolah'];

// Tangkap data dari form
$tgl_cetak       = isset($_POST['tgl_cetak']) ? $_POST['tgl_cetak'] : date('Y-m-d');
$tahun_perolehan = isset($_POST['tahun_perolehan']) ? mysqli_real_escape_string($conn, $_POST['tahun_perolehan']) : '';
$semester        = isset($_POST['semester']) ? trim($_POST['semester']) : '';

// --- Ambil Data Sekolah & Kota dari database ---
$qSekolah = mysqli_query($conn,"SELECT nama_sekolah, kota_kab FROM kode_sekolah WHERE id='$id_sekolah' LIMIT 1");
$dataSekolah = mysqli_fetch_assoc($qSekolah);

$nama_sekolah_login = ($dataSekolah) ? strtoupper($dataSekolah['nama_sekolah']) : "SEKOLAH TIDAK DITEMUKAN";
$nama_kota = ($dataSekolah && !empty($dataSekolah['kota_kab'])) ? $dataSekolah['kota_kab'] : 'Cirebon';

// 3. Ambil Data Kepala Sekolah & NIP dari tabel kop_surat
$qKop = mysqli_query($conn, "SELECT nama_kepala_sekolah, nip_kepala_sekolah FROM kop_surat WHERE id_sekolah = '$id_sekolah' LIMIT 1");
$dataKop = mysqli_fetch_assoc($qKop);
$nama_ks = ($dataKop) ? $dataKop['nama_kepala_sekolah'] : "..........................";
$nip_ks  = ($dataKop) ? $dataKop['nip_kepala_sekolah'] : "..........................";

// Fungsi Format Tanggal Indonesia
function tgl_indo($tanggal){
    $bulan = array (1 => 'Januari','Februari','Maret','April','Mei','Juni','Juli','Agustus','September','Oktober','November','Desember');
    $pecahkan = explode('-', $tanggal);
    return $pecahkan[2] . ' ' . $bulan[ (int)$pecahkan[1] ] . ' ' . $pecahkan[0];
}

// Load library TCPDF
require_once('tcpdf/tcpdf.php');

// ================= CUSTOM CLASS UNTUK AUTO-HEADER =================
class MYPDF extends TCPDF {
    public $nama_sekolah_header;
    public $kota_kab_header;

    public function Header() {
        $this->SetY(10);
        
        // PENGATURAN LEBAR KOLOM SESUAI PROPORSI BUKU SALUR (TOTAL PAS 320mm, MARGIN 5mm KIRI/KANAN)
        $w = [
            'no'=>8, 'kode'=>26, 'reg'=>15, 'nama'=>38, 'merek'=>38, 
            'identitas'=>25, 'bahan'=>15, 'asal'=>18, 'bulan'=>10, 
            'tahun'=>10, 'ukuran'=>14, 'satuan'=>13, 
            'b'=>8, 'rr'=>8, 'rb'=>8, 
            'vol'=>10, 'harga'=>26, 'ket'=>30
        ];

        if ($this->getPage() == 1) {
            $this->SetFont('helvetica', 'B', 13);
            $this->Cell(0, 5, 'BUKU INVENTARIS', 0, 1, 'C');
            $this->Cell(0, 5, 'PERALATAN DAN MESIN', 0, 1, 'C');
            $this->Cell(0, 5, $this->nama_sekolah_header, 0, 1, 'C'); 
            $this->Ln(2);

            $this->SetFont('helvetica', '', 9);
            $this->Cell(150, 5, 'KAB/KOTA : ' . strtoupper($this->kota_kab_header), 0, 0, 'L');
            $this->SetFont('helvetica', 'B', 9);
            $this->Cell(0, 5, 'NO. KODE LOKASI : ................', 0, 1, 'R');
            
            $this->SetFont('helvetica', '', 9);
            $this->Cell(0, 5, 'PROVINSI : JAWA BARAT', 0, 1, 'L');
            $this->Ln(2);
        } else {
            $this->SetY(12);
        }

        // TABEL HEADER
        $this->SetFont('helvetica','B',7.5);
        $this->setCellPaddings(0.5, 1, 0.5, 1);
        
        $start_y = $this->GetY();
        
        // BARIS UTAMA (Atas)
        $this->MultiCell($w['no'], 14, 'NO', 1, 'C', 0, 0, '', '', true, 0, false, true, 14, 'M');
        $this->MultiCell($w['kode'], 14, 'KODE BARANG', 1, 'C', 0, 0, '', '', true, 0, false, true, 14, 'M');
        $this->MultiCell($w['reg'], 14, 'REG', 1, 'C', 0, 0, '', '', true, 0, false, true, 14, 'M');

        $x_spek = $this->GetX();
        $this->MultiCell($w['nama']+$w['merek']+$w['identitas'], 7, 'SPESIFIKASI', 1, 'C', 0, 0, '', '', true, 0, false, true, 7, 'M');

        $this->MultiCell($w['bahan'], 14, 'BAHAN', 1, 'C', 0, 0, '', '', true, 0, false, true, 14, 'M');
        $this->MultiCell($w['asal'], 14, 'ASAL PEROLEHAN', 1, 'C', 0, 0, '', '', true, 0, false, true, 14, 'M');
        $this->MultiCell($w['bulan'], 14, 'BLN', 1, 'C', 0, 0, '', '', true, 0, false, true, 14, 'M');
        $this->MultiCell($w['tahun'], 14, 'THN', 1, 'C', 0, 0, '', '', true, 0, false, true, 14, 'M');
        $this->MultiCell($w['ukuran'], 14, 'UKURAN', 1, 'C', 0, 0, '', '', true, 0, false, true, 14, 'M');
        $this->MultiCell($w['satuan'], 14, 'SATUAN', 1, 'C', 0, 0, '', '', true, 0, false, true, 14, 'M');
        
        $x_keadaan = $this->GetX();
        $this->MultiCell($w['b']+$w['rr']+$w['rb'], 7, 'KEADAAN', 1, 'C', 0, 0, '', '', true, 0, false, true, 7, 'M');

        $x_jumlah = $this->GetX();
        $this->MultiCell($w['vol']+$w['harga'], 7, 'JUMLAH', 1, 'C', 0, 0, '', '', true, 0, false, true, 7, 'M');

        $x_ket = $this->GetX();
        $this->MultiCell($w['ket'], 14, 'KETERANGAN', 1, 'C', 0, 1, $x_ket, $start_y, true, 0, false, true, 14, 'M');

        // SUB HEADER BARIS KEDUA (Bawah)
        $this->SetXY($x_spek, $start_y + 7);
        $this->MultiCell($w['nama'], 7, 'NAMA BARANG', 1, 'C', 0, 0, '', '', true, 0, false, true, 7, 'M');
        $this->MultiCell($w['merek'], 7, 'MERK/TYPE', 1, 'C', 0, 0, '', '', true, 0, false, true, 7, 'M');
        $this->MultiCell($w['identitas'], 7, 'NO SERI', 1, 'C', 0, 0, '', '', true, 0, false, true, 7, 'M');

        $this->SetXY($x_keadaan, $start_y + 7);
        $this->MultiCell($w['b'], 7, 'B', 1, 'C', 0, 0, '', '', true, 0, false, true, 7, 'M');
        $this->MultiCell($w['rr'], 7, 'RR', 1, 'C', 0, 0, '', '', true, 0, false, true, 7, 'M');
        $this->MultiCell($w['rb'], 7, 'RB', 1, 'C', 0, 0, '', '', true, 0, false, true, 7, 'M');

        $this->SetXY($x_jumlah, $start_y + 7);
        $this->MultiCell($w['vol'], 7, 'Vol', 1, 'C', 0, 0, '', '', true, 0, false, true, 7, 'M');
        $this->MultiCell($w['harga'], 7, 'HARGA (Rp)', 1, 'C', 0, 1, '', '', true, 0, false, true, 7, 'M');
    }

    public function Footer() {
        $this->SetY(-15);
        $this->SetFont('helvetica', 'I', 8);
        $this->Cell(0, 10, 'Halaman '.$this->getAliasNumPage().'/'.$this->getAliasNbPages(), 0, false, 'C');
    }
}

// Inisialisasi PDF (Kertas F4 Standar Landscape)
$pdf = new MYPDF('L', 'mm', array(215, 330), true, 'UTF-8', false);
$pdf->nama_sekolah_header = $nama_sekolah_login;
$pdf->kota_kab_header = $nama_kota;

$pdf->SetCreator('SINVENTARIS');
$pdf->SetAuthor('Admin Sekolah');
$pdf->SetTitle('Buku Inventaris');

$pdf->setPrintHeader(true);
$pdf->setPrintFooter(true); 

// MARGIN DISESUAIKAN DENGAN BUKU SALUR (5mm KIRI & KANAN)
$pdf->SetMargins(5, 55, 5); 
$pdf->SetAutoPageBreak(TRUE, 15); 

$pdf->AddPage();

$w = [
    'no'=>8, 'kode'=>26, 'reg'=>15, 'nama'=>38, 'merek'=>38, 
    'identitas'=>25, 'bahan'=>15, 'asal'=>18, 'bulan'=>10, 
    'tahun'=>10, 'ukuran'=>14, 'satuan'=>13, 
    'b'=>8, 'rr'=>8, 'rb'=>8, 
    'vol'=>10, 'harga'=>26, 'ket'=>30
];

$pdf->SetFont('helvetica', '', 7.5);

// Filter tambahan untuk Tahun Perolehan jika dipilih
$filter_tahun = "";
if (!empty($tahun_perolehan)) {
    $filter_tahun = " AND b.tahun_perolehan = '$tahun_perolehan' ";
}

// Filter tambahan untuk Semester jika dipilih (1 = Jan-Jun, 2 = Jul-Des)
$filter_semester = "";
if ($semester === '1') {
    $filter_semester = " AND CAST(b.bulan_perolehan AS UNSIGNED) BETWEEN 1 AND 6 ";
} elseif ($semester === '2') {
    $filter_semester = " AND CAST(b.bulan_perolehan AS UNSIGNED) BETWEEN 7 AND 12 ";
}

// Query utama
$sql = "SELECT b.*, 
               IFNULL(kd.total_baik, 0) as total_baik,
               IFNULL(kd.total_rr, 0) as total_rr,
               IFNULL(kd.total_rb, 0) as total_rb
        FROM data_barang b
        LEFT JOIN (
            SELECT id_barang,
                   SUM(CASE WHEN kondisi = 'B' THEN 1 ELSE 0 END) as total_baik,
                   SUM(CASE WHEN kondisi = 'RR' THEN 1 ELSE 0 END) as total_rr,
                   SUM(CASE WHEN kondisi = 'RB' THEN 1 ELSE 0 END) as total_rb
            FROM kondisi_barang_detail
            GROUP BY id_barang
        ) kd ON b.id = kd.id_barang
        WHERE b.id_sekolah = '$id_sekolah' 
        $filter_tahun
        $filter_semester
        AND STR_TO_DATE(
            CONCAT(
                IF(CAST(b.tahun_perolehan AS UNSIGNED) > 0, b.tahun_perolehan, '1970'), '-', 
                LPAD(IF(CAST(b.bulan_perolehan AS UNSIGNED) BETWEEN 1 AND 12, b.bulan_perolehan, '01'), 2, '0'), '-', 
                LPAD(IF(CAST(b.tanggal_perolehan AS UNSIGNED) BETWEEN 1 AND 31, b.tanggal_perolehan, '01'), 2, '0')
            ), 
            '%Y-%m-%d'
        ) <= '$tgl_cetak'
        ORDER BY 
            CAST(b.tahun_perolehan AS UNSIGNED) ASC, 
            CAST(b.bulan_perolehan AS UNSIGNED) ASC,
            CAST(b.tanggal_perolehan AS UNSIGNED) ASC";

$q = mysqli_query($conn, $sql);
$total_rows = mysqli_num_rows($q);
$no = 1;

while($d = mysqli_fetch_assoc($q)){
    $text_nama  = $d['nama_jenis_barang'];
    // MERK DIBUAT KAPITAL AWAL KATA & SISANYA KECIL
    $text_merek = ucwords(strtolower($d['merek_type'])); 
    $text_ket   = isset($d['keterangan']) ? $d['keterangan'] : ''; 
    
    $h_nama  = $pdf->getStringHeight($w['nama'], $text_nama);
    $h_merek = $pdf->getStringHeight($w['merek'], $text_merek);
    $h_ket   = $pdf->getStringHeight($w['ket'], $text_ket);
    
    // TINGGI BARIS MINIMAL 10mm
    $row_height = max(10, $h_nama, $h_merek, $h_ket);

    $sisa_data = $total_rows - $no + 1;
    if ($sisa_data <= 1) {
        $batas_bawah = $pdf->getPageHeight() - 75; 
    } else {
        $batas_bawah = $pdf->getPageHeight() - 25; 
    }

    if (($pdf->GetY() + $row_height) > $batas_bawah) {
        $pdf->SetMargins(5, 26, 5); 
        $pdf->AddPage();
    }

    $bulan_tampil = is_numeric($d['bulan_perolehan']) ? (int)$d['bulan_perolehan'] : $d['bulan_perolehan'];

    $baik = (int)$d['total_baik'];
    $rr   = (int)$d['total_rr'];
    $rb   = (int)$d['total_rb'];
    
    if (($baik + $rr + $rb) == 0) {
        $baik = (int)$d['volume'];
    }

    $txt_baik = ($baik > 0) ? $baik : '';
    $txt_rr   = ($rr > 0) ? $rr : '';
    $txt_rb   = ($rb > 0) ? $rb : '';

    $pdf->MultiCell($w['no'], $row_height, $no, 1, 'C', 0, 0, '', '', true, 0, false, true, $row_height, 'M');
    $pdf->MultiCell($w['kode'], $row_height, $d['kode_barang'], 1, 'C', 0, 0, '', '', true, 0, false, true, $row_height, 'M');
    $pdf->MultiCell($w['reg'], $row_height, $d['reg'], 1, 'C', 0, 0, '', '', true, 0, false, true, $row_height, 'M');
    
    $pdf->MultiCell($w['nama'], $row_height, $text_nama, 1, 'L', 0, 0, '', '', true, 0, false, true, $row_height, 'M');
    $pdf->MultiCell($w['merek'], $row_height, $text_merek, 1, 'L', 0, 0, '', '', true, 0, false, true, $row_height, 'M');
    
    $pdf->MultiCell($w['identitas'], $row_height, $d['no_identitas'], 1, 'C', 0, 0, '', '', true, 0, false, true, $row_height, 'M');
    $pdf->MultiCell($w['bahan'], $row_height, $d['bahan'], 1, 'C', 0, 0, '', '', true, 0, false, true, $row_height, 'M');
    $pdf->MultiCell($w['asal'], $row_height, $d['asal_perolehan'], 1, 'C', 0, 0, '', '', true, 0, false, true, $row_height, 'M');
    $pdf->MultiCell($w['bulan'], $row_height, $bulan_tampil, 1, 'C', 0, 0, '', '', true, 0, false, true, $row_height, 'M');
    $pdf->MultiCell($w['tahun'], $row_height, $d['tahun_perolehan'], 1, 'C', 0, 0, '', '', true, 0, false, true, $row_height, 'M');
    $pdf->MultiCell($w['ukuran'], $row_height, $d['ukuran_konstruksi'], 1, 'C', 0, 0, '', '', true, 0, false, true, $row_height, 'M');
    $pdf->MultiCell($w['satuan'], $row_height, $d['satuan'], 1, 'C', 0, 0, '', '', true, 0, false, true, $row_height, 'M');
    
    $pdf->MultiCell($w['b'], $row_height, $txt_baik, 1, 'C', 0, 0, '', '', true, 0, false, true, $row_height, 'M');
    $pdf->MultiCell($w['rr'], $row_height, $txt_rr, 1, 'C', 0, 0, '', '', true, 0, false, true, $row_height, 'M');
    $pdf->MultiCell($w['rb'], $row_height, $txt_rb, 1, 'C', 0, 0, '', '', true, 0, false, true, $row_height, 'M');
    
    $pdf->MultiCell($w['vol'], $row_height, $d['volume'], 1, 'C', 0, 0, '', '', true, 0, false, true, $row_height, 'M');
    
    $harga = is_numeric($d['harga_satuan']) ? number_format($d['harga_satuan'], 0, ',', '.') : $d['harga_satuan'];
    $pdf->MultiCell($w['harga'], $row_height, $harga, 1, 'L', 0, 0, '', '', true, 0, false, true, $row_height, 'M');

    $pdf->MultiCell($w['ket'], $row_height, $text_ket, 1, 'L', 0, 1, '', '', true, 0, false, true, $row_height, 'M');

    $no++;
}

// ================= TANDA TANGAN =================
$pdf->Ln(10);
$pdf->SetFont('helvetica', '', 9);
$lebar_kolom_ttd = 100;
$posisi_x_kanan = 220; 

$nama_kota_ttd = ucwords(strtolower($nama_kota));

$pdf->SetX($posisi_x_kanan);
$pdf->Cell($lebar_kolom_ttd, 5, $nama_kota_ttd . ', ' . tgl_indo($tgl_cetak), 0, 1, 'C');
$pdf->SetX($posisi_x_kanan);
$pdf->Cell($lebar_kolom_ttd, 5, 'Mengetahui,', 0, 1, 'C');
$pdf->SetX($posisi_x_kanan);
$pdf->Cell($lebar_kolom_ttd, 5, 'Kepala Sekolah', 0, 1, 'C');
$pdf->Ln(18); 

$pdf->SetFont('helvetica', 'B', 9);
$pdf->SetX($posisi_x_kanan);
$pdf->Cell($lebar_kolom_ttd, 5, $nama_ks, 0, 1, 'C');
$pdf->SetFont('helvetica', '', 9);
$pdf->SetX($posisi_x_kanan);
$pdf->Cell($lebar_kolom_ttd, 5, 'NIP. ' . $nip_ks, 0, 1, 'C');

ob_end_clean();
$pdf->Output('buku_inventaris.pdf', 'I');
exit;
?>