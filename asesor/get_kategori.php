<?php
// asesor/get_kategori.php
session_start();
require_once __DIR__ . '/../config/koneksi.php';

header('Content-Type: application/json; charset=utf-8');

$id_bidang = isset($_GET['id_bidang']) ? (int)$_GET['id_bidang'] : 0;

if ($id_bidang > 0) {
    $stmt = $conn->prepare("SELECT id_kategori, nama_kategori FROM kategori WHERE id_bidang = ? ORDER BY nama_kategori ASC");
    $stmt->bind_param("i", $id_bidang);
    $stmt->execute();
    $res = $stmt->get_result();
} else {
    // Semua kategori
    $res = $conn->query("SELECT id_kategori, nama_kategori FROM kategori ORDER BY nama_kategori ASC");
}

$out = [
    ["value" => 0, "label" => "Semua Kategori"]
];
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $out[] = ["value" => (int)$row['id_kategori'], "label" => $row['nama_kategori']];
    }
}

echo json_encode($out);
