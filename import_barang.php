<?php
session_start();

// 1. Cek Login & Session
if(!isset($_SESSION['id_user']) || !isset($_SESSION['id_sekolah'])){
    header("Location: login.php");
    exit;
}

// 2. Koneksi Database
$conn = new mysqli("localhost","root","","db_inventaris");
if($conn->connect_error){
    die("Koneksi DB Gagal: " . $conn->connect_error);
}

$id_sekolah = $_SESSION['id_sekolah'];
$current_page = basename($_SERVER['PHP_SELF']);

function formatRupiah($angka){
    return "Rp " . number_format((float)$angka, 0, ',', '.');
}

// ================= FUNGSI RE-INDEX NO REGISTER (KRONOLOGIS MUTLAK PER TAHUN) =================
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
        // Reset nomor register ke 1 jika tahun perolehan berbeda
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

// ================= MENCEGAH RE-INDEX JALAN DI BACKGROUND SAAT AJAX =================
// Logika ini dibungkus agar HANYA berjalan saat load halaman normal, bukan saat request AJAX (live preview).
if (!isset($_POST['action_ajax']) && !isset($_GET['download_template'])) {
    
    // ================= AUTO-REFRESH REINDEX UNTUK DATA YANG TERBALIK/ACAK =================
    $q_all_kode = mysqli_query($conn, "SELECT DISTINCT kode_barang FROM data_barang WHERE id_sekolah = '$id_sekolah'");
    while($row_kode = mysqli_fetch_assoc($q_all_kode)) {
        urutkanUlangRegister($conn, $id_sekolah, $row_kode['kode_barang']);
    }

    // ================= PERBAIKAN: AUTO-SINKRONISASI DATA BARANG LAMA YANG BELUM PUNYA DETAIL/SALAH URUTAN =================
    $q_cek_lama = mysqli_query($conn, "SELECT id, volume, reg FROM data_barang WHERE id_sekolah = '$id_sekolah'");
    while($row_lama = mysqli_fetch_assoc($q_cek_lama)){
        $id_brg_lama = $row_lama['id'];
        $vol_lama    = intval($row_lama['volume']) ?: 1;
        $reg_lama    = $row_lama['reg'];
        
        // Cek jumlah data yang ada di detail
        $q_hitung = mysqli_query($conn, "SELECT COUNT(*) as total FROM kondisi_barang_detail WHERE id_barang = '$id_brg_lama'");
        $d_hitung = mysqli_fetch_assoc($q_hitung);
        
        // Jika masih 0 (belum ada detail) ATAU jumlah volume beda dengan detail, kita sinkronkan langsung ngikutin nomor 'reg' nya.
        if(($d_hitung['total'] == 0 || $d_hitung['total'] != $vol_lama) && !empty($reg_lama) && $reg_lama != '-'){
            
            // Bersihkan dulu yang tidak sesuai
            mysqli_query($conn, "DELETE FROM kondisi_barang_detail WHERE id_barang = '$id_brg_lama'");
            
            // Buat detail baru berpatokan HANYA pada rentang string `reg` dari data_barang
            if (strpos($reg_lama, '-') !== false) {
                $pecah = explode('-', $reg_lama);
                $awal = (int) $pecah[0];
                $akhir = (int) $pecah[1];
                
                for ($i = $awal; $i <= $akhir; $i++) {
                    $no_unit_formatted = str_pad($i, 3, '0', STR_PAD_LEFT);
                    mysqli_query($conn, "INSERT INTO kondisi_barang_detail (id_barang, no_reg, kondisi) VALUES ('$id_brg_lama', '$no_unit_formatted', '-')");
                }
            } else {
                $no_unit_formatted = str_pad((int)$reg_lama, 3, '0', STR_PAD_LEFT);
                mysqli_query($conn, "INSERT INTO kondisi_barang_detail (id_barang, no_reg, kondisi) VALUES ('$id_brg_lama', '$no_unit_formatted', '-')");
            }
        }
    }
}

// ================= PROSES DOWNLOAD TEMPLATE =================
if (isset($_GET['download_template'])) {
    $file = 'template_import_inventaris_langsung.xlsx'; 
    if (file_exists($file)) {
        if (ob_get_level()) ob_end_clean();
        header('Content-Description: File Transfer');
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="'.basename($file).'"');
        header('Expires: 0');
        header('Cache-Control: must-revalidate');
        header('Pragma: public');
        header('Content-Length: ' . filesize($file));
        readfile($file);
        exit;
    } else {
        echo "<script>alert('File template ($file) tidak ditemukan!'); window.location.href='index.php';</script>";
        exit;
    }
}

// ================= PROSES AJAX =================
if(isset($_POST['action_ajax'])){
    header('Content-Type: application/json');
    $id = isset($_POST['id']) ? intval($_POST['id']) : 0;

   // --- AJAX: LIVE SEARCH KODE BARANG / NAMA BARANG DARI TABEL KODE_BARANG ---
    if($_POST['action_ajax'] == 'search_referensi_kode'){
        $keyword = mysqli_real_escape_string($conn, trim(strtolower($_POST['keyword'])));
        
        if (empty($keyword)) {
            echo json_encode(['status' => 'success', 'results' => []]);
            exit;
        }

        // QUERY PEMBASMI INDUK BER-PADDING TITIK:
        $sql = "SELECT k1.kode_barang, k1.uraian 
                FROM kode_barang k1 
                WHERE (LOWER(k1.kode_barang) LIKE '%$keyword%' OR LOWER(k1.uraian) LIKE '%$keyword%') 
                
                -- 1. Buang semua kode Induk yang memiliki titik ganda/beruntun (seperti '1......' atau '1.1.7....')
                AND k1.kode_barang NOT LIKE '%..%'
                
                -- 2. Buang kode yang diakhiri dengan titik (seperti '1.1.')
                AND k1.kode_barang NOT LIKE '%.'
                
                LIMIT 100";

        $res = mysqli_query($conn, $sql);
        $output = [];
        if ($res) {
            while($row = mysqli_fetch_assoc($res)){
                $output[] = $row;
            }
        }
        
        echo json_encode(['status' => 'success', 'results' => $output]);
        exit;
    }
    // --- AJAX: GET URAIAN BARANG BERDASARKAN KODE EKSAK ---
    if($_POST['action_ajax'] == 'get_uraian_by_kode'){
        $kode_barang = mysqli_real_escape_string($conn, $_POST['kode_barang']);
        $sql = "SELECT uraian FROM KODE_BARANG WHERE kode_barang = '$kode_barang' LIMIT 1";
        $res = mysqli_query($conn, $sql);
        if($row = mysqli_fetch_assoc($res)){
            echo json_encode(['status' => 'success', 'uraian' => $row['uraian']]);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Kode tidak ditemukan']);
        }
        exit;
    }

    // --- AJAX: HITUNG PREVIEW NO REGISTER SECARA AUTOMATIS/REALTIME ---
    if($_POST['action_ajax'] == 'get_next_register'){
        $kode_barang = mysqli_real_escape_string($conn, $_POST['kode_barang']);
        $volume      = intval($_POST['volume']) ?: 1;
        $tgl         = intval($_POST['tanggal_perolehan']) ?: 0;
        $bln         = intval($_POST['bulan_perolehan']) ?: 0;
        $thn         = intval($_POST['tahun_perolehan']) ?: 0;

        // Ditambahkan filter tahun_perolehan = '$thn' agar preview register terisolasi per tahun
        $sql = "SELECT volume, tanggal_perolehan, bulan_perolehan, tahun_perolehan FROM data_barang 
                WHERE id_sekolah = '$id_sekolah' AND kode_barang = '$kode_barang' AND tahun_perolehan = '$thn'
                ORDER BY CAST(tahun_perolehan AS UNSIGNED) ASC, 
                         CAST(bulan_perolehan AS UNSIGNED) ASC, 
                         CAST(tanggal_perolehan AS UNSIGNED) ASC, 
                         id ASC";
        $res = mysqli_query($conn, $sql);

        $current_reg = 1;
        $preview_reg_val = "-";
        $list_barang = [];
        while($row = mysqli_fetch_assoc($res)) { $list_barang[] = $row; }

        $simulated_list = [];
        $inserted = false;
        foreach($list_barang as $b) {
            if(!$inserted) {
                if(($thn < $b['tahun_perolehan']) || 
                   ($thn == $b['tahun_perolehan'] && $bln < $b['bulan_perolehan']) || 
                   ($thn == $b['tahun_perolehan'] && $bln == $b['bulan_perolehan'] && $tgl <= $b['tanggal_perolehan'])) {
                    $simulated_list[] = ['volume' => $volume, 'is_new' => true];
                    $inserted = true;
                }
            }
            $simulated_list[] = ['volume' => $b['volume'], 'is_new' => false];
        }
        if(!$inserted) { $simulated_list[] = ['volume' => $volume, 'is_new' => true]; }

        foreach($simulated_list as $item) {
            $vol = intval($item['volume']) ?: 1;
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
            if(isset($item['is_new']) && $item['is_new'] == true) { $preview_reg_val = $reg_val; }
        }
        echo json_encode(['status' => 'success', 'preview_reg' => $preview_reg_val]);
        exit;
    }

    // --- AJAX: UPDATE DATA EDIT (TERMASUK HISTORI PEROLEHAN & PENYESUAIAN KONDISI DETAIL) ---
    if($_POST['action_ajax'] == 'update_data'){
        $nama_barang = mysqli_real_escape_string($conn, $_POST['nama_jenis_barang']);
        $kode_barang = mysqli_real_escape_string($conn, $_POST['kode_barang']);
        $merek       = mysqli_real_escape_string($conn, $_POST['merek_type']);
        $bahan       = mysqli_real_escape_string($conn, $_POST['bahan']);
        $volume      = intval($_POST['volume']) ?: 1;
        $satuan      = mysqli_real_escape_string($conn, $_POST['satuan']);
        $keterangan  = mysqli_real_escape_string($conn, $_POST['keterangan']);
        
        $asal_perolehan = mysqli_real_escape_string($conn, $_POST['asal_perolehan']);
        $tgl_perolehan  = mysqli_real_escape_string($conn, $_POST['tanggal_perolehan']) ?: '0';
        $bln_perolehan  = mysqli_real_escape_string($conn, $_POST['bulan_perolehan']) ?: '0';
        $thn_perolehan  = mysqli_real_escape_string($conn, $_POST['tahun_perolehan']);

        $harga_raw   = $_POST['harga_satuan'];
        $harga_final = (int)preg_replace('/[^0-9]/', '', $harga_raw);

        $q_old = mysqli_query($conn, "SELECT kode_barang, volume FROM data_barang WHERE id='$id'");
        $d_old = mysqli_fetch_assoc($q_old);
        $kode_lama = $d_old['kode_barang'] ?? '';
        $vol_lama  = intval($d_old['volume'] ?? 1);

        $sql = "UPDATE data_barang SET 
                nama_jenis_barang = '$nama_barang',
                kode_barang = '$kode_barang',
                merek_type = '$merek',
                bahan = '$bahan',
                volume = '$volume',
                satuan = '$satuan',
                harga_satuan = '$harga_final', 
                keterangan = '$keterangan',
                asal_perolehan = '$asal_perolehan',
                tanggal_perolehan = '$tgl_perolehan',
                bulan_perolehan = '$bln_perolehan',
                tahun_perolehan = '$thn_perolehan'
                WHERE id = '$id' AND id_sekolah = '$id_sekolah'";

        if(mysqli_query($conn, $sql)){
            // Jika volume berubah, wipe detail lama karena register pasti ikut berantakan
            if($volume != $vol_lama) {
                mysqli_query($conn, "DELETE FROM kondisi_barang_detail WHERE id_barang = '$id'");
            }

            // Urutkan dan generate reg setelah edit data masuk ke tabel
            urutkanUlangRegister($conn, $id_sekolah, $kode_barang);
            if($kode_lama != '' && $kode_lama != $kode_barang) {
                urutkanUlangRegister($conn, $id_sekolah, $kode_lama);
            }
            
            // Generate detail kondisi sesuai reg yang baru dihitung jika terjadi perubahan volume
            if($volume != $vol_lama) {
                $q_reg_edit = mysqli_query($conn, "SELECT reg FROM data_barang WHERE id = '$id'");
                $d_reg_edit = mysqli_fetch_assoc($q_reg_edit);
                $reg_edit = $d_reg_edit['reg'];
                
                if (!empty($reg_edit) && $reg_edit != '-') {
                    if (strpos($reg_edit, '-') !== false) {
                        $pecah = explode('-', $reg_edit);
                        $awal = (int) $pecah[0];
                        $akhir = (int) $pecah[1];
                        for ($i = $awal; $i <= $akhir; $i++) {
                            $no_unit_formatted = str_pad($i, 3, '0', STR_PAD_LEFT);
                            mysqli_query($conn, "INSERT INTO kondisi_barang_detail (id_barang, no_reg, kondisi) VALUES ('$id', '$no_unit_formatted', '-')");
                        }
                    } else {
                        $no_unit_formatted = str_pad((int)$reg_edit, 3, '0', STR_PAD_LEFT);
                        mysqli_query($conn, "INSERT INTO kondisi_barang_detail (id_barang, no_reg, kondisi) VALUES ('$id', '$no_unit_formatted', '-')");
                    }
                }
            }

            echo json_encode(['status' => 'success']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Gagal simpan ke database']);
        }
        exit;
    }

    if($_POST['action_ajax'] == 'get_data'){
        $q = mysqli_query($conn, "SELECT * FROM data_barang WHERE id='$id' AND id_sekolah='$id_sekolah'");
        $data = mysqli_fetch_assoc($q);
        echo json_encode($data);
        exit;
    }

    if($_POST['action_ajax'] == 'hapus'){
        $q_get = mysqli_query($conn, "SELECT kode_barang FROM data_barang WHERE id='$id'");
        $d_get = mysqli_fetch_assoc($q_get);
        
        // Hapus juga data dari tabel kondisi_barang_detail agar database bersih
        mysqli_query($conn,"DELETE FROM kondisi_barang_detail WHERE id_barang='$id'");
        mysqli_query($conn,"DELETE FROM data_barang WHERE id='$id' AND id_sekolah='$id_sekolah'");
        
        if($d_get) { urutkanUlangRegister($conn, $id_sekolah, $d_get['kode_barang']); }
        echo json_encode(['status' => 'success']);
        exit;
    }

    // --- AJAX: HAPUS SEMUA DATA BARANG SEKOLAH INI ---
    if($_POST['action_ajax'] == 'hapus_semua_data'){
        // Hapus semua data kondisi detail barang terkait sekolah ini
        mysqli_query($conn, "DELETE kbd FROM kondisi_barang_detail kbd JOIN data_barang db ON kbd.id_barang = db.id WHERE db.id_sekolah = '$id_sekolah'");
        // Hapus semua data barang milik sekolah ini
        mysqli_query($conn, "DELETE FROM data_barang WHERE id_sekolah = '$id_sekolah'");

        echo json_encode(['status' => 'success']);
        exit;
    }

    // --- AJAX: UPLOAD FOTO LANGSUNG ---
    if($_POST['action_ajax'] == 'upload_foto_langsung'){
        if(!empty($_FILES['foto_langsung']['name'])){
            $ext = pathinfo($_FILES['foto_langsung']['name'], PATHINFO_EXTENSION);
            $nama_file_baru = "brg_" . time() . "_" . $id . "." . $ext;
            $tujuan = "upload/" . $nama_file_baru;
            if(move_uploaded_file($_FILES['foto_langsung']['tmp_name'], $tujuan)){
                mysqli_query($conn, "UPDATE data_barang SET foto = '$nama_file_baru' WHERE id = '$id' AND id_sekolah = '$id_sekolah'");
                echo json_encode(['status' => 'success', 'nama_file' => $nama_file_baru]);
            } else { echo json_encode(['status' => 'error', 'message' => 'Gagal upload file']); }
        } else { echo json_encode(['status' => 'error', 'message' => 'Tidak ada file']); }
        exit;
    }

    // --- AJAX: TAMBAH DATA MANUAL ---
    if($_POST['action_ajax'] == 'tambah_data'){
        $nama_barang = mysqli_real_escape_string($conn, $_POST['nama_jenis_barang']);
        $kode_barang = mysqli_real_escape_string($conn, $_POST['kode_barang']);
        $merek       = mysqli_real_escape_string($conn, $_POST['merek_type']);
        $bahan       = mysqli_real_escape_string($conn, $_POST['bahan']);
        $volume      = intval($_POST['volume']) ?: 1;
        $satuan      = mysqli_real_escape_string($conn, $_POST['satuan']);
        $keadaan     = mysqli_real_escape_string($conn, $_POST['keadaan_barang']); 
        $keterangan  = mysqli_real_escape_string($conn, $_POST['keterangan']);
        
        $no_identitas  = mysqli_real_escape_string($conn, $_POST['no_identitas']);
        $asal_perolehan= mysqli_real_escape_string($conn, $_POST['asal_perolehan']);
        $tgl_perolehan = mysqli_real_escape_string($conn, $_POST['tanggal_perolehan']) ?: '0';
        $bln_perolehan = mysqli_real_escape_string($conn, $_POST['bulan_perolehan']) ?: '0';
        $thn_perolehan = mysqli_real_escape_string($conn, $_POST['tahun_perolehan']);
        $harga_raw   = $_POST['harga_satuan'];
        $harga_final = (int)preg_replace('/[^0-9]/', '', $harga_raw);

        $nama_file_baru = "";
        if(!empty($_FILES['foto_input']['name'])){
            $ext = pathinfo($_FILES['foto_input']['name'], PATHINFO_EXTENSION);
            $nama_file_baru = "brg_" . time() . "_" . rand(100,999) . "." . $ext;
            $tujuan = "upload/" . $nama_file_baru;
            move_uploaded_file($_FILES['foto_input']['tmp_name'], $tujuan);
        }

        $sql = "INSERT INTO data_barang (
                    id_sekolah, nama_jenis_barang, kode_barang, merek_type, bahan, volume, satuan, 
                    keadaan_barang, harga_satuan, keterangan, foto, reg, no_identitas, 
                    asal_perolehan, tanggal_perolehan, bulan_perolehan, tahun_perolehan
                ) VALUES (
                    '$id_sekolah', '$nama_barang', '$kode_barang', '$merek', '$bahan', '$volume', '$satuan', 
                    '$keadaan', '$harga_final', '$keterangan', '$nama_file_baru', '', '$no_identitas', 
                    '$asal_perolehan', '$tgl_perolehan', '$bln_perolehan', '$thn_perolehan'
                )";

        if(mysqli_query($conn, $sql)){
            $id_barang_baru = mysqli_insert_id($conn);
            
            // 1. Eksekusi urutkan agar barang baru dapat NO REGISTER valid sesuai dengan kronologinya
            urutkanUlangRegister($conn, $id_sekolah, $kode_barang);
            
            // 2. Ambil Nomor Register yang baru saja diberikan
            $q_reg_baru = mysqli_query($conn, "SELECT reg FROM data_barang WHERE id = '$id_barang_baru'");
            $d_reg_baru = mysqli_fetch_assoc($q_reg_baru);
            $reg_input = $d_reg_baru['reg'];

            // 3. LOGIKA UTAMA SINKRONISASI: Input ke kondisi_barang_detail ngikutin rentang angka 'reg' nya
            if (!empty($reg_input) && $reg_input != '-') {
                if (strpos($reg_input, '-') !== false) {
                    $pecah = explode('-', $reg_input);
                    $awal = (int) $pecah[0];
                    $akhir = (int) $pecah[1];
                    for ($i = $awal; $i <= $akhir; $i++) {
                        $no_unit_formatted = str_pad($i, 3, '0', STR_PAD_LEFT);
                        mysqli_query($conn, "INSERT INTO kondisi_barang_detail (id_barang, no_reg, kondisi) VALUES ('$id_barang_baru', '$no_unit_formatted', '$keadaan')");
                    }
                } else {
                    $no_unit_formatted = str_pad((int)$reg_input, 3, '0', STR_PAD_LEFT);
                    mysqli_query($conn, "INSERT INTO kondisi_barang_detail (id_barang, no_reg, kondisi) VALUES ('$id_barang_baru', '$no_unit_formatted', '$keadaan')");
                }
            }

            echo json_encode(['status' => 'success']);
        } else { echo json_encode(['status' => 'error', 'message' => 'Gagal input data']); }
        exit;
    }
}

$kop_q = mysqli_query($conn,"SELECT nama_sekolah, logo FROM kop_surat WHERE id_sekolah='$id_sekolah' LIMIT 1");
$kop = mysqli_fetch_assoc($kop_q);
$nama_sekolah = $kop['nama_sekolah'] ?? "INSTANSI PENDIDIKAN";
$logo_prov = !empty($kop['logo']) ? $kop['logo'] : "logo.jpg";
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inventaris - <?= htmlspecialchars($nama_sekolah, ENT_QUOTES, 'UTF-8'); ?></title>
    
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">

    <style>
        :root { 
            --primary: #1677ff; 
            --success: #16a34a; 
            --bg-body: #eef6fb; 
        }
        body { 
            background: linear-gradient(180deg, #f3f9fd 0%, #e4f1fa 100%);
            font-family: 'Plus Jakarta Sans', sans-serif; 
            color: #1e293b; 
            min-height: 100vh;
        }

        /* ================= BANNER UTAMA SISTEM INVENTARIS ================= */
        .hero-banner-inventaris {
            position: relative;
            background: linear-gradient(95deg, #05394d 0%, #086882 48%, #109db3 86%, #18bdc9 100%);
            border-radius: 18px;
            padding: 20px 28px;
            color: #ffffff;
            display: flex;
            align-items: center;
            justify-content: space-between;
            overflow: hidden;
            margin-bottom: 20px;
            box-shadow: 0 10px 25px -5px rgba(8, 104, 130, 0.25);
        }

        .hero-banner-inventaris::before {
            content: '';
            position: absolute;
            right: 18%;
            bottom: -35px;
            width: 45%;
            height: 85px;
            background: rgba(255, 255, 255, 0.12);
            border-radius: 50% 50% 0 0;
            transform: rotate(-4deg);
            pointer-events: none;
        }
        .hero-banner-inventaris::after {
            content: '';
            position: absolute;
            right: -5%;
            top: -25px;
            width: 35%;
            height: 90px;
            background: rgba(34, 211, 238, 0.16);
            border-radius: 50%;
            pointer-events: none;
        }

        .hero-logo-box {
            width: 56px;
            height: 56px;
            background: #ffffff;
            border-radius: 50%;
            padding: 5px;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
            flex-shrink: 0;
            position: relative;
            z-index: 2;
        }
        .hero-logo-box img {
            width: 100%;
            height: 100%;
            object-fit: contain;
        }

        .hero-divider-v {
            width: 1.5px;
            height: 44px;
            background: rgba(255, 255, 255, 0.45);
            margin: 0 18px;
            position: relative;
            z-index: 2;
        }

        .hero-text-wrap {
            position: relative;
            z-index: 2;
        }
        .hero-sub-label {
            font-size: 11.5px;
            font-weight: 700;
            letter-spacing: 0.8px;
            text-transform: uppercase;
            color: rgba(255, 255, 255, 0.9);
            margin-bottom: 2px;
        }
        .hero-school-title {
            font-size: 23px;
            font-weight: 800;
            letter-spacing: 0.3px;
            margin: 0;
            line-height: 1.15;
            text-transform: uppercase;
        }

        .hero-building-watermark {
            font-size: 64px;
            color: rgba(255, 255, 255, 0.16);
            line-height: 1;
            position: relative;
            z-index: 1;
        }

        /* ================= KARTU AKSI & IMPORT EXCEL ================= */
        .action-import-card {
            background: #ffffff;
            border-radius: 18px;
            border: 1px solid rgba(226, 232, 240, 0.95);
            box-shadow: 0 8px 24px -4px rgba(15, 23, 42, 0.05);
            padding: 20px 24px;
            margin-bottom: 22px;
        }

        .section-icon-square {
            width: 38px;
            height: 38px;
            border-radius: 10px;
            background: linear-gradient(135deg, #1677ff 0%, #0958d9 100%);
            color: #ffffff;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            flex-shrink: 0;
            box-shadow: 0 4px 10px rgba(22, 119, 255, 0.25);
        }

        .excel-icon-square {
            width: 34px;
            height: 38px;
            border-radius: 8px;
            background: linear-gradient(135deg, #16a34a 0%, #15803d 100%);
            color: #ffffff;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-weight: 800;
            font-size: 15px;
            flex-shrink: 0;
            box-shadow: 0 4px 10px rgba(22, 163, 74, 0.25);
        }

        .btn-input-manual {
            background: linear-gradient(135deg, #1677ff 0%, #1d4ed8 100%);
            border: none;
            color: #ffffff;
            font-size: 13px;
            font-weight: 700;
            padding: 9px 18px;
            border-radius: 10px;
            display: inline-flex;
            align-items: center;
            gap: 7px;
            box-shadow: 0 4px 12px rgba(22, 119, 255, 0.25);
            transition: all 0.2s;
        }
        .btn-input-manual:hover {
            background: linear-gradient(135deg, #1d4ed8 0%, #1e40af 100%);
            color: #ffffff;
            transform: translateY(-1px);
        }

        .btn-hapus-semua-top {
            background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
            border: none;
            color: #ffffff;
            font-size: 13px;
            font-weight: 700;
            padding: 9px 18px;
            border-radius: 10px;
            display: inline-flex;
            align-items: center;
            gap: 7px;
            box-shadow: 0 4px 12px rgba(220, 38, 38, 0.25);
            transition: all 0.2s;
        }
        .btn-hapus-semua-top:hover {
            background: linear-gradient(135deg, #dc2626 0%, #b91c1c 100%);
            color: #ffffff;
            transform: translateY(-1px);
        }

        .excel-picker-box {
            display: flex;
            align-items: center;
            background: #ffffff;
            border: 1px solid #cbd5e1;
            border-radius: 10px;
            padding: 4px;
            min-width: 260px;
            max-width: 340px;
            flex: 1;
        }
        .btn-choose-file-xl {
            background: #f1f5f9;
            border: 1px solid #e2e8f0;
            color: #0f172a;
            font-size: 12.5px;
            font-weight: 700;
            padding: 6px 12px;
            border-radius: 7px;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            white-space: nowrap;
            transition: 0.2s;
        }
        .btn-choose-file-xl:hover { background: #e2e8f0; }
        .excel-chosen-text {
            padding: 0 12px;
            font-size: 12.5px;
            color: #475569;
            font-weight: 500;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .btn-import-data-green {
            background: linear-gradient(135deg, #16a34a 0%, #15803d 100%);
            border: none;
            color: #ffffff;
            font-size: 13px;
            font-weight: 700;
            padding: 9px 18px;
            border-radius: 10px;
            display: inline-flex;
            align-items: center;
            gap: 7px;
            white-space: nowrap;
            box-shadow: 0 4px 12px rgba(22, 163, 74, 0.25);
            transition: all 0.2s;
        }
        .btn-import-data-green:hover {
            background: linear-gradient(135deg, #15803d 0%, #166534 100%);
            color: #ffffff;
            transform: translateY(-1px);
        }

        .btn-download-template-pill {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            background: #f0fdf4;
            color: #15803d;
            border: 1.5px solid #86efac;
            padding: 8px 20px;
            border-radius: 25px;
            font-size: 13px;
            font-weight: 700;
            text-decoration: none;
            transition: all 0.2s;
        }
        .btn-download-template-pill:hover {
            background: #16a34a;
            color: #ffffff;
            border-color: #16a34a;
            transform: translateY(-1px);
        }

        /* ================= CONTAINER TABEL DAFTAR BARANG ================= */
        .table-card-container {
            background: #ffffff;
            border-radius: 18px;
            border: 1px solid rgba(226, 232, 240, 0.95);
            box-shadow: 0 10px 28px -5px rgba(15, 23, 42, 0.05);
            overflow: hidden;
            margin-bottom: 40px;
        }

        .table-banner-bar {
            position: relative;
            background: linear-gradient(95deg, #05394d 0%, #086882 48%, #109db3 86%, #18bdc9 100%);
            padding: 14px 22px;
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            overflow: hidden;
        }
        .table-banner-bar::after {
            content: '';
            position: absolute;
            right: 25%;
            bottom: -24px;
            width: 35%;
            height: 50px;
            background: rgba(255, 255, 255, 0.12);
            border-radius: 50% 50% 0 0;
            pointer-events: none;
        }

        .table-banner-heading {
            display: flex;
            align-items: center;
            gap: 10px;
            color: #ffffff;
            font-size: 16.5px;
            font-weight: 800;
            margin: 0;
            position: relative;
            z-index: 2;
        }

        .filter-pill-select {
            height: 36px;
            border-radius: 22px !important;
            border: none !important;
            background-color: #ffffff !important;
            color: #0f172a !important;
            font-size: 12.5px !important;
            font-weight: 700 !important;
            padding: 0 32px 0 15px !important;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
            cursor: pointer;
        }

        .search-pill-wrapper {
            position: relative;
            width: 245px;
            max-width: 100%;
        }
        .search-pill-wrapper i {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            color: #475569;
            font-size: 13px;
            z-index: 3;
        }
        .search-pill-input {
            height: 36px !important;
            padding-left: 36px !important;
            padding-right: 14px !important;
            border-radius: 22px !important;
            border: none !important;
            background-color: #ffffff !important;
            color: #0f172a !important;
            font-size: 12.5px !important;
            font-weight: 500;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
        }

        /* ================= TABEL & KOLOM ================= */
        .table-responsive {
            max-height: 620px;
            overflow-y: auto;
        }
        #tableBarang {
            margin-bottom: 0;
            border-collapse: separate;
            border-spacing: 0;
        }
        #tableBarang thead th {
            position: sticky;
            top: 0;
            background-color: #f1f5f9;
            color: #0f172a;
            font-weight: 800;
            font-size: 12.5px;
            padding: 13px 12px;
            border-bottom: 1.5px solid #e2e8f0;
            z-index: 10;
            white-space: nowrap;
        }
        #tableBarang tbody tr:nth-child(even) {
            background-color: #f5fafe;
        }
        #tableBarang tbody tr:hover {
            background-color: #e8f4fc;
        }
        #tableBarang tbody td {
            padding: 12px 12px;
            border-bottom: 1px solid #e2e8f0;
            vertical-align: middle;
            font-size: 13px;
        }

        .badge-reg-pill {
            display: inline-block;
            background: #dcfce7;
            color: #15803d;
            font-weight: 800;
            font-size: 12px;
            padding: 4px 12px;
            border-radius: 20px;
        }

        .foto-cell-wrap {
            display: inline-flex;
            flex-direction: column;
            align-items: center;
            gap: 5px;
        }
        .img-barang {
            width: 52px;
            height: 44px;
            object-fit: cover;
            border-radius: 9px;
            border: 1.5px solid #cbd5e1;
            background: #f1f5f9;
            transition: transform 0.25s ease;
        }
        .img-barang:hover {
            transform: scale(2.4);
            position: relative;
            z-index: 99;
            box-shadow: 0 8px 20px rgba(0,0,0,0.2);
        }
        .btn-pilih-foto-mini {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            background: #ffffff;
            border: 1px solid #cbd5e1;
            color: #334155;
            font-size: 10.5px;
            font-weight: 700;
            padding: 2px 8px;
            border-radius: 6px;
            cursor: pointer;
            transition: 0.15s;
            white-space: nowrap;
        }
        .btn-pilih-foto-mini:hover {
            background: #f1f5f9;
            border-color: #94a3b8;
        }

        .kondisi-stack {
            display: flex;
            flex-direction: column;
            gap: 4px;
            min-width: 112px;
        }
        .kondisi-pill-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 3px 9px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 700;
            color: #ffffff;
            line-height: 1.2;
        }
        .kp-baik { background-color: #15803d; }
        .kp-rr   { background-color: #ea580c; }
        .kp-rb   { background-color: #dc2626; }

        .aksi-btn-group {
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }
        .btn-aksi-box {
            width: 30px;
            height: 30px;
            border-radius: 8px;
            border: 1px solid transparent;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 13.5px;
            transition: 0.2s;
            cursor: pointer;
        }
        .btn-aksi-kondisi {
            background: #ecfdf5;
            border-color: #a7f3d0;
            color: #059669;
        }
        .btn-aksi-kondisi:hover { background: #d1fae5; color: #047857; }

        .btn-aksi-edit {
            background: #eff6ff;
            border-color: #bfdbfe;
            color: #2563eb;
        }
        .btn-aksi-edit:hover { background: #dbeafe; color: #1d4ed8; }

        .btn-aksi-hapus {
            background: #fef2f2;
            border-color: #fecaca;
            color: #dc2626;
        }
        .btn-aksi-hapus:hover { background: #fee2e2; color: #b91c1c; }

        /* ================= UI MODERN MODAL INPUT MANUAL & EDIT (SESUAI DESAIN GAMBAR) ================= */
        .modal-modern-inv .modal-content {
            border-radius: 18px !important;
            overflow: hidden;
            border: none !important;
            box-shadow: 0 25px 50px -12px rgba(5, 57, 77, 0.35) !important;
        }

        .modal-header-teal {
            position: relative;
            background: linear-gradient(95deg, #05394d 0%, #086882 48%, #109db3 86%, #18bdc9 100%);
            padding: 16px 24px;
            border-bottom: none;
            overflow: hidden;
        }
        .modal-header-teal::before {
            content: '';
            position: absolute;
            right: 12%;
            bottom: -28px;
            width: 45%;
            height: 65px;
            background: rgba(255, 255, 255, 0.13);
            border-radius: 50% 50% 0 0;
            transform: rotate(-3deg);
            pointer-events: none;
        }
        .modal-header-teal::after {
            content: '';
            position: absolute;
            right: -4%;
            top: -22px;
            width: 30%;
            height: 68px;
            background: rgba(34, 211, 238, 0.16);
            border-radius: 50%;
            pointer-events: none;
        }
        .modal-header-teal .modal-title {
            position: relative;
            z-index: 2;
            font-size: 16.5px;
            font-weight: 800;
            color: #ffffff;
            display: flex;
            align-items: center;
            gap: 10px;
            letter-spacing: 0.2px;
        }
        .modal-header-teal .btn-close {
            position: relative;
            z-index: 2;
        }

        .modal-body-sky {
            background: #eef6fb;
            padding: 20px !important;
        }

        .bento-card-modal {
            background: #ffffff;
            border-radius: 14px;
            border: 1px solid #dbe7f2;
            box-shadow: 0 4px 14px rgba(15, 23, 42, 0.04);
            overflow: hidden;
            height: 100%;
            display: flex;
            flex-direction: column;
        }

        .bento-card-header {
            background: linear-gradient(95deg, #06445b 0%, #0b6f8a 65%, #1291a8 100%);
            color: #ffffff;
            padding: 11px 16px;
            font-size: 13.5px;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 9px;
        }
        .bento-card-header i {
            font-size: 15px;
            color: #7dd3fc;
        }

        .bento-card-body {
            padding: 16px;
            flex: 1;
        }

        .photo-dashed-frame {
            background: #f8fafc;
            border: 1.5px dashed #cbd5e1;
            border-radius: 12px;
            padding: 8px;
            margin-bottom: 12px;
        }
        .photo-dashed-frame .preview-img-modal {
            width: 100%;
            height: 155px;
            object-fit: contain;
            border-radius: 8px;
            background: #eef2f6;
            border: none;
            padding: 4px;
        }

        .custom-file-pill-box {
            display: flex;
            align-items: center;
            background: #ffffff;
            border: 1px solid #cbd5e1;
            border-radius: 10px;
            padding: 3px;
            margin-bottom: 14px;
        }
        .btn-choose-photo-pill {
            background: #eff6ff;
            border: 1px solid #bfdbfe;
            color: #1e293b;
            font-size: 12px;
            font-weight: 700;
            padding: 5px 11px;
            border-radius: 8px;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            white-space: nowrap;
            transition: 0.15s;
        }
        .btn-choose-photo-pill:hover {
            background: #dbeafe;
        }
        .chosen-photo-name {
            padding: 0 10px;
            font-size: 11.5px;
            color: #475569;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .sub-section-heading {
            font-size: 12.5px;
            font-weight: 800;
            color: #0f172a;
            display: flex;
            align-items: center;
            gap: 7px;
            padding-top: 10px;
            margin-bottom: 10px;
            border-top: 1px solid #e2e8f0;
        }
        .sub-section-heading i {
            color: #0284c7;
            font-size: 14px;
        }

        .reg-mint-box {
            background: #eaf8f0;
            border: 1px solid #bbf7d0;
            border-radius: 11px;
            padding: 10px 12px;
            margin-bottom: 12px;
        }
        .reg-mint-input {
            background: transparent !important;
            border: none !important;
            box-shadow: none !important;
            color: #15803d !important;
            font-weight: 800 !important;
            font-size: 16px !important;
            text-align: center;
            padding: 2px 0 !important;
        }

        .field-label-icon {
            font-size: 12px;
            font-weight: 700;
            color: #0f172a;
            margin-bottom: 5px;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .field-label-icon i {
            color: #0284c7;
            font-size: 13px;
        }

        .input-search-icon-wrap {
            position: relative;
        }
        .input-search-icon-wrap > i {
            position: absolute;
            left: 11px;
            top: 50%;
            transform: translateY(-50%);
            color: #64748b;
            font-size: 13px;
            pointer-events: none;
        }
        .input-search-icon-wrap input {
            padding-left: 32px !important;
        }

        .modal-modern-inv .form-control,
        .modal-modern-inv .form-select {
            border-radius: 9px !important;
            border: 1px solid #cbd5e1;
            font-size: 12.5px;
            padding: 7px 11px;
            color: #0f172a;
        }
        .modal-modern-inv .form-control:focus,
        .modal-modern-inv .form-select:focus {
            border-color: #0ea5e9;
            box-shadow: 0 0 0 3px rgba(14, 165, 233, 0.14);
        }
        .modal-modern-inv .input-readonly-gray {
            background-color: #e9eef3 !important;
            color: #475569 !important;
            font-weight: 600;
        }

        .modal-footer-modern {
            background: #f8fbfd;
            border-top: 1px solid #dbe7f2;
            padding: 13px 22px;
            gap: 10px;
        }
        .btn-modal-tutup {
            background: #ffffff;
            border: 1px solid #cbd5e1;
            color: #0f172a;
            font-size: 13px;
            font-weight: 700;
            padding: 8px 20px;
            border-radius: 10px;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: 0.2s;
        }
        .btn-modal-tutup:hover {
            background: #f1f5f9;
            color: #0f172a;
        }
        .btn-modal-simpan-green {
            background: linear-gradient(135deg, #16a34a 0%, #15803d 100%);
            border: none;
            color: #ffffff;
            font-size: 13px;
            font-weight: 700;
            padding: 8px 22px;
            border-radius: 10px;
            display: inline-flex;
            align-items: center;
            gap: 7px;
            box-shadow: 0 4px 12px rgba(22, 163, 74, 0.25);
            transition: 0.2s;
        }
        .btn-modal-simpan-green:hover {
            background: linear-gradient(135deg, #15803d 0%, #166534 100%);
            color: #ffffff;
        }
        .btn-modal-simpan-blue {
            background: linear-gradient(135deg, #0284c7 0%, #0369a1 100%);
            border: none;
            color: #ffffff;
            font-size: 13px;
            font-weight: 700;
            padding: 8px 22px;
            border-radius: 10px;
            display: inline-flex;
            align-items: center;
            gap: 7px;
            box-shadow: 0 4px 12px rgba(2, 132, 199, 0.25);
            transition: 0.2s;
        }
        .btn-modal-simpan-blue:hover {
            background: linear-gradient(135deg, #0369a1 0%, #075985 100%);
            color: #ffffff;
        }

        /* Modal & Helper Styles */
        .label-mini { font-size: 0.7rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; color: #64748b; margin-bottom: 3px; display: block; }
        .section-title { font-size: 0.75rem; font-weight: 800; color: #1e3a8a; border-bottom: 2px solid #f1f5f9; padding-bottom: 5px; margin-bottom: 15px; text-transform: uppercase; }
        #loadingOverlay { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(255, 255, 255, 0.9); backdrop-filter: blur(5px); display: none; align-items: center; justify-content: center; z-index: 9999; flex-direction: column; }
        .preview-img-modal { width: 100%; height: 180px; object-fit: contain; border-radius: 12px; background: #fff; border: 1px solid #e2e8f0; padding: 5px; }
        .form-control-custom { font-size: 13px; border-radius: 8px; padding: 8px 12px; border: 1px solid #dee2e6; }

        .autocomplete-suggestions { position: absolute; border: 1px solid #e2e8f0; background: #FFF; overflow: auto; max-height: 250px; z-index: 9999; border-radius: 8px; box-shadow: 0 4px 12px rgba(0,0,0,0.1); width: 100%; display: none; margin-top: 2px; }
        .autocomplete-suggestion { padding: 10px 15px; cursor: pointer; font-size: 13px; border-bottom: 1px solid #f1f5f9; }
        .autocomplete-suggestion:hover { background: #f0f9ff; color: #0284c7; }
        .autocomplete-suggestion strong { color: #2563eb; }

        .success-checkmark { width: 80px; height: 80px; margin: 0 auto; }
        .check-icon { width: 80px; height: 80px; position: relative; border-radius: 50%; box-sizing: content-box; border: 4px solid #4CAF50; }
        .icon-line { height: 5px; background-color: #4CAF50; display: block; border-radius: 2px; position: absolute; z-index: 10; }
        .line-tip { top: 46px; left: 14px; width: 25px; transform: rotate(45deg); }
        .line-long { top: 38px; right: 8px; width: 47px; transform: rotate(-45deg); }
    </style>
</head>
<body>

<div id="loadingOverlay">
    <div class="spinner-border text-primary mb-3" role="status"></div>
    <h6 class="fw-bold">Sedang Memproses Data...</h6>
</div>

<div class="container-fluid px-lg-4 pt-3">
    
    <!-- ================= HERO BANNER SISTEM INVENTARIS ================= -->
    <div class="hero-banner-inventaris">
        <div class="d-flex align-items-center">
            <div class="hero-logo-box">
                <img src="upload_kop/<?= htmlspecialchars($logo_prov, ENT_QUOTES, 'UTF-8'); ?>" alt="Logo" onerror="this.src='https://upload.wikimedia.org/wikipedia/commons/b/b3/Logo_Jawa_Barat.png'">
            </div>
            <div class="hero-divider-v"></div>
            <div class="hero-text-wrap">
                <div class="hero-sub-label">SISTEM INVENTARIS</div>
                <h2 class="hero-school-title"><?= htmlspecialchars($nama_sekolah, ENT_QUOTES, 'UTF-8'); ?></h2>
            </div>
        </div>
        <div class="hero-building-watermark d-none d-md-block">
            <i class="bi bi-buildings-fill"></i>
        </div>
    </div>

    <!-- ================= KARTU INPUT MANUAL & IMPORT EXCEL ================= -->
    <div class="action-import-card">
        <div class="row align-items-center g-4">
            <!-- SEBELAH KIRI: INPUT MANUAL BARANG & TOMBOL HAPUS SEMUA DATA -->
            <div class="col-xl-4 col-lg-5 border-end-lg pe-lg-4">
                <div class="d-flex align-items-start gap-3 mb-3">
                    <div class="section-icon-square">
                        <i class="bi bi-layers-fill"></i>
                    </div>
                    <div>
                        <h6 class="fw-bold mb-0 text-dark" style="font-size: 15px;">Input & Opsi Data</h6>
                        <p class="text-muted small mb-0">Tambah data barang inventaris atau hapus seluruh data.</p>
                    </div>
                </div>
                <div class="d-flex flex-wrap gap-2">
                    <button type="button" class="btn-input-manual" data-bs-toggle="modal" data-bs-target="#modalTambahManual">
                        <i class="bi bi-pencil-square"></i> Input Manual
                    </button>
                    <button type="button" class="btn-hapus-semua-top" id="btnTriggerHapusSemua">
                        <i class="bi bi-database-fill-x"></i> Hapus Semua Data
                    </button>
                </div>
            </div>

            <!-- SEBELAH KANAN: TEMPLATE & IMPORT EXCEL -->
            <div class="col-xl-8 col-lg-7 ps-lg-4">
                <div class="d-flex flex-wrap align-items-start justify-content-between gap-3">
                    <div class="flex-grow-1">
                        <div class="d-flex align-items-center gap-2 mb-2">
                            <div class="excel-icon-square">X</div>
                            <div>
                                <h6 class="fw-bold mb-0 text-dark" style="font-size: 15px;">Import Excel</h6>
                                <span class="text-muted" style="font-size: 11.5px;">Format file: .xlsx</span>
                            </div>
                        </div>
                        <form id="formImport" enctype="multipart/form-data" method="post" action="proses_import_langsung.php" class="mt-2">
                            <input type="hidden" name="id_sekolah" value="<?= $id_sekolah ?>">
                            <label class="label-mini fw-bold text-dark mb-1">PILIH FILE EXCEL</label>
                            <div class="d-flex flex-wrap align-items-center gap-2">
                                <div class="excel-picker-box">
                                    <label for="file_excel" class="btn-choose-file-xl mb-0">
                                        <i class="bi bi-upload text-primary"></i> Choose File
                                    </label>
                                    <input type="file" name="file_excel" id="file_excel" class="d-none" accept=".xlsx" required>
                                    <span id="excelFileLabel" class="excel-chosen-text">No file chosen</span>
                                </div>
                                <button type="submit" name="import" class="btn-import-data-green">
                                    <i class="bi bi-upload"></i> Import Data
                                </button>
                            </div>
                        </form>
                    </div>

                    <div class="text-md-end align-self-center">
                        <span class="d-block text-dark fw-semibold small mb-2">Belum punya format template?</span>
                        <a href="<?= $current_page; ?>?download_template=1" class="btn-download-template-pill">
                            <i class="bi bi-download"></i> Download Template
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <?php
    // ================= MENGAMBIL DATA SUMBER PEROLEHAN & TAHUN UNTUK DROPDOWN FILTER =================
    $q_asal_perolehan = mysqli_query($conn, "SELECT DISTINCT asal_perolehan FROM data_barang WHERE id_sekolah='$id_sekolah' AND asal_perolehan != '' AND asal_perolehan IS NOT NULL ORDER BY asal_perolehan ASC");
    $q_tahun_perolehan = mysqli_query($conn, "SELECT DISTINCT tahun_perolehan FROM data_barang WHERE id_sekolah='$id_sekolah' AND tahun_perolehan != '' AND tahun_perolehan IS NOT NULL AND tahun_perolehan != '0' ORDER BY CAST(tahun_perolehan AS UNSIGNED) DESC");
    ?>

    <!-- ================= TABEL DAFTAR BARANG ================= -->
    <div class="table-card-container">
        <div class="table-banner-bar">
            <h5 class="table-banner-heading">
                <i class="bi bi-box-seam fs-4"></i>
                <span>Daftar Barang</span>
            </h5>
            <div class="d-flex flex-wrap align-items-center gap-2" style="position: relative; z-index: 2;">
                <!-- Filter Dropdown Tahun Perolehan -->
                <select id="filterTahun" class="form-select filter-pill-select" style="width: auto;">
                    <option value="">Semua Tahun</option>
                    <?php while($row_thn = mysqli_fetch_assoc($q_tahun_perolehan)): ?>
                        <option value="<?= htmlspecialchars($row_thn['tahun_perolehan']); ?>"><?= htmlspecialchars($row_thn['tahun_perolehan']); ?></option>
                    <?php endwhile; ?>
                </select>
                <!-- Filter Dropdown Sumber Perolehan -->
                <select id="filterAsal" class="form-select filter-pill-select" style="width: auto;">
                    <option value="">Semua Sumber Perolehan</option>
                    <?php while($row_asal = mysqli_fetch_assoc($q_asal_perolehan)): ?>
                        <option value="<?= htmlspecialchars($row_asal['asal_perolehan']); ?>"><?= htmlspecialchars($row_asal['asal_perolehan']); ?></option>
                    <?php endwhile; ?>
                </select>
                <!-- Search Live -->
                <div class="search-pill-wrapper">
                    <i class="bi bi-search"></i>
                    <input type="text" id="liveSearch" class="form-control search-pill-input" placeholder="Cari nama, kode, atau merk...">
                </div>
            </div>
        </div>

        <div class="table-responsive">
            <table class="table align-middle" id="tableBarang">
                <thead>
                    <tr> 
                        <th class="ps-3 text-center">No</th> 
                        <th class="text-center">No. Reg</th>
                        <th class="text-center">Foto Barang</th>
                        <th>Nama Barang / Merk</th>
                        <th class="text-center">Tgl Perolehan</th>
                        <th>Sumber Perolehan</th>
                        <th class="text-center">Vol</th>
                        <th>Harga Satuan</th>
                        <th class="text-center" style="min-width: 130px;">Kondisi</th>
                        <th class="text-center">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $no=1;
                    $sql_get_barang = "SELECT b.*, 
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
                                WHERE b.id_sekolah='$id_sekolah' 
                                ORDER BY CAST(b.tahun_perolehan AS UNSIGNED) ASC, 
                                         CAST(b.bulan_perolehan AS UNSIGNED) ASC, 
                                         CAST(b.tanggal_perolehan AS UNSIGNED) ASC, 
                                         b.id ASC";

                    $q = mysqli_query($conn, $sql_get_barang);
                    while($d=mysqli_fetch_assoc($q)){
                        $tgl_full = str_pad($d['tanggal_perolehan'] ?: '0', 2, '0', STR_PAD_LEFT) . '/' . str_pad($d['bulan_perolehan'] ?: '0', 2, '0', STR_PAD_LEFT) . '/' . ($d['tahun_perolehan'] ?: '-');
                        
                        $vol_barang = intval($d['volume']) ?: 1;
                        $baik = $d['total_baik'];
                        $rr   = $d['total_rr'];
                        $rb   = $d['total_rb'];
                    ?>
                    <tr id="row-<?= $d['id']; ?>" class="barang-row">
                        <td class="text-center fw-semibold text-secondary"><?= $no++; ?></td>
                        <td class="text-center col-reg">
                            <span class="badge-reg-pill"><?= $d['reg'] ?: '-'; ?></span>
                        </td>
                        <td class="text-center">
                            <div class="foto-cell-wrap">
                                <img id="img-render-<?= $d['id']; ?>" src="upload/<?= $d['foto'] ?: 'no-photo.png'; ?>" class="img-barang" onerror="this.src='https://placehold.co/100x80/f1f5f9/94a3b8?text=Foto'">
                                <label class="btn-pilih-foto-mini mb-0" title="Upload / Ganti Foto">
                                    <span>Pilih Foto</span>
                                    <i class="bi bi-chevron-down" style="font-size: 9px;"></i>
                                    <input type="file" class="d-none file-upload-langsung" data-id="<?= $d['id']; ?>" accept="image/*">
                                </label>
                            </div>
                        </td>
                        <td>
                            <div class="fw-bold text-dark col-nama"><?= $d['nama_jenis_barang']; ?></div>
                            <div class="text-muted small col-merek"><?= $d['merek_type'] ?: '-'; ?></div>
                            <div class="text-muted small col-kode" style="display:none;"><?= $d['kode_barang']; ?></div>
                        </td>
                        <td class="text-center small fw-semibold text-dark">
                            <?= $tgl_full; ?>
                            <span class="col-tahun" style="display:none;"><?= $d['tahun_perolehan']; ?></span>
                        </td>
                        <td class="small fw-semibold text-dark col-asal"><?= $d['asal_perolehan'] ?: '-'; ?></td>
                        <td class="text-center fw-bold text-dark small"><?= $d['volume'] . ' ' . $d['satuan']; ?></td>
                        <td class="small fw-bold text-primary col-harga"><?= formatRupiah($d['harga_satuan']); ?></td>
                        <td>
                            <div class="kondisi-stack mx-auto">
                                <div class="kondisi-pill-row kp-baik">
                                    <span><i class="bi bi-check-circle-fill me-1"></i> Baik</span>
                                    <span><?= $baik; ?></span>
                                </div>
                                <div class="kondisi-pill-row kp-rr">
                                    <span><i class="bi bi-exclamation-triangle-fill me-1"></i> RR</span>
                                    <span><?= $rr; ?></span>
                                </div>
                                <div class="kondisi-pill-row kp-rb">
                                    <span><i class="bi bi-x-circle-fill me-1"></i> RB</span>
                                    <span><?= $rb; ?></span>
                                </div>
                            </div>
                        </td>
                        <td class="text-center">
                            <div class="aksi-btn-group">
                                <button class="btn-aksi-box btn-aksi-kondisi btn-kondisi-detail" data-id="<?= $d['id']; ?>" title="Edit Detail Kondisi Unit">
                                    <i class="bi bi-eye-fill"></i>
                                </button>
                                <button class="btn-aksi-box btn-aksi-edit btn-edit" data-id="<?= $d['id']; ?>" title="Edit Barang">
                                    <i class="bi bi-pencil-square"></i>
                                </button>
                                <button class="btn-aksi-box btn-aksi-hapus btn-hapus" data-id="<?= $d['id']; ?>" title="Hapus Barang">
                                    <i class="bi bi-trash3-fill"></i>
                                </button>
                            </div>
                        </td>
                    </tr>
                    <?php } ?>
                    <tr id="noData" style="display:none;"><td colspan="10" class="text-center py-5"><p class="text-muted mb-0">Data barang tidak ditemukan...</p></td></tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- ================= MODAL INPUT DATA INVENTARIS MANUAL (UI BARU) ================= -->
<div class="modal fade modal-modern-inv" id="modalTambahManual" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
        <form id="formTambahManual" class="modal-content" enctype="multipart/form-data">
            <div class="modal-header modal-header-teal">
                <h5 class="modal-title"><i class="bi bi-box-seam fs-4"></i> INPUT DATA INVENTARIS MANUAL</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body modal-body-sky">
                <input type="hidden" name="action_ajax" value="tambah_data">
                <div class="row g-3">
                    <!-- KOLOM KIRI: PREVIEW FOTO & INFORMASI SISTEM -->
                    <div class="col-lg-3">
                        <div class="bento-card-modal">
                            <div class="bento-card-header">
                                <i class="bi bi-image"></i>
                                <span>Preview Foto</span>
                            </div>
                            <div class="bento-card-body">
                                <div class="photo-dashed-frame text-center">
                                    <img id="prev_foto_input" src="https://placehold.co/300x200/eef2f6/94a3b8?text=No+Photo" class="preview-img-modal">
                                </div>

                                <label class="field-label-icon">
                                    <i class="bi bi-cloud-arrow-up"></i>
                                    <span>Upload Foto Barang</span>
                                </label>
                                <div class="custom-file-pill-box">
                                    <label for="foto_input" class="btn-choose-photo-pill mb-0">
                                        <i class="bi bi-upload text-primary"></i> Choose File
                                    </label>
                                    <input type="file" name="foto_input" id="foto_input" class="d-none" accept="image/*">
                                    <span id="fotoInputFileName" class="chosen-photo-name">No file chosen</span>
                                </div>

                                <div class="sub-section-heading">
                                    <i class="bi bi-gear-fill"></i>
                                    <span>Informasi Sistem</span>
                                </div>

                                <div class="reg-mint-box">
                                    <label class="d-block text-success fw-bold mb-1" style="font-size: 11.5px;">No. Register (Otomatis)</label>
                                    <input type="text" id="preview_reg_manual" class="form-control reg-mint-input" value="-" readonly>
                                </div>

                                <div>
                                    <label class="field-label-icon">
                                        <i class="bi bi-person-vcard"></i>
                                        <span>No. Identitas</span>
                                    </label>
                                    <input type="text" name="no_identitas" class="form-control" placeholder="">
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- KOLOM TENGAH: DATA UTAMA -->
                    <div class="col-lg-5">
                        <div class="bento-card-modal">
                            <div class="bento-card-header">
                                <i class="bi bi-layers-fill"></i>
                                <span>Data Utama</span>
                            </div>
                            <div class="bento-card-body">
                                <div class="row g-2">
                                    <div class="col-12 mb-2 position-relative">
                                        <label class="field-label-icon">
                                            <i class="bi bi-tag-fill"></i>
                                            <span>Kode Barang / Cari Nama Barang</span>
                                        </label>
                                        <div class="input-search-icon-wrap">
                                            <i class="bi bi-search"></i>
                                            <input type="text" name="kode_barang" id="manual_kode_barang" class="form-control live-reg-trigger autocomplete-input" placeholder="Contoh: 1.02.01 atau ketik nama barang Laptop..." autocomplete="off" required>
                                        </div>
                                        <div class="autocomplete-suggestions" id="suggestions_tambah"></div>
                                    </div>

                                    <div class="col-12 mb-2">
                                        <label class="field-label-icon">
                                            <i class="bi bi-list-ul"></i>
                                            <span>Nama Jenis Barang (Otomatis dari Kode Barang)</span>
                                        </label>
                                        <input type="text" name="nama_jenis_barang" id="manual_nama_barang" class="form-control input-readonly-gray" readonly required placeholder="Terisi otomatis setelah Kode Aset dipilih">
                                    </div>

                                    <div class="col-md-6 mb-2">
                                        <label class="field-label-icon">
                                            <i class="bi bi-bookmark-fill"></i>
                                            <span>Merek / Type</span>
                                        </label>
                                        <input type="text" name="merek_type" class="form-control" placeholder="" required>
                                    </div>

                                    <div class="col-md-6 mb-2">
                                        <label class="field-label-icon">
                                            <i class="bi bi-stack"></i>
                                            <span>Bahan / Material</span>
                                        </label>
                                        <input type="text" name="bahan" class="form-control" placeholder="" required>
                                    </div>

                                    <div class="col-md-3 mb-2">
                                        <label class="field-label-icon">
                                            <i class="bi bi-box"></i>
                                            <span>Volume</span>
                                        </label>
                                        <input type="number" name="volume" id="manual_volume" class="form-control live-reg-trigger" min="1" value="1" placeholder="" required>
                                    </div>

                                    <div class="col-md-4 mb-2">
                                        <label class="field-label-icon">
                                            <i class="bi bi-grid-fill"></i>
                                            <span>Satuan</span>
                                        </label>
                                        <input type="text" name="satuan" class="form-control" placeholder="" required>
                                    </div>

                                    <div class="col-md-5 mb-2">
                                        <label class="field-label-icon">
                                            <i class="bi bi-activity"></i>
                                            <span>Keadaan Barang</span>
                                        </label>
                                        <select name="keadaan_barang" class="form-select fw-bold">
                                            <option value="B">B (Baik)</option>
                                            <option value="RR">RR (Rusak Ringan)</option>
                                            <option value="RB">RB (Rusak Berat)</option>
                                        </select>
                                    </div>

                                    <div class="col-md-7 mb-2">
                                        <label class="field-label-icon">
                                            <i class="bi bi-cash-coin"></i>
                                            <span>Harga Satuan (Rp)</span>
                                        </label>
                                        <input type="text" name="harga_satuan" id="input_harga_manual" class="form-control fw-bold text-primary" required placeholder="">
                                    </div>

                                    <div class="col-12">
                                        <label class="field-label-icon">
                                            <i class="bi bi-file-earmark-text"></i>
                                            <span>Keterangan</span>
                                        </label>
                                        <textarea name="keterangan" class="form-control" rows="3" placeholder=""></textarea>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- KOLOM KANAN: DATA PEROLEHAN -->
                    <div class="col-lg-4">
                        <div class="bento-card-modal">
                            <div class="bento-card-header">
                                <i class="bi bi-calendar-check"></i>
                                <span>Data Perolehan</span>
                            </div>
                            <div class="bento-card-body">
                                <div class="mb-3">
                                    <label class="field-label-icon">
                                        <i class="bi bi-bank2"></i>
                                        <span>Asal Usul Perolehan</span>
                                    </label>
                                    <input type="text" name="asal_perolehan" class="form-control" placeholder="">
                                </div>

                                <div>
                                    <label class="field-label-icon">
                                        <i class="bi bi-calendar-event"></i>
                                        <span>Tanggal Perolehan</span>
                                    </label>
                                    <input type="date" id="manual_tgl_full" class="form-control live-reg-trigger" value="<?= date('Y-m-d') ?>" required>
                                    <input type="hidden" name="tanggal_perolehan" id="manual_tgl" value="<?= date('d') ?>">
                                    <input type="hidden" name="bulan_perolehan" id="manual_bln" value="<?= date('m') ?>">
                                    <input type="hidden" name="tahun_perolehan" id="manual_thn" value="<?= date('Y') ?>">
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer modal-footer-modern">
                <button type="button" class="btn-modal-tutup" data-bs-dismiss="modal">
                    <i class="bi bi-x-lg"></i> Tutup
                </button>
                <button type="submit" id="btnSimpanManual" class="btn-modal-simpan-green">
                    <i class="bi bi-floppy-fill"></i> Simpan Barang
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ================= MODAL EDIT DATA INVENTARIS (UI BARU) ================= -->
<div class="modal fade modal-modern-inv" id="modalEdit" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
        <form id="formEdit" class="modal-content">
            <div class="modal-header modal-header-teal">
                <h5 class="modal-title"><i class="bi bi-pencil-square fs-5"></i> EDIT DATA INVENTARIS</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body modal-body-sky">
                <input type="hidden" name="id" id="edit_id">
                <input type="hidden" name="action_ajax" value="update_data">
                <div class="row g-3">
                    <!-- KOLOM KIRI: INFORMASI SISTEM -->
                    <div class="col-lg-3">
                        <div class="bento-card-modal">
                            <div class="bento-card-header">
                                <i class="bi bi-gear-fill"></i>
                                <span>Informasi Sistem</span>
                            </div>
                            <div class="bento-card-body">
                                <div class="reg-mint-box">
                                    <label class="d-block text-success fw-bold mb-1" style="font-size: 11.5px;">No. Register Saat Ini</label>
                                    <input type="text" id="view_reg" class="form-control reg-mint-input" readonly placeholder="Memuat register...">
                                </div>

                                <div>
                                    <label class="field-label-icon">
                                        <i class="bi bi-person-vcard"></i>
                                        <span>No. Identitas</span>
                                    </label>
                                    <input type="text" id="view_no_id" class="form-control input-readonly-gray" readonly placeholder="">
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- KOLOM TENGAH: DATA UTAMA -->
                    <div class="col-lg-5">
                        <div class="bento-card-modal">
                            <div class="bento-card-header">
                                <i class="bi bi-layers-fill"></i>
                                <span>Data Utama</span>
                            </div>
                            <div class="bento-card-body">
                                <div class="row g-2">
                                    <div class="col-12 mb-2 position-relative">
                                        <label class="field-label-icon">
                                            <i class="bi bi-tag-fill"></i>
                                            <span>Kode Barang / Cari Nama Barang</span>
                                        </label>
                                        <div class="input-search-icon-wrap">
                                            <i class="bi bi-search"></i>
                                            <input type="text" name="kode_barang" id="edit_kode" class="form-control autocomplete-input edit-live-reg-trigger" placeholder="Ketik kode baru atau nama barang baru untuk merubah..." autocomplete="off" required>
                                        </div>
                                        <div class="autocomplete-suggestions" id="suggestions_edit"></div>
                                    </div>

                                    <div class="col-12 mb-2">
                                        <label class="field-label-icon">
                                            <i class="bi bi-list-ul"></i>
                                            <span>Nama Jenis Barang (otomatis dari kode barang)</span>
                                        </label>
                                        <input type="text" name="nama_jenis_barang" id="edit_nama" class="form-control input-readonly-gray" readonly required placeholder="Otomatis berubah mengikuti kode barang diatas">
                                    </div>

                                    <div class="col-md-6 mb-2">
                                        <label class="field-label-icon">
                                            <i class="bi bi-bookmark-fill"></i>
                                            <span>Merek / Type</span>
                                        </label>
                                        <input type="text" name="merek_type" id="edit_merek" class="form-control" placeholder="" required>
                                    </div>

                                    <div class="col-md-6 mb-2">
                                        <label class="field-label-icon">
                                            <i class="bi bi-stack"></i>
                                            <span>Bahan / Material</span>
                                        </label>
                                        <input type="text" name="bahan" id="edit_bahan" class="form-control" placeholder="" required>
                                    </div>

                                    <div class="col-md-6 mb-2">
                                        <label class="field-label-icon">
                                            <i class="bi bi-box"></i>
                                            <span>Volume</span>
                                        </label>
                                        <input type="number" name="volume" id="edit_vol" class="form-control edit-live-reg-trigger" placeholder="" required>
                                    </div>

                                    <div class="col-md-6 mb-2">
                                        <label class="field-label-icon">
                                            <i class="bi bi-grid-fill"></i>
                                            <span>Satuan</span>
                                        </label>
                                        <input type="text" name="satuan" id="edit_satuan" class="form-control" placeholder="" required>
                                    </div>

                                    <div class="col-md-12 mb-2">
                                        <label class="field-label-icon">
                                            <i class="bi bi-cash-coin"></i>
                                            <span>Harga Satuan (Rp)</span>
                                        </label>
                                        <input type="text" name="harga_satuan" id="edit_harga" class="form-control fw-bold text-primary" placeholder="" required>
                                    </div>

                                    <div class="col-12">
                                        <label class="field-label-icon">
                                            <i class="bi bi-file-earmark-text"></i>
                                            <span>Keterangan</span>
                                        </label>
                                        <textarea name="keterangan" id="edit_keterangan" class="form-control" rows="3" placeholder=""></textarea>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- KOLOM KANAN: DATA PEROLEHAN (BISA DIUBAH) -->
                    <div class="col-lg-4">
                        <div class="bento-card-modal">
                            <div class="bento-card-header">
                                <i class="bi bi-calendar-check"></i>
                                <span>Data Perolehan (Bisa Diubah)</span>
                            </div>
                            <div class="bento-card-body">
                                <div class="mb-3">
                                    <label class="field-label-icon">
                                        <i class="bi bi-bank2"></i>
                                        <span>Asal Usul Perolehan</span>
                                    </label>
                                    <input type="text" name="asal_perolehan" id="edit_asal" class="form-control" placeholder="">
                                </div>

                                <div>
                                    <label class="field-label-icon">
                                        <i class="bi bi-calendar-event"></i>
                                        <span>Tanggal Perolehan</span>
                                    </label>
                                    <input type="date" id="edit_tgl_full" class="form-control edit-live-reg-trigger" required>
                                    <input type="hidden" name="tanggal_perolehan" id="edit_tgl">
                                    <input type="hidden" name="bulan_perolehan" id="edit_bln">
                                    <input type="hidden" name="tahun_perolehan" id="edit_thn">
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer modal-footer-modern">
                <button type="button" class="btn-modal-tutup" data-bs-dismiss="modal">
                    <i class="bi bi-x-lg"></i> Tutup
                </button>
                <button type="submit" id="btnSimpan" class="btn-modal-simpan-blue">
                    <i class="bi bi-floppy-fill"></i> Simpan Perubahan
                </button>
            </div>
        </form>
    </div>
</div>

<div class="modal fade" id="modalBerhasil" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content border-0 shadow-lg rounded-4 text-center">
            <div class="modal-body p-4">
                <div class="success-checkmark mb-3">
                    <div class="check-icon">
                        <span class="icon-line line-tip"></span>
                        <span class="icon-line line-long"></span>
                    </div>
                </div>
                <h5 class="fw-bold">Berhasil!</h5>
                <p class="text-secondary small mb-1" id="msgBerhasil">Data diproses.</p>
                <p class="text-muted" style="font-size: 0.75rem;">(Memuat ulang otomatis...)</p>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="modalHapus" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content border-0 shadow-lg rounded-4 text-center">
            <div class="modal-body p-4">
                <div class="mb-3 text-danger">
                    <i class="bi bi-exclamation-circle-fill" style="font-size: 4rem;"></i>
                </div>
                <h5 class="fw-bold text-dark">Hapus Data?</h5>
                <p class="text-secondary small mb-4">Data barang ini akan dihapus permanen dan tidak dapat dikembalikan.</p>
                <div class="d-flex justify-content-center gap-2">
                    <button type="button" class="btn btn-light btn-sm px-4 rounded-pill fw-bold" data-bs-dismiss="modal">Batal</button>
                    <button type="button" id="btnKonfirmasiHapus" class="btn btn-danger btn-sm px-4 rounded-pill fw-bold shadow-sm">Ya, Hapus</button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- MODAL KONFIRMASI HAPUS SEMUA DATA -->
<div class="modal fade" id="modalHapusSemua" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content border-0 shadow-lg rounded-4 text-center">
            <div class="modal-body p-4">
                <div class="mb-3 text-danger">
                    <i class="bi bi-exclamation-triangle-fill" style="font-size: 4rem;"></i>
                </div>
                <h5 class="fw-bold text-dark">Hapus SEMUA Data?</h5>
                <p class="text-secondary small mb-4">Seluruh data barang dan detail kondisi sekolah ini akan dihapus PERMANEN dan tidak dapat dikembalikan.</p>
                <div class="d-flex justify-content-center gap-2">
                    <button type="button" class="btn btn-light btn-sm px-4 rounded-pill fw-bold" data-bs-dismiss="modal">Batal</button>
                    <button type="button" id="btnKonfirmasiHapusSemua" class="btn btn-danger btn-sm px-4 rounded-pill fw-bold shadow-sm">Ya, Hapus Semua</button>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
const myModal = new bootstrap.Modal(document.getElementById('modalEdit'));
const mBerhasil = new bootstrap.Modal(document.getElementById('modalBerhasil'));
const mHapus = new bootstrap.Modal(document.getElementById('modalHapus'));
const mHapusSemua = new bootstrap.Modal(document.getElementById('modalHapusSemua'));
const inputHarga = document.getElementById('edit_harga');
const inputHargaManual = document.getElementById('input_harga_manual');
let idHapus = null;

// Tampilkan nama file Excel saat dipilih
const inputExcelFile = document.getElementById('file_excel');
const excelFileLabel = document.getElementById('excelFileLabel');
if (inputExcelFile) {
    inputExcelFile.addEventListener('change', function() {
        excelFileLabel.textContent = this.files[0] ? this.files[0].name : 'No file chosen';
    });
}

// FUNGSI MENAMPILKAN MODAL SUKSES 2 DETIK
function showSuccessModal(msg) {
    document.getElementById('msgBerhasil').innerText = msg;
    mBerhasil.show();
    setTimeout(() => {
        mBerhasil.hide();
        location.reload(); 
    }, 2000);
}

// ================= LIVE AUTO-CALCULATE REGISTER PREVIEW (TAMBAH MANUAL) =================
function fetchLivePreviewRegister() {
    const kode = document.getElementById('manual_kode_barang').value.trim();
    const vol = document.getElementById('manual_volume').value;
    let tgl = '01', bln = '01', thn = '0';
    
    // Parse Kalender Data ke hidden value logic untuk realtime preview
    const dateVal = document.getElementById('manual_tgl_full').value.split('-');
    if(dateVal.length === 3) {
        thn = dateVal[0]; bln = parseInt(dateVal[1]); tgl = parseInt(dateVal[2]);
    }

    if(kode === "" || thn === "0" || thn === "") { document.getElementById('preview_reg_manual').value = "-"; return; }

    const fd = new FormData();
    fd.append('action_ajax', 'get_next_register');
    fd.append('kode_barang', kode); fd.append('volume', vol);
    fd.append('tanggal_perolehan', tgl); fd.append('bulan_perolehan', bln); fd.append('tahun_perolehan', thn);

    fetch('<?= $current_page; ?>', { method: 'POST', body: fd })
    .then(res => res.json()).then(data => { if(data.status === 'success') document.getElementById('preview_reg_manual').value = data.preview_reg; })
    .catch(() => { document.getElementById('preview_reg_manual').value = "-"; });
}

document.querySelectorAll('.live-reg-trigger').forEach(input => {
    input.addEventListener('input', fetchLivePreviewRegister);
    input.addEventListener('change', fetchLivePreviewRegister);
});

// ================= LIVE AUTO-CALCULATE REGISTER PREVIEW (EDIT DATA) =================
function fetchLivePreviewRegisterEdit() {
    const kode = document.getElementById('edit_kode').value.trim();
    const vol = document.getElementById('edit_vol').value;
    let tgl = '01', bln = '01', thn = '0';
    
    const dateVal = document.getElementById('edit_tgl_full').value.split('-');
    if(dateVal.length === 3) {
        thn = dateVal[0]; bln = parseInt(dateVal[1]); tgl = parseInt(dateVal[2]);
    }

    if(kode === "" || thn === "0" || thn === "") return;

    const fd = new FormData();
    fd.append('action_ajax', 'get_next_register');
    fd.append('kode_barang', kode); fd.append('volume', vol);
    fd.append('tanggal_perolehan', tgl); fd.append('bulan_perolehan', bln); fd.append('tahun_perolehan', thn);

    fetch('<?= $current_page; ?>', { method: 'POST', body: fd })
    .then(res => res.json()).then(data => { 
        if(data.status === 'success') document.getElementById('view_reg').value = data.preview_reg; 
    }).catch(() => { });
}

document.querySelectorAll('.edit-live-reg-trigger').forEach(input => {
    input.addEventListener('input', fetchLivePreviewRegisterEdit);
    input.addEventListener('change', fetchLivePreviewRegisterEdit);
});

// ================= LOADING SAAT PREVIEW IMPORT =================
document.getElementById('formImport').addEventListener('submit', function() {
    document.getElementById('loadingOverlay').style.display = 'flex';
});

// ================= AUTOFILL DAN AUTOCOMPLETE SEARCH KODE_BARANG =================
function initAutocomplete(inputId, suggestionsId, namaBarangInputId, callbackOnSelect) {
    const input = document.getElementById(inputId);
    const box = document.getElementById(suggestionsId);
    const targetNama = document.getElementById(namaBarangInputId);

    input.addEventListener('input', function() {
        const val = this.value.trim();
        if(val.length < 1) { box.style.display = 'none'; return; }

        const fd = new FormData();
        fd.append('action_ajax', 'search_referensi_kode');
        fd.append('keyword', val);

        fetch('<?= $current_page; ?>', { method: 'POST', body: fd })
        .then(res => res.json())
        .then(data => {
            if(data.status === 'success' && data.results.length > 0) {
                box.innerHTML = '';
                data.results.forEach(item => {
                    const div = document.createElement('div');
                    div.className = 'autocomplete-suggestion';
                    div.innerHTML = `<strong>${item.kode_barang}</strong> - ${item.uraian}`;
                    div.addEventListener('click', function() {
                        input.value = item.kode_barang;
                        targetNama.value = item.uraian;
                        box.style.display = 'none';
                        if(callbackOnSelect) callbackOnSelect();
                    });
                    box.appendChild(div);
                });
                box.style.display = 'block';
            } else { box.style.display = 'none'; }
        });
    });

    input.addEventListener('change', function() {
        setTimeout(() => {
            const val = this.value.trim();
            if(val === "") return;
            const fd = new FormData();
            fd.append('action_ajax', 'get_uraian_by_kode');
            fd.append('kode_barang', val);
            fetch('<?= $current_page; ?>', { method: 'POST', body: fd })
            .then(res => res.json()).then(data => {
                if(data.status === 'success') { targetNama.value = data.uraian; if(callbackOnSelect) callbackOnSelect(); }
            });
        }, 300);
    });

    document.addEventListener('click', function(e) { if(e.target !== input) box.style.display = 'none'; });
}

initAutocomplete('manual_kode_barang', 'suggestions_tambah', 'manual_nama_barang', fetchLivePreviewRegister);
initAutocomplete('edit_kode', 'suggestions_edit', 'edit_nama', fetchLivePreviewRegisterEdit);

// ================= ACTION UTAMA LAINNYA =================
document.addEventListener('change', function(e) {
    if(e.target.classList.contains('file-upload-langsung')) {
        const fileInput = e.target; const idBarang = fileInput.dataset.id; const file = fileInput.files[0]; if(!file) return;
        const fd = new FormData(); fd.append('id', idBarang); fd.append('action_ajax', 'upload_foto_langsung'); fd.append('foto_langsung', file);
        document.getElementById('loadingOverlay').style.display = 'flex';
        fetch('<?= $current_page; ?>', { method: 'POST', body: fd }).then(res => res.json()).then(data => {
            document.getElementById('loadingOverlay').style.display = 'none';
            if(data.status === 'success') {
                document.getElementById('img-render-' + idBarang).src = 'upload/' + data.nama_file + '?v=' + new Date().getTime();
                alert('Foto berhasil diupload!'); fileInput.value = '';
            } else { alert(data.message); }
        }).catch(() => { document.getElementById('loadingOverlay').style.display = 'none'; });
    }
});

// LOGIKA FILTER GABUNGAN (PENCARIAN TEKS + DROPDOWN SUMBER PEROLEHAN + DROPDOWN TAHUN)
function filterTableBarang() {
    const filterText = document.getElementById('liveSearch').value.toLowerCase();
    const filterAsal = document.getElementById('filterAsal').value.toLowerCase();
    const filterTahun = document.getElementById('filterTahun').value.toLowerCase();
    const rows = document.querySelectorAll('.barang-row'); 
    let visibleCount = 0;
    
    rows.forEach(row => {
        const nama = row.querySelector('.col-nama').textContent.toLowerCase();
        const kode = row.querySelector('.col-kode').textContent.toLowerCase();
        const merek = row.querySelector('.col-merek').textContent.toLowerCase();
        const asal = row.querySelector('.col-asal').textContent.toLowerCase();
        const tahunEl = row.querySelector('.col-tahun');
        const tahun = tahunEl ? tahunEl.textContent.trim().toLowerCase() : '';
        
        const matchesText = nama.includes(filterText) || kode.includes(filterText) || merek.includes(filterText);
        const matchesAsal = (filterAsal === "") || (asal === filterAsal) || (asal === '-' && filterAsal === "");
        const matchesTahun = (filterTahun === "") || (tahun === filterTahun) || ((tahun === '-' || tahun === '0') && filterTahun === "");
        
        if (matchesText && matchesAsal && matchesTahun) { 
            row.style.display = ""; 
            visibleCount++; 
        } else { 
            row.style.display = "none"; 
        }
    });
    document.getElementById('noData').style.display = (visibleCount === 0) ? "" : "none";
}

document.getElementById('liveSearch').addEventListener('keyup', filterTableBarang);
document.getElementById('filterAsal').addEventListener('change', filterTableBarang);
document.getElementById('filterTahun').addEventListener('change', filterTableBarang);

document.getElementById('foto_input').addEventListener('change', function() {
    const file = this.files[0];
    const lblFoto = document.getElementById('fotoInputFileName');
    if (lblFoto) lblFoto.textContent = file ? file.name : 'No file chosen';
    if (file) { let reader = new FileReader(); reader.onload = function(e) { document.getElementById('prev_foto_input').src = e.target.result; }; reader.readAsDataURL(file); }
});

inputHarga.addEventListener('input', function() { let raw = this.value.replace(/[^0-9]/g, ''); this.value = raw ? new Intl.NumberFormat('id-ID').format(raw) : ''; });
inputHargaManual.addEventListener('input', function() { let raw = this.value.replace(/[^0-9]/g, ''); this.value = raw ? new Intl.NumberFormat('id-ID').format(raw) : ''; });

document.addEventListener('click', function(e) {
    if(e.target.closest('.btn-edit')) {
        const id = e.target.closest('.btn-edit').dataset.id; const fd = new FormData(); fd.append('id', id); fd.append('action_ajax', 'get_data');
        fetch('<?= $current_page; ?>', { method: 'POST', body: fd }).then(res => res.json()).then(data => {
            document.getElementById('edit_id').value = data.id; document.getElementById('edit_nama').value = data.nama_jenis_barang;
            document.getElementById('edit_kode').value = data.kode_barang; document.getElementById('edit_merek').value = data.merek_type;
            document.getElementById('edit_bahan').value = data.bahan; document.getElementById('edit_vol').value = data.volume;
            document.getElementById('edit_satuan').value = data.satuan;
            document.getElementById('edit_keterangan').value = data.keterangan; inputHarga.value = new Intl.NumberFormat('id-ID').format(data.harga_satuan);
            document.getElementById('view_reg').value = data.reg; document.getElementById('view_no_id').value = data.no_identitas;
            
            document.getElementById('edit_asal').value = data.asal_perolehan; 
            
            // Logika sinkronisasi DatePicker ke Hidden Input agar logic PHP tidak perlu berubah
            let y = data.tahun_perolehan;
            let m = data.bulan_perolehan ? data.bulan_perolehan.toString().padStart(2, '0') : '01';
            let d = data.tanggal_perolehan ? data.tanggal_perolehan.toString().padStart(2, '0') : '01';
            if(y && y !== '0' && y !== '') {
                document.getElementById('edit_tgl_full').value = `${y}-${m}-${d}`;
            } else {
                document.getElementById('edit_tgl_full').value = '';
            }
            
            document.getElementById('edit_tgl').value = data.tanggal_perolehan;
            document.getElementById('edit_bln').value = data.bulan_perolehan; 
            document.getElementById('edit_thn').value = data.tahun_perolehan;

            myModal.show();
        });
    }
});

document.getElementById('formEdit').addEventListener('submit', function(e) {
    e.preventDefault(); const btn = document.getElementById('btnSimpan'); btn.disabled = true; btn.innerHTML = 'Menyimpan...';
    
    // Parse value dari input date (Kalender) menjadi ke form data yang ditangkap PHP
    let dateVal = document.getElementById('edit_tgl_full').value.split('-');
    if(dateVal.length === 3) {
        document.getElementById('edit_thn').value = dateVal[0];
        document.getElementById('edit_bln').value = parseInt(dateVal[1]);
        document.getElementById('edit_tgl').value = parseInt(dateVal[2]);
    }

    const fd = new FormData(this); let cleanHarga = inputHarga.value.replace(/[^0-9]/g, ''); fd.set('harga_satuan', cleanHarga);
    fetch('<?= $current_page; ?>', { method: 'POST', body: fd }).then(res => res.json()).then(data => { 
        if(data.status === 'success') { 
            myModal.hide(); 
            showSuccessModal('Data Berhasil Diperbarui!'); 
        } 
    }).finally(() => { btn.disabled = false; btn.innerHTML = 'Simpan Perubahan'; });
});

document.getElementById('formTambahManual').addEventListener('submit', function(e) {
    e.preventDefault(); const btn = document.getElementById('btnSimpanManual'); btn.disabled = true; btn.innerHTML = 'Menyimpan...';

    // Parse value dari input date (Kalender) menjadi ke hidden input untuk ditangkap PHP
    let dateVal = document.getElementById('manual_tgl_full').value.split('-');
    if(dateVal.length === 3) {
        document.getElementById('manual_thn').value = dateVal[0];
        document.getElementById('manual_bln').value = parseInt(dateVal[1]);
        document.getElementById('manual_tgl').value = parseInt(dateVal[2]);
    }

    const fd = new FormData(this); let cleanHarga = inputHargaManual.value.replace(/[^0-9]/g, ''); fd.set('harga_satuan', cleanHarga);
    fetch('<?= $current_page; ?>', { method: 'POST', body: fd }).then(res => res.json()).then(data => { 
        if(data.status === 'success') { 
            bootstrap.Modal.getInstance(document.getElementById('modalTambahManual')).hide();
            showSuccessModal('Data Barang Berhasil Ditambahkan!'); 
        } else { alert(data.message); } 
    }).finally(() => { btn.disabled = false; btn.innerHTML = 'Simpan Barang'; });
});

// FUNGSI TRIGGER HAPUS PER BARANG
document.addEventListener('click', function(e) {
    if(e.target.closest('.btn-hapus')) {
        idHapus = e.target.closest('.btn-hapus').dataset.id;
        mHapus.show();
    }
});

// KONFIRMASI HAPUS PER BARANG
document.getElementById('btnKonfirmasiHapus').addEventListener('click', function() {
    if(idHapus) {
        mHapus.hide();
        document.getElementById('loadingOverlay').style.display = 'flex';
        const fd = new FormData(); fd.append('id', idHapus); fd.append('action_ajax', 'hapus');
        fetch('<?= $current_page; ?>', { method: 'POST', body: fd }).then(res => res.json()).then(data => { 
            document.getElementById('loadingOverlay').style.display = 'none';
            if(data.status === 'success') showSuccessModal('Data barang berhasil dihapus!'); 
        });
    }
});

// FUNGSI TRIGGER & KONFIRMASI HAPUS SEMUA DATA
document.getElementById('btnTriggerHapusSemua').addEventListener('click', function() {
    mHapusSemua.show();
});

document.getElementById('btnKonfirmasiHapusSemua').addEventListener('click', function() {
    mHapusSemua.hide();
    document.getElementById('loadingOverlay').style.display = 'flex';
    const fd = new FormData();
    fd.append('action_ajax', 'hapus_semua_data');
    fetch('<?= $current_page; ?>', { method: 'POST', body: fd })
    .then(res => res.json())
    .then(data => {
        document.getElementById('loadingOverlay').style.display = 'none';
        if(data.status === 'success') {
            showSuccessModal('Seluruh data barang berhasil dihapus!');
        } else {
            alert('Gagal menghapus semua data!');
        }
    }).catch(() => {
        document.getElementById('loadingOverlay').style.display = 'none';
        alert('Terjadi kesalahan sistem.');
    });
});
</script>

<?php include 'edit_kondisi_unit.php'; ?>

</body>
</html>