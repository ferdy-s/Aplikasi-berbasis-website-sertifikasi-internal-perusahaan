<?php
include '../config/koneksi.php';
session_start();

$id_pengguna = $_SESSION['id_pengguna'] ?? null;
$nama = $_SESSION['nama_lengkap'] ?? 'Peserta';

if (!$id_pengguna) {
    header("Location: ../login.php");
    exit;
}

$query = mysqli_query($conn, "
    SELECT p.*, k.nama_posisi 
    FROM pendaftaran p
    JOIN posisi k ON p.id_posisi = k.id_posisi
    WHERE p.id_pengguna = $id_pengguna
    ORDER BY p.id_pendaftaran DESC
    LIMIT 1
");

$data = mysqli_fetch_assoc($query);
if (!is_array($data)) $data = [];

$id_pendaftaran = $data['id_pendaftaran'] ?? null;

$penilaian = null;
if ($id_pendaftaran) {
    $cek = mysqli_query($conn, "SELECT * FROM penilaian WHERE id_pendaftaran = $id_pendaftaran");
    $penilaian = mysqli_fetch_assoc($cek);
    if (!is_array($penilaian)) $penilaian = [];
}
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <title>Status Asesmen | RELIPROVE</title>
    <link rel="icon" href="../aset/img/logo.png" type="image/png">

    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../peserta/css/asesmen.css">
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
        <a href="status_asesmen.php" class="active"><i class="fas fa-clipboard-check"></i> Status Asesmen</a>
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
                <h4 class="d-none d-md-block" style="color: white">ASESMEN</h4>
                <h4 class="d-md-none" style="margin-left: 0.5rem; color: white;">ASESMEN</h4>
            </div>
            <div class="user-info">
                <img src="https://ui-avatars.com/api/?name=<?= urlencode($nama) ?>&background=9b5eff&color=fff" alt="avatar">
                <div class="user-meta text-end">
                    <strong><?= htmlspecialchars($nama) ?></strong><br>
                    <small>Peserta</small>
                </div>
            </div>
        </div>
        <div class="tracking-wrapper">
            <div class="tracking-grid">
                <?php
                function badgeItem($label, $value)
                {
                    $sent = !empty($value);
                    $color = $sent ? 'success' : 'secondary';
                    $text = $sent ? 'Terkirim' : 'Belum';
                    return "<div class='badge-item'>
            <span>$label</span>
            <span class='badge bg-$color'>$text</span></div>";
                }

                echo badgeItem('Timeline', $data['link_timeline'] ?? null);
                echo badgeItem('Jobdesk', $data['link_jobdesk'] ?? null);
                echo badgeItem('Portofolio', $data['link_portofolio'] ?? null);
                echo badgeItem('CV', $data['link_cv'] ?? null);
                echo badgeItem('Biodata', $data['link_biodata'] ?? null);
                echo badgeItem('Foto Formal', $data['link_foto_formal'] ?? null);
                echo badgeItem('Foto Transparan', $data['link_foto_transparan'] ?? null);
                echo badgeItem('Dokumen', $data['link_dok_perkuliahan'] ?? null);

                $status = $data['status_verifikasi'] ?? 'belum';
                $bg = match ($status) {
                    'diterima' => 'success',
                    'ditolak' => 'danger',
                    default => 'warning text-dark',
                };
                ?>
                <div class="badge-item wide">
                    <span><strong>Status Verifikasi</strong></span>
                    <span class="badge bg-<?= $bg ?>"><?= strtoupper($status) ?></span>
                </div>
                <?php if (!empty($data)): ?>
                    <?php $link_soal = $penilaian['link_soal'] ?? null; ?>

                    <div class="badge-item soal-asesmen">
                        <h5 class="mb-2 text-light">Soal Asesmen</h5>

                        <?php if ($status === 'ditolak'): ?>
                            <div class="soal-box bg-danger text-dark">
                                <strong>Maaf, pendaftaran kamu ditolak. Soal asesmen tidak tersedia.</strong>
                            </div>

                        <?php elseif ($status === 'diterima'): ?>
                            <?php if ($link_soal): ?>
                                <div class="soal-box bg-success text-dark">
                                    <strong>Soal dari asesor telah tersedia.</strong><br>
                                    <a href="<?= htmlspecialchars($link_soal) ?>" target="_blank" class="btn btn-sm btn-outline-light mt-2">
                                        <i class="fas fa-download"></i> Buka Soal Asesmen
                                    </a>
                                </div>

                                <form action="proses_jawaban.php" method="POST" class="mt-3 w-100">
                                    <label class="form-label text-light">Link Jawaban Google Drive</label>
                                    <input type="url" name="link_jawaban" class="form-control bg-dark text-light" required placeholder="https://drive.google.com/...">
                                    <small class="text-muted">Pastikan link dapat diakses publik, tanpa login.</small>
                                    <input type="hidden" name="id_pendaftaran" value="<?= $id_pendaftaran ?>">
                                    <button type="submit" class="btn btn-outline-primary mt-2">
                                        <i class="fas fa-paper-plane"></i> Kirim Jawaban
                                    </button>
                                </form>

                            <?php else: ?>
                                <div class="soal-box bg-warning text-dark">
                                    <strong>Soal sedang disiapkan oleh asesor. Harap tunggu hingga soal dikirim.</strong>
                                </div>
                            <?php endif; ?>

                        <?php else: ?>
                            <div class="soal-box bg-info text-dark">
                                <strong>Kamu bisa mulai membaca soal setelah pendaftaran diverifikasi oleh tim.</strong>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
                <?php
                $kelulusan = $data['status_kelulusan'] ?? 'belum';
                $bgKel = match ($kelulusan) {
                    'lulus' => 'success',
                    'tidak' => 'danger',
                    default => 'secondary',
                };
                ?>
                <div class="badge-item wide">
                    <span><strong>Status Kelulusan</strong></span>
                    <span class="badge bg-<?= $bgKel ?>"><?= strtoupper($kelulusan) ?></span>
                </div>

            </div>
        </div>


        <div class="footer">
            &copy; <?= date('Y') ?> RELIPROVE — PT Reliable Future Technology
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