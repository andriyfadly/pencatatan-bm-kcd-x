<?php
// Ambil ID dan Nomor Registrasi dari URL
$id  = $_GET['id']  ?? '';
$reg = $_GET['reg'] ?? '';

/* DOMAIN HOSTING */
// Pastikan link ini aktif dan bisa diakses lewat internet/lokal
$base_url = "https://nicolle-subcordiform-gianna.ngrok-free.dev/rev_projek_sekolah/";

/* LINK TUJUAN SAAT QR DISCAN */
$link = $base_url . "salur.php?id_barang=" . urlencode($id) . "&reg=" . urlencode($reg);

/* ENCODE AGAR URL TIDAK RUSAK */
$qr_data = urlencode($link);

/* GENERATE QR MENGGUNAKAN API */
/** * PERUBAHAN:
 * - size ditingkatkan ke 300x300 (biar lebih tajam)
 * - margin diubah ke 0 (agar hitam-hitam QR mentok ke pinggir/lebih gede)
 */
header("Location: https://api.qrserver.com/v1/create-qr-code/?size=300x300&margin=0&data=".$qr_data);
exit;
?>