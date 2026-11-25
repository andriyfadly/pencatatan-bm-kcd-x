<?php
// JIKA DI-CALL LEWAT FETCH AJAX
if (isset($_POST['action_ajax'])) {
    if (session_status() === PHP_SESSION_NONE) { session_start(); }

    // 1. Cek Sesi
    if(!isset($_SESSION['id_user']) || !isset($_SESSION['id_sekolah'])){
        header('Content-Type: application/json');
        echo json_encode(['status' => 'error', 'message' => 'Sesi habis, silakan login kembali.']);
        exit;
    }

    // 2. Koneksi Database
    $conn = new mysqli("localhost","root","","db_inventaris");
    if($conn->connect_error){
        header('Content-Type: application/json');
        echo json_encode(['status' => 'error', 'message' => 'Koneksi DB Gagal']);
        exit;
    }

    $id_sekolah = $_SESSION['id_sekolah'];
    header('Content-Type: application/json');
    
    // --- AJAX: GET DETAIL KONDISI PER UNIT ---
    if($_POST['action_ajax'] == 'get_kondisi_detail'){
        $id_barang = intval($_POST['id']);
        
        // UPGRADE: Ambil juga kolom 'reg' untuk sinkronisasi nomor unit
        $q_brg = mysqli_query($conn, "SELECT nama_jenis_barang, merek_type, volume, reg FROM data_barang WHERE id='$id_barang' AND id_sekolah='$id_sekolah'");
        $d_brg = mysqli_fetch_assoc($q_brg);
        $volume = intval($d_brg['volume'] ?? 1);
        $nama_barang = $d_brg['nama_jenis_barang'] ?? '';
        $merek_barang = $d_brg['merek_type'] ?? '';
        $reg_barang = $d_brg['reg'] ?? '';

        // UPGRADE LOGIKA SINKRONISASI: Pastikan record detail unit terbuat sesuai range reg (misal 003-007)
        if (!empty($reg_barang) && $reg_barang != '-') {
            if (strpos($reg_barang, '-') !== false) {
                // Jika berupa paketan/range (contoh: '003-007')
                $pecah = explode('-', $reg_barang);
                $awal = (int) $pecah[0];
                $akhir = (int) $pecah[1];
                
                for ($i = $awal; $i <= $akhir; $i++) {
                    $nomor_unit_3_digit = str_pad($i, 3, '0', STR_PAD_LEFT);
                    $q_cek = mysqli_query($conn, "SELECT id FROM kondisi_barang_detail WHERE id_barang='$id_barang' AND no_reg='$nomor_unit_3_digit'");
                    if(mysqli_num_rows($q_cek) == 0){
                        // Default awal diset '-' (pilih kondisi)
                        mysqli_query($conn, "INSERT INTO kondisi_barang_detail (id_barang, no_reg, kondisi) VALUES ('$id_barang', '$nomor_unit_3_digit', '-')");
                    }
                }
            } else {
                // Jika berupa barang tunggal (contoh: '008')
                $nomor_unit_3_digit = str_pad((int)$reg_barang, 3, '0', STR_PAD_LEFT);
                $q_cek = mysqli_query($conn, "SELECT id FROM kondisi_barang_detail WHERE id_barang='$id_barang' AND no_reg='$nomor_unit_3_digit'");
                if(mysqli_num_rows($q_cek) == 0){
                    // Default awal diset '-' (pilih kondisi)
                    mysqli_query($conn, "INSERT INTO kondisi_barang_detail (id_barang, no_reg, kondisi) VALUES ('$id_barang', '$nomor_unit_3_digit', '-')");
                }
            }
        } else {
            // Logika Fallback (Jika kolom reg kosong, buat urutan dari 1 s/d volume)
            for($i = 1; $i <= $volume; $i++){
                $nomor_unit_3_digit = str_pad($i, 3, '0', STR_PAD_LEFT);
                $q_cek = mysqli_query($conn, "SELECT id FROM kondisi_barang_detail WHERE id_barang='$id_barang' AND (no_reg='$nomor_unit_3_digit' OR no_reg='$i' OR CAST(no_reg AS UNSIGNED) = $i)");
                if(mysqli_num_rows($q_cek) == 0){
                    // Default awal diset '-' (pilih kondisi)
                    mysqli_query($conn, "INSERT INTO kondisi_barang_detail (id_barang, no_reg, kondisi) VALUES ('$id_barang', '$nomor_unit_3_digit', '-')");
                }
            }
        }

        // Ambil data detail HANYA untuk id_barang yang aktif saat ini, diurutkan sesuai nomor registrasinya
        $q_detail = mysqli_query($conn, "SELECT * FROM kondisi_barang_detail WHERE id_barang='$id_barang' ORDER BY CAST(no_reg AS UNSIGNED) ASC");
        $results = [];
        while($row = mysqli_fetch_assoc($q_detail)){
            $results[] = $row;
        }

        echo json_encode([
            'status' => 'success', 
            'nama_barang' => $nama_barang,
            'merek_barang' => $merek_barang,
            'data' => $results
        ]);
        exit;
    }

    // --- AJAX: UPDATE DETAIL KONDISI PER UNIT ---
    if($_POST['action_ajax'] == 'update_kondisi_detail'){
        $id_barang = intval($_POST['id_barang']);
        $kondisi_array = $_POST['kondisi_unit'] ?? [];

        foreach($kondisi_array as $id_detail => $kondisi_val){
            $id_detail = intval($id_detail);
            $kondisi_val = mysqli_real_escape_string($conn, $kondisi_val);
            
            // DIKUNCI MATI: Mengupdate data berdasarkan primary key `id` baris database tersebut dan `id_barang`-nya.
            mysqli_query($conn, "UPDATE kondisi_barang_detail SET kondisi = '$kondisi_val' WHERE id = '$id_detail' AND id_barang = '$id_barang'");
        }

        echo json_encode(['status' => 'success']);
        exit;
    }
}
?>

<style>
.grid-kondisi-container {
    display: flex;
    flex-direction: column;
    gap: 8px;
    width: 100%;
}
.card-unit-item-vertical { 
    width: 100%;
    background: white; 
    border: 1px solid #e2e8f0; 
    border-radius: 10px; 
    padding: 12px 20px; 
    display: flex;
    align-items: center;
    justify-content: space-between;
    box-shadow: 0 2px 4px rgba(0,0,0,0.01);
}
.kondisi-pagination {
    display: flex;
    justify-content: center;
    gap: 5px;
    margin-top: 15px;
    flex-wrap: wrap;
}
.kondisi-pagination .page-item-btn {
    border: 1px solid #dee2e6;
    background: #fff;
    color: #198754;
    padding: 4px 10px;
    font-size: 12px;
    font-weight: bold;
    border-radius: 5px;
    cursor: pointer;
    transition: all 0.2s;
}
.kondisi-pagination .page-item-btn.active {
    background: #198754;
    color: #fff;
    border-color: #198754;
}
.kondisi-pagination .page-item-btn:hover:not(.active) {
    background: #e9ecef;
}
</style>

<div class="modal fade" id="modalKondisiDetail" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-md modal-dialog-centered modal-dialog-scrollable">
        <form id="formKondisiDetail" class="modal-content border-0 shadow-lg">
            <div class="modal-header bg-success text-white p-3">
                <h5 class="modal-title fw-bold small"><i class="bi bi-shield-check me-2"></i>EDIT KONDISI BARANG PER UNIT</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4 bg-light">
                <input type="hidden" name="id_barang" id="kondisi_id_barang">
                <input type="hidden" name="action_ajax" value="update_kondisi_detail">
                
                <h6 class="fw-bold text-dark mb-1" id="label_nama_merek_barang">Nama Barang - Merk</h6>
                <p class="text-muted small mb-3">Silakan tentukan kondisi fisik untuk setiap unit item:</p>
                
                <!-- FITUR TAMBAHAN: UBAH SEMUA KONDISI SEKALIGUS -->
                <div class="p-2 mb-3 bg-white border rounded d-flex align-items-center justify-content-between gap-2 shadow-sm">
                    <span class="small fw-bold text-secondary mb-0"><i class="bi bi-check2-square text-success me-1"></i> Ubah Semua Sekaligus:</span>
                    <select id="select_all_kondisi" class="form-select form-select-sm fw-bold text-center" style="width: 160px; font-size: 13px;">
                        <option value="" selected disabled>-- Pilih Kondisi --</option>
                        <option value="B">B (Baik)</option>
                        <option value="RR">RR (Rusak Ringan)</option>
                        <option value="RB">RB (Rusak Berat)</option>
                    </select>
                </div>

                <div id="container_list_unit" class="grid-kondisi-container">
                    </div>

                <div id="kondisi_pagination_container" class="kondisi-pagination">
                    </div>
            </div>
            <div class="modal-footer bg-white p-3">
                <button type="button" class="btn btn-secondary btn-sm px-4 rounded-pill" data-bs-dismiss="modal">Tutup</button>
                <button type="submit" id="btnSimpanKondisi" class="btn btn-success btn-sm px-4 rounded-pill fw-bold shadow">Simpan Kondisi</button>
            </div>
        </form>
    </div>
</div>

<script>
const modalKondisi = new bootstrap.Modal(document.getElementById('modalKondisiDetail'));

let globalKondisiData = [];
let kondisiCurrentPage = 1;
const kondisiItemsPerPage = 10;

function renderKondisiPage(page) {
    kondisiCurrentPage = page;
    const container = document.getElementById('container_list_unit');
    container.innerHTML = '';

    const startIndex = (page - 1) * kondisiItemsPerPage;
    const endIndex = startIndex + kondisiItemsPerPage;
    const pageItems = globalKondisiData.slice(startIndex, endIndex);

    pageItems.forEach(item => {
        const nomorUnitFormat = String(item.no_reg).padStart(3, '0');
        const div = document.createElement('div');
        div.className = 'card-unit-item-vertical';
        div.innerHTML = `
            <div class="d-flex align-items-center gap-2">
                <i class="bi bi-box-seam text-secondary"></i>
                <span class="fw-bold text-dark" style="font-size: 14px;">Nomor Unit: ${nomorUnitFormat}</span>
            </div>
            <div style="width: 140px;">
                <select name="kondisi_unit[${item.id}]" class="form-select form-select-sm fw-bold text-center select-kondisi-item" data-id="${item.id}" style="font-size: 13px;">
                    <option value="-" ${item.kondisi === '-' || !item.kondisi ? 'selected' : ''}>-- Pilih Kondisi --</option>
                    <option value="B" ${item.kondisi === 'B' ? 'selected' : ''}>B (Baik)</option>
                    <option value="RR" ${item.kondisi === 'RR' ? 'selected' : ''}>RR (Rusak Ringan)</option>
                    <option value="RB" ${item.kondisi === 'RB' ? 'selected' : ''}>RB (Rusak Berat)</option>
                </select>
            </div>
        `;
        container.appendChild(div);
    });

    document.querySelectorAll('.select-kondisi-item').forEach(select => {
        select.addEventListener('change', function() {
            const idDetail = this.dataset.id;
            const valBaru = this.value;
            const found = globalKondisiData.find(x => x.id == idDetail);
            if(found) found.kondisi = valBaru;
        });
    });

    renderKondisiPaginationControls();
}

// Render tombol halaman
function renderKondisiPaginationControls() {
    const pagContainer = document.getElementById('kondisi_pagination_container');
    pagContainer.innerHTML = '';

    const totalPages = Math.ceil(globalKondisiData.length / kondisiItemsPerPage);
    if(totalPages <= 1) return;

    for(let i = 1; i <= totalPages; i++) {
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = `page-item-btn ${i === kondisiCurrentPage ? 'active' : ''}`;
        btn.innerText = i;
        btn.addEventListener('click', function() {
            renderKondisiPage(i);
        });
        pagContainer.appendChild(btn);
    }
}

// HANDLER UNTUK MERUBAH SEMUA KONDISI SEKALIGUS
document.getElementById('select_all_kondisi').addEventListener('change', function() {
    const selectedVal = this.value;
    if(!selectedVal) return;

    // Update seluruh data di array global (semua unit & semua halaman)
    globalKondisiData.forEach(item => {
        item.kondisi = selectedVal;
    });

    // Render ulang halaman aktif agar tampilan UI langsung berubah
    renderKondisiPage(kondisiCurrentPage);

    // Reset pilihan dropdown ke default
    this.value = '';
});

document.addEventListener('click', function(e) {
    if(e.target.closest('.btn-kondisi-detail')) {
        const id = e.target.closest('.btn-kondisi-detail').dataset.id;
        document.getElementById('kondisi_id_barang').value = id;
        document.getElementById('select_all_kondisi').value = ''; // Reset dropdown pilih semua
        
        const container = document.getElementById('container_list_unit');
        const pagContainer = document.getElementById('kondisi_pagination_container');
        const labelHeader = document.getElementById('label_nama_merek_barang');
        
        container.innerHTML = '<div class="w-100 text-center py-3"><div class="spinner-border spinner-border-sm text-success"></div></div>';
        pagContainer.innerHTML = '';
        labelHeader.innerText = "Memuat data barang...";
        modalKondisi.show();

        const fd = new FormData();
        fd.append('id', id);
        fd.append('action_ajax', 'get_kondisi_detail');

        fetch('edit_kondisi_unit.php', { method: 'POST', body: fd })
        .then(res => res.json())
        .then(res => {
            if(res.status === 'success') {
                const merekText = res.merek_barang ? ' - ' + res.merek_barang : '';
                labelHeader.innerText = res.nama_barang + merekText;
                
                globalKondisiData = res.data;
                renderKondisiPage(1);
            } else {
                container.innerHTML = '<p class="text-danger small text-center w-100">Gagal memuat unit detail.</p>';
            }
        });
    }
});

document.getElementById('formKondisiDetail').addEventListener('submit', function(e) {
    e.preventDefault();
    const btn = document.getElementById('btnSimpanKondisi');
    btn.disabled = true;
    btn.innerHTML = 'Menyimpan...';

    const fd = new FormData();
    fd.append('id_barang', document.getElementById('kondisi_id_barang').value);
    fd.append('action_ajax', 'update_kondisi_detail');
    
    globalKondisiData.forEach(item => {
        fd.append(`kondisi_unit[${item.id}]`, item.kondisi);
    });

    fetch('edit_kondisi_unit.php', { method: 'POST', body: fd })
    .then(res => res.json())
    .then(data => {
        if(data.status === 'success') {
            alert('Kondisi detail seluruh unit berhasil disimpan!');
            location.reload();
        } else {
            alert('Gagal menyimpan kondisi.');
        }
    })
    .finally(() => {
        btn.disabled = false;
        btn.innerHTML = 'Simpan Kondisi';
    });
});
</script>