<?php
// ===================== KONEKSI DB =====================
$host = "localhost";
$user = "root";
$pass = "";
$db   = "db_inventaris";

$conn = new mysqli($host, $user, $pass, $db);
if($conn->connect_error){
    die("Koneksi gagal: ".$conn->connect_error);
}

// ===================== LOAD PHPSPREADSHEET =====================
require 'vendor/autoload.php';
use PhpOffice\PhpSpreadsheet\IOFactory;

$message = '';
$message_type = 'info';

// ===================== IMPORT EXCEL =====================
if(isset($_POST['import'])){
    if(isset($_FILES['file_excel']['tmp_name']) && $_FILES['file_excel']['tmp_name'] != ''){

        $ext = pathinfo($_FILES['file_excel']['name'], PATHINFO_EXTENSION);

        if($ext == 'xlsx' || $ext == 'xls'){
            try{
                $file = $_FILES['file_excel']['tmp_name'];
                $spreadsheet = IOFactory::load($file);
                $sheetData = $spreadsheet->getActiveSheet()->toArray();

                $stmt = $conn->prepare("
                    INSERT INTO kode_barang
                    (kode_barang, uraian, kodering_aset, jenis_aset, umur_ekonomis)
                    VALUES (?,?,?,?,?)
                    ON DUPLICATE KEY UPDATE
                        uraian=VALUES(uraian),
                        kodering_aset=VALUES(kodering_aset),
                        jenis_aset=VALUES(jenis_aset),
                        umur_ekonomis=VALUES(umur_ekonomis)
                ");

                $count = 0;

                for($i=1;$i<count($sheetData);$i++){
                    if(!empty($sheetData[$i][0])){

                        $kode_barang   = trim($sheetData[$i][0]);
                        $uraian        = trim($sheetData[$i][1]);
                        $kodering_aset = trim($sheetData[$i][2]);
                        $jenis_aset    = trim($sheetData[$i][3]);
                        $umur_ekonomis = (int)$sheetData[$i][4];

                        $stmt->bind_param(
                            "ssssi",
                            $kode_barang,
                            $uraian,
                            $kodering_aset,
                            $jenis_aset,
                            $umur_ekonomis
                        );

                        $stmt->execute();
                        $count++;
                    }
                }

                $message = "✅ Berhasil import $count data ke database!";
                $message_type = "success";
            }catch(Exception $e){
                $message = "❌ Gagal membaca file Excel!";
                $message_type = "danger";
            }
        }
    }
}

// ===================== FUNCTION AMBIL DATA =====================
function ambilData($conn,$search=''){
    $sql = "SELECT * FROM kode_barang";
    if($search!=''){
        $s = $conn->real_escape_string($search);
        $sql .= " WHERE 
            kode_barang LIKE '%$s%' OR
            uraian LIKE '%$s%' OR
            jenis_aset LIKE '%$s%'";
    }
    $sql .= " ORDER BY kode_barang ASC";
    return $conn->query($sql);
}

// ===================== AJAX SEARCH =====================
if(isset($_GET['ajax'])){
    $q = $_GET['q'] ?? '';
    $res = ambilData($conn,$q);

    if($res && $res->num_rows>0){
        while($row=$res->fetch_assoc()){
            echo "<tr>
            <td class='fw-bold text-primary'>".htmlspecialchars($row['kode_barang'])."</td>
            <td>".htmlspecialchars($row['uraian'])."</td>
            <td><span class='badge bg-light text-dark border'>".htmlspecialchars($row['kodering_aset'])."</span></td>
            <td>".htmlspecialchars($row['jenis_aset'])."</td>
            <td class='text-center'>".htmlspecialchars($row['umur_ekonomis'])."</td>
            </tr>";
        }
    }else{
        echo "<tr><td colspan='5' class='text-center text-muted py-4'>Data tidak ditemukan</td></tr>";
    }
    exit;
}

// ===================== LOAD DATA AWAL =====================
$result = ambilData($conn);
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Import Kode Barang | SINVENTARIS</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">

    <style>
        :root {
            --primary: #2563eb;
            --bg-body: #f8fafc;
        }

        body { 
            background: var(--bg-body); 
            font-family: 'Plus Jakarta Sans', sans-serif;
            color: #1e293b;
        }

        .card {
            border: 1px solid #e2e8f0;
            border-radius: 20px;
            box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05);
            background: #ffffff;
            transition: transform 0.2s;
        }

        .info-box {
            background: #eff6ff;
            border-left: 5px solid var(--primary);
            padding: 15px;
            border-radius: 15px;
            font-size: 14px;
        }

        .table-wrapper {
            max-height: 500px;
            overflow-y: auto;
            border-radius: 12px;
        }

        .soft-table thead th {
            position: sticky;
            top: 0;
            background: #ffffff;
            z-index: 10;
            border-bottom: 2px solid #f1f5f9;
            text-transform: uppercase;
            font-size: 12px;
            letter-spacing: 0.5px;
            color: #64748b;
            padding: 15px;
        }

        .soft-table td {
            padding: 15px;
            vertical-align: middle;
            border-bottom: 1px solid #f1f5f9;
            font-size: 14px;
        }

        .soft-table tbody tr:hover {
            background: #f8fafc;
        }

        .search-box {
            border-radius: 12px;
            padding: 12px 20px;
            border: 1.5px solid #e2e8f0;
            transition: 0.3s;
        }

        .search-box:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 4px rgba(37, 99, 235, 0.1);
        }

        .btn-primary {
            background: var(--primary);
            border: none;
            padding: 12px;
            border-radius: 12px;
            font-weight: 600;
        }

        .btn-outline-secondary {
            border-radius: 10px;
            padding: 8px 16px;
        }

        /* Custom Scrollbar */
        .table-wrapper::-webkit-scrollbar { width: 6px; }
        .table-wrapper::-webkit-scrollbar-track { background: #f1f5f9; }
        .table-wrapper::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 10px; }
    </style>
</head>

<body>
<div class="container py-5">

    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-4 gap-3">
        <div>
            <h3 class="fw-bold mb-1">
                <i class="bi bi-database-add text-primary me-2"></i>
                Master Kode Barang
            </h3>
            <p class="text-secondary mb-0">Kelola dan import data referensi barang inventaris</p>
        </div>

        <a href="index.php" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left me-1"></i> Dashboard
        </a>
    </div>

    <?php if($message): ?>
    <div class="alert alert-<?= $message_type ?> border-0 shadow-sm alert-dismissible fade show mb-4" style="border-radius: 15px;">
        <?= $message ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
    <?php endif; ?>

    <div class="row g-4">
        <div class="col-lg-4">
            <div class="card p-4 mb-4">
                <h6 class="fw-bold mb-3">
                    <i class="bi bi-upload me-2 text-primary"></i>Upload File Excel
                </h6>

                <form method="post" enctype="multipart/form-data">
                    <div class="mb-3">
                        <input type="file" name="file_excel" class="form-control" accept=".xlsx,.xls" required>
                    </div>

                    <button name="import" class="btn btn-primary w-100 shadow-sm">
                        <i class="bi bi-cloud-upload me-2"></i> Import Sekarang
                    </button>
                </form>

                <div class="info-box mt-4">
                    <strong class="d-block mb-2"><i class="bi bi-info-circle me-1"></i> Panduan Import:</strong>
                    <ul class="ps-3 mb-0 text-secondary">
                        <li>Gunakan file format <strong>.xlsx</strong></li>
                        <li>Baris pertama adalah <strong>Header</strong></li>
                        <li>Sistem menggunakan <strong>Upsert</strong> (Jika kode sama, data akan diupdate)</li>
                    </ul>
                </div>
            </div>

            <div class="card p-4 text-center">
                <h6 class="fw-bold mb-3 text-start">
                    <i class="bi bi-search text-success me-2"></i>Pencarian Cepat
                </h6>
                <input type="text" id="searchBox" class="form-control search-box" placeholder="Cari kode, nama, atau jenis...">
            </div>
        </div>

        <div class="col-lg-8">
            <div class="card p-0 overflow-hidden">
                <div class="p-4 border-bottom bg-white d-flex justify-content-between align-items-center">
                    <h6 class="fw-bold mb-0">Daftar Referensi Kode</h6>
                    <span class="badge bg-primary bg-opacity-10 text-primary px-3 py-2 rounded-pill small">Real-time Table</span>
                </div>
                
                <div class="table-responsive table-wrapper">
                    <table class="table soft-table align-middle mb-0">
                        <thead>
                            <tr>
                                <th>Kode Barang</th>
                                <th>Uraian</th>
                                <th>Kodering Aset</th>
                                <th>Jenis Aset</th>
                                <th class="text-center">Umur</th>
                            </tr>
                        </thead>
                        <tbody id="dataTable">
                            <?php if($result->num_rows > 0): ?>
                                <?php while($row=$result->fetch_assoc()): ?>
                                <tr>
                                    <td class="fw-bold text-primary"><?= htmlspecialchars($row['kode_barang']) ?></td>
                                    <td><?= htmlspecialchars($row['uraian']) ?></td>
                                    <td><span class="badge bg-light text-dark border"><?= htmlspecialchars($row['kodering_aset']) ?></span></td>
                                    <td><?= htmlspecialchars($row['jenis_aset']) ?></td>
                                    <td class="text-center"><?= htmlspecialchars($row['umur_ekonomis']) ?></td>
                                </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr><td colspan="5" class="text-center text-muted py-5">Belum ada data tersedia</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
    let delay;
    const searchBox = document.getElementById("searchBox");
    const dataTable = document.getElementById("dataTable");

    searchBox.addEventListener("keyup", function(){
        clearTimeout(delay);
        let val = this.value;
        
        // Animasi loading sederhana
        dataTable.style.opacity = "0.5";
        
        delay = setTimeout(()=>{
            fetch("?ajax=1&q="+encodeURIComponent(val))
            .then(r => r.text())
            .then(d => {
                dataTable.innerHTML = d;
                dataTable.style.opacity = "1";
            })
            .catch(err => {
                console.error("Fetch error:", err);
                dataTable.style.opacity = "1";
            });
        }, 300);
    });
</script>

</body>
</html>