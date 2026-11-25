<?php
session_start();
require 'koneksi.php'; 
require 'vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;

if(!isset($_SESSION['id_user']) || !isset($_SESSION['id_sekolah'])){
    header("Location: login.php");
    exit;
}

$session_id_sekolah = $_SESSION['id_sekolah'];

/**
 * LOGIK PERBAIKAN:
 * Mencari nomor register terakhir berdasarkan KODE BARANG DAN TAHUN di sekolah terkait.
 */
function getLastRegisterByKodeAndYear($conn, $kode_barang, $tahun, $id_sekolah){
    $kode_barang = mysqli_real_escape_string($conn, $kode_barang);
    $tahun = mysqli_real_escape_string($conn, $tahun);
    
    // Query sekarang memfilter berdasarkan kode_barang DAN tahun_perolehan
    $query = "SELECT reg FROM data_barang WHERE kode_barang = '$kode_barang' AND tahun_perolehan = '$tahun' AND id_sekolah = '$id_sekolah'";
    $result = mysqli_query($conn, $query);
    $max = 0;
    while($row = mysqli_fetch_assoc($result)){
        $reg = $row['reg'];
        $akhir = (strpos($reg, '-') !== false) ? intval(explode('-', $reg)[1]) : intval($reg);
        if($akhir > $max) $max = $akhir;
    }
    return $max;
}

if(!isset($_FILES['file_excel'])) {
    header("Location: import_barang.php");
    exit;
}

$file = $_FILES['file_excel']['tmp_name'];
$spreadsheet = IOFactory::load($file);
$data = $spreadsheet->getActiveSheet()->toArray();
$header = array_shift($data); 

/**
 * SORT DATA:
 * Mengurutkan data berdasarkan KODE BARANG (index 0) DAN TAHUN (index 5)
 */
usort($data, function($a, $b){
    $cmp = strcmp(strtolower($a[0]), strtolower($b[0]));
    if($cmp === 0) {
        return strcmp($a[5], $b[5]);
    }
    return $cmp;
});

$register_per_row = [];
$group_counter = [];

foreach($data as $row){
    if(empty($row[1])) continue; 
    
    $kode_barang_excel = trim($row[0]); // Kolom A
    $tahun_excel = trim($row[5]);       // Kolom F (Tahun Perolehan)
    $volume = intval($row[7]) < 1 ? 1 : intval($row[7]);
    
    // KUNCI PERBAIKAN:
    // Key menggunakan gabungan Kode Barang + Tahun agar jika tahun beda, counter terpisah (reset ke 001 jika di DB belum ada)
    $key = strtolower($kode_barang_excel) . "|" . $tahun_excel;
    
    if(!isset($group_counter[$key])){
        // Ambil nomor terakhir dari DB berdasarkan Kode Barang DAN Tahun
        $group_counter[$key] = getLastRegisterByKodeAndYear($conn, $kode_barang_excel, $tahun_excel, $session_id_sekolah);
    }
    
    $awal = $group_counter[$key] + 1;
    $akhir = $awal + $volume - 1;
    
    $register_per_row[] = ($volume == 1) ? str_pad($awal, 3, '0', STR_PAD_LEFT) : str_pad($awal, 3, '0', STR_PAD_LEFT).'-'.str_pad($akhir, 3, '0', STR_PAD_LEFT);
    
    $group_counter[$key] = $akhir;
}

$total_item = count($data);
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Preview Import - SINVENTARIS</title>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        :root {
            --primary: #2563eb;
            --success: #10b981;
            --bg-body: #f1f5f9;
        }
        body { background: var(--bg-body); font-family: 'Plus Jakarta Sans', sans-serif; color: #334155; }
        
        .header-section {
            background: linear-gradient(135deg, #1e3a8a 0%, #3b82f6 100%);
            color: white; padding: 30px; border-radius: 0 0 25px 25px; margin-bottom: 30px;
        }

        .card-custom { background: white; border: none; border-radius: 16px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1); }
        .sticky-sidebar { position: sticky; top: 20px; max-height: 90vh; overflow-y: auto; padding-right: 5px; }
        
        .list-barang-item {
            border: 2px solid transparent !important;
            border-radius: 12px !important;
            margin-bottom: 8px;
            transition: all 0.2s;
            padding: 12px 15px;
        }
        .list-barang-item.active {
            background: #eff6ff !important;
            border-color: var(--primary) !important;
            color: var(--primary) !important;
        }
        .list-barang-item.done { border-color: var(--success) !important; }
        .list-barang-item.done .icon-done { color: var(--success); display: block !important; }

        /* PERUBAHAN LABEL DI SINI: Bold Hitam */
        .label-mini { font-size: 0.75rem; font-weight: bold; text-transform: uppercase; color: #000000; margin-bottom: 4px; display: block; }
        
        .form-control-sm, .form-select-sm { border-radius: 8px; padding: 10px; border: 1px solid #e2e8f0; }
        .form-control:focus { box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1); }
        
        /* PERUBAHAN WARNA ABU UNTUK READONLY DI SINI */
        .locked { background-color: #e9ecef !important; color: #000000 !important; font-weight: 600; cursor: not-allowed; border: 1px solid #ced4da !important; }
        
        .form-barang { animation: fadeIn 0.3s ease-out; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }

        .wajib:not(:placeholder-shown):not([value=""]) { border-color: var(--success); background-color: #f0fdf4; }
        .progress { background: #e2e8f0; border-radius: 50px; height: 12px; }
    </style>
</head>
<body>

<div class="header-section shadow">
    <div class="container-fluid px-4">
        <div class="d-flex justify-content-between align-items-center">
            <div>
                <h2 class="fw-bold mb-1"><i class="bi bi-file-earmark-check-fill me-2"></i> Verifikasi Data Import</h2>
                <p class="mb-0 opacity-75">Register akan reset ke 001 jika <b>Tahun Perolehan</b> berbeda dari data sebelumnya.</p>
            </div>
            <a href="index.php" class="btn btn-light text-danger fw-bold rounded-pill shadow-sm">
                <i class="bi bi-x-circle-fill me-2"></i> Batalkan
            </a>
        </div>
    </div>
</div>

<div class="container-fluid px-4 mb-5">
    <div class="row g-4">
        <div class="col-lg-4 col-md-5">
            <div class="sticky-sidebar">
                <div class="card card-custom p-3 mb-3">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <span class="fw-bold">Progress Kelengkapan</span>
                        <span class="badge bg-primary rounded-pill" id="percentText">0%</span>
                    </div>
                    <div class="progress mb-2">
                        <div class="progress-bar bg-success progress-bar-striped progress-bar-animated" id="progressBar" style="width: 0%"></div>
                    </div>
                    <small class="text-muted"><i class="bi bi-info-circle me-1"></i> <span id="countSelesai">0</span> dari <?= $total_item ?> item siap.</small>
                </div>

                <div class="list-group" id="list-barang">
                    <?php $no=0; foreach($data as $row){ if(empty($row[1])) continue; $no++; ?>
                    <a href="#" class="list-group-item list-group-item-action list-barang-item shadow-sm" data-no="<?= $no ?>" id="list-item-<?= $no ?>">
                        <div class="d-flex justify-content-between align-items-start">
                            <div style="max-width: 85%;">
                                <div class="fw-bold text-truncate"><?= $row[1] ?></div>
                                <small class="text-muted d-block mt-1">
                                    <span class="badge bg-primary opacity-75" title="Kode Barang">K: <?= $row[0] ?></span>
                                    <span class="badge bg-warning text-dark opacity-75">T: <?= $row[5] ?></span>
                                    <span class="badge bg-secondary opacity-75">Reg: <?= $register_per_row[$no-1] ?></span>
                                </small>
                            </div>
                            <i class="bi bi-check-circle-fill fs-5 d-none icon-done"></i>
                        </div>
                    </a>
                    <?php } ?>
                </div>
            </div>
        </div>

        <div class="col-lg-8 col-md-7">
            <form action="simpan_import.php" method="post" id="formImport">
                <input type="hidden" name="id_sekolah" value="<?= $session_id_sekolah ?>">

                <div id="container-form">
                    <?php $no=0; foreach($data as $row){ if(empty($row[1])) continue; $no++; 
                        // Jika ada format titik ganda di akhir seperti .00, buang dua nol terakhir terlebih dahulu
                        $raw_harga = trim($row[8]);
                        if (substr($raw_harga, -3) === '.00') {
                            $raw_harga = substr($raw_harga, 0, -3);
                        }
                        $harga_clean = preg_replace('/[^0-9]/', '', $raw_harga);
                    ?>
                    <div class="card card-custom p-4 mb-3 d-none form-barang" id="form-<?= $no ?>">
                        <div class="d-flex justify-content-between align-items-center border-bottom pb-3 mb-4">
                            <h5 class="fw-bold mb-0 text-primary">Verifikasi Item #<?= $no ?></h5>
                            <span class="badge bg-light text-primary border border-primary px-3 py-2 rounded-pill">Tahun: <?= $row[5] ?> | Kode: <?= $row[0] ?></span>
                        </div>
                        
                        <div class="row g-3">
                            <div class="col-md-3">
                                <label class="label-mini">No. Register (Otomatis)</label>
                                <input name="reg[]" class="form-control form-control-sm locked" readonly value="<?= $register_per_row[$no-1] ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="label-mini">Nama / Jenis Barang</label>
                                <input name="nama[]" class="form-control form-control-sm locked" readonly value="<?= $row[1] ?>">
                            </div>
                            <div class="col-md-3">
                                <label class="label-mini">Tahun Perolehan</label>
                                <input name="tahun[]" class="form-control form-control-sm locked" readonly value="<?= $row[5] ?>">
                            </div>

                            <div class="col-md-4">
                                <label class="label-mini">Merek / Type</label>
                                <input name="merek[]" class="form-control form-control-sm locked" readonly value="<?= $row[2] ?>">
                            </div>
                            <div class="col-md-5">
                                <label class="label-mini">Harga Satuan</label>
                                <div class="input-group input-group-sm">
                                    <span class="input-group-text locked border-end-0">Rp</span>
                                    <input class="form-control locked border-start-0" readonly value="<?= number_format((float)$harga_clean, 0, ',', '.') ?>">
                                </div>
                                <input type="hidden" name="harga[]" value="<?= $harga_clean ?>">
                            </div>
                            <div class="col-md-3">
                                <label class="label-mini">Volume</label>
                                <input name="volume[]" class="form-control form-control-sm locked" readonly value="<?= $row[7] ?>">
                            </div>

                            <hr class="my-4 text-muted opacity-25">

                            <div class="col-md-4">
                                <label class="label-mini">Bahan / Material <span class="text-danger fw-bold">(WAJIB ISI)</span></label>
                                <input name="bahan[]" class="form-control form-control-sm wajib" placeholder="Kayu / Besi / Plastik" required>
                            </div>
                            <div class="col-md-4">
                                <label class="label-mini">Asal Perolehan <span class="text-danger fw-bold">(WAJIB ISI)</span></label>
                                <input name="asal[]" class="form-control form-control-sm wajib" placeholder="APBD / Hibah / Dana BOS" required>
                            </div>
                            <div class="col-md-4">
                                <label class="label-mini">Kondisi Barang <span class="text-danger fw-bold">(WAJIB ISI)</span></label>
                                <select name="keadaan[]" class="form-select form-select-sm wajib" required>
                                    <option value="" hidden>- Pilih Kondisi -</option>
                                    <option value="B">Baik (B)</option>
                                    <option value="RR">Rusak Ringan (RR)</option>
                                    <option value="RB">Rusak Berat (RB)</option>
                                </select>
                            </div>

                            <div class="col-md-6">
                                <label class="label-mini">No Identitas (Mesin/Sertifikat/Chasis)</label>
                                <input name="no_identitas[]" class="form-control form-control-sm" placeholder="Opsional">
                            </div>
                            <div class="col-md-6">
                                <label class="label-mini">Keterangan Tambahan</label>
                                <input name="ket[]" class="form-control form-control-sm" placeholder="Opsional">
                            </div>

                            <input type="hidden" name="kode_barang[]" value="<?= $row[0] ?>">
                            <input type="hidden" name="tanggal[]" value="<?= $row[3] ?>">
                            <input type="hidden" name="bulan[]" value="<?= $row[4] ?>">
                            <input type="hidden" name="satuan[]" value="<?= $row[6] ?>">
                        </div>

                        <div class="mt-4 pt-3 border-top d-flex justify-content-end">
                            <?php if($no < $total_item): ?>
                            <button type="button" class="btn btn-primary px-4 rounded-pill btn-next" data-next="<?= $no + 1 ?>" disabled>
                                Item Berikutnya <i class="bi bi-arrow-right ms-2"></i>
                            </button>
                            <?php else: ?>
                            <span class="text-success fw-bold"><i class="bi bi-check-all me-1"></i> Item Terakhir Telah Dicapai</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php } ?>
                </div>

                <div class="card card-custom p-4 sticky-bottom shadow-lg border-top border-4 border-success mt-4">
                    <div class="row align-items-center">
                        <div class="col-md-8">
                            <div id="msgWarning" class="text-danger fw-bold">
                                <i class="bi bi-exclamation-triangle-fill me-2"></i> Data belum lengkap! Isi semua kolom bertanda (WAJIB ISI).
                            </div>
                            <div id="msgSuccess" class="text-success fw-bold d-none">
                                <i class="bi bi-check-circle-fill me-2"></i> Mantap! Semua data sudah valid dan siap disimpan.
                            </div>
                        </div>
                        <div class="col-md-4 text-end">
                            <button type="submit" class="btn btn-success btn-lg px-5 rounded-pill shadow w-100" id="btnSimpan" disabled>
                                <i class="bi bi-cloud-arrow-up-fill me-2"></i> Simpan Ke Inventaris
                            </button>
                        </div>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
    const totalItem = <?= $total_item ?>;
    const listItems = document.querySelectorAll('.list-barang-item');
    const forms = document.querySelectorAll('.form-barang');
    const progressBar = document.getElementById('progressBar');
    const btnSimpan = document.getElementById('btnSimpan');
    const msgWarning = document.getElementById('msgWarning');
    const msgSuccess = document.getElementById('msgSuccess');

    if(forms.length > 0) {
        forms[0].classList.remove('d-none');
        listItems[0].classList.add('active');
    }

    listItems.forEach(item => {
        item.addEventListener('click', function(e) {
            e.preventDefault();
            showForm(this.dataset.no);
        });
    });

    document.querySelectorAll('.btn-next').forEach(btn => {
        btn.addEventListener('click', function() {
            if(!this.hasAttribute('disabled')) {
                showForm(this.dataset.next);
                window.scrollTo({ top: 0, behavior: 'smooth' });
            }
        });
    });

    function showForm(no) {
        forms.forEach(f => f.classList.add('d-none'));
        listItems.forEach(l => l.classList.remove('active'));
        document.getElementById('form-' + no).classList.remove('d-none');
        document.getElementById('list-item-' + no).classList.add('active');
    }

    document.querySelectorAll('.wajib').forEach(el => {
        el.addEventListener('input', checkProgress);
        el.addEventListener('change', checkProgress);
    });

    // Pengecekan awal saat pertama kali halaman dimuat
    checkProgress();

    function checkProgress() {
        let finished = 0;
        forms.forEach((form, index) => {
            const inputs = form.querySelectorAll('.wajib');
            let isComplete = true;
            inputs.forEach(input => {
                if(input.value.trim() === '') isComplete = false;
            });

            const listItem = listItems[index];
            const icon = listItem.querySelector('.icon-done');
            const btnNext = form.querySelector('.btn-next');

            // Kontrol tombol 'Item Berikutnya' di dalam form ini
            if(btnNext) {
                if(isComplete) {
                    btnNext.disabled = false;
                } else {
                    btnNext.disabled = true;
                }
            }

            if(isComplete) {
                finished++;
                listItem.classList.add('done');
                icon.classList.remove('d-none');
            } else {
                listItem.classList.remove('done');
                icon.classList.add('d-none');
            }
        });

        const percent = Math.round((finished / totalItem) * 100);
        progressBar.style.width = percent + '%';
        document.getElementById('percentText').innerText = percent + '%';
        document.getElementById('countSelesai').innerText = finished;

        if(finished === totalItem) {
            btnSimpan.disabled = false;
            msgWarning.classList.add('d-none');
            msgSuccess.classList.remove('d-none');
        } else {
            btnSimpan.disabled = true;
            msgWarning.classList.remove('d-none');
            msgSuccess.classList.add('d-none');
        }
    }

    // ================= SOLUSI BATCHING PENYIMPANAN DATA =================
    document.getElementById('formImport').addEventListener('submit', async function(e) {
        e.preventDefault();

        if (!confirm('Apakah Anda yakin ingin menyimpan seluruh ' + totalItem + ' data barang ini?')) {
            return;
        }

        btnSimpan.disabled = true;
        const defaultBtnText = '<i class="bi bi-cloud-arrow-up-fill me-2"></i> Simpan Ke Inventaris';
        btnSimpan.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span> Memproses Data...';

        // 1. Ekstrak seluruh data dari masing-masing form card
        const allData = [];
        const idSekolah = document.querySelector('input[name="id_sekolah"]').value;

        forms.forEach(form => {
            const getVal = (name) => {
                const el = form.querySelector(`[name="${name}"]`);
                return el ? el.value : '';
            };

            allData.push({
                id_sekolah: idSekolah,
                reg: getVal('reg[]'),
                nama: getVal('nama[]'),
                tahun: getVal('tahun[]'),
                merek: getVal('merek[]'),
                harga: getVal('harga[]'),
                volume: getVal('volume[]'),
                bahan: getVal('bahan[]'),
                asal: getVal('asal[]'),
                keadaan: getVal('keadaan[]'),
                no_identitas: getVal('no_identitas[]'),
                ket: getVal('ket[]'),
                kode_barang: getVal('kode_barang[]'),
                tanggal: getVal('tanggal[]'),
                bulan: getVal('bulan[]'),
                satuan: getVal('satuan[]')
            });
        });

        // 2. Bagi pengiriman data bertahap (50 data per batch)
        const batchSize = 50;
        const totalBatches = Math.ceil(allData.length / batchSize);

        for (let i = 0; i < totalBatches; i++) {
            const chunk = allData.slice(i * batchSize, (i + 1) * batchSize);
            
            btnSimpan.innerHTML = `<span class="spinner-border spinner-border-sm me-2"></span> Menyimpan Batch ${i + 1} dari ${totalBatches}...`;

            try {
                const response = await fetch('simpan_import.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(chunk)
                });

                const result = await response.json();
                if (!result.success) {
                    alert('Gagal menyimpan pada batch ke-' + (i + 1) + ': ' + (result.message || 'Error tidak diketahui'));
                    btnSimpan.disabled = false;
                    btnSimpan.innerHTML = defaultBtnText;
                    return;
                }
            } catch (err) {
                alert('Terjadi kesalahan koneksi server pada batch ke-' + (i + 1));
                btnSimpan.disabled = false;
                btnSimpan.innerHTML = defaultBtnText;
                return;
            }
        }

        alert('Berhasil! Seluruh ' + totalItem + ' data barang sukses disimpan ke database.');
        window.location.href = 'index.php';
    });
</script>
</body>
</html>