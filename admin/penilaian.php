<?php
// =========================
// penilaian.php (Read-Only)
// =========================
session_start();

/* -------------------------------------------------------
   OPTIONAL: pakai config/guard proyekmu jika ada
------------------------------------------------------- */
if (file_exists(__DIR__ . '/config.php')) {
    require_once __DIR__ . '/config.php'; // seandainya sudah ada $pdo, esc(), is_admin(), dll
}
if (!function_exists('esc')) {
    function esc($v)
    {
        return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    }
}
if (!function_exists('is_admin')) {
    function is_admin()
    {
        $role = $_SESSION['role'] ?? ($_SESSION['me']['peran'] ?? null);
        return in_array($role, ['admin', 'superadmin'], true);
    }
}
if (!isset($_SESSION['user'])) {
    $_SESSION['user'] = [
        'id_pengguna' => 3,
        'nama_lengkap' => 'Admin RELIPROVE',
        'email' => 'admin@reliprove.local',
        'peran' => 'superadmin'
    ];
}
$me = $_SESSION['user'];
$namaAdmin = $me['nama_lengkap'] ?? 'Admin';
if (!isset($me)) {
    $me = ['peran' => $_SESSION['role'] ?? 'admin'];
}

/* -------------------------------------------------------
   Koneksi PDO fallback (pakai jika $pdo belum ada)
------------------------------------------------------- */
if (!isset($pdo)) {
    $dbHost = '127.0.0.1';
    $dbName = 'db_relipove';
    $dbUser = 'root';
    $dbPass = '';
    $dbCharset = 'utf8mb4';
    $dsn = "mysql:host={$dbHost};dbname={$dbName};charset={$dbCharset}";
    try {
        $pdo = new PDO($dsn, $dbUser, $dbPass, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    } catch (Throwable $e) {
        http_response_code(500);
        echo "DB connection failed: " . esc($e->getMessage());
        exit;
    }
}

/* -------------------------------------------------------
   Guard akses: halaman ini read-only; boleh diakses admin.
   (Jika ingin membatasi non-admin, aktifkan blok di bawah)
------------------------------------------------------- */
// if (!is_admin()) {
//     http_response_code(403);
//     echo "Forbidden";
//     exit;
// }

/* -------------------------------------------------------
   Ambil & normalisasi parameter GET (aman dari warning)
------------------------------------------------------- */
$q = trim($_GET['q'] ?? '');

/* angka terikat tabel (null jika kosong/tidak ada) */
$id_bidang   = isset($_GET['bidang'])   && $_GET['bidang']   !== '' ? (int)$_GET['bidang']   : null;
$id_kategori = isset($_GET['kategori']) && $_GET['kategori'] !== '' ? (int)$_GET['kategori'] : null;
$id_posisi   = isset($_GET['posisi'])   && $_GET['posisi']   !== '' ? (int)$_GET['posisi']   : null;

/* enum rekomendasi */
$rekomendasi = $_GET['rekomendasi'] ?? ''; // '', 'di review', 'belum layak', 'layak'

/* skor (0–10). Gunakan filter_input agar tidak timbul warning */
$skor_min_raw = filter_input(INPUT_GET, 'skor_min', FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 10], 'flags' => FILTER_NULL_ON_FAILURE]);
$skor_max_raw = filter_input(INPUT_GET, 'skor_max', FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 10], 'flags' => FILTER_NULL_ON_FAILURE]);
$skor_min = ($skor_min_raw === null || $skor_min_raw === false) ? null : (int)$skor_min_raw;
$skor_max = ($skor_max_raw === null || $skor_max_raw === false) ? null : (int)$skor_max_raw;

$urut    = $_GET['urut'] ?? 'tanggal_dinilai_desc';
$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = max(1, min(50, (int)($_GET['per'] ?? 10)));
$exportFmt = strtolower($_GET['export'] ?? ''); // '', 'csv', 'xls', 'excel'

/* Safety skor range */
if ($skor_min !== null && $skor_max !== null && $skor_min > $skor_max) {
    [$skor_min, $skor_max] = [$skor_max, $skor_min];
}


/* -------------------------------------------------------
   Build WHERE & params (prepared)
------------------------------------------------------- */
$where   = [];
$params  = [];

$where[] = '1=1';

if ($q !== '') {
    $where[] = '(peserta.nama_lengkap LIKE :q OR ases.nama_lengkap LIKE :q OR pos.nama_posisi LIKE :q)';
    $params[':q'] = "%{$q}%";
}
if ($id_bidang !== null) {
    $where[] = 'bid.id_bidang = :id_bidang';
    $params[':id_bidang'] = $id_bidang;
}
if ($id_kategori !== null) {
    $where[] = 'kat.id_kategori = :id_kategori';
    $params[':id_kategori'] = $id_kategori;
}
if ($id_posisi !== null) {
    $where[] = 'pos.id_posisi = :id_posisi';
    $params[':id_posisi'] = $id_posisi;
}
if ($rekomendasi !== '') {
    $where[] = 'pen.rekomendasi = :rekomendasi';
    $params[':rekomendasi'] = $rekomendasi;
}
if ($skor_min !== null) {
    $where[] = 'pen.skor >= :skor_min';
    $params[':skor_min'] = $skor_min;
}
if ($skor_max !== null) {
    $where[] = 'pen.skor <= :skor_max';
    $params[':skor_max'] = $skor_max;
}

$whereSql = implode(' AND ', $where);

/* -------------------------------------------------------
   ORDER BY whitelist
------------------------------------------------------- */
$mapOrder = [
    'tanggal_dinilai_desc' => 'pen.tanggal_dinilai DESC, pen.id_penilaian DESC',
    'tanggal_dinilai_asc'  => 'pen.tanggal_dinilai ASC, pen.id_penilaian ASC',
    'skor_desc'            => 'pen.skor DESC, pen.id_penilaian DESC',
    'skor_asc'             => 'pen.skor ASC, pen.id_penilaian ASC',
];
$orderSql = $mapOrder[$urut] ?? $mapOrder['tanggal_dinilai_desc'];

/* -------------------------------------------------------
   Query master base (JOIN lengkap)
------------------------------------------------------- */
$BASE_FROM = "
FROM penilaian pen
JOIN pendaftaran penda   ON penda.id_pendaftaran = pen.id_pendaftaran
JOIN pengguna   peserta  ON peserta.id_pengguna  = penda.id_pengguna
LEFT JOIN pengguna ases  ON ases.id_pengguna     = pen.id_asesor
JOIN posisi     pos      ON pos.id_posisi        = penda.id_posisi
JOIN kategori   kat      ON kat.id_kategori      = pos.id_kategori
JOIN bidang     bid      ON bid.id_bidang        = kat.id_bidang
";

/* -------------------------------------------------------
   Ringkasan hitung per status untuk header
------------------------------------------------------- */
$sqlSummary = "
SELECT
  COUNT(*) AS total,
  SUM(CASE WHEN pen.rekomendasi = 'di review'   THEN 1 ELSE 0 END) AS cnt_review,
  SUM(CASE WHEN pen.rekomendasi = 'belum layak' THEN 1 ELSE 0 END) AS cnt_belum,
  SUM(CASE WHEN pen.rekomendasi = 'layak'       THEN 1 ELSE 0 END) AS cnt_layak
$BASE_FROM
WHERE $whereSql
";
$st = $pdo->prepare($sqlSummary);
$st->execute($params);
$summary = $st->fetch() ?: ['total' => 0, 'cnt_review' => 0, 'cnt_belum' => 0, 'cnt_layak' => 0];

/* -------------------------------------------------------
   Ekspor CSV (tanpa LIMIT)
------------------------------------------------------- */
if (in_array($exportFmt, ['csv', 'xls', 'excel'], true)) {
    $sqlExport = "
    SELECT
      peserta.nama_lengkap               AS Peserta,
      pos.nama_posisi                    AS Posisi,
      kat.nama_kategori                  AS Kategori,
      bid.nama_bidang                    AS Bidang,
      COALESCE(ases.nama_lengkap,'-')    AS Asesor,
      pen.skor                           AS Skor,
      pen.rekomendasi                    AS Rekomendasi,
      DATE_FORMAT(pen.tanggal_dinilai, '%Y-%m-%d %H:%i') AS Tanggal_Dinilai,
      pen.link_soal                      AS Link_Soal,
      pen.link_jawaban                   AS Link_Jawaban
    $BASE_FROM
    WHERE $whereSql
    ORDER BY $orderSql
    ";
    $st = $pdo->prepare($sqlExport);
    $st->execute($params);

    if ($exportFmt === 'csv') {
        // === CSV polos (kompatibel lintas sistem) ===
        $filename = "rekap-penilaian-" . date('Ymd-His') . ".csv";
        header('Content-Type: text/csv; charset=utf-8');
        header("Content-Disposition: attachment; filename=\"$filename\"");
        $out = fopen('php://output', 'w');
        // BOM UTF-8 untuk Excel
        fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));
        // Header
        $first = $st->fetch(PDO::FETCH_ASSOC);
        if ($first) {
            fputcsv($out, array_keys($first));
            fputcsv($out, $first);
            while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
                fputcsv($out, $row);
            }
        } else {
            fputcsv($out, ['Peserta', 'Posisi', 'Kategori', 'Bidang', 'Asesor', 'Skor', 'Rekomendasi', 'Tanggal_Dinilai', 'Link_Soal', 'Link_Jawaban']);
        }
        fclose($out);
        exit;
    }

    // === XLS (HTML table dengan styling, Excel-friendly) ===
    // === XLS (HTML table dengan styling, Excel-friendly) ===
    $filename = "rekap-penilaian-" . date('Ymd-His') . ".xls";
    header("Content-Type: application/vnd.ms-excel; charset=utf-8");
    header("Content-Disposition: attachment; filename=\"$filename\"");

    // Excel membaca HTML, jadi kita kirim dokumen HTML ringan + CSS inline
    echo "<!DOCTYPE html><html><head><meta charset=\"utf-8\">";
    echo "<title>Rekap Penilaian</title>";
    echo "<style>
    body { font-family: Segoe UI, Arial, sans-serif; }
    .wrap { width: 95%; margin: 0 auto; }        /* <-- pusatkan tabel */
    table.exl { border-collapse: collapse; width: 100%; margin: 0 auto; }
    .exl th, .exl td { border: 1px solid #d0d3db; padding: 8px 10px; }
    .exl th {
        background: #9b5eff; color: #fff; font-weight: 800;
        text-align: center;                      /* header rata tengah */
    }
    .exl td { text-align: center; }              /* isi default tengah */
    .exl td.text-left { text-align: left; }      /* kolom khusus kiri */
    .exl tr:nth-child(even) td { background: #f7f5ff; }
    .exl tr:hover td { background: #eee9ff; }
    .muted { color: #666; }
    a { color: #3b49df; text-decoration: none; }
</style>";
    echo "</head><body>";
    echo "<div class='wrap'>";
    echo "<table class=\"exl\">";
    $headers = ['Peserta', 'Posisi', 'Kategori', 'Bidang', 'Asesor', 'Skor', 'Rekomendasi', 'Tanggal_Dinilai', 'Link_Soal', 'Link_Jawaban'];
    echo "<thead><tr>";
    foreach ($headers as $h) echo "<th>" . esc($h) . "</th>";
    echo "</tr></thead><tbody>";

    $st->execute($params);
    $hadRow = false;
    while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
        $hadRow = true;
        $linkSoal  = $row['Link_Soal'] ? '<a href="' . esc($row['Link_Soal']) . '">Buka</a>' : '<span class=\"muted\">-</span>';
        $linkJawab = $row['Link_Jawaban'] ? '<a href="' . esc($row['Link_Jawaban']) . '">Buka</a>' : '<span class=\"muted\">-</span>';
        echo "<tr>";
        echo "<td class='text-left'>" . esc($row['Peserta']) . "</td>";
        echo "<td class='text-left'>" . esc($row['Posisi']) . "</td>";
        echo "<td class='text-left'>" . esc($row['Kategori']) . "</td>";
        echo "<td>" . esc($row['Bidang']) . "</td>";
        echo "<td>" . esc($row['Asesor']) . "</td>";
        echo "<td>" . (is_numeric($row['Skor']) ? (int)$row['Skor'] : 0) . "</td>";
        echo "<td>" . esc($row['Rekomendasi']) . "</td>";
        echo "<td>" . esc($row['Tanggal_Dinilai']) . "</td>";
        echo "<td>" . $linkSoal . "</td>";
        echo "<td>" . $linkJawab . "</td>";
        echo "</tr>";
    }
    if (!$hadRow) {
        echo "<tr><td colspan=\"10\" class=\"muted\">Tidak ada data.</td></tr>";
    }
    echo "</tbody></table>";
    echo "</div>"; // wrap
    echo "</body></html>";
    exit;
}

/* -------------------------------------------------------
   Pagination: total, data page
------------------------------------------------------- */
$total = (int)$summary['total'];
$pages = max(1, (int)ceil($total / $perPage));
$offset = ($page - 1) * $perPage;

$sqlList = "
SELECT
  pen.id_penilaian, pen.skor, pen.rekomendasi, pen.komentar, pen.tanggal_dinilai,
  pen.link_soal, pen.link_jawaban,
  penda.id_pendaftaran,
  peserta.id_pengguna       AS id_peserta,
  peserta.nama_lengkap      AS nama_peserta,
  peserta.email             AS email_peserta,
  ases.id_pengguna          AS id_asesor,
  ases.nama_lengkap         AS nama_asesor,
  pos.id_posisi, pos.nama_posisi,
  kat.id_kategori, kat.nama_kategori,
  bid.id_bidang, bid.nama_bidang
$BASE_FROM
WHERE $whereSql
ORDER BY $orderSql
LIMIT :limit OFFSET :offset
";
$st = $pdo->prepare($sqlList);
foreach ($params as $k => $v) {
    $st->bindValue($k, $v);
}
$st->bindValue(':limit',  $perPage, PDO::PARAM_INT);
$st->bindValue(':offset', $offset,  PDO::PARAM_INT);
$st->execute();
$rows = $st->fetchAll();

/* -------------------------------------------------------
   Data dropdown filter (dependent)
------------------------------------------------------- */
$bidangs = $pdo->query("SELECT id_bidang, nama_bidang FROM bidang ORDER BY nama_bidang")->fetchAll();

$kategoriSql = "SELECT id_kategori, nama_kategori FROM kategori";
$katWhere = [];
$katParams = [];
if ($id_bidang !== null) {
    $katWhere[] = 'id_bidang = :bid';
    $katParams[':bid'] = $id_bidang;
}
if ($katWhere) {
    $kategoriSql .= " WHERE " . implode(' AND ', $katWhere);
}
$kategoriSql .= " ORDER BY nama_kategori";
$stKat = $pdo->prepare($kategoriSql);
$stKat->execute($katParams);
$kategoris = $stKat->fetchAll();

$posisiSql = "SELECT id_posisi, nama_posisi FROM posisi";
$posWhere = [];
$posParams = [];
if ($id_kategori !== null) {
    $posWhere[] = 'id_kategori = :kat';
    $posParams[':kat'] = $id_kategori;
}
if ($posWhere) {
    $posisiSql .= " WHERE " . implode(' AND ', $posWhere);
}
$posisiSql .= " ORDER BY nama_posisi";
$stPos = $pdo->prepare($posisiSql);
$stPos->execute($posParams);
$posisis = $stPos->fetchAll();

/* -------------------------------------------------------
   Helper kecil
------------------------------------------------------- */
function rec_class($rec)
{
    if ($rec === 'layak') return 'ok';
    if ($rec === 'belum layak' || $rec === 'di review') return 'warn';
    return 'muted';
}
function score_tone($skor)
{
    if ($skor >= 7) return 'ok';
    if ($skor >= 1) return 'warn';
    return ''; // abu-abu
}
function initials($name)
{
    $parts = preg_split('/\s+/', trim($name));
    $ini = '';
    foreach ($parts as $p) {
        if ($p !== '') {
            $ini .= mb_strtoupper(mb_substr($p, 0, 1));
        }
        if (mb_strlen($ini) >= 2) break;
    }
    return $ini ?: 'P';
}
function build_query(array $overrides = [])
{
    $qs = $_GET;
    foreach ($overrides as $k => $v) {
        if ($v === null) unset($qs[$k]);
        else $qs[$k] = $v;
    }
    return http_build_query($qs);
}
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="utf-8">
    <title>Penilaian | RELIPROVE</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="css/style.css" rel="stylesheet">
    <link href="css/fitur_filter.css" rel="stylesheet">
    <link rel="icon" href="../aset/img/logo.png" type="image/png">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap" rel="stylesheet" />
    <style>
        /* Modal ringan untuk detail (memakai pola yang sudah kamu kirim) */
        .modal {
            position: fixed;
            inset: 0;
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 90;
            background: radial-gradient(1200px 600px at 20% -10%, rgba(155, 94, 255, .08), transparent 60%), rgba(0, 0, 0, .55);
            backdrop-filter: blur(3px);
        }

        .modal.open {
            display: flex;
        }

        .modal .sheet {
            width: min(900px, 96vw);
            background: linear-gradient(180deg, rgba(21, 25, 35, .96), rgba(21, 25, 35, .94));
            border: 1px solid var(--border-light);
            border-radius: 22px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, .55), 0 0 0 1px rgba(255, 255, 255, .03) inset;
            padding: 18px;
        }

        .sheet .card__hdr {
            display: flex;
            align-items: center;
            justify-content: space-between;
            border: none;
            margin-bottom: 10px;
        }

        .sheet .card__hdr h6 {
            font-weight: 900;
            display: flex;
            gap: 10px;
            align-items: center;
            margin: 0;
        }

        .sheet .card__hdr h6 i {
            width: 28px;
            height: 28px;
            border-radius: 10px;
            display: grid;
            place-items: center;
            background: rgba(155, 94, 255, .14);
            color: #e9ddff;
            border: 1px solid rgba(155, 94, 255, .35);
        }

        .sheet__body {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 14px;
        }

        @media (max-width:880px) {
            .sheet__body {
                grid-template-columns: 1fr;
            }
        }

        .panel {
            background: var(--bg-row);
            border: 1px solid var(--border-light);
            border-radius: 16px;
            padding: 12px;
        }

        .kv {
            display: grid;
            grid-template-columns: 150px 1fr;
            gap: 8px;
            align-items: center;
            padding: 10px;
            border: 1px solid var(--border-light);
            border-radius: 12px;
            background: rgba(255, 255, 255, .03);
            margin-bottom: 8px;
        }

        .kv .k {
            color: var(--muted);
            font-weight: 700;
        }

        .kv .v {
            color: var(--text-light);
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .close-x {
            border: 1px solid var(--border-light);
            background: transparent;
            color: var(--text);
            border-radius: 10px;
            width: 36px;
            height: 36px;
        }

        .pill-link {
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
    </style>
</head>

<body>
    <!-- SIDEBAR -->
    <aside class="sb" id="sidebar">
        <div class="sb__brand" style="font-size: 35px; margin-top: 15px; margin-bottom: 10px;">RELIPROVE</div>
        <nav class="sb__nav">
            <a href="dashboard_admin.php"><i class="fa-solid fa-gauge"></i>Dashboard</a>
            <a href="pengguna.php"><i class="fa-solid fa-users"></i>Manajemen Pengguna</a>
            <a href="pendaftaran.php"><i class="fa-solid fa-id-card"></i>Pendaftaran</a>
            <a class="active" href="penilaian.php"><i class="fa-solid fa-clipboard-check"></i>Penilaian</a>
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
        <!-- TOPBAR -->
        <header class="tb">
            <div class="tb__left">
                <button class="tb__burger" onclick="document.getElementById('sidebar').classList.toggle('open')">
                    <i class="fa-solid fa-bars"></i>
                </button>

                <!-- Judul halaman -->
                <h1 class="tb__title">Penilaian</h1>

                <!-- Search (GET) -->
                <form class="tb__search" method="get" action="penilaian.php">
                    <i class="fa-solid fa-magnifying-glass"></i>
                    <input name="q" value="<?= esc($q) ?>" placeholder="Cari… (peserta, asesor, posisi)">
                </form>
            </div>

            <div class="tb__right">
                <!-- Tidak ada tombol create karena read-only -->
                <div class="tb__me">
                    <img src="https://ui-avatars.com/api/?name=<?= urlencode($namaAdmin) ?>&background=9b5eff&color=fff&rounded=true&size=40" alt="ava">
                    <div>
                        <div class="me__name"><?= esc($namaAdmin) ?></div>
                        <div class="me__role"><?= esc($me['peran'] ?? '-') ?></div>
                    </div>
                </div>
            </div>
        </header>


        <!-- FILTER CARD -->
        <section class="card card--ht-filter" style="margin-top:14px;">
            <div class="filter__hdr">
                <div class="filter__title">
                    <i class="fa-solid fa-filter"></i>
                    <span>Filter Penilaian</span>
                </div>
                <span class="count-badge"><?= (int)$summary['total'] ?> hasil</span>
            </div>

            <form method="get" id="filterForm">
                <div class="frow">
                    <!-- Bidang -->
                    <div class="fitem">
                        <label>Bidang</label>
                        <div class="field">
                            <div class="ficon"><i class="fa-solid fa-layer-group"></i></div>
                            <select class="fsel" name="bidang" id="f_bidang">
                                <option value="">Semua Bidang</option>
                                <?php foreach ($bidangs as $b): ?>
                                    <option value="<?= (int)$b['id_bidang'] ?>" <?= $id_bidang === (int)$b['id_bidang'] ? 'selected' : '' ?>>
                                        <?= esc($b['nama_bidang']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="chev"><i class="fa-solid fa-chevron-down"></i></div>
                        </div>
                    </div>

                    <!-- Kategori -->
                    <div class="fitem">
                        <label>Kategori</label>
                        <div class="field">
                            <div class="ficon"><i class="fa-solid fa-shapes"></i></div>
                            <select class="fsel" name="kategori" id="f_kategori">
                                <option value="">Semua Kategori</option>
                                <?php foreach ($kategoris as $k): ?>
                                    <option value="<?= (int)$k['id_kategori'] ?>" <?= $id_kategori === (int)$k['id_kategori'] ? 'selected' : '' ?>>
                                        <?= esc($k['nama_kategori']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="chev"><i class="fa-solid fa-chevron-down"></i></div>
                        </div>
                    </div>

                    <!-- Posisi -->
                    <div class="fitem">
                        <label>Posisi</label>
                        <div class="field">
                            <div class="ficon"><i class="fa-solid fa-briefcase"></i></div>
                            <select class="fsel" name="posisi" id="f_posisi">
                                <option value="">Semua Posisi</option>
                                <?php foreach ($posisis as $p): ?>
                                    <option value="<?= (int)$p['id_posisi'] ?>" <?= $id_posisi === (int)$p['id_posisi'] ? 'selected' : '' ?>>
                                        <?= esc($p['nama_posisi']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="chev"><i class="fa-solid fa-chevron-down"></i></div>
                        </div>
                    </div>

                    <!-- Rekomendasi -->
                    <div class="fitem">
                        <label>Rekomendasi</label>
                        <div class="field">
                            <div class="ficon"><i class="fa-solid fa-traffic-light"></i></div>
                            <select class="fsel" name="rekomendasi">
                                <option value="">Semua</option>
                                <?php
                                $recs = ['di review' => 'Di Review', 'belum layak' => 'Belum Layak', 'layak' => 'Layak'];
                                foreach ($recs as $val => $label):
                                ?>
                                    <option value="<?= esc($val) ?>" <?= $rekomendasi === $val ? 'selected' : '' ?>><?= esc($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <div class="chev"><i class="fa-solid fa-chevron-down"></i></div>
                        </div>
                    </div>

                    <!-- Rentang Skor -->
                    <div class="fitem">
                        <label>Skor Min</label>
                        <div class="field">
                            <div class="ficon"><i class="fa-solid fa-sort-numeric-down"></i></div>
                            <input class="fsel" type="number" min="0" max="10" name="skor_min" value="<?= esc($skor_min ?? '') ?>" placeholder="0">
                            <div class="chev"></div>
                        </div>
                    </div>
                    <div class="fitem">
                        <label>Skor Max</label>
                        <div class="field">
                            <div class="ficon"><i class="fa-solid fa-sort-numeric-up"></i></div>
                            <input class="fsel" type="number" min="0" max="10" name="skor_max" value="<?= esc($skor_max ?? '') ?>" placeholder="10">
                            <div class="chev"></div>
                        </div>
                    </div>

                    <!-- Urutkan -->
                    <div class="fitem">
                        <label>Urutkan</label>
                        <div class="field">
                            <div class="ficon"><i class="fa-solid fa-arrow-down-wide-short"></i></div>
                            <select class="fsel" name="urut">
                                <option value="tanggal_dinilai_desc" <?= $urut === 'tanggal_dinilai_desc' ? 'selected' : '' ?>>Tanggal Dinilai Terbaru</option>
                                <option value="tanggal_dinilai_asc" <?= $urut === 'tanggal_dinilai_asc' ? 'selected' : ''  ?>>Tanggal Dinilai Terlama</option>
                                <option value="skor_desc" <?= $urut === 'skor_desc' ? 'selected' : ''            ?>>Skor Tertinggi</option>
                                <option value="skor_asc" <?= $urut === 'skor_asc' ? 'selected' : ''             ?>>Skor Terendah</option>
                            </select>
                            <div class="chev"><i class="fa-solid fa-chevron-down"></i></div>
                        </div>
                    </div>

                    <!-- Per halaman -->
                    <div class="fitem">
                        <label>Per Halaman</label>
                        <div class="field">
                            <div class="ficon"><i class="fa-solid fa-list-ol"></i></div>
                            <select class="fsel" name="per">
                                <?php foreach ([10, 20, 30, 40, 50] as $n): ?>
                                    <option value="<?= $n ?>" <?= $perPage === $n ? 'selected' : '' ?>><?= $n ?></option>
                                <?php endforeach; ?>
                            </select>
                            <div class="chev"><i class="fa-solid fa-chevron-down"></i></div>
                        </div>
                    </div>
                </div>

                <div class="factions">
                    <p class="hintline">
                        Admin hanya bisa <b>melihat</b> dan <b>mengekspor</b> rekap. Aksi menilai ada di akun Asesor.
                    </p>
                    <div class="actions">
                        <a class="btn btn--sm btn--outline" href="penilaian.php"><i class="fa-solid fa-rotate-left"></i> Reset</a>
                        <button class="btn btn--sm btn--primary" type="submit"><i class="fa-solid fa-filter"></i> Terapkan</button>
                        <a class="btn btn--sm" href="penilaian.php?<?= esc(build_query(['page' => 1, 'export' => 'xls'])) ?>">
                            <i class="fa-solid fa-file-excel"></i> Ekspor Excel
                        </a>
                    </div>
                </div>
            </form>
        </section>

        <!-- RINGKASAN STATUS -->
        <section class="card" style="margin-top:14px;">
            <div class="tool chips">
                <span class="chip"><i>●</i> Di Review : <b><?= (int)$summary['cnt_review'] ?></b></span>
                <span class="chip"><i>●</i> Belum Layak : <b><?= (int)$summary['cnt_belum'] ?></b></span>
                <span class="chip"><i>●</i> Sudah Layak : <b><?= (int)$summary['cnt_layak'] ?></b></span>
            </div>
        </section>

        <!-- LIST HASIL -->
        <section class="card card--loose" style="margin-top:14px;">
            <div class="card__hdr card__hdr--xl">
                <h6><i class="fa-solid fa-list-check"></i> Daftar Hasil Penilaian</h6>
                <span class="count-badge"><?= (int)$total ?> data</span>

            </div>

            <?php if (!$rows): ?>
                <div class="list">
                    <div class="list empty">Belum ada data penilaian sesuai filter.</div>
                </div>
            <?php else: ?>
                <ul class="relaxed-list">
                    <?php foreach ($rows as $r):
                        $sk = (int)$r['skor'];
                        $tone = score_tone($sk);
                        $rec = $r['rekomendasi'];
                        $recClass = rec_class($rec);
                        $w = max(0, min(100, $sk * 10));
                        $peserta = $r['nama_peserta'];
                        $asesor  = $r['nama_asesor'] ?: '-';
                        $tgl     = $r['tanggal_dinilai'] ? date('Y-m-d H:i', strtotime($r['tanggal_dinilai'])) : '-';
                        $ini     = initials($peserta);
                        // data-* untuk modal detail
                        $dataAttrs = [
                            'id'         => $r['id_penilaian'],
                            'peserta'    => $peserta,
                            'email'      => $r['email_peserta'],
                            'posisi'     => $r['nama_posisi'],
                            'kategori'   => $r['nama_kategori'],
                            'bidang'     => $r['nama_bidang'],
                            'asesor'     => $asesor,
                            'skor'       => $sk,
                            'rekom'      => $rec,
                            'tgl'        => $tgl,
                            'komentar'   => $r['komentar'] ?: '-',
                            'link_soal'  => $r['link_soal'] ?: '',
                            'link_jwb'   => $r['link_jawaban'] ?: '',
                        ];
                        $dataStr = '';
                        foreach ($dataAttrs as $k => $v) {
                            $dataStr .= ' data-' . $k . '="' . esc($v) . '"';
                        }
                    ?>
                        <li class="row" <?= $dataStr ?> onclick="openDetail(this)" style="cursor:pointer">
                            <div class="row__left">
                                <div class="avatar"><?= esc($ini) ?></div>
                                <div class="txt">
                                    <div class="ttl"><?= esc($peserta) ?></div>
                                    <div class="sub">
                                        <?= esc($r['nama_posisi']) ?> • <?= esc($r['nama_kategori']) ?> • <?= esc($r['nama_bidang']) ?>
                                    </div>

                                </div>
                            </div>
                            <div class="row__right">
                                <?php
                                // derajat untuk cincin (10 poin = 360°, 1 poin = 36°)
                                $deg = max(0, min(360, $sk * 36));
                                // tone class sudah ada di $tone: '', 'ok', 'warn'
                                $toneClass = $tone ?: 'muted';
                                ?>
                                <div class="score-ht <?= esc($toneClass) ?>" title="Skor: <?= $sk ?>/10">
                                    <div class="score-ht__ring" style="--deg: <?= $deg ?>deg"></div>
                                    <div class="score-ht__meta">
                                        <div class="score-ht__val"><b><?= $sk ?></b><span>/10</span></div>
                                    </div>
                                </div>
                                <span class="status <?= esc($recClass) ?>"><?= esc($rec) ?></span>
                                <span class="tag"><i class="fa-regular fa-user"></i> <?= esc($asesor) ?></span>
                                <time><i class="fa-regular fa-clock"></i> <?= esc($tgl) ?></time>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>

            <!-- Pagination -->
            <div class="card__hdr" style="border-bottom:none; margin-top:12px;">
                <div></div>
                <div class="pagination">
                    <?php
                    $prev = max(1, $page - 1);
                    $next = min($pages, $page + 1);
                    $qsPrev = build_query(['page' => $prev]);
                    $qsNext = build_query(['page' => $next]);
                    if ($page > 1) {
                        echo '<a href="penilaian.php?' . esc($qsPrev) . '">&laquo; Prev</a>';
                    } else {
                        echo '<span class="muted">&laquo; Prev</span>';
                    }
                    // window simple
                    $win = 3;
                    $start = max(1, $page - $win);
                    $end   = min($pages, $page + $win);
                    for ($i = $start; $i <= $end; $i++) {
                        if ($i === $page) {
                            echo '<span class="active">' . (int)$i . '</span>';
                        } else {
                            echo '<a href="penilaian.php?' . esc(build_query(['page' => $i])) . '">' . (int)$i . '</a>';
                        }
                    }
                    if ($page < $pages) {
                        echo '<a href="penilaian.php?' . esc($qsNext) . '">Next &raquo;</a>';
                    } else {
                        echo '<span class="muted">Next &raquo;</span>';
                    }
                    ?>
                </div>
            </div>
        </section>
        <br>
        <footer class="ft">© <?= date('Y') ?> RELIPROVE • Penilaian</footer>
    </main>

    <!-- MODAL DETAIL -->
    <div class="modal" id="detailModal" aria-hidden="true">
        <div class="sheet">
            <div class="card__hdr">
                <h6><i class="fa-solid fa-circle-info"></i> Detail Penilaian</h6>
                <button class="close-x" onclick="closeDetail()"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <div class="sheet__body">
                <div class="panel">
                    <div class="kv">
                        <div class="k">Peserta</div>
                        <div class="v" id="d_peserta">-</div>
                    </div>
                    <div class="kv">
                        <div class="k">Email</div>
                        <div class="v" id="d_email">-</div>
                    </div>
                    <div class="kv">
                        <div class="k">Bidang</div>
                        <div class="v" id="d_bidang">-</div>
                    </div>
                    <div class="kv">
                        <div class="k">Kategori</div>
                        <div class="v" id="d_kategori">-</div>
                    </div>
                    <div class="kv">
                        <div class="k">Posisi</div>
                        <div class="v" id="d_posisi">-</div>
                    </div>
                </div>
                <div class="panel">
                    <div class="kv">
                        <div class="k">Asesor</div>
                        <div class="v" id="d_asesor">-</div>
                    </div>
                    <div class="kv">
                        <div class="k">Skor</div>
                        <div class="v" id="d_skor">-</div>
                    </div>
                    <div class="kv">
                        <div class="k">Rekomendasi</div>
                        <div class="v" id="d_rekom"><span class="status">-</span></div>
                    </div>
                    <div class="kv">
                        <div class="k">Tanggal Dinilai</div>
                        <div class="v" id="d_tgl">-</div>
                    </div>
                    <div class="kv">
                        <div class="k">Komentar</div>
                        <div class="v" id="d_komentar">-</div>
                    </div>
                    <div class="kv">
                        <div class="k">Link Soal</div>
                        <div class="v" id="d_link_soal">-</div>
                    </div>
                    <div class="kv">
                        <div class="k">Link Jawaban</div>
                        <div class="v" id="d_link_jwb">-</div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        /* Dependent selects: submit on change agar server rebuild opsi turunan */
        document.getElementById('f_bidang')?.addEventListener('change', function() {
            // saat bidang berubah, kosongkan kategori/posisi agar tidak mismatch
            document.getElementById('f_kategori').selectedIndex = 0;
            document.getElementById('f_posisi').selectedIndex = 0;
            document.getElementById('filterForm').submit();
        });
        document.getElementById('f_kategori')?.addEventListener('change', function() {
            document.getElementById('f_posisi').selectedIndex = 0;
            document.getElementById('filterForm').submit();
        });

        /* Modal detail */
        function openDetail(el) {
            const m = document.getElementById('detailModal');
            const get = (k) => el.dataset[k] || '-';
            // isi fields
            document.getElementById('d_peserta').textContent = get('peserta');
            document.getElementById('d_email').textContent = get('email');
            document.getElementById('d_bidang').textContent = get('bidang');
            document.getElementById('d_kategori').textContent = get('kategori');
            document.getElementById('d_posisi').textContent = get('posisi');
            document.getElementById('d_asesor').textContent = get('asesor');
            document.getElementById('d_skor').textContent = get('skor') + '/10';
            document.getElementById('d_tgl').textContent = get('tgl');
            document.getElementById('d_komentar').textContent = get('komentar');

            // rekom chip
            const dRekom = document.getElementById('d_rekom');
            dRekom.innerHTML = '';
            const span = document.createElement('span');
            span.className = 'status ' + rekomClass(get('rekom'));
            span.textContent = get('rekom');
            dRekom.appendChild(span);

            // link soal/jawaban
            putLink('d_link_soal', get('link_soal'));
            putLink('d_link_jwb', get('link_jwb'));

            m.classList.add('open');
            m.setAttribute('aria-hidden', 'false');
        }

        function closeDetail() {
            const m = document.getElementById('detailModal');
            m.classList.remove('open');
            m.setAttribute('aria-hidden', 'true');
        }

        function rekomClass(rec) {
            rec = (rec || '').toLowerCase();
            if (rec === 'layak') return 'ok';
            if (rec === 'belum layak' || rec === 'di review') return 'warn';
            return 'muted';
        }

        function putLink(id, url) {
            const container = document.getElementById(id);
            container.innerHTML = '-';
            if (!url || url === '-') return;
            const a = document.createElement('a');
            a.href = url;
            a.target = '_blank';
            a.rel = 'noopener';
            a.className = 'pill-link';
            a.innerHTML = '<i class="fa-solid fa-link"></i> Buka';
            container.innerHTML = '';
            container.appendChild(a);
        }
        // tutup modal saat klik backdrop
        document.getElementById('detailModal')?.addEventListener('click', (e) => {
            if (e.target.id === 'detailModal') closeDetail();
        });
    </script>
</body>

</html>