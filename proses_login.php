<?php
session_start();
include 'config/koneksi.php';

$email = $_POST['email'];
$sandi = $_POST['sandi'];

$stmt = $conn->prepare("SELECT * FROM pengguna WHERE email = ?");
$stmt->bind_param("s", $email);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();

if ($user && password_verify($sandi, $user['sandi'])) {
    $_SESSION['id_pengguna'] = $user['id_pengguna'];
    $_SESSION['nama_lengkap'] = $user['nama_lengkap'];
    $_SESSION['peran'] = $user['peran'];

    // Redirect by role
    switch ($user['peran']) {
        case 'peserta':
            header("Location: peserta/dashboard_peserta.php");
            break;
        case 'asesor':
            header("Location: dashboard_asesor.php");
            break;
        case 'admin':
        case 'superadmin':
            header("Location: dashboard/dashboard_admin.php");
            break;
    }
} else {
    echo "<script>alert('Login gagal!'); window.location='login.php';</script>";
}
