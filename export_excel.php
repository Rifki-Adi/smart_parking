<?php
session_start();
require 'db_config.php';

// Pastikan hanya admin yang bisa download
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    die("Akses Ditolak!");
}

header("Content-Type: application/vnd-ms-excel");
header("Content-Disposition: attachment; filename=Laporan_Transaksi_SmartParking.xls");
header("Pragma: no-cache");
header("Expires: 0");

$data = $conn->query("
    SELECT t.*, p.nama, p.plat_nomor 
    FROM transaksi t 
    JOIN profiles p ON t.user_id = p.id 
    ORDER BY t.created_at DESC
")->fetchAll();
?>

<table border="1">
    <thead>
        <tr style="background-color: #1abc9c; color: white;">
            <th>Waktu Transaksi</th>
            <th>Nama User</th>
            <th>Plat Nomor</th>
            <th>Tipe Transaksi</th>
            <th>Nominal (Rp)</th>
            <th>Keterangan Lengkap</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach($data as $d): ?>
        <tr>
            <td><?= $d['created_at'] ?></td>
            <td><?= $d['nama'] ?></td>
            <td><?= $d['plat_nomor'] ?></td>
            <td><?= strtoupper($d['tipe']) ?></td>
            <td><?= $d['jumlah'] ?></td>
            <td><?= $d['keterangan'] ?></td>
        </tr>
        <?php endforeach; ?>
    </tbody>
</table>