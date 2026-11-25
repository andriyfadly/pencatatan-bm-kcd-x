<?php
session_start();
include "koneksi.php";

// AUTO-CREATE TABEL PROTEKSI BRUTE FORCE (Hanya berjalan jika tabel belum ada)
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS login_attempts (
    device_id VARCHAR(32) PRIMARY KEY,
    attempts INT NOT NULL,
    last_attempt DATETIME NOT NULL
)");

// Jika sudah login, langsung lempar ke index
if (isset($_SESSION['id_sekolah'])) {
    header("Location: index.php");
    exit;
}

$message = "";
$error = "";
$mode = $_GET['mode'] ?? 'login';
$login_success = false; // Flag untuk trigger modal

/* ================= LOGIKA REGISTER ================= */
if(isset($_POST['daftar'])){
    $username   = mysqli_real_escape_string($conn, trim($_POST['username']));
    $password   = password_hash($_POST['password'], PASSWORD_DEFAULT);
    $id_sekolah = mysqli_real_escape_string($conn, $_POST['id_sekolah']);

    if($username == "" || $_POST['password'] == "" || $id_sekolah == ""){
        $error = "Semua field wajib diisi!";
        $mode = 'register';
    } else {
        $cek = mysqli_query($conn,"SELECT * FROM users WHERE username='$username'");
        if(mysqli_num_rows($cek) > 0){
            $error = "Username sudah digunakan!";
            $mode = 'register';
        } else {
            // BACKEND PROTECTION: Validasi ganda jika form HTML di-inspect elemen oleh user
            $cek_terdaftar = mysqli_query($conn, "SELECT id FROM users WHERE id_sekolah = '$id_sekolah'");
            if(mysqli_num_rows($cek_terdaftar) > 0) {
                $error = "Sekolah tersebut sudah terdaftar sebelumnya!";
                $mode = 'register';
            } else {
                $insert = mysqli_query($conn,"INSERT INTO users (username, password, id_sekolah, role) 
                                               VALUES ('$username', '$password', '$id_sekolah', 'user')");
                if($insert){
                    $message = "Pendaftaran berhasil! Silakan login.";
                    $mode = 'login';
                } else {
                    $error = "Gagal mendaftar, coba lagi.";
                }
            }
        }
    }
}

/* ================= LOGIKA LOGIN DENGAN PROTEKSI ================= */
if(isset($_POST['login'])){
    $username = mysqli_real_escape_string($conn, trim($_POST['username']));
    $password_input = $_POST['password'];

    // 1. IDENTIFIKASI DEVICE (Kombinasi IP dan Browser)
    $ip_address = $_SERVER['REMOTE_ADDR'];
    $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown_device';
    $device_id = md5($ip_address . $user_agent);

    // 2. CEK STATUS BLOKIR DEVICE INI
    $cek_blokir = mysqli_query($conn, "SELECT * FROM login_attempts WHERE device_id = '$device_id'");
    $data_blokir = mysqli_fetch_assoc($cek_blokir);
    $waktu_sekarang = date('Y-m-d H:i:s');
    $is_blocked = false;

    if($data_blokir) {
        $last_attempt = $data_blokir['last_attempt'];
        $diff_minutes = round(abs(strtotime($waktu_sekarang) - strtotime($last_attempt)) / 60, 2);
        
        // Jika gagal 4x atau lebih dan belum lewat 15 menit
        if($data_blokir['attempts'] >= 4 && $diff_minutes < 15) {
            $is_blocked = true;
            $sisa_waktu = ceil(15 - $diff_minutes);
            $error = "Akses perangkat ini ditangguhkan sementara. Silakan coba lagi dalam $sisa_waktu menit.";
            $mode = 'login';
        } 
        // Jika sudah lewat 15 menit, reset percobaan
        elseif ($diff_minutes >= 15 && $data_blokir['attempts'] >= 4) {
            mysqli_query($conn, "UPDATE login_attempts SET attempts = 0 WHERE device_id = '$device_id'");
        }
    }

    // 3. PROSES LOGIN (Hanya jalan jika tidak diblokir)
    if(!$is_blocked) {
        $q = mysqli_query($conn,"
            SELECT users.*, kode_sekolah.nama_sekolah 
            FROM users 
            LEFT JOIN kode_sekolah ON users.id_sekolah = kode_sekolah.id
            WHERE users.username='$username'
        ");

        $user = mysqli_fetch_assoc($q);

        if($user && password_verify($password_input, $user['password'])){
            // Hapus riwayat kegagalan karena berhasil login
            mysqli_query($conn, "DELETE FROM login_attempts WHERE device_id = '$device_id'");
            
            session_regenerate_id();
            $_SESSION['id_user']      = $user['id'];
            $_SESSION['username']     = $user['username'];
            $_SESSION['id_sekolah']   = $user['id_sekolah'];
            $_SESSION['nama_sekolah'] = $user['nama_sekolah']; 
            $_SESSION['role']         = $user['role']; 
            
            $id_u = $user['id'];
            mysqli_query($conn, "UPDATE users SET last_login = NOW() WHERE id = '$id_u'");
            
            $login_success = true; 
        } else {
            // Catat kegagalan
            if($data_blokir) {
                mysqli_query($conn, "UPDATE login_attempts SET attempts = attempts + 1, last_attempt = NOW() WHERE device_id = '$device_id'");
            } else {
                mysqli_query($conn, "INSERT INTO login_attempts (device_id, attempts, last_attempt) VALUES ('$device_id', 1, NOW())");
            }
            
            // Hitung sisa percobaan untuk ditampilkan
            $cek_lagi = mysqli_query($conn, "SELECT attempts FROM login_attempts WHERE device_id = '$device_id'");
            $data_lagi = mysqli_fetch_assoc($cek_lagi);
            $sisa_coba = 4 - $data_lagi['attempts'];
            
            if($sisa_coba > 0) {
                $error = "Username atau password salah! Sisa percobaan: $sisa_coba kali.";
            } else {
                $error = "Terlalu banyak percobaan gagal! Perangkat ini diblokir selama 15 menit.";
            }
            $mode = 'login';
        }
    }
}

// Ambil daftar id_sekolah yang sudah terdaftar di tabel users untuk pengecekan data readonly
$terdaftar_list = [];
$qCheck = mysqli_query($conn, "SELECT DISTINCT id_sekolah FROM users WHERE id_sekolah IS NOT NULL AND id_sekolah != ''");
if($qCheck) {
    while($rCheck = mysqli_fetch_assoc($qCheck)) {
        $terdaftar_list[] = $rCheck['id_sekolah'];
    }
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= ($mode=='login') ? 'Login' : 'Daftar' ?> | SINVENTARIS</title>
    
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <style>
        :root {
            --primary: #0284c7;
            --primary-dark: #034ea2;
            --bg-gradient: radial-gradient(circle at 50% 15%, #f4faff 0%, #dceefa 55%, #cce5f7 100%);
        }

        body {
            background: var(--bg-gradient);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: 'Plus Jakarta Sans', sans-serif;
            padding: 16px;
        }

        .auth-card {
            position: relative;
            background: #ffffff;
            border: 1px solid rgba(255, 255, 255, 0.9);
            border-radius: 28px;
            box-shadow: 0 22px 50px -10px rgba(3, 78, 162, 0.14), 0 4px 16px rgba(15, 23, 42, 0.04);
            width: 100%;
            max-width: 440px;
            overflow: hidden;
        }

        .auth-header {
            background: #ffffff;
            padding: 24px 32px 4px 32px;
            text-align: center;
        }

        .img-top {
            width: 132px;
            height: auto;
            max-width: 100%;
            object-fit: contain;
            display: block;
            margin: 0 auto 6px auto;
        }
        
        .auth-header h3 {
            font-size: 1.38rem;
            font-weight: 800;
            color: #0b1f3a !important;
            letter-spacing: -0.3px;
            margin-bottom: 4px !important;
        }

        .img-banner {
            width: 100%;
            height: auto;
            max-height: 92px;
            object-fit: contain;
            border-radius: 10px;
            margin-top: 2px;
            margin-bottom: 10px;
        }

        .auth-body {
            position: relative;
            z-index: 2;
            padding: 0 34px 48px 34px;
        }

        .form-label {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            font-weight: 800;
            font-size: 0.78rem;
            color: #092244;
            margin-bottom: 6px;
            letter-spacing: 0.4px;
        }

        .form-label i {
            color: #062b5b;
            font-size: 0.95rem;
        }

        .input-group-custom {
            background: #f8fafc;
            border: 1.5px solid #cbd5e1;
            border-radius: 13px;
            padding: 2px 14px;
            display: flex;
            align-items: center;
            transition: all 0.2s ease;
        }

        .input-group-custom:focus-within {
            border-color: #0284c7;
            background: #ffffff;
            box-shadow: 0 0 0 3.5px rgba(2, 132, 199, 0.14);
        }

        .input-group-custom i {
            color: #64748b;
            margin-right: 11px;
            font-size: 1rem;
        }

        .input-group-custom input,
        .input-group-custom select {
            border: none;
            background: transparent;
            width: 100%;
            padding: 9px 0;
            font-weight: 600;
            outline: none;
            font-size: 0.9rem;
            color: #1e293b;
        }

        .input-group-custom input::placeholder {
            color: #64748b;
            font-weight: 500;
        }

        .input-group-custom select optgroup {
            font-weight: 700;
            color: var(--primary-dark);
            background: #f0f9ff;
        }

        .input-group-custom select option {
            font-weight: 600;
            color: #1e293b;
            background: #ffffff;
        }

        .input-group-custom select option:disabled {
            color: #94a3b8 !important;
            background: #f1f5f9;
            font-style: italic;
        }

        .btn-auth {
            background: linear-gradient(90deg, #0095ff 0%, #0066d6 55%, #0047ab 100%);
            border: none;
            border-radius: 14px;
            padding: 12px 18px;
            color: #ffffff;
            font-weight: 800;
            width: 100%;
            margin-top: 10px;
            transition: all 0.25s ease;
            font-size: 0.95rem;
            letter-spacing: 0.4px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            box-shadow: 0 8px 20px rgba(0, 102, 214, 0.28);
        }

        .btn-auth i {
            font-size: 1.15rem;
        }

        .btn-auth:hover {
            background: linear-gradient(90deg, #0084e6 0%, #0058bd 55%, #003b91 100%);
            transform: translateY(-1.5px);
            box-shadow: 0 10px 24px rgba(0, 71, 171, 0.35);
        }

        .auth-footer-switch {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            margin-top: 18px;
        }

        .auth-footer-switch span {
            color: #0f172a !important;
            font-weight: 600;
            font-size: 0.9rem !important;
        }

        .btn-switch-pill {
            display: inline-block;
            border: 1.5px solid #93c5fd;
            background: #ffffff;
            color: #0284c7 !important;
            font-weight: 800;
            font-size: 0.88rem !important;
            padding: 5px 20px;
            border-radius: 999px;
            text-decoration: none;
            transition: all 0.2s ease;
        }

        .btn-switch-pill:hover {
            background: #eff6ff;
            border-color: #0284c7;
            color: #0369a1 !important;
        }

        .alert-custom {
            border-radius: 12px;
            border: none;
            font-weight: 600;
            font-size: 0.8rem;
            padding: 8px 12px;
        }

        /* Dekorasi Gelombang Biru & Aksen Kuning di Bawah Kartu */
        .auth-bottom-wave {
            position: absolute;
            left: 0;
            right: 0;
            bottom: 0;
            width: 100%;
            height: 44px;
            pointer-events: none;
            z-index: 1;
        }
    </style>
</head>
<body>

<div class="auth-card shadow">
    <div class="auth-header">
        <img src="logolog.JPEG" alt="Logo" class="img-top">
        <h3 class="fw-bold text-dark mb-0"><?= ($mode=='login') ? 'Selamat Datang' : 'Buat Akun' ?></h3>
        <img src="dipta.JPEG" alt="Banner" class="img-banner">
    </div>

    <div class="auth-body">
        <?php if($error!=""): ?>
            <div class="alert alert-danger alert-custom mb-3">
                <i class="bi bi-exclamation-circle-fill me-1"></i> <?= $error ?>
            </div>
        <?php endif; ?>

        <?php if($message!=""): ?>
            <div class="alert alert-success alert-custom mb-3">
                <i class="bi bi-check-circle-fill me-1"></i> <?= $message ?>
            </div>
        <?php endif; ?>

        <form method="POST">
            <?php if($mode=='register'): ?>
            <div class="mb-2"> 
                <label class="form-label"><i class="bi bi-building-fill"></i> INSTANSI / SEKOLAH</label>
                <div class="input-group-custom">
                    <i class="bi bi-building"></i>
                    <select name="id_sekolah" required>
                        <option value="">Pilih Instansi...</option>
                        <?php
                        // Urutkan berdasarkan kota_kab terlebih dahulu agar pengelompokan rapi
                        $qSekolah = mysqli_query($conn,"SELECT * FROM kode_sekolah ORDER BY kota_kab ASC, nama_sekolah ASC");
                        
                        $current_region = "";
                        while($s = mysqli_fetch_assoc($qSekolah)):
                            $region = (!empty($s['kota_kab'])) ? $s['kota_kab'] : "Lainnya";
                            
                            // Logika pembuatan optgroup berdasarkan wilayah kota_kab
                            if($current_region != $region) {
                                if($current_region != "") {
                                    echo "</optgroup>";
                                }
                                $current_region = $region;
                                echo "<optgroup label='" . strtoupper($current_region) . "'>";
                            }

                            // Cek apakah id sekolah ini sudah terdaftar di tabel users
                            $is_registered = in_array($s['id'], $terdaftar_list);
                            $disabled = $is_registered ? "disabled" : "";
                            $display_text = $is_registered ? $s['nama_sekolah'] . " (Sudah Terdaftar)" : $s['nama_sekolah'];
                        ?>
                        <option value="<?= $s['id'] ?>" <?= $disabled ?>><?= $display_text ?></option>
                        <?php 
                        endwhile; 
                        if($current_region != "") echo "</optgroup>";
                        ?>
                    </select>
                </div>
            </div>
            <?php endif; ?>

            <div class="mb-3"> 
                <label class="form-label"><i class="bi bi-person-fill"></i> USERNAME</label>
                <div class="input-group-custom">
                    <i class="bi bi-person-fill"></i>
                    <input type="text" name="username" placeholder="Username" required>
                </div>
            </div>

            <div class="mb-3"> 
                <label class="form-label"><i class="bi bi-key-fill"></i> PASSWORD</label>
                <div class="input-group-custom">
                    <i class="bi bi-key-fill"></i>
                    <input type="password" name="password" id="passwordInput" placeholder="••••••••" required>
                    <i class="bi bi-eye-slash" id="togglePassword" style="cursor: pointer; margin-right: 0; margin-left: 8px;"></i>
                </div>
            </div>

            <button type="submit" name="<?= ($mode=='login') ? 'login' : 'daftar' ?>" class="btn-auth">
                <i class="bi <?= ($mode=='login') ? 'bi-box-arrow-in-right' : 'bi-person-plus-fill' ?>"></i>
                <span><?= ($mode=='login') ? 'MASUK SEKARANG' : 'DAFTAR AKUN' ?></span>
            </button>
        </form>

        <div class="auth-footer-switch"> 
            <?php if($mode=='login'): ?>
                <span>Belum punya akun?</span>
                <a href="?mode=register" class="btn-switch-pill">Daftar</a>
            <?php else: ?>
                <span>Sudah punya akun?</span>
                <a href="?mode=login" class="btn-switch-pill">Login</a>
            <?php endif; ?>
        </div>
    </div>

    <!-- Wave Dekoratif Bawah Kartu (Biru & Kuning) -->
    <svg class="auth-bottom-wave" viewBox="0 0 500 60" preserveAspectRatio="none" aria-hidden="true">
        <path d="M0,12 C140,56 320,42 500,4 L500,60 L0,60 Z" fill="#facc15"></path>
        <path d="M0,16 C140,60 320,46 500,10 L500,60 L0,60 Z" fill="#0ea5e9"></path>
        <path d="M0,22 C135,62 325,50 500,18 L500,60 L0,60 Z" fill="#0252a8"></path>
    </svg>
</div>

<script>
// Toggle Show/Hide Password
const togglePassword = document.querySelector('#togglePassword');
const passwordInput = document.querySelector('#passwordInput');

if (togglePassword && passwordInput) {
    togglePassword.addEventListener('click', function () {
        const type = passwordInput.getAttribute('type') === 'password' ? 'text' : 'password';
        passwordInput.setAttribute('type', type);
        this.classList.toggle('bi-eye');
        this.classList.toggle('bi-eye-slash');
    });
}

<?php if($login_success): ?>
Swal.fire({
    title: 'Login Berhasil!',
    text: 'Selamat datang di <?= addslashes($_SESSION['nama_sekolah']) ?>',
    icon: 'success',
    timer: 2000,
    showConfirmButton: false,
    timerProgressBar: true,
    willClose: () => {
        <?php if($_SESSION['role'] == 'admin'): ?>
            window.location.href = 'admin.php';
        <?php else: ?>
            window.location.href = 'index.php';
        <?php endif; ?>
    }
});
<?php endif; ?>
</script>

</body>
</html>