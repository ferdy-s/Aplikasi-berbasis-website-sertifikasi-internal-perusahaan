<?php
// daftar_penilaian.php (FINAL, mysqli + session schema sesuai proses_login.php)
ob_start();
session_start();
require_once __DIR__ . '/../config/koneksi.php';

/* --------- Guard: wajib login & peran asesor --------- */
if (!isset($_SESSION['id_pengguna']) || ($_SESSION['peran'] ?? '') !== 'asesor') {
    header('Location: ../login.php');
    exit;
}
$nama      = $_SESSION['nama_lengkap'] ?? 'Asesor';
$idAsesor  = (int)($_SESSION['id_pengguna'] ?? 0);

/* --------- CSRF token --------- */
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_token'];

/* --------- Flash dari session (opsional, sesuai pola kamu) --------- */
$flash_ok  = $_SESSION['flash_ok']  ?? null;
$flash_err = $_SESSION['flash_err'] ?? null;
unset($_SESSION['flash_ok'], $_SESSION['flash_err']);

/* --------- Helper escape --------- */
function esc($s)
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}
function badgeRekom($r)
{
    $r = strtolower((string)$r);
    if ($r === 'layak')        return '<span class="badge bg-success">Layak</span>';
    if ($r === 'belum layak')  return '<span class="badge bg-danger">Belum Layak</span>';
    return '<span class="badge bg-secondary">Di Review</span>';
}

/* --------- Handle UPDATE (skor/komentar/link_jawaban) --------- */
$flash = ['type' => null, 'msg' => null];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aksi'] ?? '') === 'update') {
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
        $flash = ['type' => 'danger', 'msg' => 'Token tidak valid. Muat ulang halaman.'];
    } else {
        $id_penilaian = (int)($_POST['id_penilaian'] ?? 0);
        $skor_in      = trim($_POST['skor'] ?? '');
        $komentar_in  = trim($_POST['komentar'] ?? '');
        $jawaban_in   = trim($_POST['link_jawaban'] ?? '');

        $skor = ($skor_in === '') ? null : (int)$skor_in;
        if ($skor !== null && ($skor < 0 || $skor > 10)) {
            $flash = ['type' => 'warning', 'msg' => 'Skor harus 0–10.'];
        } else {
            // Pastikan record milik asesor ini
            $cek = $conn->prepare("SELECT 1 FROM penilaian WHERE id_penilaian=? AND id_asesor=?");
            $cek->bind_param('ii', $id_penilaian, $idAsesor);
            if (!$cek->execute()) {
                $flash = ['type' => 'danger', 'msg' => 'Gagal memeriksa kepemilikan data.'];
            } else {
                $res = $cek->get_result();
                if ($res->num_rows !== 1) {
                    $flash = ['type' => 'danger', 'msg' => 'Data tidak ditemukan / bukan kewenangan Anda.'];
                } else {
                    // Update (gunakan NULL jika input kosong)
                    $sql = "
                        UPDATE penilaian
                        SET skor = ?, komentar = ?, link_jawaban = ?, tanggal_dinilai = NOW()
                        WHERE id_penilaian = ?
                    ";
                    $upd = $conn->prepare($sql);
                    // Untuk mysqli, binding NULL: gunakan tipe 's'/'i' biasa, value = null akan menjadi NULL
                    // Tipe: skor (i|nullable), komentar (s|null), link (s|null), id (i)
                    $komentar = ($komentar_in === '') ? null : $komentar_in;
                    $jawaban  = ($jawaban_in  === '') ? null : $jawaban_in;

                    // Trik: jika $skor === null, set 0 dulu lalu override dengan NULL via set_null
                    // Lebih sederhana: gunakan bind_param dengan tipe yang sama dan pass null—MySQLi akan mengirim NULL.
                    $upd->bind_param('issi', $skor, $komentar, $jawaban, $id_penilaian);

                    if ($upd->execute()) {
                        // Rekomendasi akan otomatis diset oleh trigger BEFORE UPDATE
                        $flash = ['type' => 'success', 'msg' => 'Penilaian berhasil diperbarui.'];
                    } else {
                        $flash = ['type' => 'danger', 'msg' => 'Gagal memperbarui data.'];
                    }
                    $upd->close();
                }
                $res->free();
            }
            $cek->close();
        }
    }
}

/* --------- Ambil data penilaian milik asesor --------- */
$rows = [];
$list = $conn->prepare("
    SELECT id_penilaian, id_pendaftaran, link_soal, id_asesor, skor, komentar, rekomendasi, tanggal_dinilai, link_jawaban
    FROM penilaian
    WHERE id_asesor = ?
    ORDER BY COALESCE(tanggal_dinilai, '1970-01-01 00:00:00') DESC, id_penilaian DESC
");
$list->bind_param('i', $idAsesor);
if ($list->execute()) {
    $res = $list->get_result();
    while ($row = $res->fetch_assoc()) {
        $rows[] = $row;
    }
    $res->free();
}
$list->close();
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
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js" crossorigin="anonymous"></script>


    <link rel="stylesheet" href="../asesor/css/dashboard_asesor.css">
</head>
<div class="modal fade" id="modalUploadSoal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="background-color:#121318; color:#eaeaea; border-radius:12px; border:1px solid rgba(255,255,255,.08); box-shadow:0 8px 30px rgba(0,0,0,.6);">
            <div class="modal-header" style="border:0; padding:1rem 1.25rem;">
                <h5 class="modal-title" id="us_title" style="font-weight:600; font-size:1rem; display:flex; align-items:center; gap:.5rem;">
                    <i class="fa-solid fa-paperclip" style="opacity:.8;"></i> Upload Soal
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Tutup"></button>
            </div>

            <form action="simpan_link_soal.php" method="post" id="formUploadSoal">
                <div class="modal-body" style="padding:1rem 1.25rem;">
                    <input type="hidden" name="id_pendaftaran" id="us_id">
                    <input type="hidden" name="return_to" id="us_return" value="daftar_penilaian.php#blok-siap-dinilai">

                    <label class="form-label" style="color:#aaa; font-weight:500;">Link Soal Google Drive</label>
                    <div class="input-group input-group-sm" style="margin-bottom:.5rem;">
                        <span class="input-group-text" style="background:rgba(255,255,255,.05); border:1px solid rgba(255,255,255,.15); color:#bbb;">
                            <i class="fa-solid fa-link"></i>
                        </span>
                        <input type="url" name="link_soal" id="us_link" placeholder="https://drive.google.com/..."
                            required
                            style="background:rgba(255,255,255,.04); color:#fff; border:1px solid rgba(255,255,255,.15); border-left:0; font-size:.875rem; padding:.45rem .75rem; flex:1; border-radius:0 .5rem .5rem 0;">
                    </div>

                    <!-- status/info -->
                    <div id="us_info" style="font-size:.75rem; color:#888;">Pastikan link dapat diakses peserta.</div>

                    <!-- detail apa yang harus diisi di Bank Soal (hanya muncul saat belum ada soal) -->
                    <div id="us_missing_meta" class="mt-2 d-none" style="font-size:.8rem;">
                        <!-- akan diisi via JS -->
                    </div>
                </div>

                <div class="modal-footer" style="border:0; padding:1rem 1.25rem; display:flex; justify-content:space-between;">
                    <button type="button" data-bs-dismiss="modal"
                        style="background:none; border:1px solid rgba(255,255,255,.25); color:#ccc; font-size:.85rem; border-radius:.5rem; padding:.4rem .9rem;">
                        <i class="fa-solid fa-xmark me-1"></i> Batal
                    </button>

                    <!-- tombol submit normal -->
                    <button id="us_btn_submit" type="submit"
                        style="background:none; border:1px solid #9b5eff; color:#cfc7ff; font-size:.85rem; border-radius:.5rem; padding:.4rem .9rem;">
                        <i class="fa-solid fa-paperclip me-1"></i> Simpan
                    </button>

                    <!-- CTA ke Bank Soal -->
                    <a id="us_btn_bank" href="bank_soal.php" class="btn btn-warning d-none">
                        <i class="fa-solid fa-plus"></i> Tambah Soal di Bank Soal
                    </a>
                </div>
            </form>
        </div>
    </div>
</div>


<body>

    <!-- SIDEBAR -->
    <div class="sidebar" id="sidebar">
        <div class="sidebar-title">
            <h2 class="sidebar-brand d-none d-md-block text-center" style="margin-top: 37px; margin-bottom: 50px;">RELIPROVE</h2>
            <button class="sidebar-close d-md-none" onclick="document.getElementById('sidebar').classList.remove('active')">
                <i class="fas fa-times"></i>
            </button>
        </div>

        <a href="dashboard_asesor.php"><i class="fas fa-gauge"></i> Dashboard</a>
        <a href="bank_soal.php"><i class="fa-solid fa-book-open"></i> Bank Soal</a>
        <a href="daftar_penilaian.php" class="active"><i class="fas fa-clipboard-list"></i> Daftar Penilaian</a>
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
                <h4 class="d-none d-md-block" style="margin:0; font-weight:600;">DAFTAR PENILAIAN</h4>
                <h4 class="d-md-none" style="margin:0; font-weight:600;">RELIPROVE</h4>
            </div>

            <div class="right-group">
                <div class="user-info">
                    <img src="https://ui-avatars.com/api/?name=<?= urlencode($nama) ?>&background=9b5eff&color=fff&rounded=true&size=40" alt="avatar">
                    <div class="user-meta">
                        <strong><?= esc($nama) ?></strong><br>
                        <small>Asesor</small>
                    </div>
                </div>
            </div>
        </div>
        <?php
        // ========= Guard & helper =========
        if (session_status() === PHP_SESSION_NONE) session_start();
        require_once __DIR__ . '/../config/koneksi.php'; // mysqli $conn

        // Pakai struktur sesi yang sama dengan proses_login.php & halaman lain
        $idAsesor = $_SESSION['id_pengguna'] ?? 0;

        if (!function_exists('esc')) {
            function esc($s)
            {
                return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
            }
        }

        // ========= Ambil data siap dinilai (acuannya: query dashboard) =========
        // Di dashboard, $siap_dinilai dibuat dari join pendaftaran+pengguna+posisi (+LEFT JOIN penilaian).
        // Kita ambil ulang dengan mysqli procedural/OO agar mandiri di halaman ini.
        $rows = [];
        $sql = "
  SELECT 
    p.id_pendaftaran,
    p.tanggal_daftar,
    p.status_verifikasi,
    u.nama_lengkap,
    ps.nama_posisi,
    n.link_soal,
    n.link_jawaban,
    n.id_penilaian,
    n.skor,
    n.rekomendasi,
    n.tanggal_dinilai
  FROM pendaftaran p
  INNER JOIN pengguna u ON u.id_pengguna = p.id_pengguna
  INNER JOIN posisi  ps ON ps.id_posisi   = p.id_posisi
  LEFT JOIN penilaian n 
         ON n.id_pendaftaran = p.id_pendaftaran
        AND (n.id_asesor = ? OR ? = 0)      -- jika asesor tidak ter-set, tampilkan semua
  WHERE p.status_verifikasi = 'diterima'
  ORDER BY p.tanggal_daftar DESC, p.id_pendaftaran DESC
";
        $stmt = $conn->prepare($sql);
        $zero = 0; // utk bind param kedua
        $stmt->bind_param('ii', $idAsesor, $idAsesor);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($r = $res->fetch_assoc()) $rows[] = $r;
        $stmt->close();

        // ========= Hitung ringkasan filter =========
        $sum_all = count($rows);
        $sum_no_soal = $sum_wait_jwb = $sum_ready = 0;
        foreach ($rows as $s) {
            $ls = trim($s['link_soal'] ?? '');
            $lj = trim($s['link_jawaban'] ?? '');
            $ada_soal = ($ls !== '');
            $ada_jwb  = ($lj !== '');
            if (!$ada_soal) $sum_no_soal++;
            if ($ada_soal && !$ada_jwb) $sum_wait_jwb++;
            if ($ada_jwb) $sum_ready++;
        }
        ?>

        <!-- ============================ STYLE ============================ -->
        <style>
            :root {
                --primary: #9b5eff;
                --bg-dark: #0b0f1a;
                --bg-card: #161a25;
                --bg-surface: #151923;
                --text: #e9e9ee;
                --muted: #9aa0ad;
                --line: rgba(255, 255, 255, .10);
            }

            .card-surface {
                background: var(--bg-surface);
                border: 1px solid var(--line);
                border-radius: 16px;
                padding: 16px;
            }

            .segmented {
                display: flex;
                gap: .5rem;
                flex-wrap: wrap;
                background: rgba(255, 255, 255, .04);
                border: 1px solid var(--line);
                border-radius: 999px;
                padding: .35rem
            }

            .segmented .seg-btn {
                border: 0;
                background: transparent;
                color: var(--text);
                font-weight: 700;
                padding: .38rem .8rem;
                border-radius: 999px
            }

            .segmented .seg-btn.active {
                background: var(--primary);
                color: #fff
            }

            .grid-cards {
                display: grid;
                gap: 16px;
                margin-top: 14px;
                grid-template-columns: repeat(12, 1fr)
            }

            @media (max-width:1399px) {
                .grid-cards {
                    grid-template-columns: repeat(9, 1fr)
                }
            }

            @media (max-width:991px) {
                .grid-cards {
                    grid-template-columns: repeat(6, 1fr)
                }
            }

            @media (max-width:575px) {
                .grid-cards {
                    grid-template-columns: repeat(1, 1fr)
                }
            }

            .p-card {
                grid-column: span 6;
                background: var(--bg-card);
                border: 1px solid var(--line);
                border-radius: 16px;
                box-shadow: 0 10px 30px rgba(0, 0, 0, .35);
                overflow: hidden;
                transition: transform .15s ease
            }

            .p-card:hover {
                transform: translateY(-2px)
            }

            .p-card__head {
                display: flex;
                align-items: center;
                justify-content: space-between;
                gap: 12px;
                padding: 14px 16px;
                border-bottom: 1.4px solid var(--line)
            }

            .p-card__title {
                display: flex;
                flex-direction: column;
                gap: 2px;
                min-width: 0
            }

            .p-card__name {
                font-weight: 800;
                letter-spacing: .2px;
                white-space: nowrap;
                overflow: hidden;
                text-overflow: ellipsis
            }

            .p-card__meta {
                color: var(--muted);
                font-size: .86rem;
                white-space: nowrap;
                overflow: hidden;
                text-overflow: ellipsis
            }

            .p-chip {
                display: inline-flex;
                align-items: center;
                gap: .4rem;
                padding: .28rem .6rem;
                border-radius: 999px;
                font-weight: 700;
                font-size: .78rem;
                border: 1px solid rgba(255, 255, 255, .14);
                color: #e9e9ee
            }

            .p-chip--ready {
                background: rgba(30, 158, 107, .18);
                border-color: rgba(30, 158, 107, .45);
                color: #c9ffea
            }

            .p-chip--wait {
                background: rgba(255, 198, 93, .18);
                border-color: rgba(255, 198, 93, .45);
                color: #ffe9b5
            }

            .p-chip--none {
                background: rgba(120, 120, 120, .18);
                border-color: rgba(120, 120, 120, .35);
                color: #cfd3dc
            }

            .p-card__body {
                padding: 14px 16px;
                display: grid;
                grid-template-columns: 1fr auto;
                gap: 14px
            }

            .p-stats {
                display: flex;
                flex-wrap: wrap;
                gap: 10px 18px
            }

            .stat {
                min-width: 120px
            }

            .stat__label {
                color: var(--muted);
                font-size: .8rem;
                margin-bottom: 7px
            }

            .stat__value {
                font-weight: 800
            }

            .prog {
                display: flex;
                align-items: center;
                gap: 10px;
                min-width: 220px
            }

            .prog__bar {
                height: 10px;
                border-radius: 999px;
                background: rgba(255, 255, 255, .14);
                overflow: hidden;
                width: 160px
            }

            .prog__fill {
                height: 100%;
                background: var(--primary);
                border-radius: 999px
            }

            .prog__pct {
                color: var(--muted);
                font-size: .86rem;
                min-width: 40px
            }

            .trk {
                border-top: 1px dashed var(--line);
                padding: 12px 16px;
                background: #131723
            }

            .trk__row {
                display: grid;
                grid-template-columns: 24px 1fr auto;
                gap: 10px;
                align-items: center;
                padding: 6px 0
            }

            .trk__dot {
                width: 10px;
                height: 10px;
                border-radius: 50%;
                background: rgba(255, 255, 255, .22);
                margin-left: 7px
            }

            .trk__dot--done {
                background: var(--primary)
            }

            .trk__label {
                font-weight: 700
            }

            .trk__time {
                color: var(--muted);
                font-size: .8rem;
                white-space: nowrap
            }

            .p-card__foot {
                padding: 12px 16px;
                border-top: 1.2px solid var(--line);
                display: flex;
                flex-wrap: wrap;
                gap: 8px
            }

            .btn-ghost {
                display: inline-flex;
                align-items: center;
                gap: .4rem;
                padding: .44rem .7rem;
                border-radius: 10px;
                font-weight: 700;
                letter-spacing: .2px;
                border: 1.5px solid rgba(255, 255, 255, .16);
                color: #e9e9ee;
                background: transparent
            }

            .btn-ghost:hover {
                border-color: var(--primary);
                color: #fff;
                box-shadow: 0 0 0 .12rem rgba(155, 94, 255, .2) inset
            }

            .btn-ghost[disabled],
            .btn-ghost[aria-disabled="true"] {
                opacity: .5;
                pointer-events: none
            }

            @media (max-width:575px) {
                .p-card {
                    grid-column: span 1
                }

                .p-card__body {
                    grid-template-columns: 1fr
                }

                .prog {
                    min-width: 100%
                }
            }
        </style>

        <!-- ============================ VIEW ============================ -->
        <div class="card-surface" id="blok-siap-dinilai">
            <div class="d-flex flex-column gap-2">
                <div style="display:flex; flex-direction:column; gap:.25rem; margin-bottom:1rem;">
                    <h5 style="margin:0; font-weight:600; font-size:1.2rem; display:flex; align-items:center; gap:.5rem;">
                        Peserta Siap Mengerjakan Soal dan Dinilai
                        <i class="fas fa-clipboard-list" style="color:#9b5eff;"></i>
                    </h5>
                    <div style="font-size:.79rem; color:#eaeaea;">
                        Daftar peserta berikut menampilkan profil ringkas, progres pengerjaan soal,
                        riwayat waktu, serta aksi utama yang dapat dilakukan asesor.
                    </div>
                </div>


                <!-- Filter (acuannya sama dengan “kategori” di dashboard) -->
                <div style="display:flex; align-items:center; justify-content:space-between; gap:.75rem;
            padding:.5rem; background:rgba(255,255,255,.04); border:1px solid rgba(255,255,255,.12);
            border-radius:1rem; backdrop-filter:blur(6px);">

                    <!-- LEFT: segmented -->
                    <div style="display:flex; gap:.5rem;">
                        <!-- Semua (active) -->
                        <button class="js-card-filter" data-filter="all"
                            style="display:inline-flex; align-items:center; gap:.5rem; padding:.55rem .9rem;
             border:1px solid rgba(155,94,255,.55); border-radius:.8rem;
             background:linear-gradient(0deg, rgba(155,94,255,.16), rgba(155,94,255,.10));
             color:#fff; font-weight:600; font-size:.92rem; cursor:pointer;">
                            <i class="fa-solid fa-list" style="font-size:.95rem;"></i>
                            <span>Semua</span>
                            <span style="margin-left:.35rem; padding:.15rem .45rem; border-radius:.6rem; font-size:.75rem; font-weight:700;
                   color:#fff; background:rgba(155,94,255,.22); border:1px solid rgba(155,94,255,.65);">
                                <?= (int)$sum_all ?>
                            </span>
                        </button>

                        <!-- Belum Soal -->
                        <button class="js-card-filter" data-filter="no_soal"
                            style="display:inline-flex; align-items:center; gap:.5rem; padding:.55rem .9rem;
             border:1px solid transparent; border-radius:.8rem; background:transparent;
             color:#a7a7b3; font-weight:600; font-size:.92rem; cursor:pointer;">
                            <i class="fa-solid fa-paperclip" style="font-size:.95rem;"></i>
                            <span>Belum Kirim Soal</span>
                            <span style="margin-left:.35rem; padding:.15rem .45rem; border-radius:.6rem; font-size:.75rem; font-weight:700;
                   color:#cfc7ff; background:rgba(155,94,255,.14); border:1px solid rgba(155,94,255,.35);">
                                <?= (int)$sum_no_soal ?>
                            </span>
                        </button>

                        <!-- Menunggu -->
                        <button class="js-card-filter" data-filter="wait_jwb"
                            style="display:inline-flex; align-items:center; gap:.5rem; padding:.55rem .9rem;
             border:1px solid transparent; border-radius:.8rem; background:transparent;
             color:#a7a7b3; font-weight:600; font-size:.92rem; cursor:pointer;">
                            <i class="fa-solid fa-hourglass-half" style="font-size:.95rem;"></i>
                            <span>Menunggu</span>
                            <span style="margin-left:.35rem; padding:.15rem .45rem; border-radius:.6rem; font-size:.75rem; font-weight:700;
                   color:#cfc7ff; background:rgba(155,94,255,.14); border:1px solid rgba(155,94,255,.35);">
                                <?= (int)$sum_wait_jwb ?>
                            </span>
                        </button>

                        <!-- Siap Dinilai -->
                        <button class="js-card-filter" data-filter="ready"
                            style="display:inline-flex; align-items:center; gap:.5rem; padding:.55rem .9rem;
             border:1px solid transparent; border-radius:.8rem; background:transparent;
             color:#a7a7b3; font-weight:600; font-size:.92rem; cursor:pointer;">
                            <i class="fa-solid fa-check" style="font-size:.95rem;"></i>
                            <span>Siap Dinilai</span>
                            <span style="margin-left:.35rem; padding:.15rem .45rem; border-radius:.6rem; font-size:.75rem; font-weight:700;
                   color:#cfc7ff; background:rgba(155,94,255,.14); border:1px solid rgba(155,94,255,.35);">
                                <?= (int)$sum_ready ?>
                            </span>
                        </button>
                    </div>

                    <!-- RIGHT: search -->
                    <!-- RIGHT: search + sort -->
                    <div style="min-width:320px; display:flex; align-items:center; gap:.5rem;">
                        <!-- Search -->
                        <div style="display:flex; align-items:center; width:100%;
              background:rgba(255,255,255,.04); border:2px solid rgba(255,255,255,.04);
              border-radius:.8rem; padding:.35rem .6rem;">
                            <i class="fa-solid fa-magnifying-glass" style="opacity:.7; margin-right:.35rem;"></i>
                            <input id="segSearch" type="search" placeholder="Cari nama, bidang, kategori, posisi…"
                                style="background:transparent; border:0; outline:0; color:#e9e9ee; width:100%; font-size:.92rem;">
                        </div>

                        <!-- Sort hamburger (tanpa caret) -->
                        <div class="btn-group">
                            <a href="#" data-bs-toggle="dropdown" data-bs-auto-close="true" aria-expanded="false"
                                style="display:inline-flex; align-items:center; justify-content:center; width:40px; height:40px;
            border-radius:.8rem; border:1px solid rgba(255,255,255,.12);
            background:rgba(255,255,255,.04); color:#e9e9ee; text-decoration:none;">
                                <i class="fa-solid fa-bars"></i>
                            </a>
                            <ul class="dropdown-menu dropdown-menu-end"
                                style="background:#121318; border:1px solid rgba(255,255,255,.12); border-radius:.75rem; overflow:hidden;">
                                <li>
                                    <h6 class="dropdown-header" style="color:#cfc7ff;">Urutkan</h6>
                                </li>
                                <li><a class="dropdown-item js-sort" data-sort="az" href="#" style="color:#e9e9ee;">Nama A–Z</a></li>
                                <li><a class="dropdown-item js-sort" data-sort="za" href="#" style="color:#e9e9ee;">Nama Z–A</a></li>
                                <li>
                                    <hr class="dropdown-divider" style="border-color:rgba(255,255,255,.12)">
                                </li>
                                <li><a class="dropdown-item js-sort" data-sort="status" href="#" style="color:#e9e9ee;">Status (Belum Soal → Menunggu → Siap)</a></li>
                            </ul>
                        </div>

                    </div>


                </div>

                <div class="grid-cards" id="gridCards">
                    <?php if ($sum_all > 0): ?>
                        <?php foreach ($rows as $s):
                            $nm   = $s['nama_lengkap'];
                            $pos  = $s['nama_posisi'];
                            $tgl  = date('d M Y', strtotime($s['tanggal_daftar']));
                            $ls   = trim($s['link_soal'] ?? '');
                            $lj   = trim($s['link_jawaban'] ?? '');
                            $ada_soal = ($ls !== '');
                            $ada_jwb  = ($lj !== '');
                            $sudah_nilai = !empty($s['tanggal_dinilai']);
                            $step = 1 + ($ada_soal ? 1 : 0) + ($ada_jwb ? 1 : 0) + ($sudah_nilai ? 1 : 0);
                            $pct  = (int)round(($step / 4) * 100);
                            $rowTag = $ada_jwb ? 'ready' : ($ada_soal ? 'wait_jwb' : 'no_soal');
                            $rekom = strtolower((string)($s['rekomendasi'] ?? ''));
                        ?>
                            <div class="p-card" data-tag="<?= esc($rowTag) ?>">
                                <!-- Header -->
                                <div class="p-card__head">
                                    <div class="p-card__title">
                                        <div class="p-card__name" title="<?= esc($nm) ?>"><?= esc($nm) ?></div>
                                        <div class="p-card__meta"><?= esc($pos) ?> • <?= esc($tgl) ?></div>
                                    </div>
                                    <div class="d-flex flex-wrap gap-2">
                                        <?php if ($ada_jwb): ?>
                                            <span class="p-chip p-chip--ready"><i class="fa-solid fa-circle-check"></i> Siap Dinilai</span>
                                        <?php elseif ($ada_soal): ?>
                                            <span class="p-chip p-chip--wait"><i class="fa-solid fa-hourglass-half"></i> Menunggu Jawaban</span>
                                        <?php else: ?>
                                            <span class="p-chip p-chip--none"><i class="fa-solid fa-paperclip"></i> Belum Dikirim Soal</span>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <!-- Body -->
                                <div class="p-card__body">
                                    <div class="p-stats">
                                        <div class="stat">
                                            <div class="stat__label">Progres</div>
                                            <div class="prog">
                                                <div class="prog__bar">
                                                    <div class="prog__fill" style="width:<?= $pct ?>%"></div>
                                                </div>
                                                <div class="prog__pct"><?= $pct ?>%</div>
                                            </div>
                                        </div>
                                        <div class="stat">
                                            <div class="stat__label">Skor</div>
                                            <div class="stat__value">
                                                <?php if ($sudah_nilai): ?>
                                                    <span class="badge rounded-pill text-bg-success" style="background:#1e9e6b;color:#fff"><?= (int)$s['skor'] ?></span>
                                                <?php else: ?>
                                                    <span class="text-muted">—</span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        <div class="stat">
                                            <div class="stat__label">Status Peserta</div>
                                            <div class="stat__value">
                                                <?php if ($rekom === 'layak'): ?>
                                                    <span class="badge rounded-pill" style="background:#183f31;color:#b9ffe7;border:1px solid #2e9f7a">Layak Lulus</span>
                                                <?php elseif ($rekom === 'belum layak'): ?>
                                                    <span class="badge rounded-pill" style="background:#3a1a1f;color:#ffe3e3;border:1px solid #e05252">Belum Layak Lulus</span>
                                                <?php elseif ($rekom === 'di review'): ?>
                                                    <span class="badge rounded-pill"
                                                        style="background:#4a4032;
             color:#f5e7c6;
             border:1px solid rgba(245, 231, 198, .45);
             font-weight:600;">
                                                        Menunggu
                                                    </span>
                                                <?php else: ?>
                                                    <span class="text-muted">—</span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Tracking (tanpa kolom timestamp tambahan; siap jika nanti ditambah) -->
                                <div class="trk">
                                    <div class="trk__row">
                                        <div class="trk__dot trk__dot--done"></div>
                                        <div class="trk__label">Pendaftaran</div>
                                        <div class="trk__time"><?= esc($s['tanggal_daftar']) ?></div>
                                    </div>
                                    <div class="trk__row">
                                        <div class="trk__dot <?= $ada_soal ? 'trk__dot--done' : '' ?>"></div>
                                        <div class="trk__label">Soal Diberikan</div>
                                        <div class="trk__time"><?= $ada_soal ? 'Sudah Diberikan' : 'Belum tersedia' ?></div>
                                    </div>
                                    <div class="trk__row">
                                        <div class="trk__dot <?= $ada_jwb ? 'trk__dot--done' : '' ?>"></div>
                                        <div class="trk__label">Jawaban Diterima</div>
                                        <div class="trk__time"><?= $ada_jwb ? 'Sudah Diterima' : 'Belum tersedia' ?></div>
                                    </div>
                                    <div class="trk__row">
                                        <div class="trk__dot <?= $sudah_nilai ? 'trk__dot--done' : '' ?>"></div>
                                        <div class="trk__label">Dinilai</div>
                                        <div class="trk__time"><?= $sudah_nilai ? esc($s['tanggal_dinilai']) : '—' ?></div>
                                    </div>
                                </div>

                                <!-- Footer: Aksi (PERSIS logika dashboard: Simpan Soal / Soal, Buka Jawaban, Nilai, Batalkan) -->
                                <div class="p-card__foot">
                                    <?php if (!$ada_soal): ?>
                                        <!-- Upload Soal -->
                                        <a href="#"
                                            class="btn btn-ghost js-upload-soal"
                                            title="Upload link soal Google Drive"
                                            data-bs-toggle="modal"
                                            data-bs-target="#modalUploadSoal"
                                            data-id="<?= (int)$s['id_pendaftaran'] ?>"
                                            data-return="daftar_penilaian.php#blok-siap-dinilai"
                                            data-link="">
                                            <i class="fa-solid fa-up-right-from-square"></i> Upload Soal
                                        </a>
                                    <?php else: ?>
                                        <!-- Buka / Edit Soal -->
                                        <a href="<?= esc($ls) ?>"
                                            target="_blank"
                                            class="btn btn-ghost"
                                            title="Buka soal di Google Drive">
                                            <i class="fa-brands fa-google-drive me-1"></i> Buka Soal
                                        </a>

                                    <?php endif; ?>


                                    <?php
                                    $buka_jawaban_ok = $ada_jwb;
                                    $nilai_ok        = $ada_jwb;
                                    ?>
                                    <a href="<?= $buka_jawaban_ok ? esc($lj) : '#' ?>"
                                        target="_blank"
                                        class="btn btn-ghost <?= $buka_jawaban_ok ? '' : 'disabled' ?>"
                                        aria-disabled="<?= $buka_jawaban_ok ? 'false' : 'true' ?>"
                                        title="<?= $buka_jawaban_ok ? 'Buka jawaban peserta' : 'Belum ada jawaban peserta' ?>">
                                        <i class="fa-solid fa-up-right-from-square"></i> Buka Jawaban
                                    </a>

                                    <a href="<?= $nilai_ok ? 'penilaian.php?id=' . (int)$s['id_pendaftaran'] : '#' ?>"
                                        class="btn btn-ghost <?= $nilai_ok ? '' : 'disabled' ?>"
                                        aria-disabled="<?= $nilai_ok ? 'false' : 'true' ?>"
                                        title="<?= $nilai_ok ? 'Mulai menilai' : 'Menunggu jawaban peserta' ?>">
                                        <i class="fa-solid fa-pen-to-square"></i> Nilai
                                    </a>

                                    <form action="verifikasi_pendaftaran.php" method="post" class="d-inline ms-auto"
                                        onsubmit="return confirm('Kembalikan peserta ini ke tahap Dokumen Peserta?');">
                                        <input type="hidden" name="id" value="<?= (int)$s['id_pendaftaran'] ?>">
                                        <input type="hidden" name="aksi" value="batal">
                                        <input type="hidden" name="return_to" value="daftar_penilaian.php#blok-siap-dinilai">
                                        <button type="submit" class="btn btn-ghost" title="Batalkan & kembalikan ke Dokumen">
                                            <i class="fas fa-times"></i> Batalkan
                                        </button>
                                    </form>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="text-center text-muted py-4">Belum ada peserta siap dinilai.</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <br>
        <div class="footer"
            style="margin-top:auto;">
            &copy; <?= date('Y') ?> Created by PT. Reliable Future Technology
        </div>


        <!-- ============================ SCRIPT ============================ -->
        <script>
            (() => {
                // ====== GLOBAL STATE ======
                let segFilter = 'all';
                let segQuery = '';
                let fetchCtrl = null; // AbortController untuk race-click

                // ====== UTIL ======
                const normalize = s => (s || '').toString().toLowerCase()
                    .normalize('NFD').replace(/[\u0300-\u036f]/g, '').trim();

                function cardMatchesQuery(card, q) {
                    if (!q) return true;
                    const nama = card.dataset.nama || '';
                    const bidang = card.dataset.bidang || '';
                    const kategori = card.dataset.kategori || '';
                    const posisi = card.dataset.posisi || '';
                    const fallback = card.textContent || '';
                    const haystack = normalize([nama, bidang, kategori, posisi, fallback].join(' '));
                    return haystack.includes(normalize(q));
                }

                function cardMatchesSegment(card, seg) {
                    if (seg === 'all') return true;
                    const tag = card.dataset.tag || ''; // no_soal | wait_jwb | ready
                    return tag === seg;
                }

                function runFilter() {
                    const cards = document.querySelectorAll('#gridCards .p-card');
                    cards.forEach(card => {
                        const ok = cardMatchesSegment(card, segFilter) && cardMatchesQuery(card, segQuery);
                        card.classList.toggle('d-none', !ok);
                    });
                }

                // ====== MODAL ELEMENTS GETTER ======
                function getModalEls() {
                    const modalEl = document.getElementById('modalUploadSoal');
                    return {
                        modalEl,
                        modal: bootstrap.Modal.getOrCreateInstance(modalEl),
                        usId: modalEl.querySelector('#us_id'),
                        usRet: modalEl.querySelector('#us_return'),
                        usLink: modalEl.querySelector('#us_link'),
                        usInfo: modalEl.querySelector('#us_info'),
                        btnSub: modalEl.querySelector('#us_btn_submit'),
                        btnBank: modalEl.querySelector('#us_btn_bank'),
                        usTitle: modalEl.querySelector('#us_title'),
                        missBox: modalEl.querySelector('#us_missing_meta') // opsional (boleh tidak ada di DOM)
                    };
                }

                // ====== MODAL STATE HELPERS ======
                function setModalCheckingState(m) {
                    m.usLink.value = '';
                    m.usLink.readOnly = false;
                    m.usLink.disabled = false;
                    m.usLink.required = true;
                    m.usLink.style.opacity = '1';
                    m.usLink.style.pointerEvents = 'auto';

                    if (m.usInfo) {
                        m.usInfo.style.color = '#888';
                        m.usInfo.textContent = 'Memeriksa Bank Soal...';
                    }
                    if (m.btnSub) m.btnSub.classList.remove('d-none');
                    if (m.btnBank) m.btnBank.classList.add('d-none');
                    if (m.missBox) {
                        m.missBox.classList.add('d-none');
                        m.missBox.innerHTML = '';
                    }
                }

                function setModalAvailableState(m, link) {
                    m.usLink.value = link || '';
                    m.usLink.readOnly = true;
                    m.usLink.disabled = false;
                    m.usLink.required = true;
                    m.usLink.style.opacity = '1';
                    m.usLink.style.pointerEvents = 'auto';

                    if (m.usInfo) {
                        m.usInfo.style.color = '#7dd3fc';
                        m.usInfo.textContent = 'Soal otomatis diambil dari Bank Soal.';
                    }
                    if (m.btnSub) m.btnSub.classList.remove('d-none');
                    if (m.btnBank) m.btnBank.classList.add('d-none');
                    if (m.missBox) {
                        m.missBox.classList.add('d-none');
                        m.missBox.innerHTML = '';
                    }
                    if (m.usTitle) m.usTitle.textContent = 'Upload Soal'; // atau "Konfirmasi Soal"
                }

                function setModalMissingState(m, payload = {}) {
                    // Non-editable & tidak bisa submit
                    m.usLink.value = '';
                    m.usLink.readOnly = true;
                    m.usLink.disabled = true;
                    m.usLink.required = false;
                    m.usLink.style.opacity = '.5';
                    m.usLink.style.pointerEvents = 'none';

                    if (m.usInfo) {
                        m.usInfo.style.color = '#facc15';
                        m.usInfo.innerHTML = 'Belum ada soal untuk posisi peserta ini. Silakan buat terlebih dahulu di <strong>Bank Soal</strong>.';
                    }
                    if (m.btnSub) m.btnSub.classList.add('d-none');
                    if (m.btnBank) {
                        m.btnBank.classList.remove('d-none');
                        if (payload.url_bank_soal) m.btnBank.href = payload.url_bank_soal;
                    }

                    // Tampilkan meta (bidang/kategori/posisi) jika tersedia
                    if (m.missBox && payload.meta) {
                        const {
                            bidang,
                            kategori,
                            posisi
                        } = payload.meta;
                        const pill = (label, val) =>
                            `<span class="me-2 mb-2" style="display:inline-block; padding:.25rem .5rem; border:1px solid rgba(255,255,255,.15); border-radius:.5rem; background:rgba(255,255,255,.03); color:#ddd;">
           <strong style="color:#cfc7ff;">${label}:</strong> ${val}
         </span>`;
                        m.missBox.innerHTML = [
                            bidang ? pill('Bidang', `${bidang.nama} (ID ${bidang.id})`) : '',
                            kategori ? pill('Kategori', `${kategori.nama} (ID ${kategori.id})`) : '',
                            posisi ? pill('Posisi', `${posisi.nama} (ID ${posisi.id})`) : ''
                        ].join('');
                        m.missBox.classList.remove('d-none');
                    }
                    if (m.usTitle) m.usTitle.textContent = 'Upload Soal';
                }

                function setModalErrorState(m) {
                    // Treat as "must go to Bank Soal"
                    m.usLink.value = '';
                    m.usLink.readOnly = true;
                    m.usLink.disabled = true;
                    m.usLink.required = false;
                    m.usLink.style.opacity = '.5';
                    m.usLink.style.pointerEvents = 'none';

                    if (m.usInfo) {
                        m.usInfo.style.color = '#f87171';
                        m.usInfo.innerHTML = 'Gagal memeriksa Bank Soal. Silakan buat/cek soal di <strong>Bank Soal</strong>.';
                    }
                    if (m.btnSub) m.btnSub.classList.add('d-none');
                    if (m.btnBank) m.btnBank.classList.remove('d-none');
                    if (m.missBox) {
                        m.missBox.classList.add('d-none');
                        m.missBox.innerHTML = '';
                    }
                    if (m.usTitle) m.usTitle.textContent = 'Upload Soal';
                }

                // ====== MAIN DELEGATED CLICK (SATU SAJA) ======
                document.addEventListener('click', async function(e) {
                    // 1) Segmented filter
                    const btn = e.target.closest('.js-card-filter');
                    if (btn) {
                        // visual active
                        document.querySelectorAll('.js-card-filter').forEach(b => {
                            b.style.border = '1px solid transparent';
                            b.style.background = 'transparent';
                            b.style.color = '#a7a7b3';
                        });
                        btn.style.border = '1px solid rgba(155,94,255,.55)';
                        btn.style.background = 'linear-gradient(0deg, rgba(155,94,255,.16), rgba(155,94,255,.10))';
                        btn.style.color = '#fff';

                        segFilter = btn.dataset.filter || 'all';
                        runFilter();
                        return;
                    }

                    // 2) Upload Soal (modal)
                    const a = e.target.closest('.js-upload-soal');
                    if (!a) return;

                    e.preventDefault(); // cegah navigasi hash biar tidak flicker

                    const id = a.dataset.id || '';
                    const link = a.dataset.link || ''; // bila kamu masih isi dataset link
                    const ret = a.dataset.return || 'daftar_penilaian.php#blok-siap-dinilai';

                    const m = getModalEls();

                    // isi field hidden + placeholder
                    m.usId.value = id;
                    m.usRet.value = ret;
                    if (m.usTitle) m.usTitle.textContent = link ? 'Edit Soal' : 'Upload Soal';
                    if (m.usLink) {
                        m.usLink.value = link;
                        m.usLink.placeholder = link || 'https://drive.google.com/...';
                    }

                    // buka modal
                    m.modal.show();

                    // set checking state
                    setModalCheckingState(m);

                    // batalkan request sebelumnya jika ada (hindari race)
                    if (fetchCtrl) fetchCtrl.abort();
                    fetchCtrl = new AbortController();

                    try {
                        // SESUAIKAN path relatif bila endpoint beda folder
                        const resp = await fetch('get_link_soal.php?id_pendaftaran=' + encodeURIComponent(id), {
                            cache: 'no-store',
                            signal: fetchCtrl.signal
                        });
                        const d = await resp.json();

                        if (d && d.ok && d.link) {
                            setModalAvailableState(m, d.link);
                        } else if (d && (d.must_create || d.meta || d.url_bank_soal)) {
                            setModalMissingState(m, d);
                        } else {
                            // Tidak ok dan tidak ada petunjuk → treat as missing
                            setModalMissingState(m, d || {});
                        }
                    } catch (err) {
                        if (err.name === 'AbortError') return; // diklik lagi; abaikan
                        setModalErrorState(m);
                    }
                });

                // ====== FOCUS input saat modal tampil ======
                const modalUpload = document.getElementById('modalUploadSoal');
                if (modalUpload) {
                    modalUpload.addEventListener('shown.bs.modal', function() {
                        const input = document.getElementById('us_link');
                        if (input && !input.disabled) {
                            try {
                                input.focus({
                                    preventScroll: true
                                });
                            } catch (_) {
                                input.focus();
                            }
                        }
                    });
                }

                // ====== Search input (debounce) ======
                (function() {
                    const input = document.getElementById('segSearch');
                    let t;
                    if (!input) return;
                    input.addEventListener('input', function() {
                        clearTimeout(t);
                        const v = this.value;
                        t = setTimeout(function() {
                            segQuery = v;
                            runFilter();
                        }, 200);
                    });
                })();

                // ====== Initial run ======
                document.addEventListener('DOMContentLoaded', runFilter);
            })();
        </script>
        <script>
            (() => {
                const grid = document.querySelector('#gridCards');
                if (!grid) return;

                const $$ = (sel, ctx = document) => Array.from(ctx.querySelectorAll(sel));
                const norm = s => (s || '').toString().toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '').trim();

                // urutan status: Belum Soal → Menunggu → Siap → (opsional: Tidak Lulus → Sudah Lulus)
                const order = {
                    no_soal: 1,
                    menunggu: 2,
                    siap: 3,
                    tidak_lulus: 4,
                    lulus: 5
                };

                function getStatus(card) {
                    // prefer data-status, fallback dari data-tag legacy
                    let st = (card.dataset.status || '').toLowerCase();
                    if (!st) {
                        const tag = (card.dataset.tag || '').toLowerCase();
                        if (tag === 'no_soal') st = 'no_soal';
                        else if (tag === 'wait_jwb') st = 'menunggu';
                        else if (tag === 'ready') st = 'siap';
                    }
                    return order.hasOwnProperty(st) ? st : 'menunggu';
                }

                function getTime(card) {
                    const raw = card.dataset.time || '';
                    if (!raw) return 0;

                    // epoch ms
                    if (/^\d{13}$/.test(raw)) return parseInt(raw, 10);
                    // epoch s
                    if (/^\d{10}$/.test(raw)) return parseInt(raw, 10) * 1000;

                    // ISO / Date.parse-friendly
                    let t = Date.parse(raw);
                    if (!isNaN(t)) return t;

                    // "YYYY-MM-DD HH:MM:SS" → jadikan ISO lokal
                    const m = raw.match(/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})(?::(\d{2}))?$/);
                    if (m) {
                        const iso = `${m[1]}-${m[2]}-${m[3]}T${m[4]}:${m[5]}:${m[6] || '00'}`;
                        const tt = Date.parse(iso);
                        if (!isNaN(tt)) return tt;
                    }

                    // "DD Mon YYYY" (fallback ringan)
                    const m2 = raw.match(/^(\d{1,2})\s+([A-Za-z]{3,})\s+(\d{4})$/);
                    if (m2) {
                        const tt = Date.parse(`${m2[1]} ${m2[2]} ${m2[3]}`);
                        if (!isNaN(tt)) return tt;
                    }

                    return 0;
                }


                function sortCards(mode) {
                    const cards = $$('.p-card', grid);
                    const sorted = cards.slice();

                    if (mode === 'time_desc') {
                        sorted.sort((a, b) => getTime(b) - getTime(a));
                    } else if (mode === 'time_asc') {
                        sorted.sort((a, b) => getTime(a) - getTime(b));
                    } else if (mode === 'status') {
                        sorted.sort((a, b) => {
                            const sa = order[getStatus(a)] || 999;
                            const sb = order[getStatus(b)] || 999;
                            if (sa !== sb) return sa - sb;
                            // tie-break: terbaru dulu
                            return getTime(b) - getTime(a);
                        });
                    } else if (mode === 'az' || mode === 'za') {
                        sorted.sort((a, b) => {
                            const na = norm(a.dataset.nama || a.textContent);
                            const nb = norm(b.dataset.nama || b.textContent);
                            const cmp = na.localeCompare(nb, 'id', {
                                sensitivity: 'base'
                            });
                            return mode === 'az' ? cmp : -cmp;
                        });
                    }

                    // re-append sesuai urutan
                    sorted.forEach(el => grid.appendChild(el));
                }

                // handler menu
                document.addEventListener('click', function(e) {
                    const item = e.target.closest('.js-sort');
                    if (!item) return;
                    e.preventDefault();
                    const mode = item.dataset.sort;
                    sortCards(mode);
                });

                // optional: sort default saat load (mis. terbaru)
                document.addEventListener('DOMContentLoaded', () => sortCards('time_desc'));
            })();
        </script>


</body>

</html>