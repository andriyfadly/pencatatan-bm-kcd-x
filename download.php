<?php
include "koneksi.php";

if(!isset($_GET['file'])) {
    die("File tidak ditemukan!");
}

$fileName = basename($_GET['file']); // cegah path traversal
$filePath = "update_file/" . $fileName;

// Cek file
if(!file_exists($filePath)) {
    die("File sudah tidak tersedia atau telah dihapus.");
}

// Set header untuk download
header('Content-Description: File Transfer');
header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . $fileName . '"');
header('Expires: 0');
header('Cache-Control: must-revalidate');
header('Pragma: public');
header('Content-Length: ' . filesize($filePath));
flush();
readfile($filePath);

// Setelah download, hapus file
unlink($filePath);

// Update DB: hapus nama file agar tidak bisa di-download lagi
mysqli_query($conn, "UPDATE new_features SET file_update='' WHERE file_update='".mysqli_real_escape_string($conn, $fileName)."'");
exit;
?>