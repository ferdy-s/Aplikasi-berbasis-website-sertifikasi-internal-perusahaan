<?php
// asesor/get_posisi.php
session_start();
require_once __DIR__ . '/../config/koneksi.php';

header('Content-Type: application/json; charset=utf-8');

$id_kategori = isset($_GET['id_kategori']) ? (int)$_GET['id_kategori'] : 0;
$id_bidang   = isset($_GET['id_bidang'])   ? (int)$_GET['id_bidang']   : 0;

if ($id_kategori > 0) {
    $stmt = $conn->prepare("SELECT id_posisi, nama_posisi FROM posisi WHERE id_kategori = ? ORDER BY nama_posisi ASC");
    $stmt->bind_param("i", $id_kategori);
    $stmt->execute();
    $res = $stmt->get_result();
} elseif ($id_bidang > 0) {
    // Posisi berdasarkan bidang (join lewat kategori)
    $stmt = $conn->prepare("
        SELECT p.id_posisi, p.nama_posisi
        FROM posisi p
        JOIN kategori k ON k.id_kategori = p.id_kategori
        WHERE k.id_bidang = ?
        ORDER BY p.nama_posisi ASC
    ");
    $stmt->bind_param("i", $id_bidang);
    $stmt->execute();
    $res = $stmt->get_result();
} else {
    // Semua posisi
    $res = $conn->query("SELECT id_posisi, nama_posisi FROM posisi ORDER BY nama_posisi ASC");
}

$out = [
    ["value" => 0, "label" => "Semua Posisi"]
];
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $out[] = ["value" => (int)$row['id_posisi'], "label" => $row['nama_posisi']];
    }
}

echo json_encode($out);
