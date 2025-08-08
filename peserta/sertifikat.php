<?php
include '../config/koneksi.php';
session_start();

if (!isset($_SESSION['id_pengguna'])) {
    header("Location: ../login.php");
    exit;
}

$id_pengguna = $_SESSION['id_pengguna'];
$pengguna = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM pengguna WHERE id_pengguna = '$id_pengguna'"));

$pendaftaran = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM pendaftaran WHERE id_pengguna = '$id_pengguna' ORDER BY id_pendaftaran DESC LIMIT 1"));
$id_pendaftaran = $pendaftaran['id_pendaftaran'] ?? 0;

$penilaian = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM penilaian WHERE id_pendaftaran = '$id_pendaftaran'"));
$sertifikat = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM sertifikat WHERE id_pendaftaran = '$id_pendaftaran'"));

$nama = htmlspecialchars($pengguna['nama_lengkap']);
$slug = $sertifikat['slug_sertifikat'] ?? (strtolower(str_replace(' ', '_', $nama)) . '_' . sprintf('%05d', $id_pengguna));
$link_verifikasi = "https://reliprove.com/verifikasi.php?kode=$slug";
$qr_image_url = "https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=" . urlencode($link_verifikasi);
?>


<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Sertifikat | RELIPROVE</title>
    <link rel="icon" href="../aset/img/logo.png" type="image/png">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../peserta/css/sertifikat.css">
</head>

<body>
    <div class="sidebar" id="sidebar">
        <div class="sidebar-title">
            <h2 class="sidebar-brand d-none d-md-block">RELIPROVE</h2> <!-- ❌ Hidden on mobile -->
            <button class="sidebar-close d-md-none" onclick="document.getElementById('sidebar').classList.remove('active')">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <a href="dashboard_peserta.php"><i class="fas fa-gauge"></i> Dashboard</a>
        <a href="form_registrasi.php"><i class="fas fa-file-lines"></i> Form Registrasi</a>
        <a href="unggah_dokumen.php"><i class="fas fa-upload"></i> Upload Dokumen</a>
        <a href="status_asesmen.php"><i class="fas fa-clipboard-check"></i> Status Asesmen</a>
        <a href="sertifikat.php" class="active"><i class="fas fa-graduation-cap"></i> Sertifikat</a>
        <a href="../logout.php"><i class="fas fa-sign-out-alt"></i> Keluar</a>
    </div>

    <div class="content">
        <div class="topbar">
            <div class="left-group">
                <div class="toggle-sidebar" onclick="document.getElementById('sidebar').classList.toggle('active')">
                    <i class="fas fa-bars"></i>
                </div>
                <h4 class="d-none d-md-block" style="color: white">SERTIFIKAT</h4>
                <h4 class="d-md-none">SERTIFIKAT</h4>
            </div>
            <div class="right-group">
                <div class="user-info">
                    <img src="https://ui-avatars.com/api/?name=<?= urlencode($nama) ?>&background=9b5eff&color=fff&rounded=true&size=40" alt="avatar">
                    <div class="user-meta">
                        <strong><?= htmlspecialchars($nama) ?></strong><br>
                        <small>Peserta</small>
                    </div>
                </div>
            </div>
        </div>

        <div class="content-sert">
            <div class="certificate">
                <?php if (!$penilaian || $penilaian['rekomendasi'] !== 'layak'): ?>
                    <h1>Sertifikat Belum Tersedia</h1>
                    <p>Hai <strong><?= $nama ?></strong>, kamu belum menyelesaikan proses asesmen atau belum mendapatkan rekomendasi dari asesor.</p>
                <?php elseif (!$sertifikat || empty($sertifikat['link_file_sertifikat'])): ?>
                    <h1>Sedang Diproses</h1>
                    <p>Asesmen kamu sudah disetujui, namun sertifikatmu belum diunggah oleh asesor.</p>
                <?php else: ?>
                    <h1>SERTIFIKAT KOMPETENSI</h1>
                    <p>Selamat, <strong><?= $nama ?></strong>!</p>
                    <p>Kamu telah dinyatakan <strong style="color: var(--accent);">kompeten</strong> dalam <strong><?= ucfirst($sertifikat['tipe_sertifikat']) ?></strong> dengan level <strong><?= ucfirst($sertifikat['level_kompetensi']) ?></strong>.</p>
                    <p>Sertifikatmu telah diterbitkan pada <strong><?= date('d F Y', strtotime($sertifikat['tanggal_terbit'])) ?></strong></p>

                    <a href="<?= $sertifikat['link_file_sertifikat'] ?>" class="download-btn" target="_blank">
                        <i class="fas fa-download"></i> Unduh Sertifikat
                    </a>

                    <div style="margin-top: 2rem;">
                        <p><strong>Verifikasi Sertifikat:</strong></p>
                        <p>Ketik URL: <code>reliprove.com/verifikasi.php?kode=<?= $slug ?></code></p>
                        <img src="<?= $qr_image_url ?>" alt="QR Code" class="qr">
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="footer">
            &copy; <?= date('Y') ?> Created by PT. Reliable Future Technology
        </div>
    </div>

    <script>
        document.addEventListener('click', function(event) {
            const sidebar = document.getElementById('sidebar');
            const toggle = document.querySelector('.toggle-sidebar');
            if (!sidebar.contains(event.target) && !toggle.contains(event.target)) {
                sidebar.classList.remove('active');
            }
        });
    </script>
</body>

</html>