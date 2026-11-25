<?php
header("X-Frame-Options: DENY");
header("X-XSS-Protection: 1; mode=block");
header("X-Content-Type-Options: nosniff");

session_start();

if(!isset($_SESSION['id_user'])){
    header("Location: login.php");
    exit;
}

include "koneksi.php";

$id_sekolah = mysqli_real_escape_string($conn, $_SESSION['id_sekolah']);

// Pastikan tabel pengaturan_surat tersedia secara otomatis
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS pengaturan_surat (
    id INT AUTO_INCREMENT PRIMARY KEY,
    id_sekolah VARCHAR(50) NOT NULL UNIQUE,
    kode_bast VARCHAR(100) NOT NULL DEFAULT '',
    kode_pengembalian VARCHAR(100) NOT NULL DEFAULT '',
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)");

// ================= PROSES SIMPAN PENGATURAN (AJAX) =================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['simpan_pengaturan'])) {
    header('Content-Type: application/json');
    
    $kode_bast = mysqli_real_escape_string($conn, trim($_POST['kode_bast'] ?? ''));
    $kode_pengembalian = mysqli_real_escape_string($conn, trim($_POST['kode_pengembalian'] ?? ''));
    
    $cek = mysqli_query($conn, "SELECT id FROM pengaturan_surat WHERE id_sekolah='$id_sekolah' LIMIT 1");
    
    if (mysqli_num_rows($cek) > 0) {
        $query = mysqli_query($conn, "UPDATE pengaturan_surat SET 
            kode_bast = '$kode_bast', 
            kode_pengembalian = '$kode_pengembalian' 
            WHERE id_sekolah = '$id_sekolah'");
    } else {
        $query = mysqli_query($conn, "INSERT INTO pengaturan_surat (id_sekolah, kode_bast, kode_pengembalian) 
            VALUES ('$id_sekolah', '$kode_bast', '$kode_pengembalian')");
    }
    
    if ($query) {
        echo json_encode([
            'status' => 'success', 
            'message' => 'Kode klasifikasi berhasil disimpan ke database.',
            'updated_at' => date('d M Y, H:i') . ' WIB'
        ]);
    } else {
        echo json_encode([
            'status' => 'error', 
            'message' => 'Gagal menyimpan pengaturan: ' . mysqli_error($conn)
        ]);
    }
    exit;
}

// ================= AMBIL DATA PENGATURAN SAAT INI =================
$qData = mysqli_query($conn, "SELECT * FROM pengaturan_surat WHERE id_sekolah='$id_sekolah' LIMIT 1");
$dSurat = mysqli_fetch_assoc($qData);

$kode_bast = $dSurat['kode_bast'] ?? '';
$kode_pengembalian = $dSurat['kode_pengembalian'] ?? '';
$updated_at = !empty($dSurat['updated_at']) ? date('d M Y, H:i', strtotime($dSurat['updated_at'])) . ' WIB' : 'Belum pernah disimpan';
?>

<style>
    /* ================= TOP HEADER CARD ================= */
    .cfg-header-box {
        background: #ffffff;
        border-radius: 20px;
        padding: 24px 28px;
        box-shadow: 0 8px 24px -6px rgba(15, 23, 42, 0.05);
        border: 1px solid rgba(226, 232, 240, 0.85);
        border-left: 6px solid #20b4cb;
        margin-bottom: 24px;
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        justify-content: space-between;
        gap: 16px;
    }
    .cfg-title-icon {
        width: 52px;
        height: 52px;
        border-radius: 15px;
        background: linear-gradient(135deg, #074759 0%, #20b4cb 100%);
        color: #ffffff;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 24px;
        box-shadow: 0 6px 14px rgba(32, 180, 203, 0.28);
        flex-shrink: 0;
    }
    .cfg-sync-chip {
        background: #f1f5f9;
        border: 1px solid #e2e8f0;
        color: #475569;
        padding: 8px 14px;
        border-radius: 12px;
        font-size: 12.5px;
        font-weight: 600;
        display: inline-flex;
        align-items: center;
        gap: 8px;
    }

    /* ================= CARD INPUT KLASIFIKASI ================= */
    .cfg-card {
        background: #ffffff;
        border-radius: 20px;
        padding: 28px;
        box-shadow: 0 10px 25px -5px rgba(15, 23, 42, 0.05);
        border: 1px solid rgba(226, 232, 240, 0.9);
        position: relative;
        overflow: hidden;
        height: 100%;
        transition: all 0.25s ease;
    }
    .cfg-card:hover {
        transform: translateY(-3px);
        box-shadow: 0 16px 32px -8px rgba(15, 23, 42, 0.09);
    }
    .cfg-card-accent-blue { border-top: 5px solid #1677ff; }
    .cfg-card-accent-green { border-top: 5px solid #16a34a; }

    .cfg-card-badge {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 5px 12px;
        border-radius: 8px;
        font-size: 11.5px;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.4px;
    }
    .badge-bast { background: #e6f0ff; color: #1677ff; }
    .badge-pengembalian { background: #dcfce7; color: #16a34a; }

    .cfg-input-wrapper {
        position: relative;
        margin-top: 14px;
        margin-bottom: 12px;
    }
    .cfg-input-icon {
        position: absolute;
        left: 16px;
        top: 50%;
        transform: translateY(-50%);
        font-size: 18px;
        color: #64748b;
        pointer-events: none;
        transition: 0.2s;
    }
    .cfg-input-field {
        width: 100%;
        padding: 13px 16px 13px 46px;
        border-radius: 13px;
        border: 1.5px solid #cbd5e1;
        background: #f8fafc;
        font-size: 15px;
        font-weight: 700;
        color: #0f172a;
        transition: all 0.2s ease;
    }
    .cfg-input-field:focus {
        outline: none;
        background: #ffffff;
        border-color: #20b4cb;
        box-shadow: 0 0 0 4px rgba(32, 180, 203, 0.14);
    }
    .cfg-input-field:focus + .cfg-input-icon,
    .cfg-input-wrapper:focus-within .cfg-input-icon {
        color: #074759;
    }

    /* ================= ACTIVE STORAGE & SAVE BAR ================= */
    .cfg-bottom-panel {
        background: #ffffff;
        border-radius: 20px;
        padding: 24px 28px;
        box-shadow: 0 10px 25px -5px rgba(15, 23, 42, 0.05);
        border: 1px solid rgba(226, 232, 240, 0.9);
    }
    .cfg-stored-item {
        background: linear-gradient(180deg, #f8fafc 0%, #f1f5f9 100%);
        border: 1px solid #e2e8f0;
        border-radius: 14px;
        padding: 15px 18px;
        display: flex;
        align-items: center;
        gap: 14px;
    }
    .cfg-stored-dot {
        width: 38px;
        height: 38px;
        border-radius: 10px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 17px;
        flex-shrink: 0;
    }
    .btn-simpan-utama {
        background: linear-gradient(90deg, #074759 0%, #0d6e8a 100%);
        color: #ffffff;
        border: none;
        border-radius: 14px;
        padding: 13px 30px;
        font-weight: 700;
        font-size: 14.5px;
        display: inline-flex;
        align-items: center;
        gap: 10px;
        box-shadow: 0 6px 16px rgba(7, 71, 89, 0.24);
        transition: all 0.25s ease;
    }
    .btn-simpan-utama:hover {
        background: linear-gradient(90deg, #22b8cf 0%, #1da1b8 100%);
        color: #ffffff;
        transform: translateY(-2px);
        box-shadow: 0 8px 22px rgba(32, 180, 203, 0.35);
    }

    /* ================= MODAL SUKSES 3 DETIK ================= */
    .modal-check-anim {
        width: 84px;
        height: 84px;
        border-radius: 50%;
        background: #dcfce7;
        color: #16a34a;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 46px;
        margin: 0 auto 18px;
        box-shadow: 0 0 0 12px rgba(22, 163, 74, 0.09);
        animation: modalPop 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
    }
    @keyframes modalPop {
        0% { transform: scale(0.45); opacity: 0; }
        100% { transform: scale(1); opacity: 1; }
    }
    .timer-track {
        width: 100%;
        height: 5px;
        background: #e2e8f0;
        border-radius: 99px;
        overflow: hidden;
        margin-top: 22px;
    }
    .timer-fill {
        height: 100%;
        width: 100%;
        background: linear-gradient(90deg, #16a34a, #20b4cb);
        transform-origin: left;
    }
    @keyframes timerCount {
        from { transform: scaleX(1); }
        to { transform: scaleX(0); }
    }
</style>

<!-- HEADER PENGATURAN -->
<div class="cfg-header-box">
    <div class="d-flex align-items-center gap-3">
        <div class="cfg-title-icon">
            <i class="bi bi-sliders"></i>
        </div>
        <div>
            <h5 class="fw-extrabold text-dark mb-1" style="letter-spacing: -0.2px;">Pengaturan Kode Klasifikasi Surat</h5>
            <p class="text-secondary mb-0" style="font-size: 13px;">
                Atur kode klasifikasi penomoran surat dinas sekolah untuk dokumen BAST dan Pengembalian Barang.
            </p>
        </div>
    </div>
    <div class="cfg-sync-chip">
        <i class="bi bi-clock-history text-primary"></i>
        <span>Diperbarui: <strong id="labelLastUpdated" class="text-dark"><?= $updated_at; ?></strong></span>
    </div>
</div>

<!-- ALERT ERROR (HANYA MUNCUL JIKA GAGAL SERVER) -->
<div id="alertPengaturan" class="d-none mb-4"></div>

<!-- FORM KONFIGURASI -->
<form id="formPengaturanSurat">
    <input type="hidden" name="simpan_pengaturan" value="1">

    <div class="row g-4 mb-4">
        <!-- KARTU KLASIFIKASI BAST -->
        <div class="col-md-6">
            <div class="cfg-card cfg-card-accent-blue">
                <div class="d-flex align-items-center justify-content-between mb-2">
                    <span class="cfg-card-badge badge-bast">
                        <i class="bi bi-file-earmark-text-fill"></i> Dokumen Penyaluran
                    </span>
                    <i class="bi bi-journal-check text-primary opacity-25 fs-3"></i>
                </div>

                <h6 class="fw-bold text-dark mt-2 mb-1" style="font-size: 16.5px;">Kode Klasifikasi BAST</h6>
                <p class="text-secondary mb-0" style="font-size: 12.5px;">
                    Kode klasifikasi untuk Berita Acara Serah Terima (BAST) penyaluran aset sekolah.
                </p>

                <div class="cfg-input-wrapper">
                    <i class="bi bi-tag-fill cfg-input-icon"></i>
                    <input type="text" 
                           class="cfg-input-field" 
                           id="kode_bast" 
                           name="kode_bast" 
                           placeholder="Ketik kode klasifikasi BAST..." 
                           value="<?= htmlspecialchars($kode_bast); ?>" 
                           required>
                </div>

                <div class="d-flex align-items-center gap-2 text-muted" style="font-size: 12px;">
                    <i class="bi bi-info-circle-fill text-primary"></i>
                    <span>Bebas disesuaikan dengan tata naskah dinas sekolah.</span>
                </div>
            </div>
        </div>

        <!-- KARTU KLASIFIKASI PENGEMBALIAN -->
        <div class="col-md-6">
            <div class="cfg-card cfg-card-accent-green">
                <div class="d-flex align-items-center justify-content-between mb-2">
                    <span class="cfg-card-badge badge-pengembalian">
                        <i class="bi bi-arrow-repeat"></i> Dokumen Pengembalian
                    </span>
                    <i class="bi bi-journal-arrow-up text-success opacity-25 fs-3"></i>
                </div>

                <h6 class="fw-bold text-dark mt-2 mb-1" style="font-size: 16.5px;">Kode Klasifikasi Pengembalian</h6>
                <p class="text-secondary mb-0" style="font-size: 12.5px;">
                    Kode klasifikasi untuk Berita Acara Pengembalian barang inventaris sekolah.
                </p>

                <div class="cfg-input-wrapper">
                    <i class="bi bi-tag-fill cfg-input-icon"></i>
                    <input type="text" 
                           class="cfg-input-field" 
                           id="kode_pengembalian" 
                           name="kode_pengembalian" 
                           placeholder="Ketik kode klasifikasi pengembalian..." 
                           value="<?= htmlspecialchars($kode_pengembalian); ?>" 
                           required>
                </div>

                <div class="d-flex align-items-center gap-2 text-muted" style="font-size: 12px;">
                    <i class="bi bi-info-circle-fill text-success"></i>
                    <span>Otomatis digunakan saat mencetak surat pengembalian.</span>
                </div>
            </div>
        </div>
    </div>

    <!-- PANEL DATA TERSIMPAN & TOMBOL SIMPAN -->
    <div class="cfg-bottom-panel">
        <div class="d-flex align-items-center gap-2 mb-3">
            <i class="bi bi-database-check text-info fs-5"></i>
            <span class="fw-bold text-dark" style="font-size: 14px;">Data Klasifikasi Tersimpan Saat Ini</span>
        </div>

        <div class="row g-3 mb-4">
            <div class="col-md-6">
                <div class="cfg-stored-item">
                    <div class="cfg-stored-dot" style="background: #e6f0ff; color: #1677ff;">
                        <i class="bi bi-file-earmark-check-fill"></i>
                    </div>
                    <div>
                        <small class="text-secondary d-block fw-semibold" style="font-size: 11.5px;">Kode Klasifikasi BAST</small>
                        <div id="previewBast" class="mt-0.5" style="font-size: 15px;">
                            <?php if(!empty($kode_bast)): ?>
                                <span class="fw-bold text-primary"><?= htmlspecialchars($kode_bast); ?></span>
                            <?php else: ?>
                                <span class="text-muted fst-italic" style="font-size: 13px;">Belum diatur oleh sekolah</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-md-6">
                <div class="cfg-stored-item">
                    <div class="cfg-stored-dot" style="background: #dcfce7; color: #16a34a;">
                        <i class="bi bi-arrow-left-right"></i>
                    </div>
                    <div>
                        <small class="text-secondary d-block fw-semibold" style="font-size: 11.5px;">Kode Klasifikasi Pengembalian</small>
                        <div id="previewPengembalian" class="mt-0.5" style="font-size: 15px;">
                            <?php if(!empty($kode_pengembalian)): ?>
                                <span class="fw-bold text-success"><?= htmlspecialchars($kode_pengembalian); ?></span>
                            <?php else: ?>
                                <span class="text-muted fst-italic" style="font-size: 13px;">Belum diatur oleh sekolah</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="d-flex justify-content-end pt-3 border-top">
            <button type="submit" class="btn btn-simpan-utama" id="btnSimpanPengaturan">
                <i class="bi bi-check2-circle fs-5"></i>
                <span>Simpan Pengaturan</span>
            </button>
        </div>
    </div>
</form>

<!-- ================= MODAL SUKSES AUTO CLOSE 3 DETIK ================= -->
<div class="modal fade" id="modalSuksesPengaturan" data-bs-backdrop="static" data-bs-keyboard="false" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" style="max-width: 390px;">
        <div class="modal-content border-0 shadow-lg" style="border-radius: 24px;">
            <div class="modal-body p-4 p-md-5 text-center">
                <div class="modal-check-anim">
                    <i class="bi bi-check-lg"></i>
                </div>
                <h5 class="fw-extrabold text-dark mb-2">Kode Klasifikasi Berhasil Disimpan</h5>
                <p class="text-secondary mb-0" style="font-size: 13.5px;">
                    Pengaturan klasifikasi surat telah tersimpan ke dalam database.
                </p>
                <div class="timer-track">
                    <div class="timer-fill" id="modalTimerProgress"></div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    (function() {
        const inputBast = document.getElementById('kode_bast');
        const inputPengembalian = document.getElementById('kode_pengembalian');
        const previewBast = document.getElementById('previewBast');
        const previewPengembalian = document.getElementById('previewPengembalian');
        const labelLastUpdated = document.getElementById('labelLastUpdated');

        let autoCloseTimer = null;

        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        const form = document.getElementById('formPengaturanSurat');
        const alertBox = document.getElementById('alertPengaturan');
        const btnSimpan = document.getElementById('btnSimpanPengaturan');

        if (form) {
            form.addEventListener('submit', function(e) {
                e.preventDefault();

                const originalBtnHtml = btnSimpan.innerHTML;
                btnSimpan.disabled = true;
                btnSimpan.innerHTML = '<span class="spinner-border spinner-border-sm"></span><span>Menyimpan...</span>';
                alertBox.classList.add('d-none');

                const formData = new FormData(form);

                fetch('pengaturan.php', {
                    method: 'POST',
                    body: formData
                })
                .then(res => res.json())
                .then(data => {
                    if (data.status === 'success') {
                        const valBast = inputBast.value.trim();
                        const valPengembalian = inputPengembalian.value.trim();

                        previewBast.innerHTML = valBast 
                            ? `<span class="fw-bold text-primary">${escapeHtml(valBast)}</span>` 
                            : `<span class="text-muted fst-italic" style="font-size: 13px;">Belum diatur oleh sekolah</span>`;

                        previewPengembalian.innerHTML = valPengembalian 
                            ? `<span class="fw-bold text-success">${escapeHtml(valPengembalian)}</span>` 
                            : `<span class="text-muted fst-italic" style="font-size: 13px;">Belum diatur oleh sekolah</span>`;

                        if (labelLastUpdated && data.updated_at) {
                            labelLastUpdated.textContent = data.updated_at;
                        }

                        // Tampilkan Modal Sukses selama 3 detik lalu otomatis hilang
                        const modalSuksesEl = document.getElementById('modalSuksesPengaturan');
                        const progressBar = document.getElementById('modalTimerProgress');
                        
                        if (modalSuksesEl) {
                            const modalSukses = bootstrap.Modal.getOrCreateInstance(modalSuksesEl);
                            
                            if (progressBar) {
                                progressBar.style.animation = 'none';
                                progressBar.offsetHeight;
                                progressBar.style.animation = 'timerCount 3s linear forwards';
                            }

                            modalSukses.show();

                            if (autoCloseTimer) clearTimeout(autoCloseTimer);
                            autoCloseTimer = setTimeout(() => {
                                modalSukses.hide();
                            }, 3000);
                        }
                    } else {
                        alertBox.className = 'alert alert-danger border-0 shadow-sm rounded-3 d-flex align-items-center gap-2 mb-4';
                        alertBox.innerHTML = `<i class="bi bi-exclamation-triangle-fill fs-5"></i> <div>${data.message}</div>`;
                    }
                })
                .catch(err => {
                    alertBox.className = 'alert alert-danger border-0 shadow-sm rounded-3 d-flex align-items-center gap-2 mb-4';
                    alertBox.innerHTML = `<i class="bi bi-exclamation-triangle-fill fs-5"></i> <div>Terjadi kesalahan koneksi saat menyimpan pengaturan.</div>`;
                })
                .finally(() => {
                    btnSimpan.disabled = false;
                    btnSimpan.innerHTML = originalBtnHtml;
                });
            });
        }
    })();
</script>