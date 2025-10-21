<?php
// =====================================================
// RELIPROVE • log_aktivitas.php (Hi-Tech v2)
// Konsep baru: 3 Tab (Overview / Activity / QR Verify)
// =====================================================
session_start();
date_default_timezone_set('Asia/Jakarta');

// --- Guard role (opsional) ---
// if (!isset($_SESSION['peran']) || !in_array($_SESSION['peran'], ['admin','superadmin'])) {
//   header('Location: ../login.php'); exit;
// }

// --- Koneksi DB ---
$cfg = __DIR__ . '/../config/koneksi.php';
if (!file_exists($cfg)) die('Konfigurasi DB tidak ditemukan: ../config/koneksi.php');
require_once $cfg;

if (!isset($koneksi) || !($koneksi instanceof mysqli)) {
    if (isset($conn) && $conn instanceof mysqli) {
        $koneksi = $conn;
    } else die('Koneksi database tidak tersedia.');
}
$koneksi->set_charset('utf8mb4');

// --- Helper ---
function esc($s)
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}
function qs($k, $d = '')
{
    return isset($_GET[$k]) ? trim($_GET[$k]) : $d;
}
function dt($ts)
{
    return $ts ? date('d M Y, H:i', strtotime($ts)) : '-';
}
function scalar($db, $sql)
{
    $r = $db->query($sql);
    return ($r && ($row = $r->fetch_row())) ? (int)$row[0] : 0;
}
function scalarf($db, $sql)
{
    $r = $db->query($sql);
    return ($r && ($row = $r->fetch_row())) ? (float)$row[0] : 0.0;
}
function assoc($db, $sql)
{
    $r = $db->query($sql);
    return $r ? $r->fetch_assoc() : null;
}
function pagelinks($total, $page, $pp, $base)
{
    $pages = max(1, ceil($total / $pp));
    if ($pages <= 1) return '';
    $sep = (strpos($base, '?') === false) ? '?' : '&';
    $html = '<div class="chips" style="margin-top:10px;flex-wrap:wrap">';
    $mk = function ($p, $t, $act = false) use ($base, $sep) {
        $url = $base . $sep . 'page=' . $p;
        return '<a class="pill' . ($act ? ' primary' : '') . '" href="' . esc($url) . '">' . esc($t) . '</a>';
    };
    $html .= $mk(max(1, $page - 1), '«');
    for ($i = max(1, $page - 2); $i <= min($pages, $page + 2); $i++) $html .= $mk($i, (string)$i, $i == $page);
    $html .= $mk(min($pages, $page + 1), '»') . '</div>';
    return $html;
}

// --- Profile kanan atas ---
$me = [];
$namaAdmin = 'Admin';
if (isset($_SESSION['id_pengguna'])) {
    $idMe = (int)$_SESSION['id_pengguna'];
    $r = $koneksi->query("SELECT nama_lengkap, peran FROM pengguna WHERE id_pengguna=$idMe LIMIT 1");
    if ($r && $r->num_rows) {
        $me = $r->fetch_assoc();
        $namaAdmin = $me['nama_lengkap'] ?? 'Admin';
    }
}

// ================== DATA: OVERVIEW ==================
$total_pengguna   = scalar($koneksi, "SELECT COUNT(*) FROM pengguna");
$cnt_peserta      = scalar($koneksi, "SELECT COUNT(*) FROM pengguna WHERE peran='peserta'");
$cnt_asesor       = scalar($koneksi, "SELECT COUNT(*) FROM pengguna WHERE peran='asesor'");
$cnt_admin        = scalar($koneksi, "SELECT COUNT(*) FROM pengguna WHERE peran IN ('admin','superadmin')");

$total_pendaftaran = scalar($koneksi, "SELECT COUNT(*) FROM pendaftaran");
$cnt_ver_pending  = scalar($koneksi, "SELECT COUNT(*) FROM pendaftaran WHERE status_verifikasi='pending'");
$cnt_ver_terima   = scalar($koneksi, "SELECT COUNT(*) FROM pendaftaran WHERE status_verifikasi='diterima'");
$cnt_ver_tolak    = scalar($koneksi, "SELECT COUNT(*) FROM pendaftaran WHERE status_verifikasi='ditolak'");
$cnt_pen_belum    = scalar($koneksi, "SELECT COUNT(*) FROM pendaftaran WHERE status_penilaian='belum'");
$cnt_pen_dinilai  = scalar($koneksi, "SELECT COUNT(*) FROM pendaftaran WHERE status_penilaian='dinilai'");
$cnt_lulus        = scalar($koneksi, "SELECT COUNT(*) FROM pendaftaran WHERE status_kelulusan='lulus'");
$cnt_tidak        = scalar($koneksi, "SELECT COUNT(*) FROM pendaftaran WHERE status_kelulusan='tidak'");

$total_penilaian  = scalar($koneksi, "SELECT COUNT(*) FROM penilaian");
$avg_skor         = scalarf($koneksi, "SELECT AVG(NULLIF(skor,0)) FROM penilaian");
$avg_skor = $avg_skor ? round($avg_skor, 2) : 0;

$total_sertifikat = scalar($koneksi, "SELECT COUNT(*) FROM sertifikat");
$cnt_lvl_dasar    = scalar($koneksi, "SELECT COUNT(*) FROM sertifikat WHERE level_kompetensi='dasar'");
$cnt_lvl_menengah = scalar($koneksi, "SELECT COUNT(*) FROM sertifikat WHERE level_kompetensi='menengah'");
$cnt_lvl_ahli     = scalar($koneksi, "SELECT COUNT(*) FROM sertifikat WHERE level_kompetensi='ahli'");

$verif_today = scalar($koneksi, "SELECT COUNT(*) FROM verifikasi_qr WHERE waktu_verifikasi >= DATE_SUB(NOW(), INTERVAL 1 DAY)");
$verif_7d    = scalar($koneksi, "SELECT COUNT(*) FROM verifikasi_qr WHERE waktu_verifikasi >= DATE_SUB(NOW(), INTERVAL 7 DAY)");
$verif_prev7 = scalar($koneksi, "SELECT COUNT(*) FROM verifikasi_qr WHERE waktu_verifikasi >= DATE_SUB(NOW(), INTERVAL 14 DAY) AND waktu_verifikasi < DATE_SUB(NOW(), INTERVAL 7 DAY)");
$delta_verif_7d = ($verif_prev7 > 0) ? round((($verif_7d - $verif_prev7) / $verif_prev7) * 100, 1) : ($verif_7d > 0 ? 100 : 0);

// versi/uptime DB & PHP
$db_ver = 'MariaDB';
if ($v = assoc($koneksi, "SELECT VERSION() v")) $db_ver = 'MariaDB ' . ($v['v'] ?? '');
$php_ver = PHP_VERSION;
$uptime = '-';
if ($st = $koneksi->query("SHOW GLOBAL STATUS LIKE 'Uptime'")) {
    if ($row = $st->fetch_assoc()) {
        $sec = (int)($row['Value'] ?? 0);
        $uptime = $sec ? sprintf('%d jam %d mnt', floor($sec / 3600), floor(($sec % 3600) / 60)) : '-';
    }
}

// coverage dokumen
$den = max(1, $total_pendaftaran);
$covs = [
    'Foto Formal'        => round(scalarf($koneksi, "SELECT SUM(link_foto_formal IS NOT NULL AND link_foto_formal!='') FROM pendaftaran") / $den * 100),
    'Foto Transparan'    => round(scalarf($koneksi, "SELECT SUM(link_foto_transparan IS NOT NULL AND link_foto_transparan!='') FROM pendaftaran") / $den * 100),
    'CV'                 => round(scalarf($koneksi, "SELECT SUM(link_cv IS NOT NULL AND link_cv!='') FROM pendaftaran") / $den * 100),
    'Biodata'            => round(scalarf($koneksi, "SELECT SUM(link_biodata IS NOT NULL AND link_biodata!='') FROM pendaftaran") / $den * 100),
    'Dok. Perkuliahan'   => round(scalarf($koneksi, "SELECT SUM(link_dok_perkuliahan IS NOT NULL AND link_dok_perkuliahan!='') FROM pendaftaran") / $den * 100),
];
// coverage bank-soal per posisi
$total_posisi = scalar($koneksi, "SELECT COUNT(*) FROM posisi");
$posisi_covered = scalar($koneksi, "
  SELECT COUNT(*) FROM (
    SELECT p.id_posisi
    FROM posisi p
    LEFT JOIN bank_soal bs ON bs.id_posisi=p.id_posisi AND bs.is_aktif=1
    WHERE (p.link_soal_default IS NOT NULL AND p.link_soal_default!='') OR bs.id_bank_soal IS NOT NULL
    GROUP BY p.id_posisi
  ) x
");
$cov_bank_posisi = $total_posisi ? round($posisi_covered / $total_posisi * 100) : 0;

// score-ring derajat
$cnt_review = scalar($koneksi, "SELECT COUNT(*) FROM penilaian WHERE rekomendasi='di review'");
$cnt_layak  = scalar($koneksi, "SELECT COUNT(*) FROM penilaian WHERE rekomendasi='layak'");
$cnt_belum  = scalar($koneksi, "SELECT COUNT(*) FROM penilaian WHERE rekomendasi='belum layak'");
$deg = function ($part, $tot) {
    return (int)round(($tot > 0 ? ($part / $tot) : 0) * 360);
};
$deg_layak = $deg($cnt_layak, $total_penilaian);
$deg_belum = $deg($cnt_belum, $total_penilaian);
$deg_review = $deg($cnt_review, $total_penilaian);

// sparkline verifikasi 7 hari terakhir
$seriesDates = [];
$seriesCounts = [];
for ($i = 6; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-$i days"));
    $seriesDates[] = $d;
    $c = scalar($koneksi, "SELECT COUNT(*) FROM verifikasi_qr WHERE DATE(waktu_verifikasi)='$d'");
    $seriesCounts[] = $c;
}

// ================== DATA: ACTIVITY ==================
$q       = qs('q');
$user_id = (int)qs('user_id', 0);
$dari    = qs('dari');
$sampai  = qs('sampai');
$sort    = qs('sort', 'new'); // new|old|name
$page    = max(1, (int)qs('page', 1));
$pp      = 14;
$ofs     = ($page - 1) * $pp;

$conds = [];
if ($q !== '') {
    $qEsc = $koneksi->real_escape_string($q);
    $conds[] = "(l.aktivitas LIKE '%$qEsc%' OR u.nama_lengkap LIKE '%$qEsc%')";
}
if ($user_id > 0) {
    $conds[] = "l.id_pengguna=$user_id";
}
if ($dari !== '' && $sampai !== '') {
    $d1 = $koneksi->real_escape_string($dari);
    $d2 = $koneksi->real_escape_string($sampai);
    $conds[] = "(DATE(l.waktu) BETWEEN '$d1' AND '$d2')";
}
$where = $conds ? ('WHERE ' . implode(' AND ', $conds)) : '';
$order = "ORDER BY l.waktu DESC";
if ($sort === 'old') $order = "ORDER BY l.waktu ASC";
if ($sort === 'name') $order = "ORDER BY u.nama_lengkap ASC, l.waktu DESC";

$totalLog = scalar($koneksi, "SELECT COUNT(*) FROM log_aktivitas l LEFT JOIN pengguna u ON u.id_pengguna=l.id_pengguna $where");
$sqlLogs  = "
SELECT l.*, u.nama_lengkap, u.peran
FROM log_aktivitas l
LEFT JOIN pengguna u ON u.id_pengguna=l.id_pengguna
$where
$order
LIMIT $pp OFFSET $ofs";
$resLogs = $koneksi->query($sqlLogs);

// recent timeline (10 terakhir) untuk overview
$tl = $koneksi->query("
  SELECT l.*, u.nama_lengkap, u.peran
  FROM log_aktivitas l
  LEFT JOIN pengguna u ON u.id_pengguna=l.id_pengguna
  ORDER BY l.waktu DESC
  LIMIT 10
");

// dropdown user
$usersOpt = $koneksi->query("SELECT id_pengguna, nama_lengkap FROM pengguna ORDER BY nama_lengkap");

// ================== DATA: QR VERIFY LIST ==================
$qr_page = max(1, (int)qs('qr_page', 1));
$qr_pp   = 18;
$qr_ofs = ($qr_page - 1) * $qr_pp;

$qr_total = scalar($koneksi, "SELECT COUNT(*) FROM verifikasi_qr");
$resQR = $koneksi->query("
SELECT v.*, s.nomor_sertifikat, s.slug_sertifikat, s.level_kompetensi, s.tanggal_terbit,
       d.id_posisi, p.nama_posisi, pg.nama_lengkap
FROM verifikasi_qr v
LEFT JOIN sertifikat s ON s.kode_qr = v.kode_qr
LEFT JOIN pendaftaran d ON d.id_pendaftaran = s.id_pendaftaran
LEFT JOIN posisi p ON p.id_posisi = d.id_posisi
LEFT JOIN pengguna pg ON pg.id_pengguna = d.id_pengguna
ORDER BY v.waktu_verifikasi DESC
LIMIT $qr_pp OFFSET $qr_ofs
");

// ================== VIEW ==================
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="utf-8">
    <title>Log • Health • QR | RELIPROVE</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="css/style.css" rel="stylesheet">
    <link href="css/fitur_filter.css" rel="stylesheet">
    <link rel="icon" href="../aset/img/logo.png" type="image/png">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap" rel="stylesheet" />
    <style>
        /* ====== Peningkatan konsep high-tech v2 (tambahan ringan) ====== */
        .subnav {
            display: flex;
            gap: 8px;
            flex-wrap: wrap
        }

        .subnav .pill {
            cursor: pointer
        }

        .tab {
            display: none
        }

        .tab.active {
            display: block
        }

        .grid-3-1 {
            display: grid;
            gap: 14px;
            grid-template-columns: 2fr 1fr
        }

        @media(max-width:1100px) {
            .grid-3-1 {
                grid-template-columns: 1fr
            }
        }

        .vline {
            position: relative;
            padding-left: 18px
        }

        .vline::before {
            content: "";
            position: absolute;
            left: 8px;
            top: 0;
            bottom: 0;
            border-left: 1px dashed var(--border-light)
        }

        .vitem {
            position: relative;
            margin: 10px 0 14px 0;
            background: var(--bg-row);
            border: 1px solid var(--border-light);
            border-radius: 12px;
            padding: 10px 12px
        }

        .vitem::before {
            content: "";
            position: absolute;
            left: -2px;
            top: 16px;
            width: 10px;
            height: 10px;
            border-radius: 50%;
            background: var(--primary);
            box-shadow: 0 0 10px var(--primary)
        }

        .badge.soft {
            background: rgba(255, 255, 255, .05);
            border: 1px solid var(--border-light)
        }

        .dock {
            display: grid;
            gap: 12px;
            grid-template-columns: repeat(auto-fill, minmax(260px, 1fr))
        }

        .stat-mini {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px;
            border: 1px solid var(--border-light);
            border-radius: 12px;
            background: var(--bg-row)
        }

        .stat-mini .meter {
            width: 120px
        }

        .qr-grid {
            display: grid;
            gap: 12px;
            grid-template-columns: repeat(auto-fill, minmax(340px, 1fr))
        }

        .qr-card {
            display: grid;
            grid-template-columns: 1fr auto;
            gap: 8px;
            align-items: center;
            border: 1px solid var(--border-light);
            border-radius: 14px;
            background: var(--bg-row);
            padding: 12px
        }

        .qr-card.ok {
            box-shadow: 0 0 18px rgba(23, 177, 117, .08)
        }

        .qr-card.warn {
            box-shadow: 0 0 18px rgba(255, 187, 60, .08)
        }

        .qr-title {
            font-weight: 900;
            color: var(--text-light)
        }

        .qr-sub {
            color: var(--muted)
        }

        .qr-meta {
            display: flex;
            align-items: center;
            gap: 8px
        }

        .right-col {
            display: grid;
            gap: 14px
        }

        .pill-tab {
            padding: .32rem .7rem;
            border-radius: 999px;
            border: 1px solid var(--border-light);
            background: rgba(255, 255, 255, .04)
        }

        .pill-tab.active {
            border-color: rgba(155, 94, 255, .35);
            background: rgba(155, 94, 255, .12);
            color: var(--primary)
        }

        .toolbar {
            display: flex;
            gap: 8px;
            flex-wrap: wrap
        }

        .btn-ghost {
            border: 1px dashed var(--border-light);
            background: transparent
        }

        .export {
            display: inline-flex;
            gap: 8px;
            align-items: center
        }

        canvas {
            display: block;
            width: 100%;
            max-width: 100%;
            height: 70px
        }
    </style>
</head>

<body>

    <!-- SIDEBAR -->
    <aside class="sb" id="sidebar">
        <div class="sb__brand" style="font-size:35px;margin:15px 0 10px">RELIPROVE</div>
        <nav class="sb__nav">
            <a href="dashboard_admin.php"><i class="fa-solid fa-gauge"></i>Dashboard</a>
            <a href="pengguna.php"><i class="fa-solid fa-users"></i>Manajemen Pengguna</a>
            <a href="pendaftaran.php"><i class="fa-solid fa-id-card"></i>Pendaftaran</a>
            <a href="penilaian.php"><i class="fa-solid fa-clipboard-check"></i>Penilaian</a>
            <a href="bank_soal.php"><i class="fa-solid fa-book-open"></i>Bank Soal</a>
            <a href="sertifikat.php"><i class="fa-solid fa-graduation-cap"></i>Sertifikat</a>
            <a class="active" href="log_aktivitas.php"><i class="fa-solid fa-list"></i>Log • Health • QR</a>
            <a href="../logout.php"><i class="fa-solid fa-arrow-right-from-bracket"></i>Keluar</a>
        </nav>
    </aside>

    <!-- MAIN -->
    <main class="main">
        <!-- TOPBAR -->
        <header class="tb">
            <div class="tb__left">
                <button class="tb__burger" onclick="document.getElementById('sidebar').classList.toggle('open')"><i class="fa-solid fa-bars"></i></button>
                <h1 class="tb__title">System Ops Center</h1>
                <div class="subnav" style="margin-left:8px">
                    <a class="pill-tab active" data-tab="tab-overview"><i class="fa-solid fa-heart-pulse"></i>&nbsp;Overview</a>
                    <a class="pill-tab" data-tab="tab-activity"><i class="fa-solid fa-list-check"></i>&nbsp;Activity</a>
                    <a class="pill-tab" data-tab="tab-qr"><i class="fa-solid fa-qrcode"></i>&nbsp;QR Verify</a>
                </div>
            </div>
            <div class="tb__right">
                <div class="tb__me">
                    <img src="https://ui-avatars.com/api/?name=<?= urlencode($namaAdmin) ?>&background=9b5eff&color=fff&rounded=true&size=40" alt="ava">
                    <div>
                        <div class="me__name"><?= esc($namaAdmin) ?></div>
                        <div class="me__role"><?= esc($me['peran'] ?? '-') ?></div>
                    </div>
                </div>
            </div>
        </header>

        <!-- TAB: OVERVIEW -->
        <section class="tab active" id="tab-overview" style="margin-top:14px">
            <div class="grid kpi-strip">
                <!-- Users -->
                <div class="kpi">
                    <div class="kpi__icon"><i class="fa-solid fa-users"></i></div>
                    <div>
                        <div class="kpi__label">Pengguna</div>
                        <div class="kpi__value"><?= (int)$total_pengguna ?></div>
                        <div class="chips" style="margin-top:6px">
                            <span class="chip"><i>Peserta</i> <?= (int)$cnt_peserta ?></span>
                            <span class="chip"><i>Asesor</i> <?= (int)$cnt_asesor ?></span>
                            <span class="chip"><i>Admin+</i> <?= (int)$cnt_admin ?></span>
                        </div>
                    </div>
                    <div class="delta up">OK</div>
                </div>
                <!-- Pendaftaran -->
                <div class="kpi">
                    <div class="kpi__icon"><i class="fa-solid fa-id-card"></i></div>
                    <div>
                        <div class="kpi__label">Pendaftaran</div>
                        <div class="kpi__value"><?= (int)$total_pendaftaran ?></div>
                        <div class="chips" style="margin-top:6px">
                            <span class="chip"><i>Terima</i> <?= (int)$cnt_ver_terima ?></span>
                            <span class="chip"><i>Pending</i> <?= (int)$cnt_ver_pending ?></span>
                            <span class="chip"><i>Tolak</i> <?= (int)$cnt_ver_tolak ?></span>
                        </div>
                    </div>
                    <div class="delta <?= $cnt_ver_terima >= $cnt_ver_pending ? 'up' : 'down' ?>"><?= $cnt_ver_terima >= $cnt_ver_pending ? '+good' : '-watch' ?></div>
                </div>
                <!-- Penilaian -->
                <div class="kpi">
                    <div class="kpi__icon"><i class="fa-solid fa-clipboard-check"></i></div>
                    <div>
                        <div class="kpi__label">Penilaian</div>
                        <div class="kpi__value"><?= (int)$total_penilaian ?> <small class="muted" style="font-weight:700">(avg <?= esc($avg_skor) ?>)</small></div>
                        <div class="chips" style="margin-top:6px">
                            <span class="chip"><i>Layak</i> <?= (int)$cnt_layak ?></span>
                            <span class="chip"><i>Belum</i> <?= (int)$cnt_belum ?></span>
                            <span class="chip"><i>Review</i> <?= (int)$cnt_review ?></span>
                        </div>
                    </div>
                    <div class="delta <?= $cnt_layak >= $cnt_belum ? 'up' : 'down' ?>"><?= $cnt_layak >= $cnt_belum ? '+ok' : '-warn' ?></div>
                </div>
                <!-- Sertifikat / QR -->
                <div class="kpi">
                    <div class="kpi__icon"><i class="fa-solid fa-graduation-cap"></i></div>
                    <div>
                        <div class="kpi__label">Sertifikat</div>
                        <div class="kpi__value"><?= (int)$total_sertifikat ?></div>
                        <div class="chips" style="margin-top:6px">
                            <span class="chip"><i>Dasar</i> <?= (int)$cnt_lvl_dasar ?></span>
                            <span class="chip"><i>Menengah</i> <?= (int)$cnt_lvl_menengah ?></span>
                            <span class="chip"><i>Ahli</i> <?= (int)$cnt_lvl_ahli ?></span>
                            <span class="chip"><i>QR 7d</i> <?= (int)$verif_7d ?></span>
                        </div>
                    </div>
                    <div class="delta <?= $delta_verif_7d >= 0 ? 'up' : 'down' ?>"><?= ($delta_verif_7d >= 0 ? '+' : '') . esc($delta_verif_7d) ?>%</div>
                </div>
            </div>

            <div class="grid-3-1" style="margin-top:14px">
                <!-- Health & Coverage -->
                <div class="card card--health">
                    <div class="card__hdr card__hdr--xl">
                        <h6><i class="fa-solid fa-heart-pulse"></i>Kesehatan Sistem</h6>
                        <div class="tool">
                            <span class="pill">DB: <?= esc($db_ver) ?></span>
                            <span class="pill">PHP: <?= esc($php_ver) ?></span>
                            <span class="pill">Uptime DB: <?= esc($uptime) ?></span>
                        </div>
                    </div>

                    <div class="dock">
                        <div class="stat-mini">
                            <div class="score-ht ok" style="--deg: <?= (int)$deg_layak ?>deg;">
                                <div class="score-ht__ring"></div>
                                <div class="score-ht__meta">
                                    <div class="score-ht__val"><b><?= (int)$cnt_layak ?></b><span>layak</span></div>
                                    <div class="score-ht__label">stabil</div>
                                </div>
                            </div>
                            <div class="meter"><span class="meter__fill ok" style="width:<?= min(100, max(10, round(($cnt_layak / max(1, $total_penilaian)) * 100))) ?>%"></span></div>
                        </div>
                        <div class="stat-mini">
                            <div class="score-ht warn" style="--deg: <?= (int)$deg_belum ?>deg;">
                                <div class="score-ht__ring"></div>
                                <div class="score-ht__meta">
                                    <div class="score-ht__val"><b><?= (int)$cnt_belum ?></b><span>belum</span></div>
                                    <div class="score-ht__label">pantau</div>
                                </div>
                            </div>
                            <div class="meter"><span class="meter__fill warn" style="width:<?= min(100, max(10, round(($cnt_belum / max(1, $total_penilaian)) * 100))) ?>%"></span></div>
                        </div>
                        <div class="stat-mini">
                            <div class="score-ht muted" style="--deg: <?= (int)$deg_review ?>deg;">
                                <div class="score-ht__ring"></div>
                                <div class="score-ht__meta">
                                    <div class="score-ht__val"><b><?= (int)$cnt_review ?></b><span>review</span></div>
                                    <div class="score-ht__label">antri</div>
                                </div>
                            </div>
                            <div class="meter"><span class="meter__fill" style="width:<?= min(100, max(10, round(($cnt_review / max(1, $total_penilaian)) * 100))) ?>%"></span></div>
                        </div>
                    </div>

                    <div class="card cover-ht" style="margin-top:10px">
                        <div class="card__hdr">
                            <h6><i class="fa-solid fa-layer-group"></i>Coverage Data</h6>
                        </div>
                        <div class="cover-ht__chips">
                            <?php foreach ($covs as $label => $p): ?>
                                <div class="hchip" style="--w: <?= (int)$p ?>%"><b><?= esc($label) ?></b><em><?= (int)$p ?>%</em></div>
                            <?php endforeach; ?>
                            <div class="hchip" style="--w: <?= (int)$cov_bank_posisi ?>%"><b>Bank Soal/Posisi</b><em><?= (int)$cov_bank_posisi ?>%</em></div>
                        </div>
                        <div class="cover-ht__note">Semakin tinggi coverage, semakin sehat pipeline asesmen.</div>
                    </div>

                    <div class="card" style="margin-top:10px">
                        <div class="card__hdr">
                            <h6><i class="fa-solid fa-chart-line"></i>Traffic Verifikasi QR (7 hari)</h6>
                        </div>
                        <canvas id="sparkQR" width="600" height="90"></canvas>
                        <div class="chips" style="margin-top:6px">
                            <?php foreach ($seriesDates as $i => $d): ?>
                                <span class="badge soft"><?= date('d M', strtotime($d)) ?>: <?= (int)$seriesCounts[$i] ?></span>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

                <!-- Timeline kanan -->
                <div class="right-col">
                    <div class="card">
                        <div class="card__hdr card__hdr--xl">
                            <h6><i class="fa-solid fa-clock-rotate-left"></i>Live Timeline</h6>
                            <a class="btn btn--sm" href="#" onclick="switchTab('tab-activity');return false;"><i class="fa-solid fa-list"></i>Lihat Activity</a>
                        </div>
                        <div class="vline">
                            <?php if ($tl && $tl->num_rows): while ($t = $tl->fetch_assoc()):
                                    $nm = $t['nama_lengkap'] ?? 'Sistem';
                                    $pr = $t['peran'] ?? '-';
                                    $ak = $t['aktivitas'] ?? '(kosong)';
                                    $tm = $t['waktu'] ?? null;
                                    $ini = mb_strtoupper(mb_substr(trim($nm), 0, 1), 'UTF-8');
                            ?>
                                    <div class="vitem">
                                        <div class="txt">
                                            <div class="ttl" style="font-weight:900"><?= esc($ak) ?></div>
                                            <div class="sub"><?= esc($nm) ?> • <span class="badge primary"><?= esc($pr) ?></span></div>
                                        </div>
                                        <div class="row__right" style="margin-top:6px"><time><?= esc(dt($tm)) ?></time></div>
                                    </div>
                                <?php endwhile;
                            else: ?>
                                <div class="vitem">
                                    <div class="sub">Belum ada aktivitas.</div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="card">
                        <div class="card__hdr">
                            <h6><i class="fa-solid fa-info"></i>Mesin</h6>
                        </div>
                        <div class="sys-grid">
                            <div class="sys-kv"><span>DB</span><b><?= esc($db_ver) ?></b></div>
                            <div class="sys-kv"><span>PHP</span><b><?= esc($php_ver) ?></b></div>
                            <div class="sys-kv"><span>Uptime DB</span><b><?= esc($uptime) ?></b></div>
                            <div class="sys-kv"><span>QR 24h</span><b><?= (int)$verif_today ?></b></div>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- TAB: ACTIVITY -->
        <section class="tab" id="tab-activity" style="margin-top:14px">
            <div class="grid">
                <div class="card card--ht-filter">
                    <div class="filter__hdr">
                        <div class="filter__title"><i class="fa-solid fa-filter"></i>Filter Activity</div>
                        <div class="toolbar">
                            <a class="btn btn--sm btn-ghost export" href="javascript:void(0)" onclick="exportCSV('activity')"><i class="fa-solid fa-file-export"></i>Export CSV</a>
                            <span class="count-badge"><?= (int)$totalLog ?> item</span>
                        </div>
                    </div>
                    <form method="get" class="frow" action="log_aktivitas.php#tab-activity">
                        <input type="hidden" name="anchor" value="tab-activity">
                        <div class="fitem">
                            <label>Kata kunci</label>
                            <div class="field">
                                <div class="ficon"><i class="fa-solid fa-magnifying-glass"></i></div>
                                <input class="fsel" name="q" value="<?= esc($q) ?>" placeholder="tambah / ubah / hapus / sertifikat ...">
                                <div class="chev"><i class="fa-regular fa-keyboard"></i></div>
                            </div>
                        </div>
                        <div class="fitem">
                            <label>Pengguna</label>
                            <div class="field">
                                <div class="ficon"><i class="fa-solid fa-user"></i></div>
                                <select class="fsel" name="user_id">
                                    <option value="0">Semua</option>
                                    <?php if ($usersOpt): while ($u = $usersOpt->fetch_assoc()): ?>
                                            <option value="<?= (int)$u['id_pengguna'] ?>" <?= $user_id == (int)$u['id_pengguna'] ? 'selected' : '' ?>><?= esc($u['nama_lengkap']) ?></option>
                                    <?php endwhile;
                                    endif; ?>
                                </select>
                                <div class="chev"><i class="fa-solid fa-chevron-down"></i></div>
                            </div>
                        </div>
                        <div class="fitem">
                            <label>Dari</label>
                            <div class="field">
                                <div class="ficon"><i class="fa-regular fa-calendar"></i></div>
                                <input class="fsel" type="date" name="dari" value="<?= esc($dari) ?>">
                                <div class="chev"><i class="fa-solid fa-arrow-right-long"></i></div>
                            </div>
                        </div>
                        <div class="fitem">
                            <label>Sampai</label>
                            <div class="field">
                                <div class="ficon"><i class="fa-regular fa-calendar-check"></i></div>
                                <input class="fsel" type="date" name="sampai" value="<?= esc($sampai) ?>">
                                <div class="chev"><i class="fa-solid fa-calendar-day"></i></div>
                            </div>
                        </div>
                        <div class="fitem">
                            <label>Urutkan</label>
                            <div class="field">
                                <div class="ficon"><i class="fa-solid fa-sort"></i></div>
                                <select class="fsel" name="sort">
                                    <option value="new" <?= $sort === 'new' ? 'selected' : '' ?>>Terbaru</option>
                                    <option value="old" <?= $sort === 'old' ? 'selected' : '' ?>>Terlama</option>
                                    <option value="name" <?= $sort === 'name' ? 'selected' : '' ?>>Nama</option>
                                </select>
                                <div class="chev"><i class="fa-solid fa-chevron-down"></i></div>
                            </div>
                        </div>
                        <div class="factions" style="grid-column:1/-1">
                            <p class="hintline">Gunakan kata kerja <b>tambah</b>, <b>ubah</b>, <b>hapus</b>, <b>terbit</b> untuk hasil lebih relevan.</p>
                            <div class="actions">
                                <a class="btn btn--sm btn--outline" href="log_aktivitas.php#tab-activity"><i class="fa-solid fa-rotate"></i>Reset</a>
                                <button class="btn btn--sm btn--primary" type="submit"><i class="fa-solid fa-filter"></i>Terapkan</button>
                            </div>
                        </div>
                    </form>
                </div>

                <div class="card card--loose">
                    <div class="card__hdr card__hdr--xl">
                        <h6><i class="fa-solid fa-list-check"></i>Activity Stream</h6>
                        <span class="pill">Menampilkan <?= (int)min($pp, max(0, $totalLog - $ofs)) ?> dari <?= (int)$totalLog ?></span>
                    </div>
                    <ul class="relaxed-list">
                        <?php if ($resLogs && $resLogs->num_rows): while ($r = $resLogs->fetch_assoc()):
                                $nm = $r['nama_lengkap'] ?? 'Sistem';
                                $pr = $r['peran'] ?? '-';
                                $ak = $r['aktivitas'] ?? '(kosong)';
                                $tm = $r['waktu'] ?? null;
                                $ini = mb_strtoupper(mb_substr(trim($nm), 0, 1), 'UTF-8');
                        ?>
                                <li class="row">
                                    <div class="row__left">
                                        <div class="avatar"><?= esc($ini) ?></div>
                                        <div class="txt">
                                            <div class="ttl"><?= esc($ak) ?></div>
                                            <div class="sub"><?= esc($nm) ?> • <span class="badge primary"><?= esc($pr) ?></span></div>
                                        </div>
                                    </div>
                                    <div class="row__right">
                                        <a class="pill-link" href="javascript:void(0)" onclick="openDetail('<?= esc(addslashes($ak)) ?>','<?= esc(addslashes($nm)) ?>','<?= esc($pr) ?>','<?= esc(dt($tm)) ?>')"><i class="fa-regular fa-eye"></i> Detail</a>
                                        <time><?= esc(dt($tm)) ?></time>
                                    </div>
                                </li>
                            <?php endwhile;
                        else: ?>
                            <li class="row row--empty">Tidak ada data berdasarkan filter.</li>
                        <?php endif; ?>
                    </ul>
                    <?php
                    $params = $_GET;
                    unset($params['page']);
                    $base = 'log_aktivitas.php';
                    if (!empty($params)) $base .= '?' . http_build_query($params);
                    echo pagelinks($totalLog, $page, $pp, $base . '#tab-activity');
                    ?>
                </div>
            </div>
        </section>

        <!-- TAB: QR VERIFY -->
        <section class="tab" id="tab-qr" style="margin-top:14px">
            <div class="grid">
                <div class="card">
                    <div class="card__hdr card__hdr--xl">
                        <h6><i class="fa-solid fa-qrcode"></i>Verifikasi QR</h6>
                        <div class="tool">
                            <span class="pill">7d: <?= (int)$verif_7d ?></span>
                            <span class="pill <?= $delta_verif_7d >= 0 ? 'ok' : 'warn' ?>"><?= ($delta_verif_7d >= 0 ? '+' : '') . esc($delta_verif_7d) ?>%</span>
                            <a class="btn btn--sm btn-ghost" href="javascript:void(0)" onclick="exportCSV('qr')"><i class="fa-solid fa-file-export"></i>Export CSV</a>
                        </div>
                    </div>

                    <div class="qr-grid">
                        <?php if ($resQR && $resQR->num_rows): while ($qr = $resQR->fetch_assoc()):
                                $recognized = !empty($qr['nomor_sertifikat']);
                                $lvl = $qr['level_kompetensi'] ?? '-';
                                $who = $qr['nama_lengkap'] ?? 'Unknown';
                                $pos = $qr['nama_posisi'] ?? '-';
                                $code = $qr['kode_qr'] ?? '';
                                $slug = $qr['slug_sertifikat'] ?? '';
                                $link = $slug ? ('../storage/sertifikat/' . esc($slug) . '.html') : '#';
                        ?>
                                <div class="qr-card <?= $recognized ? 'ok' : 'warn' ?>">
                                    <div>
                                        <div class="qr-title"><?= esc($recognized ? ($qr['nomor_sertifikat'] . ' • ' . $who) : $code) ?></div>
                                        <div class="qr-sub"><?= esc($recognized ? $pos : 'Kode belum terhubung ke sertifikat') ?></div>
                                        <div class="qr-meta" style="margin-top:6px">
                                            <span class="status <?= $recognized ? 'ok' : 'warn' ?>"><?= esc($recognized ? $lvl : 'unknown') ?></span>
                                            <div class="bar"><span class="bar__fill <?= $recognized ? 'ok' : 'warn' ?>" style="width:<?= $recognized ? '88' : '34' ?>%"></span><em><?= esc($recognized ? 'valid' : '-') ?></em></div>
                                        </div>
                                    </div>
                                    <div style="display:grid;gap:8px;justify-items:end">
                                        <time><?= esc(dt($qr['waktu_verifikasi'])) ?></time>
                                        <?php if ($recognized): ?>
                                            <a class="pill-link" target="_blank" rel="noopener" href="<?= esc($link) ?>"><i class="fa-regular fa-file-lines"></i> Sertifikat</a>
                                        <?php else: ?>
                                            <span class="badge warn">tidak dikenal</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endwhile;
                        else: ?>
                            <div class="cov-empty">Belum ada verifikasi.</div>
                        <?php endif; ?>
                    </div>

                    <?php
                    // pagination QR
                    $qr_params = $_GET;
                    unset($qr_params['qr_page']);
                    $qr_base = 'log_aktivitas.php';
                    if (!empty($qr_params)) $qr_base .= '?' . http_build_query($qr_params);
                    echo pagelinks($qr_total, $qr_page, $qr_pp, $qr_base . '#tab-qr');
                    ?>
                </div>
            </div>
        </section>

        <footer class="ft">© <?= date('Y') ?> RELIPROVE • Ops Center</footer>
    </main>

    <!-- Modal Detail -->
    <div class="modal" id="modalDetail">
        <div class="sheet">
            <div class="card__hdr">
                <h6><i class="fa-regular fa-eye"></i>Detail Aktivitas</h6>
                <button class="close-x" onclick="closeDetail()"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <div class="sheet__body">
                <div class="panel">
                    <div class="kv">
                        <div class="k">Aktivitas</div>
                        <div class="v" id="md-aksi">-</div>
                    </div>
                    <div class="kv">
                        <div class="k">Pengguna</div>
                        <div class="v" id="md-user">-</div>
                    </div>
                    <div class="kv">
                        <div class="k">Peran</div>
                        <div class="v" id="md-role">-</div>
                    </div>
                    <div class="kv">
                        <div class="k">Waktu</div>
                        <div class="v" id="md-time">-</div>
                    </div>
                </div>
                <div class="panel">
                    <div class="kv">
                        <div class="k">Status</div>
                        <div class="v"><span class="badge primary">OK</span></div>
                    </div>
                    <div class="kv">
                        <div class="k">Catatan</div>
                        <div class="v">Tidak ada catatan tambahan.</div>
                    </div>
                    <div class="kv">
                        <div class="k">Aksi Lanjut</div>
                        <div class="v"><a class="pill-link" href="javascript:void(0)"><i class="fa-solid fa-share-from-square"></i> Salin</a></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        // ====== Tab Switcher ======
        const tabs = document.querySelectorAll('.pill-tab');
        tabs.forEach(t => t.addEventListener('click', () => switchTab(t.dataset.tab)));

        function switchTab(id) {
            document.querySelectorAll('.pill-tab').forEach(p => p.classList.remove('active'));
            document.querySelectorAll('.tab').forEach(x => x.classList.remove('active'));
            document.querySelector(`.pill-tab[data-tab="${id}"]`)?.classList.add('active');
            document.getElementById(id)?.classList.add('active');
            // persist hash
            history.replaceState(null, null, '#' + id);
        }
        // restore from hash
        if (location.hash) {
            const h = location.hash.replace('#', '');
            if (document.getElementById(h)) switchTab(h);
        }

        // ====== Modal Detail ======
        function openDetail(aksi, user, role, time) {
            document.getElementById('md-aksi').textContent = aksi;
            document.getElementById('md-user').textContent = user;
            document.getElementById('md-role').textContent = role;
            document.getElementById('md-time').textContent = time;
            document.getElementById('modalDetail').classList.add('open');
        }

        function closeDetail() {
            document.getElementById('modalDetail').classList.remove('open');
        }

        // ====== Sparkline (canvas, tanpa lib, no color set in CSS guideline) ======
        (function() {
            const el = document.getElementById('sparkQR');
            if (!el) return;
            const ctx = el.getContext('2d');
            const data = <?= json_encode($seriesCounts) ?>;
            const pad = 8;
            const w = el.width,
                h = el.height;
            const max = Math.max(1, ...data);
            // bg grid faint
            ctx.globalAlpha = 0.15;
            ctx.strokeStyle = '#fff';
            for (let i = 0; i <= 3; i++) {
                const y = pad + (i * (h - 2 * pad) / 3);
                ctx.beginPath();
                ctx.moveTo(pad, y);
                ctx.lineTo(w - pad, y);
                ctx.stroke();
            }
            ctx.globalAlpha = 1;
            // line
            ctx.lineWidth = 2;
            ctx.strokeStyle = '#eaeaf1';
            ctx.beginPath();
            data.forEach((v, i) => {
                const x = pad + i * ((w - 2 * pad) / (data.length - 1));
                const y = h - pad - (v / max) * (h - 2 * pad);
                if (i === 0) ctx.moveTo(x, y);
                else ctx.lineTo(x, y);
                // glow dot
                ctx.fillStyle = '#eaeaf1';
                ctx.beginPath();
                ctx.arc(x, y, 2.2, 0, Math.PI * 2);
                ctx.fill();
            });
            ctx.stroke();
        })();

        // ====== Export CSV (ringan) ======
        function exportCSV(which) {
            let url = 'export_csv.php?type=' + encodeURIComponent(which) + '&ts=' + (Date.now());
            // jika tidak ada endpoint, buat data URL kosong
            try {
                window.open(url, '_blank');
            } catch (e) {
                alert('Siapkan export_csv.php untuk ekspor.');
            }
        }
    </script>
</body>

</html>