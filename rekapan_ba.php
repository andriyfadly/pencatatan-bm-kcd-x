<?php
session_start();

if(!isset($_SESSION['id_user'])){
    header("Location: login.php");
    exit;
}

include "koneksi.php";
$id_sekolah = $_SESSION['id_sekolah'];

// ================= AMBIL DATA REKAPAN BAST =================
// Diurutkan berdasarkan tanggal secara Ascending (ASC) dan no_reg secara Ascending (ASC)
$query_ba = mysqli_query($conn, "SELECT id_history, nomor_surat, nama_barang, no_reg, kondisi, nama_penerima, tanggal 
                                 FROM history_bast 
                                 WHERE id_sekolah = '$id_sekolah' 
                                 ORDER BY tanggal ASC, CAST(no_reg AS UNSIGNED) ASC");
?>

<div class="container-fluid p-0">
    <div class="row mb-4">
        <div class="col-12">
            <div class="card card-box" style="border: none; border-radius: 20px; background: #ffffff; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1); padding: 25px; border: 1px solid #e2e8f0;">
                
                <div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-4">
                    <div>
                        <h5 class="fw-bold text-dark mb-1"><i class="bi bi-file-earmark-text-fill text-primary me-2"></i> Rekapan Berita Acara (BAST)</h5>
                        <p class="text-secondary small mb-0">Daftar rekapan nomor surat BAST otomatis dan unduh laporan Excel.</p>
                    </div>
                    <div class="d-flex align-items-center gap-2 flex-wrap">
                        <div class="position-relative">
                            <i class="bi bi-search position-absolute top-50 translate-middle-y text-secondary" style="left: 15px;"></i>
                            <input type="text" id="input-search-penerima" class="form-control ps-5" placeholder="Cari nama penerima..." style="border-radius: 12px; font-size: 14px; height: 40px; min-width: 220px; border: 1px solid #cbd5e1;">
                        </div>
                        <button type="button" id="btn-export-excel" class="btn btn-success px-3 py-2 d-flex align-items-center gap-2" style="border-radius: 12px; font-weight: 600; font-size: 14px; height: 40px;">
                            <i class="bi bi-file-earmark-excel-fill fs-5"></i> Export ke Excel (.xlsx)
                        </button>
                        <div class="bg-blue p-2 rounded-3" style="background: #eff6ff; color: #2563eb; height: 40px; display: flex; align-items: center;">
                            <span class="fw-bold px-2" id="total-ba-count"><?= mysqli_num_rows($query_ba); ?> Total BA</span>
                        </div>
                    </div>
                </div>

                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0" id="table-rekapan" style="border-color: #f1f5f9;">
                        <thead class="table-light" style="background-color: #f8fafc;">
                            <tr class="text-secondary small text-uppercase fw-bold" style="font-size: 12px;">
                                <th class="py-3" width="5%">No</th>
                                <th class="py-3" width="10%">No Urut</th>
                                <th class="py-3" width="25%">Nomor Surat Lengkap</th>
                                <th class="py-3">Nama Barang</th>
                                <th class="py-3">No. Reg</th>
                                <th class="py-3">Kondisi Barang</th>
                                <th class="py-3">Penerima</th>
                                <th class="py-3">Tanggal BA</th>
                            </tr>
                        </thead>
                        <tbody style="font-size: 14px;">
                            <?php 
                            if(mysqli_num_rows($query_ba) > 0):
                                $no = 1;
                                while($row = mysqli_fetch_assoc($query_ba)): 
                                    $nomor_surat_lengkap = trim($row['nomor_surat'] ?? '');
                                    
                                    // Ambil bagian nomor urut sebelum tanda '/' (contoh: "001/421.2/..." -> "001")
                                    $pecah_nomor = explode('/', $nomor_surat_lengkap);
                                    $no_urut = !empty($pecah_nomor[0]) ? trim($pecah_nomor[0]) : '-';

                                    // Terjemahkan kode kondisi dari database
                                    $kode_kondisi = strtoupper(trim($row['kondisi'] ?? ''));
                                    $teks_kondisi = '-';
                                    if ($kode_kondisi === 'B') {
                                        $teks_kondisi = 'Baik';
                                    } elseif ($kode_kondisi === 'RR') {
                                        $teks_kondisi = 'Rusak Ringan';
                                    } elseif ($kode_kondisi === 'RB') {
                                        $teks_kondisi = 'Rusak Berat';
                                    }
                            ?>
                                <tr class="row-data-ba">
                                    <td class="fw-semibold text-secondary row-number"><?= $no++; ?></td>
                                    <td class="fw-bold text-primary"><?= htmlspecialchars($no_urut); ?></td>
                                    <td class="fw-bold text-dark"><?= htmlspecialchars($nomor_surat_lengkap ?: '-'); ?></td>
                                    <td><span class="fw-semibold"><?= htmlspecialchars($row['nama_barang']); ?></span></td>
                                    <td><code class="text-dark bg-light px-2 py-1 rounded"><?= htmlspecialchars($row['no_reg']); ?></code></td>
                                    <td>
                                        <span class="text-secondary fw-semibold"><?= $teks_kondisi; ?></span>
                                    </td>
                                    <td>
                                        <div class="d-flex align-items-center gap-2">
                                            <div class="bg-light text-secondary rounded-circle d-flex align-items-center justify-content-center" style="width: 28px; height: 28px;">
                                                <i class="bi bi-person-fill" style="font-size: 12px;"></i>
                                            </div>
                                            <span class="fw-medium text-nama-penerima"><?= htmlspecialchars($row['nama_penerima']); ?></span>
                                        </div>
                                    </td>
                                    <td class="text-secondary"><?= date('d M Y', strtotime($row['tanggal'])); ?></td>
                                </tr>
                            <?php 
                                endwhile; 
                            else: 
                            ?>
                                <tr id="row-empty-state">
                                    <td colspan="8" class="text-center py-5 text-secondary">
                                        <i class="bi bi-folder-x fs-1 d-block mb-2 text-muted"></i>
                                        Belum ada data history BAST terekam.
                                    </td>
                                </tr>
                            <?php endif; ?>
                            <tr id="row-search-not-found" class="d-none">
                                <td colspan="8" class="text-center py-5 text-secondary">
                                    <i class="bi bi-search fs-1 d-block mb-2 text-muted"></i>
                                    Nama penerima tidak ditemukan dalam rekapan.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

            </div>
        </div>
    </div>
</div>

<script>
    (function() {
        // --- FITUR SEARCH REALTIME DI SISI KLIEN ---
        const searchInput = document.getElementById('input-search-penerima');
        if (searchInput) {
            searchInput.addEventListener('input', function() {
                const keyword = this.value.toLowerCase().trim();
                const rows = document.querySelectorAll('.row-data-ba');
                const notFoundRow = document.getElementById('row-search-not-found');
                const emptyStateRow = document.getElementById('row-empty-state');
                const totalBaBadge = document.getElementById('total-ba-count');
                
                let visibleCount = 0;
                let activeNumbering = 1;

                rows.forEach(row => {
                    const nameText = row.querySelector('.text-nama-penerima').textContent.toLowerCase();
                    if (nameText.includes(keyword)) {
                        row.classList.remove('d-none');
                        const numberCell = row.querySelector('.row-number');
                        if (numberCell) {
                            numberCell.textContent = activeNumbering++;
                        }
                        visibleCount++;
                    } else {
                        row.classList.add('d-none');
                    }
                });

                if (visibleCount === 0 && keyword !== '') {
                    if (notFoundRow) notFoundRow.classList.remove('d-none');
                    if (emptyStateRow) emptyStateRow.classList.add('d-none');
                } else {
                    if (notFoundRow) notFoundRow.classList.add('d-none');
                }

                if (totalBaBadge) {
                    totalBaBadge.textContent = visibleCount + ' Total BA';
                }
            });
        }

        // --- SISTEM INJEKSI & PENGECEKAN ASINKRONUS LIBRARY SHEETJS ---
        function pastikanLibraryExcelLoaded(callback) {
            if (typeof XLSX !== 'undefined') {
                callback();
                return;
            }
            const script = document.createElement('script');
            script.type = 'text/javascript';
            script.src = 'https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js';
            script.onload = function() {
                callback();
            };
            script.onerror = function() {
                alert('Gagal memuat sistem generator Excel dari CDN server. Mohon periksa koneksi internet.');
            };
            document.head.appendChild(script);
        }

        // --- EXPORT EXCEL ---
        document.getElementById('btn-export-excel').addEventListener('click', function() {
            pastikanLibraryExcelLoaded(function() {
                const table = document.getElementById('table-rekapan');
                const rows = table.querySelectorAll('tbody tr.row-data-ba:not(.d-none)');
                
                const dataExcel = [
                    ["No", "Nomor Urut", "Nomor Surat Lengkap", "Nama Barang", "No. Reg", "Kondisi Barang", "Nama Penerima", "Tanggal BA"]
                ];

                rows.forEach((row, index) => {
                    const cols = row.querySelectorAll('td');
                    if (cols.length > 1) {
                        const no = index + 1;
                        const noUrut = cols[1].textContent.trim() === '-' ? '' : cols[1].textContent.trim();
                        const noSurat = cols[2].textContent.trim();
                        const namaBarang = cols[3].textContent.trim();
                        const noReg = cols[4].textContent.trim();
                        const kondisi = cols[5].textContent.trim(); 
                        const penerima = cols[6].textContent.trim();
                        const tanggal = cols[7].textContent.trim();

                        dataExcel.push([no, noUrut, noSurat, namaBarang, noReg, kondisi, penerima, tanggal]);
                    }
                });

                const worksheet = XLSX.utils.aoa_to_sheet(dataExcel);
                const workbook = XLSX.utils.book_new();
                XLSX.utils.book_append_sheet(workbook, worksheet, "Rekapan BAST");

                XLSX.writeFile(workbook, "Laporan_Rekapan_BAST_" + new Date().toISOString().slice(0,10) + ".xlsx");
            });
        });
    })();
</script>