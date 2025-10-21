<?php
session_start();
include '../config/koneksi.php';

if (!isset($_SESSION['id_pengguna']) || $_SESSION['peran'] !== 'peserta') {
    header("Location: ../login.php");
    exit;
}

$id_pengguna = $_SESSION['id_pengguna'];
$nama = $_SESSION['nama_lengkap'] ?? 'Peserta';

// Reset dokumen jika klik ulang
if (isset($_GET['ulang']) && $_GET['ulang'] == '1') {
    mysqli_query($conn, "UPDATE pendaftaran SET 
        link_foto_formal = NULL, 
        link_foto_transparan = NULL, 
        link_cv = NULL, 
        link_biodata = NULL, 
        link_dok_perkuliahan = NULL 
        WHERE id_pengguna = '$id_pengguna'");
    $_SESSION['pesan'] = 'Silakan unggah ulang dokumen kamu.';
    header("Location: unggah_dokumen.php");
    exit;
}

// Ambil data dan tentukan status unggahan
$cek_dokumen = mysqli_query($conn, "SELECT * FROM pendaftaran WHERE id_pengguna = '$id_pengguna'");
$data = mysqli_fetch_assoc($cek_dokumen);
$sudah_unggah = $data && !empty($data['link_foto_formal']);

// Handle Submit
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['simpan'])) {
    $link_foto_formal       = trim($_POST['link_foto_formal']);
    $link_foto_transparan   = trim($_POST['link_foto_transparan']);
    $link_cv                = trim($_POST['link_cv']);
    $link_biodata           = trim($_POST['link_biodata']);
    $link_dok_perkuliahan   = trim($_POST['link_dok_perkuliahan']);

    $isValid = (
        $link_foto_formal && str_contains($link_foto_formal, 'drive.google.com') &&
        $link_foto_transparan && str_contains($link_foto_transparan, 'drive.google.com') &&
        $link_biodata && str_contains($link_biodata, 'drive.google.com') &&
        $link_dok_perkuliahan && str_contains($link_dok_perkuliahan, 'drive.google.com')
    );

    if ($isValid) {
        $query = "UPDATE pendaftaran SET 
            link_foto_formal = '$link_foto_formal',
            link_foto_transparan = '$link_foto_transparan',
            link_cv = '$link_cv',
            link_biodata = '$link_biodata',
            link_dok_perkuliahan = '$link_dok_perkuliahan'
            WHERE id_pengguna = '$id_pengguna'";

        if (mysqli_query($conn, $query)) {
            $_SESSION['pesan'] = "Dokumen berhasil diunggah.";
            header("Location: unggah_dokumen.php");
            exit;
        } else {
            $error = "Gagal menyimpan dokumen. Silakan coba lagi.";
        }
    } else {
        $error = "Semua bidang wajib diisi dengan link Google Drive yang valid. CV opsional.";
    }
}
?>

<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Upload Dokumen | RELIPROVE</title>
    <link rel="icon" href="../aset/img/logo.png" type="image/png">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../peserta/css/dokumen.css">
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
        <a href="unggah_dokumen.php" class="active"><i class="fas fa-upload"></i> Upload Dokumen</a>
        <a href="status_asesmen.php"><i class="fas fa-clipboard-check"></i> Status Asesmen</a>
        <a href="sertifikat.php"><i class="fas fa-graduation-cap"></i> Sertifikat</a>
        <a href="pengaturan.php"><i class="fas fa-gear"></i> Pengaturan</a>
        <a href="../logout.php"><i class="fas fa-sign-out-alt"></i> Keluar</a>
    </div>

    <div class="content">
        <div class="topbar">
            <div class="left-group">
                <div class="toggle-sidebar" onclick="document.getElementById('sidebar').classList.toggle('active')">
                    <i class="fas fa-bars"></i>
                </div>
                <h4 class="d-none d-md-block" style="color: white">DOKUMEN</h4>
                <h4 class="d-md-none">DOKUMEN</h4>
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

        <div class="main-content">
            <?php if ($sudah_unggah): ?>
                <div class="card-pendaftaran">
                    <h3>DOKUMEN YANG TELAH DIUNGGAH</h3>
                    <div class="info-group"><span class="label">Foto Formal:</span> <a href="<?= $data['link_foto_formal'] ?>" target="_blank"><?= $data['link_foto_formal'] ?></a></div>
                    <div class="info-group"><span class="label">Foto Transparan:</span> <a href="<?= $data['link_foto_transparan'] ?>" target="_blank"><?= $data['link_foto_transparan'] ?></a></div>
                    <?php if (!empty($data['link_cv'])): ?>
                        <div class="info-group"><span class="label">CV:</span> <a href="<?= $data['link_cv'] ?>" target="_blank"><?= $data['link_cv'] ?></a></div>
                    <?php endif; ?>
                    <div class="info-group"><span class="label">Biodata:</span> <a href="<?= $data['link_biodata'] ?>" target="_blank"><?= $data['link_biodata'] ?></a></div>
                    <div class="info-group"><span class="label">Dokumen Pendidikan:</span> <a href="<?= $data['link_dok_perkuliahan'] ?>" target="_blank"><?= $data['link_dok_perkuliahan'] ?></a></div>
                    <div class="status">
                        <span>Status Verifikasi : <strong><?= ucfirst($data['status_verifikasi']) ?></strong></span><br>
                        <span>Status Asesmen : <strong><?= ucfirst($data['status_penilaian']) ?></strong></span>
                    </div>
                    <div style="text-align: center; margin-top: 1.5rem;">
                        <a href="unggah_dokumen.php?ulang=1"
                            onclick="return confirm('Apakah kamu yakin ingin mengisi ulang dokumen? Data sebelumnya akan dihapus.')"
                            class="btn btn-sm"
                            style="padding: 0.6rem 1.4rem; border-radius: 10px; background-color: var(--primary); color: white; font-weight: 600; border: none;">
                            Isi Ulang Dokumen
                        </a>
                    </div>
                </div>
            <?php else: ?>
                <div class="form-wrapper">
                    <h3 class="mb-4 text-center">UPLOAD DOKUMEN PENDUKUNG</h3>
                    <form action="" method="POST" novalidate>
                        <div class="mb-3">
                            <label class="form-label">Link Foto Formal</label>
                            <input type="url" class="form-control" name="link_foto_formal" required placeholder="Link Google Drive" value="<?= htmlspecialchars($data['link_foto_formal'] ?? '') ?>">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Link Foto Transparan</label>
                            <input type="url" class="form-control" name="link_foto_transparan" required placeholder="Link Google Drive" value="<?= htmlspecialchars($data['link_foto_transparan'] ?? '') ?>">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Link CV (Opsional)</label>
                            <input type="url" class="form-control" name="link_cv" placeholder="Link Google Drive" value="<?= htmlspecialchars($data['link_cv'] ?? '') ?>">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Link Biodata</label>
                            <input type="url" class="form-control" name="link_biodata" required placeholder="Link Google Drive" value="<?= htmlspecialchars($data['link_biodata'] ?? '') ?>">
                        </div>
                        <div class="mb-4">
                            <label class="form-label">Link Dokumen Pendidikan</label>
                            <input type="url" class="form-control" name="link_dok_perkuliahan" required placeholder="Link Google Drive" value="<?= htmlspecialchars($data['link_dok_perkuliahan'] ?? '') ?>">
                        </div>
                        <button type="submit" name="simpan" class="btn btn-primary w-100">Simpan Dokumen</button>
                    </form>
                </div>
            <?php endif; ?>
        </div>

        <div class="footer">
            &copy; <?= date('Y') ?> Created by PT. Reliable Future Technology
        </div>
    </div>
</body>

<script>
    document.addEventListener('click', function(event) {
        const sidebar = document.getElementById('sidebar');
        const toggle = document.querySelector('.toggle-sidebar');
        if (!sidebar.contains(event.target) && !toggle.contains(event.target)) {
            sidebar.classList.remove('active');
        }
    });
</script>

</html>