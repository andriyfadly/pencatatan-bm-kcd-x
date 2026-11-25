<?php
require('fpdf/fpdf.php');

$pdf = new FPDF('L','mm','A4');
$pdf->AddPage();
$pdf->SetFont('Arial','B',12);

// JUDUL
$pdf->Cell(0,7,'BUKU INVENTARIS',0,1,'C');
$pdf->Ln(5);

// ===== HEADER =====
$pdf->SetFont('Arial','B',9);

$y = $pdf->GetY(); // simpan posisi Y
$x = 10;           // margin kiri default FPDF

// KOLOM KIRI
$pdf->Cell(10,14,'No',1,0,'C');
$pdf->Cell(25,14,'Kode Barang',1,0,'C');
$pdf->Cell(15,14,'Reg',1,0,'C');

// POSISI AWAL SPESIFIKASI
$xSpek = $pdf->GetX();

// SPESIFIKASI ATAS
$pdf->Cell(75,7,'SPESIFIKASI',1,0,'C');

// KOLOM KANAN
$pdf->Cell(20,14,'Bahan',1,0,'C');
$pdf->Cell(25,14,'Asal',1,0,'C');
$pdf->Cell(15,14,'Bln',1,0,'C');
$pdf->Cell(20,14,'Tahun',1,1,'C');

// ===== BALIK KE ATAS SPESIFIKASI =====
$pdf->SetXY($xSpek, $y + 7);

// SUB KOLOM SPESIFIKASI
$pdf->Cell(25,7,'Nama/Jenis',1,0,'C');
$pdf->Cell(25,7,'Merek/Type',1,0,'C');
$pdf->Cell(25,7,'No Identitas',1,0,'C');

$pdf->Ln(7); // sekarang baru turun baris

// ===== CONTOH DATA =====
$pdf->SetFont('Arial','',9);
$pdf->Cell(10,7,'1',1);
$pdf->Cell(25,7,'1.3.2',1);
$pdf->Cell(15,7,'1-3',1);
$pdf->Cell(25,7,'PC Unit',1);
$pdf->Cell(25,7,'HP AIO',1);
$pdf->Cell(25,7,'-',1);
$pdf->Cell(20,7,'Campuran',1);
$pdf->Cell(25,7,'APBD',1);
$pdf->Cell(15,7,'',1);
$pdf->Cell(20,7,'2022',1);

$pdf->Output();
?>
