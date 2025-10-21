<?php
session_start();
require_once '../config/koneksi.php';

// hanya via POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../pengaturan.php');
    exit;
}

// wajib login
if (!isset($_SESSION['id_pengguna'])) {
    header('Location: ../login.php');
    exit;
}

$id_pengguna = (int) $_SESSION['id_pengguna'];

// ambil input
$password_lama       = isset($_POST['password_lama']) ? trim($_POST['password_lama']) : '';
$password_baru       = isset($_POST['password_baru']) ? trim($_POST['password_baru']) : '';
$password_konfirmasi = isset($_POST['password_konfirmasi']) ? trim($_POST['password_konfirmasi']) : '';

// validasi dasar
if ($password_lama === '' || $password_baru === '' || $password_konfirmasi === '') {
    echo "<script>alert('Semua kolom wajib diisi');history.back();</script>";
    exit;
}
if ($password_baru !== $password_konfirmasi) {
    echo "<script>alert('Konfirmasi password baru tidak cocok');history.back();</script>";
    exit;
}
if (strlen($password_baru) < 8) {
    echo "<script>alert('Password baru minimal 8 karakter');history.back();</script>";
    exit;
}

// ambil hash saat ini
$stmt = $conn->prepare("SELECT sandi FROM pengguna WHERE id_pengguna = ?");
$stmt->bind_param("i", $id_pengguna);
$stmt->execute();
$stmt->bind_result($hash_db);
if (!$stmt->fetch()) {
    $stmt->close();
    echo "<script>alert('Akun tidak ditemukan');history.back();</script>";
    exit;
}
$stmt->close();

// verifikasi password lama
if (!password_verify($password_lama, $hash_db)) {
    echo "<script>alert('Password lama salah');history.back();</script>";
    exit;
}

// cegah update ke password yang sama
if (password_verify($password_baru, $hash_db)) {
    echo "<script>alert('Password baru tidak boleh sama dengan password lama');history.back();</script>";
    exit;
}

// update hash baru
$hash_baru = password_hash($password_baru, PASSWORD_DEFAULT);
$stmt = $conn->prepare("UPDATE pengguna SET sandi = ? WHERE id_pengguna = ?");
$stmt->bind_param("si", $hash_baru, $id_pengguna);
if ($stmt->execute()) {
    $stmt->close();
    echo "<script>alert('Password berhasil diperbarui');window.location.href='../peserta/pengaturan.php';</script>";
    exit;
} else {
    $stmt->close();
    echo "<script>alert('Gagal memperbarui password');history.back();</script>";
    exit;
}
