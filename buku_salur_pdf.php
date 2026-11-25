<?php
ob_start();
error_reporting(0); 
session_start(); 

// ==============================================================================
// 1. PENGAMANAN PERTAMA: CEK SESI LOGIN (Cegah penyusup tanpa login)
// ==============================================================================
if (!isset($_SESSION['id_user'])) {
    header("Location: login.php");
    exit;
}

require_once('tcpdf/tcpdf.php');
include "koneksi.php";

// Fungsi untuk merapikan nama dan gelar (Nama Utama KAPITAL SEMUA, Gelar Rapi)
function formatNamaGelar($nama) {
    if (empty($nama)) return '';
    if (strpos($nama, ',') === false) {
        return strtoupper(trim($nama));
    }
    $parts = explode(',', $nama);
    $nama_utama = strtoupper(trim(array_shift($parts)));
    
    $formatted_gelar = array_map(function($g) {
        $g = trim($g);
        if (empty($g)) return '';
        return ucwords(strtolower($g), " .");
    }, $parts);
    
    return $nama_utama . ', ' . implode(', ', $formatted_gelar);
}

// Helper konversi nama/angka bulan menjadi angka 1..12
function getBulanAngka($bln) {
    $b = strtolower(trim((string)$bln));
    if (is_numeric($b)) {
        $n = (int)$b;
        return ($n >= 1 && $n <= 12) ? $n : 0;
    }
    $map = [
        'januari' => 1, 'jan' => 1,
        'februari' => 2, 'feb' => 2,
        'maret' => 3, 'mar' => 3,
        'april' => 4, 'apr' => 4,
        'mei' => 5, 'may' => 5,
        'juni' => 6, 'jun' => 6,
        'juli' => 7, 'jul' => 7,
        'agustus' => 8, 'agt' => 8, 'aug' => 8,
        'september' => 9, 'sep' => 9,
        'oktober' => 10, 'okt' => 10, 'oct' => 10,
        'november' => 11, 'nov' => 11,
        'desember' => 12, 'des' => 12, 'dec' => 12
    ];
    return $map[$b] ?? 0;
}

// ==============================================================================
// 2. PENGAMANAN KEDUA & KETIGA: ANTI-IDOR (Hak Akses) & ANTI-SQL INJECTION
// ==============================================================================
$id_sekolah_url = isset($_GET['id_sekolah']) ? (int)$_GET['id_sekolah'] : 0;

if (isset($_SESSION['role']) && $_SESSION['role'] !== 'admin') {
    $id_sekolah = (int)$_SESSION['id_sekolah'];
    
    if ($id_sekolah_url !== 0 && $id_sekolah_url !== $id_sekolah) {
        die("<div style='text-align:center; margin-top:50px; font-family:sans-serif;'>
                <h1 style='color:red;'>AKSES DITOLAK!</h1>
                <p>Anda tidak memiliki izin mencetak PDF untuk instansi/sekolah lain.</p>
             </div>");
    }
} else {
    $id_sekolah = $id_sekolah_url;
}

if (empty($id_sekolah) || $id_sekolah <= 0) {
    die("Error: Akses ditolak. ID Sekolah tidak valid atau tidak ditemukan.");
}

// Tangkap Parameter Filter dari Modal
$filter            = isset($_GET['filter']) ? $_GET['filter'] : 'semua';
$value             = isset($_GET['value']) ? $_GET['value'] : '';
$value_safe        = mysqli_real_escape_string($conn, $value);

$is_rentang        = (isset($_GET['rentang']) && $_GET['rentang'] === '1');
$tahun_dari        = isset($_GET['tahun_dari']) ? trim($_GET['tahun_dari']) : (isset($_GET['tahun']) ? trim($_GET['tahun']) : '');
$semester_dari     = isset($_GET['semester_dari']) ? trim($_GET['semester_dari']) : (isset($_GET['semester']) ? trim($_GET['semester']) : '');
$tahun_sampai      = isset($_GET['tahun_sampai']) ? trim($_GET['tahun_sampai']) : $tahun_dari;
$semester_sampai   = isset($_GET['semester_sampai']) ? trim($_GET['semester_sampai']) : $semester_dari;

$asal              = isset($_GET['asal']) ? trim($_GET['asal']) : '';
$berdasarkan_ruang = isset($_GET['berdasarkan_ruang']) ? trim($_GET['berdasarkan_ruang']) : ($filter === 'ruang' ? 'ya' : 'tidak');
$ruang_filter      = isset($_GET['ruang']) ? trim($_GET['ruang']) : '';

$id_sekolah = mysqli_real_escape_string($conn, $id_sekolah);

// Susun daftar sumber perolehan jika dicentang
$asal_filter_list = [];
if ($asal !== '') {
    foreach (explode(',', $asal) as $item_asal) {
        $clean_asal = trim($item_asal);
        if ($clean_asal !== '') {
            $asal_filter_list[] = $clean_asal;
        }
    }
} elseif ($filter === 'asal_perolehan' && $value !== '') {
    $asal_filter_list[] = trim($value);
}

// ==============================================================================
// LOGIKA FILTER TAHUN & SEMESTER (KETAT PER TAHUN)
// - Jika Rentang TIDAK dicentang: cek tahun & semester yang dipilih.
// - Jika Rentang DICENTANG (misal 2024 Sem 1 s/d 2025 Sem 2):
//   * Untuk tahun awal (2024): ikuti semester awal (jika Sem 1 -> hanya bulan 1-6; jika Sem 2 -> hanya bulan 7-12; jika Semua -> bulan 1-12)
//   * Untuk tahun akhir (2025): ikuti semester akhir (jika Sem 1 -> hanya bulan 1-6; jika Sem 2 -> hanya bulan 7-12; jika Semua -> bulan 1-12)
//   * Untuk tahun di tengah (jika rentang > 2 tahun): bulan 1-12
// ==============================================================================
$has_tahun_filter = ($tahun_dari !== '' && is_numeric($tahun_dari));
$thn_start_int    = $has_tahun_filter ? (int)$tahun_dari : 0;
$thn_end_int      = ($has_tahun_filter && $is_rentang && $tahun_sampai !== '' && is_numeric($tahun_sampai))
                    ? (int)$tahun_sampai
                    : $thn_start_int;

if ($has_tahun_filter && $thn_end_int < $thn_start_int) {
    $tmp = $thn_start_int;
    $thn_start_int = $thn_end_int;
    $thn_end_int = $tmp;
}
// ==============================================================================


// Class Custom untuk handle Header otomatis
class MYPDF extends TCPDF {
    public function Header() {
        if ($this->getPage() > 1) {
            $this->SetY(10); 
        } else {
            $this->SetY(30); 
        }

        $this->SetFont('helvetica', 'B', 8);
        $w = [8, 35, 25, 28, 8, 8, 10, 10, 15, 27, 27, 10, 15, 45, 18, 31];
        $h1 = 7; 
        $h2 = 7; 
        $full = $h1 + $h2;
        
        $startX = $this->GetX();
        $startY = $this->GetY();

        $this->MultiCell($w[0], $full, 'NO', 1, 'C', false, 0, '', '', true, 0, false, true, $full, 'M');
        $this->MultiCell($w[1], $full, 'NAMA BARANG', 1, 'C', false, 0, '', '', true, 0, false, true, $full, 'M');
        $this->MultiCell($w[2], $full, 'MERK/TIPE', 1, 'C', false, 0, '', '', true, 0, false, true, $full, 'M');
        $this->MultiCell($w[3], $full, 'KODE BARANG', 1, 'C', false, 0, '', '', true, 0, false, true, $full, 'M');

        $peng_w = $w[4]+$w[5]+$w[6]+$w[7]+$w[8]+$w[9];
        $this->MultiCell($peng_w, $h1, 'PENGADAAN', 1, 'C', false, 0, '', '', true, 0, false, true, $h1, 'M');

        $peny_w = $w[10]+$w[11]+$w[12]+$w[13];
        $this->MultiCell($peny_w, $h1, 'PENYALURAN', 1, 'C', false, 0, '', '', true, 0, false, true, $h1, 'M');

        $this->MultiCell($w[14], $full, 'NO. REG', 1, 'C', false, 0, '', '', true, 0, false, true, $full, 'M');
        $this->MultiCell($w[15], $full, 'KETERANGAN', 1, 'C', false, 1, '', '', true, 0, false, true, $full, 'M');

        $this->SetXY($startX + $w[0] + $w[1] + $w[2] + $w[3], $startY + $h1);
        $sub = ['TGL', 'BLN', 'THN', 'Vol', 'SATUAN', 'HARGA SATUAN', 'LOKASI', 'Vol', 'SATUAN', 'NAMA PENERIMA'];
        $subW = [$w[4], $w[5], $w[6], $w[7], $w[8], $w[9], $w[10], $w[11], $w[12], $w[13]];
        
        foreach($sub as $i => $txt){
            $this->MultiCell($subW[$i], $h2, $txt, 1, 'C', false, 0, '', '', true, 0, false, true, $h2, 'M');
        }
        $this->Ln();
    }
}

// Set Ukuran Kertas ke F4 Landscape (215mm x 330mm)
$pdf = new MYPDF('L', 'mm', array(215, 330), true, 'UTF-8', false);
$pdf->setPrintHeader(true); 
$pdf->setPrintFooter(false);
$pdf->SetMargins(5, 10, 5);
$pdf->SetAutoPageBreak(TRUE, 15);
$pdf->AddPage();

$kop_q = mysqli_query($conn, "SELECT * FROM kop_surat WHERE id_sekolah = '$id_sekolah' LIMIT 1");
$kop = mysqli_fetch_assoc($kop_q);
$nama_sekolah = ($kop && !empty($kop['nama_sekolah'])) ? $kop['nama_sekolah'] : "SEKOLAH ID: " . $id_sekolah;
$nama_wakasek = ($kop && !empty($kop['nama_wakasek_sarpras'])) ? formatNamaGelar($kop['nama_wakasek_sarpras']) : "-";
$nip_wakasek  = ($kop && !empty($kop['nip_wakasek_sarpras'])) ? trim($kop['nip_wakasek_sarpras']) : "-";

$pdf->SetFont('helvetica', 'B', 12);
$pdf->SetY(8);
$pdf->Cell(0, 6, 'REKAP PENYALURAN BELANJA MODAL', 0, 1, 'C');
$pdf->Cell(0, 6, 'PERALATAN DAN MESIN', 0, 1, 'C');
$pdf->Cell(0, 6, strtoupper($nama_sekolah), 0, 1, 'C');

$pdf->SetY(44); 

// Ambil seluruh data_barang sekolah berurutan secara kronologis agar perhitungan offset NO. REG tetap akurat
$query = "SELECT * FROM data_barang 
          WHERE id_sekolah = '$id_sekolah'
          ORDER BY 
              CAST(tahun_perolehan AS UNSIGNED) ASC, 
              CAST(bulan_perolehan AS UNSIGNED) ASC,
              CAST(tanggal_perolehan AS UNSIGNED) ASC";
              
$barang_q = mysqli_query($conn, $query);

$pdf->SetFont('helvetica', '', 8);
$w = [8, 35, 25, 28, 8, 8, 10, 10, 15, 27, 27, 10, 15, 45, 18, 31];
$aligns = ['C', 'L', 'L', 'C', 'C', 'C', 'C', 'C', 'C', 'L', 'L', 'C', 'C', 'L', 'C', 'C'];

// Array tracker offset urutan nomor registrasi
$last_reg_offset = [];

// ARRAY TAMPUNGAN GLOBAL SELURUH DATA BARIS YANG LOLOS FILTER
$semua_baris_cetak = [];

while($d = mysqli_fetch_assoc($barang_q)){
    $volume = intval($d['volume']);
    $nama_barang = mysqli_real_escape_string($conn, $d['nama_jenis_barang']);
    $merek_induk = mysqli_real_escape_string($conn, $d['merek_type']);
    
    // Format Tampilan Merk/Tipe: Awal kata kapital, sisanya biasa
    $merek_tampil = ucwords(strtolower(trim($d['merek_type'])));
    
    $satuan = $d['satuan'];
    $kode_barang = $d['kode_barang'];

    $tgl_p = $d['tanggal_perolehan'];
    $bln_p = $d['bulan_perolehan'];
    $thn_p = $d['tahun_perolehan'];
    $asal_p = trim((string)($d['asal_perolehan'] ?? ''));

    if (!isset($last_reg_offset[$kode_barang])) {
        $last_reg_offset[$kode_barang] = 0;
    }

    // ==========================================================================
    // EVALUASI FILTER PERIODE (TAHUN + SEMESTER KETAT) & SUMBER PEROLEHAN
    // ==========================================================================
    $lolos_filter_barang = true;
    $thn_item_int = (int)trim((string)$thn_p);
    $bln_item_int = getBulanAngka($bln_p);
    $sem_item_int = ($bln_item_int >= 1 && $bln_item_int <= 6) ? 1 : (($bln_item_int >= 7 && $bln_item_int <= 12) ? 2 : 0);

    if ($has_tahun_filter) {
        if ($thn_item_int < $thn_start_int || $thn_item_int > $thn_end_int) {
            $lolos_filter_barang = false;
        } else {
            if (!$is_rentang) {
                // Mode Single Tahun (misal: 2024 Semester 1 -> hanya bulan 1..6; bulan 7..12 tidak ditampilkan)
                if ($semester_dari === '1' && $sem_item_int !== 1) {
                    $lolos_filter_barang = false;
                } elseif ($semester_dari === '2' && $sem_item_int !== 2) {
                    $lolos_filter_barang = false;
                }
            } else {
                // Mode Rentang Tahun & Semester (misal: 2024 Sem 1 s/d 2025 Sem 1)
                if ($thn_item_int === $thn_start_int && $thn_item_int === $thn_end_int) {
                    // Rentang di tahun yang sama
                    if ($semester_dari === '1' && $semester_sampai === '1' && $sem_item_int !== 1) {
                        $lolos_filter_barang = false;
                    } elseif ($semester_dari === '2' && $semester_sampai === '2' && $sem_item_int !== 2) {
                        $lolos_filter_barang = false;
                    } elseif ($semester_dari === '1' && $semester_sampai === '' && $sem_item_int !== 1) {
                        $lolos_filter_barang = false;
                    } elseif ($semester_dari === '2' && $semester_sampai === '' && $sem_item_int !== 2) {
                        $lolos_filter_barang = false;
                    }
                } else {
                    // Cek batas semester khusus di tahun awal (2024)
                    if ($thn_item_int === $thn_start_int) {
                        if ($semester_dari === '1' && $sem_item_int !== 1) {
                            $lolos_filter_barang = false;
                        } elseif ($semester_dari === '2' && $sem_item_int !== 2) {
                            $lolos_filter_barang = false;
                        }
                    }
                    // Cek batas semester khusus di tahun akhir (2025)
                    if ($thn_item_int === $thn_end_int) {
                        if ($semester_sampai === '1' && $sem_item_int !== 1) {
                            $lolos_filter_barang = false;
                        } elseif ($semester_sampai === '2' && $sem_item_int !== 2) {
                            $lolos_filter_barang = false;
                        }
                    }
                }
            }
        }
    } elseif ($semester_dari === '1' || $semester_dari === '2') {
        // Jika "Semua Tahun" tapi memilih Semester 1 atau Semester 2 saja
        if ((int)$semester_dari !== $sem_item_int) {
            $lolos_filter_barang = false;
        }
    }

    if ($lolos_filter_barang && !empty($asal_filter_list)) {
        if (!in_array($asal_p, $asal_filter_list, true)) {
            $lolos_filter_barang = false;
        }
    }

    $q_penyaluran = "SELECT * FROM penyaluran 
                     WHERE id_sekolah = '$id_sekolah' 
                     AND nama_barang = '$nama_barang' 
                     AND merk_tipe = '$merek_induk' 
                     AND tgl_pengadaan = '$tgl_p'
                     AND bln_pengadaan = '$bln_p'
                     AND thn_pengadaan = '$thn_p'
                     ORDER BY no_reg ASC";
    
    $penyaluran_q = mysqli_query($conn, $q_penyaluran);
    
    $total_vol_disalurkan_tgl_ini = 0;
    $distributed_nums = []; 

    // 1. Kumpulkan data yang SUDAH disalurkan
    if($penyaluran_q && mysqli_num_rows($penyaluran_q) > 0){
        while($p = mysqli_fetch_assoc($penyaluran_q)){
            
            $vol_penerimaan = intval($p['vol_penyaluran']);
            $total_vol_disalurkan_tgl_ini += $vol_penerimaan;
            $display_reg = $p['no_reg'];
            
            $start_num = 0;

            if (!empty($display_reg)) {
                if (strpos($display_reg, '-') !== false) {
                    $parts = explode('-', $display_reg);
                    $start_num = intval($parts[0]);
                    $end_num = intval($parts[1]);
                    for ($i = $start_num; $i <= $end_num; $i++) {
                        $distributed_nums[] = $i;
                    }
                } else {
                    $start_num = intval($display_reg);
                    $distributed_nums[] = $start_num;
                }
            }

            if ($lolos_filter_barang) {
                $lokasi_item = trim((string)($p['lokasi'] ?? ''));

                // Filter spesifik ruang jika user memilih "Berdasarkan Ruang" + memilih 1 ruang tertentu
                if ($berdasarkan_ruang === 'ya' && $ruang_filter !== '' && strcasecmp($lokasi_item, $ruang_filter) !== 0) {
                    continue;
                }

                $sat_p = !empty($p['sat_penyaluran']) ? $p['sat_penyaluran'] : (!empty($p['sat_pengadaan']) ? $p['sat_pengadaan'] : $satuan);
                $nama_penerima_bersih = formatNamaGelar($p['nama_penerima']);
                $bln_pengadaan_tampil = is_numeric($p['bln_pengadaan']) ? (int)$p['bln_pengadaan'] : $p['bln_pengadaan'];

                $row = [
                    '', // Index 0 Dikosongkan sementara untuk No Urut
                    $d['nama_jenis_barang'], $merek_tampil, $d['kode_barang'],
                    $p['tgl_pengadaan'], $bln_pengadaan_tampil, $p['thn_pengadaan'],
                    $volume, $satuan, 'Rp. ' . number_format($p['harga_satuan'], 0, ",", "."),
                    $p['lokasi'], $p['vol_penyaluran'], $sat_p,
                    $nama_penerima_bersih, $display_reg, $p['keterangan']
                ];

                // Masukkan ke tampungan global
                $semua_baris_cetak[] = [
                    'lokasi' => strtoupper($lokasi_item),
                    'sort_key' => $start_num,
                    'data_row' => $row
                ];
            }
        }
    }

    // 2. Kumpulkan data sisa yang BELUM disalurkan / bolong
    $sisa_volume = $volume - $total_vol_disalurkan_tgl_ini;
    $missing_nums = [];

    if($sisa_volume > 0){
        $start_range = $last_reg_offset[$kode_barang] + 1;
        $current_check = $start_range;
        
        while (count($missing_nums) < $sisa_volume) {
            if (!in_array($current_check, $distributed_nums)) {
                $missing_nums[] = $current_check;
            }
            $current_check++;
        }

        // Barang yang belum disalurkan tidak memiliki ruang spesifik;
        // tampilkan hanya jika lolos filter periode & tidak sedang memfilter 1 ruang spesifik
        if ($lolos_filter_barang && !($berdasarkan_ruang === 'ya' && $ruang_filter !== '')) {
            $blocks = [];
            if (!empty($missing_nums)) {
                $current_block = [$missing_nums[0]];
                for ($i = 1; $i < count($missing_nums); $i++) {
                    if ($missing_nums[$i] == end($current_block) + 1) {
                        $current_block[] = $missing_nums[$i];
                    } else {
                        $blocks[] = $current_block;
                        $current_block = [$missing_nums[$i]];
                    }
                }
                $blocks[] = $current_block;
            }

            foreach ($blocks as $block) {
                $b_start = $block[0];
                $b_end = end($block);
                $b_vol = count($block);
                
                $reg_d = ($b_start == $b_end) 
                         ? str_pad($b_start, 3, '0', STR_PAD_LEFT) 
                         : str_pad($b_start, 3, '0', STR_PAD_LEFT) . '-' . str_pad($b_end, 3, '0', STR_PAD_LEFT);
                
                $bln_perolehan_tampil = is_numeric($d['bulan_perolehan']) ? (int)$d['bulan_perolehan'] : $d['bulan_perolehan'];

                $row = [
                    '', // Index 0 Dikosongkan sementara untuk No Urut
                    $d['nama_jenis_barang'], $merek_tampil, $d['kode_barang'],
                    $d['tanggal_perolehan'], $bln_perolehan_tampil, $d['tahun_perolehan'],
                    $volume, $satuan, 'Rp. ' . number_format($d['harga_satuan'], 0, ",", "."),
                    '-', $b_vol, $satuan, '-', $reg_d, 'Belum Disalurkan / Dikembalikan'
                ];

                // Masukkan ke tampungan global (Di-set ZZZZ agar 'Belum Disalurkan' selalu paling bawah)
                $semua_baris_cetak[] = [
                    'lokasi' => 'ZZZZ',
                    'sort_key' => $b_start,
                    'data_row' => $row
                ];
            }
        }
    }

    // UPDATE TRACKER GLOBAL 
    $highest_this_round = $last_reg_offset[$kode_barang];
    if (!empty($distributed_nums)) {
        $highest_this_round = max($highest_this_round, max($distributed_nums));
    }
    if (!empty($missing_nums)) {
        $highest_this_round = max($highest_this_round, max($missing_nums));
    }
    $last_reg_offset[$kode_barang] = $highest_this_round;
}

// ==============================================================================
// 3. PROSES SORTING GLOBAL BERDASARKAN LOKASI (A-Z) JIKA BERDASARKAN RUANG
// ==============================================================================
if ($berdasarkan_ruang === 'ya') {
    usort($semua_baris_cetak, function($a, $b) {
        $cmpLokasi = strcmp($a['lokasi'], $b['lokasi']);
        if ($cmpLokasi === 0) {
            return $a['sort_key'] <=> $b['sort_key'];
        }
        return $cmpLokasi;
    });
}

// ==============================================================================
// 4. CETAK SELURUH BARIS DATA KE PDF
//    (Jika baris terakhir + TTD mepet batas bawah, baris terakhir ikut turun)
// ==============================================================================
$no = 1;
$total_baris = count($semua_baris_cetak);
$tinggi_ttd  = 36;
$jarak_ttd   = 8;

foreach ($semua_baris_cetak as $idx => $item) {
    $row_to_print = $item['data_row'];
    
    // Isi Nomor Urut Tabel secara berurutan (1, 2, 3, ...)
    $row_to_print[0] = $no;

    $lineH = 5; 
    $maxH = 10; 
    
    foreach($row_to_print as $i => $txt){
        $nb = $pdf->getNumLines($txt, $w[$i]);
        $h = $nb * $lineH;
        if($h > $maxH) $maxH = $h;
    }

    $is_last_row = ($idx === $total_baris - 1);
    $kebutuhan_tinggi = $is_last_row ? ($maxH + $jarak_ttd + $tinggi_ttd) : $maxH;

    // Cek Page Break: jika baris terakhir + TTD tidak muat, baris terakhir turun ke halaman baru bersama TTD
    if($pdf->GetY() + $kebutuhan_tinggi > $pdf->getPageHeight() - 15) {
        $pdf->AddPage();
        $pdf->SetY(24);
    }

    foreach($row_to_print as $i => $txt){
        $pdf->MultiCell($w[$i], $maxH, $txt, 1, $aligns[$i], false, 0, '', '', true, 0, false, true, $maxH, 'M');
    }
    $pdf->Ln();
    $no++;
}

// ==============================================================================
// 5. AREA TANDA TANGAN WAKASEK SARPRAS DI BAWAH BARIS TERAKHIR
// ==============================================================================
if ($total_baris === 0 && $pdf->GetY() + $tinggi_ttd > $pdf->getPageHeight() - 15) {
    $pdf->AddPage();
    $pdf->SetY(26);
} else {
    $pdf->Ln($jarak_ttd);
}

$pdf->SetFont('helvetica', '', 9);

$html_ttd = '
<table border="0" cellpadding="0" cellspacing="0" width="100%" style="font-family:helvetica; font-size:9pt; line-height:1.4;">
    <tr>
        <td width="68%"></td>
        <td width="32%" align="center">
            WAKASEK SARPRAS
            <br><br><br><br>
            <b>'.$nama_wakasek.'</b><br>
            NIP. '.$nip_wakasek.'
        </td>
    </tr>
</table>';

$pdf->writeHTML($html_ttd, true, false, true, false, '');

ob_end_clean();
$pdf->Output('rekap_penyaluran_'.$id_sekolah.'.pdf', 'I');
?>