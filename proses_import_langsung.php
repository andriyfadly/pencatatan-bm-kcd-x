<?php
session_start();

// MENCEGAH TIMEOUT & LIMIT MEMORY UNTUK DATA RIBUAN (5000+ Baris)
set_time_limit(0);
ini_set('memory_limit', '-1');

require 'vendor/autoload.php'; // Pastikan path autoload Composer sudah benar

use PhpOffice\PhpSpreadsheet\IOFactory;

// 1. Cek Login & Session
if(!isset($_SESSION['id_user']) || !isset($_SESSION['id_sekolah'])){
    header("Location: login.php");
    exit;
}

// 2. Koneksi Database
$conn = new mysqli("localhost", "root", "", "db_inventaris");
if($conn->connect_error){
    die("Koneksi DB Gagal: " . $conn->connect_error);
}

$id_sekolah = $_SESSION['id_sekolah'];

// ================= FUNGSI RE-INDEX NO REGISTER =================
function urutkanUlangRegister($conn, $id_sekolah, $kode_barang) {
    $sql = "SELECT id, volume, tahun_perolehan FROM data_barang 
            WHERE id_sekolah = '$id_sekolah' AND kode_barang = '$kode_barang'
            ORDER BY CAST(tahun_perolehan AS UNSIGNED) ASC, 
                     CAST(bulan_perolehan AS UNSIGNED) ASC, 
                     CAST(tanggal_perolehan AS UNSIGNED) ASC, 
                     id ASC";
    $res = mysqli_query($conn, $sql);
    
    $current_reg = 1;
    $last_year = null;
    while($row = mysqli_fetch_assoc($res)) {
        if ($last_year !== null && $last_year != $row['tahun_perolehan']) {
            $current_reg = 1;
        }
        $last_year = $row['tahun_perolehan'];

        $id_brg = $row['id'];
        $vol = intval($row['volume']) ?: 1;
        
        $startStr = str_pad($current_reg, 3, '0', STR_PAD_LEFT);
        if ($vol > 1) {
            $endNum = $current_reg + ($vol - 1);
            $endStr = str_pad($endNum, 3, '0', STR_PAD_LEFT);
            $reg_val = $startStr . '-' . $endStr;
            $current_reg = $endNum + 1;
        } else {
            $reg_val = $startStr;
            $current_reg++;
        }
        mysqli_query($conn, "UPDATE data_barang SET reg = '$reg_val' WHERE id = '$id_brg'");
    }
}

// ================= PROSES EKSEKUSI IMPORT =================
if(isset($_POST['import'])){
    
    if(isset($_FILES['file_excel']['name']) && $_FILES['file_excel']['name'] != ''){
        $file_mimes = array('application/vnd.ms-excel','application/excel','application/vnd.msexcel','application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        
        if(in_array($_FILES['file_excel']['type'], $file_mimes)){
            $tmp_file = $_FILES['file_excel']['tmp_name'];
            $spreadsheet = IOFactory::load($tmp_file);
            $sheetData = $spreadsheet->getActiveSheet()->toArray(null, true, true, true);
            
            $inserted_count = 0;
            $kode_barang_affected = [];
            $newly_inserted_ids = []; // Array untuk menampung ID barang baru

            // TAHAP 1: Looping data Excel murni untuk INSERT ke data_barang (Lebih Cepat)
            foreach($sheetData as $key => $row) {
                if($key == 1) continue; // Skip header
                
                $kode_barang      = mysqli_real_escape_string($conn, $row['A'] ?? '');
                $sumber_perolehan = mysqli_real_escape_string($conn, $row['B'] ?? '');
                $nama_barang      = mysqli_real_escape_string($conn, $row['C'] ?? '');
                $merek_type       = mysqli_real_escape_string($conn, $row['D'] ?? '');
                $bahan            = mysqli_real_escape_string($conn, $row['E'] ?? '');
                $tanggal          = intval($row['F']) ?: 0;
                $bulan            = intval($row['G']) ?: 0;
                $tahun            = intval($row['H']) ?: 0;
                $satuan           = mysqli_real_escape_string($conn, $row['I'] ?? '');
                $volume           = intval($row['J']) ?: 1;
                $harga_raw        = $row['K'] ?? '0';
                
                $harga_final      = (int)preg_replace('/[^0-9]/', '', $harga_raw);

                // Default keadaan dikosongkan/strip mengikuti sinkronisasi
                $keadaan_barang   = '-'; 
                $keterangan       = '';
                $no_identitas     = '';

                if(!empty($kode_barang) && !empty($nama_barang)){
                    
                    $sql_insert = "INSERT INTO data_barang (
                                        id_sekolah, nama_jenis_barang, kode_barang, merek_type, bahan, volume, satuan, 
                                        keadaan_barang, harga_satuan, keterangan, foto, reg, no_identitas, 
                                        asal_perolehan, tanggal_perolehan, bulan_perolehan, tahun_perolehan
                                    ) VALUES (
                                        '$id_sekolah', '$nama_barang', '$kode_barang', '$merek_type', '$bahan', '$volume', '$satuan', 
                                        '$keadaan_barang', '$harga_final', '$keterangan', '', '', '$no_identitas', 
                                        '$sumber_perolehan', '$tanggal', '$bulan', '$tahun'
                                    )";
                    
                    if(mysqli_query($conn, $sql_insert)){
                        $id_barang_baru = mysqli_insert_id($conn);
                        $newly_inserted_ids[] = $id_barang_baru;
                        
                        // Kumpulkan kode barang yang unik untuk di-reindex nanti
                        if(!in_array($kode_barang, $kode_barang_affected)) {
                            $kode_barang_affected[] = $kode_barang;
                        }
                        
                        $inserted_count++;
                    }
                }
            } 

            // TAHAP 2: Re-Index Semua Kode Barang yang Terpengaruh HANYA 1x Per Kode
            foreach($kode_barang_affected as $kode_unik) {
                urutkanUlangRegister($conn, $id_sekolah, $kode_unik);
            }

            // TAHAP 3: Generate Detail Kondisi untuk Semua Barang Baru
            if(!empty($newly_inserted_ids)){
                $kondisi_hardcode = '-'; // Kondisi default jadi strip
                $detail_values = [];
                
                // Chunking array untuk menghindari query kepanjangan jika data mencapai 5000
                $chunks = array_chunk($newly_inserted_ids, 500); 
                
                foreach($chunks as $chunk) {
                    $ids_str = implode(',', $chunk);
                    $q_new_regs = mysqli_query($conn, "SELECT id, reg FROM data_barang WHERE id IN ($ids_str)");
                    
                    while($d_reg = mysqli_fetch_assoc($q_new_regs)){
                        $id_brg = $d_reg['id'];
                        $reg_input = $d_reg['reg'];
                        
                        if (!empty($reg_input) && $reg_input != '-') {
                            if (strpos($reg_input, '-') !== false) {
                                $pecah = explode('-', $reg_input);
                                $awal = (int) $pecah[0];
                                $akhir = (int) $pecah[1];
                                for ($i = $awal; $i <= $akhir; $i++) {
                                    $no_unit_formatted = str_pad($i, 3, '0', STR_PAD_LEFT);
                                    $detail_values[] = "('$id_brg', '$no_unit_formatted', '$kondisi_hardcode')";
                                }
                            } else {
                                $no_unit_formatted = str_pad((int)$reg_input, 3, '0', STR_PAD_LEFT);
                                $detail_values[] = "('$id_brg', '$no_unit_formatted', '$kondisi_hardcode')";
                            }
                        }
                        
                        // Optimasi Bulk Insert: Eksekusi per 1000 unit baris detail agar ramah memori
                        if(count($detail_values) >= 1000){
                            $query_detail = "INSERT INTO kondisi_barang_detail (id_barang, no_reg, kondisi) VALUES " . implode(',', $detail_values);
                            mysqli_query($conn, $query_detail);
                            $detail_values = []; // Kosongkan array untuk batch selanjutnya
                        }
                    }
                }
                
                // Eksekusi sisa baris detail jika masih ada di array
                if(count($detail_values) > 0){
                    $query_detail = "INSERT INTO kondisi_barang_detail (id_barang, no_reg, kondisi) VALUES " . implode(',', $detail_values);
                    mysqli_query($conn, $query_detail);
                }
            }

            // DI SINI LETAK PERUBAHANNYA: KEMBALI KE INDEX.PHP?P=...
            echo "<script>
                    alert('Import berhasil! Total $inserted_count barang ditambahkan ke database.');
                    window.location.href='index.php?p=import_barang.php';
                  </script>";
            exit;

        } else {
            echo "<script>alert('Format file tidak valid. Pastikan Anda mengupload file .xlsx!'); window.location.href='index.php?p=import_barang.php';</script>";
            exit;
        }
    } else {
         echo "<script>alert('File belum dipilih!'); window.location.href='index.php?p=import_barang.php';</script>";
         exit;
    }
} else {
    header("Location: index.php?p=import_barang.php");
    exit;
}
?>