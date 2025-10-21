<?php
session_start();
include '../config/koneksi.php';

if (!isset($_SESSION['id_pengguna'])) {
    header("Location: ../login.php");
    exit;
}

$id_pengguna = $_SESSION['id_pengguna'];
$judul       = trim($_POST['judul']);
$deskripsi   = trim($_POST['deskripsi']);

// Simpan ke database (opsional)
mysqli_query($conn, "INSERT INTO aduan (id_pengguna, judul, deskripsi, tanggal) 
                     VALUES ('$id_pengguna', '" . mysqli_real_escape_string($conn, $judul) . "', '" . mysqli_real_escape_string($conn, $deskripsi) . "', NOW())");

// Nomor tujuan (format internasional tanpa 0 di depan → +62821...)
$tujuan = "6282134027993";

// Pesan WA
$pesan = "*Aduan Baru dari Peserta RELIPROVE*\n\n" .
    "Judul: $judul\n" .
    "Deskripsi:\n$deskripsi\n\n" .
    "ID Peserta: $id_pengguna";

// Kirim via Fonnte API
$curl = curl_init();
curl_setopt_array($curl, array(
    CURLOPT_URL => "https://api.fonnte.com/send",
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CUSTOMREQUEST => "POST",
    CURLOPT_POSTFIELDS => array(
        'target' => $tujuan,
        'message' => $pesan
    ),
    CURLOPT_HTTPHEADER => array(
        "Authorization: API_TOKEN_ANDA" // ganti dengan API token dari Fonnte
    ),
));
$response = curl_exec($curl);
curl_close($curl);

// Redirect kembali
echo "<script>alert('Aduan berhasil dikirim ke admin via WhatsApp');window.location.href='../pengaturan.php';</script>";
