<?php
include "koneksi.php";

// Ambil update terbaru
$query = mysqli_query($conn, "SELECT * FROM new_features ORDER BY tanggal DESC LIMIT 1");
$data = mysqli_fetch_assoc($query);

$ada_update = !empty($data);

// Default
$fileReady = false;
$sizeMB = 0;
$fileName = "";

// Cek file berdasarkan kolom: file_update
if($ada_update && !empty($data['file_update'])){
    $fileName = $data['file_update'];
    $filePath = "update_file/" . $fileName;

    if(file_exists($filePath)){
        $fileReady = true;
        $sizeMB = round(filesize($filePath) / (1024 * 1024), 2);
    }
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<title>Update Sistem</title>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">

<style>
body{
    background:#f1f5f9;
    font-family:Arial;
    display:flex;
    justify-content:center;
    align-items:center;
    height:100vh;
}

.card-box{
    width:380px;
    border:none;
    border-radius:18px;
    box-shadow:0 10px 25px rgba(0,0,0,.08);
    padding:22px;
    text-align:center;
    background:#fff;
}

.icon{
    font-size:45px;
    color:#2563eb;
}

.alert-update{
    font-size:13px;
    border-radius:10px;
    padding:10px;
    margin-bottom:15px;
}

.progress{
    height:20px;
    border-radius:10px;
    overflow:hidden;
    margin-top:15px;
    display:none;
}

.progress-bar{
    display:flex;
    align-items:center;
    justify-content:center;
    font-weight:bold;
}

.btn-download{
    border-radius:12px;
    padding:9px 18px;
    font-weight:600;
}

.empty i{
    font-size:45px;
    color:#94a3b8;
}
</style>
</head>

<body>

<div class="card-box">

<?php if(!$ada_update){ ?>

    <!-- TIDAK ADA DATA UPDATE -->
    <div class="empty">
        <i class="bi bi-check-circle"></i>
        <h5 class="mt-3">Sistem Terbaru</h5>
        <p class="text-muted small">Belum ada update tersedia</p>
    </div>

<?php } else { ?>

    <div class="alert alert-warning alert-update">
        ⚠️ Silahkan Menggunakan Sistem Versi Terbaru !
    </div>

    <i class="bi bi-cloud-arrow-down icon"></i>

    <h5 class="mt-3"><?= htmlspecialchars($data['fitur']); ?></h5>

    <p class="text-muted small"><?= htmlspecialchars($data['deskripsi']); ?></p>

    <p class="small">
        <b><?= $sizeMB ?> MB</b> 
    </p>

    <?php if($fileReady){ ?>
        <button onclick="downloadUpdate()" class="btn btn-primary btn-download" id="btnDownload">
            <i class="bi bi-download"></i> Download
        </button>
    <?php } else { ?>
        <div class="alert alert-danger small">
            File Sudah Di Download Silahkan Lakukan Instal Kembali !</b>
        </div>
    <?php } ?>

    <div class="progress" id="progressBar">
        <div class="progress-bar progress-bar-striped progress-bar-animated" 
             style="width:0%" id="progress">0%</div>
    </div>

<?php } ?>

<br>
<a href="dashboard.php" class="text-muted small">← Kembali</a>

</div>

<script>
function downloadUpdate(){
    const btn = document.getElementById('btnDownload');
    const progressBar = document.getElementById('progressBar');
    const progress = document.getElementById('progress');

    btn.innerHTML = "Downloading...";
    btn.disabled = true;

    progressBar.style.display = 'block';

    let width = 0;

    let interval = setInterval(()=>{
        if(width >= 100){
            clearInterval(interval);

            // Trigger download file
            window.location.href = "download.php?file=<?= urlencode($fileName); ?>";

            btn.innerHTML = "Selesai ✔";
            progress.innerHTML = "100%";
        }else{
            width++;
            progress.style.width = width + "%";
            progress.innerHTML = width + "%";
        }
    }, 50); // lebih lambat supaya terlihat animasinya
}
</script>

</body>
</html>