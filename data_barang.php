<?php
session_start();

if(!isset($_SESSION['id_user'])){
    header("Location: login.php");
    exit;
}

$id_sekolah = $_SESSION['id_sekolah'];
$current_page = basename($_SERVER['PHP_SELF']);

// ================= KONEKSI DATABASE =================
$conn = new mysqli("localhost","root","","db_inventaris");
if($conn->connect_error){
    die("Koneksi DB Gagal: " . $conn->connect_error);
}

// Fungsi bantu format rupiah untuk PHP
function formatRupiah($angka){
    return "Rp " . number_format((float)$angka, 0, ',', '.');
}

// ================= PROSES AJAX =================
if(isset($_POST['action_ajax'])){
    header('Content-Type: application/json');
    $id = isset($_POST['id']) ? intval($_POST['id']) : 0;

    // 1. UPDATE DATA (HANYA HARGA, KEADAAN, KETERANGAN)
    if($_POST['action_ajax'] == 'update_data'){
        $harga_raw = $_POST['harga_satuan'];
        $harga_final = (int)preg_replace('/[^0-9]/', '', $harga_raw);
        
        $keadaan    = mysqli_real_escape_string($conn, $_POST['keadaan_barang']); 
        $keterangan = mysqli_real_escape_string($conn, $_POST['keterangan']);

        $sql = "UPDATE data_barang SET 
                keadaan_barang = '$keadaan', 
                harga_satuan = '$harga_final', 
                keterangan = '$keterangan' 
                WHERE id = '$id' AND id_sekolah = '$id_sekolah'";

        if(mysqli_query($conn, $sql)){
            echo json_encode([
                'status' => 'success',
                'updated_data' => [
                    'id' => $id,
                    'keadaan_barang' => $keadaan,
                    'harga_satuan' => formatRupiah($harga_final)
                ]
            ]);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Gagal simpan ke database']);
        }
        exit;
    }

    // 2. AMBIL DATA (UNTUK FORM EDIT)
    if($_POST['action_ajax'] == 'get_data'){
        $q = mysqli_query($conn, "SELECT * FROM data_barang WHERE id='$id' AND id_sekolah='$id_sekolah'");
        $data = mysqli_fetch_assoc($q);
        echo json_encode($data);
        exit;
    }

    // 3. LOGIKA HAPUS
    if($_POST['action_ajax'] == 'hapus'){
        mysqli_query($conn,"DELETE FROM data_barang WHERE id='$id' AND id_sekolah='$id_sekolah'");
        echo json_encode(['status' => 'success']);
        exit;
    }
}

$kop_q = mysqli_query($conn,"SELECT nama_sekolah FROM kop_surat WHERE id_sekolah='$id_sekolah' LIMIT 1");
$kop = mysqli_fetch_assoc($kop_q);
$nama_sekolah = $kop['nama_sekolah'] ?? "INSTANSI PENDIDIKAN";
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inventaris - <?= $nama_sekolah; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <style>
        :root { --primary: #0d6efd; --bg-body: #f4f7fe; }
        body { background-color: var(--bg-body); font-family: 'Inter', sans-serif; color: #334155; }
        .main-header { background: linear-gradient(135deg, #0d6efd 0%, #0a4ebd 100%); border-radius: 20px; padding: 30px; color: white; box-shadow: 0 10px 25px rgba(13, 110, 253, 0.2); margin-bottom: 30px; }
        .table-container { background: white; border-radius: 20px; padding: 25px; box-shadow: 0 5px 20px rgba(0,0,0,0.03); }
        .img-barang { width: 55px; height: 55px; object-fit: cover; border-radius: 10px; transition: 0.3s; border: 2px solid #fff; cursor: pointer; }
        .img-barang:hover { transform: scale(2.5); position: relative; z-index: 99; box-shadow: 0 5px 15px rgba(0,0,0,0.3); }
        
        /* Gaya Khusus Readonly agar kontras */
        .form-control[readonly] { background-color: #f1f5f9 !important; color: #64748b !important; border: 1px solid #e2e8f0; font-size: 0.85rem; }
        .editable-field { border: 2px solid #0d6efd !important; background-color: #fff !important; font-weight: bold; }
        label { font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.5px; color: #475569; }
    </style>
</head>
<body>

<div class="container-fluid py-4 px-lg-5">
    <div class="main-header d-flex justify-content-between align-items-center flex-wrap shadow">
        <div>
            <h6 class="text-uppercase opacity-75 mb-1" style="font-size: 12px; letter-spacing: 2px;">Manajemen Inventaris</h6>
            <h2 class="fw-bold mb-0"><?= $nama_sekolah; ?></h2>
        </div>
        <a href="index.php" class="btn btn-light rounded-pill px-4 fw-bold text-primary shadow-sm"><i class="bi bi-house-door me-2"></i>Dashboard</a>
    </div>

    <div class="table-container">
        <h5 class="fw-bold mb-4"><i class="bi bi-list-stars me-2 text-primary"></i>Daftar Inventaris</h5>
        <div class="table-responsive">
            <table class="table table-hover align-middle">
                <thead>
                    <tr>
                        <th class="text-center">No</th>
                        <th>Foto</th>
                        <th>Info Barang</th>
                        <th>Merk</th>
                        <th>Harga Satuan</th>
                        <th>Kondisi</th>
                        <th class="text-center">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $no=1;
                    $q = mysqli_query($conn,"SELECT * FROM data_barang WHERE id_sekolah='$id_sekolah' ORDER BY id DESC");
                    while($d=mysqli_fetch_assoc($q)){
                        $kon = strtoupper(trim($d['keadaan_barang']));
                        $badge = ($kon == 'B') ? 'bg-success' : (($kon == 'KB') ? 'bg-warning text-dark' : 'bg-danger');
                    ?>
                    <tr id="row-<?= $d['id']; ?>">
                        <td class="text-center"><?= $no++; ?></td>
                        <td><img src="upload/<?= $d['foto'] ?: 'no-photo.png'; ?>" class="img-barang shadow-sm" onerror="this.src='https://placehold.co/100x100?text=Barang'"></td>
                        <td>
                            <div class="fw-bold"><?= $d['nama_jenis_barang']; ?></div>
                            <div class="text-muted small"><?= $d['kode_barang']; ?></div>
                        </td>
                        <td class="small"><?= $d['merek_type']; ?></td>
                        <td class="small fw-bold text-primary col-harga"><?= formatRupiah($d['harga_satuan']); ?></td>
                        <td class="col-kondisi"><span class="badge rounded-pill <?= $badge; ?>"><?= $d['keadaan_barang']; ?></span></td>
                        <td class="text-center">
                            <div class="btn-group btn-group-sm">
                                <button class="btn btn-outline-primary btn-edit" data-id="<?= $d['id']; ?>"><i class="bi bi-pencil-square"></i></button>
                                <button class="btn btn-outline-danger btn-hapus" data-id="<?= $d['id']; ?>"><i class="bi bi-trash3"></i></button>
                            </div>
                        </td>
                    </tr>
                    <?php } ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="modal fade" id="modalEdit" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <form id="formEdit" class="modal-content border-0 shadow-lg">
            <div class="modal-header bg-primary text-white p-4">
                <h5 class="modal-title fw-bold"><i class="bi bi-shield-lock me-2"></i>Update Data Barang</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4 bg-light">
                <input type="hidden" name="id" id="edit_id">
                <input type="hidden" name="action_ajax" value="update_data">
                
                <div class="row g-3">
                    <div class="col-12"><div class="fw-bold text-muted small border-bottom mb-2">IDENTITAS BARANG (READONLY)</div></div>
                    <div class="col-md-6"><label class="fw-bold">Nama Barang</label><input type="text" id="view_nama" class="form-control" readonly></div>
                    <div class="col-md-4"><label class="fw-bold">Kode Barang</label><input type="text" id="view_kode" class="form-control" readonly></div>
                    <div class="col-md-2"><label class="fw-bold">Reg</label><input type="text" id="view_reg" class="form-control" readonly></div>
                    
                    <div class="col-md-4"><label class="fw-bold">Merek / Type</label><input type="text" id="view_merek" class="form-control" readonly></div>
                    <div class="col-md-4"><label class="fw-bold">No. Identitas</label><input type="text" id="view_no_id" class="form-control" readonly></div>
                    <div class="col-md-4"><label class="fw-bold">Bahan</label><input type="text" id="view_bahan" class="form-control" readonly></div>

                    <div class="col-12 mt-4"><div class="fw-bold text-muted small border-bottom mb-2">DATA PEROLEHAN (READONLY)</div></div>
                    <div class="col-md-5"><label class="fw-bold">Asal Perolehan</label><input type="text" id="view_asal" class="form-control" readonly></div>
                    <div class="col-md-4"><label class="fw-bold">Tgl Perolehan</label><input type="text" id="view_tgl" class="form-control" readonly></div>
                    <div class="col-md-3"><label class="fw-bold">Volume / Satuan</label><input type="text" id="view_vol" class="form-control" readonly></div>

                    <div class="col-12 mt-4"><div class="fw-bold text-primary small border-bottom mb-2">UPDATE DATA (EDITABLE)</div></div>
                    <div class="col-md-4">
                        <label class="fw-bold text-primary">Keadaan Barang</label>
                        <select name="keadaan_barang" id="edit_keadaan" class="form-select editable-field">
                            <option value="B">B (Baik)</option>
                            <option value="KB">KB (Kurang Baik)</option>
                            <option value="RB">RB (Rusak Berat)</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="fw-bold text-primary">Harga Satuan (Rp)</label>
                        <input type="text" name="harga_satuan" id="edit_harga" class="form-control editable-field">
                    </div>
                    <div class="col-md-4">
                        <label class="fw-bold text-primary">Keterangan</label>
                        <textarea name="keterangan" id="edit_keterangan" class="form-control editable-field" rows="1"></textarea>
                    </div>
                </div>
            </div>
            <div class="modal-footer bg-white p-3">
                <button type="button" class="btn btn-secondary btn-sm px-4 rounded-pill" data-bs-dismiss="modal">Batal</button>
                <button type="submit" id="btnSimpan" class="btn btn-primary btn-sm px-4 rounded-pill fw-bold shadow">Simpan Perubahan</button>
            </div>
        </form>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
const myModal = new bootstrap.Modal(document.getElementById('modalEdit'));
const inputHarga = document.getElementById('edit_harga');

// Format Rupiah Input
inputHarga.addEventListener('input', function() {
    let raw = this.value.replace(/[^0-9]/g, '');
    this.value = raw ? new Intl.NumberFormat('id-ID').format(raw) : '';
});

// Load Data
document.addEventListener('click', function(e) {
    if(e.target.closest('.btn-edit')) {
        const id = e.target.closest('.btn-edit').dataset.id;
        const fd = new FormData();
        fd.append('id', id);
        fd.append('action_ajax', 'get_data');

        fetch('<?= $current_page; ?>', { method: 'POST', body: fd })
        .then(res => res.json())
        .then(data => {
            // Isi Readonly
            document.getElementById('edit_id').value = data.id;
            document.getElementById('view_nama').value = data.nama_jenis_barang;
            document.getElementById('view_kode').value = data.kode_barang;
            document.getElementById('view_reg').value = data.reg;
            document.getElementById('view_merek').value = data.merek_type;
            document.getElementById('view_no_id').value = data.no_identitas;
            document.getElementById('view_bahan').value = data.bahan;
            document.getElementById('view_asal').value = data.asal_perolehan;
            document.getElementById('view_tgl').value = `${data.tanggal_perolehan}-${data.bulan_perolehan}-${data.tahun_perolehan}`;
            document.getElementById('view_vol').value = `${data.volume} ${data.satuan}`;
            
            // Isi Editable
            document.getElementById('edit_keadaan').value = data.keadaan_barang;
            document.getElementById('edit_keterangan').value = data.keterangan;
            inputHarga.value = new Intl.NumberFormat('id-ID').format(data.harga_satuan);
            
            myModal.show();
        });
    }
});

// Submit
document.getElementById('formEdit').addEventListener('submit', function(e) {
    e.preventDefault();
    const btn = document.getElementById('btnSimpan');
    btn.disabled = true; btn.innerHTML = 'Menyimpan...';

    const fd = new FormData(this);
    let cleanHarga = inputHarga.value.replace(/[^0-9]/g, '');
    fd.set('harga_satuan', cleanHarga);

    fetch('<?= $current_page; ?>', { method: 'POST', body: fd })
    .then(res => res.json())
    .then(data => {
        if(data.status === 'success') {
            const row = document.getElementById('row-' + data.updated_data.id);
            row.querySelector('.col-harga').innerText = data.updated_data.harga_satuan;
            const badge = row.querySelector('.col-kondisi span');
            badge.innerText = data.updated_data.keadaan_barang;
            badge.className = 'badge rounded-pill ' + (data.updated_data.keadaan_barang == 'B' ? 'bg-success' : (data.updated_data.keadaan_barang == 'KB' ? 'bg-warning text-dark' : 'bg-danger'));
            myModal.hide();
        }
    }).finally(() => { btn.disabled = false; btn.innerHTML = 'Simpan Perubahan'; });
});

// Hapus
document.addEventListener('click', function(e) {
    if(e.target.closest('.btn-hapus')) {
        if(!confirm('Hapus data?')) return;
        const id = e.target.closest('.btn-hapus').dataset.id;
        const fd = new FormData();
        fd.append('id', id);
        fd.append('action_ajax', 'hapus');
        fetch('<?= $current_page; ?>', { method: 'POST', body: fd })
        .then(res => res.json())
        .then(data => { if(data.status === 'success') document.getElementById('row-' + id).remove(); });
    }
});
</script>
</body>
</html>