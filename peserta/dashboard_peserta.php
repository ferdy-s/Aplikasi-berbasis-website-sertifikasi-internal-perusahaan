<?php
ob_start();
session_start();
include __DIR__ . '/../config/koneksi.php';

if (!isset($_SESSION['id_pengguna']) || $_SESSION['peran'] !== 'peserta') {
    header("Location: ../login.php");
    exit;
}

$id = $_SESSION['id_pengguna'];
$nama = $_SESSION['nama_lengkap'];

// Cek apakah peserta sudah mengisi form registrasi
$cek_form = mysqli_num_rows(mysqli_query($conn, "
    SELECT * FROM pendaftaran 
    WHERE id_pengguna = '$id'
"));

// Ambil data pendaftaran terakhir
$data_dokumen = mysqli_fetch_assoc(mysqli_query($conn, "
    SELECT * FROM pendaftaran 
    WHERE id_pengguna = '$id' 
    ORDER BY id_pendaftaran DESC 
    LIMIT 1
"));

// Default status
$status_dokumen    = 'Belum Diunggah';
$trend_dokumen     = -1;
$status_asesmen    = 'Belum Lengkap';
$trend_asesmen     = -1;
$id_pendaftaran    = null;

if ($data_dokumen) {
    $id_pendaftaran = $data_dokumen['id_pendaftaran'];

    // Cek kelengkapan dokumen
    $dokumen_lengkap =
        !empty($data_dokumen['link_foto_formal']) &&
        !empty($data_dokumen['link_foto_transparan']) &&
        !empty($data_dokumen['link_biodata']) &&
        !empty($data_dokumen['link_dok_perkuliahan']) &&
        !empty($data_dokumen['link_timeline']) &&
        !empty($data_dokumen['link_jobdesk']) &&
        !empty($data_dokumen['link_portofolio']) &&
        !empty($data_dokumen['link_cv']);

    if ($dokumen_lengkap) {
        $status_dokumen = 'Sudah Mengunggah';
        $trend_dokumen  = 1;

        $status_verifikasi = $data_dokumen['status_verifikasi'] ?? 'belum';

        if ($status_verifikasi === 'diterima') {
            // Cek apakah sudah dinilai
            $cek_penilaian = mysqli_fetch_assoc(mysqli_query($conn, "
                SELECT rekomendasi FROM penilaian 
                WHERE id_pendaftaran = '$id_pendaftaran' 
                LIMIT 1
            "));

            if (!empty($cek_penilaian['rekomendasi'])) {
                $status_asesmen  = 'Selesai';
                $trend_asesmen   = 1;
            } else {
                $status_asesmen  = 'Mengerjakan Soal';
                $trend_asesmen   = 0;
            }
        } else {
            $status_asesmen      = 'Direview';
            $trend_asesmen       = 0;
        }
    } else {
        $status_asesmen      = 'Belum Lengkap';
        $trend_asesmen       = -1;
    }
}

// Cek apakah peserta sudah memiliki sertifikat
$cek_sertifikat = mysqli_num_rows(mysqli_query($conn, "
    SELECT * FROM sertifikat 
    WHERE id_pendaftaran IN (
        SELECT id_pendaftaran 
        FROM pendaftaran 
        WHERE id_pengguna = '$id'
    )
"));
?>


<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Dashboard Peserta | RELIPROVE</title>
    <link rel="icon" href="../aset/img/logo.png" type="image/png">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../peserta/css/dashboard.css">
</head>

<body>
    <!-- SIDEBAR -->
    <div class="sidebar" id="sidebar">
        <div class="sidebar-title">
            <h2 class="sidebar-brand d-none d-md-block">RELIPROVE</h2> <!-- ❌ Hidden on mobile -->
            <button class="sidebar-close d-md-none" onclick="document.getElementById('sidebar').classList.remove('active')">
                <i class="fas fa-times"></i>
            </button>
        </div>

        <a href="dashboard_peserta.php" class="active"><i class="fas fa-gauge"></i> Dashboard</a>
        <a href="form_registrasi.php"><i class="fas fa-file-lines"></i> Form Registrasi</a>
        <a href="unggah_dokumen.php"><i class="fas fa-upload"></i> Upload Dokumen</a>
        <a href="status_asesmen.php"><i class="fas fa-clipboard-check"></i> Status Asesmen</a>
        <a href="sertifikat.php"><i class="fas fa-graduation-cap"></i> Sertifikat</a>
        <a href="pengaturan.php"><i class="fas fa-gear"></i> Pengaturan</a>
        <a href="../logout.php"><i class="fas fa-sign-out-alt"></i> Keluar</a>
    </div>

    <!-- CONTENT -->
    <div class="content">
        <div class="topbar">
            <div class="left-group">
                <div class="toggle-sidebar" onclick="document.getElementById('sidebar').classList.toggle('active')">
                    <i class="fas fa-bars"></i>
                </div>
                <h4 class="d-none d-md-block" style="color: white">DASHBOARD PESERTA</h4>
                <h4 class="d-md-none" style="margin-left: 0.5rem; color: white;">RELIPROVE</h4>
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
        <div class="peserta-card">
            <?php
            $pengguna = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM pengguna WHERE id_pengguna = '$id'"));

            if ($cek_form > 0):
            ?>
                <!-- Jika SUDAH mengisi form registrasi -->
                <h5 style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 1rem;">
                    <span>Peserta Terdaftar</span>
                    <span class="trend"><i class="fas fa-user-check"></i></span>
                </h5>

                <div class="progress-bar-custom mt-3">
                    <div class="progress-fill"></div>
                </div>
                <div class="status-value mt-3 mb-4" style="font-size: 1.4rem;"></div>

                <div class="row g-3">
                    <div class="col-md-6"><strong>Nama :</strong> <?= htmlspecialchars($pengguna['nama_lengkap']) ?></div>
                    <div class="col-md-6"><strong>Email :</strong> <?= htmlspecialchars($pengguna['email']) ?></div>
                    <div class="col-md-6"><strong>Asal Peserta :</strong> <?= ucfirst($pengguna['asal_peserta']) ?></div>
                    <div class="col-md-6"><strong>Instansi :</strong> <?= htmlspecialchars($pengguna['nama_instansi']) ?></div>
                    <div class="col-md-6"><strong>No Identitas :</strong> <?= htmlspecialchars($pengguna['no_identitas']) ?></div>
                    <div class="col-md-6"><strong>Pendidikan Terakhir :</strong> <?= htmlspecialchars($pengguna['pendidikan']) ?></div>
                </div>

                <div class="mt-4">
                    <a href="form_registrasi.php?ulang=1" onclick="return confirm('Yakin ingin mengisi ulang data?')"
                        class="btn btn-sm" style="
                background-color: transparent; 
                color: var(--primary); 
                border: 1px solid var(--primary); 
                padding: 0.6rem 1.4rem; 
                border-radius: 8px;">
                        <i class="fas fa-rotate-right me-1"></i> Isi Ulang Data
                    </a>
                </div>

            <?php else: ?>
                <!-- Jika BELUM mengisi form registrasi -->
                <h5 style="font-size: 1.5rem; margin-bottom: 1rem;">
                    Selamat Datang,
                    <span style="color: var(--primary); text-transform: uppercase;">
                        <?= htmlspecialchars($pengguna['nama_lengkap']) ?>
                    </span>
                    !
                </h5>

                <p style="text-align: left; font-size: 0.96rem; line-height: 1.6; margin-bottom: 1.8rem;">
                    <strong>1.</strong> Untuk memulai proses sertifikasi di platform <strong style="color: var(--primary);">RELIPROVE</strong>, kamu diwajibkan mengisi form registrasi terlebih dahulu.
                    Pastikan semua informasi yang kamu isikan sesuai dengan data resmi dan terbaru.<br><br>
                    <strong>2.</strong> Sebagai panduan awal, silakan <strong style="color: var(--primary);">unduh dokumen panduan tata cara</strong> melalui tombol di bawah ini.
                    Panduan ini akan membantumu memahami alur, persyaratan, dan dokumen yang perlu dipersiapkan.
                </p>

                <div style="display: flex; flex-wrap: wrap; gap: 1rem;">
                    <!-- Tombol Registrasi -->
                    <a href="form_registrasi.php"
                        style="flex: 1 1 200px;
              text-align: center;
              background-color: transparent;
              color: #9b5eff;
              border: 1px solid #9b5eff;
              padding: 0.6rem 1.4rem;
              border-radius: 8px;
              font-size: 0.95rem;
              font-weight: 500;
              display: inline-flex;
              justify-content: center;
              align-items: center;
              gap: 0.5rem;
              text-decoration: none;
              transition: all 0.3s ease;"
                        onmouseover="this.style.backgroundColor='#9b5eff'; this.style.color='#0e0e0e'; this.style.boxShadow='0 0 12px #9b5eff88';"
                        onmouseout="this.style.backgroundColor='transparent'; this.style.color='#9b5eff'; this.style.boxShadow='none';">
                        <i class="fas fa-clipboard-list me-1"></i> Isi Form Registrasi
                    </a>

                    <!-- Tombol Unduh Panduan -->
                    <a href="../dokumen/PANDUAN_PENGISIAN.pdf"
                        download
                        style="flex: 1 1 200px;
              text-align: center;
              background-color: transparent;
              color: #9b5eff;
              border: 1px solid #9b5eff;
              padding: 0.6rem 1.4rem;
              border-radius: 8px;
              font-size: 0.95rem;
              font-weight: 500;
              display: inline-flex;
              justify-content: center;
              align-items: center;
              gap: 0.5rem;
              text-decoration: none;
              transition: all 0.3s ease;"
                        onmouseover="this.style.backgroundColor='#9b5eff'; this.style.color='#0e0e0e'; this.style.boxShadow='0 0 12px #9b5eff88';"
                        onmouseout="this.style.backgroundColor='transparent'; this.style.color='#9b5eff'; this.style.boxShadow='none';">
                        <i class="fas fa-download me-1"></i> Unduh Tata Cara
                    </a>
                </div>




            <?php endif; ?>
        </div>

        <div class="card-status-wrapper">
            <?php
            function statBlock($title, $value, $trend, $link)
            {
                // Icon status (check / clock)
                $trendIcon = $trend > 0
                    ? '<i class="fas fa-check-circle"></i>'
                    : '<i class="fas fa-clock"></i>';

                // Progress bar animasi
                $progressBar = $trend > 0
                    ? "<div class='progress-fill'></div>"
                    : "<div class='progress-dots'></div>";

                // Inline button style (tombol ungu modern)
                $buttonStyle = "background-color: transparent; 
            color: #9b5eff; 
            border: 1px solid #9b5eff; 
            padding: 0.5rem 1.2rem; 
            border-radius: 8px; 
            font-size: 0.9rem; 
            font-weight: 5000; 
            display: inline-flex; 
            align-items: center; 
            gap: 1rem; 
            text-decoration: none; 
            transition: all 0.3s ease;
            margin-top: -0.5rem;";

                $hoverJS = "onmouseover=\"this.style.backgroundColor='#9b5eff'; this.style.color='#0e0e0e'; this.style.boxShadow='0 0 12px #9b5eff88';\" 
        onmouseout=\"this.style.backgroundColor='transparent'; this.style.color='#9b5eff'; this.style.boxShadow='none';\"";

                // Tombol
                $button = "<a href='$link' style=\"$buttonStyle\" $hoverJS>
            <i class='fas fa-arrow-right'></i> Lihat Status
        </a>";

                // Output HTML
                echo "<div class='stat-block'>
            <h5>$title <span class='trend'>$trendIcon</span></h5>
            <div class='progress-bar-custom' style='margin-bottom: 0.8rem;'>$progressBar</div>
            <div class='status-value'>$value</div>
            $button
        </div>";
            }

            // Panggil fungsi statBlock dengan data masing-masing
            statBlock('Status Registrasi', $cek_form > 0 ? 'Sudah Mengisi' : 'Belum Mengisi', $cek_form > 0 ? 1 : -1, 'form_registrasi.php');
            statBlock('Status Dokumen', $status_dokumen, $trend_dokumen, 'unggah_dokumen.php');
            statBlock('Status Asesmen', $status_asesmen, $trend_asesmen, 'status_asesmen.php');
            statBlock('Status Sertifikat', $cek_sertifikat > 0 ? 'Tersedia' : 'Belum Tersedia', $cek_sertifikat > 0 ? 1 : -1, 'sertifikat.php');
            ?>
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
    <script>
        (function() {
            const sidebar = document.getElementById('sidebar');
            const toggles = sidebar.querySelectorAll('[data-toggle="submenu"]');

            // Selalu mulai tertutup
            toggles.forEach(t => t.setAttribute('aria-expanded', 'false'));

            function closeAll() {
                sidebar.querySelectorAll('.submenu.open').forEach(s => s.classList.remove('open'));
                sidebar.querySelectorAll('[data-toggle="submenu"] .caret').forEach(c => c.classList.remove('rotate'));
                toggles.forEach(t => t.setAttribute('aria-expanded', 'false'));
            }

            // Klik teks "Pengaturan" ATAU caret → toggle
            toggles.forEach(toggle => {
                toggle.addEventListener('click', function(e) {
                    e.preventDefault();
                    const panel = this.nextElementSibling;
                    const isOpen = panel.classList.contains('open');
                    closeAll();
                    if (!isOpen) {
                        panel.classList.add('open');
                        this.setAttribute('aria-expanded', 'true');
                        this.querySelector('.caret')?.classList.add('rotate');
                    }
                });
            });

            // (Opsional) tandai link aktif di submenu
            const current = location.pathname.split('/').pop();
            sidebar.querySelectorAll('.submenu a[href]').forEach(a => {
                if (a.getAttribute('href').split('/').pop() === current) {
                    a.classList.add('active');
                    // Boleh buka otomatis saat halaman submenu (hapus jika ingin tetap tertutup)
                    const toggle = a.closest('.submenu')?.previousElementSibling;
                    if (toggle && toggle.matches('[data-toggle="submenu"]')) {
                        toggle.click();
                    }
                }
            });
        })();
    </script>


</body>

</html>