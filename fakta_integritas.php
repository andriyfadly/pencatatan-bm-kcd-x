<?php
include "koneksi.php";

$q = mysqli_query($conn,"SELECT * FROM penyaluran ORDER BY id_penyaluran DESC");
$dataBarang = [];
while($row = mysqli_fetch_assoc($q)){
    $dataBarang[] = $row;
}

$show = false;

if(isset($_POST['generate'])){

    $show = true;

    $nomor_surat = $_POST['nomor_surat'];
    $id          = $_POST['id_penyaluran'];

    $barang  = mysqli_query($conn,"SELECT * FROM penyaluran WHERE id_penyaluran='$id'");
    $barang  = mysqli_fetch_assoc($barang);

    $pegawai = mysqli_query($conn,"SELECT * FROM pegawai WHERE nama_lengkap='".$barang['nama_penerima']."'");
    $pegawai = mysqli_fetch_assoc($pegawai);
}
?>

<!DOCTYPE html>
<html>
<head>
<title>Fakta Integritas</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">

<style>
body{background:#eef5ff;}
.card{border-radius:15px;}
.header-biru{
    background:linear-gradient(90deg,#0d6efd,#003b8f);
    color:white;
}

.surat-area{
    background:white;
    padding:55px 75px;
    margin-top:20px;
    font-family:"Times New Roman", serif;
    font-size:15px;
    line-height:1.4;
}

/* Judul Tengah */
.judul-surat{
    text-align:center;
    font-weight:bold;
}

.table-indent{
    margin-left:50px;
}
.table-indent td{
    padding:2px 0;
    vertical-align:top;
}

.table-barang{
    margin-left:70px;
}
.table-barang td{
    padding:2px 0;
    vertical-align:top;
}

.ttd{
    margin-top:60px;
}

.ttd-space{
    height:80px;
}

@media print{
    .no-print{display:none;}
    body{background:white;}
}
</style>
</head>
<body>

<div class="container py-4">

<div class="card shadow no-print">
<div class="card-header header-biru">
<h5 class="mb-0">Generate Fakta Integritas</h5>
</div>
<div class="card-body">

<form method="POST">

<div class="mb-3">
<label>Nomor Surat</label>
<input type="text" name="nomor_surat" class="form-control" required>
</div>

<div class="mb-3">
<label>Pilih Barang</label>
<select name="id_penyaluran" id="barangSelect" class="form-control" required>
<option value="">-- Pilih Barang --</option>
<?php foreach($dataBarang as $d): ?>
<option value="<?= $d['id_penyaluran'] ?>"
data-nama="<?= $d['nama_penerima'] ?>">
<?= $d['nama_barang'] ?> | Reg: <?= $d['no_reg'] ?>
</option>
<?php endforeach; ?>
</select>
</div>

<div class="mb-3">
<label>Nama Penerima</label>
<input type="text" id="namaPenerima" class="form-control" readonly>
</div>

<button type="submit" name="generate" class="btn btn-primary w-100">
Generate Fakta Integritas
</button>

</form>
</div>
</div>

<?php if($show): ?>

<div class="surat-area shadow">

<div class="judul-surat">
PAKTA INTEGRITAS<br>
PEMANFAATAN INVENTARIS BARANG MILIK DAERAH (BMD)<br>
Nomor: <?= $nomor_surat ?> /AR.03.05-CADISDIK.WIL-X
</div>

<br><br>

Saya yang bertandatangan dibawah ini:

<br><br>

<table class="table-indent">
<tr>
<td width="170">Nama</td>
<td width="15">:</td>
<td><?= $pegawai['nama_lengkap'] ?></td>
</tr>
<tr>
<td>NIP</td>
<td>:</td>
<td><?= $pegawai['nip'] ?></td>
</tr>
<tr>
<td>Pangkat/Gol.</td>
<td>:</td>
<td><?= $pegawai['pangkat_golongan'] ?></td>
</tr>
<tr>
<td>Sub. Bidang</td>
<td>:</td>
<td><?= $pegawai['jabatan'] ?></td>
</tr>
</table>

<br>

Dengan ini menyatakan bahwa saya:

<br><br>

1. Akan bertanggungjawab atas Inventaris Barang Milik Daerah (BMD) dengan seluruh resiko yang melekat terhadap Barang Inventaris tersebut, dengan rincian spesifikasi sebagai berikut:

<br><br>

<table class="table-barang">
<tr>
<td width="190">Nama Barang</td>
<td width="15">:</td>
<td><?= $barang['nama_barang'] ?></td>
</tr>
<tr>
<td>Merk/Spesifikasi</td>
<td>:</td>
<td><?= $barang['merk_tipe'] ?></td>
</tr>
<tr>
<td>Tahun Pengadaan</td>
<td>:</td>
<td><?= $barang['thn_pengadaan'] ?></td>
</tr>
<tr>
<td>Register</td>
<td>:</td>
<td><?= $barang['no_reg'] ?></td>
</tr>
<tr>
<td>Kondisi Barang</td>
<td>:</td>
<td>Baik</td>
</tr>
</table>

<br>

2. Akan memanfaatkan Inventaris BMD sesuai Tugas, Pokok dan Fungsi Jabatan yang melekat;<br>
3. Akan menjaga dan memelihara Inventaris BMD sesuai umur ekonomis BMD tersebut;<br>
4. Akan segera mengembalikan Inventaris BMD setelah jangka waktu penggunaan berakhir atau terjadi mutasi, yang dituangkan dalam Berita Acara Penyerahan kembali;<br>
5. Apabila Inventaris hilang sebagai akibat dari kesalahan atau kelalaian atau penyimpangan dari ketentuan, maka saya sebagai penanggung jawab Inventaris BMD siap dikenakan sanksi sesuai dengan ketentuan peraturan perundang-undangan.

<br><br>

Demikian Pakta Integritas Pemanfaatan Barang Milik Daerah (BMD) ini dibuat dalam rangkap 2 (dua) untuk dipergunakan sebagaimana mestinya.

<div class="ttd">

<table width="100%">
<tr>
<td width="50%" align="center">
Pengurus Barang Pembantu<br>
Cabang Dinas Pendidikan Wil. X
<div class="ttd-space"></div>
<b>PEPEN APANDI</b><br>
NIP. 19800101 200701 1 012
</td>

<td width="50%" align="center">
Penanggungjawab Inventaris<br>
Barang Milik Daerah (BMD)
<div class="ttd-space"></div>
<b><?= $pegawai['nama_lengkap'] ?></b><br>
NIP. <?= $pegawai['nip'] ?>
</td>
</tr>
</table>

<br><br>

<center>
Mengetahui,<br>
Kepala Sub. Bagian Tata Usaha<br>
Cabang Dinas Pendidikan Wilayah X
<div class="ttd-space"></div>
<b>ABDUL FATAH, S.Pd., M.Pd</b><br>
NIP. 19690614 199702 1 003
</center>

</div>

<br>

<div class="text-center no-print">
<a href="fakta_pdf.php?id=<?= $id ?>&nomor=<?= $nomor_surat ?>" 
class="btn btn-danger" target="_blank">
Download PDF
</a>
</div>

</div>

<?php endif; ?>

</div>

<script>
document.getElementById("barangSelect").addEventListener("change", function(){
    var nama = this.options[this.selectedIndex].getAttribute("data-nama");
    document.getElementById("namaPenerima").value = nama ? nama : "";
});
</script>

</body>
</html>