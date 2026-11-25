<?php
session_start();
include "koneksi.php";

// 1. Cek Login
if(!isset($_SESSION['id_sekolah'])){
    die("Sesi berakhir, silakan login kembali.");
}

$id_sekolah = $_SESSION['id_sekolah'];

// 2. Ambil Data Pejabat (Kepala Sekolah) dari database
$qPejabat = mysqli_query($conn, "SELECT nama_kepala_sekolah, nip_kepala_sekolah FROM kop_surat WHERE id_sekolah = '$id_sekolah' LIMIT 1");
$pejabat  = mysqli_fetch_assoc($qPejabat);

$nama_ks = $pejabat['nama_kepala_sekolah'] ?? "Nama Belum Diatur";
$nip_ks  = $pejabat['nip_kepala_sekolah'] ?? "-";

// 3. Ambil Daftar Tahun Perolehan Unik dari tabel data_barang (Diurutkan dari MUDA ke TUA)
$qTahun = mysqli_query($conn, "SELECT DISTINCT tahun_perolehan 
                               FROM data_barang 
                               WHERE id_sekolah = '$id_sekolah' 
                                 AND tahun_perolehan IS NOT NULL 
                                 AND tahun_perolehan != '' 
                                 AND tahun_perolehan != '0' 
                               ORDER BY CAST(tahun_perolehan AS UNSIGNED) DESC");
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cetak Buku Inventaris - SINVENTARIS</title>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">

    <style>
        :root {
            --navy-deep: #0b2d5b;
            --teal-mid: #0a5f75;
            --cyan-bright: #0ea5b7;
            --text-dark: #0f172a;
            --border-soft: #cbd5e1;
        }

        body { 
            background: linear-gradient(180deg, #f1f6fb 0%, #e5eef8 100%);
            font-family: 'Plus Jakarta Sans', sans-serif; 
            color: var(--text-dark);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0;
            padding: 16px;
        }

        .card-custom {
            width: 100%;
            max-width: 500px;
            border: 1px solid #dbe5f1;
            border-radius: 22px;
            box-shadow: 0 18px 40px -10px rgba(11, 45, 91, 0.14);
            background: #ffffff;
            overflow: hidden;
        }

        /* ================= HEADER GRADASI & WAVE KANAN ================= */
        .header-section {
            position: relative;
            background: linear-gradient(95deg, #0b2e5e 0%, #094a6e 52%, #0b7a8c 85%, #12a4b0 100%);
            padding: 24px 28px;
            color: #ffffff;
            display: flex;
            align-items: center;
            overflow: hidden;
        }

        /* Wave dekoratif kanan bawah header */
        .header-section::before {
            content: '';
            position: absolute;
            right: -12%;
            bottom: -24px;
            width: 52%;
            height: 68px;
            background: linear-gradient(135deg, rgba(45, 212, 191, 0.45) 0%, rgba(16, 185, 129, 0.25) 100%);
            border-radius: 50% 50% 0 0;
            transform: rotate(-8deg);
            pointer-events: none;
        }

        .header-section::after {
            content: '';
            position: absolute;
            right: -6%;
            bottom: -32px;
            width: 38%;
            height: 55px;
            background: rgba(255, 255, 255, 0.22);
            border-radius: 50% 50% 0 0;
            transform: rotate(-4deg);
            pointer-events: none;
        }

        .header-icon-box {
            width: 54px;
            height: 54px;
            border-radius: 14px;
            background: rgba(255, 255, 255, 0.12);
            border: 1px solid rgba(255, 255, 255, 0.18);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.65rem;
            color: #ffffff;
            flex-shrink: 0;
            position: relative;
            z-index: 2;
        }

        .header-divider {
            width: 1.5px;
            height: 46px;
            background: rgba(255, 255, 255, 0.65);
            margin: 0 18px;
            position: relative;
            z-index: 2;
        }

        .header-section .title-wrap {
            position: relative;
            z-index: 2;
        }
        .header-section h4 {
            font-weight: 800;
            margin-bottom: 3px;
            font-size: 1.45rem;
            letter-spacing: -0.2px;
            line-height: 1.15;
        }
        .header-section p {
            color: rgba(255, 255, 255, 0.92);
            font-size: 0.92rem;
            font-weight: 500;
            margin-bottom: 0;
        }

        /* ================= BODY FORM ================= */
        .card-body-content {
            padding: 26px 28px 28px;
            background: #ffffff;
        }

        .field-row-group {
            display: flex;
            align-items: flex-start;
            gap: 14px;
            margin-bottom: 20px;
        }

        .side-badge-icon {
            width: 38px;
            height: 38px;
            border-radius: 10px;
            background: #eef4fc;
            color: #1d4ed8;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.15rem;
            flex-shrink: 0;
            margin-top: 2px;
        }

        .field-input-col {
            flex: 1;
        }

        .form-label-modern {
            display: block;
            font-weight: 700;
            color: #0f172a;
            font-size: 0.95rem;
            margin-bottom: 7px;
        }

        .input-box-clean {
            background: #f8fafc;
            border: 1.5px solid #cbd5e1;
            border-radius: 11px;
            transition: all 0.2s ease;
            overflow: hidden;
        }

        .input-box-clean:focus-within {
            border-color: #0284c7;
            background: #ffffff;
            box-shadow: 0 0 0 3px rgba(2, 132, 199, 0.12);
        }

        .form-control-custom {
            width: 100%;
            border: none;
            background: transparent;
            padding: 11px 15px;
            outline: none;
            font-weight: 600;
            font-size: 0.96rem;
            color: #0f172a;
            font-family: inherit;
        }

        .form-control-custom option {
            font-size: 0.95rem;
            font-weight: 600;
            color: #0f172a;
            background-color: #ffffff;
            padding: 8px 12px;
            font-family: inherit;
        }

        /* ================= BAR PENANDA TANGAN ================= */
        .signer-banner-bar {
            background: #eaf1fb;
            border-radius: 10px;
            padding: 8px 14px;
            display: flex;
            align-items: center;
            gap: 10px;
            margin: 6px 0 16px;
        }

        .signer-banner-bar i {
            color: #1d4ed8;
            font-size: 1.15rem;
        }

        .signer-banner-bar span {
            font-size: 0.95rem;
            font-weight: 700;
            color: #0f172a;
        }

        /* ================= KARTU PEJABAT KEPALA SEKOLAH ================= */
        .pejabat-box {
            background: #f0f9f8;
            border-radius: 14px;
            padding: 16px 18px;
            border: 1.5px solid #99d5cf;
            display: flex;
            align-items: center;
            gap: 16px;
        }

        .pejabat-icon {
            width: 52px;
            height: 52px;
            background: #ffffff;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            border: 1px solid #dbeafe;
            flex-shrink: 0;
            box-shadow: 0 3px 8px rgba(15, 23, 42, 0.04);
        }

        .pejabat-icon i {
            color: #1d4ed8;
            font-size: 1.5rem;
        }

        .pejabat-info .title {
            font-size: 0.72rem;
            text-transform: uppercase;
            font-weight: 700;
            color: #475569;
            letter-spacing: 0.4px;
            margin-bottom: 3px;
        }
        .pejabat-info .name {
            font-size: 1.02rem;
            font-weight: 800;
            color: #0f172a;
            margin-bottom: 3px;
            line-height: 1.25;
            text-transform: uppercase;
        }
        .pejabat-info .nip {
            font-size: 0.85rem;
            font-weight: 500;
            color: #475569;
            margin-bottom: 0;
        }

        /* ================= TOMBOL GENERATE LAPORAN PDF ================= */
        .btn-generate {
            background: linear-gradient(95deg, #0b2e5e 0%, #095276 50%, #0d9488 100%);
            border: none;
            border-radius: 13px;
            padding: 14px 20px;
            color: #ffffff;
            font-weight: 800;
            font-size: 1rem;
            letter-spacing: 0.3px;
            transition: all 0.22s ease;
            margin-top: 22px;
            width: 100%;
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 14px;
            box-shadow: 0 10px 22px -6px rgba(11, 46, 94, 0.35);
            cursor: pointer;
        }

        .btn-generate:hover {
            transform: translateY(-2px);
            box-shadow: 0 14px 26px -6px rgba(11, 46, 94, 0.45);
            filter: brightness(1.04);
        }

        .btn-generate .pdf-icon-wrap {
            display: inline-flex;
            align-items: center;
            font-size: 1.45rem;
        }

        .btn-generate .btn-v-divider {
            width: 1.5px;
            height: 24px;
            background: rgba(255, 255, 255, 0.65);
        }
    </style>
</head>

<body>

    <div class="card-custom">
        <div class="header-section">
            <div class="header-icon-box">
                <i class="bi bi-printer"></i>
            </div>
            <div class="header-divider"></div>
            <div class="title-wrap">
                <h4>Buku Inventaris</h4>
                <p>Cetak laporan aset sekolah</p>
            </div>
        </div>

        <div class="card-body-content">
            <form action="buku_inventaris_pdf.php" method="POST" target="_blank">
                
                <div class="field-row-group">
                    <div class="side-badge-icon">
                        <i class="bi bi-calendar-event"></i>
                    </div>
                    <div class="field-input-col">
                        <label class="form-label-modern">Tanggal Laporan</label>
                        <div class="input-box-clean">
                            <input type="date" name="tgl_cetak" class="form-control-custom" value="<?= date('Y-m-d'); ?>" required>
                        </div>
                    </div>
                </div>

                <div class="field-row-group">
                    <div class="side-badge-icon">
                        <i class="bi bi-layers"></i>
                    </div>
                    <div class="field-input-col">
                        <label class="form-label-modern">Tahun Perolehan</label>
                        <div class="input-box-clean">
                            <select name="tahun_perolehan" class="form-control-custom">
                                <option value="">-- Semua Tahun --</option>
                                <?php 
                                if ($qTahun && mysqli_num_rows($qTahun) > 0) {
                                    while ($thn = mysqli_fetch_assoc($qTahun)) {
                                        echo '<option value="' . htmlspecialchars($thn['tahun_perolehan']) . '">' . htmlspecialchars($thn['tahun_perolehan']) . '</option>';
                                    }
                                }
                                ?>
                            </select>
                        </div>
                    </div>
                </div>

                <div class="field-row-group">
                    <div class="side-badge-icon">
                        <i class="bi bi-calendar2-range"></i>
                    </div>
                    <div class="field-input-col">
                        <label class="form-label-modern">Semester</label>
                        <div class="input-box-clean">
                            <select name="semester" class="form-control-custom">
                                <option value="">-- Semua Semester (Jan - Des) --</option>
                                <option value="1">Semester 1 (Januari - Juni)</option>
                                <option value="2">Semester 2 (Juli - Desember)</option>
                            </select>
                        </div>
                    </div>
                </div>

                <div class="signer-banner-bar">
                    <i class="bi bi-person-check"></i>
                    <span>Penanda Tangan</span>
                </div>

                <div class="pejabat-box">
                    <div class="pejabat-icon">
                        <i class="bi bi-person-fill"></i>
                    </div>
                    <div class="pejabat-info">
                        <div class="title">Kepala Sekolah</div>
                        <div class="name"><?= $nama_ks; ?></div>
                        <div class="nip">NIP. <?= $nip_ks; ?></div>
                    </div>
                </div>

                <button type="submit" class="btn-generate">
                    <span class="pdf-icon-wrap"><i class="bi bi-file-earmark-pdf-fill"></i></span>
                    <span class="btn-v-divider"></span>
                    <span>GENERATE LAPORAN PDF</span>
                </button>

            </form>
        </div>
    </div>

</body>
</html>