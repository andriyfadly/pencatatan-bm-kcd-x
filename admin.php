<?php
session_start();
include "koneksi.php";

// ================= API DATA USER (UNTUK REALTIME AJAX) =================
// Logika ini akan dipanggil oleh JavaScript setiap 5 detik
if (isset($_GET['fetch_realtime'])) {
    $q = mysqli_query($conn, "
        SELECT u.id, u.username, u.role, u.last_login, s.nama_sekolah 
        FROM users u 
        LEFT JOIN kode_sekolah s ON u.id_sekolah = s.id
        ORDER BY u.last_login DESC
    ");
    $users = [];
    while($row = mysqli_fetch_assoc($q)) {
        $row['time_formatted'] = ($row['last_login']) ? date('d/m H:i:s', strtotime($row['last_login'])) : 'Belum pernah';
        $users[] = $row;
    }
    header('Content-Type: application/json');
    echo json_encode($users);
    exit; // Berhenti di sini jika request berasal dari AJAX
}

// ================= LOGIKA RESET PASSWORD =================
$msg = "";
if (isset($_POST['reset_pw'])) {
    $id_u = $_POST['user_id'];
    $new_pw = password_hash($_POST['new_password'], PASSWORD_DEFAULT);
    $update = mysqli_query($conn, "UPDATE users SET password='$new_pw' WHERE id='$id_u'");
    if ($update) {
        $msg = "<script>alert('Password berhasil diperbarui!');</script>";
    }
}

// ================= HITUNG DATA MASTER =================
$qTotalKode = mysqli_query($conn, "SELECT COUNT(*) as total FROM kode_barang");
$rowKode = mysqli_fetch_assoc($qTotalKode);
$totalMasterKode = $rowKode['total'] ?? 0;

echo $msg;
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SINVENTARIS | Admin Panel Realtime</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">

    <style>
        :root {
            --primary: #2563eb;
            --secondary: #64748b;
            --sidebar-width: 280px;
            --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            --bg-body: #f8fafc;
        }

        * { font-family: 'Plus Jakarta Sans', sans-serif; }
        body { background: var(--bg-body); overflow-x: hidden; }

        /* SIDEBAR */
        .sidebar {
            width: var(--sidebar-width); height: 100vh; position: fixed; top: 0; left: 0;
            background: #ffffff; border-right: 1px solid #e2e8f0; z-index: 1000;
            transition: var(--transition); display: flex; flex-direction: column;
        }
        .sidebar .brand { padding: 30px 24px; display: flex; align-items: center; gap: 15px; }
        .sidebar .brand i { font-size: 28px; color: var(--primary); }
        .sidebar .brand span { font-weight: 800; font-size: 18px; }

        .menu-btn {
            width: calc(100% - 30px); margin: 0 15px 5px; border: none; background: transparent; 
            padding: 12px 15px; border-radius: 12px; display: flex; align-items: center; 
            gap: 12px; color: var(--secondary); font-weight: 600; text-decoration: none; transition: 0.2s;
        }
        .menu-btn:hover, .menu-btn.active { background: #eff6ff; color: var(--primary); }

        /* HEADER & CONTENT */
        .header {
            position: fixed; top: 0; left: var(--sidebar-width); right: 0; height: 80px;
            background: rgba(255, 255, 255, 0.9); backdrop-filter: blur(12px); border-bottom: 1px solid #e2e8f0;
            display: flex; align-items: center; justify-content: space-between; padding: 0 30px; z-index: 900;
        }
        .content { margin-left: var(--sidebar-width); padding: 110px 40px 40px; }

        .card-box { border: none; border-radius: 20px; background: #fff; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); padding: 25px; border: 1px solid #e2e8f0; }
        
        .table-custom th { font-size: 12px; text-transform: uppercase; color: #64748b; padding: 15px; }
        .table-custom td { padding: 15px; vertical-align: middle; border-bottom: 1px solid #f1f5f9; font-size: 14px; }
        
        /* Pulse Animation untuk Indikator Live */
        .status-live { width: 8px; height: 8px; background: #10b981; border-radius: 50%; display: inline-block; margin-right: 5px; animation: pulse 1.5s infinite; }
        @keyframes pulse { 0% { transform: scale(0.9); opacity: 1; } 70% { transform: scale(1.5); opacity: 0; } 100% { transform: scale(0.9); opacity: 0; } }

        #loader { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 3px; background: var(--primary); z-index: 2000; animation: loading 2s infinite; }
        @keyframes loading { 0% { left: -100%; width: 100%; } 100% { left: 100%; width: 100%; } }

        @media (max-width: 992px) {
            .sidebar { left: -100%; }
            body.mobile-show .sidebar { left: 0; }
            .header, .content { left: 0 !important; margin-left: 0 !important; }
        }
    </style>
</head>
<body>

<div id="loader"></div>

<div class="sidebar" id="sidebar">
    <div class="brand">
        <i class="bi bi-shield-lock-fill"></i>
        <span>ADMIN PANEL</span>
    </div>

    <div class="nav-wrapper mt-3">
        <a href="javascript:void(0)" onclick="loadDashboard()" class="menu-btn active" id="btn-dash">
            <i class="bi bi-grid-1x2-fill"></i>
            <span>Monitoring User</span>
        </a>
        
        <a href="javascript:void(0)" onclick="loadPage('import_kode_barang.php', 'Kode Barang')" class="menu-btn" id="btn-kode">
            <i class="bi bi-file-earmark-excel-fill text-success"></i>
            <span>Kode Barang</span>
        </a>

        <div class="border-top my-3 mx-3"></div>
    </div>
</div>

<div class="header">
    <div class="d-flex align-items-center gap-3">
        <div id="sidebarToggle" class="d-lg-none" style="cursor:pointer; padding:10px; background:#f1f5f9; border-radius:10px;">
            <i class="bi bi-list fs-5"></i>
        </div>
        <div>
            <h6 class="mb-0 fw-bold" id="main-title">Dashboard Monitoring</h6>
            <small class="text-secondary"><span class="status-live"></span> Sistem Live Monitoring</small>
        </div>
    </div>
    <div class="d-flex align-items-center gap-3">
        <div class="text-end d-none d-sm-block">
            <small class="text-secondary d-block" style="font-size: 11px;">Waktu Server</small>
            <span class="fw-bold small" id="realtime-clock"></span>
        </div>
        <img src="https://ui-avatars.com/api/?name=Admin&background=2563eb&color=fff" class="rounded-circle border" width="40">
    </div>
</div>

<div class="content" id="main-content">
    
    <div id="dashboard-view">
        <div class="row g-4 mb-4">
            <div class="col-md-4">
                <div class="card card-box d-flex flex-row align-items-center gap-3">
                    <div class="rounded-4 bg-primary bg-opacity-10 p-3 text-primary"><i class="bi bi-database-fill fs-3"></i></div>
                    <div>
                        <h6 class="text-secondary small mb-0">Total Master Kode</h6>
                        <h3 class="fw-bold mb-0"><?= number_format($totalMasterKode) ?></h3>
                    </div>
                </div>
            </div>
        </div>

        <div class="card card-box">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h5 class="fw-bold mb-0">Aktivitas User Login</h5>
                <span class="badge bg-light text-secondary border fw-normal" id="sync-status">Sinkronisasi...</span>
            </div>
            <div class="table-responsive">
                <table class="table table-custom">
                    <thead>
                        <tr>
                            <th>Username</th>
                            <th>Sekolah</th>
                            <th>Terakhir Login</th>
                            <th>Aksi</th>
                        </tr>
                    </thead>
                    <tbody id="user-table-body">
                        <tr><td colspan="4" class="text-center py-5 text-muted">Memuat data...</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div id="ajax-container" style="display:none;"></div>
</div>

<div class="modal fade" id="resetModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form action="" method="POST" class="modal-content border-0 shadow" style="border-radius:20px;">
            <div class="modal-body p-4">
                <h5 class="fw-bold mb-1">Ganti Password</h5>
                <p class="text-secondary small mb-4">Mengubah password untuk user: <b id="target-user"></b></p>
                
                <input type="hidden" name="user_id" id="target-id">
                <div class="mb-3">
                    <label class="form-label small fw-bold">Password Baru</label>
                    <input type="password" name="new_password" class="form-control rounded-3" placeholder="Masukkan password baru..." required>
                </div>
                <div class="d-grid">
                    <button type="submit" name="reset_pw" class="btn btn-primary py-2 fw-bold rounded-3">Simpan Perubahan</button>
                </div>
            </div>
        </form>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
    const dashView = document.getElementById('dashboard-view');
    const ajaxView = document.getElementById('ajax-container');
    const mainTitle = document.getElementById('main-title');
    const loader = document.getElementById('loader');
    const userTableBody = document.getElementById('user-table-body');
    const syncStatus = document.getElementById('sync-status');

    // 1. FUNGSI REALTIME FETCHING DATA USER
    async function fetchUserActivity() {
        try {
            const response = await fetch('admin.php?fetch_realtime=1');
            const data = await response.json();
            
            let html = '';
            data.forEach(u => {
                html += `
                <tr>
                    <td>
                        <span class="fw-bold">${u.username}</span><br>
                        <span class="badge bg-light text-primary" style="font-size:10px">${u.role.toUpperCase()}</span>
                    </td>
                    <td>${u.nama_sekolah || '-'}</td>
                    <td>
                        <span class="text-secondary small">
                            <i class="bi bi-clock-history me-1 text-primary"></i>
                            ${u.time_formatted}
                        </span>
                    </td>
                    <td>
                        <button class="btn btn-primary btn-sm rounded-pill px-3" onclick="openResetModal(${u.id}, '${u.username}')">
                            <i class="bi bi-key-fill me-1"></i> Ganti Password
                        </button>
                    </td>
                </tr>`;
            });
            userTableBody.innerHTML = html;
            syncStatus.innerText = "Terupdate: " + new Date().toLocaleTimeString();
        } catch (e) {
            syncStatus.innerText = "Koneksi Terputus";
        }
    }

    // Jalankan fetch setiap 5 detik
    setInterval(fetchUserActivity, 5000);
    fetchUserActivity(); // Panggil saat pertama load

    // 2. NAVIGASI AJAX
    async function loadPage(url, title) {
        loader.style.display = 'block';
        mainTitle.innerText = title;
        
        try {
            const response = await fetch(url);
            const text = await response.text();
            
            dashView.style.display = 'none';
            ajaxView.style.display = 'block';
            ajaxView.innerHTML = text;

            const scripts = ajaxView.querySelectorAll("script");
            scripts.forEach(s => eval(s.innerHTML));

            document.getElementById('btn-dash').classList.remove('active');
            document.getElementById('btn-kode').classList.add('active');
        } catch (e) {
            ajaxView.innerHTML = '<div class="alert alert-danger">Gagal memuat halaman.</div>';
        } finally {
            loader.style.display = 'none';
        }
    }

    function loadDashboard() {
        ajaxView.style.display = 'none';
        dashView.style.display = 'block';
        mainTitle.innerText = "Dashboard Monitoring";
        document.getElementById('btn-kode').classList.remove('active');
        document.getElementById('btn-dash').classList.add('active');
        fetchUserActivity();
    }

    function openResetModal(id, user) {
        document.getElementById('target-id').value = id;
        document.getElementById('target-user').innerText = user;
        new bootstrap.Modal(document.getElementById('resetModal')).show();
    }

    function updateClock() {
        const d = new Date();
        document.getElementById('realtime-clock').innerText = d.toLocaleTimeString('id-ID');
    }
    setInterval(updateClock, 1000); updateClock();

    document.getElementById('sidebarToggle').addEventListener('click', () => {
        document.body.classList.toggle('mobile-show');
    });
</script>
</body>
</html>