<?php
// asesor/simpan_link_soal.php
session_start();
require_once __DIR__ . '/../config/koneksi.php';

if (!isset($_SESSION['id_pengguna'])) {
    header('Location: ../login.php');
    exit;
}
$peran = $_SESSION['peran'] ?? '';
if (!in_array($peran, ['asesor', 'admin'], true)) {
    header('Location: ../index.php');
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: dashboard_asesor.php');
    exit;
}

$id_pendaftaran = (int)($_POST['id_pendaftaran'] ?? 0);
$link_soal      = trim($_POST['link_soal'] ?? '');
$return_to      = $_POST['return_to'] ?? 'dashboard_asesor.php#blok-siap-dinilai';
$id_asesor      = (int)($_SESSION['id_pengguna'] ?? 0);

if ($id_pendaftaran <= 0 || $link_soal === '') {
    $_SESSION['flash_error'] = 'ID atau link soal tidak valid.';
    header('Location: ' . $return_to);
    exit;
}

if (!preg_match('~^https?://(drive|docs)\.google\.com/~i', $link_soal)) {
    $_SESSION['flash_error'] = 'Link soal harus dari Google Drive/Docs.';
    header('Location: ' . $return_to);
    exit;
}

// validasi pendaftaran ada
$cek = $conn->prepare("SELECT 1 FROM pendaftaran WHERE id_pendaftaran=?");
$cek->bind_param('i', $id_pendaftaran);
$cek->execute();
if ($cek->get_result()->num_rows === 0) {
    $_SESSION['flash_error'] = 'Pendaftaran tidak ditemukan.';
    header('Location: ' . $return_to);
    exit;
}
$cek->close();

// pastikan asesor valid (untuk FK)
$cek2 = $conn->prepare("SELECT 1 FROM pengguna WHERE id_pengguna=?");
$cek2->bind_param('i', $id_asesor);
$cek2->execute();
if ($cek2->get_result()->num_rows === 0) {
    $_SESSION['flash_error'] = 'Asesor tidak valid.';
    header('Location: ' . $return_to);
    exit;
}
$cek2->close();

// upsert penilaian
$sql = "INSERT INTO penilaian (id_pendaftaran, id_asesor, link_soal)
        VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE link_soal = VALUES(link_soal)";
$st = $conn->prepare($sql);
$st->bind_param('iis', $id_pendaftaran, $id_asesor, $link_soal);
$ok = $st->execute();
$err = $st->error ?? '';
$st->close();

$_SESSION['flash_' . ($ok ? 'success' : 'error')] = $ok ? 'Link soal disimpan.' : ('Gagal menyimpan: ' . $err);

// sanitize redirect
if (preg_match('~^(?:[a-zA-Z0-9_\-\/\.#]+)$~', $return_to) !== 1) {
    $return_to = 'dashboard_asesor.php#blok-siap-dinilai';
}
header('Location: ' . $return_to);
exit;
