<?php
// proses_hapus_penyaluran.php
session_start();
include "koneksi.php";

header('Content-Type: application/json');

if (!isset($_SESSION['id_user'])) {
    echo json_encode(['success' => false, 'message' => 'Sesi berakhir']);
    exit;
}

if (!isset($_REQUEST['id'])) {
    echo json_encode(['success' => false, 'message' => 'ID tidak ditemukan']);
    exit;
}

$id = mysqli_real_escape_string($conn, $_REQUEST['id']);
$id_sekolah = $_SESSION['id_sekolah'];

// Tangkap Parameter Kontrol dari Modal
$jenis_kembali = isset($_REQUEST['jenis_kembali']) ? trim($_REQUEST['jenis_kembali']) : 'semua';
$reg_spesifik  = isset($_REQUEST['reg_spesifik']) ? trim($_REQUEST['reg_spesifik']) : '';

// =========================================================================
// TAMBAHAN: Tangkap Keterangan Tambahan yang diisi user dari modal
// =========================================================================
$keterangan_user = isset($_REQUEST['keterangan']) ? mysqli_real_escape_string($conn, trim($_REQUEST['keterangan'])) : '';

// Tangkap Kondisi dengan Fallback
$kondisi_kembali = 'B'; // Default
if (isset($_REQUEST['kondisi_kembali']) && trim($_REQUEST['kondisi_kembali']) !== '') {
    $kondisi_kembali = mysqli_real_escape_string($conn, trim($_REQUEST['kondisi_kembali']));
} elseif (isset($_REQUEST['kondisi']) && trim($_REQUEST['kondisi']) !== '') {
    $kondisi_kembali = mysqli_real_escape_string($conn, trim($_REQUEST['kondisi']));
} elseif (isset($_REQUEST['keadaan_barang']) && trim($_REQUEST['keadaan_barang']) !== '') {
    $kondisi_kembali = mysqli_real_escape_string($conn, trim($_REQUEST['keadaan_barang']));
}

// 1. Ambil data asli dari tabel penyaluran
$q_ambil = mysqli_query($conn, "SELECT * FROM penyaluran WHERE id_penyaluran = '$id' AND id_sekolah = '$id_sekolah'");
$data = mysqli_fetch_assoc($q_ambil);

if ($data) {
    $nama_barang   = mysqli_real_escape_string($conn, $data['nama_barang']);
    $merk_tipe     = mysqli_real_escape_string($conn, $data['merk_tipe']);
    $kode_barang   = mysqli_real_escape_string($conn, $data['kode_barang']);
    
    // Fallback ID Barang
    $id_barang_asli = isset($data['id_barang']) ? $data['id_barang'] : '';
    if (empty($id_barang_asli) || $id_barang_asli == 0) {
        $q_cari = mysqli_query($conn, "SELECT id FROM data_barang WHERE id_sekolah = '$id_sekolah' AND kode_barang = '$kode_barang' LIMIT 1");
        if ($d_cari = mysqli_fetch_assoc($q_cari)) {
            $id_barang_asli = $d_cari['id'];
        }
    }

    $tgl_pengadaan = !empty($data['tgl_pengadaan']) ? intval($data['tgl_pengadaan']) : 0;
    $bln_pengadaan = !empty($data['bln_pengadaan']) ? intval($data['bln_pengadaan']) : 0;
    $thn_pengadaan = !empty($data['thn_pengadaan']) ? intval($data['thn_pengadaan']) : 0;
    $vol_pengadaan = !empty($data['vol_pengadaan']) ? intval($data['vol_pengadaan']) : 0;
    $sat_pengadaan = mysqli_real_escape_string($conn, $data['sat_pengadaan']);
    $harga_satuan  = !empty($data['harga_satuan']) ? $data['harga_satuan'] : 0.00;
    $lokasi        = mysqli_real_escape_string($conn, $data['lokasi']);
    $vol_penyaluran= !empty($data['vol_penyaluran']) ? intval($data['vol_penyaluran']) : 0;
    $nama_penerima = mysqli_real_escape_string($conn, $data['nama_penerima']);
    $no_reg_asal   = trim($data['no_reg']);
    
    // $keterangan ini adalah bawaan lama dari tabel penyaluran, biarkan utuh untuk barang sisa
    $keterangan    = mysqli_real_escape_string($conn, $data['keterangan']);

    mysqli_begin_transaction($conn);

    try {
        $list_unit_kembali = [];
        $pad_length = 3; // Default 3 digit misal: '007'

        // Deteksi panjang digit dari no_reg asal
        if (strpos($no_reg_asal, '-') !== false) {
            $pad_length = strlen(explode('-', $no_reg_asal)[0]);
        } else {
            $pad_length = strlen($no_reg_asal);
        }

        if ($jenis_kembali === 'sebagian' && strpos($no_reg_asal, '-') !== false) {
            $target_unit_int = intval($reg_spesifik);
            $list_unit_kembali[] = $target_unit_int;

            $parts = explode('-', $no_reg_asal);
            $start_range = intval($parts[0]);
            $end_range = intval($parts[1]);

            $blok_sebelum = [];
            $blok_sesudah = [];

            for ($i = $start_range; $i <= $end_range; $i++) {
                if ($i < $target_unit_int) {
                    $blok_sebelum[] = $i;
                } elseif ($i > $target_unit_int) {
                    $blok_sesudah[] = $i;
                }
            }

            $buat_range_teks = function($arr_angka) use ($pad_length) {
                if (empty($arr_angka)) return '';
                sort($arr_angka);
                $min = $arr_angka[0];
                $max = $arr_angka[count($arr_angka) - 1];
                if ($min == $max) {
                    return str_pad($min, $pad_length, '0', STR_PAD_LEFT);
                } else {
                    return str_pad($min, $pad_length, '0', STR_PAD_LEFT) . '-' . str_pad($max, $pad_length, '0', STR_PAD_LEFT);
                }
            };

            $range_sebelum = $buat_range_teks($blok_sebelum);
            $range_sesudah = $buat_range_teks($blok_sesudah);

            if (empty($range_sebelum) && empty($range_sesudah)) {
                $query_hapus = "DELETE FROM penyaluran WHERE id_penyaluran = '$id' AND id_sekolah = '$id_sekolah'";
                if (!mysqli_query($conn, $query_hapus)) throw new Exception("Gagal menghapus data penyaluran.");
            } else {
                if (!empty($range_sebelum)) {
                    $vol_sebelum = count($blok_sebelum);
                    $query_update_asal = "UPDATE penyaluran SET no_reg = '$range_sebelum', vol_penyaluran = $vol_sebelum WHERE id_penyaluran = '$id' AND id_sekolah = '$id_sekolah'";
                    if (!mysqli_query($conn, $query_update_asal)) throw new Exception("Gagal memperbarui data penyaluran bagian awal.");
                }

                if (!empty($range_sesudah)) {
                    $vol_sesudah = count($blok_sesudah);
                    if (empty($range_sebelum)) {
                        $query_update_asal = "UPDATE penyaluran SET no_reg = '$range_sesudah', vol_penyaluran = $vol_sesudah WHERE id_penyaluran = '$id' AND id_sekolah = '$id_sekolah'";
                        if (!mysqli_query($conn, $query_update_asal)) throw new Exception("Gagal memperbarui data penyaluran.");
                    } else {
                        $query_insert_sisa = "INSERT INTO penyaluran (
                                                id_barang, nama_barang, merk_tipe, kode_barang, 
                                                tgl_pengadaan, bln_pengadaan, thn_pengadaan, vol_pengadaan, 
                                                sat_pengadaan, harga_satuan, lokasi, vol_penyaluran, 
                                                nama_penerima, no_reg, keterangan, id_sekolah
                                              ) VALUES (
                                                '$id_barang_asli', '$nama_barang', '$merk_tipe', '$kode_barang', 
                                                '$tgl_pengadaan', '$bln_pengadaan', '$thn_pengadaan', '$vol_pengadaan', 
                                                '$sat_pengadaan', '$harga_satuan', '$lokasi', '$vol_sesudah', 
                                                '$nama_penerima', '$range_sesudah', '$keterangan', '$id_sekolah'
                                              )";
                        if (!mysqli_query($conn, $query_insert_sisa)) throw new Exception("Gagal membuat baris penyaluran baru untuk sisa pecahan paket.");
                    }
                }
            }

            $no_reg_history = str_pad($target_unit_int, $pad_length, '0', STR_PAD_LEFT);
            $vol_history = 1;

        } else {
            if (strpos($no_reg_asal, '-') !== false) {
                $parts = explode('-', $no_reg_asal);
                for ($i = intval($parts[0]); $i <= intval($parts[1]); $i++) {
                    $list_unit_kembali[] = $i;
                }
            } else {
                $list_unit_kembali[] = intval($no_reg_asal);
            }

            $no_reg_history = $no_reg_asal;
            $vol_history = $vol_penyaluran;

            $query_hapus = "DELETE FROM penyaluran WHERE id_penyaluran = '$id' AND id_sekolah = '$id_sekolah'";
            if (!mysqli_query($conn, $query_hapus)) throw new Exception("Gagal menghapus data penyaluran.");
        }

        // =========================================================================
        // 2. UPDATE STATUS KONDISI DI TABEL DETAIL ASET (BERDASARKAN NO REG)
        // =========================================================================
        if(!empty($id_barang_asli)){
            foreach ($list_unit_kembali as $unit) {
                // Ubah angka menjadi format register (misal: 7 menjadi '007')
                $reg_target = str_pad($unit, $pad_length, '0', STR_PAD_LEFT);

                $query_update_kondisi = "UPDATE kondisi_barang_detail 
                                         SET kondisi = '$kondisi_kembali' 
                                         WHERE id_barang = '$id_barang_asli' 
                                         AND no_reg = '$reg_target'";
                
                mysqli_query($conn, $query_update_kondisi);
            }
        }

        // =========================================================================
        // 3. MASUKKAN DATA UNIT YANG DIKEMBALIKAN KE TABEL HISTORY PENGEMBALIAN
        //    (Di sini variabel $keterangan diganti jadi $keterangan_user yang diambil dari ketikan modal form)
        // =========================================================================
        $query_history = "INSERT INTO history_pengembalian (
                            id_penyaluran, nama_barang, merk_tipe, kode_barang, 
                            tgl_pengadaan, bln_pengadaan, thn_pengadaan, vol_pengadaan, 
                            sat_pengadaan, harga_satuan, lokasi, vol_penyaluran, 
                            nama_penerima, no_reg, kondisi, keterangan, id_sekolah, tgl_kembali
                          ) VALUES (
                            '$id', '$nama_barang', '$merk_tipe', '$kode_barang', 
                            '$tgl_pengadaan', '$bln_pengadaan', '$thn_pengadaan', '$vol_pengadaan', 
                            '$sat_pengadaan', '$harga_satuan', '$lokasi', '$vol_history', 
                            '$nama_penerima', '$no_reg_history', '$kondisi_kembali', '$keterangan_user', '$id_sekolah', NOW()
                          )";
                          
        if (!mysqli_query($conn, $query_history)) {
            throw new Exception("Gagal menyimpan catatan history pengembalian.");
        }

        mysqli_commit($conn);
        echo json_encode(['success' => true]);

    } catch (Exception $e) {
        mysqli_rollback($conn);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }

} else {
    echo json_encode(['success' => false, 'message' => 'Data penyaluran tidak ditemukan.']);
}
?>