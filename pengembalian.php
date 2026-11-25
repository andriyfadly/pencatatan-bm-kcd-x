<?php
session_start();

if(!isset($_SESSION['id_user'])){
    header("Location: login.php");
    exit;
}

include "koneksi.php";

$id_sekolah = $_SESSION['id_sekolah'];

// =========================================================================
// AMBIL KODE KLASIFIKASI SURAT DARI TABEL pengaturan_surat
// =========================================================================
$kode_klasifikasi = '';
$qPengaturan = mysqli_query($conn, "SELECT * FROM pengaturan_surat WHERE id_sekolah = '$id_sekolah' LIMIT 1");
if ($qPengaturan && mysqli_num_rows($qPengaturan) > 0) {
    $dPengaturan = mysqli_fetch_assoc($qPengaturan);
    // Prioritaskan kode_pengembalian / kode_ba jika ada, jika tidak gunakan kode_bast
    if (!empty(trim($dPengaturan['kode_pengembalian'] ?? ''))) {
        $kode_klasifikasi = trim($dPengaturan['kode_pengembalian']);
    } elseif (!empty(trim($dPengaturan['kode_ba'] ?? ''))) {
        $kode_klasifikasi = trim($dPengaturan['kode_ba']);
    } elseif (!empty(trim($dPengaturan['kode_bast'] ?? ''))) {
        $kode_klasifikasi = trim($dPengaturan['kode_bast']);
    }
}

// =========================================================================
// FUNGSI HELPER GENERATE NOMOR SURAT PENGEMBALIAN OTOMATIS (001/KLASIFIKASI)
// =========================================================================
function generateNomorPengembalianOtomatis($conn, $id_sekolah, $kode_klasifikasi) {
    $lastNum = 0;

    // Pastikan kolom nomor_surat tersedia di tabel history_pengembalian
    $cekCol = mysqli_query($conn, "SHOW COLUMNS FROM history_pengembalian LIKE 'nomor_surat'");
    if ($cekCol && mysqli_num_rows($cekCol) == 0) {
        @mysqli_query($conn, "ALTER TABLE history_pengembalian ADD COLUMN nomor_surat VARCHAR(100) NULL AFTER id_sekolah");
    }

    $qNum = mysqli_query($conn, "SELECT nomor_surat FROM history_pengembalian WHERE id_sekolah = '$id_sekolah'");
    if ($qNum) {
        while ($rNum = mysqli_fetch_assoc($qNum)) {
            $rawNomor = trim($rNum['nomor_surat'] ?? '');
            if ($rawNomor !== '') {
                $firstPart = trim(explode('/', $rawNomor)[0] ?? '');
                if ($firstPart !== '' && ctype_digit($firstPart)) {
                    $val = (int)$firstPart;
                    if ($val > $lastNum) {
                        $lastNum = $val;
                    }
                }
            }
        }
    }

    // Jika belum ada data bernomor, pakai total baris history yang sudah ada + 1 (pertama kali = 001)
    if ($lastNum === 0) {
        $qCount = mysqli_query($conn, "SELECT COUNT(*) AS total FROM history_pengembalian WHERE id_sekolah = '$id_sekolah'");
        if ($qCount && $rCount = mysqli_fetch_assoc($qCount)) {
            $lastNum = (int)$rCount['total'];
        }
    }

    $nextNum = $lastNum + 1;
    $padded = str_pad($nextNum, 3, '0', STR_PAD_LEFT);
    return ($kode_klasifikasi !== '') ? ($padded . '/' . $kode_klasifikasi) : $padded;
}

// =========================================================================
// PROSES AJAX PENGEMBALIAN BARANG (LANGSUNG + SUPPORT RANGE PAKET)
// =========================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'proses_pengembalian_langsung') {
    header('Content-Type: application/json');

    $id_penyaluran   = (int)($_POST['id'] ?? 0);
    $kondisi_kembali = mysqli_real_escape_string($conn, trim($_POST['kondisi_kembali'] ?? 'B'));
    $jenis_kembali   = trim($_POST['jenis_kembali'] ?? 'semua');
    $reg_dari_input  = trim($_POST['reg_dari'] ?? '');
    $reg_sampai_input= trim($_POST['reg_sampai'] ?? '');
    $keterangan      = mysqli_real_escape_string($conn, trim($_POST['keterangan'] ?? ''));

    // Ambil data penyaluran aktif
    $qPenyaluran = mysqli_query($conn, "SELECT * FROM penyaluran WHERE id_penyaluran = '$id_penyaluran' AND id_sekolah = '$id_sekolah' LIMIT 1");
    if (!$qPenyaluran || mysqli_num_rows($qPenyaluran) === 0) {
        echo json_encode(['success' => false, 'message' => 'Data penyaluran tidak ditemukan atau sudah dikembalikan.']);
        exit;
    }

    $dataP = mysqli_fetch_assoc($qPenyaluran);
    $no_reg_asal = trim($dataP['no_reg']);
    $is_paket    = (strpos($no_reg_asal, '-') !== false);

    $reg_dikembalikan = $no_reg_asal;
    $vol_dikembalikan = (int)$dataP['vol_penyaluran'];

    // Validasi & hitung pecahan range jika pengembalian sebagian pada paket
    if ($is_paket && $jenis_kembali === 'sebagian') {
        $pecahAsal = explode('-', $no_reg_asal);
        $asalStart = (int)$pecahAsal[0];
        $asalEnd   = (int)$pecahAsal[1];
        $padLen    = max(3, strlen(trim($pecahAsal[0])));

        if ($reg_dari_input === '' || $reg_sampai_input === '' || !ctype_digit($reg_dari_input) || !ctype_digit($reg_sampai_input)) {
            echo json_encode(['success' => false, 'message' => 'Nomor registrasi awal dan akhir harus diisi dengan angka yang valid!']);
            exit;
        }

        $dariInt   = (int)$reg_dari_input;
        $sampaiInt = (int)$reg_sampai_input;

        if ($dariInt > $sampaiInt) {
            echo json_encode(['success' => false, 'message' => 'No. Reg awal (' . $dariInt . ') tidak boleh lebih besar dari No. Reg akhir (' . $sampaiInt . ')!']);
            exit;
        }

        if ($dariInt < $asalStart || $sampaiInt > $asalEnd) {
            echo json_encode(['success' => false, 'message' => 'Rentang (' . $dariInt . '-' . $sampaiInt . ') berada di luar rentang paket aktif (' . $no_reg_asal . ')!']);
            exit;
        }

        $dariPad   = str_pad($dariInt, $padLen, '0', STR_PAD_LEFT);
        $sampaiPad = str_pad($sampaiInt, $padLen, '0', STR_PAD_LEFT);

        $reg_dikembalikan = ($dariInt === $sampaiInt) ? $dariPad : ($dariPad . '-' . $sampaiPad);
        $vol_dikembalikan = ($sampaiInt - $dariInt) + 1;

        // Jika ternyata rentang yang dipilih mencakup seluruh paket
        if ($dariInt === $asalStart && $sampaiInt === $asalEnd) {
            $jenis_kembali = 'semua';
        }
    }

    // Generate nomor surat pengembalian otomatis (001/klasifikasi)
    $nomor_surat_baru = generateNomorPengembalianOtomatis($conn, $id_sekolah, $kode_klasifikasi);

    // Cek struktur kolom history_pengembalian agar insert kompatibel dengan skema DB yang ada
    $colsHistory = [];
    $qCols = mysqli_query($conn, "SHOW COLUMNS FROM history_pengembalian");
    while ($c = mysqli_fetch_assoc($qCols)) {
        $colsHistory[] = $c['Field'];
    }

    $insertFields = [];
    $insertValues = [];

    $kandidatData = [
        'id_sekolah'      => $id_sekolah,
        'nomor_surat'     => $nomor_surat_baru,
        'id_penyaluran'   => $dataP['id_penyaluran'],
        'id_barang'       => $dataP['id_barang'] ?? 0,
        'nama_barang'     => $dataP['nama_barang'] ?? '',
        'merk_tipe'       => $dataP['merk_tipe'] ?? '',
        'kode_barang'     => $dataP['kode_barang'] ?? '',
        'grid_barang'     => $dataP['kode_barang'] ?? '',
        'tgl_pengadaan'   => $dataP['tgl_pengadaan'] ?? '',
        'bln_pengadaan'   => $dataP['bln_pengadaan'] ?? '',
        'thn_pengadaan'   => $dataP['thn_pengadaan'] ?? '',
        'vol_pengadaan'   => $dataP['vol_pengadaan'] ?? 0,
        'sat_pengadaan'   => $dataP['sat_pengadaan'] ?? '',
        'harga_satuan'    => $dataP['harga_satuan'] ?? 0,
        'lokasi'          => $dataP['lokasi'] ?? '',
        'vol_penyaluran'  => $vol_dikembalikan,
        'nama_penerima'   => $dataP['nama_penerima'] ?? '',
        'no_reg'          => $reg_dikembalikan,
        'kondisi'         => $kondisi_kembali,
        'kondisi_kembali' => $kondisi_kembali,
        'keterangan'      => $keterangan,
        'tgl_kembali'     => date('Y-m-d H:i:s')
    ];

    foreach ($kandidatData as $colName => $colVal) {
        if (in_array($colName, $colsHistory)) {
            $insertFields[] = "`$colName`";
            $insertValues[] = "'" . mysqli_real_escape_string($conn, (string)$colVal) . "'";
        }
    }

    $sqlInsertHistory = "INSERT INTO history_pengembalian (" . implode(', ', $insertFields) . ") VALUES (" . implode(', ', $insertValues) . ")";
    if (!mysqli_query($conn, $sqlInsertHistory)) {
        echo json_encode(['success' => false, 'message' => 'Gagal menyimpan riwayat pengembalian: ' . mysqli_error($conn)]);
        exit;
    }

    $id_history_baru = mysqli_insert_id($conn);

    // Update kondisi barang di tabel kondisi_barang_detail jika tersedia
    if (!empty($dataP['id_barang'])) {
        $id_brg = (int)$dataP['id_barang'];
        if (strpos($reg_dikembalikan, '-') !== false) {
            list($rStart, $rEnd) = explode('-', $reg_dikembalikan);
            $rStartInt = (int)$rStart;
            $rEndInt   = (int)$rEnd;
            @mysqli_query($conn, "UPDATE kondisi_barang_detail SET kondisi = '$kondisi_kembali' WHERE id_barang = '$id_brg' AND CAST(no_reg AS UNSIGNED) BETWEEN $rStartInt AND $rEndInt");
        } else {
            $rSingleInt = (int)$reg_dikembalikan;
            @mysqli_query($conn, "UPDATE kondisi_barang_detail SET kondisi = '$kondisi_kembali' WHERE id_barang = '$id_brg' AND CAST(no_reg AS UNSIGNED) = $rSingleInt");
        }
    }

    // Perbarui / Hapus data pada tabel penyaluran
    if (!$is_paket || $jenis_kembali === 'semua') {
        mysqli_query($conn, "DELETE FROM penyaluran WHERE id_penyaluran = '$id_penyaluran' AND id_sekolah = '$id_sekolah'");
    } else {
        // Pecah rentang paket yang tersisa (sisa kiri dan/atau sisa kanan)
        $pecahAsal = explode('-', $no_reg_asal);
        $asalStart = (int)$pecahAsal[0];
        $asalEnd   = (int)$pecahAsal[1];
        $padLen    = max(3, strlen(trim($pecahAsal[0])));
        $dariInt   = (int)$reg_dari_input;
        $sampaiInt = (int)$reg_sampai_input;

        $sisaRanges = [];
        // Sisa bagian kiri (sebelum dariInt)
        if ($dariInt > $asalStart) {
            $kiriStart = $asalStart;
            $kiriEnd   = $dariInt - 1;
            $kiriReg   = ($kiriStart === $kiriEnd)
                ? str_pad($kiriStart, $padLen, '0', STR_PAD_LEFT)
                : str_pad($kiriStart, $padLen, '0', STR_PAD_LEFT) . '-' . str_pad($kiriEnd, $padLen, '0', STR_PAD_LEFT);
            $sisaRanges[] = [
                'no_reg' => $kiriReg,
                'vol'    => ($kiriEnd - $kiriStart) + 1
            ];
        }

        // Sisa bagian kanan (setelah sampaiInt)
        if ($sampaiInt < $asalEnd) {
            $kananStart = $sampaiInt + 1;
            $kananEnd   = $asalEnd;
            $kananReg   = ($kananStart === $kananEnd)
                ? str_pad($kananStart, $padLen, '0', STR_PAD_LEFT)
                : str_pad($kananStart, $padLen, '0', STR_PAD_LEFT) . '-' . str_pad($kananEnd, $padLen, '0', STR_PAD_LEFT);
            $sisaRanges[] = [
                'no_reg' => $kananReg,
                'vol'    => ($kananEnd - $kananStart) + 1
            ];
        }

        if (count($sisaRanges) >= 1) {
            // Update baris pertama dengan sisa pertama
            $firstSisa = $sisaRanges[0];
            $reg1 = mysqli_real_escape_string($conn, $firstSisa['no_reg']);
            $vol1 = (int)$firstSisa['vol'];
            mysqli_query($conn, "UPDATE penyaluran SET no_reg = '$reg1', vol_penyaluran = '$vol1' WHERE id_penyaluran = '$id_penyaluran' AND id_sekolah = '$id_sekolah'");

            // Jika ada sisa kedua (terpotong di tengah), insert baris baru untuk sisa kedua
            if (count($sisaRanges) === 2) {
                $secondSisa = $sisaRanges[1];
                $reg2 = mysqli_real_escape_string($conn, $secondSisa['no_reg']);
                $vol2 = (int)$secondSisa['vol'];

                $stmtClone = mysqli_prepare($conn,
                    "INSERT INTO penyaluran 
                    (id_sekolah, id_barang, nama_barang, merk_tipe, kode_barang, tgl_pengadaan, bln_pengadaan, thn_pengadaan, vol_pengadaan, sat_pengadaan, harga_satuan, lokasi, vol_penyaluran, nama_penerima, no_reg, keterangan)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
                );
                mysqli_stmt_bind_param(
                    $stmtClone,
                    "ssssssssssssssss",
                    $dataP['id_sekolah'],
                    $dataP['id_barang'],
                    $dataP['nama_barang'],
                    $dataP['merk_tipe'],
                    $dataP['kode_barang'],
                    $dataP['tgl_pengadaan'],
                    $dataP['bln_pengadaan'],
                    $dataP['thn_pengadaan'],
                    $dataP['vol_pengadaan'],
                    $dataP['sat_pengadaan'],
                    $dataP['harga_satuan'],
                    $dataP['lokasi'],
                    $vol2,
                    $dataP['nama_penerima'],
                    $reg2,
                    $dataP['keterangan']
                );
                mysqli_stmt_execute($stmtClone);
            }
        }
    }

    echo json_encode([
        'success'     => true,
        'id_history'  => $id_history_baru,
        'nomor_surat' => $nomor_surat_baru,
        'no_reg'      => $reg_dikembalikan
    ]);
    exit;
}

// =========================================================================
// PREVIEW NOMOR SURAT PENGEMBALIAN BERIKUTNYA UNTUK TAMPILAN MODAL
// =========================================================================
$preview_nomor_surat = generateNomorPengembalianOtomatis($conn, $id_sekolah, $kode_klasifikasi);

// =========================================================================
// QUERY UTAMA DATA BARANG DISALURKAN (LANGSUNG BISA DIKEMBALIKAN TANPA CEK NO URUT BA)
// =========================================================================
$query = "SELECT p.*
          FROM penyaluran p
          WHERE p.id_sekolah = '$id_sekolah' 
          AND EXISTS (
              SELECT 1 
              FROM history_bast hb 
              WHERE p.nama_barang = hb.nama_barang 
                AND p.id_sekolah = hb.id_sekolah
                AND p.nama_penerima = hb.nama_penerima
                AND (
                    p.no_reg = hb.no_reg 
                    OR 
                    (INSTR(hb.no_reg, '-') > 0 AND (
                        CAST(SUBSTRING_INDEX(p.no_reg, '-', 1) AS UNSIGNED) >= CAST(SUBSTRING_INDEX(hb.no_reg, '-', 1) AS UNSIGNED)
                        AND 
                        CAST(SUBSTRING_INDEX(p.no_reg, '-', -1) AS UNSIGNED) <= CAST(SUBSTRING_INDEX(hb.no_reg, '-', -1) AS UNSIGNED)
                    ))
                )
          )
          AND NOT EXISTS (
              SELECT 1 
              FROM history_pengembalian hp 
              WHERE hp.id_sekolah = '$id_sekolah'
                AND hp.nama_barang = p.nama_barang
                AND hp.nama_penerima = p.nama_penerima
                AND hp.no_reg = p.no_reg
          )
          ORDER BY p.id_penyaluran DESC";
$result = mysqli_query($conn, $query);

// Ambil semua data history pengembalian untuk modal riwayat komplit
$q_history_all = mysqli_query($conn, "SELECT * FROM history_pengembalian WHERE id_sekolah = '$id_sekolah' ORDER BY id_history DESC");
$arr_history = [];
while($h_row = mysqli_fetch_assoc($q_history_all)) {
    $arr_history[] = $h_row;
}
?>

<style>
    /* ================= UI THEME PENGEMBALIAN BARANG ================= */
    .pengembalian-page-wrap {
        position: relative;
        background: linear-gradient(180deg, #eff7fd 0%, #e1f1fb 65%, #d3ecfa 100%);
        border-radius: 20px;
        padding: 24px 26px 46px;
        overflow: hidden;
        min-height: calc(100vh - 110px);
    }

    .pengembalian-page-wrap::before {
        content: '';
        position: absolute;
        top: -40px;
        right: -5%;
        width: 55%;
        height: 150px;
        background: radial-gradient(ellipse at center, rgba(125, 211, 252, 0.35) 0%, rgba(186, 230, 253, 0) 70%);
        border-radius: 50%;
        pointer-events: none;
        z-index: 0;
    }

    .pengembalian-page-wrap::after {
        content: '';
        position: absolute;
        left: -5%;
        right: -5%;
        bottom: -28px;
        height: 75px;
        background: linear-gradient(90deg, #0ea5e9 0%, #14b8a6 48%, #38bdf8 100%);
        opacity: 0.32;
        border-radius: 50% 50% 0 0 / 100% 100% 0 0;
        pointer-events: none;
        z-index: 0;
    }

    .pengembalian-inner {
        position: relative;
        z-index: 2;
    }

    .header-box-icon-teal {
        width: 50px;
        height: 50px;
        border-radius: 13px;
        background: linear-gradient(135deg, #0b5d78 0%, #129490 60%, #1bb89b 100%);
        color: #ffffff;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 24px;
        flex-shrink: 0;
        box-shadow: 0 6px 16px rgba(11, 93, 120, 0.25);
    }

    .page-title-main {
        font-size: 21px;
        font-weight: 800;
        color: #0b3259;
        margin-bottom: 2px;
        letter-spacing: -0.2px;
    }

    .page-subtitle-main {
        font-size: 13px;
        color: #475569;
        font-weight: 500;
        margin-bottom: 0;
    }

    .btn-history-pill {
        background: linear-gradient(135deg, #073968 0%, #0a5f82 100%);
        color: #ffffff !important;
        border: none;
        border-radius: 25px;
        padding: 10px 20px;
        font-size: 13.5px;
        font-weight: 700;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
        white-space: nowrap;
        box-shadow: 0 6px 15px rgba(7, 57, 104, 0.22);
        transition: all 0.2s ease;
    }
    .btn-history-pill:hover {
        background: linear-gradient(135deg, #052a4e 0%, #084d6b 100%);
        transform: translateY(-1px);
        box-shadow: 0 8px 18px rgba(7, 57, 104, 0.3);
    }

    .search-pill-box {
        position: relative;
        flex: 1;
        min-width: 260px;
    }
    .search-pill-box i {
        position: absolute;
        left: 17px;
        top: 50%;
        transform: translateY(-50%);
        color: #475569;
        font-size: 14px;
        z-index: 3;
    }
    .search-pill-field {
        width: 100%;
        background: #ffffff !important;
        border: 1.5px solid #cbd5e1 !important;
        border-radius: 25px !important;
        padding: 9px 18px 9px 42px !important;
        font-size: 13.5px !important;
        font-weight: 500;
        color: #0f172a !important;
        box-shadow: 0 4px 12px rgba(15, 23, 42, 0.04) !important;
    }
    .search-pill-field:focus {
        border-color: #0ea5e9 !important;
        box-shadow: 0 0 0 3px rgba(14, 165, 233, 0.14) !important;
        outline: none;
    }

    .card-table-modern {
        background: #ffffff;
        border: 1px solid #dbe8f4;
        border-radius: 18px;
        padding: 14px;
        box-shadow: 0 12px 30px -6px rgba(7, 57, 104, 0.09);
    }

    #table-pengembalian {
        border-collapse: separate;
        border-spacing: 0;
        width: 100%;
    }

    #table-pengembalian thead tr {
        background: linear-gradient(95deg, #062f59 0%, #09587a 52%, #119498 100%);
    }

    #table-pengembalian thead th {
        background: transparent !important;
        color: #ffffff !important;
        font-size: 12.5px;
        font-weight: 700;
        border: none !important;
        border-right: 1px solid rgba(255, 255, 255, 0.14) !important;
        padding: 12px 12px !important;
        vertical-align: middle;
        white-space: nowrap;
    }
    #table-pengembalian thead th:last-child {
        border-right: none !important;
        border-top-right-radius: 12px;
        border-bottom-right-radius: 12px;
    }
    #table-pengembalian thead th:first-child {
        border-top-left-radius: 12px;
        border-bottom-left-radius: 12px;
    }

    .th-icon-stack {
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        gap: 3px;
        line-height: 1.2;
    }
    .th-icon-stack i {
        font-size: 14px;
        color: #e0f2fe;
    }

    #table-pengembalian tbody tr.searchable-row {
        background-color: #ffffff;
        transition: background-color 0.15s ease;
    }
    #table-pengembalian tbody tr.searchable-row:nth-child(even) {
        background-color: #f3f9fd;
    }
    #table-pengembalian tbody tr.searchable-row:hover {
        background-color: #e7f4fc;
    }

    #table-pengembalian tbody td {
        padding: 14px 12px !important;
        border-bottom: 1px solid #e2ecf5 !important;
        border-right: 1px solid #edf4fa;
        font-size: 13px;
        vertical-align: middle;
    }
    #table-pengembalian tbody td:last-child {
        border-right: none;
    }

    .text-kode {
        color: #1d4ed8 !important;
        font-size: 13px;
    }

    .vol-pill-badge {
        display: inline-block;
        background: #dbeafe;
        color: #1e3a8a;
        font-weight: 700;
        font-size: 12.5px;
        padding: 5px 13px;
        border-radius: 8px;
        white-space: nowrap;
    }

    .btn-kembalikan-red {
        background: linear-gradient(135deg, #e11d48 0%, #dc2626 100%) !important;
        color: #ffffff !important;
        border: none !important;
        border-radius: 10px !important;
        padding: 8px 16px !important;
        font-size: 13px !important;
        font-weight: 700 !important;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 6px;
        box-shadow: 0 4px 10px rgba(220, 38, 38, 0.25) !important;
        transition: all 0.2s;
    }
    .btn-kembalikan-red:hover {
        background: linear-gradient(135deg, #be123c 0%, #b91c1c 100%) !important;
        transform: translateY(-1px);
    }

    /* ================= UI MODAL RIWAYAT LENGKAP PENGEMBALIAN BARANG ================= */
    #modalHistoryAll .modal-content {
        border-radius: 22px !important;
        overflow: hidden;
        border: none !important;
        box-shadow: 0 25px 50px -12px rgba(6, 47, 89, 0.35) !important;
    }

    .history-modal-header {
        position: relative;
        background: linear-gradient(95deg, #062f59 0%, #09587a 52%, #11989b 86%, #1bb5b2 100%);
        padding: 18px 26px;
        color: #ffffff;
        display: flex;
        align-items: center;
        justify-content: space-between;
        overflow: hidden;
    }

    .history-modal-header::before {
        content: '';
        position: absolute;
        right: -5%;
        bottom: -24px;
        width: 45%;
        height: 64px;
        background: rgba(255, 255, 255, 0.14);
        border-radius: 50% 50% 0 0;
        transform: rotate(-5deg);
        pointer-events: none;
    }

    .history-modal-header::after {
        content: '';
        position: absolute;
        right: 12%;
        bottom: -30px;
        width: 35%;
        height: 52px;
        background: rgba(45, 212, 191, 0.25);
        border-radius: 50% 50% 0 0;
        pointer-events: none;
    }

    .history-header-icon {
        width: 48px;
        height: 48px;
        border-radius: 50%;
        background: rgba(255, 255, 255, 0.16);
        border: 1.5px solid rgba(255, 255, 255, 0.35);
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 23px;
        color: #ffffff;
        flex-shrink: 0;
        position: relative;
        z-index: 2;
    }

    .history-header-divider {
        width: 1.5px;
        height: 40px;
        background: rgba(255, 255, 255, 0.55);
        margin: 0 16px;
        position: relative;
        z-index: 2;
    }

    .history-header-text {
        position: relative;
        z-index: 2;
    }
    .history-header-text h5 {
        font-size: 1.25rem;
        font-weight: 800;
        color: #ffffff;
        margin-bottom: 2px;
        letter-spacing: -0.2px;
    }
    .history-header-text p {
        font-size: 0.84rem;
        color: rgba(255, 255, 255, 0.9);
        margin-bottom: 0;
    }

    .btn-close-history-circle {
        position: relative;
        z-index: 2;
        width: 36px;
        height: 36px;
        border-radius: 50%;
        background: rgba(255, 255, 255, 0.16);
        border: 1px solid rgba(255, 255, 255, 0.25);
        color: #ffffff;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 16px;
        cursor: pointer;
        transition: 0.2s;
    }
    .btn-close-history-circle:hover {
        background: rgba(255, 255, 255, 0.28);
        transform: rotate(90deg);
    }

    .history-modal-body {
        background: #f3f9fd;
        padding: 20px 22px 14px !important;
    }

    .history-table-card {
        background: #ffffff;
        border: 1px solid #dbe8f4;
        border-radius: 16px;
        overflow: hidden;
        box-shadow: 0 6px 18px rgba(15, 23, 42, 0.04);
    }

    .table-history-modern {
        margin-bottom: 0;
        border-collapse: separate;
        border-spacing: 0;
    }

    .table-history-modern thead th {
        background: #eef6fc !important;
        color: #0f172a !important;
        font-size: 12.5px;
        font-weight: 800;
        border-bottom: 1.5px solid #d5e5f2 !important;
        padding: 13px 12px !important;
        vertical-align: middle;
        white-space: nowrap;
    }

    .th-inline-icon {
        display: inline-flex;
        align-items: center;
        gap: 7px;
    }
    .th-inline-icon i {
        color: #09587a;
        font-size: 15px;
    }

    .table-history-modern tbody tr:nth-child(even) {
        background-color: #f6fbff;
    }
    .table-history-modern tbody tr:hover {
        background-color: #eaf5fd;
    }
    .table-history-modern tbody td {
        padding: 13px 12px !important;
        border-bottom: 1px solid #e6eff7 !important;
        font-size: 13px;
        vertical-align: middle;
    }

    .badge-reg-soft {
        display: inline-block;
        background: #e2e8f0;
        color: #475569;
        font-weight: 700;
        font-size: 11.5px;
        padding: 2px 10px;
        border-radius: 6px;
        margin-top: 3px;
    }

    .badge-thn-soft {
        display: inline-block;
        background: #dbeafe;
        color: #1e40af;
        font-weight: 700;
        font-size: 12.5px;
        padding: 4px 12px;
        border-radius: 8px;
    }

    .badge-vol-mint {
        display: inline-block;
        background: #dcfce7;
        color: #15803d;
        font-weight: 700;
        font-size: 12.5px;
        padding: 4px 14px;
        border-radius: 8px;
        white-space: nowrap;
    }

    .btn-cetak-ba-pill {
        background: #ffffff !important;
        color: #0b4f7c !important;
        border: 1.5px solid #93c5fd !important;
        border-radius: 10px !important;
        padding: 6px 14px !important;
        font-size: 12.5px !important;
        font-weight: 700 !important;
        display: inline-flex;
        align-items: center;
        gap: 6px;
        transition: all 0.18s ease;
    }
    .btn-cetak-ba-pill:hover {
        background: #0b4f7c !important;
        color: #ffffff !important;
        border-color: #0b4f7c !important;
    }

    .history-modal-footer {
        background: #f3f9fd;
        border-top: none !important;
        padding: 8px 22px 20px !important;
        display: flex;
        justify-content: flex-end;
    }

    .btn-tutup-history-dark {
        background: linear-gradient(135deg, #334155 0%, #1e293b 100%) !important;
        color: #ffffff !important;
        border: none !important;
        border-radius: 11px !important;
        padding: 9px 24px !important;
        font-size: 13.5px !important;
        font-weight: 700 !important;
        display: inline-flex;
        align-items: center;
        gap: 7px;
        box-shadow: 0 4px 12px rgba(15, 23, 42, 0.2);
        transition: 0.2s;
    }
    .btn-tutup-history-dark:hover {
        background: linear-gradient(135deg, #1e293b 0%, #0f172a 100%) !important;
        transform: translateY(-1px);
    }
</style>

<div class="container-fluid p-0">
    <div class="pengembalian-page-wrap">
        <div class="pengembalian-inner">
            <!-- HEADER JUDUL & TOMBOL AKSI ATAS -->
            <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-3 mb-4">
                <div class="d-flex align-items-center gap-3">
                    <div class="header-box-icon-teal">
                        <i class="bi bi-box-arrow-in-left"></i>
                    </div>
                    <div>
                        <h4 class="page-title-main">Pengembalian Barang</h4>
                        <p class="page-subtitle-main">Kelola pengembalian barang inventaris yang telah disalurkan</p>
                    </div>
                </div>
                
                <div class="d-flex flex-column flex-sm-row align-items-stretch align-items-sm-center gap-2" style="max-width: 580px; width: 100%;">
                    <button type="button" class="btn-history-pill" onclick="bukaModalHistoryAll()">
                        <i class="bi bi-clock-history"></i> History Pengembalian
                    </button>

                    <div class="search-pill-box">
                        <i class="bi bi-search"></i>
                        <input type="text" 
                               id="search-input" 
                               class="form-control search-pill-field" 
                               placeholder="Cari barang, merk, pegawai, atau ruang...">
                    </div>
                </div>
            </div>

            <!-- KARTU TABEL DATA -->
            <div class="card-table-modern">
                <div class="table-responsive">
                    <table class="table align-middle mb-0" id="table-pengembalian">
                        <thead>
                            <tr>
                                <th class="text-center" style="width: 55px;">No</th>
                                <th class="text-center">
                                    <div class="th-icon-stack">
                                        <i class="bi bi-upc"></i>
                                        <span>Kode / No. Reg</span>
                                    </div>
                                </th>
                                <th class="text-center">
                                    <div class="th-icon-stack">
                                        <i class="bi bi-box-seam"></i>
                                        <span>Nama Barang</span>
                                    </div>
                                </th>
                                <th class="text-center">
                                    <div class="th-icon-stack">
                                        <i class="bi bi-layers-fill"></i>
                                        <span>Merk & Tipe</span>
                                    </div>
                                </th>
                                <th class="text-center">
                                    <div class="th-icon-stack">
                                        <i class="bi bi-calendar-event"></i>
                                        <span>Thn Pengadaan</span>
                                    </div>
                                </th> 
                                <th class="text-center">
                                    <div class="th-icon-stack">
                                        <i class="bi bi-person-lines-fill"></i>
                                        <span>Penerima & Lokasi</span>
                                    </div>
                                </th>
                                <th class="text-center">
                                    <div class="th-icon-stack">
                                        <i class="bi bi-boxes"></i>
                                        <span>Vol Salur</span>
                                    </div>
                                </th>
                                <th class="text-center">
                                    <div class="th-icon-stack">
                                        <i class="bi bi-tag-fill"></i>
                                        <span>Harga Satuan</span>
                                    </div>
                                </th>
                                <th class="text-center" style="width: 165px;">
                                    <div class="th-icon-stack">
                                        <i class="bi bi-gear-fill"></i>
                                        <span>Aksi</span>
                                    </div>
                                </th>
                            </tr>
                        </thead>
                        <tbody id="table-body-pengembalian">
                            <?php 
                            $no = 1;
                            if ($result && mysqli_num_rows($result) > 0) {
                                while ($row = mysqli_fetch_assoc($result)) { 
                            ?>
                                <tr class="searchable-row">
                                    <td class="text-center fw-semibold text-secondary row-number"><?= $no++; ?></td>
                                    <td>
                                        <span class="fw-bold d-block text-primary text-kode"><?= htmlspecialchars($row['kode_barang']); ?></span>
                                        <small class="text-muted">Reg: <?= htmlspecialchars($row['no_reg']); ?></small>
                                    </td>
                                    <td>
                                        <span class="fw-bold text-dark text-barang"><?= htmlspecialchars($row['nama_barang']); ?></span>
                                    </td>
                                    <td class="text-merk fw-medium text-dark"><?= htmlspecialchars($row['merk_tipe'] ?: '-'); ?></td>
                                    <td class="text-center fw-semibold text-dark text-tahun"><?= htmlspecialchars($row['thn_pengadaan'] ?: '-'); ?></td> 
                                    <td>
                                        <span class="d-block fw-bold text-dark text-penerima"><i class="bi bi-person-fill text-primary small me-1"></i><?= htmlspecialchars($row['nama_penerima']); ?></span>
                                        <small class="text-secondary text-lokasi"><i class="bi bi-geo-alt-fill text-info small me-1"></i><?= htmlspecialchars($row['lokasi']); ?></small>
                                    </td>
                                    <td class="text-center">
                                        <span class="vol-pill-badge"><?= number_format($row['vol_penyaluran']); ?> <?= htmlspecialchars($row['sat_pengadaan']); ?></span>
                                    </td>
                                    <td class="fw-bold text-dark text-nowrap">Rp <?= number_format($row['harga_satuan'], 0, ',', '.'); ?></td>
                                    <td class="text-center">
                                        <button type="button" 
                                                class="btn btn-sm btn-kembalikan-red"
                                                onclick="prosesPengembalian('<?= $row['id_penyaluran']; ?>', '<?= addslashes($row['nama_barang']); ?>', '<?= addslashes($row['no_reg']); ?>')">
                                            <i class="bi bi-arrow-counterclockwise"></i> Kembalikan
                                        </button>
                                    </td>
                                </tr>
                            <?php 
                                } 
                            } else { 
                            ?>
                                <tr id="empty-row-db">
                                    <td colspan="9" class="text-center py-5 text-secondary">
                                        <i class="bi bi-box-seam d-block mb-2 text-muted" style="font-size: 40px;"></i>
                                         Tidak ada data barang yang sedang disalurkan.
                                    </td>
                                </tr>
                            <?php } ?>
                            
                            <tr id="no-search-results" style="display: none;">
                                <td colspan="9" class="text-center py-5 text-secondary">
                                    <i class="bi bi-search d-block mb-2 text-muted" style="font-size: 40px;"></i>
                                    Data tidak ditemukan berdasarkan kata kunci tersebut.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ================= MODAL PROSES PENGEMBALIAN BARANG ================= -->
<div class="modal fade" id="kembaliModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered">
        <div class="modal-content border-0 shadow" style="border-radius: 20px; overflow: hidden;">
            <div class="modal-body p-0">
                <div class="row g-0">
                    <!-- KIRI: HISTORY PENGEMBALIAN TERBARU -->
                    <div class="col-md-6 bg-light p-4 style-scrollbar" style="max-height: 580px; overflow-y: auto; border-right: 1px solid rgba(0,0,0,0.05);">
                        <div class="d-flex align-items-center gap-2 mb-3">
                            <i class="bi bi-clock-history text-primary fs-5"></i>
                            <h5 class="fw-bold text-dark mb-0">History Pengembalian (Terbaru)</h5>
                        </div>
                        <hr class="my-2 text-muted opacity-25">
                        <div class="pe-1">
                            <?php if(!empty($arr_history)): ?>
                                <?php 
                                $limit_mini = 0;
                                foreach($arr_history as $hist): 
                                    if($limit_mini >= 5) break; 
                                    $limit_mini++;
                                ?>
                                    <div class="bg-white p-3 mb-2 shadow-sm border border-light-subtle" style="border-radius: 12px;">
                                        <div class="d-flex justify-content-between align-items-start mb-1">
                                            <span class="fw-bold text-primary small"><?= htmlspecialchars($hist['nomor_surat'] ?: ($hist['grid_barang'] ?? $hist['kode_barang'])); ?></span>
                                            <span class="badge bg-secondary-subtle text-secondary style-date" style="font-size: 10px;"><?= $hist['tgl_kembali'] ?? date('Y-m-d'); ?></span>
                                        </div>
                                        <h6 class="fw-bold text-dark mb-1" style="font-size: 14px;"><?= htmlspecialchars($hist['nama_barang']); ?> <span class="text-muted fw-normal">(Reg: <?= htmlspecialchars($hist['no_reg']); ?>)</span></h6>
                                        <small class="text-secondary d-block mb-1"><i class="bi bi-calendar3 me-1"></i>Thn Pengadaan: <b><?= htmlspecialchars($hist['thn_pengadaan'] ?: '-'); ?></b></small>
                                        <small class="text-muted d-block"><i class="bi bi-person me-1"></i>Penerima: <?= htmlspecialchars($hist['nama_penerima']); ?></small>
                                        <small class="text-muted d-block"><i class="bi bi-geo-alt me-1"></i>Lokasi: <?= htmlspecialchars($hist['lokasi']); ?></small>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="text-center py-5 text-secondary">
                                    <i class="bi bi-folder-x d-block mb-2 text-muted" style="font-size: 30px;"></i>
                                    Belum ada catatan history pengembalian.
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- KANAN: FORM KONFIRMASI PENGEMBALIAN -->
                    <div class="col-md-6 p-4 text-center d-flex flex-column justify-content-center align-items-center bg-white">
                        <div class="text-danger mb-2">
                            <i class="bi bi-arrow-counterclockwise" style="font-size: 46px;"></i>
                        </div>
                        <h4 class="fw-bold mb-1">Generate BA Pengembalian</h4>
                        <p class="text-secondary small mb-3 px-3">
                            Sistem akan memproses berkas PDF serta memperbarui status penyaluran aktif untuk barang:<br>
                            <span id="nama-barang-modal" class="fw-bold text-dark d-block mt-2 p-2 bg-light rounded" style="font-size: 14.5px;"></span>
                        </p>

                        <!-- NOMOR SURAT OTOMATIS (READONLY) -->
                        <div class="mb-3 text-start w-100 px-4">
                            <label class="form-label fw-bold text-secondary small mb-1"><i class="bi bi-file-earmark-text me-1"></i>Nomor Surat BA Pengembalian (Otomatis):</label>
                            <input type="text" id="modal-nomor-surat-preview" class="form-control border-1 py-2 fw-bold text-primary bg-light" style="border-radius: 10px; font-size: 14px;" value="<?= htmlspecialchars($preview_nomor_surat); ?>" readonly>
                        </div>

                        <!-- METODE PENGEMBALIAN KHUSUS PAKET -->
                        <div class="mb-3 text-start w-100 px-4" id="container-jenis-kembali" style="display:none;">
                            <label class="form-label fw-bold text-secondary small mb-1"><i class="bi bi-gear me-1"></i>Metode Pengembalian Paket:</label>
                            <select id="modal-jenis-kembali" class="form-select border-1 py-2" style="border-radius: 10px; font-size: 14px;">
                                <option value="semua">Kembalikan Semua Unit Sekaligus</option>
                                <option value="sebagian">Kembalikan Sebagian Unit (Range No. Reg)</option>
                            </select>
                        </div>

                        <!-- RANGE NO. REG SPESIFIK (DARI - SAMPAI) -->
                        <div class="mb-3 text-start w-100 px-4" id="container-reg-spesifik" style="display:none;">
                            <label class="form-label fw-bold text-danger small mb-1"><i class="bi bi-sliders me-1"></i>Rentang No. Reg Yang Dikembalikan:</label>
                            <div class="row g-2 align-items-center">
                                <div class="col-5">
                                    <input type="text" id="modal-reg-dari" class="form-control border-1 py-2 text-center fw-bold" style="border-radius: 10px; font-size: 14px;" placeholder="Dari (mis: 002)">
                                </div>
                                <div class="col-2 text-center fw-bold text-secondary">s/d</div>
                                <div class="col-5">
                                    <input type="text" id="modal-reg-sampai" class="form-control border-1 py-2 text-center fw-bold" style="border-radius: 10px; font-size: 14px;" placeholder="Sampai (mis: 004)">
                                </div>
                            </div>
                            <small class="text-muted d-block mt-1 fw-semibold" id="info-range-paket" style="font-size: 11px; color: #dc3545 !important;"></small>
                        </div>

                        <!-- KONDISI BARANG -->
                        <div class="mb-3 text-start w-100 px-4">
                            <label class="form-label fw-bold text-secondary small mb-1"><i class="bi bi-info-circle me-1"></i>Kondisi Barang Saat Dikembalikan:</label>
                            <select id="modal-kondisi-kembali" class="form-select border-1 py-2" style="border-radius: 10px; font-size: 14px;">
                                <option value="B">B (Baik)</option>
                                <option value="RR">RR (Rusak Ringan)</option>
                                <option value="RB">RB (Rusak Berat)</option>
                            </select>
                        </div>

                        <!-- KETERANGAN TAMBAHAN -->
                        <div class="mb-4 text-start w-100 px-4">
                            <label class="form-label fw-bold text-secondary small mb-1"><i class="bi bi-chat-text me-1"></i>Keterangan Tambahan:</label>
                            <textarea id="modal-keterangan-kembali" class="form-control border-1 py-2" style="border-radius: 10px; font-size: 14px;" rows="2" placeholder="Tuliskan keterangan (misal: layar pecah, kabel hilang)..."></textarea>
                        </div>

                        <div class="d-flex gap-3 justify-content-center w-100 px-4">
                            <button type="button" class="btn btn-light px-4 py-2 fw-semibold flex-fill" data-bs-dismiss="modal" style="border-radius: 12px;">Batal</button>
                            <button type="button" id="btn-confirm-kembali" class="btn btn-danger px-4 py-2 fw-semibold flex-fill" style="border-radius: 12px;">Cetak & Proses</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ================= MODAL RIWAYAT LENGKAP PENGEMBALIAN BARANG ================= -->
<div class="modal fade" id="modalHistoryAll" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="history-modal-header">
                <div class="d-flex align-items-center">
                    <div class="history-header-icon">
                        <i class="bi bi-clock-history"></i>
                    </div>
                    <div class="history-header-divider"></div>
                    <div class="history-header-text">
                        <h5 class="modal-title" id="historyModalLabel">Riwayat Lengkap Pengembalian Barang</h5>
                        <p>Daftar seluruh transaksi pengembalian barang ke inventaris.</p>
                    </div>
                </div>
                <button type="button" class="btn-close-history-circle" data-bs-dismiss="modal" aria-label="Close">
                    <i class="bi bi-x-lg"></i>
                </button>
            </div>
            <div class="modal-body history-modal-body">
                <div class="history-table-card">
                    <div class="table-responsive">
                        <table class="table table-history-modern align-middle mb-0">
                            <thead>
                                <tr>
                                    <th class="text-center" style="width: 55px;">No</th>
                                    <th>
                                        <span class="th-inline-icon">
                                            <i class="bi bi-file-earmark-text"></i>
                                            <span>Nomor Surat</span>
                                        </span>
                                    </th>
                                    <th>
                                        <span class="th-inline-icon">
                                            <i class="bi bi-calendar-event"></i>
                                            <span>Tanggal Kembali</span>
                                        </span>
                                    </th>
                                    <th>
                                        <span class="th-inline-icon">
                                            <i class="bi bi-layers"></i>
                                            <span>Kode / No. Reg</span>
                                        </span>
                                    </th>
                                    <th>
                                        <span class="th-inline-icon">
                                            <i class="bi bi-box-seam"></i>
                                            <span>Nama Barang</span>
                                        </span>
                                    </th>
                                    <th class="text-center">
                                        <span class="th-inline-icon">
                                            <i class="bi bi-calendar-check"></i>
                                            <span>Thn Pengadaan</span>
                                        </span>
                                    </th> 
                                    <th>
                                        <span class="th-inline-icon">
                                            <i class="bi bi-people"></i>
                                            <span>Eks Penerima</span>
                                        </span>
                                    </th>
                                    <th class="text-center">
                                        <span class="th-inline-icon">
                                            <i class="bi bi-box"></i>
                                            <span>Volume</span>
                                        </span>
                                    </th>
                                    <th class="text-center">
                                        <span class="th-inline-icon">
                                            <i class="bi bi-gear"></i>
                                            <span>Aksi</span>
                                        </span>
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if(!empty($arr_history)): ?>
                                    <?php $h_no = 1; foreach($arr_history as $h_item): ?>
                                        <tr>
                                            <td class="text-center fw-semibold text-secondary"><?= $h_no++; ?></td>
                                            <td class="fw-bold text-primary"><?= htmlspecialchars($h_item['nomor_surat'] ?: '-'); ?></td>
                                            <td class="fw-semibold text-dark"><?= date('d M Y - H:i', strtotime($h_item['tgl_kembali'])); ?> WIB</td>
                                            <td>
                                                <span class="fw-bold d-block text-primary" style="color: #1d4ed8 !important;"><?= htmlspecialchars($h_item['grid_barang'] ?? $h_item['kode_barang']); ?></span>
                                                <span class="badge-reg-soft">Reg: <?= htmlspecialchars($h_item['no_reg']); ?></span>
                                            </td>
                                            <td class="fw-bold text-dark">
                                                <i class="bi bi-box text-primary me-1"></i><?= htmlspecialchars($h_item['nama_barang']); ?>
                                                <div class="small text-muted fw-normal"><?= htmlspecialchars($h_item['merk_tipe'] ?: '-'); ?></div>
                                            </td>
                                            <td class="text-center">
                                                <span class="badge-thn-soft"><?= htmlspecialchars($h_item['thn_pengadaan'] ?: '-'); ?></span>
                                            </td> 
                                            <td>
                                                <span class="d-block fw-bold text-dark"><i class="bi bi-person text-primary small me-1"></i><?= htmlspecialchars($h_item['nama_penerima']); ?></span>
                                                <small class="text-secondary"><i class="bi bi-geo-alt text-info small me-1"></i><?= htmlspecialchars($h_item['lokasi']); ?></small>
                                            </td>
                                            <td class="text-center">
                                                <span class="badge-vol-mint"><?= number_format($h_item['vol_penyaluran']); ?> <?= htmlspecialchars($h_item['sat_pengadaan']); ?></span>
                                            </td>
                                            <td class="text-center">
                                                <button type="button" class="btn-cetak-ba-pill" onclick="cetakUlangBA('<?= $h_item['id_history']; ?>')">
                                                    <i class="bi bi-printer"></i> Cetak BA
                                                </button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="9" class="text-center py-5 text-secondary">
                                            <i class="bi bi-folder-x d-block mb-2 text-muted" style="font-size: 40px;"></i>
                                            Tidak ditemukan adanya riwayat data pengembalian barang BMD.
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <div class="modal-footer history-modal-footer">
                <button type="button" class="btn-tutup-history-dark" data-bs-dismiss="modal">
                    <i class="bi bi-x-lg"></i> Tutup
                </button>
            </div>
        </div>
    </div>
</div>

<script>
(function() {
    const modalHistoryAllEl = document.getElementById('modalHistoryAll');
    const kembaliModalEl = document.getElementById('kembaliModal');
    
    const modalHistoryAll = bootstrap.Modal.getOrCreateInstance(modalHistoryAllEl);
    const kembaliModal = bootstrap.Modal.getOrCreateInstance(kembaliModalEl);

    let targetId = null;
    let targetNamaBarang = null;
    let targetNoReg = null;

    window.bukaModalHistoryAll = function() {
        modalHistoryAll.show();
    };

    window.prosesPengembalian = function(id, namaBarang, noReg) {
        targetId = id;
        targetNamaBarang = namaBarang;
        targetNoReg = noReg;
        
        document.getElementById('nama-barang-modal').innerText = namaBarang + " (Reg: " + noReg + ")";
        document.getElementById('modal-kondisi-kembali').value = 'B';
        document.getElementById('modal-reg-dari').value = '';
        document.getElementById('modal-reg-sampai').value = '';
        document.getElementById('modal-keterangan-kembali').value = '';
        
        if(noReg.includes('-')) {
            const parts = noReg.split('-');
            document.getElementById('container-jenis-kembali').style.display = 'block';
            document.getElementById('modal-jenis-kembali').value = 'semua';
            document.getElementById('modal-reg-dari').placeholder = 'Dari (' + parts[0].trim() + ')';
            document.getElementById('modal-reg-sampai').placeholder = 'Sampai (' + parts[1].trim() + ')';
            document.getElementById('info-range-paket').innerText = 'Isi rentang No. Reg di dalam paket (' + noReg + '). Jika hanya 1 unit, isi angka yang sama pada kedua kolom.';
            document.getElementById('container-reg-spesifik').style.display = 'none';
        } else {
            document.getElementById('container-jenis-kembali').style.display = 'none';
            document.getElementById('container-reg-spesifik').style.display = 'none';
        }
        
        kembaliModal.show();
    };

    window.cetakUlangBA = function(idHistory) {
        const urlCetak = 'cetak_pengembalian.php?id_history=' + encodeURIComponent(idHistory);
        window.open(urlCetak, '_blank');
    };

    document.getElementById('modal-jenis-kembali').onchange = function() {
        if(this.value === 'sebagian') {
            document.getElementById('container-reg-spesifik').style.display = 'block';
        } else {
            document.getElementById('container-reg-spesifik').style.display = 'none';
        }
    };

    document.getElementById('btn-confirm-kembali').onclick = function() {
        if(!targetId) return;
        
        const kondisiTerpilih   = document.getElementById('modal-kondisi-kembali').value;
        const jenisKembali      = document.getElementById('modal-jenis-kembali').value;
        const regDari           = document.getElementById('modal-reg-dari').value.trim();
        const regSampai         = document.getElementById('modal-reg-sampai').value.trim();
        const keteranganKembali = document.getElementById('modal-keterangan-kembali').value.trim();
        
        if(targetNoReg.includes('-') && jenisKembali === 'sebagian') {
            if(regDari === '' || regSampai === '') {
                alert('Silakan isi No. Reg awal dan No. Reg akhir yang ingin dikembalikan!');
                return;
            }

            const parts = targetNoReg.split('-');
            const startRange = parseInt(parts[0], 10);
            const endRange   = parseInt(parts[1], 10);
            const dariInt    = parseInt(regDari, 10);
            const sampaiInt  = parseInt(regSampai, 10);

            if(isNaN(dariInt) || isNaN(sampaiInt)) {
                alert('Nomor registrasi harus berupa angka murni!');
                return;
            }

            if(dariInt > sampaiInt) {
                alert('No. Reg awal (' + regDari + ') tidak boleh lebih besar dari No. Reg akhir (' + regSampai + ')!');
                return;
            }

            if(dariInt < startRange || sampaiInt > endRange) {
                alert('Gagal! Rentang ' + regDari + ' s/d ' + regSampai + ' berada di luar rentang paket aktif (' + targetNoReg + ').');
                return;
            }
        }

        const btnConfirm = this;
        btnConfirm.disabled = true;
        btnConfirm.innerText = 'Memproses...';

        const formData = new FormData();
        formData.append('action', 'proses_pengembalian_langsung');
        formData.append('id', targetId);
        formData.append('kondisi_kembali', kondisiTerpilih);
        formData.append('no_reg_asal', targetNoReg);
        formData.append('jenis_kembali', jenisKembali); 
        formData.append('reg_dari', regDari);
        formData.append('reg_sampai', regSampai);
        formData.append('keterangan', keteranganKembali);

        fetch('pengembalian.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            btnConfirm.disabled = false;
            btnConfirm.innerText = 'Cetak & Proses';

            if(data.success) {
                kembaliModal.hide();

                let urlCetak = 'cetak_pengembalian.php?id_history=' + encodeURIComponent(data.id_history) +
                               '&nomor_surat=' + encodeURIComponent(data.nomor_surat) +
                               '&kondisi_kembali=' + encodeURIComponent(kondisiTerpilih) + 
                               '&keterangan=' + encodeURIComponent(keteranganKembali);
                window.open(urlCetak, '_blank');

                if (typeof loadPage === "function") {
                    loadPage('pengembalian.php', 'Pengembalian Barang', false);
                } else {
                    window.location.reload();
                }
            } else {
                alert('Gagal memproses data: ' + data.message);
            }
        })
        .catch(() => {
            btnConfirm.disabled = false;
            btnConfirm.innerText = 'Cetak & Proses';
            alert('Terjadi kesalahan koneksi sistem database.');
        });
    };

    // Fitur Pencarian Realtime halaman Utama
    document.getElementById('search-input').oninput = function() {
        const filter = this.value.toLowerCase().trim();
        const rows = document.querySelectorAll('.searchable-row');
        const noResultsRow = document.getElementById('no-search-results');
        const emptyDbRow = document.getElementById('empty-row-db');
        
        let visibleCount = 0;
        let rowNumber = 1;

        rows.forEach(row => {
            const textBarang = row.querySelector('.text-barang').textContent.toLowerCase();
            const textMerk = row.querySelector('.text-merk').textContent.toLowerCase();
            const textPenerima = row.querySelector('.text-penerima').textContent.toLowerCase();
            const textLokasi = row.querySelector('.text-lokasi').textContent.toLowerCase();
            const textKode = row.querySelector('.text-kode').textContent.toLowerCase();
            const textTahun = row.querySelector('.text-tahun').textContent.toLowerCase();

            if (
                textBarang.includes(filter) || 
                textMerk.includes(filter) || 
                textPenerima.includes(filter) || 
                textLokasi.includes(filter) ||
                textKode.includes(filter) ||
                textTahun.includes(filter)
            ) {
                row.style.display = '';
                row.querySelector('.row-number').textContent = rowNumber++;
                visibleCount++;
            } else {
                row.style.display = 'none';
            }
        });

        if (visibleCount === 0 && rows.length > 0) {
            noResultsRow.style.display = '';
        } else {
            noResultsRow.style.display = 'none';
        }
        
        if (emptyDbRow) {
            if (filter !== '') {
                emptyDbRow.style.display = 'none';
            } else if (rows.length === 0) {
                emptyDbRow.style.display = '';
            }
        }
    };
})();
</script>