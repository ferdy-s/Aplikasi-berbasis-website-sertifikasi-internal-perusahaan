<?php
session_start();
require_once __DIR__ . '/../config/koneksi.php';

if (!isset($_SESSION['id_pengguna'])) {
    header('Location: ../login.php');
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: status_asesmen.php');
    exit;
}

$id_pendaftaran = (int)($_POST['id_pendaftaran'] ?? 0);
$link_jawaban   = trim($_POST['link_jawaban'] ?? '');

if ($id_pendaftaran <= 0 || $link_jawaban === '') {
    $_SESSION['flash_error'] = 'Data tidak valid.';
    header('Location: status_asesmen.php');
    exit;
}
if (!preg_match('~^https?://(drive|docs)\.google\.com/~i', $link_jawaban)) {
    $_SESSION['flash_error'] = 'Link jawaban harus Google Drive/Docs.';
    header('Location: status_asesmen.php');
    exit;
}

/* Coba UPDATE dulu (jika baris penilaian sudah ada) */
$upd = $conn->prepare("UPDATE penilaian SET link_jawaban = ? WHERE id_pendaftaran = ?");
$upd->bind_param('si', $link_jawaban, $id_pendaftaran);
$upd->execute();

// setelah berhasil simpan/UPDATE penilaian.link_jawaban …
$st2 = $conn->prepare("UPDATE pendaftaran SET status_penilaian = 'belum', status_kelulusan='belum' WHERE id_pendaftaran = ?");
$st2->bind_param('i', $id_pendaftaran);
$st2->execute();
$st2->close();

if ($upd->affected_rows > 0) {
    $ok = true;
    $err = '';
} else {
    /* Belum ada baris -> INSERT dengan id_asesor NULL (diizinkan oleh FK jika kolomnya NULLable) */
    $ins = $conn->prepare("INSERT INTO penilaian (id_pendaftaran, id_asesor, link_jawaban) VALUES (?, NULL, ?)");
    $ins->bind_param('is', $id_pendaftaran, $link_jawaban);
    $ok = $ins->execute();
    $err = $ins->error ?? '';
    $ins->close();
}
$upd->close();

$_SESSION['flash_' . ($ok ? 'success' : 'error')] = $ok ? 'Link jawaban terkirim.' : ('Gagal menyimpan jawaban: ' . $err);
header('Location: status_asesmen.php');
exit;
