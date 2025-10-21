<?php
/* ====== SESSION GUARD ====== */
if (session_status() === PHP_SESSION_NONE) session_start();

/* dukung format lama/baru + role adminbackup */
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
if (!in_array($userArr['peran'] ?? '', ['admin', 'superadmin', 'adminbackup'], true)) {
    header('HTTP/1.1 403 Forbidden');
    echo 'Akses ditolak.';
    exit;
}
$me = $userArr;

/* ===== KONEKSI DB ===== */
require_once __DIR__ . '/../config/koneksi.php';
$isPDO   = isset($pdo)  && $pdo instanceof PDO;
$isMySQL = isset($conn) && $conn instanceof mysqli;
if (!$isPDO && !$isMySQL) {
    http_response_code(500);
    echo "config/koneksi.php harus definisikan \$pdo atau \$conn.";
    exit;
}

/* ===== HELPERS ===== */
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

/* ===== ENDPOINT: cek akses link (AJAX) ===== */
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['cek_akses'])) {
    header('Content-Type: application/json');
    $idc = (int)$_GET['cek_akses'];
    $d = db_one("SELECT link_soal_asesmen, link_hasil_ujian FROM pendaftaran WHERE id_pendaftaran={$idc} LIMIT 1");
    if (!$d) {
        echo json_encode(['ok' => false]);
        exit;
    }

    $items = [];
    foreach (['Soal' => 'link_soal_asesmen', 'Hasil' => 'link_hasil_ujian'] as $label => $col) {
        $url = $d[$col] ?? null;
        if (!$url) continue;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_NOBODY => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 8,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $ok = curl_exec($ch) !== false;
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $items[] = ['label' => $label, 'status' => $ok && $code >= 200 && $code < 400, 'code' => $code];
    }
    echo json_encode(['ok' => true, 'items' => $items]);
    exit;
}

/* ===== FILTER & DATA ===== */
$q  = trim($_GET['q'] ?? '');
$fv = $_GET['fv'] ?? '';                  // pending|diterima|ditolak|""
$fp = (int)($_GET['fp'] ?? 0);            // id_posisi
$fd = $_GET['fd'] ?? '';                  // YYYY-MM-DD
$fs = $_GET['fs'] ?? '';                  // YYYY-MM-DD
$pp = max(10, (int)($_GET['pp'] ?? 10));
$pg = max(1,  (int)($_GET['p']  ?? 1));
$off = ($pg - 1) * $pp;

$where = [];
if ($q !== '')     $where[] = "(pg.nama_lengkap LIKE '%" . addslashes($q) . "%' OR pg.email LIKE '%" . addslashes($q) . "%' OR ps.nama_posisi LIKE '%" . addslashes($q) . "%')";
if (in_array($fv, ['pending', 'diterima', 'ditolak'], true)) $where[] = "p.status_verifikasi='" . addslashes($fv) . "'";
if ($fp > 0)       $where[] = "p.id_posisi=" . (int)$fp;
if ($fd && preg_match('/^\d{4}-\d{2}-\d{2}$/', $fd)) $where[] = "DATE(p.tanggal_daftar)>='" . addslashes($fd) . "'";
if ($fs && preg_match('/^\d{4}-\d{2}-\d{2}$/', $fs)) $where[] = "DATE(p.tanggal_daftar)<='" . addslashes($fs) . "'";
$sqlWhere = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$total = db_scalar("SELECT COUNT(*) FROM pendaftaran p
                    JOIN pengguna pg ON pg.id_pengguna=p.id_pengguna
                    JOIN posisi   ps ON ps.id_posisi=p.id_posisi
                    $sqlWhere");

$rows = db_all("SELECT p.*, pg.nama_lengkap, pg.email, ps.nama_posisi
                FROM pendaftaran p
                JOIN pengguna pg ON pg.id_pengguna=p.id_pengguna
                JOIN posisi   ps ON ps.id_posisi=p.id_posisi
                $sqlWhere
                ORDER BY p.tanggal_daftar DESC
                LIMIT {$pp} OFFSET {$off}");


$posiMap = [];
foreach (db_all("SELECT id_posisi, nama_posisi FROM posisi ORDER BY nama_posisi ASC") as $x) {
    $posiMap[$x['id_posisi']] = $x['nama_posisi'];
}
?>
<!doctype html>
<html lang="id">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1.0">
    <title>Pendaftaran | RELIPROVE</title>
    <link rel="icon" href="../aset/img/logo.png" type="image/png">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" rel="stylesheet" />
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap" rel="stylesheet" />
    <link href="css/style.css" rel="stylesheet" />
    <link href="css/fitur_filter.css" rel="stylesheet" />
</head>

<body>

    <!-- SIDEBAR -->
    <aside class="sb" id="sidebar">
        <div class="sb__brand" style="font-size: 35px; margin-top: 15px; margin-bottom: 10px;">RELIPROVE</div>
        <nav class="sb__nav">
            <a href="dashboard_admin.php"><i class="fa-solid fa-gauge"></i>Dashboard</a>
            <a href="pengguna.php"><i class="fa-solid fa-users"></i>Manajemen Pengguna</a>
            <a class="active" href="pendaftaran.php"><i class="fa-solid fa-id-card"></i>Pendaftaran</a>
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
                <button class="tb__burger" onclick="document.getElementById('sidebar').classList.toggle('open')">
                    <i class="fa-solid fa-bars"></i>
                </button>
                <h1 class="tb__title">Pendaftaran</h1>
                <form class="tb__search" method="get">
                    <i class="fa-solid fa-magnifying-glass"></i>
                    <input type="text" name="q" value="<?= esc($q) ?>" placeholder="Cari (nama, email, posisi)…">
                </form>
            </div>
            <div class="tb__right">
                <div class="tb__me">
                    <img src="https://ui-avatars.com/api/?name=<?= urlencode($me['nama_lengkap'] ?? 'Admin') ?>&background=9b5eff&color=fff&rounded=true&size=40" alt="ava">
                    <div>
                        <div class="me__name"><?= esc($me['nama_lengkap'] ?? 'Admin') ?></div>
                        <div class="me__role"><?= esc($me['peran']) ?></div>
                    </div>
                </div>
            </div>
        </header>
        <br>
        <section class="sec">
            <div class="sec__hdr">
            </div>

            <!-- FILTER (fitur_filter.css) -->
            <div class="card card--ht-filter">
                <div class="filter__hdr">
                    <div>
                        <h2 class="filter__title"><i class="fa-solid fa-id-card"></i> Filter Pendaftaran</h2>
                        <p class="sec__desc" style="margin-top:-8px; margin-bottom: 20px;">Tujuan: memverifikasi kelengkapan berkas & memastikan alur berjalan.</p>
                    </div>
                </div>
                <form method="get">
                    <div class="frow">
                        <!-- Status Verifikasi -->
                        <div class="fitem">
                            <label>Status Verifikasi</label>
                            <div class="field">
                                <div class="ficon"><i class="fa-solid fa-clipboard-check"></i></div>
                                <select name="fv" class="fsel">
                                    <option value="">Semua</option>
                                    <option value="pending" <?= $fv === 'pending'  ? 'selected' : ''; ?>>Pending</option>
                                    <option value="diterima" <?= $fv === 'diterima' ? 'selected' : ''; ?>>Diterima</option>
                                    <option value="ditolak" <?= $fv === 'ditolak'  ? 'selected' : ''; ?>>Ditolak</option>
                                </select>
                                <div class="chev"><i class="fa-solid fa-chevron-down"></i></div>
                            </div>
                        </div>

                        <!-- Posisi -->
                        <div class="fitem">
                            <label>Posisi</label>
                            <div class="field">
                                <div class="ficon"><i class="fa-solid fa-briefcase"></i></div>
                                <select name="fp" class="fsel">
                                    <option value="0">Semua</option>
                                    <?php foreach ($posiMap as $idp => $np): ?>
                                        <option value="<?= (int)$idp ?>" <?= $fp === (int)$idp ? 'selected' : ''; ?>><?= esc($np) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="chev"><i class="fa-solid fa-chevron-down"></i></div>
                            </div>
                        </div>

                        <!-- Tanggal -->
                        <div class="fitem">
                            <label>Dari</label>
                            <div class="field">
                                <div class="ficon"><i class="fa-regular fa-calendar"></i></div>
                                <input type="date" name="fd" class="fsel" value="<?= esc($fd) ?>">
                                <div class="chev"></div>
                            </div>
                        </div>
                        <div class="fitem">
                            <label>Sampai</label>
                            <div class="field">
                                <div class="ficon"><i class="fa-regular fa-calendar-check"></i></div>
                                <input type="date" name="fs" class="fsel" value="<?= esc($fs) ?>">
                                <div class="chev"></div>
                            </div>
                        </div>

                        <!-- Keyword -->
                        <div class="fitem">
                            <label>Kata Kunci</label>
                            <div class="field">
                                <div class="ficon"><i class="fa-solid fa-magnifying-glass"></i></div>
                                <input type="text" name="q" class="fsel" placeholder="Nama, email, posisi…" value="<?= esc($q) ?>">
                                <div class="chev"></div>
                            </div>
                        </div>
                    </div>

                    <div class="factions">
                        <p class="hintline">Gunakan filter untuk mempersempit daftar pendaftaran.</p>
                        <div class="actions">
                            <a class="btn btn--sm btn--ghost" href="pendaftaran.php"><i class="fa-solid fa-rotate"></i> Reset</a>
                            <button class="btn btn--sm"><i class="fa-solid fa-filter"></i> Terapkan</button>
                        </div>
                    </div>
                </form>
            </div>
            <br>
            <!-- GRID KARTU PESERTA (pakai assess-grid/assess dari style.css) -->
            <div class="card">
                <div class="card__hdr card__hdr--xl">
                    <h6><i class="fa-solid fa-list-check"></i> Rekap Pendaftaran</h6>
                    <span class="count-badge"><?= (int)$total ?> data</span>
                </div>

                <?php if (!$rows): ?>
                    <div class="assess-empty">
                        <i class="fa-regular fa-folder-open"></i>&nbsp; Tidak ada data pendaftaran.
                    </div>
                <?php else: ?>
                    <div class="assess-grid">
                        <?php foreach ($rows as $r):

                            // ===== tone status (pakai class yang SUDAH ADA: ok | warn | muted) =====
                            $toneVer = ['pending' => 'warn', 'diterima' => 'ok', 'ditolak' => 'warn'][$r['status_verifikasi']] ?? 'muted';
                            $tonePen = ['belum' => 'muted', 'dinilai' => 'ok'][$r['status_penilaian']] ?? 'muted';
                            $toneKel = ['belum' => 'muted', 'lulus' => 'ok', 'tidak' => 'warn'][$r['status_kelulusan']] ?? 'muted';

                            // ===== tracking umur pendaftaran =====
                            $ts      = strtotime($r['tanggal_daftar']);
                            $ageDays = max(0, (int)floor((time() - $ts) / 86400));
                            $ageTone = ($ageDays <= 3) ? 'ok' : 'warn';
                            // skala 0–10 hari ⇒ 0–100%
                            $fillW   = min(100, (int)round(($ageDays / 10) * 100));

                            // ===== links =====
                            $docs = [
                                'Timeline'         => $r['link_timeline'],
                                'Jobdesk'          => $r['link_jobdesk'],
                                'Portofolio'       => $r['link_portofolio'],
                                'Foto Formal'      => $r['link_foto_formal'],
                                'Foto Transparan'  => $r['link_foto_transparan'],
                                'CV'               => $r['link_cv'],
                                'Biodata'          => $r['link_biodata'],
                                'Dok. Perkuliahan' => $r['link_dok_perkuliahan'],
                            ];
                            $ases = [
                                'Soal'  => $r['link_soal_asesmen'],
                                'Hasil' => $r['link_hasil_ujian'],
                            ];
                        ?>
                            <div class="assess">
                                <!-- ZONA 1: IDENTITAS -->
                                <!-- ZONA 1: IDENTITAS -->
                                <div class="assess__head" style="display:flex; align-items:center; justify-content:space-between; gap:14px; margin-top: 10px;">
                                    <!-- avatar -->
                                    <div style="display:flex; align-items:center; gap:12px; flex:1; min-width:0;">
                                        <div class="avatar"><?= strtoupper(substr(trim($r['nama_lengkap']), 0, 1)) ?></div>
                                        <div style="min-width:0;">
                                            <!-- nama: paksa 1 baris -->
                                            <div class="assess__name"
                                                style="white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">
                                                <?= esc($r['nama_lengkap']) ?>
                                            </div>
                                            <div class="assess__pos" style="white-space:nowrap; overflow:hidden; text-overflow:ellipsis;"><?= esc($r['nama_posisi']) ?></div>
                                        </div>
                                    </div>
                                    <!-- status tags kanan -->
                                    <div class="head__tags" style="flex-shrink:0; display:flex; gap:6px; flex-wrap:wrap; justify-content:flex-end;">
                                        <span class="status <?= esc($toneVer) ?>">verifikasi : <?= esc($r['status_verifikasi']) ?></span>
                                        <span class="status <?= esc($tonePen) ?>">penilaian : <?= esc($r['status_penilaian']) ?></span>
                                        <span class="status <?= esc($toneKel) ?>">kelulusan : <?= esc($r['status_kelulusan']) ?></span>
                                    </div>
                                </div>


                                <!-- ZONA 2: STATUS RAIL / PROGRESS -->
                                <div class="assess__meter">
                                    <div class="meter">
                                        <span class="meter__fill <?= $ageTone === 'ok' ? 'ok' : 'warn' ?>" style="width: <?= $fillW ?>%"></span>
                                    </div>
                                    <div class="meter__info" style="margin-top: 10px;">
                                        <span class="hint">Waktu sejak daftar</span>
                                        <span class="age <?= esc($ageTone) ?>"><?= $ageDays ?> hari</span>
                                    </div>
                                </div>

                                <!-- ZONA 3A: RESOURCE – DOKUMEN -->
                                <div class="chips" style="margin-top:9px; display:grid; grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); gap:19px;">
                                    <?php foreach ($docs as $label => $url) {
                                        if (!$url) continue; ?>
                                        <a class="pill-link" href="<?= esc($url) ?>" target="_blank" rel="noopener" style="text-align:center;">
                                            <i class="fa-regular fa-file-lines"></i> <?= esc($label) ?>
                                        </a>
                                    <?php } ?>
                                </div>


                                <!-- ZONA 3B: RESOURCE – ASESMEN + CEK AKSES -->
                                <div class="assess__foot">
                                    <div class="chips">
                                        <?php foreach ($ases as $label => $url) {
                                            if (!$url) continue; ?>
                                            <a class="pill-link" href="<?= esc($url) ?>" target="_blank" rel="noopener">
                                                <i class="fa-solid fa-link"></i> <?= esc($label) ?>
                                            </a>
                                        <?php } ?>
                                    </div>
                                    <?php if ($r['link_soal_asesmen'] || $r['link_hasil_ujian']): ?>
                                        <button class="btn btn--ghost btn--sm" onclick="cekAkses(<?= (int)$r['id_pendaftaran'] ?>)">
                                            <i class="fa-solid fa-shield-check"></i> Cek Akses
                                        </button>
                                    <?php endif; ?>
                                </div>

                                <!-- META RINGKAS (tetap terlihat, tidak menumpuk) -->
                                <div class="assess__meta" style="margin-bottom: 5px; justify-content: center;">
                                    <div class="meta"><i class="fa-regular fa-envelope"></i> <?= esc($r['email']) ?></div>
                                    <div class="meta"><i class="fa-regular fa-id-badge"></i> Nomor ID : <?= (int)$r['id_pendaftaran'] ?></div>
                                    <div class="meta"><i class="fa-regular fa-calendar"></i> Daftar : <?= date('d M Y H:i', $ts) ?></div>
                                </div>

                                <?php if ($r['link_soal_asesmen'] || $r['link_hasil_ujian']): ?>
                                    <div id="cek-<?= (int)$r['id_pendaftaran'] ?>" class="muted tiny"></div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <br>
                    <br>
                    <!-- PAGINATION -->
                    <?php $pages = max(1, (int)ceil($total / $pp)); ?>
                    <div class="pager" style="display:flex; justify-content:flex-end; align-items:center; gap:12px;">
                        <div class="pager__info">Halaman <?= (int)$pg ?> / <?= (int)$pages ?></div>
                        <div class="pager__nav">
                            <?php
                            $base = $_GET;
                            unset($base['p']);
                            $qs = function ($p) use ($base) {
                                $base['p'] = $p;
                                return '?' . http_build_query($base);
                            };
                            ?>
                            <a class="btn btn--ghost <?= $pg <= 1 ? 'disabled' : '' ?>"
                                href="<?= $pg <= 1 ? '#' : $qs($pg - 1) ?>">
                                <i class="fa-solid fa-chevron-left"></i> Prev
                            </a>
                            <a class="btn btn--ghost <?= $pg >= $pages ? 'disabled' : '' ?>"
                                href="<?= $pg >= $pages ? '#' : $qs($pg + 1) ?>">
                                Next <i class="fa-solid fa-chevron-right"></i>
                            </a>
                        </div>
                    </div>
                    <br>
                <?php endif; ?>
            </div>
        </section>
    </main>

    <script>
        async function cekAkses(id) {
            const el = document.getElementById('cek-' + id);
            if (!el) return;
            el.textContent = 'Mengecek akses…';
            try {
                const res = await fetch('pendaftaran.php?cek_akses=' + id, {
                    headers: {
                        'X-Requested-With': 'fetch'
                    }
                });
                const js = await res.json();
                if (!js.ok) {
                    el.textContent = 'Gagal cek akses.';
                    return;
                }
                if (!js.items || !js.items.length) {
                    el.textContent = 'Tidak ada link untuk dicek.';
                    return;
                }
                el.innerHTML = js.items.map(it => `${it.label}: ${it.status?'OK':'GAGAL'} (HTTP ${it.code})`).join('<br>');
            } catch (e) {
                el.textContent = 'Error: ' + e;
            }
        }
    </script>
</body>

</html>