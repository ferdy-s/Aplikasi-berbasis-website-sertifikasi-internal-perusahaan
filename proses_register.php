<?php
include 'config/koneksi.php';

$nama  = trim($_POST['nama']);
$email = trim($_POST['email']);
$sandi = password_hash($_POST['sandi'], PASSWORD_BCRYPT);
$peran = $_POST['peran'];

// Blokir jika ada upaya manipulasi peran
if (strtolower($peran) !== 'peserta') {
    die('Pendaftaran hanya diperbolehkan untuk peserta.');
}

// Cek email sudah terdaftar atau belum
$cek = $conn->prepare("SELECT id_pengguna FROM pengguna WHERE email = ?");
$cek->bind_param("s", $email);
$cek->execute();
$cek->store_result();

if ($cek->num_rows > 0) {
    // Email sudah digunakan
    echo "<script>alert('Email sudah terdaftar! Gunakan email lain.'); window.location.href='register.php';</script>";
    exit;
}
$cek->close();

// Insert ke database
$stmt = $conn->prepare("INSERT INTO pengguna (nama_lengkap, email, sandi, peran) VALUES (?, ?, ?, ?)");
$stmt->bind_param("ssss", $nama, $email, $sandi, $peran);

if ($stmt->execute()) {
    // Registrasi sukses, redirect ke login
    header("Location: login.php?register=success");
    exit;
} else {
    // Gagal insert
    echo "<script>alert('Terjadi kesalahan saat mendaftar. Silakan coba lagi.'); window.location.href='register.php';</script>";
    exit;
}
