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
    $tanggal = $_POST['tanggal'];
    $bulan   = $_POST['bulan'];
    $tahun   = $_POST['tahun'];
    $id = $_POST['id_penyaluran'];

    $barang = mysqli_query($conn,"SELECT * FROM penyaluran WHERE id_penyaluran='$id'");
    $barang = mysqli_fetch_assoc($barang);

    $pegawai = mysqli_query($conn,"SELECT * FROM pegawai WHERE nama_lengkap='".$barang['nama_penerima']."'");
    $pegawai = mysqli_fetch_assoc($pegawai);
}
?>

<!DOCTYPE html>
<html>
<head>
<title>BAST Inventaris</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">

<style>
body{background:#e7f1ff;}
.card{border-radius:15px;}
.header-biru{
    background:linear-gradient(90deg,#0d6efd,#003b8f);
    color:white;
}
.bast-area{
    background:white;
    padding:80px;
    margin-top:30px;
    font-family:"Times New Roman", serif;
    font-size:16px;
    line-height:1.6;
}
.table-surat{
    width:100%;
}
.table-surat td{
    padding:2px 0;
    vertical-align:top;
}
.bold{font-weight:bold;}
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
<h5 class="mb-0">Generate BAST</h5>
</div>
<div class="card-body">

<form method="POST">

<div class="mb-3">
<label>Nomor Surat</label>
<input type="text" name="nomor_surat" class="form-control" required>
</div>

<div class="mb-3">
<label>Tanggal (Teks Angka)</label>
<input type="text" name="tanggal" class="form-control"
placeholder="Contoh: Dua Puluh Lima" required>
</div>

<div class="mb-3">
<label>Bulan (Teks)</label>
<input type="text" name="bulan" class="form-control"
placeholder="Contoh: Februari" required>
</div>

<div class="mb-3">
<label>Tahun (Teks Belakang)</label>
<input type="text" name="tahun" class="form-control"
placeholder="Contoh: Enam" required>
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
Generate BAST
</button>

</form>
</div>
</div>

<?php if($show): ?>

<div class="bast-area shadow">

<center>
<b>BERITA ACARA SERAH TERIMA (BAST)<br>
PENYALURAN BARANG INVENTARIS</b><br>
Nomor: <?= $nomor_surat ?> /AR.03.05-CADISDIK.WIL-X
</center>

<br><br>

Pada hari ini tanggal <?= $tanggal ?> bulan <?= $bulan ?> tahun Dua Ribu Dua Puluh <?= $tahun ?> bertempat di Cabang Dinas Pendidikan Wilayah X, yang bertandatangan dibawah ini

<br><br>

<table class="table-surat">
<tr>
<td width="30">1.</td>
<td width="200">Nama</td>
<td width="20">:</td>
<td class="bold">PEPEN APANDI</td>
</tr>
<tr>
<td></td>
<td>NIP</td>
<td>:</td>
<td>19800101 200701 1 012</td>
</tr>
<tr>
<td></td>
<td>Pangkat/Gol.</td>
<td>:</td>
<td>Penata Muda Tk. I, III/a</td>
</tr>
</table>

<br>

Dalam hal ini bertindak sebagai Pengurus Barang Pembantu dan selanjutnya disebut sebagai <span class="bold">PIHAK KESATU</span>

<br><br>

<table class="table-surat">
<tr>
<td width="30">2.</td>
<td width="200">Nama</td>
<td width="20">:</td>
<td class="bold"><?= $pegawai['nama_lengkap'] ?></td>
</tr>
<tr>
<td></td>
<td>NIP</td>
<td>:</td>
<td><?= $pegawai['nip'] ?></td>
</tr>
<tr>
<td></td>
<td>Pangkat/Gol.</td>
<td>:</td>
<td><?= $pegawai['pangkat_golongan'] ?></td>
</tr>
<tr>
<td></td>
<td>Jabatan</td>
<td>:</td>
<td><?= $pegawai['jabatan'] ?></td>
</tr>
</table>

<br>

Dalam hal ini bertindak sebagai Pemegang Barang Inventaris pada Pengguna Barang dan selanjutnya disebut sebagai <span class="bold">PIHAK KEDUA</span>

<br><br>

<span class="bold">PIHAK KESATU</span> telah menyerahkan Barang Inventaris kepada <span class="bold">PIHAK KEDUA</span> dan <span class="bold">PIHAK KEDUA</span> menerima dari PIHAK PERTAMA Barang Milik Daerah yang tercatat pada Cabang Dinas Pendidikan Wilayah X Provinsi Jawa Barat, berupa barang modal untuk kebutuhan pelayanan, penetapan beserta untuk menunjang tugas pokok dan fungsi <span class="bold">PIHAK KEDUA</span> dengan rincian sebagai berikut:

<br><br>

<table class="table-surat">
<tr>
<td width="220">Nama Barang</td>
<td width="20">:</td>
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
<td>Kode Barang</td>
<td>:</td>
<td><?= $barang['kode_barang'] ?></td>
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

<br><br>

Demikian Berita Acara Serah Terima Pemanfaatan Barang Milik Daerah (BMD) ini dibuat dalam rangkap 2 (dua), untuk digunakan sebagai mana mestinya.

<br><br><br>

<table width="100%">
<tr align="center">
<td width="50%">
<span class="bold">PIHAK KEDUA</span><br><br><br><br>
<span class="bold"><?= $pegawai['nama_lengkap'] ?></span><br>
NIP. <?= $pegawai['nip'] ?>
</td>
<td width="50%">
<span class="bold">PIHAK PERTAMA</span><br><br><br><br>
<span class="bold">PEPEN APANDI</span><br>
NIP. 19800101 200701 1 012
</td>
</tr>
</table>

<br>

<div class="text-center no-print">
    <a href="bast_pdf.php?id=<?= $id ?>&nomor=<?= $nomor_surat ?>&tanggal=<?= $tanggal ?>&bulan=<?= $bulan ?>&tahun=<?= $tahun ?>" 
class="btn btn-danger" target="_blank">
Download PDF
</a>
</button>
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