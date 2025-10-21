<?php
if (session_status() === PHP_SESSION_NONE) session_start();

/* ===== Guard session (format lama/baru) ===== */
$userArr = $_SESSION['user'] ?? null;
if (!$userArr && isset($_SESSION['peran'])) {
    $userArr = [
        'id_pengguna'  => $_SESSION['id_pengguna'] ?? null,
        'nama_lengkap' => $_SESSION['nama_lengkap'] ?? null,
        'peran'        => $_SESSION['peran'] ?? null,
    ];
}
if (!$userArr) {
    header('Location: ../login.php');
    exit;
}
if (!in_array($userArr['peran'] ?? '', ['admin', 'superadmin'], true)) {
    header('HTTP/1.1 403 Forbidden');
    echo 'Akses ditolak.';
    exit;
}
$me = $userArr;

/* ===== Koneksi DB ===== */
require_once __DIR__ . '/../config/koneksi.php';
$isPDO   = isset($pdo)  && $pdo instanceof PDO;
$isMySQL = isset($conn) && $conn instanceof mysqli;
if (!$isPDO && !$isMySQL) {
    http_response_code(500);
    echo "config/koneksi.php harus definisikan \$pdo (PDO) atau \$conn (MySQLi).";
    exit;
}

/* ===== Helpers ===== */
function esc($s)
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}
function db_scalar($sql)
{
    global $isPDO, $pdo, $isMySQL, $conn;
    if ($isPDO) {
        $q = $pdo->query($sql);
        return (int)($q ? $q->fetchColumn() : 0);
    }
    $r = $conn->query($sql);
    if (!$r) return 0;
    $row = $r->fetch_row();
    return (int)($row[0] ?? 0);
}
function db_one($sql)
{
    global $isPDO, $pdo, $isMySQL, $conn;
    if ($isPDO) {
        $q = $pdo->query($sql);
        return $q ? $q->fetch(PDO::FETCH_ASSOC) : null;
    }
    $r = $conn->query($sql);
    return $r ? $r->fetch_assoc() : null;
}
function db_all($sql)
{
    global $isPDO, $pdo, $isMySQL, $conn;
    if ($isPDO) {
        $q = $pdo->query($sql);
        return $q ? $q->fetchAll(PDO::FETCH_ASSOC) : [];
    }
    $r = $conn->query($sql);
    if (!$r) return [];
    $o = [];
    while ($x = $r->fetch_assoc()) $o[] = $x;
    return $o;
}

/* ===== KPI ===== */
$stats = [
    'peserta'     => db_scalar("SELECT COUNT(*) FROM pengguna WHERE peran='peserta'"),
    'asesor'      => db_scalar("SELECT COUNT(*) FROM pengguna WHERE peran='asesor'"),
    'pendaftaran' => db_scalar("SELECT COUNT(*) FROM pendaftaran"),
    'sertifikat'  => db_scalar("SELECT COUNT(*) FROM sertifikat"),
];

/* ===== Tren 7 hari (pendaftaran) + growth ===== */
$trend = db_all("
  SELECT DATE(tanggal_daftar) d, COUNT(*) c
  FROM pendaftaran
  WHERE tanggal_daftar >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
  GROUP BY DATE(tanggal_daftar) ORDER BY d ASC
");
$labels = [];
$counts = [];
for ($i = 6; $i >= 0; $i--) {
    $d = (new DateTime('today'))->modify("-{$i} day")->format('Y-m-d');
    $labels[] = $d;
    $v = 0;
    foreach ($trend as $r) {
        if (($r['d'] ?? '') === $d) {
            $v = (int)$r['c'];
            break;
        }
    }
    $counts[] = $v;
}
$last7 = array_sum($counts);
$prevRows = db_all("
  SELECT DATE(tanggal_daftar) d, COUNT(*) c
  FROM pendaftaran
  WHERE tanggal_daftar < DATE_SUB(CURDATE(), INTERVAL 6 DAY)
    AND tanggal_daftar >= DATE_SUB(CURDATE(), INTERVAL 13 DAY)
  GROUP BY DATE(tanggal_daftar)
");
$prev7 = 0;
for ($i = 13; $i >= 7; $i--) {
    $d = (new DateTime('today'))->modify("-{$i} day")->format('Y-m-d');
    foreach ($prevRows as $r) {
        if (($r['d'] ?? '') === $d) {
            $prev7 += (int)$r['c'];
            break;
        }
    }
}
$growth = $prev7 > 0 ? round((($last7 - $prev7) / $prev7) * 100) : ($last7 > 0 ? 100 : 0);

/* ===== Breakdown status (horizontal bar, bukan donut) ===== */
$verif = [
    'pending'  => db_scalar("SELECT COUNT(*) FROM pendaftaran WHERE status_verifikasi='pending'"),
    'diterima' => db_scalar("SELECT COUNT(*) FROM pendaftaran WHERE status_verifikasi='diterima'"),
    'ditolak'  => db_scalar("SELECT COUNT(*) FROM pendaftaran WHERE status_verifikasi='ditolak'"),
];
$nilai = [
    'belum'   => db_scalar("SELECT COUNT(*) FROM pendaftaran WHERE status_penilaian='belum'"),
    'dinilai' => db_scalar("SELECT COUNT(*) FROM pendaftaran WHERE status_penilaian='dinilai'"),
];
$lulus = [
    'belum' => db_scalar("SELECT COUNT(*) FROM pendaftaran WHERE status_kelulusan='belum'"),
    'lulus' => db_scalar("SELECT COUNT(*) FROM pendaftaran WHERE status_kelulusan='lulus'"),
    'tidak' => db_scalar("SELECT COUNT(*) FROM pendaftaran WHERE status_kelulusan='tidak'"),
];

/* ===== Coverage bidang (chips) ===== */
$coverage = db_all("
  SELECT b.nama_bidang, COUNT(bs.id_bank_soal) jml
  FROM bidang b
  LEFT JOIN bank_soal bs ON bs.id_bidang=b.id_bidang AND bs.is_aktif=1
  GROUP BY b.id_bidang, b.nama_bidang
  ORDER BY jml DESC, b.nama_bidang ASC
");

/* ===== Top posisi 30 hari (bar) ===== */
$topPos = db_all("
  SELECT ps.nama_posisi, COUNT(*) jml
  FROM pendaftaran p
  JOIN posisi ps ON ps.id_posisi=p.id_posisi
  WHERE p.tanggal_daftar >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
  GROUP BY ps.id_posisi, ps.nama_posisi
  ORDER BY jml DESC, ps.nama_posisi ASC
  LIMIT 6
");

/* ===== Lists ===== */
$siapDinilai = db_all("
  SELECT
    pda.id_pendaftaran,
    pg.nama_lengkap, pg.email, pg.pendidikan, pg.asal_peserta,
    ps.nama_posisi,
    k.nama_kategori, b.nama_bidang,
    pda.tanggal_daftar
  FROM pendaftaran pda
  JOIN pengguna pg ON pg.id_pengguna = pda.id_pengguna
  JOIN posisi   ps ON ps.id_posisi   = pda.id_posisi
  JOIN kategori k  ON k.id_kategori  = ps.id_kategori
  JOIN bidang   b  ON b.id_bidang    = k.id_bidang
  WHERE pda.status_verifikasi='diterima' AND pda.status_penilaian='belum'
  ORDER BY pda.tanggal_daftar DESC
  LIMIT 4
");
$penTerbaru = db_all("
  SELECT pg.nama_lengkap, ps.nama_posisi, pen.skor, pen.rekomendasi, pen.tanggal_dinilai
  FROM penilaian pen
  JOIN pendaftaran pda ON pda.id_pendaftaran=pen.id_pendaftaran
  JOIN pengguna pg ON pg.id_pengguna=pda.id_pengguna
  JOIN posisi ps   ON ps.id_posisi  =pda.id_posisi
  WHERE pen.tanggal_dinilai IS NOT NULL
  ORDER BY pen.tanggal_dinilai DESC LIMIT 4
");
$sertTerbaru = db_all("
  SELECT s.nomor_sertifikat, s.level_kompetensi, s.tanggal_terbit, s.link_file_sertifikat,
         pg.nama_lengkap, ps.nama_posisi
  FROM sertifikat s
  JOIN pendaftaran pda ON pda.id_pendaftaran=s.id_pendaftaran
  JOIN pengguna pg     ON pg.id_pengguna=pda.id_pengguna
  JOIN posisi ps       ON ps.id_posisi  =pda.id_posisi
  ORDER BY s.tanggal_terbit DESC, s.id_sertifikat DESC LIMIT 3
");
$notifs = db_all("SELECT judul, waktu FROM notifikasi ORDER BY waktu DESC LIMIT 5");

/* ===== QR 7 hari (sparkline) ===== */
$qr7 = db_all("
  SELECT DATE(waktu_verifikasi) d, COUNT(*) c
  FROM verifikasi_qr
  WHERE waktu_verifikasi >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
  GROUP BY DATE(waktu_verifikasi) ORDER BY d ASC
");
$qLabels = [];
$qCounts = [];
for ($i = 6; $i >= 0; $i--) {
    $d = (new DateTime('today'))->modify("-{$i} day")->format('Y-m-d');
    $qLabels[] = $d;
    $v = 0;
    foreach ($qr7 as $r) {
        if (($r['d'] ?? '') === $d) {
            $v = (int)$r['c'];
            break;
        }
    }
    $qCounts[] = $v;
}
$qSum = array_sum($qCounts);

/* ===== System info ===== */
$phpVersion = PHP_VERSION;
if ($isPDO) {
    $dbVersion = (string)db_one("SELECT VERSION() AS v")['v'];
} else {
    $v = $conn->query("SELECT VERSION() AS v");
    $dbVersion = $v ? ($v->fetch_assoc()['v'] ?? '') : '';
}
$namaAdmin = $me['nama_lengkap'] ?? 'Admin RELIPROVE';
?>
<!doctype html>
<html lang="id">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Dashboard Admin | RELIPROVE</title>
    <link rel="icon" href="../aset/img/logo.png" type="image/png">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" rel="stylesheet" />
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap" rel="stylesheet" />
    <link href="css/style.css" rel="stylesheet" />
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
</head>

<body>

    <!-- SIDEBAR -->
    <aside class="sb" id="sidebar">
        <div class="sb__brand" style="font-size: 35px; margin-top: 15px; margin-bottom: 10px;">RELIPROVE</div>
        <nav class="sb__nav">
            <a class="active" href="dashboard_admin.php"><i class="fa-solid fa-gauge"></i>Dashboard</a>
            <a href="pengguna.php"><i class="fa-solid fa-users"></i>Manajemen Pengguna</a>
            <a href="pendaftaran.php"><i class="fa-solid fa-id-card"></i>Pendaftaran</a>
            <a href="penilaian.php"><i class="fa-solid fa-clipboard-check"></i>Penilaian</a>
            <a href="bank_soal.php"><i class="fa-solid fa-book-open"></i>Bank Soal</a>
            <a href="sertifikat.php"><i class="fa-solid fa-graduation-cap"></i>Sertifikat</a>
            <!-- <a href="notifikasi.php"><i class="fa-solid fa-bell"></i>Notifikasi</a> -->
            <a href="log_aktivitas.php"><i class="fa-solid fa-list"></i>Log Aktivitas</a>
            <!-- <a href="template_sertifikat.php"><i class="fa-solid fa-file"></i>Template Sertifikat</a> -->
            <a href="../logout.php"><i class="fa-solid fa-arrow-right-from-bracket"></i>Keluar</a>
        </nav>
    </aside>

    <!-- MAIN -->
    <main class="main">
        <!-- TOPBAR -->
        <header class="tb">
            <div class="tb__left">
                <button class="tb__burger" onclick="document.getElementById('sidebar').classList.toggle('open')"><i class="fa-solid fa-bars"></i></button>
                <h1 class="tb__title">Dashboard Admin</h1>
                <div class="tb__search">
                    <i class="fa-solid fa-magnifying-glass"></i>
                    <input type="text" placeholder="Cari cepat… (nama, posisi, sertifikat)">
                </div>
            </div>
            <div class="tb__right">
                <button class="btn" onclick="location.href='penilaian.php'"><i class="fa-regular fa-clipboard"></i>Kelola Penilaian</button>
                <button class="btn" onclick="location.href='sertifikat.php'"><i class="fa-regular fa-file-lines"></i>Kelola Sertifikat</button>
                <div class="tb__me">
                    <img src="https://ui-avatars.com/api/?name=<?= urlencode($namaAdmin) ?>&background=9b5eff&color=fff&rounded=true&size=40" alt="ava">
                    <div>
                        <div class="me__name"><?= esc($namaAdmin) ?></div>
                        <div class="me__role"><?= esc($me['peran']) ?></div>
                    </div>
                </div>
            </div>
        </header>
        <br>
        <!-- KPI STRIP (dengan sparkline mini) -->
        <section class="grid kpi-strip">
            <div class="kpi">
                <div class="kpi__icon"><i class="fa-solid fa-user-graduate"></i></div>
                <div class="kpi__meta">
                    <div class="kpi__label">Jumlah Peserta</div>
                    <div class="kpi__value" style="margin-top: 10px; font-size:20px;"><?= $stats['peserta'] ?> - ORANG</div>
                </div>
                <canvas class="spark" id="sp1" height="34"></canvas>
            </div>
            <div class="kpi">
                <div class="kpi__icon"><i class="fa-solid fa-user-tie"></i></div>
                <div class="kpi__meta">
                    <div class="kpi__label">Jumlah Asesor</div>
                    <div class="kpi__value" style="margin-top: 10px; font-size:20px;"><?= $stats['asesor'] ?> - ORANG</div>
                </div>
                <canvas class="spark" id="sp2" height="34"></canvas>
            </div>
            <div class="kpi">
                <div class="kpi__icon"><i class="fa-solid fa-id-card"></i></div>
                <div class="kpi__meta">
                    <div class="kpi__label">Jumlah Pendaftar</div>
                    <div class="kpi__value" style="margin-top: 10px; font-size:20px;"><?= $stats['pendaftaran'] ?> - ORANG</div>
                </div>
                <span class="delta <?= $growth >= 0 ? 'up' : 'down' ?>"><?= $growth >= 0 ? '+' . $growth : $growth ?>%</span>
                <canvas class="spark" id="sp3" height="34"></canvas>
            </div>
            <div class="kpi">
                <div class="kpi__icon"><i class="fa-solid fa-graduation-cap"></i></div>
                <div class="kpi__meta">
                    <div class="kpi__label">Jumlah Sertifikat</div>
                    <div class="kpi__value" style="margin-top: 10px; font-size:20px;"><?= $stats['sertifikat'] ?> - SERTIFIKAT</div>
                </div>
                <canvas class="spark" id="sp4" height="34"></canvas>
            </div>
        </section>
        <br>
        <!-- ROW: TREN + TOP POSISI -->
        <section class="grid grid-2">
            <div class="card">
                <div class="card__hdr card__hdr--xl">
                    <h6><i class="fa-solid fa-chart-line"></i>Tren Pendaftaran</h6>
                    <div class="tool"><span class="pill">Total 7d: <?= $last7 ?></span></div>
                </div>
                <canvas id="ch_trend" height="120"></canvas>
            </div>

            <div class="card">
                <div class="card__hdr card__hdr--xl">
                    <h6><i class="fa-solid fa-ranking-star"></i>Top Posisi</h6>
                    <div class="tool"><span class="pill">Pendaftaran</span></div>
                </div>
                <canvas id="ch_toppos" height="120"></canvas>
            </div>
        </section>
        <br>
        <!-- ROW: BREAKDOWN STATUS (bars horizontal), COVERAGE -->
        <section class="grid grid-2">
            <div class="card">
                <div class="card__hdr card__hdr--xl">
                    <h6><i class="fa-solid fa-sliders"></i>Breakdown Status</h6>
                </div>
                <div class="grid grid-3 mini-panels">
                    <div>
                        <div class="mini__title">Verifikasi</div>
                        <canvas id="bar_verif" height="110"></canvas>
                    </div>
                    <div>
                        <div class="mini__title">Penilaian</div>
                        <canvas id="bar_nilai" height="110"></canvas>
                    </div>
                    <div>
                        <div class="mini__title">Kelulusan</div>
                        <canvas id="bar_lulus" height="110"></canvas>
                    </div>
                </div>
            </div>
            <?php
            // ----- KPI utk Coverage -----
            $covMax = 0;
            $covTotal = 0;
            $topName = '-';
            $topVal = 0;
            foreach ($coverage as $r) {
                $v = (int)$r['jml'];
                $covTotal += $v;
                if ($v > $covMax) $covMax = $v;
                if ($v > $topVal) {
                    $topVal = $v;
                    $topName = $r['nama_bidang'];
                }
            }
            // siapkan list penjelasan
            $covExplain = [];
            if ($covTotal > 0) {
                foreach ($coverage as $r) {
                    $v = (int)$r['jml'];
                    $p = round($v / $covTotal * 100, 1);
                    $covExplain[] = ['nama' => $r['nama_bidang'], 'val' => $v, 'pct' => $p];
                }
                // urutkan desc
                usort($covExplain, fn($a, $b) => $b['val'] <=> $a['val']);
            }
            ?>

            <!-- ===== Coverage: Full Width High-Tech ===== -->
            <div class="card cover-wide">
                <div class="card__hdr card__hdr--xl">
                    <h6><i class="fa-solid fa-layer-group"></i>Coverage Bank Soal</h6>
                    <a class="btn btn--sm" href="bank_soal.php">Lihat Semua</a>
                </div>
                <!-- Grid tile per-bidang -->
                <div class="cov-grid">
                    <?php if (empty($coverage)): ?>
                        <div class="cov-empty">Belum ada data coverage.</div>
                        <?php else: foreach ($coverage as $c):
                            $val = (int)$c['jml'];
                            $w   = $covMax ? round($val / $covMax * 100) : 0;
                            $pct = $covTotal ? round($val / $covTotal * 100, 1) : 0;
                        ?>
                            <article class="cov-tile" style="--w:<?= $w ?>%">
                                <div class="tile__head">
                                    <div class="tile__name"><?= esc($c['nama_bidang']) ?></div>
                                    <div class="tile__count"><?= $val ?></div>
                                </div>
                                <div class="cov-bar" style="margin-top: 11px;"><span class="cov-bar__fill"></span></div>
                                <div class="tile__meta" style="margin-top: 10px; margin-bottom: 10px;">
                                    <span class="pill small" style="font-size: 10px;">Share : <b><?= $pct ?>%</b></span>
                                    <span class="hint">Proporsi terhadap total aktif</span>
                                </div>
                            </article>
                    <?php endforeach;
                    endif; ?>
                </div>

            </div>

        </section>
        <br>
        <!-- ROW: LISTS -->
        <!-- LISTS — layout baru (lega & presisi) -->
        <section class="deck deck-lists">
            <!-- Siap Dinilai (area besar kiri) -->
            <div class="card card--loose panel--siap">
                <div class="card__hdr card__hdr--xl">
                    <h6><i class="fa-solid fa-clipboard-list"></i>Siap Dinilai</h6>
                    <a class="btn btn--sm" href="pendaftaran.php">Semua</a>
                </div>

                <div class="assess-grid">
                    <?php if (!$siapDinilai): ?>
                        <div class="assess-empty">Tidak ada antrean.</div>
                        <?php else: foreach ($siapDinilai as $r):
                            $dt = !empty($r['tanggal_daftar']) ? new DateTime($r['tanggal_daftar']) : null;
                            $days = $dt ? $dt->diff(new DateTime())->days : 0;
                            $pct  = min(100, round(($days / 14) * 100)); // progress 0–14 hari
                            $tone = $days >= 7 ? 'warn' : 'ok';
                            $id   = (int)($r['id_pendaftaran'] ?? 0);
                        ?>
                            <article class="assess">
                                <header class="assess__head">
                                    <div class="avatar"><?= strtoupper(substr($r['nama_lengkap'], 0, 1)) ?></div>
                                    <div class="head__text">
                                        <div class="assess__name"><?= esc($r['nama_lengkap']) ?></div>
                                        <div class="assess__pos"><?= esc($r['nama_posisi']) ?></div>
                                    </div>
                                    <div class="head__tags">
                                        <span class="pill small"><?= esc($r['nama_kategori'] ?? '-') ?></span>
                                        <span class="pill small"><?= esc($r['nama_bidang'] ?? '-') ?></span>
                                    </div>
                                </header>

                                <div class="assess__meta">
                                    <span class="meta"><i class="fa-regular fa-clock"></i>
                                        <time><?= esc($dt ? $dt->format('d M Y · H:i') : '-') ?></time>
                                    </span>
                                    <span class="meta"><i class="fa-regular fa-user"></i><?= esc($r['asal_peserta'] ?? '-') ?></span>
                                    <?php if (!empty($r['pendidikan'])): ?>
                                        <span class="meta"><i class="fa-solid fa-graduation-cap"></i><?= esc($r['pendidikan']) ?></span>
                                    <?php endif; ?>
                                </div>

                                <div class="assess__meter">
                                    <div class="meter" style="margin-top: 6px;"><span class="meter__fill <?= $tone ?>" style="width:<?= $pct ?>%"></span></div>
                                    <div class="meter__info" style="margin-top: 12px;">
                                        <span class="age <?= $tone ?>"><?= $days ?> hari menunggu</span>
                                        <span class="hint">Target penilaian ≤ 14 hari</span>
                                    </div>
                                </div>

                                <footer class="assess__foot">
                                    <div class="foot__left">
                                        <?php if (!empty($r['email'])): ?>
                                            <a class="btn btn--ghost" href="mailto:<?= esc($r['email']) ?>"><i class="fa-regular fa-envelope"></i>Hubungi</a>
                                        <?php endif; ?>
                                    </div>
                                    <div class="foot__right">
                                        <a class="btn btn--outline" href="pendaftaran.php?id=<?= $id ?>"><i class="fa-regular fa-eye"></i>Detail</a>
                                        <a class="btn btn--primary" href="penilaian.php?id=<?= $id ?>"><i class="fa-solid fa-pen-to-square"></i>Kelola Penilaian</a>
                                    </div>
                                </footer>
                            </article>
                    <?php endforeach;
                    endif; ?>
                </div>

            </div>

            <!-- Penilaian Terbaru (kanan atas) -->
            <div class="card card--loose panel--nilai">
                <div class="card__hdr card__hdr--xl">
                    <h6><i class="fa-solid fa-clipboard-check"></i>Penilaian Terbaru</h6>
                    <a class="btn btn--sm" href="penilaian.php">Semua</a>
                </div>

                <ul class="relaxed-list">
                    <?php if (!$penTerbaru): ?>
                        <li class="row row--empty">Kosong.</li>
                        <?php else: foreach ($penTerbaru as $p):
                            $score = (int)$p['skor'];
                            $pct   = max(0, min(100, $score * 10));
                            $tone  = $score >= 7 ? 'ok' : ($score >= 1 ? 'warn' : 'muted');
                        ?>
                            <li class="row">
                                <div class="row__left">
                                    <div class="avatar score <?= $tone ?>"><?= $score ?></div>
                                    <div class="txt">
                                        <div class="ttl"><?= esc($p['nama_lengkap']) ?></div>
                                        <div class="sub"><?= esc($p['nama_posisi']) ?></div>
                                    </div>
                                </div>
                                <div class="row__right">
                                    <span class="status <?= $tone ?>"><?= esc($p['rekomendasi']) ?></span>
                                </div>
                            </li>
                    <?php endforeach;
                    endif; ?>
                </ul>
            </div>

            <!-- Sertifikat Terbaru (kanan bawah) -->
            <div class="cert-grid">
                <?php if (!$sertTerbaru): ?>
                    <div class="cert-card cert-card--empty">Belum ada sertifikat.</div>
                    <?php else: foreach ($sertTerbaru as $s):
                        $lvl = strtolower($s['level_kompetensi'] ?? '');
                        $lvlClass = $lvl ? 'lvl-' . $lvl : '';
                        $href = !empty($s['link_file_sertifikat']) ? $s['link_file_sertifikat'] : '#';
                        $dt = !empty($s['tanggal_terbit']) ? new DateTime($s['tanggal_terbit']) : null;
                    ?>
                        <article class="cert-card">
                            <header class="cert-card__head">
                                <a class="cert-code" href="<?= esc($href) ?>" target="_blank" rel="noopener">
                                    <?= esc($s['nomor_sertifikat']) ?>
                                </a>
                                <?php if ($lvl): ?>
                                    <span class="lvl-badge <?= $lvlClass ?>"><?= esc($s['level_kompetensi']) ?></span>
                                <?php endif; ?>
                            </header>

                            <div class="cert-card__title"><?= esc($s['nama_lengkap']) ?></div>
                            <div class="cert-card__sub"><?= esc($s['nama_posisi']) ?></div>

                            <footer class="cert-card__foot">
                                <time><?= esc($dt ? $dt->format('d M Y') : '—') ?></time>
                                <a class="cert-cta" href="<?= esc($href) ?>" target="_blank" rel="noopener">
                                    Lihat <i class="fa-solid fa-arrow-right"></i>
                                </a>
                            </footer>
                        </article>
                <?php endforeach;
                endif; ?>
            </div>

        </section>
        <br>
        <!-- ROW: QR + HEALTH -->
        <section class="grid grid-2-1">
            <div class="card">
                <div class="card__hdr card__hdr--xl">
                    <h6><i class="fa-solid fa-qrcode"></i>Verifikasi QR • <?= $qSum ?>x</h6>
                </div>
                <canvas id="ch_qr" height="165"></canvas>
            </div>
            <div class="card card--health">
                <div class="card__hdr card__hdr--xl">
                    <h6><i class="fa-solid fa-heart-pulse"></i>System</h6>
                    <div class="tool">
                        <span id="netStatus" class="pill small">Mengukur…</span>
                        <button id="sysRetest" class="btn btn--sm">Uji Ulang</button>
                    </div>
                </div>

                <!-- Info server/app -->
                <div class="sys-grid">
                    <div class="sys-kv"><span>PHP</span><b><?= esc($phpVersion) ?></b></div>
                    <div class="sys-kv"><span>Database</span><b><?= esc($dbVersion ?: 'n/a') ?></b></div>
                    <div class="sys-kv"><span>Koneksi</span><b><?= $isPDO ? 'PDO' : ($isMySQL ? 'MySQLi' : 'n/a') ?></b></div>
                    <div class="sys-kv"><span>Server Time</span><b><?= date('d M Y H:i:s') ?></b></div>
                    <div class="sys-kv"><span>Timezone</span><b><?= esc(date_default_timezone_get()) ?></b></div>
                    <div class="sys-kv"><span>Memory</span><b><?= number_format(memory_get_usage(true) / 1048576, 1) ?> MB / <?= ini_get('memory_limit') ?></b></div>
                </div>

                <!-- Network diagnostics (client-side) -->
                <div class="sys-net">
                    <div class="nrow">
                        <label>RTT</label>
                        <div class="meter"><span id="rttBar" class="meter__fill"></span></div>
                        <span id="rttVal" class="nval">–</span>
                    </div>
                    <div class="nrow">
                        <label>Jitter</label>
                        <div class="meter"><span id="jitBar" class="meter__fill warn"></span></div>
                        <span id="jitVal" class="nval">–</span>
                    </div>
                    <div class="nrow">
                        <label>Downlink</label>
                        <div class="meter"><span id="dlBar" class="meter__fill ok"></span></div>
                        <span id="dlVal" class="nval">–</span>
                    </div>
                    <div class="nrow nrow--info">
                        <div><i class="fa-solid fa-signal"></i> <span id="netType">–</span></div>
                        <div><i class="fa-solid fa-wifi"></i> <span id="netOnline">Online</span></div>
                        <div><i class="fa-solid fa-bolt"></i> <span id="netSave">Data Saver: –</span></div>
                    </div>
                </div>
            </div>

        </section>
        <br>
        <footer class="ft">&copy; <?= date('Y') ?> RELIPROVE — Dashboard Admin</footer>
    </main>
    <script>
        (() => {
            const PING_URL = 'ajax/ping.php';
            const SAMPLES = 7; // total ping; 1 pertama dipakai warm-up
            const TIMEOUT = 2500; // ms

            const $ = (id) => document.getElementById(id);

            function clamp(v, min, max) {
                return Math.max(min, Math.min(max, v));
            }

            async function pingOnce(controller) {
                const t0 = performance.now();
                try {
                    const res = await fetch(PING_URL + '?x=' + Date.now(), {
                        cache: 'no-store',
                        signal: controller.signal
                    });
                    if (!res.ok) throw new Error('bad');
                    await res.text(); // kecil sekali
                    return performance.now() - t0;
                } catch (e) {
                    return TIMEOUT; // timeout dianggap lag
                }
            }

            async function measure() {
                const tag = $('netStatus');
                if (tag) {
                    tag.textContent = 'Mengukur…';
                    tag.className = 'pill small';
                }

                const rtts = [];
                for (let i = 0; i < SAMPLES; i++) {
                    const ctrl = new AbortController();
                    const guard = setTimeout(() => ctrl.abort(), TIMEOUT);
                    const r = await pingOnce(ctrl);
                    clearTimeout(guard);
                    if (i > 0) rtts.push(r); // drop warm-up
                    await new Promise(r => setTimeout(r, 120));
                }

                // hitung rata2 dan jitter (avg delta absolut)
                const avg = rtts.reduce((a, b) => a + b, 0) / Math.max(1, rtts.length);
                const diffs = rtts.slice(1).map((v, i) => Math.abs(v - rtts[i]));
                const jitter = diffs.length ? diffs.reduce((a, b) => a + b, 0) / diffs.length : 0;

                updateLatency(avg, jitter);
                updateConnInfo();

                // status keseluruhan
                const c = classify(avg, jitter, getDownlink());
                if (tag) {
                    tag.textContent = c.label;
                    tag.className = 'pill small ' + c.className;
                }
            }

            function updateLatency(avg, jitter) {
                const rttMax = 500,
                    jitMax = 150; // normalisasi
                $('rttBar').style.width = (100 - (clamp(avg, 0, rttMax) / rttMax * 100)) + '%';
                $('jitBar').style.width = (100 - (clamp(jitter, 0, jitMax) / jitMax * 100)) + '%';
                $('rttVal').textContent = Math.round(avg) + ' ms';
                $('jitVal').textContent = Math.round(jitter) + ' ms';
            }

            function getDownlink() {
                if (navigator.connection && typeof navigator.connection.downlink === 'number') {
                    return navigator.connection.downlink; // Mbps, mungkin undefined di Safari/Firefox
                }
                return null;
            }

            function updateConnInfo() {
                const c = navigator.connection || {};
                const down = getDownlink();

                if (down != null) {
                    const pct = clamp(down, 0, 100) / 100 * 100; // skala 0–100 Mbps
                    $('dlBar').style.width = pct + '%';
                    $('dlVal').textContent = down.toFixed(1) + ' Mbps';
                } else {
                    $('dlBar').style.width = '0%';
                    $('dlVal').textContent = 'n/a';
                }

                $('netType').textContent = (c.effectiveType || c.type || '–').toString().toUpperCase();
                $('netSave').textContent = 'Saver' + (c.saveData ? 'ON' : 'OFF');
                $('netOnline').textContent = navigator.onLine ? 'Online' : 'Offline';
            }

            function classify(rtt, jitter, down) {
                const good = rtt <= 100 && jitter <= 30 && (down == null || down >= 10);
                const ok = rtt <= 200 && jitter <= 60;
                if (good) return {
                    label: 'sehat',
                    className: 'good'
                };
                if (ok) return {
                    label: 'cukup',
                    className: 'warn'
                };
                return {
                    label: 'lag',
                    className: 'bad'
                };
            }

            $('sysRetest')?.addEventListener('click', (e) => {
                e.preventDefault();
                measure();
            });
            window.addEventListener('online', measure);
            window.addEventListener('offline', measure);

            // pertama kali jalan
            measure();
        })();
    </script>

    <script>
        /* ===== Theme from CSS variables ===== */
        const css = getComputedStyle(document.documentElement);
        const COLOR_PRIMARY = css.getPropertyValue('--primary').trim();
        const COLOR_TEXT = css.getPropertyValue('--text').trim();
        const COLOR_MUTED = css.getPropertyValue('--muted').trim();
        const COLOR_LINE = css.getPropertyValue('--line').trim();
        const COLOR_SURFACE = css.getPropertyValue('--bg-surface').trim();

        const labels = <?= json_encode($labels) ?>;
        const counts = <?= json_encode($counts) ?>;
        const topPos = <?= json_encode($topPos) ?>;
        const verif = <?= json_encode($verif) ?>;
        const nilai = <?= json_encode($nilai) ?>;
        const lulus = <?= json_encode($lulus) ?>;
        const qLabels = <?= json_encode($qLabels) ?>;
        const qCounts = <?= json_encode($qCounts) ?>;

        /* ===== Defaults ===== */
        Chart.defaults.color = COLOR_TEXT;
        Chart.defaults.borderColor = COLOR_LINE;

        /* ===== Main line (area) ===== */
        new Chart(document.getElementById('ch_trend'), {
            type: 'line',
            data: {
                labels,
                datasets: [{
                    label: 'Pendaftaran',
                    data: counts,
                    borderWidth: 2,
                    borderColor: COLOR_PRIMARY,
                    backgroundColor: 'rgba(155,94,255,0.18)',
                    fill: true,
                    tension: .35,
                    pointRadius: 2
                }]
            },
            options: {
                plugins: {
                    legend: {
                        labels: {
                            color: COLOR_TEXT
                        }
                    }
                },
                scales: {
                    x: {
                        ticks: {
                            color: COLOR_MUTED
                        },
                        grid: {
                            color: COLOR_LINE
                        }
                    },
                    y: {
                        beginAtZero: true,
                        ticks: {
                            color: COLOR_MUTED
                        },
                        grid: {
                            color: COLOR_LINE
                        }
                    }
                }
            }
        });

        /* ===== Top posisi (bar) ===== */
        const tpLabels = topPos.map(x => x.nama_posisi);
        const tpData = topPos.map(x => parseInt(x.jml || 0, 10));
        new Chart(document.getElementById('ch_toppos'), {
            type: 'bar',
            data: {
                labels: tpLabels,
                datasets: [{
                    label: 'Pendaftaran',
                    data: tpData,
                    backgroundColor: 'rgba(155,94,255,0.28)',
                    borderColor: COLOR_PRIMARY,
                    borderWidth: 1
                }]
            },
            options: {
                indexAxis: 'y',
                scales: {
                    x: {
                        beginAtZero: true,
                        ticks: {
                            color: COLOR_MUTED
                        },
                        grid: {
                            color: COLOR_LINE
                        }
                    },
                    y: {
                        ticks: {
                            color: COLOR_MUTED
                        },
                        grid: {
                            color: COLOR_LINE
                        }
                    }
                }
            }
        });

        /* ===== Breakdown status (bars horizontal mini) ===== */
        function hbar(canvasId, labels, values) {
            new Chart(document.getElementById(canvasId), {
                type: 'bar',
                data: {
                    labels,
                    datasets: [{
                        data: values,
                        backgroundColor: ['rgba(255,187,60,.35)', 'rgba(155,94,255,.45)', 'rgba(233,233,238,.25)'],
                        borderColor: [COLOR_LINE, COLOR_PRIMARY, COLOR_LINE],
                        borderWidth: 1
                    }]
                },
                options: {
                    indexAxis: 'y',
                    plugins: {
                        legend: {
                            display: false
                        },
                        tooltip: {
                            enabled: true
                        }
                    },
                    scales: {
                        x: {
                            beginAtZero: true,
                            ticks: {
                                color: COLOR_MUTED
                            },
                            grid: {
                                color: COLOR_LINE
                            }
                        },
                        y: {
                            ticks: {
                                color: COLOR_MUTED
                            },
                            grid: {
                                color: COLOR_LINE
                            }
                        }
                    }
                }
            });
        }
        hbar('bar_verif', ['Pending', 'Diterima', 'Ditolak'], [<?= $verif['pending'] ?>, <?= $verif['diterima'] ?>, <?= $verif['ditolak'] ?>]);
        hbar('bar_nilai', ['Belum', 'Dinilai'], [<?= $nilai['belum'] ?>, <?= $nilai['dinilai'] ?>]);
        hbar('bar_lusus', ['Belum', 'Lulus', 'Tidak'], [<?= $lulus['belum'] ?>, <?= $lulus['lulus'] ?>, <?= $lulus['tidak'] ?>]); // typo id placeholder

        /* fix id name (lulus) */
        hbar('bar_lulus', ['Belum', 'Lulus', 'Tidak'], [<?= $lulus['belum'] ?>, <?= $lulus['lulus'] ?>, <?= $lulus['tidak'] ?>]);

        /* ===== QR spark (line minimal, tanpa axes) ===== */
        new Chart(document.getElementById('ch_qr'), {
            type: 'line',
            data: {
                labels: qLabels,
                datasets: [{
                    data: qCounts,
                    borderColor: COLOR_PRIMARY,
                    backgroundColor: 'rgba(155,94,255,.18)',
                    tension: .35,
                    borderWidth: 2,
                    fill: true,
                    pointRadius: 0
                }]
            },
            options: {
                plugins: {
                    legend: {
                        display: false
                    }
                },
                scales: {
                    x: {
                        display: false
                    },
                    y: {
                        display: false
                    }
                }
            }
        });

        /* ===== KPI sparklines (dummy: gunakan counts & qCounts sebagai baseline) ===== */
        function spark(id, arr) {
            new Chart(document.getElementById(id), {
                type: 'line',
                data: {
                    labels: arr.map((_, i) => i),
                    datasets: [{
                        data: arr,
                        borderColor: COLOR_PRIMARY,
                        borderWidth: 2,
                        tension: .35,
                        pointRadius: 0
                    }]
                },
                options: {
                    plugins: {
                        legend: {
                            display: false
                        }
                    },
                    scales: {
                        x: {
                            display: false
                        },
                        y: {
                            display: false
                        }
                    },
                    elements: {
                        line: {
                            fill: false
                        }
                    }
                }
            });
        }
        spark('sp1', qCounts);
        spark('sp2', counts);
        spark('sp3', counts);
        spark('sp4', qCounts);
    </script>
</body>

</html>