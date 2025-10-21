<?php
// notifikasi.php (FINAL - MySQLi & Auto Sync)
session_start();
require_once '../config/koneksi.php'; // ini mendefinisikan $conn (MySQLi)

// ====== AUTH GUARD ======
if (!isset($_SESSION['id_pengguna'])) {
    header('Location: ../login.php');
    exit;
}
$id_pengguna = (int) $_SESSION['id_pengguna'];
$nama = isset($_SESSION['nama']) && $_SESSION['nama'] !== '' ? $_SESSION['nama'] : 'Asesor';

// ====== CSRF TOKEN ======
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$CSRF = $_SESSION['csrf_token'];

function verify_csrf()
{
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        http_response_code(400);
        die('Permintaan tidak valid (CSRF).');
    }
}

/**
 * Cek apakah notifikasi (judul+isi) sudah ada utk user.
 */
function notif_exists(mysqli $conn, int $id_pengguna, string $judul, string $isi): bool
{
    $q = $conn->prepare("SELECT id_notifikasi FROM notifikasi WHERE id_pengguna = ? AND judul = ? AND isi = ? LIMIT 1");
    $q->bind_param("iss", $id_pengguna, $judul, $isi);
    $q->execute();
    $res = $q->get_result();
    return (bool)$res->fetch_assoc();
}

/**
 * Insert notifikasi aman (hindari duplikasi berdasarkan judul+isi).
 * $waktu_ts optional (format 'Y-m-d H:i:s'); jika null akan pakai CURRENT_TIMESTAMP.
 */
function insert_notif(mysqli $conn, int $id_pengguna, string $judul, string $isi, ?string $waktu_ts = null)
{
    if (notif_exists($conn, $id_pengguna, $judul, $isi)) return;
    if ($waktu_ts) {
        $q = $conn->prepare("INSERT INTO notifikasi (id_pengguna, judul, isi, status_baca, waktu) VALUES (?, ?, ?, 0, ?)");
        $q->bind_param("isss", $id_pengguna, $judul, $isi, $waktu_ts);
    } else {
        $q = $conn->prepare("INSERT INTO notifikasi (id_pengguna, judul, isi, status_baca) VALUES (?, ?, ?, 0)");
        $q->bind_param("iss", $id_pengguna, $judul, $isi);
    }
    $q->execute();
}

/**
 * Sinkronisasi notifikasi dari data nyata untuk asesor yang login:
 * - Penilaian oleh asesor (penilaian.id_asesor = $id_pengguna)
 * - Sertifikat terbit untuk pendaftaran yang dinilai asesor
 * - Verifikasi QR terhadap sertifikat yang terkait pendaftaran yg dinilai asesor
 */
function sync_notifications(mysqli $conn, int $id_pengguna)
{
    // 1) Penilaian oleh asesor
    $sql = "
        SELECT p.id_penilaian, p.tanggal_dinilai, p.skor, p.rekomendasi, p.link_jawaban,
               pg.nama_lengkap AS nama_peserta, ps.nama_posisi
        FROM penilaian p
        JOIN pendaftaran d ON d.id_pendaftaran = p.id_pendaftaran
        JOIN pengguna pg ON pg.id_pengguna = d.id_pengguna
        JOIN posisi ps ON ps.id_posisi = d.id_posisi
        WHERE p.id_asesor = ? AND p.tanggal_dinilai IS NOT NULL
        ORDER BY p.tanggal_dinilai DESC";
    $st = $conn->prepare($sql);
    $st->bind_param("i", $id_pengguna);
    $st->execute();
    $res = $st->get_result();
    while ($row = $res->fetch_assoc()) {
        $judul = "Hasil Penilaian Tersimpan";
        $isi = sprintf(
            "Kamu menilai %s untuk posisi %s. Skor: %s/10, rekomendasi: %s.",
            $row['nama_peserta'] ?: 'Peserta',
            $row['nama_posisi'] ?: '-',
            (string)$row['skor'],
            $row['rekomendasi'] ?: '-'
        );
        $waktu = $row['tanggal_dinilai']; // datetime (Y-m-d H:i:s)
        insert_notif($conn, $id_pengguna, $judul, $isi, $waktu);
    }

    // 2) Sertifikat terbit utk pendaftaran yg dinilai asesor
    $sql2 = "
        SELECT s.id_sertifikat, s.tanggal_terbit, s.nomor_sertifikat, s.level_kompetensi,
               pg.nama_lengkap AS nama_peserta, ps.nama_posisi
        FROM sertifikat s
        JOIN penilaian p ON p.id_pendaftaran = s.id_pendaftaran
        JOIN pendaftaran d ON d.id_pendaftaran = s.id_pendaftaran
        JOIN pengguna pg ON pg.id_pengguna = d.id_pengguna
        JOIN posisi ps ON ps.id_posisi = d.id_posisi
        WHERE p.id_asesor = ? AND s.tanggal_terbit IS NOT NULL
        ORDER BY s.tanggal_terbit DESC";
    $st2 = $conn->prepare($sql2);
    $st2->bind_param("i", $id_pengguna);
    $st2->execute();
    $res2 = $st2->get_result();
    while ($row = $res2->fetch_assoc()) {
        $judul = "Sertifikat Terbit";
        $isi = sprintf(
            "Sertifikat %s (%s) untuk %s pada posisi %s telah terbit.",
            $row['nomor_sertifikat'] ?: '-',
            ucfirst($row['level_kompetensi'] ?: '-'),
            $row['nama_peserta'] ?: 'Peserta',
            $row['nama_posisi'] ?: '-'
        );
        // tanggal_terbit adalah DATE — beri jam 08:00 lokal biar rapi
        $waktu = $row['tanggal_terbit'] ? $row['tanggal_terbit'] . " 08:00:00" : null;
        insert_notif($conn, $id_pengguna, $judul, $isi, $waktu);
    }

    // 3) Verifikasi QR yg terkait pendaftaran yg dinilai asesor
    $sql3 = "
        SELECT v.kode_qr, v.waktu_verifikasi, v.ip_address,
               s.nomor_sertifikat, pg.nama_lengkap AS nama_peserta, ps.nama_posisi
        FROM verifikasi_qr v
        JOIN sertifikat s ON s.kode_qr = v.kode_qr
        JOIN penilaian p ON p.id_pendaftaran = s.id_pendaftaran
        JOIN pendaftaran d ON d.id_pendaftaran = s.id_pendaftaran
        JOIN pengguna pg ON pg.id_pengguna = d.id_pengguna
        JOIN posisi ps ON ps.id_posisi = d.id_posisi
        WHERE p.id_asesor = ?
        ORDER BY v.waktu_verifikasi DESC";
    $st3 = $conn->prepare($sql3);
    $st3->bind_param("i", $id_pengguna);
    $st3->execute();
    $res3 = $st3->get_result();
    while ($row = $res3->fetch_assoc()) {
        $judul = "QR Sertifikat Diverifikasi";
        $isi = sprintf(
            "Sertifikat %s (%s - %s) diverifikasi dari IP %s.",
            $row['nomor_sertifikat'] ?: '-',
            $row['nama_peserta'] ?: 'Peserta',
            $row['nama_posisi'] ?: '-',
            $row['ip_address'] ?: '-'
        );
        $waktu = $row['waktu_verifikasi']; // timestamp
        insert_notif($conn, $id_pengguna, $judul, $isi, $waktu);
    }
}

// ====== HANDLE AKSI ======
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'mark_read' && isset($_POST['id_notifikasi'])) {
        $id = (int) $_POST['id_notifikasi'];
        $stmt = $conn->prepare("UPDATE notifikasi SET status_baca = 1 WHERE id_notifikasi = ? AND id_pengguna = ?");
        $stmt->bind_param("ii", $id, $id_pengguna);
        $stmt->execute();
    } elseif ($action === 'mark_unread' && isset($_POST['id_notifikasi'])) {
        $id = (int) $_POST['id_notifikasi'];
        $stmt = $conn->prepare("UPDATE notifikasi SET status_baca = 0 WHERE id_notifikasi = ? AND id_pengguna = ?");
        $stmt->bind_param("ii", $id, $id_pengguna);
        $stmt->execute();
    } elseif ($action === 'delete' && isset($_POST['id_notifikasi'])) {
        $id = (int) $_POST['id_notifikasi'];
        $stmt = $conn->prepare("DELETE FROM notifikasi WHERE id_notifikasi = ? AND id_pengguna = ?");
        $stmt->bind_param("ii", $id, $id_pengguna);
        $stmt->execute();
    } elseif ($action === 'mark_all_read') {
        $stmt = $conn->prepare("UPDATE notifikasi SET status_baca = 1 WHERE id_pengguna = ?");
        $stmt->bind_param("i", $id_pengguna);
        $stmt->execute();
    } elseif ($action === 'sync') {
        // sinkronisasi manual via tombol
        sync_notifications($conn, $id_pengguna);
    }

    header('Location: notifikasi.php');
    exit;
}

// ====== AUTO SYNC (tiap buka halaman) ======
sync_notifications($conn, $id_pengguna);

// ====== QUERY DATA TAMPILAN ======
$stmt = $conn->prepare("
    SELECT id_notifikasi, judul, isi, status_baca, waktu
    FROM notifikasi
    WHERE id_pengguna = ?
    ORDER BY status_baca ASC, waktu DESC
");
$stmt->bind_param("i", $id_pengguna);
$stmt->execute();
$result = $stmt->get_result();
$rows = $result->fetch_all(MYSQLI_ASSOC);

$stmt2 = $conn->prepare("SELECT COUNT(*) AS unread FROM notifikasi WHERE id_pengguna = ? AND status_baca = 0");
$stmt2->bind_param("i", $id_pengguna);
$stmt2->execute();
$res2 = $stmt2->get_result()->fetch_assoc();
$unread_count = (int) ($res2['unread'] ?? 0);
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Notifikasi | RELIPROVE</title>
    <link rel="icon" href="../aset/img/logo.png" type="image/png">

    <!-- Library umum -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" />
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet" />
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600&display=swap" rel="stylesheet" />
    <link rel="stylesheet" href="https://cdn.datatables.net/responsive/2.5.0/css/responsive.bootstrap5.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.8/css/dataTables.bootstrap5.min.css">
    <link rel="stylesheet" href="../asesor/css/dashboard_asesor.css">

    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.8/js/dataTables.bootstrap5.min.js"></script>
    <script src="https://cdn.datatables.net/responsive/2.5.0/js/dataTables.responsive.min.js"></script>
    <script src="https://cdn.datatables.net/responsive/2.5.0/js/responsive.bootstrap5.min.js"></script>

    <style>
        .badge-unread {
            background: #e05252;
        }

        .badge-read {
            background: #1e9e6b;
        }

        .notif-title {
            font-weight: 700;
            color: #e9e9ee;
        }

        .notif-body {
            color: #a6abb7;
            margin-top: .25rem;
        }

        .action-btns .btn {
            padding: .35rem .6rem;
            border-radius: 10px;
        }

        .pill-unread {
            background: rgba(224, 82, 82, .2);
            color: #ffb3b3;
            border: 1px solid rgba(224, 82, 82, .35);
        }

        .pill-read {
            background: rgba(30, 158, 107, .2);
            color: #b8f0da;
            border: 1px solid rgba(30, 158, 107, .35);
        }

        .top-actions .btn {
            border-radius: 12px;
        }

        .empty-state {
            background: var(--bg-card, #161a25);
            border: 1px dashed rgba(255, 255, 255, 0.12);
            border-radius: 16px;
            padding: 32px;
            text-align: center;
            color: #a6abb7;
        }

        .empty-state i {
            font-size: 40px;
            color: var(--primary, #9b5eff);
            margin-bottom: 10px;
            display: block;
        }

        .nav-notif {
            position: relative;
        }

        .nav-notif .nav-dot {
            position: absolute;
            top: 10px;
            right: 14px;
            width: 10px;
            height: 10px;
            border-radius: 50%;
            background: #e05252;
            box-shadow: 0 0 0 3px rgba(224, 82, 82, .25);
        }

        .nav-notif .nav-badge {
            margin-left: 8px;
            padding: 2px 6px;
            font-size: 10px;
            font-weight: 800;
            color: #fff;
            background: #e05252;
            border-radius: 10px;
            border: 1px solid rgba(255, 255, 255, .16);
        }
    </style>
</head>
<?php
// ... setelah $id_pengguna dibuat
$jmlNotif = 0; // default agar tidak warning

if ($stmtN = $conn->prepare("SELECT COUNT(*) FROM notifikasi WHERE id_pengguna = ? AND status_baca = 0")) {
    $stmtN->bind_param("i", $id_pengguna);
    $stmtN->execute();
    $stmtN->bind_result($cnt);
    if ($stmtN->fetch()) {
        $jmlNotif = (int)$cnt;
    }
    $stmtN->close();
}
?>

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
        <a href="daftar_penilaian.php"><i class="fas fa-clipboard-list"></i> Daftar Penilaian</a>
        <a href="riwayat_penilaian.php"><i class="fas fa-history"></i> Riwayat Penilaian</a>
        <!-- <a href="sertifikat.php"><i class="fas fa-graduation-cap"></i> Sertifikat</a> -->
        <a href="notifikasi.php" class="active nav-notif" style="position:relative;">
            <i class="fas fa-bell"></i> Notifikasi
            <?php if ($jmlNotif > 0): ?>
                <!-- pilih salah satu: titik merah ATAU label NEW; boleh tampilkan keduanya -->
                <span class="nav-dot" aria-hidden="true"></span>
                <span class="nav-badge">NEW</span>
            <?php endif; ?>
        </a>


        <a href="pengaturan.php"><i class="fas fa-gear"></i> Pengaturan</a>
        <a href="../logout.php"><i class="fas fa-sign-out-alt"></i> Keluar</a>
    </div>

    <!-- CONTENT -->
    <div class="content">
        <!-- TOPBAR -->
        <div class="topbar">
            <div class="left-group" style="display:flex; align-items:center; gap:10px;">
                <div class="toggle-sidebar" onclick="document.getElementById('sidebar').classList.toggle('active')" style="cursor:pointer; display:flex; align-items:center;">
                    <i class="fas fa-bars"></i>
                </div>
                <h4 class="d-none d-md-block" style="margin:0; font-weight:600;">NOTIFIKASI</h4>
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

        <!-- HEADER CARD -->
        <div class="peserta-card">
            <h5 style="margin-bottom: .75rem;">
                <span><i class="fas fa-bell"></i> Pusat Notifikasi</span>
                <span class="trend">
                    <span class="badge <?= $unread_count > 0 ? 'badge-unread' : 'badge-read' ?>">
                        <?= $unread_count ?> belum dibaca
                    </span>
                </span>
            </h5>
            <div class="progress-bar-custom" aria-hidden="true">
                <div class="progress-fill"></div>
                <div class="progress-dots"></div>
            </div>

            <div class="d-flex gap-2 flex-wrap top-actions mt-3">
                <form method="post" class="m-0">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($CSRF) ?>">
                    <input type="hidden" name="action" value="mark_all_read">
                    <button class="btn btn-sm btn-primary">
                        <i class="fa-solid fa-envelope-open"></i> Tandai Semua Dibaca
                    </button>
                </form>

                <form method="post" class="m-0">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($CSRF) ?>">
                    <input type="hidden" name="action" value="sync">
                    <button class="btn btn-sm btn-secondary">
                        <i class="fa-solid fa-rotate"></i> Sinkronkan Notifikasi
                    </button>
                </form>
            </div>
        </div>

        <!-- ============================ NOTIFIKASI: CARDS VIEW ============================ -->

        <!-- STYLE CARD (dari guideline kamu) -->
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

            .p-card__body {
                padding: 14px 16px;
                border-top: 1px dashed var(--line);
                background: #131723
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
                background: transparent;
                text-decoration: none
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

            .empty-state {
                background: var(--bg-card);
                border: 1px dashed rgba(255, 255, 255, 0.12);
                border-radius: 16px;
                padding: 32px;
                text-align: center;
                color: #a6abb7;
            }

            .empty-state i {
                font-size: 40px;
                color: var(--primary);
                margin-bottom: 10px;
                display: block;
            }

            @media (max-width:575px) {
                .p-card {
                    grid-column: span 1
                }
            }
        </style>

        <div class="card-surface">
            <!-- BAR ATAS: filter + pencarian -->
            <div style="display:flex; align-items:center; gap:.75rem; flex-wrap:wrap; justify-content:space-between;">
                <div class="segmented" role="tablist" aria-label="Filter status">
                    <button type="button" class="seg-btn active js-filter" data-filter="all">Semua</button>
                    <button type="button" class="seg-btn js-filter" data-filter="unread">Belum dibaca</button>
                    <button type="button" class="seg-btn js-filter" data-filter="read">Dibaca</button>
                </div>

                <div style="min-width:280px; max-width:440px; width:100%; display:flex; align-items:center; gap:.5rem;">
                    <div style="display:flex; align-items:center; width:100%;
                        background:rgba(255,255,255,.04); border:2px solid rgba(255,255,255,.04);
                        border-radius:.8rem; padding:.35rem .6rem;">
                        <i class="fa-solid fa-magnifying-glass" style="opacity:.7; margin-right:.35rem;"></i>
                        <input id="notifSearch" type="search" placeholder="Cari judul atau isi notifikasi…"
                            style="background:transparent; border:0; outline:0; color:#e9e9ee; width:100%; font-size:.92rem;">
                    </div>
                </div>
            </div>

            <!-- GRID CARDS -->
            <div class="grid-cards" id="notifGrid" style="margin-top:14px;">
                <?php if (empty($rows)): ?>
                    <div class="empty-state" style="grid-column: 1/-1;">
                        <i class="fa-regular fa-bell"></i>
                        <h6 class="mb-1">Belum ada notifikasi</h6>
                        <div>Klik <strong>Sinkronkan Notifikasi</strong> di bagian atas bila kamu baru saja melakukan penilaian atau menerbitkan sertifikat.</div>
                    </div>
                <?php else: ?>
                    <?php foreach ($rows as $r):
                        $is_read = (int)$r['status_baca'] === 1;
                        $status  = $is_read ? 'read' : 'unread';
                        $chipCls = $is_read ? 'p-chip--ready' : 'p-chip--wait';
                        $chipTxt = $is_read ? 'Dibaca' : 'Belum dibaca';
                        $judul   = trim($r['judul'] ?: '(Tanpa judul)');
                        $isi     = trim($r['isi'] ?? '');
                        $waktu   = date('d M Y H:i', strtotime($r['waktu']));
                        $nid     = (int)$r['id_notifikasi'];
                    ?>
                        <div class="p-card js-card" data-status="<?= $status ?>"
                            data-title="<?= htmlspecialchars(mb_strtolower($judul)) ?>"
                            data-body="<?= htmlspecialchars(mb_strtolower($isi)) ?>">
                            <!-- HEAD: hanya judul + meta ringkas -->
                            <div class="p-card__head">
                                <div class="p-card__title">
                                    <div class="p-card__name" title="<?= htmlspecialchars($judul) ?>">
                                        <?= htmlspecialchars($judul) ?>
                                    </div>
                                    <div class="p-card__meta"><i class="fa-regular fa-clock me-1"></i><?= $waktu ?></div>
                                </div>
                                <span class="p-chip <?= $chipCls ?>"><i class="fa-solid fa-circle"></i> <?= $chipTxt ?></span>
                            </div>

                            <!-- BODY (collapse): detail notifikasi -->
                            <div class="p-card__body collapse" id="notifBody-<?= $nid ?>">
                                <?php if ($isi !== ''): ?>
                                    <div style="color:#d8d8e6; line-height:1.5; white-space:pre-wrap;"><?= nl2br(htmlspecialchars($isi)) ?></div>
                                <?php else: ?>
                                    <div style="color:#9aa0ad;">(Tidak ada detail)</div>
                                <?php endif; ?>
                            </div>

                            <!-- FOOT: tombol aksi -->
                            <div class="p-card__foot">
                                <a class="btn-ghost" data-bs-toggle="collapse" href="#notifBody-<?= $nid ?>" role="button" aria-expanded="false" aria-controls="notifBody-<?= $nid ?>">
                                    <i class="fa-regular fa-eye"></i> Lihat detail
                                </a>

                                <?php if (!$is_read): ?>
                                    <form method="post" class="d-inline">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($CSRF) ?>">
                                        <input type="hidden" name="action" value="mark_read">
                                        <input type="hidden" name="id_notifikasi" value="<?= $nid ?>">
                                        <button class="btn-ghost" title="Tandai dibaca">
                                            <i class="fa-solid fa-envelope-open"></i> Tandai dibaca
                                        </button>
                                    </form>
                                <?php else: ?>
                                    <form method="post" class="d-inline">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($CSRF) ?>">
                                        <input type="hidden" name="action" value="mark_unread">
                                        <input type="hidden" name="id_notifikasi" value="<?= $nid ?>">
                                        <button class="btn-ghost" title="Tandai belum dibaca">
                                            <i class="fa-regular fa-envelope"></i> Tandai belum dibaca
                                        </button>
                                    </form>
                                <?php endif; ?>

                                <form method="post" class="d-inline ms-auto" onsubmit="return confirm('Hapus notifikasi ini?')">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($CSRF) ?>">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id_notifikasi" value="<?= $nid ?>">
                                    <button class="btn-ghost" title="Hapus">
                                        <i class="fa-solid fa-trash"></i> Hapus
                                    </button>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- Bootstrap JS diperlukan untuk collapse -->
        <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

        <script>
            (function() {
                const grid = document.getElementById('notifGrid');
                const cards = Array.from(grid.querySelectorAll('.js-card'));
                const filterBtns = document.querySelectorAll('.js-filter');
                const searchInput = document.getElementById('notifSearch');

                function applyFilter() {
                    const active = document.querySelector('.js-filter.active');
                    const f = active ? active.dataset.filter : 'all';
                    const q = (searchInput.value || '').trim().toLowerCase();

                    cards.forEach(card => {
                        const status = card.dataset.status; // read/unread
                        const title = card.dataset.title || '';
                        const body = card.dataset.body || '';
                        const textOk = !q || title.includes(q) || body.includes(q);
                        const statusOk = (f === 'all') || (status === f);
                        card.style.display = (textOk && statusOk) ? '' : 'none';
                    });
                }

                filterBtns.forEach(btn => {
                    btn.addEventListener('click', () => {
                        filterBtns.forEach(b => b.classList.remove('active'));
                        btn.classList.add('active');
                        applyFilter();
                    });
                });

                searchInput.addEventListener('input', applyFilter);
            })();
        </script>


        <div class="footer">© <?= date('Y') ?> RELIPROVE — Notifikasi Asesor</div>
    </div>

    <script>
        $(function() {
            const hasTable = $('#tblNotif').length > 0;
            if (hasTable) {
                $('#tblNotif').DataTable({
                    responsive: true,
                    pageLength: 10,
                    lengthMenu: [10, 25, 50, 100],
                    order: [
                        [2, 'asc'],
                        [3, 'desc']
                    ], // Unread dulu, lalu terbaru
                    language: {
                        url: 'https://cdn.datatables.net/plug-ins/1.13.8/i18n/id.json'
                    },
                    columnDefs: [{
                            targets: 0,
                            width: '40px'
                        },
                        {
                            targets: 4,
                            orderable: false,
                            searchable: false,
                            width: '160px'
                        }
                    ]
                });
            }
        });
    </script>

</body>

</html>