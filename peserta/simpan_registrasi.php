<?php
session_start();
include __DIR__ . '/../config/koneksi.php';

// Cek autentikasi peserta
if (!isset($_SESSION['id_pengguna']) || $_SESSION['peran'] !== 'peserta') {
    header("Location: ../login.php");
    exit;
}

$id_pengguna     = $_SESSION['id_pengguna'];
$id_posisi       = $_POST['id_posisi'] ?? '';
$link_timeline   = trim($_POST['link_timeline'] ?? '');
$link_jobdesk    = trim($_POST['link_jobdesk'] ?? '');
$link_portofolio = trim($_POST['link_portofolio'] ?? null); // opsional
$pendidikan      = trim($_POST['pendidikan'] ?? '');
$asal_peserta    = $_POST['asal_peserta'] ?? 'internal';
$nama_instansi   = $_POST['nama_instansi'] ?? 'PT. Reliable Future Technology';
$no_identitas    = $_POST['no_identitas'] ?? '';

// Validasi wajib
if (empty($id_posisi) || empty($link_timeline) || empty($link_jobdesk) || empty($pendidikan)) {
    $_SESSION['pesan'] = "Semua field wajib diisi kecuali portofolio.";
    header("Location: form_registrasi.php");
    exit;
}

// Cek apakah sudah mendaftar sebelumnya
$cek = mysqli_query($conn, "SELECT 1 FROM pendaftaran WHERE id_pengguna = '$id_pengguna'");
if (mysqli_num_rows($cek) > 0) {
    $_SESSION['pesan'] = "Kamu sudah mengisi form sebelumnya.";
    header("Location: form_registrasi.php");
    exit;
}

// Update data ke tabel pengguna
$stmt_pengguna = mysqli_prepare($conn, "UPDATE pengguna 
    SET pendidikan = ?, asal_peserta = ?, nama_instansi = ?, no_identitas = ? 
    WHERE id_pengguna = ?");
mysqli_stmt_bind_param($stmt_pengguna, 'ssssi', $pendidikan, $asal_peserta, $nama_instansi, $no_identitas, $id_pengguna);
mysqli_stmt_execute($stmt_pengguna);

// Simpan ke tabel pendaftaran
$stmt = mysqli_prepare($conn, "INSERT INTO pendaftaran (
    id_pengguna, id_posisi, link_timeline, link_jobdesk, link_portofolio,
    status_verifikasi, status_penilaian, tanggal_daftar
) VALUES (?, ?, ?, ?, ?, 'pending', 'belum', NOW())");

mysqli_stmt_bind_param($stmt, 'iisss', $id_pengguna, $id_posisi, $link_timeline, $link_jobdesk, $link_portofolio);

if (mysqli_stmt_execute($stmt)) {
    $_SESSION['pesan'] = "Pendaftaran berhasil!";
    header("Location: dashboard_peserta.php");
    exit;
} else {
    echo "Gagal menyimpan data: " . mysqli_error($conn);
}
