<?php
ob_start();
session_start();
include __DIR__ . '/../config/koneksi.php';

if (!isset($_SESSION['id_pengguna']) || $_SESSION['peran'] !== 'asesor') {
    header("Location: ../login.php");
    exit;
}

$id_asesor = (int)$_SESSION['id_pengguna'];
$nama      = $_SESSION['nama_lengkap'];

// Pastikan aman dari injeksi
$id_asesor = (int)$id_asesor;

/** RINGKASAN */
$sql = "
    SELECT
      /* Perlu verifikasi (status pending) */
      (SELECT COUNT(*) FROM pendaftaran pd WHERE pd.status_verifikasi='pending') AS perlu_verifikasi,

      /* Siap dinilai: sudah diterima & belum ada penilaian */
      (SELECT COUNT(*)
         FROM pendaftaran pd
         WHERE pd.status_verifikasi='diterima'
           AND NOT EXISTS (
               SELECT 1 FROM penilaian pn
               WHERE pn.id_pendaftaran = pd.id_pendaftaran
           )
      ) AS siap_dinilai,

      /* Sudah saya nilai */
      (SELECT COUNT(*) FROM penilaian WHERE id_asesor = {$id_asesor}) AS sudah_dinilai_saya,

      /* Status kelulusan berdasar pendaftaran */
      (SELECT COUNT(*)
         FROM penilaian pn
         JOIN pendaftaran pd ON pd.id_pendaftaran = pn.id_pendaftaran
        WHERE pn.id_asesor = {$id_asesor}
          AND pd.status_kelulusan = 'lulus'
      ) AS lulus_saya,

      (SELECT COUNT(*)
         FROM penilaian pn
         JOIN pendaftaran pd ON pd.id_pendaftaran = pn.id_pendaftaran
        WHERE pn.id_asesor = {$id_asesor}
          AND pd.status_kelulusan = 'tidak'
      ) AS tidak_lulus_saya
";

$summary = mysqli_fetch_assoc(mysqli_query($conn, $sql));

/** LIST PERLU VERIFIKASI (PENDING) */
$perlu_verifikasi = mysqli_query($conn, "
    SELECT
      pd.id_pendaftaran,
      pd.link_timeline, pd.link_jobdesk, pd.link_portofolio,
      pd.link_foto_formal, pd.link_foto_transparan, pd.link_cv, pd.link_biodata, pd.link_dok_perkuliahan,
      pg.nama_lengkap, pg.email,
      ps.nama_posisi,
      pd.tanggal_daftar
    FROM pendaftaran pd
    JOIN pengguna pg ON pg.id_pengguna = pd.id_pengguna
    JOIN posisi   ps ON ps.id_posisi   = pd.id_posisi
    WHERE pd.status_verifikasi = 'pending'
    ORDER BY pd.tanggal_daftar ASC
");

/** LIST SIAP DINILAI (DITERIMA & BELUM DINILAI) */
// contoh: peserta siap dinilai = yg sudah diterima
$siap_dinilai = mysqli_query($conn, "
  SELECT 
    p.id_pendaftaran,
    u.nama_lengkap,
    po.nama_posisi,
    p.tanggal_daftar,
    p.status_verifikasi,
    pen.link_soal,
    pen.link_jawaban
  FROM pendaftaran p
  JOIN pengguna u   ON u.id_pengguna = p.id_pengguna
  JOIN posisi   po  ON po.id_posisi   = p.id_posisi
  LEFT JOIN penilaian pen ON pen.id_pendaftaran = p.id_pendaftaran
  WHERE p.status_verifikasi = 'diterima'
  ORDER BY p.tanggal_daftar DESC
");


/** RIWAYAT PENILAIAN OLEH ASESOR LOGIN (tanpa perubahan) */
$riwayat = mysqli_query($conn, "
    SELECT
      pd.id_pendaftaran,
      pg.nama_lengkap,
      ps.nama_posisi,
      pn.skor,
      pn.rekomendasi,
      pn.tanggal_dinilai
    FROM penilaian pn
    JOIN pendaftaran pd ON pd.id_pendaftaran = pn.id_pendaftaran
    JOIN pengguna   pg ON pg.id_pengguna   = pd.id_pengguna
    JOIN posisi     ps ON ps.id_posisi     = pd.id_posisi
    WHERE pn.id_asesor = {$id_asesor}
    ORDER BY pn.tanggal_dinilai DESC
");
?>

<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Dashboard Asesor | RELIPROVE</title>
    <link rel="icon" href="../aset/img/logo.png" type="image/png">

    <!-- Library umum (biarkan, tanpa custom style) -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" />
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet" />
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600&display=swap" rel="stylesheet" />
    <link rel="stylesheet" href="https://cdn.datatables.net/responsive/2.5.0/css/responsive.bootstrap5.min.css">
    <script src="https://cdn.datatables.net/responsive/2.5.0/js/dataTables.responsive.min.js"></script>
    <script src="https://cdn.datatables.net/responsive/2.5.0/js/responsive.bootstrap5.min.js"></script>

    <link rel="stylesheet" href="../asesor/css/dashboard_asesor.css">

    <!-- Optional: kamu bisa pakai stylesheet global yang sama dengan peserta
         <link rel="stylesheet" href="../asesor/css/dashboard.css"> -->
</head>

<body>

    <!-- SIDEBAR -->
    <div class="sidebar" id="sidebar">
        <div class="sidebar-title">
            <h2 class="sidebar-brand d-none d-md-block text-center" style="margin-top: 37px; margin-bottom: 50px;">RELIPROVE</h2>
            <button class="sidebar-close d-md-none" onclick="document.getElementById('sidebar').classList.remove('active')">
                <i class="fas fa-times"></i>
            </button>
        </div>

        <a href="dashboard_asesor.php" class="active"><i class="fas fa-gauge"></i> Dashboard</a>
        <a href="bank_soal.php"><i class="fa-solid fa-book-open"></i> Bank Soal</a>
        <a href="daftar_penilaian.php"><i class="fas fa-clipboard-list"></i> Daftar Penilaian</a>
        <a href="riwayat_penilaian.php"><i class="fas fa-history"></i> Riwayat Penilaian</a>
        <!-- <a href="sertifikat.php"><i class="fas fa-graduation-cap"></i> Sertifikat</a> -->
        <a href="notifikasi.php"><i class="fas fa-bell"></i> Notifikasi</a>
        <a href="pengaturan.php"><i class="fas fa-gear"></i> Pengaturan</a>
        <a href="../logout.php"><i class="fas fa-sign-out-alt"></i> Keluar</a>
    </div>
    <?php
    if (session_status() === PHP_SESSION_NONE) session_start();

    include __DIR__ . '/partials/sidebar_asesor.php';
    ?>

    <!-- CONTENT -->
    <div class="content">
        <!-- TOPBAR -->
        <div class="topbar">
            <div class="left-group" style="display:flex; align-items:center; gap:10px;">
                <div class="toggle-sidebar" onclick="document.getElementById('sidebar').classList.toggle('active')" style="cursor:pointer; display:flex; align-items:center;">
                    <i class="fas fa-bars"></i>
                </div>
                <h4 class="d-none d-md-block" style="margin:0; font-weight:600;">DASHBOARD ASESOR</h4>
                <h4 class="d-md-none" style="margin:0; font-weight:600;">RELIPROVE</h4>
            </div>

            <div class="right-group">
                <div class="user-info">
                    <img src="https://ui-avatars.com/api/?name=<?= urlencode($nama) ?>&background=9b5eff&color=fff&rounded=true&size=40" alt="avatar">
                    <div class="user-meta">
                        <strong><?= htmlspecialchars($nama) ?></strong><br>
                        <small>Asesor</small>
                    </div>
                </div>
            </div>
        </div>

        <!-- HEADER CARD (mengikuti struktur peserta-card) -->
        <!-- Ringkasan Penilaian -->
        <div class="row g-3 mt-3">
            <div class="col-6 col-md-3">
                <div class="stat-block">
                    <h5 style="margin-bottom: 1rem;">Perlu Verifikasi <span class="trend"><i class="fas fa-user-check"></i></span></h5>
                    <div class="progress-bar-custom">
                        <div class="progress-dots"></div>
                    </div>
                    <div class="status-value"><?= (int)$summary['perlu_verifikasi'] ?></div>
                    <a href="#blok-perlu-verifikasi" class="btn-link"><i class="fas fa-arrow-right"></i> Lihat</a>
                </div>
            </div>

            <div class="col-6 col-md-3">
                <div class="stat-block">
                    <h5 style="margin-bottom: 1rem;">Siap Diuji <span class="trend"><i class="fas fa-hourglass-half"></i></span></h5>
                    <div class="progress-bar-custom">
                        <div class="progress-dots"></div>
                    </div>
                    <div class="status-value"><?= (int)$summary['siap_dinilai'] ?></div>
                    <a href="#blok-siap-dinilai" class="btn-link"><i class="fas fa-arrow-right"></i> Lihat</a>
                </div>
            </div>

            <div class="col-6 col-md-3">
                <div class="stat-block">
                    <h5 style="margin-bottom: 1rem;">Proses Dinilai <span class="trend"><i class="fas fa-check-circle"></i></span></h5>
                    <div class="progress-bar-custom">
                        <div class="progress-fill"></div>
                    </div>
                    <div class="status-value"><?= (int)$summary['sudah_dinilai_saya'] ?></div>
                    <a href="riwayat_penilaian.php" class="btn-link"><i class="fas fa-arrow-right"></i> Lihat</a>
                </div>
            </div>

            <div class="col-6 col-md-3">
                <div class="stat-block">
                    <h5 style="margin-bottom: 1rem;">Lulus / Tidak <span class="trend"><i class="fas fa-balance-scale"></i></span></h5>
                    <div class="progress-bar-custom">
                        <div class="progress-fill"></div>
                    </div>
                    <div class="status-value">
                        <?= (int)$summary['lulus_saya'] ?> / <?= (int)$summary['tidak_lulus_saya'] ?>
                    </div>
                    <a href="riwayat_penilaian.php" class="btn-link"><i class="fas fa-arrow-right"></i> Lihat</a>
                </div>
            </div>
        </div>

        <br>
        <!-- GRID STATUS (pakai wrapper yang sama supaya style global kamu nempel) -->
        <!-- BLOK: PERLU VERIFIKASI -->
        <div class="table-surface">
            <h5>Dokumen Peserta <span class="trend"><i class="fas fa-clipboard-list"></i></span></h5>
            <br>
            <table id="tbl-verifikasi" class="table dt-theme align-middle w-100">
                <thead>
                    <tr>
                        <th>Nama</th>
                        <th>Posisi</th>
                        <th>Tanggal Daftar</th>
                        <th class="text-center">Dokumen</th>
                        <th class="text-center">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (mysqli_num_rows($perlu_verifikasi) > 0): ?>
                        <?php while ($p = mysqli_fetch_assoc($perlu_verifikasi)): ?>
                            <tr>
                                <td><?= htmlspecialchars($p['nama_lengkap']) ?></td>
                                <td><?= htmlspecialchars($p['nama_posisi']) ?></td>
                                <td><?= date('d M Y', strtotime($p['tanggal_daftar'])) ?></td>
                                <td>
                                    <div class="doc-badges">
                                        <?php if ($p['link_timeline']): ?><a href="<?= htmlspecialchars($p['link_timeline']) ?>" target="_blank" class="doc-chip">Timeline</a><?php endif; ?>
                                        <?php if ($p['link_jobdesk']): ?><a href="<?= htmlspecialchars($p['link_jobdesk']) ?>" target="_blank" class="doc-chip">Jobdesk</a><?php endif; ?>
                                        <?php if ($p['link_portofolio']): ?><a href="<?= htmlspecialchars($p['link_portofolio']) ?>" target="_blank" class="doc-chip">Portofolio</a><?php endif; ?>
                                        <?php if ($p['link_cv']): ?><a href="<?= htmlspecialchars($p['link_cv']) ?>" target="_blank" class="doc-chip">CV</a><?php endif; ?>
                                        <?php if ($p['link_biodata']): ?><a href="<?= htmlspecialchars($p['link_biodata']) ?>" target="_blank" class="doc-chip">Biodata</a><?php endif; ?>
                                        <?php if ($p['link_dok_perkuliahan']): ?><a href="<?= htmlspecialchars($p['link_dok_perkuliahan']) ?>" target="_blank" class="doc-chip">Pendidikan</a><?php endif; ?>
                                        <?php if ($p['link_foto_formal']): ?><a href="<?= htmlspecialchars($p['link_foto_formal']) ?>" target="_blank" class="doc-chip">Foto Formal</a><?php endif; ?>
                                        <?php if ($p['link_foto_transparan']): ?><a href="<?= htmlspecialchars($p['link_foto_transparan']) ?>" target="_blank" class="doc-chip">Foto Transparan</a><?php endif; ?>
                                    </div>
                                </td>
                                <td class="text-center">
                                    <div style="display: inline-flex; align-items: center; gap: 6px;">
                                        <form action="verifikasi_pendaftaran.php" method="post">
                                            <input type="hidden" name="id" value="<?= (int)$p['id_pendaftaran'] ?>">
                                            <input type="hidden" name="aksi" value="terima">
                                            <input type="hidden" name="return_to" value="dashboard_asesor.php#tbl-verifikasi">
                                            <button class="btn btn-accept btn-sm" type="submit">Terima</button>
                                        </form>
                                        <form action="verifikasi_pendaftaran.php" method="post">
                                            <input type="hidden" name="id" value="<?= (int)$p['id_pendaftaran'] ?>">
                                            <input type="hidden" name="aksi" value="tolak">
                                            <input type="hidden" name="return_to" value="dashboard_asesor.php#tbl-verifikasi">
                                            <button class="btn btn-reject btn-sm" type="submit">Tolak</button>
                                        </form>
                                    </div>
                                </td>

                            </tr>
                        <?php endwhile; ?>
                    <?php endif; ?>
                </tbody>

            </table>
        </div>


        <br>
        <!-- BLOK: SIAP DINILAI -->
        <div class="stat-block" id="blok-siap-dinilai">
            <h5 style="margin-bottom: 30px;">
                Peserta Siap Mengerjakan Soal dan Dinilai <span class="trend"><i class="fas fa-clipboard-list"></i></span>
            </h5>

            <div class="table-responsive mt-3 custom-table-wrapper">
                <table class="table table-dark table-hover modern-table">
                    <thead>
                        <tr>
                            <th>Nama</th>
                            <th>Posisi</th>
                            <th>Tanggal Daftar</th>
                            <th class="text-center">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (mysqli_num_rows($siap_dinilai) > 0): ?>
                            <?php while ($s = mysqli_fetch_assoc($siap_dinilai)): ?>
                                <?php
                                $verif_ok     = ($s['status_verifikasi'] === 'diterima');
                                $link_soal    = trim($s['link_soal']    ?? '');
                                $link_jawaban = trim($s['link_jawaban'] ?? '');

                                $ada_soal     = ($link_soal    !== '');
                                $ada_jawaban  = ($link_jawaban !== '');

                                $buka_soal_ok     = $verif_ok && $ada_soal;
                                $buka_jawaban_ok  = $ada_jawaban; // peserta sudah kirim
                                $nilai_ok         = $ada_jawaban; // aktif saat ada jawaban
                                ?>
                                <tr>
                                    <td><?= htmlspecialchars($s['nama_lengkap']) ?></td>
                                    <td><?= htmlspecialchars($s['nama_posisi']) ?></td>
                                    <td><?= date('d M Y', strtotime($s['tanggal_daftar'])) ?></td>
                                    <td class="text-center">

                                        <?php if (!$ada_soal): ?>
                                            <!-- Belum ada soal: tampilkan form simpan -->
                                            <form action="simpan_link_soal.php" method="post"
                                                class="d-inline-flex align-items-center"
                                                style="gap:.5rem; min-width:260px;">
                                                <input type="hidden" name="id_pendaftaran" value="<?= (int)$s['id_pendaftaran'] ?>">
                                                <input type="hidden" name="return_to" value="dashboard_asesor.php#blok-siap-dinilai">
                                                <input type="url" name="link_soal"
                                                    class="form-control form-control-sm"
                                                    placeholder="Tempel link soal Google Drive…"
                                                    style="max-width:420px" required>
                                                <button class="btn btn-sm btn-primary btn-action" type="submit">Simpan</button>
                                            </form>

                                        <?php else: ?>
                                            <!-- Sudah ada soal: tunjukkan tombol Soal -->
                                            <a href="<?= htmlspecialchars($link_soal) ?>" target="_blank"
                                                class="btn btn-sm btn-primary">Soal</a>
                                        <?php endif; ?>

                                        <!-- Buka Jawaban: aktif jika peserta sudah kirim -->
                                        <a href="<?= $buka_jawaban_ok ? htmlspecialchars($link_jawaban) : '#' ?>"
                                            target="_blank"
                                            class="btn btn-sm <?= $buka_jawaban_ok ? 'btn-success' : 'btn-secondary disabled' ?> ms-1"
                                            aria-disabled="<?= $buka_jawaban_ok ? 'false' : 'true' ?>"
                                            title="<?= $buka_jawaban_ok ? 'Buka jawaban peserta' : 'Belum ada jawaban peserta' ?>">Buka Jawaban</a>

                                        <!-- Nilai: aktif kalau sudah ada jawaban -->
                                        <a href="<?= $nilai_ok ? 'penilaian.php?id=' . (int)$s['id_pendaftaran'] : '#' ?>"
                                            class="btn btn-sm <?= $nilai_ok ? 'btn-primary' : 'btn-secondary disabled' ?> ms-1"
                                            aria-disabled="<?= $nilai_ok ? 'false' : 'true' ?>"
                                            title="<?= $nilai_ok ? 'Mulai menilai' : 'Menunggu jawaban peserta' ?>">Nilai</a>
                                        <form action="verifikasi_pendaftaran.php" method="post" class="d-inline ms-1"
                                            onsubmit="return confirm('Kembalikan peserta ini ke tahap Dokumen Peserta?');">
                                            <input type="hidden" name="id" value="<?= (int)$s['id_pendaftaran'] ?>">
                                            <input type="hidden" name="aksi" value="batal">
                                            <input type="hidden" name="return_to" value="dashboard_asesor.php#tbl-verifikasi">
                                            <button type="submit" class="btn btn-sm btn-danger" title="Batalkan & kembalikan ke Dokumen">
                                                <i class="fas fa-times"></i>
                                            </button>
                                        </form>

                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td class="text-center text-muted">—</td>
                                <td class="text-center text-muted">—</td>
                                <td class="text-center text-muted">—</td>
                                <td class="text-center text-muted">Belum ada yang siap dinilai.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

        </div>



        <div class="footer">
            &copy; <?= date('Y') ?> Created by PT. Reliable Future Technology
        </div>
    </div>

    <!-- JS: close sidebar ketika klik di luar (sama seperti peserta) -->
    <script>
        document.addEventListener('click', function(event) {
            const sidebar = document.getElementById('sidebar');
            const toggle = document.querySelector('.toggle-sidebar');
            if (!sidebar.contains(event.target) && !toggle.contains(event.target)) {
                sidebar.classList.remove('active');
            }
        });
    </script>

    <!-- JS: submenu logic (mengikuti pola peserta) -->
    <script>
        (function() {
            const sidebar = document.getElementById('sidebar');
            const toggles = sidebar.querySelectorAll('[data-toggle="submenu"]');

            // Mulai tertutup
            toggles.forEach(t => t.setAttribute('aria-expanded', 'false'));

            function closeAll() {
                sidebar.querySelectorAll('.submenu.open').forEach(s => s.classList.remove('open'));
                sidebar.querySelectorAll('[data-toggle="submenu"] .caret').forEach(c => c.classList.remove('rotate'));
                toggles.forEach(t => t.setAttribute('aria-expanded', 'false'));
            }

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

            // Tandai link aktif di submenu (opsional)
            const current = location.pathname.split('/').pop();
            sidebar.querySelectorAll('.submenu a[href]').forEach(a => {
                if (a.getAttribute('href').split('/').pop() === current) {
                    a.classList.add('active');
                    const toggle = a.closest('.submenu')?.previousElementSibling;
                    if (toggle && toggle.matches('[data-toggle="submenu"]')) {
                        toggle.click();
                    }
                }
            });
        })();
    </script>
    <!-- jQuery -->
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>

    <!-- DataTables + Bootstrap 5 + Responsive -->
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.8/css/dataTables.bootstrap5.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/responsive/2.5.0/css/responsive.bootstrap5.min.css">
    <script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.8/js/dataTables.bootstrap5.min.js"></script>
    <script src="https://cdn.datatables.net/responsive/2.5.0/js/dataTables.responsive.min.js"></script>
    <script src="https://cdn.datatables.net/responsive/2.5.0/js/responsive.bootstrap5.min.js"></script>

    <script>
        $(function() {
            $('#tbl-verifikasi').DataTable({
                responsive: {
                    details: {
                        type: 'inline', // klik baris untuk expand detail
                        target: 'tr'
                    }
                },
                autoWidth: false,
                pageLength: 10,
                lengthMenu: [
                    [10, 25, 50, -1],
                    [10, 25, 50, 'Semua']
                ],
                order: [
                    [2, 'desc']
                ],
                columnDefs: [{
                        targets: [3, 4],
                        orderable: false
                    },
                    {
                        targets: [4],
                        searchable: false
                    }
                ],
                language: {
                    url: 'https://cdn.datatables.net/plug-ins/1.13.8/i18n/id.json'
                }
            });
        });
    </script>

    <!-- Bootstrap bundle (opsional untuk komponen yang butuh JS) -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>

</html>