<?php
session_start();
include 'config/koneksi.php';

/* --- Ambil & normalisasi input --- */
$email_raw = $_POST['email'] ?? '';
$sandi_raw = $_POST['sandi'] ?? '';

$email = strtolower(trim($email_raw));
$pwd_raw  = $sandi_raw;
$pwd_trim = trim($sandi_raw);
/* Hapus invisible chars (zero-width) yang kadang ikut saat copy/paste) */
$pwd_norm = preg_replace('/\x{200B}|\x{200C}|\x{200D}|\x{FEFF}/u', '', $pwd_trim);

/* --- Validasi input kosong --- */
if ($email === '' || $pwd_norm === '') {
    echo "<script>alert('Login gagal! [input]'); location='login.php';</script>";
    exit;
}

/* --- Query user berdasar email --- */
$sql = "SELECT id_pengguna, nama_lengkap, peran, sandi, status_aktif FROM pengguna WHERE email = ?";
$stmt = $conn->prepare($sql);
if (!$stmt) {
    echo "<script>alert('Login gagal! [prep]'); location='login.php';</script>";
    exit;
}

$stmt->bind_param("s", $email);
if (!$stmt->execute()) {
    echo "<script>alert('Login gagal! [exec]'); location='login.php';</script>";
    exit;
}

$stmt->store_result();
if ($stmt->num_rows !== 1) {
    echo "<script>alert('Login gagal! [user]'); location='login.php';</script>";
    exit;
}

$stmt->bind_result($id_pengguna, $nama_lengkap, $peran, $hash, $status_aktif);
$stmt->fetch();

/* --- Cek status aktif --- */
if ((int)$status_aktif !== 1) {
    echo "<script>alert('Login gagal! [inactive]'); location='login.php';</script>";
    exit;
}

/* --- Verifikasi password (uji raw, trim, dan norm) --- */
$ok =
    password_verify($pwd_raw,  $hash) ||
    password_verify($pwd_trim, $hash) ||
    password_verify($pwd_norm, $hash);

if (!$ok) {
    echo "<script>alert('Login gagal! [pass]'); location='login.php';</script>";
    exit;
}

/* --- Sukses: set session aman --- */
session_regenerate_id(true);
$_SESSION['id_pengguna']  = (int)$id_pengguna;
$_SESSION['nama_lengkap'] = $nama_lengkap;
$_SESSION['peran']        = $peran;
$_SESSION['user'] = [
    'id_pengguna'  => (int)$id_pengguna,
    'nama_lengkap' => $nama_lengkap,
    'peran'        => $peran,
];

/* --- Redirect by role --- */
switch ($peran) {
    case 'peserta':
        header("Location: peserta/dashboard_peserta.php");
        exit;
    case 'asesor':
        header("Location: asesor/dashboard_asesor.php");
        exit;
    case 'admin':
    case 'superadmin':
        header("Location: admin/dashboard_admin.php");
        exit;
    default:
        echo "<script>alert('Login gagal! [role]'); location='login.php';</script>";
        exit;
}
