<?php
session_start();
include 'koneksi.php';

// Set header ke JSON agar kompatibel dengan sistem batching AJAX dari preview_import.php
header('Content-Type: application/json');

// Mencegah server timeout saat memproses banyak baris data
set_time_limit(0);

// 1. Cek apakah user sudah login dan memiliki session id_sekolah
if (!isset($_SESSION['id_sekolah'])) {
    echo json_encode(['success' => false, 'message' => 'Sesi anda habis, silakan login kembali']);
    exit;
}

// 2. Ambil ID Sekolah dari Session
$id_sekolah_session = $_SESSION['id_sekolah'];

// Baca data JSON yang dikirimkan via AJAX Batching
$raw_input = file_get_contents('php://input');
$items = json_decode($raw_input, true);

// Fallback jika request dikirimkan via FORM POST biasa
if (empty($items) && isset($_POST['kode_barang']) && is_array($_POST['kode_barang'])) {
    $items = [];
    $total = count($_POST['kode_barang']);
    for ($i = 0; $i < $total; $i++) {
        $items[] = [
            'reg'          => $_POST['reg'][$i] ?? '',
            'tanggal'      => $_POST['tanggal'][$i] ?? '',
            'kode_barang'  => $_POST['kode_barang'][$i] ?? '',
            'nama'         => $_POST['nama'][$i] ?? '',
            'merek'        => $_POST['merek'][$i] ?? '',
            'no_identitas' => $_POST['no_identitas'][$i] ?? '',
            'bahan'        => $_POST['bahan'][$i] ?? '',
            'asal'         => $_POST['asal'][$i] ?? '',
            'bulan'        => $_POST['bulan'][$i] ?? '',
            'tahun'        => $_POST['tahun'][$i] ?? '',
            'ukuran'       => $_POST['ukuran'][$i] ?? '',
            'satuan'       => $_POST['satuan'][$i] ?? '',
            'keadaan'      => $_POST['keadaan'][$i] ?? '',
            'volume'       => $_POST['volume'][$i] ?? '',
            'harga'        => $_POST['harga'][$i] ?? '',
            'ket'          => $_POST['ket'][$i] ?? ''
        ];
    }
}

if (empty($items) || !is_array($items)) {
    echo json_encode(['success' => false, 'message' => 'Tidak ada data untuk diimport']);
    exit;
}

// 3. Tambahkan kolom id_sekolah ke dalam query INSERT (persis sesuai query awal Anda)
$stmt = $conn->prepare("INSERT INTO data_barang 
(id_sekolah, no_urut, kode_barang, reg, nama_jenis_barang, merek_type, no_identitas, bahan, asal_perolehan, tanggal_perolehan, bulan_perolehan, tahun_perolehan, ukuran_konstruksi, satuan, keadaan_barang, volume, harga_satuan, keterangan)
VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

if (!$stmt) {
    echo json_encode(['success' => false, 'message' => 'Gagal prepare statement: ' . $conn->error]);
    exit;
}

// Tipe data binding (persis sesuai script awal Anda: 18 kolom)
$types = "iissssssssssssssss"; 

mysqli_begin_transaction($conn);

try {
    foreach ($items as $item) {
        // Ambil data dari array item
        $reg_val   = $item['reg'] ?? '';
        $no_urut   = intval($reg_val); // Mengambil angka awal register untuk no_urut (integer)
        $tanggal   = $item['tanggal'] ?? '';
        $kode      = $item['kode_barang'] ?? '';
        $nama      = $item['nama'] ?? '';
        $merek     = $item['merek'] ?? '';
        $identitas = $item['no_identitas'] ?? '';
        $bahan     = $item['bahan'] ?? '';
        $asal      = $item['asal'] ?? '';
        $bulan     = $item['bulan'] ?? '';
        $tahun     = $item['tahun'] ?? '';
        $ukuran    = $item['ukuran'] ?? '';
        $satuan    = $item['satuan'] ?? '';
        $keadaan   = $item['keadaan'] ?? '';
        $volume    = $item['volume'] ?? '';
        $harga     = $item['harga'] ?? '';
        $ket       = $item['ket'] ?? '';
        $reg_str   = strval($reg_val);

        $stmt->bind_param(
            $types,
            $id_sekolah_session, // ID Sekolah dari session
            $no_urut,
            $kode,
            $reg_str,            // reg diisi string register
            $nama,
            $merek,
            $identitas,
            $bahan,
            $asal,
            $tanggal,
            $bulan,
            $tahun,
            $ukuran,
            $satuan,
            $keadaan,
            $volume,
            $harga,
            $ket
        );

        $stmt->execute();
    }

    mysqli_commit($conn);
    $stmt->close();
    $conn->close();

    // Kirim respons sukses ke AJAX
    echo json_encode(['success' => true, 'message' => 'Data berhasil disimpan']);

} catch (Exception $e) {
    mysqli_rollback($conn);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>