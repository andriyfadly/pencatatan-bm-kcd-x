<?php
date_default_timezone_set("Asia/Jakarta");

$conn = mysqli_connect("localhost","root","","db_inventaris");
if(!$conn){ die("Koneksi gagal"); }

require 'vendor/autoload.php';
use PhpOffice\PhpSpreadsheet\IOFactory;

/* ================= IMPORT EXCEL ================= */
if(isset($_POST['import'])){
    $file = $_FILES['file_excel']['tmp_name'];
    $spreadsheet = IOFactory::load($file);
    $sheet = $spreadsheet->getActiveSheet()->toArray();

    foreach($sheet as $key => $row){
        if($key == 0) continue;

        $no     = mysqli_real_escape_string($conn,$row[0]);
        $nama   = mysqli_real_escape_string($conn,$row[1]);
        $kota   = mysqli_real_escape_string($conn,$row[2]);
        $kode   = mysqli_real_escape_string($conn,$row[3]);

        mysqli_query($conn,"INSERT INTO kode_sekolah
            (no_urut,nama_sekolah,kota_kab,kode_sub_pengguna)
            VALUES('$no','$nama','$kota','$kode')");
    }

    echo "<script>alert('Import berhasil');window.location='kode_sekolah.php';</script>";
}

/* ================= SEARCH ================= */
$cari = "";
if(isset($_GET['cari'])){
    $cari = mysqli_real_escape_string($conn,$_GET['cari']);
    $query = "SELECT * FROM kode_sekolah 
              WHERE nama_sekolah LIKE '%$cari%' 
              OR kode_sub_pengguna LIKE '%$cari%'
              ORDER BY kode_sub_pengguna ASC";
}else{
    $query = "SELECT * FROM kode_sekolah ORDER BY kode_sub_pengguna ASC";
}

$data = mysqli_query($conn,$query);

$total_data = mysqli_num_rows($data);
$total_all  = mysqli_num_rows(mysqli_query($conn,"SELECT * FROM kode_sekolah"));
$total_kota = mysqli_num_rows(mysqli_query($conn,"SELECT DISTINCT kota_kab FROM kode_sekolah"));
$last_input = mysqli_fetch_assoc(mysqli_query($conn,"SELECT nama_sekolah FROM kode_sekolah ORDER BY id DESC LIMIT 1"));
$waktu_akses = date("d F Y H:i:s");
?>

<!DOCTYPE html>
<html>
<head>
<title>Data Kode Sekolah</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
body{
    background:#dbeafe;
}
.card{
    border-radius:20px;
}
.header-title{
    font-weight:bold;
    color:#0d6efd;
}
.info-box{
    background:#ffffff;
    padding:12px 15px;
    border-radius:12px;
    font-size:14px;
    box-shadow:0 2px 8px rgba(0,0,0,0.05);
}
.table thead{
    background:#0d6efd;
    color:white;
}
.badge-blue{
    background:#0d6efd;
    font-size:13px;
}
.footer-info{
    font-size:13px;
    color:#555;
}
</style>
</head>
<body>

<div class="container mt-5 mb-5">
<div class="card shadow p-4">

<!-- Tombol Kembali -->
<div class="mb-3">
    <a href="dashboard.html" class="btn btn-outline-primary">
        ⬅ Kembali ke Dashboard
    </a>
</div>

<h3 class="mb-4 header-title">📚 DATA KODE SEKOLAH (SUB PENGGUNA BARANG)</h3>

<!-- INFORMASI -->
<div class="row mb-4">
    <div class="col-md-3">
        <div class="info-box">
            Total Sekolah<br>
            <b><?= $total_all; ?></b>
        </div>
    </div>
    <div class="col-md-3">
        <div class="info-box">
            Total Ditampilkan<br>
            <b><?= $total_data; ?></b>
        </div>
    </div>
    <div class="col-md-3">
        <div class="info-box">
            Total Kota/Kab<br>
            <b><?= $total_kota; ?></b>
        </div>
    </div>
    <div class="col-md-3">
        <div class="info-box">
            Sekolah Terakhir<br>
            <b><?= $last_input['nama_sekolah'] ?? '-'; ?></b>
        </div>
    </div>
</div>

<!-- DOWNLOAD TEMPLATE -->
<a href="template_kode_sekolah.xlsx" class="btn btn-success mb-3">
📥 Download Template Excel
</a>

<!-- FORM IMPORT -->
<form method="POST" enctype="multipart/form-data" class="row g-2 mb-4">
    <div class="col-md-6">
        <input type="file" name="file_excel" class="form-control" required>
    </div>
    <div class="col-md-3">
        <button type="submit" name="import" class="btn btn-primary w-100">
            ⬆ Import Excel
        </button>
    </div>
</form>

<!-- SEARCH AUTO -->
<form method="GET" id="formCari" class="row g-2 mb-4">
    <div class="col-md-9">
        <input type="text" name="cari" id="inputCari"
        value="<?= $cari ?>"
        class="form-control"
        placeholder="Ketik untuk mencari Nama Sekolah / Kode Sub Pengguna...">
    </div>
    <div class="col-md-3">
        <a href="kode_sekolah.php" class="btn btn-secondary w-100">Reset</a>
    </div>
</form>

<!-- TABEL -->
<div class="table-responsive">
<table class="table table-bordered table-hover">
<thead>
<tr>
<th width="5%">NO</th>
<th>NAMA SEKOLAH</th>
<th>KOTA / KAB</th>
<th>KODE SUB PENGGUNA</th>
<th>KODE WILAYAH</th>
</tr>
</thead>
<tbody>

<?php 
$no = 1;
while($row=mysqli_fetch_assoc($data)){ 
?>

<tr>
<td><?= $no++; ?></td>
<td><?= $row['nama_sekolah']; ?></td>
<td><?= $row['kota_kab']; ?></td>
<td>
<span class="badge badge-blue"><?= $row['kode_sub_pengguna']; ?></span>
</td>
<td>
<span class="badge badge-blue"><?= $row['kode_wilayah']; ?></span>
</td>
</tr>

<?php } ?>

<?php if($total_data == 0){ ?>
<tr>
<td colspan="5" class="text-center text-danger">
Data tidak ditemukan
</td>
</tr>
<?php } ?>

</tbody>
</table>
</div>

<hr>

<!-- Tombol Kembali Bawah -->
<div class="mb-3">
    <a href="dashboard.html" class="btn btn-outline-primary">
        ⬅ Kembali ke Dashboard
    </a>
</div>

<div class="footer-info text-end">
Diakses pada : <?= $waktu_akses; ?>
</div>

</div>
</div>

<!-- AUTO SEARCH SCRIPT -->
<script>
let timeout = null;
document.getElementById("inputCari").addEventListener("keyup", function () {
    clearTimeout(timeout);
    timeout = setTimeout(function () {
        document.getElementById("formCari").submit();
    }, 500);
});
</script>

</body>
</html>