<?php
include __DIR__ . '/../config/koneksi.php';

$id_bidang = $_GET['bidang'] ?? '';
$data = [];

if (is_numeric($id_bidang)) {
    $q = mysqli_query($conn, "SELECT id_kategori, nama_kategori FROM kategori WHERE id_bidang = '$id_bidang' ORDER BY nama_kategori ASC");
    while ($row = mysqli_fetch_assoc($q)) {
        $data[] = $row;
    }
}

header('Content-Type: application/json');
echo json_encode($data);
